<?php
session_start();
require_once 'includes/db.php';

header('Content-Type: application/json');

// Only allow admin or registrar
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['systemadmin', 'registrar'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$course_filter = trim($_GET['course'] ?? 'all');
$inst_filter   = trim($_GET['institution'] ?? 'all');

// Registrars only ever see their own institution, whatever they ask for.
if ($_SESSION['role'] === 'registrar') {
    $inst_filter = 'all';
    $stmt_inst = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
    if ($stmt_inst) {
        $stmt_inst->bind_param('i', $_SESSION['user_id']);
        $stmt_inst->execute();
        $row_inst = $stmt_inst->get_result()->fetch_assoc();
        $stmt_inst->close();
        if (!empty($row_inst['institution'])) {
            $inst_filter = $row_inst['institution'];
        }
    }
}

// Students counted everywhere: active (approved) and not archived.
$student_where = "u.role = 'student' AND u.is_verified = 2 AND u.is_archived = 0";
$filter_sql = '';
$filter_types = '';
$filter_params = [];

if ($course_filter !== 'all' && $course_filter !== '') {
    $filter_sql .= " AND u.course = ?";
    $filter_types .= 's';
    $filter_params[] = $course_filter;
}
if ($inst_filter !== 'all' && $inst_filter !== '') {
    $filter_sql .= " AND u.institution = ?";
    $filter_types .= 's';
    $filter_params[] = $inst_filter;
}

/**
 * Run a prepared statement with the shared filter params (plus optional extras).
 */
function reportQuery($mysqli, $sql, $types, $params) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

$rows_by_course = [];

/** Make sure a course bucket exists before we add numbers to it. */
function bucket(&$rows_by_course, $course) {
    $key = ($course === null || $course === '') ? 'Unassigned' : $course;
    if (!isset($rows_by_course[$key])) {
        $rows_by_course[$key] = [
            'course'            => $key,
            'institution'       => '',
            'total_students'    => 0,
            'active_uploaders'  => 0,
            'total_uploads'     => 0,   // every file ever sent, re-uploads included
            'requirements'      => 0,   // distinct document types submitted
            'approved'          => 0,
            'rejected'          => 0,
            'pending'           => 0,
        ];
    }
    return $key;
}

// ── 1. Active students per course ────────────────────────────────────────────
$sql_students = "SELECT u.course,
                        MIN(u.institution) AS institution,
                        COUNT(*) AS total_students
                 FROM users u
                 WHERE $student_where $filter_sql
                 GROUP BY u.course";
$students = reportQuery($mysqli, $sql_students, $filter_types, $filter_params) ?? [];
foreach ($students as $r) {
    $key = bucket($rows_by_course, $r['course']);
    $rows_by_course[$key]['total_students'] = (int)$r['total_students'];
    $rows_by_course[$key]['institution']    = $r['institution'] ?? '';
}

// ── 2. Raw upload volume + how many students actually uploaded ───────────────
$sql_uploads = "SELECT u.course,
                       COUNT(d.id) AS total_uploads,
                       COUNT(DISTINCT d.user_id) AS active_uploaders
                FROM documents d
                JOIN users u ON u.id = d.user_id
                WHERE $student_where $filter_sql
                GROUP BY u.course";
$uploads = reportQuery($mysqli, $sql_uploads, $filter_types, $filter_params) ?? [];
foreach ($uploads as $r) {
    $key = bucket($rows_by_course, $r['course']);
    $rows_by_course[$key]['total_uploads']    = (int)$r['total_uploads'];
    $rows_by_course[$key]['active_uploaders'] = (int)$r['active_uploaders'];
}

// ── 3. Status mix, counting only the LATEST file per student per requirement ──
// A student who re-uploads a rejected PSA three times is one requirement, not three.
$sql_status = "SELECT u.course, d.status, COUNT(*) AS c
               FROM documents d
               JOIN users u ON u.id = d.user_id
               JOIN (
                    SELECT user_id,
                           COALESCE(NULLIF(document_type, ''), CONCAT('doc#', id)) AS req,
                           MAX(id) AS latest_id
                    FROM documents
                    GROUP BY user_id, req
               ) latest ON latest.latest_id = d.id
               WHERE $student_where $filter_sql
               GROUP BY u.course, d.status";
$statuses = reportQuery($mysqli, $sql_status, $filter_types, $filter_params) ?? [];
foreach ($statuses as $r) {
    $key = bucket($rows_by_course, $r['course']);
    $count = (int)$r['c'];
    $rows_by_course[$key]['requirements'] += $count;
    switch ($r['status']) {
        case 'approved': $rows_by_course[$key]['approved'] += $count; break;
        case 'rejected': $rows_by_course[$key]['rejected'] += $count; break;
        default:         $rows_by_course[$key]['pending']  += $count; break;
    }
}

// ── 4. How many requirements each student is expected to submit ──────────────
$required_types = 0;
$res_types = $mysqli->query("SELECT COUNT(*) AS c FROM document_types");
if ($res_types && ($t = $res_types->fetch_assoc())) {
    $required_types = (int)$t['c'];
}

// ── Derived per-course figures ───────────────────────────────────────────────
$rows = array_values($rows_by_course);
foreach ($rows as &$row) {
    $expected = $required_types * $row['total_students'];
    $row['expected']        = $expected;
    $row['missing']         = max(0, $expected - $row['requirements']);
    $row['completion_rate'] = $expected > 0
        ? round(($row['approved'] / $expected) * 100, 1)
        : 0;
    $row['approval_rate']   = $row['requirements'] > 0
        ? round(($row['approved'] / $row['requirements']) * 100, 1)
        : 0;
}
unset($row);

// Biggest programs first so the chart reads top-down.
usort($rows, function ($a, $b) {
    if ($a['total_students'] === $b['total_students']) {
        return strcmp($a['course'], $b['course']);
    }
    return $b['total_students'] <=> $a['total_students'];
});

// ── Totals for the KPI cards ─────────────────────────────────────────────────
$totals = [
    'students'      => 0,
    'uploaders'     => 0,
    'total_uploads' => 0,
    'requirements'  => 0,
    'approved'      => 0,
    'rejected'      => 0,
    'pending'       => 0,
    'expected'      => 0,
    'missing'       => 0,
];
foreach ($rows as $row) {
    $totals['students']      += $row['total_students'];
    $totals['uploaders']     += $row['active_uploaders'];
    $totals['total_uploads'] += $row['total_uploads'];
    $totals['requirements']  += $row['requirements'];
    $totals['approved']      += $row['approved'];
    $totals['rejected']      += $row['rejected'];
    $totals['pending']       += $row['pending'];
    $totals['expected']      += $row['expected'];
    $totals['missing']       += $row['missing'];
}
$totals['completion_rate'] = $totals['expected'] > 0
    ? round(($totals['approved'] / $totals['expected']) * 100, 1)
    : 0;
$totals['approval_rate'] = $totals['requirements'] > 0
    ? round(($totals['approved'] / $totals['requirements']) * 100, 1)
    : 0;

echo json_encode([
    'rows' => $rows,
    'totals' => $totals,
    'meta' => [
        'required_types' => $required_types,
        'course'         => $course_filter,
        'institution'    => $inst_filter,
        'generated_at'   => date('M j, Y g:i A'),
    ],
]);
