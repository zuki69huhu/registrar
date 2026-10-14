<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/name_helper.php';
require_once 'includes/audit_log_helper.php';

// Only allow systemadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

const AUDIT_PER_PAGE = 25;
const AUDIT_CSV_LIMIT = 10000;

function auditQuery($mysqli, $sql, $types, $params) {
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function auditPerson($row, $prefix) {
    if ($row[$prefix . '_id'] === null) {
        return null;
    }
    return [
        'id' => (int)$row[$prefix . '_id'],
        // Surname first, with the student number ahead of it in the UI
        'name' => formatPersonName(
            $row[$prefix . '_first'] ?? '',
            $row[$prefix . '_middle'] ?? '',
            $row[$prefix . '_last'] ?? ''
        ),
        'student_id' => trim((string)($row[$prefix . '_student_id'] ?? '')),
        'email' => $row[$prefix . '_email'],
        'role' => $row[$prefix . '_role'],
        'institution' => $row[$prefix . '_institution'],
        'course' => $row[$prefix . '_course'],
    ];
}

// Stop spreadsheet apps from treating a cell as a formula
function auditCsvCell($value) {
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

try {
    $program = trim($_GET['program'] ?? '');
    $action = trim($_GET['action'] ?? '');
    $search = trim($_GET['q'] ?? '');
    $date_from = $_GET['from'] ?? '';
    $date_to = $_GET['to'] ?? '';

    $where = [];
    $types = '';
    $params = [];

    // A program matches when the user who acted, or the user affected, belongs to it
    if ($program !== '' && strpos($program, '|') !== false) {
        [$institution, $course] = explode('|', $program, 2);
        $where[] = '((u.institution = ? AND u.course = ?) OR (t.institution = ? AND t.course = ?))';
        array_push($params, $institution, $course, $institution, $course);
        $types .= 'ssss';
    }

    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $action_like = '%' . addcslashes(strtoupper(str_replace(' ', '_', $search)), '%_\\') . '%';
        $where[] = "(a.description LIKE ? OR a.action LIKE ?
                     OR u.email LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
                     OR t.email LIKE ? OR CONCAT_WS(' ', t.first_name, t.last_name) LIKE ?)";
        array_push($params, $like, $action_like, $like, $like, $like, $like);
        $types .= 'ssssss';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[] = 'a.created_at >= ?';
        $params[] = $date_from . ' 00:00:00';
        $types .= 's';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $date_to;
        $types .= 's';
    }

    // Action counts apply every filter except the action itself, so each chip shows what it would return
    $counts_where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $counts_types = $types;
    $counts_params = $params;

    if ($action !== '') {
        $where[] = 'a.action = ?';
        $params[] = $action;
        $types .= 's';
    }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $from_sql = 'FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 LEFT JOIN users t ON t.id = a.target_user_id';
    $select_sql = "SELECT a.id, a.action, a.description,
                DATE_FORMAT(a.created_at, '%b %e, %Y %l:%i %p') AS created_display,
                DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
                TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS age_seconds,
                u.id AS actor_id, u.email AS actor_email, u.first_name AS actor_first, u.middle_name AS actor_middle, u.last_name AS actor_last,
                u.student_id AS actor_student_id,
                u.role AS actor_role, u.institution AS actor_institution, u.course AS actor_course,
                t.id AS target_id, t.email AS target_email, t.first_name AS target_first, t.middle_name AS target_middle, t.last_name AS target_last,
                t.student_id AS target_student_id,
                t.role AS target_role, t.institution AS target_institution, t.course AS target_course";
    $order_sql = 'ORDER BY a.created_at DESC, a.id DESC';

    // ─── CSV EXPORT ───
    if (($_GET['format'] ?? '') === 'csv') {
        $result = auditQuery($mysqli, "$select_sql $from_sql $where_sql $order_sql LIMIT " . AUDIT_CSV_LIMIT, $types, $params);
        logAudit($mysqli, $_SESSION['user_id'] ?? null, 'AUDIT_EXPORTED', "Exported {$result->num_rows} audit log entries to CSV.");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
        fputcsv($out, ['Date & Time', 'User ID', 'User', 'User Email', 'Role', 'Institution', 'Program', 'Action', 'Affected ID', 'Affected User', 'Affected Email', 'Affected Program', 'Details']);
        while ($row = $result->fetch_assoc()) {
            $actor = auditPerson($row, 'actor');
            $target = auditPerson($row, 'target');
            $target_program = $target ? trim(($target['course'] ?? '') . (!empty($target['institution']) ? " ({$target['institution']})" : '')) : '';
            fputcsv($out, array_map('auditCsvCell', [
                $row['created_at'],
                $actor['student_id'] ?? '',
                $actor ? ($actor['name'] ?: $actor['email']) : 'System',
                $actor['email'] ?? '',
                $actor['role'] ?? '',
                $actor['institution'] ?? '',
                $actor['course'] ?? '',
                $row['action'],
                $target['student_id'] ?? '',
                $target ? ($target['name'] ?: $target['email']) : '',
                $target['email'] ?? '',
                $target_program,
                $row['description'],
            ]));
        }
        fclose($out);
        exit;
    }

    // ─── JSON PAGE ───
    $total = (int)auditQuery($mysqli, "SELECT COUNT(*) AS n $from_sql $where_sql", $types, $params)->fetch_assoc()['n'];
    $total_pages = max(1, (int)ceil($total / AUDIT_PER_PAGE));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
    $offset = ($page - 1) * AUDIT_PER_PAGE;

    $rows = [];
    $result = auditQuery($mysqli, "$select_sql $from_sql $where_sql $order_sql LIMIT " . AUDIT_PER_PAGE . " OFFSET $offset", $types, $params);
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'action' => $row['action'],
            'description' => $row['description'],
            'time' => $row['created_display'],
            'timestamp' => $row['created_at'],
            'age_seconds' => (int)$row['age_seconds'],
            'actor' => auditPerson($row, 'actor'),
            'target' => auditPerson($row, 'target'),
        ];
    }

    $action_counts = [];
    $result = auditQuery($mysqli, "SELECT a.action, COUNT(*) AS n $from_sql $counts_where_sql GROUP BY a.action ORDER BY n DESC, a.action", $counts_types, $counts_params);
    while ($row = $result->fetch_assoc()) {
        $action_counts[] = ['action' => $row['action'], 'count' => (int)$row['n']];
    }

    $actions = [];
    $result = $mysqli->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row['action'];
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => AUDIT_PER_PAGE,
        'total_pages' => $total_pages,
        'action_counts' => $action_counts,
        'actions' => $actions,
    ]);
} catch (Exception $e) {
    error_log('fetch_audit_logs failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load audit logs.']);
}
