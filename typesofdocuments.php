<?php
session_start();
require_once 'db.php';
require_once 'request_config.php';

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
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, institution FROM users WHERE id = ?");
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

// Fetch pending documents count for notification badge
$pending_count = 0;
$res_pending = $mysqli->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'");
if ($res_pending) {
    $pending_count = $res_pending->fetch_assoc()['count'];
}

// Pending students for the Student Approvals badge, scoped like every registrar page.
$user_inst = $user_data['institution'] ?? '';
$inst_sql = $user_inst !== '' ? ' AND u.institution = ?' : '';
$run = function ($sql) use ($mysqli, $user_inst, $inst_sql) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    if ($inst_sql !== '') $stmt->bind_param('s', $user_inst);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};
$pending_students_count = (int)($run("SELECT COUNT(*) AS c FROM users u WHERE u.role = 'student' AND u.is_verified = 1$inst_sql")[0]['c'] ?? 0);

// Fetch all document types
$document_types = [];
$res = $mysqli->query("SELECT id, name FROM document_types ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $document_types[] = $row;
    }
}

// How each type is used by this institution's students - three small grouped
// queries, whatever the number of uploads.
// Approved, active students: who the checklist is for.
$active_sql = "u.role = 'student' AND u.is_verified = 2 AND COALESCE(u.is_archived, 0) = 0 AND COALESCE(u.is_active, 1) = 1";
$student_total = (int)($run("SELECT COUNT(*) AS c FROM users u WHERE $active_sql$inst_sql")[0]['c'] ?? 0);

$usage = [];        // type name -> ['pending' => n, 'approved' => n, 'rejected' => n, 'files' => n]
foreach ($run("SELECT d.document_type AS t, d.status AS s, COUNT(*) AS c FROM documents d JOIN users u ON u.id = d.user_id
               WHERE u.role = 'student'$inst_sql GROUP BY d.document_type, d.status") as $r) {
    $usage[$r['t']][$r['s']] = (int)$r['c'];
    $usage[$r['t']]['files'] = ($usage[$r['t']]['files'] ?? 0) + (int)$r['c'];
}
// Which active students have each type approved, for the compliance bar and "fully complete".
$student_types = []; // user id -> number of current types approved
$type_names = array_flip(array_column($document_types, 'name'));
foreach ($run("SELECT DISTINCT d.user_id AS u, d.document_type AS t FROM documents d JOIN users u ON u.id = d.user_id
               WHERE d.status = 'approved' AND $active_sql$inst_sql") as $r) {
    if (isset($type_names[$r['t']])) $student_types[$r['u']] = ($student_types[$r['u']] ?? 0) + 1;
}
$type_count = count($document_types);
$complete_students = $type_count ? count(array_filter($student_types, fn($n) => $n >= $type_count)) : 0;
$pending_reviews = array_sum(array_map(fn($t) => $usage[$t['name']]['pending'] ?? 0, $document_types));

// The handler sets only a message; read its kind from the wording.
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$flash_kind = stripos($flash, 'successfully') !== false ? 'success' : (stripos($flash, 'No changes') === 0 ? 'info' : 'error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=4" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=11">
    <title>Document Types - Registrar Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --sidebar-bg: #0a1628; --main-bg: #0f172a; --card-bg: #1e293b;
            --accent-gold: #c9a227; --accent-green: #10b981; --accent-orange: #f59e0b;
            --accent-red: #ef4444; --accent-purple: #8b5cf6; --accent-blue: #3b82f6;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --text-muted: #64748b;
            --border: #1e293b; --sidebar-width: 240px;
            --line: rgba(255,255,255,0.07);
        }
        body { font-family: 'Inter', sans-serif; background: var(--main-bg); color: var(--text-primary); display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: var(--sidebar-width); background: var(--sidebar-bg, #0f172a); display: flex; flex-direction: column; border-right: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .logo-box { width: 48px; height: 48px; background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .sidebar-brand { font-size: 1.15rem; font-weight: 700; color: white; }
        .sidebar-nav { flex: 1; padding: 20px 12px; overflow-y: auto; min-height: 0; }
        .nav-section-label { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; padding: 0 8px; margin: 16px 0 8px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: var(--text-secondary); text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.06); color: var(--text-primary); }
        .nav-link.active { background: rgba(201,162,39,0.15); color: var(--accent-gold); border-left: 3px solid var(--accent-gold); padding-left: 9px; }
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 20px; min-width: 20px; text-align: center; }
        .sidebar-bottom { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.05); }
        .logout-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #ef4444; text-decoration: none; border-radius: 8px; font-weight: 500; }
        .logout-link:hover { background: rgba(239,68,68,0.1); }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }

        /* MAIN */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .topbar h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.01em; }
        .topbar-sub { font-size: 0.82rem; color: var(--text-secondary); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 38px; height: 38px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .content { padding: 28px 32px 40px; }

        /* Flash */
        .flash { display: flex; align-items: flex-start; gap: 10px; padding: 13px 16px; margin-bottom: 20px; border-radius: 12px; font-size: 0.88rem; font-weight: 500; line-height: 1.45; border: 1px solid; animation: dt-in .2s ease-out; }
        .flash i { margin-top: 2px; }
        .flash.success { background: rgba(16,185,129,0.12); color: #6ee7b7; border-color: rgba(16,185,129,0.35); }
        .flash.error { background: rgba(239,68,68,0.12); color: #fca5a5; border-color: rgba(239,68,68,0.35); }
        .flash.info { background: rgba(59,130,246,0.12); color: #93c5fd; border-color: rgba(59,130,246,0.35); }
        .flash-close { margin-left: auto; background: none; border: none; color: inherit; font-size: 18px; line-height: 1; cursor: pointer; opacity: .7; }
        .flash-close:hover { opacity: 1; }
        @keyframes dt-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

        /* Stat cards */
        .dt-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 22px; }
        .dt-stat { display: flex; align-items: center; gap: 14px; padding: 18px; background: var(--card-bg); border: 1px solid var(--line); border-radius: 14px; }
        .dt-stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .dt-stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
        .dt-stat-label { font-size: 0.78rem; color: var(--text-secondary); margin-top: 3px; }
        .tone-gold { background: rgba(201,162,39,0.15); color: var(--accent-gold); }
        .tone-blue { background: rgba(59,130,246,0.15); color: #60a5fa; }
        .tone-green { background: rgba(16,185,129,0.15); color: #34d399; }
        .tone-orange { background: rgba(245,158,11,0.15); color: #fbbf24; }

        /* Panel */
        .dt-panel { background: var(--card-bg); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .dt-panel-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 20px 22px; border-bottom: 1px solid var(--line); }
        .dt-panel-title { margin-right: auto; }
        .dt-panel-title h2 { font-size: 1.05rem; font-weight: 700; }
        .dt-panel-title p { font-size: 0.8rem; color: var(--text-secondary); margin-top: 3px; }
        .dt-search { position: relative; width: 260px; }
        .dt-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; pointer-events: none; }
        .dt-search input { width: 100%; height: 40px; padding: 0 12px 0 34px; border-radius: 10px; border: 1px solid #334155; background: #0f172a; color: var(--text-primary); font: 14px 'Inter', sans-serif; }
        .dt-search input:focus { outline: none; border-color: var(--accent-gold); box-shadow: 0 0 0 3px rgba(201,162,39,0.18); }
        .btn-add { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 18px; border: none; border-radius: 10px; background: var(--accent-gold); color: #0f172a; font: 700 0.86rem 'Inter', sans-serif; cursor: pointer; transition: filter .15s, transform .15s; }
        .btn-add:hover { filter: brightness(1.08); }
        .btn-add:active { transform: scale(0.98); }

        /* The list: a grid row per type */
        .dt-icon { width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(201,162,39,0.13); color: var(--accent-gold); font-size: 17px; }
        /* A real table with fixed columns, so it always fits its panel. */
        .dt-table-wrap { width: 100%; }
        .dt-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .dt-table col.c-no { width: 70px; }
        .dt-table col.c-files { width: 160px; }
        .dt-table col.c-act { width: 220px; }
        .dt-table th { padding: 12px 22px; text-align: left; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--line); background: rgba(15,23,42,0.35); }
        .dt-table td { padding: 14px 22px; vertical-align: middle; border-bottom: 1px solid rgba(255,255,255,0.04); }
        .dt-table tbody tr:last-child td { border-bottom: none; }
        .dt-table tbody tr { transition: background .15s; }
        .dt-table tbody tr:hover { background: rgba(255,255,255,0.025); }
        .dt-table .t-right { text-align: right; }
        .t-no { color: var(--text-muted); font-size: 0.85rem; font-variant-numeric: tabular-nums; }
        .dt-name { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .dt-name strong { font-size: 0.95rem; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
        .dt-count { display: inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(59,130,246,0.12); color: #93c5fd; font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
        .dt-count.is-zero { background: rgba(148,163,184,0.12); color: var(--text-secondary); }
        .dt-actions { display: flex; justify-content: flex-end; gap: 8px; }
        .dt-delete-form { display: contents; }
        .dt-table .sk-empty td { padding: 40px 22px; text-align: center; color: var(--text-secondary); }
        .act { display: inline-flex; align-items: center; justify-content: center; gap: 7px; height: 36px; padding: 0 14px; border-radius: 9px; border: 1px solid; background: none; font: 600 0.8rem 'Inter', sans-serif; cursor: pointer; transition: background .15s, color .15s; }
        .act.edit { color: #93c5fd; border-color: rgba(59,130,246,0.4); background: rgba(59,130,246,0.1); }
        .act.edit:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .act.delete { color: #fca5a5; border-color: rgba(239,68,68,0.4); background: rgba(239,68,68,0.1); }
        .act.delete:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

        .dt-empty { padding: 54px 22px; text-align: center; color: var(--text-secondary); }
        .dt-empty i { font-size: 34px; color: var(--text-muted); margin-bottom: 12px; display: block; }
        .dt-empty strong { display: block; color: var(--text-primary); font-size: 1rem; margin-bottom: 4px; }
        .dt-empty .btn-add { margin-top: 16px; }

        /* Modal */
        .dt-modal { position: fixed; inset: 0; z-index: 4000; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .dt-modal[hidden] { display: none; }
        .dt-modal-backdrop { position: absolute; inset: 0; background: rgba(2,6,23,0.72); }
        .dt-modal-card { position: relative; width: 100%; max-width: 460px; background: #1e293b; border: 1px solid #334155; border-radius: 18px; box-shadow: 0 24px 60px rgba(0,0,0,0.55); animation: dt-pop .16s ease-out; }
        @keyframes dt-pop { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
        .dt-modal-head { display: flex; align-items: center; gap: 12px; padding: 20px 22px 0; }
        .dt-modal-head .dt-icon { width: 40px; height: 40px; }
        .dt-modal-head h3 { font-size: 1.08rem; font-weight: 700; margin-right: auto; }
        .dt-modal-x { width: 36px; height: 36px; border: none; border-radius: 9px; background: #0f172a; color: #cbd5e1; font-size: 22px; line-height: 1; cursor: pointer; }
        .dt-modal-x:hover { background: #334155; color: #fff; }
        .dt-modal-body { padding: 18px 22px 6px; }
        .dt-modal-body label { display: block; margin-bottom: 8px; font-size: 0.82rem; font-weight: 600; color: #cbd5e1; }
        .dt-modal-body input { width: 100%; height: 44px; padding: 0 14px; border-radius: 10px; border: 1px solid #475569; background: #0f172a; color: var(--text-primary); font: 15px 'Inter', sans-serif; }
        .dt-modal-body input:focus { outline: none; border-color: var(--accent-gold); box-shadow: 0 0 0 3px rgba(201,162,39,0.2); }
        .dt-modal-body input.is-invalid { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.18); }
        .dt-hint { margin-top: 8px; font-size: 0.76rem; color: var(--text-muted); line-height: 1.45; }
        .dt-error { margin-top: 8px; font-size: 0.8rem; color: #fca5a5; display: flex; gap: 6px; }
        .dt-error[hidden] { display: none; }
        .dt-rename-note { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: rgba(59,130,246,0.1); color: #bfdbfe; font-size: 0.78rem; line-height: 1.45; }
        .dt-rename-note[hidden] { display: none; }
        .dt-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 22px 22px; }
        .btn-ghost, .btn-save { height: 42px; padding: 0 18px; border-radius: 10px; font: 600 0.86rem 'Inter', sans-serif; cursor: pointer; }
        .btn-ghost { background: #0f172a; color: #e2e8f0; border: 1px solid #475569; }
        .btn-ghost:hover { background: #334155; }
        .btn-save { display: inline-flex; align-items: center; gap: 8px; background: var(--accent-gold); color: #0f172a; border: none; }
        .btn-save:hover { filter: brightness(1.08); }
        .btn-save:disabled { opacity: .7; cursor: progress; }

        .mobile-toggle { display: none; background: var(--accent-gold); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; }
        .mobile-toggle i { font-size: 20px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }

        @media (max-width: 1200px) {
            .act span { display: none; }
            .act { width: 38px; padding: 0; }
            .dt-table col.c-act { width: 124px; }
            .dt-table col.c-files { width: 130px; }
            .dt-table col.c-no { width: 56px; }
            .dt-table th, .dt-table td { padding-left: 16px; padding-right: 16px; }
        }
        @media (max-width: 1024px) {
            .sidebar { position: fixed; left: 0; top: 0; bottom: 0; height: 100%; z-index: 1050; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.active { transform: translateX(0) !important; }
            .mobile-toggle { display: block; }
            .content { padding: 20px; }
            .dt-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* Below tablet width each type is a card. */
        @media (max-width: 820px) {
            /* Each type becomes a card: name and buttons, the count under the name. */
            .dt-table, .dt-table tbody { display: block; }
            .dt-table thead, .dt-table colgroup { display: none; }
            .dt-table-wrap { padding: 12px; }
            .dt-table tbody { display: grid; gap: 10px; }
            .dt-table tbody tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: "name act" "files act";
                                 align-items: center; gap: 4px 12px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 14px; background: rgba(15,23,42,0.45); }
            .dt-table td { display: block; padding: 0; border: none !important; }
            .dt-table .t-no { display: none; }
            .dt-table .t-name { grid-area: name; }
            .dt-table .t-files { grid-area: files; padding-left: 54px; }
            .dt-table .t-act { grid-area: act; }
            .dt-table tr.sk-empty { display: block; border: none; background: none; }
            .dt-panel-head { padding: 16px; }
            .dt-search { width: 100%; order: 3; }
            .btn-add { order: 2; }
        }
        @media (max-width: 560px) {
            .topbar h1 { font-size: 1.3rem; }
            .topbar-right .user-meta { display: none; }
            .dt-stats { gap: 10px; }
            .dt-stat { padding: 14px; gap: 10px; }
            .dt-stat-icon { width: 38px; height: 38px; font-size: 16px; }
            .dt-stat-value { font-size: 1.25rem; }
            .btn-add { flex: 1; justify-content: center; height: 44px; }
            .dt-panel-title { width: 100%; }
            .dt-search input, .dt-modal-body input { font-size: 16px; }   /* no iOS zoom on focus */
            .act { width: 42px; height: 42px; }
            .dt-modal { padding: 0; align-items: flex-end; }
            .dt-modal-card { max-width: none; border-radius: 18px 18px 0 0; padding-bottom: env(safe-area-inset-bottom, 0px); }
            .dt-modal-foot .btn-ghost, .dt-modal-foot .btn-save { flex: 1; justify-content: center; height: 46px; }
        }
        @media (prefers-reduced-motion: reduce) { .flash, .dt-modal-card { animation: none; } }
    </style>
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <script src="search_helper.js?v=3" defer></script>
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
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box">
                <img src="logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span class="sidebar-brand">Registrar</span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="registrar_dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="analytics.php" class="nav-link"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendar</a>
            <?= submissionsNavLink($mysqli) ?>
            <a href="reject.php" class="nav-link"><i class="fas fa-times-circle"></i> Rejected Documents</a>
            <?= requestsNavLink($mysqli) ?>
            <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications <?= $pending_count > 0 ? '<span class="nav-badge">'.$pending_count.'</span>' : '' ?></a>
            <a href="archive.php" class="nav-link"><i class="fas fa-archive"></i> Archived Students</a>
            <a href="typesofdocuments.php" class="nav-link active"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
            <a href="registrar_settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <h1>Document Types</h1>
                <p class="topbar-sub">The documents every student must upload to their checklist</p>
            </div>
            <div class="topbar-right">
                <div class="user-meta" style="text-align:right">
                    <div style="font-size:0.85rem; font-weight:600"><?= htmlspecialchars($full_name) ?></div>
                    <div style="font-size:0.7rem; color:var(--text-muted)">Registrar</div>
                </div>
                <div class="user-avatar"><?= $initials ?></div>
            </div>
        </div>

        <div class="content">
            <?php if ($flash !== ''): ?>
                <div class="flash <?= $flash_kind ?>" role="status">
                    <i class="fas <?= ['success' => 'fa-circle-check', 'info' => 'fa-circle-info', 'error' => 'fa-circle-exclamation'][$flash_kind] ?>"></i>
                    <span><?= htmlspecialchars($flash) ?></span>
                    <button type="button" class="flash-close" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <div class="dt-stats">
                <div class="dt-stat"><div class="dt-stat-icon tone-gold"><i class="fas fa-folder-open"></i></div>
                    <div><div class="dt-stat-value"><?= $type_count ?></div><div class="dt-stat-label">Required document types</div></div></div>
                <div class="dt-stat"><div class="dt-stat-icon tone-blue"><i class="fas fa-user-graduate"></i></div>
                    <div><div class="dt-stat-value"><?= $student_total ?></div><div class="dt-stat-label">Active students</div></div></div>
                <div class="dt-stat"><div class="dt-stat-icon tone-green"><i class="fas fa-circle-check"></i></div>
                    <div><div class="dt-stat-value"><?= $complete_students ?></div><div class="dt-stat-label">Students with every type approved</div></div></div>
                <div class="dt-stat"><div class="dt-stat-icon tone-orange"><i class="fas fa-hourglass-half"></i></div>
                    <div><div class="dt-stat-value"><?= $pending_reviews ?></div><div class="dt-stat-label">Uploads waiting for review</div></div></div>
            </div>

            <section class="dt-panel">
                <div class="dt-panel-head">
                    <div class="dt-panel-title">
                        <h2>All Document Types</h2>
                        <p>Students see each one on their checklist. Renaming keeps every file already uploaded under it.</p>
                    </div>
                    <?php if ($document_types): ?>
                        <label class="dt-search">
                            <i class="fas fa-magnifying-glass"></i>
                            <input type="search" id="dtSearch" placeholder="Search document types" autocomplete="off">
                        </label>
                    <?php endif; ?>
                    <button type="button" class="btn-add" data-dt-add><i class="fas fa-plus"></i> Add New Type</button>
                </div>

                <?php if (!$document_types): ?>
                    <div class="dt-empty">
                        <i class="fas fa-folder-plus"></i>
                        <strong>No document types yet</strong>
                        Add the first document students must submit, e.g. PSA Birth Certificate or Good Moral.
                        <div><button type="button" class="btn-add" data-dt-add><i class="fas fa-plus"></i> Add New Type</button></div>
                    </div>
                <?php else: ?>
                    <div class="dt-table-wrap">
                    <table class="dt-table" id="dtTable">
                        <colgroup><col class="c-no"><col><col class="c-files"><col class="c-act"></colgroup>
                        <thead>
                            <tr><th scope="col">No.</th><th scope="col">Document Type</th><th scope="col">Files Uploaded</th><th scope="col" class="t-right">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($document_types as $i => $type):
                            $name = (string)$type['name'];
                            $files = $usage[$name]['files'] ?? 0;
                        ?>
                            <tr data-search="<?= htmlspecialchars($name) ?>">
                                <td class="t-no"><?= $i + 1 ?></td>
                                <td class="t-name">
                                    <span class="dt-name"><span class="dt-icon"><i class="fas fa-file-lines"></i></span><strong><?= htmlspecialchars($name) ?></strong></span>
                                </td>
                                <td class="t-files"><span class="dt-count<?= $files ? '' : ' is-zero' ?>"><?= $files ?> file<?= $files === 1 ? '' : 's' ?></span></td>
                                <td class="t-act">
                                    <div class="dt-actions">
                                        <button type="button" class="act edit" data-dt-edit data-id="<?= (int)$type['id'] ?>" data-name="<?= htmlspecialchars($name) ?>" data-files="<?= $files ?>" title="Rename" aria-label="Rename <?= htmlspecialchars($name) ?>"><i class="fas fa-pen"></i><span>Edit</span></button>
                                        <form action="process_document_type.php" method="POST" class="dt-delete-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$type['id'] ?>">
                                            <button type="button" class="act delete" data-dt-delete data-name="<?= htmlspecialchars($name) ?>" data-files="<?= $files ?>" title="Delete" aria-label="Delete <?= htmlspecialchars($name) ?>"><i class="fas fa-trash"></i><span>Delete</span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Add / rename -->
    <div class="dt-modal" id="dtModal" role="dialog" aria-modal="true" aria-labelledby="dtModalTitle" hidden>
        <div class="dt-modal-backdrop" data-dt-close></div>
        <form class="dt-modal-card" action="process_document_type.php" method="POST" id="documentTypeForm" novalidate>
            <div class="dt-modal-head">
                <div class="dt-icon"><i class="fas fa-file-circle-plus" id="dtModalIcon"></i></div>
                <h3 id="dtModalTitle">Add Document Type</h3>
                <button type="button" class="dt-modal-x" data-dt-close aria-label="Close">&times;</button>
            </div>
            <div class="dt-modal-body">
                <input type="hidden" name="id" id="typeId">
                <input type="hidden" name="action" id="formAction" value="add">
                <label for="typeName">Document type name</label>
                <input type="text" id="typeName" name="name" required maxlength="255" autocomplete="off" placeholder="e.g. PSA Birth Certificate" aria-describedby="typeNameError typeNameHint">
                <p class="dt-error" id="typeNameError" role="alert" hidden></p>
                <p class="dt-hint" id="typeNameHint">Use the name students know. Near-copies like "PSA" and "P.S.A." are refused.</p>
                <p class="dt-rename-note" id="dtRenameNote" hidden></p>
            </div>
            <div class="dt-modal-foot">
                <button type="button" class="btn-ghost" data-dt-close>Cancel</button>
                <button type="submit" class="btn-save" id="dtSave"><i class="fas fa-floppy-disk"></i> <span>Save</span></button>
            </div>
        </form>
    </div>

    <script>
        // Same rule as docTypeKey() in process_document_type.php, which has the final say:
        // "PSA", "psa", "P.S.A." and "P S A" are all one name.
        const DOC_TYPES = <?= json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $document_types)) ?>;
        const modal = document.getElementById('dtModal');
        const form = document.getElementById('documentTypeForm');
        const nameInput = document.getElementById('typeName');
        const idInput = document.getElementById('typeId');
        let lastFocus = null, originalName = '';

        function docTypeKey(name) {
            return String(name).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
        }
        function typeNameProblem() {
            const name = nameInput.value.trim().replace(/\s+/g, ' ');
            const id = Number(idInput.value) || 0;
            if (!name) return 'Please enter a document type name.';
            const key = docTypeKey(name);
            if (!key) return 'The name must contain letters or numbers.';
            if (id && name === originalName) return 'This is already the name. Change it, or press Cancel.';
            const twin = DOC_TYPES.find(t => t.id !== id && docTypeKey(t.name) === key);
            return twin ? '"' + name + '" is the same as the existing document type "' + twin.name + '".' : '';
        }
        function showTypeNameProblem(message) {
            const box = document.getElementById('typeNameError');
            box.innerHTML = message ? '<i class="fas fa-circle-exclamation"></i>' : '';
            if (message) box.appendChild(document.createTextNode(' ' + message));
            box.hidden = !message;
            nameInput.classList.toggle('is-invalid', !!message);
        }

        function openModal(id, name, files) {
            lastFocus = document.activeElement;
            originalName = name || '';
            document.getElementById('dtModalTitle').textContent = id ? 'Rename Document Type' : 'Add Document Type';
            document.getElementById('dtModalIcon').className = 'fas ' + (id ? 'fa-pen-to-square' : 'fa-file-circle-plus');
            document.querySelector('#dtSave span').textContent = id ? 'Save Name' : 'Add Type';
            idInput.value = id || '';
            nameInput.value = name || '';
            document.getElementById('formAction').value = id ? 'update' : 'add';
            const note = document.getElementById('dtRenameNote');
            note.hidden = !(id && files > 0);
            note.textContent = files > 0 ? 'The ' + files + ' file' + (files == 1 ? '' : 's') + ' already uploaded under "' + name + '" will move to the new name, so no student loses an upload.' : '';
            showTypeNameProblem('');
            document.getElementById('dtSave').disabled = false;
            modal.hidden = false;
            setTimeout(() => { nameInput.focus(); nameInput.select(); }, 30);
        }
        function closeModal() {
            modal.hidden = true;
            if (lastFocus) lastFocus.focus();
        }

        form.addEventListener('submit', function (e) {
            const problem = typeNameProblem();
            if (problem) { e.preventDefault(); showTypeNameProblem(problem); nameInput.focus(); return; }
            document.getElementById('dtSave').disabled = true;     // one click, one save
        });
        nameInput.addEventListener('input', () => {
            if (!document.getElementById('typeNameError').hidden) showTypeNameProblem(typeNameProblem());
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-dt-add]')) { openModal('', '', 0); return; }
            const edit = e.target.closest('[data-dt-edit]');
            if (edit) { openModal(edit.dataset.id, edit.dataset.name, Number(edit.dataset.files)); return; }
            if (e.target.closest('[data-dt-close]')) { closeModal(); return; }
            const del = e.target.closest('[data-dt-delete]');
            if (del) {
                const files = Number(del.dataset.files);
                const message = 'Students will no longer see "' + del.dataset.name + '" on their checklist.'
                    + (files ? ' The ' + files + ' file' + (files === 1 ? '' : 's') + ' already uploaded stay in the records.' : '');
                const go = () => { del.disabled = true; del.closest('form').submit(); };
                if (typeof uiConfirm === 'function') {
                    uiConfirm({ title: 'Delete this document type?', message, confirmLabel: 'Delete', tone: 'danger' }).then(ok => { if (ok) go(); });
                } else if (window.confirm(message)) {
                    go();
                }
            }
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

        // Search: every word in any order, accents and punctuation ignored (search_helper.js).
        window.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('dtSearch');
            if (!input || !window.SearchKit) return;
            const table = document.getElementById('dtTable');
            SearchKit.bind(input, () => SearchKit.filterTables(input, [table]));
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
</body>
</html>
