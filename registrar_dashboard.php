<?php
session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'name_helper.php';

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

// --- FETCH DASHBOARD STATISTICS ---
$stats = ['total_docs' => 0, 'approved' => 0, 'pending' => 0, 'active_students' => 0];

// The four cards count this registrar's institution only - the same scope as
// Recent Submissions below and as Submissions / Analytics, which they link to.
$stats_inst = $user_data['institution'] ?? '';
$stats_scope = $stats_inst !== '' ? ' AND u.institution = ?' : '';
$stats_query = function ($sql) use ($mysqli, $stats_inst, $stats_scope) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    if ($stats_scope !== '') $stmt->bind_param('s', $stats_inst);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

// Active Students: verified accounts, as Analytics counts them
$stats['active_students'] = (int)($stats_query("SELECT COUNT(*) AS count FROM users u
    WHERE u.role = 'student' AND u.is_verified = 2" . $stats_scope)['count'] ?? 0);

// Document Submissions (Counts)
$data = $stats_query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN d.status = 'approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN d.status = 'pending' THEN 1 ELSE 0 END) AS pending
    FROM documents d JOIN users u ON d.user_id = u.id WHERE 1 = 1" . $stats_scope);
$stats['total_docs'] = (int)($data['total'] ?? 0);
$stats['approved'] = (int)($data['approved'] ?? 0);
$stats['pending'] = (int)($data['pending'] ?? 0);
$pending_count = $stats['pending'];

// Fetch pending students count for notification badge
$pending_students_count = 0;
$user_inst = $user_data['institution'] ?? '';
if (!empty($user_inst)) {
    $stmt_ps = $mysqli->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND is_verified = 1 AND institution = ?");
    $stmt_ps->bind_param("s", $user_inst);
    $stmt_ps->execute();
    $res_ps = $stmt_ps->get_result();
    if ($res_ps) $pending_students_count = $res_ps->fetch_assoc()['count'];
    $stmt_ps->close();
} else {
    $res_ps = $mysqli->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND is_verified = 1");
    if ($res_ps) $pending_students_count = $res_ps->fetch_assoc()['count'];
}

// Recent Submissions List — scoped to this registrar's institution, like the counts above
//
// The card has a tab per status. Filtering only the five newest files would
// leave a tab empty whenever newer uploads pushed its rows out, so each status
// brings its own five newest as well; every row says whether it is one of the
// five newest overall (`in_recent`, the default "Recent" tab). The number on a
// tab is the real total for that status, not the handful listed.
$recent_submissions = [];
$recent_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$sub_query = "SELECT d.*, u.first_name, u.middle_name, u.last_name, u.student_id FROM documents d
              JOIN users u ON d.user_id = u.id ";

$recent_fetch = function ($status = null) use ($mysqli, $sub_query, $user_inst) {
    $where = [];
    $types = '';
    $params = [];
    if (!empty($user_inst)) { $where[] = 'u.institution = ?'; $types .= 's'; $params[] = $user_inst; }
    if ($status !== null)   { $where[] = 'd.status = ?';      $types .= 's'; $params[] = $status; }
    $stmt = $mysqli->prepare($sub_query . ($where ? 'WHERE ' . implode(' AND ', $where) . ' ' : '') . 'ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 5');
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

foreach ($recent_fetch() as $row) {
    $recent_submissions[(int)$row['id']] = $row + ['in_recent' => true];
}
foreach (array_keys($recent_counts) as $recent_status) {
    foreach ($recent_fetch($recent_status) as $row) {
        if (!isset($recent_submissions[(int)$row['id']])) {
            $recent_submissions[(int)$row['id']] = $row + ['in_recent' => false];
        }
    }
}
usort($recent_submissions, fn($a, $b) => [$b['uploaded_at'], $b['id']] <=> [$a['uploaded_at'], $a['id']]);

$count_stmt = $mysqli->prepare("SELECT d.status, COUNT(*) AS n FROM documents d JOIN users u ON d.user_id = u.id "
    . (!empty($user_inst) ? "WHERE u.institution = ? " : "") . "GROUP BY d.status");
if ($count_stmt) {
    if (!empty($user_inst)) $count_stmt->bind_param("s", $user_inst);
    $count_stmt->execute();
    foreach ($count_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key = strtolower((string)$row['status']);
        if (isset($recent_counts[$key])) $recent_counts[$key] = (int)$row['n'];
    }
    $count_stmt->close();
}

// --- FETCH PENDING STUDENT REGISTRATIONS ---
$pending_students = [];
$student_query = "SELECT id, first_name, middle_name, last_name, email, student_id, created_at FROM users WHERE role = 'student' AND is_verified = 1 ";
if (!empty($user_inst)) {
    $stu_stmt = $mysqli->prepare($student_query . "AND institution = ? ORDER BY created_at DESC LIMIT 5");
    $stu_stmt->bind_param("s", $user_inst);
    $stu_stmt->execute();
    $res_students = $stu_stmt->get_result();
    if ($res_students) while($row = $res_students->fetch_assoc()) $pending_students[] = $row;
    $stu_stmt->close();
} else {
    $res_students = $mysqli->query($student_query . "ORDER BY created_at DESC LIMIT 5");
    if ($res_students) while($row = $res_students->fetch_assoc()) $pending_students[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=4" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=11">
    <title>Registrar Dashboard - School Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        /* ─── SIDEBAR ─── */
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
            width: 48px;
            height: 48px;
            background: transparent;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 2px;
        }

        .sidebar-brand {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.02em;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 12px;
        }

        .nav-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 0 8px;
            margin: 16px 0 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 2px;
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

        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 18px;
        }

        .nav-badge {
            margin-left: auto;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
        }

        .sidebar-bottom {
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            color: #ef4444;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.2s;
        }

        .logout-link i {
            width: 24px;
            text-align: center;
            font-size: 18px;
        }

        .logout-link:hover {
            background: rgba(239,68,68,0.1);
        }

        /* ─── MAIN CONTENT ─── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ─── TOPBAR ─── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
            background: #0f172a;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            gap: 24px;
        }

        .topbar-left h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .topbar-left p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .search-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 9px 16px;
            min-width: 260px;
        }

        .search-bar i {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .search-bar input {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            width: 100%;
        }

        .search-bar input::placeholder {
            color: var(--text-muted);
        }

        .topbar-icon-btn {
            position: relative;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1e293b;
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: background 0.2s;
            border: none;
        }

        .topbar-icon-btn:hover {
            background: #2d3f55;
        }

        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s, box-shadow 0.2s, background 0.2s;
            font-size: 0.8rem;
        }
        .action-btn:hover { transform: scale(1.1); }
        .action-btn:active { transform: scale(0.95); }
        .btn-check { background: rgba(16,185,129,0.2); color: #10b981; }
        .btn-wrong { background: rgba(239,68,68,0.2); color: #ef4444; }
        .btn-view {
            width: auto;
            height: auto;
            padding: 6px 14px;
            border-radius: 8px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            text-decoration: none;
            font-weight: 600;
            gap: 6px;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-view:hover {
            transform: translateY(-2px) scale(1) !important;
            box-shadow: 0 6px 15px rgba(59, 130, 246, 0.4);
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
            color: white;
        }

        .topbar-icon-btn .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 14px;
            height: 14px;
            background: #ef4444;
            border-radius: 50%;
            font-size: 0.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: #2d3f55;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: #ffffff;
        }

        .user-info .name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-info .role {
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        /* ─── CONTENT AREA ─── */
        .content {
            flex: 1;
            padding: 28px 32px;
            overflow-y: auto;
        }

        /* ─── STAT CARDS ─── */
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 22px 24px;
            position: relative;
            border: 1px solid rgba(255,255,255,0.04);
            text-decoration: none;
            display: block;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        /* Each card is a link: to its page, or to its tab of Recent Submissions */
        .stat-card { color: inherit; }
        .stat-card:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
        .stat-link {
            display: inline-flex; align-items: center; gap: 6px; margin-left: auto;
            font-size: 0.72rem; font-weight: 600; color: var(--text-muted);
            opacity: 0.75; transition: color 0.2s, opacity 0.2s;
        }
        .stat-link i { font-size: 0.65rem; transition: transform 0.2s; }
        .stat-card:hover .stat-link, .stat-card:focus-visible .stat-link { color: var(--accent-gold); opacity: 1; }
        .stat-card:hover .stat-link i { transform: translateX(3px); }
        @media (max-width: 1024px) { .stat-link { display: none; } }
        /* The card a stat card jumped to blinks once, so the eye lands on it */
        .table-card.is-flash { animation: cardFlash 1.2s ease; }
        @keyframes cardFlash { 0%, 100% { box-shadow: none; } 25% { box-shadow: 0 0 0 2px var(--accent-gold); } }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--accent-gold);
            box-shadow: 0 10px 20px -10px rgba(0, 0, 0, 0.5);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-icon.blue    { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .stat-icon.green   { background: rgba(16,185,129,0.15); color: #10b981; }
        .stat-icon.orange  { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .stat-icon.purple  { background: rgba(139,92,246,0.15); color: #8b5cf6; }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        /* ─── BOTTOM SECTION ─── */
        .bottom-section {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 20px;
        }

        /* ─── TABLE CARD ─── */
        .table-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .view-all {
            font-size: 0.8rem;
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 500;
        }

        .view-all:hover { text-decoration: underline; }

        /* Recent Submissions: a tab per status, like the Submissions / Rejected pages */
        .recent-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: -8px 0 16px; }
        .recent-tab {
            display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0;
            padding: 6px 12px; border-radius: 20px; cursor: pointer;
            border: 1px solid rgba(255,255,255,0.09); background: transparent;
            color: var(--text-secondary); font-family: inherit; font-size: 0.78rem; font-weight: 600;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .recent-tab:hover { border-color: rgba(255,255,255,0.22); color: var(--text-primary); }
        .recent-tab:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 2px; }
        .recent-tab.is-active { background: rgba(201,162,39,0.14); border-color: var(--accent-gold); color: var(--accent-gold); }
        .recent-dot { width: 7px; height: 7px; border-radius: 50%; background: #64748b; }
        .recent-tab[data-status="pending"] .recent-dot  { background: #f59e0b; }
        .recent-tab[data-status="approved"] .recent-dot { background: #10b981; }
        .recent-tab[data-status="rejected"] .recent-dot { background: #ef4444; }
        .recent-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 19px; height: 19px; padding: 0 6px; border-radius: 10px;
            background: rgba(255,255,255,0.08); color: var(--text-secondary); font-size: 0.68rem; font-weight: 700;
        }
        .recent-tab[data-status="pending"] .recent-count.has  { background: #f59e0b; color: #1e293b; }
        .recent-tab[data-status="approved"] .recent-count.has { background: #10b981; color: #052e1f; }
        .recent-tab[data-status="rejected"] .recent-count.has { background: #ef4444; color: #fff; }
        .recent-empty { margin: 0; padding: 30px 0 14px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
        .recent-empty[hidden] { display: none; }
        .doc-type-name { display: block; font-weight: 600; }
        .doc-file-sub { display: block; font-size: 0.75rem; color: var(--text-muted); font-weight: 400; }
        @media (max-width: 600px) {
            /* One row that scrolls sideways instead of wrapping into three */
            .recent-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px; scrollbar-width: none; }
            .recent-tabs::-webkit-scrollbar { display: none; }
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 0 0 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }

        tbody tr:last-child { border-bottom: none; }

        tbody td {
            padding: 14px 0;
            font-size: 0.85rem;
            color: var(--text-primary);
            vertical-align: middle;
        }

        .doc-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doc-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .doc-icon.pdf  { background: rgba(239,68,68,0.15); color: #ef4444; }
        .doc-icon.docx { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .doc-icon.img  { background: rgba(245,158,11,0.15); color: #f59e0b; }

        .doc-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .doc-meta {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-badge.approved  { background: rgba(16,185,129,0.15); color: #10b981; }
        .status-badge.approved::before  { background: #10b981; }
        .status-badge.pending   { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .status-badge.pending::before   { background: #f59e0b; }
        .status-badge.rejected  { background: rgba(239,68,68,0.15); color: #ef4444; }
        .status-badge.rejected::before  { background: #ef4444; }
        .status-badge.in-review { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .status-badge.in-review::before { background: #3b82f6; }

        /* ─── ACTIVITY CARD ─── */
        .activity-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            flex-shrink: 0;
            color: white;
        }

        .av-sj { background: #10b981; }
        .av-mc { background: #3b82f6; }
        .av-er { background: #f59e0b; }
        .av-dk { background: #8b5cf6; }

        .activity-text {
            flex: 1;
        }

        .activity-text strong {
            font-weight: 600;
            color: var(--text-primary);
        }

        .activity-text span {
            color: var(--text-secondary);
            font-size: 0.83rem;
        }

        .activity-time {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Responsive Table for Students */
        @media (max-width: 768px) {
            .student-table thead, .table-card table thead { display: none; }
            .student-table tr, .table-card table tr {
                display: block;
                margin-bottom: 15px;
                background: var(--card-bg);
                border: 1px solid rgba(255,255,255,0.04);
                border-radius: 10px;
                padding: 10px;
            }
            .student-table td, .table-card table td { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                border: none; padding: 8px 10px; 
            }
            .student-table td::before, .table-card table td::before { 
                content: attr(data-label); 
                font-weight: 700; color: var(--text-muted); font-size: 12px; 
                text-transform: uppercase;
            }
        }

        /* ─── CONTENT SECTIONS ─── */
        .content-section {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        .content-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .reject-modal, .broadcast-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(6px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .reject-modal-content, .broadcast-modal-content {
            background: var(--card-bg, #1e293b);
            padding: 28px;
            border-radius: 14px;
            width: 90%;
            max-width: 450px;
            color: var(--text-primary, #f1f5f9);
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.1);
            transform: scale(0.95);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .reject-modal.show, .broadcast-modal.show {
            opacity: 1;
        }
        .reject-modal.show .reject-modal-content, .broadcast-modal.show .broadcast-modal-content {
            transform: scale(1);
        }
        .broadcast-modal-content {
            padding: 32px;
            max-width: 500px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .reject-modal-content h3, .broadcast-modal-content h3 {
            margin-bottom: 12px;
        }
        .reject-modal-content textarea, .broadcast-modal-content textarea {
            width: 100%;
            height: 120px;
            padding: 12px;
            border-radius: 8px;
            border: 2px solid var(--accent-gold, #c9a227);
            background: rgba(0,0,0,0.2);
            color: white;
            resize: vertical;
            margin-bottom: 20px;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        .reject-modal-actions, .broadcast-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .reject-modal-actions button, .broadcast-modal-actions button {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-family: inherit;
        }
        .reject-modal-actions .btn-cancel, .broadcast-modal-actions .btn-cancel {
            background: var(--main-bg);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        .reject-modal-actions .btn-confirm, .broadcast-modal-actions .btn-confirm {
            background: #ef4444;
            color: white;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .reject-modal-actions .btn-confirm:hover, .broadcast-modal-actions .btn-confirm:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .broadcast-modal-actions .btn-confirm {
            background: linear-gradient(135deg, var(--accent-gold) 0%, #eab308 100%);
            color: #000;
            font-weight: 700;
            box-shadow: 0 4px 10px rgba(201, 162, 39, 0.3);
        }

        #calendar-container {
            background: var(--card-bg);
            padding: 24px;
             border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.04);
        }
        .fc .fc-toolbar-title, .fc .fc-col-header-cell-cushion, .fc .fc-daygrid-day-number {
            color: var(--text-primary);
        }

        /* ─── RESPONSIVE ADDITIONS ─── */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1100;
            background: var(--accent-gold);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .mobile-toggle i { font-size: 20px; }

        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                left: 0; top: 0; bottom: 0; height: 100%;
                z-index: 1050;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active { transform: translateX(0); }
            .sidebar .sidebar-logo { padding-top: 65px; }
            .mobile-toggle { display: block; }
            .topbar {
                padding: 15px;
                padding-left: 65px;
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            .topbar-right { justify-content: space-between; }
            .search-bar { min-width: unset; flex: 1; }
            .stat-cards { grid-template-columns: repeat(4, 1fr); gap: 10px; }
            .stat-card { padding: 12px 8px; border-radius: 10px; }
            .stat-card-header { margin-bottom: 8px; justify-content: center; }
            .stat-icon { width: 32px; height: 32px; font-size: 0.85rem; border-radius: 8px; }
            .stat-value { font-size: 1.1rem; text-align: center; margin-bottom: 4px; }
            .stat-label { font-size: 0.55rem; text-align: center; line-height: 1.2; word-wrap: break-word; }
            .bottom-section { grid-template-columns: 1fr; }
            .content { padding: 15px; }
            .mobile-topbar-logo { display: block !important; }
        }

        @media (max-width: 768px) {
            .stat-cards { gap: 6px; }
            .stat-card { padding: 10px 5px; }
            .stat-value { font-size: 1rem; }
            .stat-label { font-size: 0.5rem; }
        }

        /* Ensure the iframe fills its container */
        #view-document-section .table-card {
            height: 75vh;
            display: flex;
            flex-direction: column;
            padding: 12px; 
            overflow: hidden;
            width: 100%;
            border-radius: 14px;
            background: transparent;
            transition: all 0.3s ease;
        }

        #doc-viewer {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px; 
            background: transparent;
        }
        /* Adjust content area to allow full height for viewer */
        .content {
            padding: 28px 32px;
        }

        /* Back Button Style */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 10px 16px;
            background: var(--card-bg);
            color: var(--text-secondary);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            width: fit-content;
        }
        .back-button:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border-color: var(--accent-gold);
        }

        /* ─── SCROLLBAR ─── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3f55; border-radius: 3px; }
        .sidebar-nav { overflow-y: auto; min-height: 0; }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
        /* Student number leads every student row, in a tabular face so IDs line up */
        .cell-student-id {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
            font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;
        }
        .cell-student-name { font-weight: 600; }
    </style>
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
    <link rel="stylesheet" href="doc_viewer.css?v=1">
    <script src="search_helper.js?v=3"></script>
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
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
                <img src="logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span class="sidebar-brand">Registrar</span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="javascript:void(0)" class="nav-link active" onclick="showSection('dashboard', this)"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="analytics.php" class="nav-link"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendar</a>
            <?= submissionsNavLink($mysqli) ?>
            <a href="reject.php" class="nav-link">
                <i class="fas fa-times-circle"></i> Rejected Documents
            </a>
            <?= requestsNavLink($mysqli) ?>
            <a href="notifications.php" class="nav-link">
                <i class="fas fa-bell"></i> Notifications <?= ($stats['pending'] ?? 0) > 0 ? '<span class="nav-badge">'.($stats['pending'] ?? 0).'</span>' : '' ?>
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
                    <h1>Dashboard</h1>
                    <p>Welcome back, here's what's happening today</p>
                </div>
            </div>                                                                                                                                                                                                                                                                          
            <div class="topbar-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="dashboardSearch" placeholder="Search documents, students...">
                </div>
                <button class="topbar-icon-btn" onclick="openBroadcastModal()">
                    <i class="fas fa-envelope"></i>
                </button>
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
            <!-- DASHBOARD SECTION -->
            <div id="dashboard-section" class="content-section active">
            <!-- STAT CARDS -->
            <div class="stat-cards">
                <a class="stat-card" href="submissions.php">
                    <div class="stat-card-header">
                        <div class="stat-icon blue"><i class="fas fa-folder-open"></i></div>
                        <span class="stat-link">Open submissions <i class="fas fa-arrow-right"></i></span>
                    </div>
                    <div class="stat-value"><?= number_format($stats['total_docs']) ?></div>
                    <div class="stat-label">Total Documents</div>
                </a>

                <a class="stat-card" href="#recentSubmissionsCard" data-recent-tab="approved">
                    <div class="stat-card-header">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <span class="stat-link">Show approved <i class="fas fa-arrow-right"></i></span>
                    </div>
                    <div class="stat-value"><?= number_format($stats['approved']) ?></div>
                    <div class="stat-label">Approved Submissions</div>
                </a>

                <a class="stat-card" href="#recentSubmissionsCard" data-recent-tab="pending">
                    <div class="stat-card-header">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <span class="stat-link">Show pending <i class="fas fa-arrow-right"></i></span>
                    </div>
                    <div class="stat-value"><?= number_format($stats['pending']) ?></div>
                    <div class="stat-label">Pending Review</div>
                </a>

                <a class="stat-card" href="analytics.php">
                    <div class="stat-card-header">
                        <div class="stat-icon purple"><i class="fas fa-users"></i></div>
                        <span class="stat-link">Open analytics <i class="fas fa-arrow-right"></i></span>
                    </div>
                    <div class="stat-value"><?= number_format($stats['active_students']) ?></div>
                    <div class="stat-label">Active Students</div>
                </a>
            </div>

            <!-- BOTTOM SECTION -->
            <div class="bottom-section">

                <!-- RECENT SUBMISSIONS -->
                <div class="table-card" id="recentSubmissionsCard">
                    <div class="card-header">
                        <h2>Recent Submissions</h2>
                        <a href="submissions.php" class="view-all" id="recentViewAll">View All →</a>
                    </div>
                    <div class="recent-tabs" role="group" aria-label="Filter recent submissions by status">
                        <button type="button" class="recent-tab is-active" data-status="all" aria-pressed="true"><i class="fas fa-clock-rotate-left"></i> Recent</button>
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $tab_key => $tab_label): ?>
                            <button type="button" class="recent-tab" data-status="<?= $tab_key ?>" aria-pressed="false">
                                <span class="recent-dot"></span><?= $tab_label ?>
                                <span class="recent-count<?= $recent_counts[$tab_key] > 0 ? ' has' : '' ?>"><?= $recent_counts[$tab_key] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <table id="recentSubmissionsTable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student</th>
                                <th>Document</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_submissions)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No recent submissions found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_submissions as $sub):
                                $recent_ext  = strtolower(pathinfo($sub['file_path'] ?? '', PATHINFO_EXTENSION));
                                $recent_pdf  = $recent_ext === 'pdf';
                                $recent_type = trim((string)($sub['document_type'] ?? ''));
                            ?>
                                <tr data-status="<?= htmlspecialchars(strtolower($sub['status'])) ?>" data-recent="<?= !empty($sub['in_recent']) ? '1' : '0' ?>"<?= empty($sub['in_recent']) ? ' style="display:none"' : '' ?>>
                                    <td data-label="Student ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($sub['student_id'] ?? '')) ?></td>
                                    <td data-label="Student" class="cell-student-name"><?= htmlspecialchars(formatPersonNameRow($sub)) ?></td>
                                    <td data-label="Document">
                                        <div class="doc-cell">
                                            <div class="doc-icon <?= $recent_pdf ? 'pdf' : 'img' ?>"><i class="fas <?= $recent_pdf ? 'fa-file-pdf' : 'fa-file-image' ?>"></i></div>
                                            <span class="doc-name">
                                                <?php if ($recent_type !== ''): ?><strong class="doc-type-name"><?= htmlspecialchars($recent_type) ?></strong><?php endif; ?>
                                                <span class="<?= $recent_type !== '' ? 'doc-file-sub' : '' ?>"><?= htmlspecialchars($sub['file_name']) ?></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td data-label="Date"><?= date('M d, Y', strtotime($sub['uploaded_at'])) ?></td>
                                    <td data-label="Status">
                                        <span class="status-badge <?= strtolower($sub['status']) ?>">
                                            <?= ucfirst(htmlspecialchars($sub['status'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Action">
                                        <div style="display: flex; gap: 8px;">
                                            <button type="button" class="action-btn btn-view" data-file="<?= htmlspecialchars($sub['file_path']) ?>" data-title="<?= htmlspecialchars($recent_type !== '' ? $recent_type : $sub['file_name']) ?>" onclick="openPreview(this.dataset.file, this.dataset.title)" title="View Document">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="recent-empty" id="recentEmpty" hidden></p>
                </div>

                <!-- RECENT ACTIVITY -->
                <div class="activity-card">
                    <div class="card-header">
                        <h2>Recent Activity</h2>
                        <a href="#" class="view-all">See All</a>
                    </div>
                    <div class="activity-list">
                        <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 20px 0;">
                            No recent activity recorded.
                        </p>
                    </div>
                </div>

            </div><!-- /bottom-section -->

            <!-- PENDING STUDENT REGISTRATIONS -->
            <div class="table-card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>Pending Student Registrations</h2>
                    <a href="users_approved.php" class="view-all">View All →</a>
                </div>
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Registered On</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_students)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No pending student registrations.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_students as $student): ?>
                                <tr>
                                    <td data-label="Student ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($student['student_id'] ?? '')) ?></td>
                                    <td data-label="Student Name" class="cell-student-name"><?= htmlspecialchars(formatPersonNameRow($student)) ?></td>
                                    <td data-label="Email"><?= htmlspecialchars($student['email']) ?></td>
                                    <td data-label="Registered On"><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                                    <td data-label="Action">
                                        <div style="display: flex; gap: 8px;">
                                            <form action="process_student_status.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                                <input type="hidden" name="status" value="approved">
                                                <button type="submit" class="action-btn btn-check" title="Approve Registration"><i class="fas fa-check"></i></button>
                                            </form>
                                            <form action="process_student_status.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                                <input type="hidden" name="status" value="rejected">
                                                <button type="submit" class="action-btn btn-wrong" title="Reject Registration"><i class="fas fa-times"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            </div><!-- /dashboard-section -->

            <!-- CALENDAR SECTION -->
            <div id="calendar-section" class="content-section">
                <button class="back-button" onclick="showSection('dashboard', document.querySelector('.nav-link'))"><i class="fas fa-arrow-left"></i> Back to Overview</button>
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Events Calendar</h2>
                </div>
                <div id="calendar-container">
                    <div id="calendar"></div>
                </div>
            </div>

            <!-- VIEW DOCUMENT SECTION (Deprecated, now using modal) -->
            <div id="view-document-section" class="content-section" style="display:none;"></div>

        </div><!-- /content -->
    </div><!-- /main -->

    <!-- Broadcast Modal -->
    <div id="broadcastModal" class="broadcast-modal">
        <div class="broadcast-modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="margin: 0; color: var(--accent-gold); font-size: 1.4rem; letter-spacing: 1px;">ANNOUNCEMENT!!!</h3>
                <i class="fas fa-bullhorn" style="color: var(--accent-gold); font-size: 1.5rem;"></i>
            </div>
            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 20px;">Send a message to all students in your institution.</p>
            <form action="process_broadcast.php" method="POST">
                <textarea name="message" placeholder="Enter your announcement details here..." required></textarea>
                <div class="broadcast-modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeBroadcastModal()">Cancel</button>
                    <button type="submit" class="btn-confirm"><i class="fas fa-paper-plane"></i> Send Announcement</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openBroadcastModal() {
            const modal = document.getElementById('broadcastModal');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }

        function closeBroadcastModal() {
            const modal = document.getElementById('broadcastModal');
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = 'none', 300);
        }
        // Search functionality
        // Searches the tables of the section on screen (search_helper.js: every
        // word, any order, buttons ignored), and again whenever the section changes.
        // Recent Submissions adds its status tab as the search's extra condition:
        // "Recent" keeps the five newest files, a status tab that status's newest.
        let recentStatus = 'all';
        const recentTable = document.getElementById('recentSubmissionsTable');
        const recentRowOk = row => {
            if (!row.hasAttribute('data-status')) return true;          // a row of another table
            return recentStatus === 'all' ? row.dataset.recent === '1' : row.dataset.status === recentStatus;
        };

        window.runDashboardSearch = SearchKit.bind(document.getElementById('dashboardSearch'), function () {
            let activeSection = document.querySelector('.content-section.active');
            if (!activeSection) return;
            const input = document.getElementById('dashboardSearch');
            const entries = SearchKit.filterTables(input, Array.from(activeSection.querySelectorAll('table')), recentRowOk);

            // A tab with nothing in it says so (while searching, the search's own "No results" row does).
            const empty = document.getElementById('recentEmpty');
            const mine = entries.find(e => e.table === recentTable);
            if (empty && mine) {
                const searching = SearchKit.tokens(input ? input.value : '').length > 0;
                empty.hidden = !(mine.total > 0 && mine.shown === 0 && !searching);
                empty.textContent = recentStatus === 'all' ? 'No recent submissions.' : 'No ' + recentStatus + ' documents.';
            }
        });

        // Approved / Pending stat cards open that tab of Recent Submissions.
        document.querySelectorAll('.stat-card[data-recent-tab]').forEach(card => {
            card.addEventListener('click', e => {
                const tab = document.querySelector('.recent-tab[data-status="' + card.dataset.recentTab + '"]');
                const target = document.getElementById('recentSubmissionsCard');
                if (!tab || !target) return;
                e.preventDefault();
                tab.click();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                target.classList.remove('is-flash');
                void target.offsetWidth;            // restart the animation on a second click
                target.classList.add('is-flash');
            });
        });

        document.querySelectorAll('.recent-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                recentStatus = tab.dataset.status;
                document.querySelectorAll('.recent-tab').forEach(other => {
                    const on = other === tab;
                    other.classList.toggle('is-active', on);
                    other.setAttribute('aria-pressed', String(on));
                });
                // "View All" goes where that status lives.
                const link = document.getElementById('recentViewAll');
                if (link) link.href = recentStatus === 'rejected' ? 'reject.php' : 'submissions.php';
                window.runDashboardSearch();
            });
        });

        function openRejectModal(id, type) {
            if (type === 'document') {
                document.getElementById('rejectDocId').value = id;
                document.getElementById('rejectForm').action = 'process_document_status.php';
            }
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        function showSection(sectionId, element) {
            localStorage.setItem('registrar-active-section', sectionId);
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar) sidebar.classList.remove('active');
            }
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            
            document.getElementById(sectionId + '-section').classList.add('active');
            if (element) element.classList.add('active');
            // Keep the search box's filter on the newly shown section's tables.
            if (window.runDashboardSearch) window.runDashboardSearch();

            if (sectionId === 'calendar') {
                setTimeout(() => {
                    const calendarEl = document.getElementById('calendar');
                    const calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek'
                        }
                    });
                    calendar.render();
                }, 100);
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            const initialSection = localStorage.getItem('registrar-active-section');
            if (initialSection) {
                const navLinks = document.querySelectorAll('.nav-link');
                let targetLink = null;
                navLinks.forEach(link => {
                    if (link.getAttribute('onclick') && link.getAttribute('onclick').includes("showSection('" + initialSection + "'")) {
                        targetLink = link;
                    }
                });
                showSection(initialSection, targetLink);
            }

            const savedTheme = localStorage.getItem('registrar-theme-color');
            if (savedTheme) document.documentElement.style.setProperty('--accent-gold', savedTheme);
            const savedBg = localStorage.getItem('registrar-bg-color');
            if (savedBg) {
                document.documentElement.style.setProperty('--main-bg', savedBg);
            }
            const savedSidebarBg = localStorage.getItem('registrar-sidebar-bg');
            if (savedSidebarBg) {
                document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBg);
            }
        });
    </script>
<script>
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.body.classList.toggle('sidebar-open');
    }
    function closeSidebar() {
        document.querySelector('.sidebar').classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
</script>
<script src="doc_viewer.js?v=1"></script>
</body>
</html>
