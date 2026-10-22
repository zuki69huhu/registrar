<?php
session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'name_helper.php';
require_once 'notification_helper.php';

// Access control: Only allow users with the 'registrar' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

// Fetch user details from the database
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, institution FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: logout.php");
    exit;
}

$full_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['middle_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
if (empty($full_name)) $full_name = $user_data['email'];
$initials = strtoupper(substr($user_data['first_name'] ?? 'R', 0, 1) . substr($user_data['last_name'] ?? 'O', 0, 1));
$user_institution = $user_data['institution'] ?? '';

// ─── FILTERS: course and year level ───
// Everything below — the cards, the three charts and the compliance table — is
// narrowed by the registrar's institution plus these two filters, so every
// figure on the page describes the same set of students.
$filter_course = trim((string)($_GET['course'] ?? ''));
$filter_year   = trim((string)($_GET['year'] ?? ''));

/** Runs a prepared SELECT with an optional list of string parameters. */
function analyticsRows($mysqli, $sql, array $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// The institution scope every query shares (unscoped when the registrar has
// none, the same fallback the other registrar pages use).
$scope_sql = '';
$scope_params = [];
if (!empty($user_institution)) {
    $scope_sql = ' AND u.institution = ?';
    $scope_params[] = $user_institution;
}

// Options: the institution's full program list plus any course a student has
// that is not on it, and the four year levels plus any other stored value.
// Counts are faceted — a course shows what it would give with the chosen year,
// a year what it would give with the chosen course — so each number is exactly
// what picking it returns.
require_once 'programs.php';
$all_programs = getInstitutionPrograms();
$course_options = [];
if (!empty($user_institution)) {
    $course_options = $all_programs[strtoupper($user_institution)] ?? [];
} else {
    foreach ($all_programs as $list) $course_options = array_merge($course_options, $list);
}
$year_options = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

$facet_rows = analyticsRows($mysqli,
    "SELECT u.course, u.year_level, COUNT(*) AS n FROM users u
     WHERE u.role = 'student' AND u.is_verified = 2{$scope_sql}
     GROUP BY u.course, u.year_level", $scope_params);

foreach ($facet_rows as $r) {
    $c = trim((string)$r['course']);
    $y = trim((string)$r['year_level']);
    if ($c !== '' && !in_array($c, $course_options, true)) $course_options[] = $c;
    if ($y !== '' && !in_array($y, $year_options, true)) $year_options[] = $y;
}
natcasesort($course_options);
$course_options = array_values($course_options);

// A value that is not one of the options (a stale or hand-typed link) is dropped
// rather than returning an empty page.
$match_option = function ($value, array $options) {
    foreach ($options as $o) if (strcasecmp($o, $value) === 0) return $o;
    return '';
};
$filter_course = $filter_course === '' ? '' : $match_option($filter_course, $course_options);
$filter_year   = $filter_year === ''   ? '' : $match_option($filter_year, $year_options);

$course_counts = [];
$year_counts = [];
$all_courses_total = 0;   // "All courses" with the chosen year
$all_years_total = 0;     // "All year levels" with the chosen course
foreach ($facet_rows as $r) {
    $c = trim((string)$r['course']);
    $y = trim((string)$r['year_level']);
    if ($filter_year === '' || strcasecmp($y, $filter_year) === 0) {
        $all_courses_total += (int)$r['n'];
        if ($c !== '') $course_counts[$c] = ($course_counts[$c] ?? 0) + (int)$r['n'];
    }
    if ($filter_course === '' || strcasecmp($c, $filter_course) === 0) {
        $all_years_total += (int)$r['n'];
        if ($y !== '') $year_counts[$y] = ($year_counts[$y] ?? 0) + (int)$r['n'];
    }
}

$filter_sql = $scope_sql;
$filter_params = $scope_params;
if ($filter_course !== '') { $filter_sql .= ' AND u.course = ?';     $filter_params[] = $filter_course; }
if ($filter_year !== '')   { $filter_sql .= ' AND u.year_level = ?'; $filter_params[] = $filter_year; }
$is_filtered = ($filter_course !== '' || $filter_year !== '');

// ─── FETCH ALL REQUIRED DOCUMENT TYPES ───
$document_types = [];
$dt_res = $mysqli->query("SELECT id, name FROM document_types ORDER BY name ASC");
if ($dt_res) while($row = $dt_res->fetch_assoc()) $document_types[] = $row;
$total_required = count($document_types);

// ─── OVERALL SUBMISSION STATS ───
$stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
$data = analyticsRows($mysqli, "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN d.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN d.status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM documents d JOIN users u ON d.user_id = u.id
    WHERE u.role = 'student'{$filter_sql}", $filter_params)[0] ?? [];
$stats['total'] = (int)($data['total'] ?? 0);
$stats['approved'] = (int)($data['approved'] ?? 0);
$stats['pending'] = (int)($data['pending'] ?? 0);
$stats['rejected'] = (int)($data['rejected'] ?? 0);

// ─── SUBMISSIONS PER DOCUMENT TYPE (for bar chart) ───
$type_counts = [];
foreach (analyticsRows($mysqli, "SELECT d.document_type, COUNT(*) AS cnt
    FROM documents d JOIN users u ON d.user_id = u.id
    WHERE u.role = 'student'{$filter_sql}
    GROUP BY d.document_type", $filter_params) as $r) {
    $type_counts[(string)$r['document_type']] = (int)$r['cnt'];
}
$per_type = [];
foreach ($document_types as $dt) {
    $per_type[] = ['name' => $dt['name'], 'count' => $type_counts[$dt['name']] ?? 0];
}

// ─── DAILY SUBMISSIONS (last 7 days, for line chart) ───
$daily = [];
$daily_data = [];
foreach (analyticsRows($mysqli, "SELECT DATE(d.uploaded_at) as day, COUNT(*) as cnt
    FROM documents d JOIN users u ON d.user_id = u.id
    WHERE u.role = 'student' AND d.uploaded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY){$filter_sql}
    GROUP BY DATE(d.uploaded_at)
    ORDER BY day ASC", $filter_params) as $row) {
    $daily_data[$row['day']] = (int)$row['cnt'];
}
// Fill in all 7 days (even if 0)
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[] = ['day' => date('M d', strtotime($d)), 'count' => $daily_data[$d] ?? 0];
}

// ─── PER-STUDENT COMPLIANCE ───
// Every submitted (non-rejected) type for the filtered students in one query,
// instead of one query per student.
$submitted_by_student = [];
foreach (analyticsRows($mysqli, "SELECT DISTINCT d.user_id, d.document_type
    FROM documents d JOIN users u ON d.user_id = u.id
    WHERE d.status != 'rejected' AND u.role = 'student' AND u.is_verified = 2{$filter_sql}", $filter_params) as $r) {
    if (!empty($r['document_type'])) $submitted_by_student[(int)$r['user_id']][] = $r['document_type'];
}

$students = [];
$student_rows = analyticsRows($mysqli, "SELECT u.id, u.first_name, u.middle_name, u.last_name, u.student_id, u.course, u.year_level, u.section, u.email
    FROM users u WHERE u.role = 'student' AND u.is_verified = 2{$filter_sql}
    ORDER BY u.last_name ASC, u.first_name ASC", $filter_params);
foreach ($student_rows as $s) {
    $submitted_types = $submitted_by_student[(int)$s['id']] ?? [];

    // Find missing document types
    $missing = [];
    foreach ($document_types as $dt) {
        if (!in_array($dt['name'], $submitted_types)) {
            $missing[] = $dt['name'];
        }
    }

    $submitted_count = $total_required - count($missing);
    $pct = $total_required > 0 ? round(($submitted_count / $total_required) * 100) : 0;

    $s['submitted_count'] = $submitted_count;
    $s['missing'] = $missing;
    $s['pct'] = $pct;
    $s['full_name'] = formatPersonNameRow($s);
    $students[] = $s;
}

// Summary stats
$total_students = count($students);
$complete_students = 0;
$zero_students = 0;
$total_pct_sum = 0;
foreach ($students as $s) {
    $total_pct_sum += $s['pct'];
    if ($s['pct'] >= 100) $complete_students++;
    if ($s['pct'] == 0) $zero_students++;
}
$avg_completion = $total_students > 0 ? round($total_pct_sum / $total_students) : 0;

// Pending documents for the sidebar badge: the whole institution, whatever the
// filters above are set to.
$pending_count = (int)(analyticsRows($mysqli, "SELECT COUNT(*) AS c FROM documents d JOIN users u ON d.user_id = u.id
    WHERE d.status = 'pending' AND u.role = 'student'{$scope_sql}", $scope_params)[0]['c'] ?? 0);

// Fetch pending students count for notification badge
$pending_students_count = 0;
if (!empty($user_institution)) {
    $stmt_ps = $mysqli->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND is_verified = 1 AND institution = ?");
    $stmt_ps->bind_param("s", $user_institution);
    $stmt_ps->execute();
    $res_ps = $stmt_ps->get_result();
    if ($res_ps) $pending_students_count = $res_ps->fetch_assoc()['count'];
    $stmt_ps->close();
} else {
    $res_ps = $mysqli->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND is_verified = 1");
    if ($res_ps) $pending_students_count = $res_ps->fetch_assoc()['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=3" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
    <title>Analytics - School Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>document.documentElement.classList.add('js');</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --sidebar-bg: #0f172a;
            --main-bg: #0f172a;
            --card-bg: #1e293b;
            --accent-gold: #c9a227;
            --accent-green: #10b981;
            --accent-orange: #f59e0b;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
            --accent-blue: #3b82f6;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border: #1e293b;
            --sidebar-width: 240px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--main-bg);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

        /* ─── SIDEBAR (identical to registrar_dashboard.php) ─── */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg, #0a1628);
            display: flex;
            flex-direction: column;
            padding: 0;
            flex-shrink: 0;
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-logo {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .logo-box {
            width: 48px; height: 48px;
            background: transparent;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; padding: 2px;
        }
        .sidebar-brand {
            font-size: 1.15rem; font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.02em;
        }
        .sidebar-nav {
            flex: 1; padding: 20px 12px;
            overflow-y: auto; min-height: 0;
        }
        .nav-section-label {
            font-size: 0.68rem; font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.1em;
            padding: 0 8px; margin: 16px 0 8px;
        }
        .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; color: var(--text-secondary);
            text-decoration: none; border-radius: 8px;
            font-size: 0.875rem; font-weight: 500;
            transition: all 0.2s; margin-bottom: 2px;
            position: relative;
        }
        .nav-link:hover {
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
        }
        .nav-link.active {
            background: rgba(201,162,39,0.15);
            color: var(--accent-gold);
            border-left: 3px solid var(--accent-gold);
            padding-left: 9px;
        }
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
        .nav-badge {
            margin-left: auto; background: #ef4444;
            color: white; font-size: 0.7rem; font-weight: 700;
            padding: 2px 7px; border-radius: 20px;
            min-width: 20px; text-align: center;
        }
        .sidebar-bottom {
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        .logout-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; color: #ef4444;
            text-decoration: none; border-radius: 8px;
            font-size: 0.875rem; font-weight: 500;
            transition: background 0.2s;
        }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }
        .logout-link:hover { background: rgba(239,68,68,0.1); }

        /* ─── MAIN ─── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        /* ─── TOPBAR ─── */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 32px; background: #0f172a;
            border-bottom: 1px solid rgba(255,255,255,0.05); gap: 24px;
        }
        .topbar-left h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
        .topbar-left p { font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .user-profile { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .user-avatar {
            width: 36px; height: 36px; background: #2d3f55;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.85rem; color: #ffffff;
        }
        .user-info .name { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .user-info .role { font-size: 0.72rem; color: var(--text-muted); }

        /* ─── CONTENT ─── */
        .content { flex: 1; padding: 28px 32px; overflow-y: auto; }

        /* ─── SUMMARY CARDS ─── */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .summary-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 20px 22px;
            border: 1px solid rgba(255,255,255,0.04);
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .summary-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,255,255,0.1);
            box-shadow: 0 12px 24px -8px rgba(0,0,0,0.4);
        }
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 14px 14px 0 0;
        }
        .summary-card:nth-child(1)::before { background: linear-gradient(90deg, var(--accent-blue), #60a5fa); }
        .summary-card:nth-child(2)::before { background: linear-gradient(90deg, var(--accent-green), #34d399); }
        .summary-card:nth-child(3)::before { background: linear-gradient(90deg, var(--accent-gold), #fbbf24); }
        .summary-card:nth-child(4)::before { background: linear-gradient(90deg, var(--accent-red), #f87171); }

        .summary-card .sc-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 14px;
        }
        .sc-icon.blue { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .sc-icon.green { background: rgba(16,185,129,0.15); color: #10b981; }
        .sc-icon.gold { background: rgba(201,162,39,0.15); color: var(--accent-gold); }
        .sc-icon.red { background: rgba(239,68,68,0.15); color: #ef4444; }

        .summary-card .sc-value {
            font-size: 1.8rem; font-weight: 700;
            color: var(--text-primary); line-height: 1;
            margin-bottom: 4px;
        }
        .summary-card .sc-label {
            font-size: 0.78rem; color: var(--text-secondary);
        }

        /* ─── CHARTS GRID ─── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        .chart-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
            backdrop-filter: blur(10px);
        }
        .chart-card h3 {
            font-size: 0.95rem; font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }
        .chart-card h3 i { color: var(--accent-gold); font-size: 0.9rem; }
        .chart-wrapper {
            position: relative;
            width: 100%;
        }
        .chart-wrapper.doughnut {
            max-width: 260px;
            margin: 0 auto;
        }

        /* ─── FILTER BAR ─── */
        .filter-bar {
            display: grid;
            grid-template-columns: minmax(180px, auto) minmax(220px, 1.6fr) minmax(160px, 1fr) auto;
            align-items: end;
            gap: 14px 16px;
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 12px;
        }
        .filter-head { display: flex; align-items: center; gap: 12px; align-self: center; }
        .filter-head > i {
            width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
            display: grid; place-items: center;
            background: rgba(201,162,39,0.14); color: var(--accent-gold);
        }
        .filter-title { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); }
        .filter-sub { font-size: 0.74rem; color: var(--text-secondary); margin-top: 2px; }
        .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .filter-field label {
            font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em;
            text-transform: uppercase; color: var(--text-secondary);
        }
        .filter-field select {
            width: 100%; min-width: 0;
            appearance: none; -webkit-appearance: none;
            background-color: rgba(255,255,255,0.05);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            border: 1px solid rgba(255,255,255,0.1); border-radius: 10px;
            color: var(--text-primary); font: 500 0.86rem 'Inter', sans-serif;
            padding: 10px 36px 10px 12px; cursor: pointer;
            text-overflow: ellipsis; white-space: nowrap; overflow: hidden;
        }
        .filter-field select:focus { outline: none; border-color: var(--accent-gold); box-shadow: 0 0 0 3px rgba(201,162,39,0.18); }
        .filter-field select option { background: #1e293b; color: #f1f5f9; }
        .filter-actions { display: flex; gap: 8px; align-items: center; }
        .filter-apply, .filter-clear {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px 16px; border-radius: 10px; font: 600 0.84rem 'Inter', sans-serif;
            cursor: pointer; text-decoration: none; white-space: nowrap; border: none;
        }
        .filter-apply { background: var(--accent-gold); color: #111; }
        .filter-clear { background: rgba(255,255,255,0.06); color: var(--text-primary); border: 1px solid rgba(255,255,255,0.1); }
        .filter-clear:hover { background: rgba(255,255,255,0.1); }
        /* With JS the selects apply themselves, so Apply is only a fallback. */
        .js .filter-apply { display: none; }
        .filter-bar.is-loading { opacity: 0.6; pointer-events: none; }

        .filter-summary {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: 0.84rem; color: var(--text-secondary);
            margin: 0 4px 20px; line-height: 1.6;
        }
        .filter-summary > i { color: var(--accent-gold); margin-top: 4px; }
        .filter-summary strong { color: var(--text-primary); }
        .filter-chip {
            display: inline-block; padding: 1px 10px; margin: 0 2px;
            border-radius: 999px; font-size: 0.78rem; font-weight: 600;
            background: rgba(201,162,39,0.14); color: var(--accent-gold);
            border: 1px solid rgba(201,162,39,0.3);
        }

        .search-count { font-size: 0.78rem; color: var(--text-secondary); white-space: nowrap; }
        .compliance-search input::-webkit-search-cancel-button { filter: invert(0.7); cursor: pointer; }
        .no-match-row td { text-align: center; color: var(--text-muted); padding: 32px 0 !important; }
        .compliance-table tr[hidden] { display: none !important; }

        @media (max-width: 1100px) {
            .filter-bar { grid-template-columns: 1fr 1fr; }
            .filter-head { grid-column: 1 / -1; }
            .filter-actions { grid-column: 1 / -1; }
            .filter-actions:empty { display: none; }
        }
        @media (max-width: 600px) {
            .filter-bar { grid-template-columns: 1fr; padding: 14px; gap: 12px; }
            .filter-field select { font-size: 16px; } /* no zoom-on-focus on iOS */
            .filter-actions > * { flex: 1; }
            .search-count { align-self: flex-end; }
        }

        /* ─── STUDENT TABLE ─── */
        .compliance-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
        }
        .compliance-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .compliance-header h3 {
            font-size: 1rem; font-weight: 600;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .compliance-header h3 i { color: var(--accent-gold); }
        .compliance-search {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 8px 14px;
            min-width: 240px;
        }
        .compliance-search i { color: var(--text-muted); font-size: 0.85rem; }
        .compliance-search input {
            background: none; border: none; outline: none;
            color: var(--text-primary); font-size: 0.85rem;
            font-family: 'Inter', sans-serif; width: 100%;
        }
        .compliance-search input::placeholder { color: var(--text-muted); }

        .compliance-table {
            width: 100%; border-collapse: collapse;
        }
        .compliance-table thead th {
            font-size: 0.72rem; font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.07em;
            padding: 0 12px 14px 0; text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .compliance-table tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.03);
            transition: background 0.15s;
        }
        .compliance-table tbody tr:hover {
            background: rgba(255,255,255,0.03);
        }
        .compliance-table tbody td {
            padding: 14px 12px 14px 0;
            font-size: 0.85rem;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .compliance-table tbody tr:last-child { border-bottom: none; }
        .compliance-table .cell-student-id {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
            font-size: 0.8rem; color: var(--text-secondary, #94a3b8); white-space: nowrap;
        }
        .compliance-table .cell-student-name { font-weight: 600; }

        /* Progress bar */
        .progress-bar-wrap {
            width: 100%; max-width: 160px;
            background: rgba(255,255,255,0.06);
            border-radius: 20px;
            height: 8px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 20px;
            transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
            background: linear-gradient(90deg, var(--accent-green), #34d399);
        }
        .progress-bar-fill.low { background: linear-gradient(90deg, var(--accent-red), #f87171); }
        .progress-bar-fill.mid { background: linear-gradient(90deg, var(--accent-orange), #fbbf24); }
        .pct-text {
            font-size: 0.75rem; font-weight: 600;
            margin-top: 3px;
        }
        .pct-text.full { color: var(--accent-green); }
        .pct-text.mid { color: var(--accent-orange); }
        .pct-text.low { color: var(--accent-red); }

        /* Missing docs tags */
        .missing-tags {
            display: flex; flex-wrap: wrap; gap: 4px;
            max-width: 280px;
        }
        .missing-tag {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px;
            background: rgba(239,68,68,0.12);
            color: #f87171;
            border-radius: 6px;
            font-size: 0.7rem; font-weight: 500;
            white-space: nowrap;
        }
        .missing-tag i { font-size: 0.6rem; }

        .complete-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            background: rgba(16,185,129,0.15);
            color: #10b981;
            border-radius: 8px;
            font-size: 0.75rem; font-weight: 600;
        }

        /* Reminder button */
        .btn-remind {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px;
            background: linear-gradient(135deg, var(--accent-gold), #eab308);
            color: #000; font-weight: 600;
            border: none; border-radius: 8px;
            font-size: 0.78rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            box-shadow: 0 3px 8px rgba(201,162,39,0.25);
            white-space: nowrap;
        }
        .btn-remind:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(201,162,39,0.35);
            filter: brightness(1.1);
        }
        .btn-remind:active { transform: scale(0.97); }
        .btn-remind:disabled {
            opacity: 0.5; cursor: not-allowed;
            transform: none !important; filter: none !important;
        }
        .btn-remind.sent {
            background: linear-gradient(135deg, var(--accent-green), #34d399);
            color: white;
        }

        /* Toast */
        .toast-container {
            position: fixed; top: 24px; right: 24px;
            z-index: 9999; display: flex; flex-direction: column;
            gap: 8px;
        }
        .toast {
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 0.85rem; font-weight: 500;
            color: white;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 2.7s forwards;
            backdrop-filter: blur(10px);
        }
        .toast.success { background: rgba(16,185,129,0.9); }
        .toast.error { background: rgba(239,68,68,0.9); }

        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            to { transform: translateX(100px); opacity: 0; }
        }

        /* ─── RESPONSIVE ─── */
        .mobile-toggle {
            display: none; position: fixed;
            top: 15px; left: 15px; z-index: 1100;
            background: var(--accent-gold); color: white;
            border: none; padding: 10px; border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .mobile-toggle i { font-size: 20px; }
        .sidebar-overlay {
            display: none; position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 999;
        }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        @media (max-width: 1024px) {
            .sidebar {
                position: fixed; left: 0; top: 0; bottom: 0; height: 100%;
                z-index: 1050; transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active { transform: translateX(0); }
            .sidebar .sidebar-logo { padding-top: 65px; }
            .mobile-toggle { display: block; }
            .topbar {
                padding: 15px; padding-left: 65px;
                flex-direction: column; align-items: stretch; gap: 15px;
            }
            .charts-grid { grid-template-columns: 1fr; }
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
            .content { padding: 15px; }
        }

        @media (max-width: 768px) {
            .summary-cards { grid-template-columns: 1fr 1fr; gap: 10px; }
            .compliance-table { font-size: 0.8rem; }
            .compliance-header { flex-direction: column; align-items: stretch; }
            .compliance-search { min-width: unset; }

            /* One card per student instead of a sideways scroll */
            .table-scroll { overflow-x: visible; }
            .compliance-table thead { display: none; }
            .compliance-table tbody tr {
                display: block;
                margin-bottom: 12px;
                padding: 12px 14px;
                background: rgba(255,255,255,0.03);
                border: 1px solid rgba(255,255,255,0.06);
                border-radius: 12px;
            }
            .compliance-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
                padding: 7px 0;
                text-align: right;
                word-break: break-word;
            }
            .compliance-table tbody td::before {
                content: attr(data-label);
                flex: 0 0 auto;
                font-weight: 700;
                font-size: 0.68rem;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: var(--text-muted);
                text-align: left;
            }
            /* The student number heads the card */
            .compliance-table tbody td.cell-student-id {
                border-bottom: 1px solid rgba(255,255,255,0.06);
                padding-bottom: 10px;
                margin-bottom: 4px;
            }
            .compliance-table .progress-bar-wrap { max-width: 120px; }
            .compliance-table .missing-tags { justify-content: flex-end; }
            .compliance-table .btn-remind { width: 100%; justify-content: center; min-height: 40px; }
            .compliance-table tbody td[colspan] { display: block; text-align: center; }
            .compliance-table tbody td[colspan]::before { content: none; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3f55; border-radius: 3px; }

        /* Send All Reminder */
        .btn-excel {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: 10px; text-decoration: none;
            background: rgba(16, 185, 129, 0.14); color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.4);
            font-family: 'Inter', sans-serif; font-size: 0.82rem; font-weight: 600; white-space: nowrap;
            transition: background 0.15s, color 0.15s;
        }
        .btn-excel:hover { background: #10b981; color: #fff; border-color: #10b981; }
        .btn-send-all {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 18px;
            background: linear-gradient(135deg, var(--accent-blue), #2563eb);
            color: white; font-weight: 600;
            border: none; border-radius: 10px;
            font-size: 0.82rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            box-shadow: 0 4px 10px rgba(59,130,246,0.3);
        }
        .btn-send-all:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(59,130,246,0.4);
        }

        /* Table scroll container */
        .table-scroll {
            overflow-x: auto;
            border-radius: 10px;
        }
    </style>
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
    <script src="search_helper.js?v=3"></script>
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="student_profile.css?v=11">
    <script src="student_profile.js?v=3" defer></script>
</head>
<body>
    <!-- Mobile Top Bar -->
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="registrar_dashboard.php" class="mobile-appbar-brand">
            <img src="logo.jpg" alt="">
            <span>Registrar</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box">
                <img src="logo.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span class="sidebar-brand">Registrar</span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="registrar_dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="analytics.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendar</a>
            <?= submissionsNavLink($mysqli) ?>
            <a href="reject.php" class="nav-link">
                <i class="fas fa-times-circle"></i> Rejected Documents
            </a>
            <?= requestsNavLink($mysqli) ?>
            <a href="notifications.php" class="nav-link">
                <i class="fas fa-bell"></i> Notifications <?= ($pending_count ?? 0) > 0 ? '<span class="nav-badge">'.($pending_count ?? 0).'</span>' : '' ?>
            </a>
            <a href="archive.php" class="nav-link"><i class="fas fa-archive"></i> Archived Students</a>
            <a href="typesofdocuments.php" class="nav-link"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
            <a href="registrar_settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
        </nav>

        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1><i class="fas fa-chart-bar" style="color: var(--accent-gold); margin-right: 8px;"></i>Analytics & Reports</h1>
                    <p>Document submission statistics and student compliance overview</p>
                </div>
            </div>
            <div class="topbar-right">
                <div class="user-profile">
                    <div class="user-avatar"><?= $initials ?></div>
                    <div class="user-info">
                        <div class="name"><?= htmlspecialchars($full_name) ?></div>
                        <div class="role">Registrar</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <!-- FILTERS: narrow the whole report — cards, charts and table -->
            <form class="filter-bar" method="get" action="analytics.php" id="filterForm">
                <div class="filter-head">
                    <i class="fas fa-filter"></i>
                    <div>
                        <div class="filter-title">Filter Report</div>
                        <div class="filter-sub">Applies to the summary, the charts and the compliance list</div>
                    </div>
                </div>
                <div class="filter-field">
                    <label for="fCourse">Course / Program</label>
                    <select name="course" id="fCourse">
                        <option value="">All courses (<?= (int)$all_courses_total ?>)</option>
                        <?php foreach ($course_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= strcasecmp($opt, $filter_course) === 0 ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?> (<?= (int)($course_counts[$opt] ?? 0) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field filter-field-year">
                    <label for="fYear">Year Level</label>
                    <select name="year" id="fYear">
                        <option value="">All year levels (<?= (int)$all_years_total ?>)</option>
                        <?php foreach ($year_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= strcasecmp($opt, $filter_year) === 0 ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?> (<?= (int)($year_counts[$opt] ?? 0) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="filter-apply" id="filterApply"><i class="fas fa-check"></i> Apply</button>
                    <?php if ($is_filtered): ?>
                        <a href="analytics.php" class="filter-clear"><i class="fas fa-xmark"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="filter-summary">
                <i class="fas fa-circle-info"></i>
                <span>
                    Showing <strong><?= number_format($total_students) ?></strong> verified student<?= $total_students === 1 ? '' : 's' ?>
                    <?php if ($is_filtered): ?>
                        in
                        <?php if ($filter_course !== ''): ?><span class="filter-chip"><?= htmlspecialchars($filter_course) ?></span><?php endif; ?>
                        <?php if ($filter_year !== ''): ?><span class="filter-chip"><?= htmlspecialchars($filter_year) ?></span><?php endif; ?>
                    <?php else: ?>
                        across all courses and year levels<?= !empty($user_institution) ? ' of ' . htmlspecialchars($user_institution) : '' ?>
                    <?php endif; ?>
                </span>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="sc-icon blue"><i class="fas fa-file-alt"></i></div>
                    <div class="sc-value"><?= number_format($stats['total']) ?></div>
                    <div class="sc-label">Total Submissions</div>
                </div>
                <div class="summary-card">
                    <div class="sc-icon green"><i class="fas fa-user-graduate"></i></div>
                    <div class="sc-value"><?= $complete_students ?> <span style="font-size: 0.85rem; color: var(--text-muted);">/ <?= $total_students ?></span></div>
                    <div class="sc-label">Students Complete (100%)</div>
                </div>
                <div class="summary-card">
                    <div class="sc-icon gold"><i class="fas fa-percentage"></i></div>
                    <div class="sc-value"><?= $avg_completion ?>%</div>
                    <div class="sc-label">Average Completion Rate</div>
                </div>
                <div class="summary-card">
                    <div class="sc-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="sc-value"><?= $zero_students ?></div>
                    <div class="sc-label">Students with No Submissions</div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-grid">
                <!-- Doughnut Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Submission Status</h3>
                    <div class="chart-wrapper doughnut">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                <!-- Bar Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Per Document Type</h3>
                    <div class="chart-wrapper">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
                <!-- Line Chart -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Daily Submissions (Last 7 Days)</h3>
                    <div class="chart-wrapper">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- STUDENT COMPLIANCE TABLE -->
            <div class="compliance-card">
                <div class="compliance-header">
                    <h3><i class="fas fa-clipboard-check"></i> Student Document Compliance</h3>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="compliance-search">
                            <i class="fas fa-search"></i>
                            <input type="search" id="studentSearch" placeholder="Search name or student ID..." aria-label="Search students" autocomplete="off">
                        </div>
                        <span class="search-count" id="searchCount" aria-live="polite"></span>
                        <?php if (!empty($students)): ?>
                        <?php /* The same students as this table: the course and year chosen above. */ ?>
                        <a class="btn-excel" href="export_student_profiles.php?<?= htmlspecialchars(http_build_query(array_filter(['course' => $filter_course, 'year' => $filter_year], 'strlen'))) ?>"
                           title="Download the profiles of the students listed here as an Excel file">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($students)): ?>
                        <button class="btn-send-all" onclick="sendAllReminders()" id="btnSendAll">
                            <i class="fas fa-bell"></i> Notify All Incomplete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-scroll">
                    <table class="compliance-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student</th>
                                <th>Course / Year</th>
                                <th>Progress</th>
                                <th>Missing Documents</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                        <?= $is_filtered ? 'No verified students match this course and year level.' : 'No verified students found.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $s): ?>
                                    <tr class="student-row" data-student-id="<?= $s['id'] ?>"
                                        data-search="<?= htmlspecialchars(mb_strtolower(($s['student_id'] ?? '') . ' ' . $s['full_name'] . ' ' . ($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '') . ' ' . ($s['email'] ?? ''))) ?>">
                                        <td data-label="Student ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($s['student_id'] ?? '')) ?></td>
                                        <td data-label="Student" class="cell-student-name"><span class="sp-link" role="button" tabindex="0" data-profile-id="<?= (int)$s['id'] ?>" title="View student profile"><?= htmlspecialchars($s['full_name']) ?></span></td>
                                        <td data-label="Course / Year">
                                            <?= htmlspecialchars($s['course'] ?? '') ?>
                                            <?php if (!empty($s['year_level'])): ?>
                                                <span style="color: var(--text-muted);">- <?= htmlspecialchars($s['year_level']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Progress">
                                            <div class="progress-bar-wrap">
                                                <div class="progress-bar-fill <?= $s['pct'] < 40 ? 'low' : ($s['pct'] < 80 ? 'mid' : '') ?>" 
                                                     style="width: 0%;" data-target="<?= $s['pct'] ?>"></div>
                                            </div>
                                            <div class="pct-text <?= $s['pct'] >= 100 ? 'full' : ($s['pct'] >= 40 ? 'mid' : 'low') ?>">
                                                <?= $s['submitted_count'] ?>/<?= $total_required ?> (<?= $s['pct'] ?>%)
                                            </div>
                                        </td>
                                        <td data-label="Missing Documents">
                                            <?php if (empty($s['missing'])): ?>
                                                <span class="complete-badge"><i class="fas fa-check-circle"></i> All Complete</span>
                                            <?php else: ?>
                                                <div class="missing-tags">
                                                    <?php foreach ($s['missing'] as $m): ?>
                                                        <span class="missing-tag"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($m) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action">
                                            <?php if (!empty($s['missing'])): ?>
                                                <button class="btn-remind" 
                                                        onclick="sendReminder(<?= $s['id'] ?>, <?= htmlspecialchars(json_encode($s['full_name']), ENT_QUOTES) ?>, this)"
                                                        data-missing="<?= htmlspecialchars(implode(',', $s['missing'])) ?>">
                                                    <i class="fas fa-bell"></i> Send Reminder
                                                </button>
                                            <?php else: ?>
                                                <span style="color: var(--accent-green); font-size: 0.8rem; font-weight: 600;">
                                                    <i class="fas fa-check-double"></i> Complete
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="no-match-row" id="noMatchRow" hidden>
                                    <td colspan="6">No student matches your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // ─── THEME RESTORE ───
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('registrar-theme-color');
            if (savedTheme) document.documentElement.style.setProperty('--accent-gold', savedTheme);
            const savedBg = localStorage.getItem('registrar-bg-color');
            if (savedBg) document.documentElement.style.setProperty('--main-bg', savedBg);
            const savedSidebarBg = localStorage.getItem('registrar-sidebar-bg');
            if (savedSidebarBg) document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBg);

            // Animate progress bars
            setTimeout(() => {
                document.querySelectorAll('.progress-bar-fill').forEach(bar => {
                    bar.style.width = bar.dataset.target + '%';
                });
            }, 200);
        });

        // ─── CHARTS ───
        const chartTextColor = '#94a3b8';
        const gridColor = 'rgba(255,255,255,0.05)';

        // Status Doughnut
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Rejected'],
                datasets: [{
                    data: [<?= $stats['approved'] ?>, <?= $stats['pending'] ?>, <?= $stats['rejected'] ?>],
                    backgroundColor: [
                        'rgba(16,185,129,0.85)',
                        'rgba(245,158,11,0.85)',
                        'rgba(239,68,68,0.85)'
                    ],
                    borderColor: '#1e293b',
                    borderWidth: 3,
                    hoverBorderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: chartTextColor, padding: 16, usePointStyle: true, pointStyleWidth: 12, font: { size: 12 } }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#94a3b8',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.raw} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Per Type Bar
        new Chart(document.getElementById('typeChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($per_type, 'name')) ?>,
                datasets: [{
                    label: 'Submissions',
                    data: <?= json_encode(array_column($per_type, 'count')) ?>,
                    backgroundColor: [
                        'rgba(59,130,246,0.7)', 'rgba(139,92,246,0.7)', 'rgba(16,185,129,0.7)',
                        'rgba(245,158,11,0.7)', 'rgba(239,68,68,0.7)', 'rgba(201,162,39,0.7)',
                        'rgba(236,72,153,0.7)', 'rgba(14,165,233,0.7)'
                    ],
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b', titleColor: '#f1f5f9', bodyColor: '#94a3b8',
                        borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1, cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: chartTextColor, font: { size: 11 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: chartTextColor, font: { size: 11 } }
                    }
                }
            }
        });

        // Daily Line
        new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($daily, 'day')) ?>,
                datasets: [{
                    label: 'Submissions',
                    data: <?= json_encode(array_column($daily, 'count')) ?>,
                    borderColor: 'rgba(201,162,39,1)',
                    backgroundColor: 'rgba(201,162,39,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(201,162,39,1)',
                    pointBorderColor: '#1e293b',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b', titleColor: '#f1f5f9', bodyColor: '#94a3b8',
                        borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1, cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: chartTextColor, font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: chartTextColor, font: { size: 11 }, stepSize: 1 }
                    }
                }
            }
        });

        // ─── FILTERS ───
        // The course and year selects apply as soon as one changes (Apply is the
        // no-JS fallback); an empty choice is left out of the URL.
        (function () {
            const form = document.getElementById('filterForm');
            if (!form) return;
            document.documentElement.classList.add('js');
            form.querySelectorAll('select').forEach(sel => sel.addEventListener('change', () => {
                const params = new URLSearchParams();
                form.querySelectorAll('select').forEach(s => { if (s.value) params.set(s.name, s.value); });
                form.classList.add('is-loading');
                const qs = params.toString();
                window.location.href = 'analytics.php' + (qs ? '?' + qs : '');
            }));
        })();

        // ─── SEARCH ───
        // Instant, within the filtered list: matches the student ID, the name in
        // either order and the e-mail, every word anywhere.
        (function () {
            const input = document.getElementById('studentSearch');
            const count = document.getElementById('searchCount');
            const noMatch = document.getElementById('noMatchRow');
            const rows = Array.from(document.querySelectorAll('.compliance-table .student-row'));

            function apply() {
                const words = SearchKit.tokens(input.value);
                let shown = 0;
                rows.forEach(row => {
                    const ok = SearchKit.matches(row, words);
                    row.hidden = !ok;
                    if (ok) shown++;
                });
                if (noMatch) noMatch.hidden = shown > 0 || rows.length === 0;
                if (count) count.textContent = rows.length ? (words.length ? `${shown} of ${rows.length} shown` : `${rows.length} student${rows.length === 1 ? '' : 's'}`) : '';
            }
            SearchKit.bind(input, apply);
            apply();
        })();

        // ─── SEND REMINDER ───
        function sendReminder(studentId, studentName, btn) {
            const missing = btn.getAttribute('data-missing');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            fetch('send_doc_reminder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `student_id=${studentId}&missing_docs=${encodeURIComponent(missing)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.classList.add('sent');
                    btn.innerHTML = '<i class="fas fa-check"></i> Sent!';
                    showToast('success', `Reminder sent to ${studentName}`);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-bell"></i> Send Reminder';
                    showToast('error', data.message || 'Failed to send reminder');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-bell"></i> Send Reminder';
                showToast('error', 'Network error. Please try again.');
            });
        }

        // ─── SEND ALL REMINDERS ───
        function sendAllReminders() {
            const btn = document.getElementById('btnSendAll');
            // Only the students on screen: the course/year filter and the search
            // decide who "all" means.
            const buttons = Array.from(document.querySelectorAll('.student-row:not([hidden]) .btn-remind:not(.sent):not(:disabled)'));
            if (buttons.length === 0) {
                showToast('error', 'No incomplete students to notify.');
                return;
            }
            uiConfirm({
                title: 'Send reminders',
                message: `Send reminders to ${buttons.length} student(s) with incomplete documents?`,
                confirmLabel: 'Send reminders'
            }).then(ok => { if (ok) runAllReminders(btn, buttons); });
        }

        function runAllReminders(btn, buttons) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            let sent = 0;
            buttons.forEach(b => {
                const studentId = b.closest('tr').dataset.studentId;
                const missing = b.getAttribute('data-missing');
                
                fetch('send_doc_reminder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `student_id=${studentId}&missing_docs=${encodeURIComponent(missing)}`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        b.classList.add('sent');
                        b.innerHTML = '<i class="fas fa-check"></i> Sent!';
                        b.disabled = true;
                    }
                    sent++;
                    if (sent >= buttons.length) {
                        btn.innerHTML = '<i class="fas fa-check-double"></i> All Sent!';
                        showToast('success', `Reminders sent to ${buttons.length} student(s)`);
                    }
                })
                .catch(() => { sent++; });
            });
        }

        // ─── TOAST ───
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // ─── SIDEBAR MOBILE ───
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }
        function closeSidebar() {
            document.querySelector('.sidebar').classList.remove('active');
            document.body.classList.remove('sidebar-open');
        }
    </script>
</body>
</html>
