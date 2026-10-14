<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/request_config.php';
require_once 'includes/name_helper.php';

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

// Fetch pending documents count for notification badge
$pending_count = 0;
$res_pending_docs = $mysqli->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'");
if ($res_pending_docs) {
    $pending_count = $res_pending_docs->fetch_assoc()['count'];
}

// Fetch pending students (is_verified = 1 indicates OTP verified but not approved)
$pending_students = [];
if (!empty($user_institution)) {
    $pending_query = "SELECT id, first_name, middle_name, last_name, email, student_id, created_at, course, year_level, section FROM users WHERE role = 'student' AND is_verified = 1 AND institution = ? ORDER BY created_at DESC";
    $stmt = $mysqli->prepare($pending_query);
    $stmt->bind_param("s", $user_institution);
    $stmt->execute();
    $res_pending = $stmt->get_result();
    if ($res_pending) while($row = $res_pending->fetch_assoc()) $pending_students[] = $row;
    $stmt->close();
} else {
    $pending_query = "SELECT id, first_name, middle_name, last_name, email, student_id, created_at, course, year_level, section FROM users WHERE role = 'student' AND is_verified = 1 ORDER BY created_at DESC";
    $res_pending = $mysqli->query($pending_query);
    if ($res_pending) while($row = $res_pending->fetch_assoc()) $pending_students[] = $row;
}
$pending_students_count = count($pending_students);

// Fetch processed students (is_verified 2 = approved, 3 = rejected)
$processed_students = [];
if (!empty($user_institution)) {
    $processed_query = "SELECT id, first_name, middle_name, last_name, email, student_id, created_at, is_verified, course, year_level, section FROM users WHERE role = 'student' AND is_verified IN (2,3) AND is_archived = 0 AND institution = ? ORDER BY created_at DESC";
    $stmt = $mysqli->prepare($processed_query);
    $stmt->bind_param("s", $user_institution);
    $stmt->execute();
    $res_processed = $stmt->get_result();
    if ($res_processed) while($row = $res_processed->fetch_assoc()) $processed_students[] = $row;
    $stmt->close();
} else {
    $processed_query = "SELECT id, first_name, middle_name, last_name, email, student_id, created_at, is_verified, course, year_level, section FROM users WHERE role = 'student' AND is_verified IN (2,3) AND is_archived = 0 ORDER BY created_at DESC";
    $res_processed = $mysqli->query($processed_query);
    if ($res_processed) while($row = $res_processed->fetch_assoc()) $processed_students[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="assets/js/theme_mode.js?v=3" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="assets/css/theme_mode.css?v=9">
    <title>Student Approvals - Registrar Portal</title>
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
        }
        body { font-family: 'Inter', sans-serif; background: var(--main-bg); color: var(--text-primary); display: flex; min-height: 100vh; }
        
        /* SIDEBAR */
        .sidebar { width: var(--sidebar-width); background: var(--sidebar-bg, #0f172a); display: flex; flex-direction: column; border-right: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .logo-box { width: 48px; height: 48px; background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .sidebar-brand { font-size: 1.15rem; font-weight: 700; color: white; }
        .sidebar-nav { flex: 1; padding: 20px 12px; }
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
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .user-avatar { width: 36px; height: 36px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }
        
        /* CONTENT */
        .content { padding: 28px 32px; overflow-y: auto; }
        .card { background: var(--card-bg); border-radius: 14px; padding: 24px; border: 1px solid rgba(255,255,255,0.04); margin-bottom: 20px; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .card-header h2 { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        thead th { font-size: 0.72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; padding: 0 0 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        tbody tr { border-bottom: 1px solid rgba(255,255,255,0.03); }
        tbody tr:last-child { border-bottom: none; }
        tbody td { padding: 14px 0; font-size: 0.85rem; color: var(--text-primary); vertical-align: middle; }

        .action-buttons { display: flex; gap: 8px; }
        .action-btn { padding: 6px 10px; border-radius: 6px; border: none; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; color: white; display: inline-flex; align-items: center; gap: 5px; }
        .btn-approve { background: var(--accent-green); }
        .btn-reject { background: var(--accent-red); }
        .action-btn:hover { opacity: 0.8; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-badge.approved { background: rgba(16,185,129,0.15); color: #10b981; }
        .status-badge.rejected { background: rgba(239,68,68,0.15); color: #ef4444; }

        /* Flash Message */
        .flash-message { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 15px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .flash-message.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .flash-message.error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 2000;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            width: 90%; max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out forwards;
        }
        @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h3 { font-size: 1.1rem; margin: 0; color: var(--text-primary); }
        .modal-close { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.2rem; }
        .modal-close:hover { color: var(--accent-red); }
        .modal-body { padding: 20px; color: var(--text-secondary); font-size: 0.95rem; }
        .modal-footer {
            padding: 16px 20px;
            background: rgba(0,0,0,0.2);
            display: flex; justify-content: flex-end; gap: 10px;
        }
        .modal-btn {
            padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 0.85rem; transition: opacity 0.2s; color: white;
        }
        .btn-cancel { background: #334155; }
        .btn-confirm { background: var(--accent-blue); }
        .modal-btn:hover { opacity: 0.8; }

        /* Responsive */
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
            .sidebar.active { transform: translateX(0) !important; }
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
            .content { padding: 20px; }
            .main { margin-left: 0; }
            .mobile-topbar-logo { display: block !important; }
        }
        /* Search and Filter Inputs */
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-bar {
            flex: 1;
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 9px 16px;
        }
        .search-bar input {
            background: transparent;
            border: none;
            color: var(--text-primary);
            outline: none;
            width: 100%;
            font-family: inherit;
        }
        .filter-select {
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            background: #1e293b;
            color: var(--text-primary);
            font-family: inherit;
            outline: none;
        }
        
        .bulk-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        .bulk-btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            color: white;
            transition: opacity 0.2s;
        }
        .bulk-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .bulk-approve { background: var(--accent-green); }
        .bulk-reject { background: var(--accent-red); }

        @media (max-width: 768px) {
            table thead { display: none; }
            table tr { display: block; margin-bottom: 15px; background: var(--card-bg); border: 1px solid rgba(255,255,255,0.04); border-radius: 10px; padding: 10px; }
            table td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 8px 10px; }
            table td::before { content: attr(data-label); font-weight: 700; color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
        }
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
    <link rel="stylesheet" href="assets/css/registrar_mobile.css?v=2">
    <script src="assets/js/search_helper.js?v=3"></script>
    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
</head>
<body>
    <!-- Mobile Top Bar -->
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="registrar_dashboard.php" class="mobile-appbar-brand">
            <img src="assets/img/logo.jpg" alt="">
            <span>Registrar</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box"><img src="assets/img/logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;"></div>
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
            <a href="typesofdocuments.php" class="nav-link"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link active"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
            <a href="registrar_settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="assets/img/logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1>Student Approvals</h1>
                    <p style="font-size:0.8rem; color:var(--text-secondary)">Review and manage student registrations</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px">
                <div style="text-align:right">
                    <div style="font-size:0.85rem; font-weight:600"><?= htmlspecialchars($full_name) ?></div>
                    <div style="font-size:0.7rem; color:var(--text-muted)">Registrar</div>
                </div>
                <div class="user-avatar"><?= $initials ?></div>
            </div>
        </div>

        <div class="content">
            <?php if(isset($_SESSION['flash'])): ?>
                <div class="flash-message <?= stripos($_SESSION['flash'], 'successfully') !== false ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($_SESSION['flash']); ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <div class="filter-controls">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search student name or ID...">
                </div>
                <select id="courseFilter" class="filter-select">
                    <option value="">All Programs</option>
                    <?php if (empty($user_institution) || $user_institution === 'ISAP'): ?>
                        <option value="BS Accountancy">BS Accountancy</option>
                        <option value="BS Business administration">BS Business administration</option>
                        <option value="BS Computer engineering">BS Computer engineering</option>
                        <option value="BS Criminology">BS Criminology</option>
                        <option value="BS Custom Administration">BS Custom Administration</option>
                        <option value="BS Hospitality management">BS Hospitality management</option>
                        <option value="BS Information Technology">BS Information Technology</option>
                        <option value="BS Physical education">BS Physical education</option>
                        <option value="BS Psychology">BS Psychology</option>
                        <option value="BS Secondary education">BS Secondary education</option>
                        <option value="BS Social work">BS Social work</option>
                        <option value="BS Tourism management">BS Tourism management</option>
                    <?php endif; ?>
                    <?php if (empty($user_institution) || $user_institution === 'MCNP'): ?>
                        <option value="BS Medical laboratory science">BS Medical laboratory science</option>
                        <option value="BS Midwifery">BS Midwifery</option>
                        <option value="BS Nursing">BS Nursing</option>
                        <option value="BS Pharmacy">BS Pharmacy</option>
                        <option value="BS Physical therapy">BS Physical therapy</option>
                        <option value="BS Radiologic technology">BS Radiologic technology</option>
                        <option value="Dental technology">Dental technology</option>
                    <?php endif; ?>
                </select>
                <select id="yearFilter" class="filter-select">
                    <option value="">All Years</option>
                    <option value="1st Year">1st Year</option>
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
                <select id="sectionFilter" class="filter-select">
                    <option value="">All Sections</option>
                    <?php foreach (range('A', 'Z') as $letter): ?>
                        <option value="<?= $letter ?>">Section <?= $letter ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Pending Approvals</h2>
                </div>
                <form id="bulkForm" action="process_student_status.php" method="POST">
                <input type="hidden" name="status" id="bulkStatus" value="">
                <table id="pendingTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllPending" onchange="toggleAll(this)"></th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Registered On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_students)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No pending student registrations.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_students as $student): ?>
                                <tr data-course="<?= htmlspecialchars($student['course'] ?? '') ?>" data-year="<?= htmlspecialchars($student['year_level'] ?? '') ?>" data-section="<?= htmlspecialchars($student['section'] ?? '') ?>">
                                    <td data-label="Select"><input type="checkbox" name="student_id[]" value="<?= $student['id'] ?>" class="pending-checkbox" onchange="updateBulkButtons()"></td>
                                    <td data-label="Student ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($student['student_id'] ?? '')) ?></td>
                                    <td data-label="Student Name" class="cell-student-name">
                                        <?= htmlspecialchars(formatPersonNameRow($student)) ?>
                                        <?php if(!empty($student['course'])): ?>
                                            <br><small style="color:var(--text-muted)"><?= htmlspecialchars($student['course'] ?? '') ?> - <?= htmlspecialchars(!empty($student['year_level']) ? substr($student['year_level'], 0, 1) . ($student['section'] ?? '') : '') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email"><?= htmlspecialchars($student['email']) ?></td>
                                    <td data-label="Registered On"><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                                    <td data-label="Actions">
                                        <div class="action-buttons">
                                            <button type="submit" class="action-btn btn-approve" onclick="submitSingle(<?= $student['id'] ?>, 'approved'); return false;"><i class="fas fa-check"></i> Approve</button>
                                            <button type="submit" class="action-btn btn-reject" onclick="submitSingle(<?= $student['id'] ?>, 'rejected'); return false;"><i class="fas fa-times"></i> Reject</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="bulk-actions" <?php if(empty($pending_students)) echo 'style="display:none;"'; ?>>
                    <button type="button" id="btnBulkApprove" class="bulk-btn bulk-approve" onclick="submitBulk('approved')" disabled><i class="fas fa-check-double"></i> Accept Selected</button>
                    <button type="button" id="btnBulkReject" class="bulk-btn bulk-reject" onclick="submitBulk('rejected')" disabled><i class="fas fa-times"></i> Reject Selected</button>
                </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Processed Students</h2>
                </div>
                <table id="processedTable">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($processed_students)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No processed students yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($processed_students as $student): ?>
                                <tr data-course="<?= htmlspecialchars($student['course'] ?? '') ?>" data-year="<?= htmlspecialchars($student['year_level'] ?? '') ?>" data-section="<?= htmlspecialchars($student['section'] ?? '') ?>">
                                    <td data-label="Student ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($student['student_id'] ?? '')) ?></td>
                                    <td data-label="Student Name" class="cell-student-name">
                                        <?= htmlspecialchars(formatPersonNameRow($student)) ?>
                                        <?php if(!empty($student['course'])): ?>
                                            <br><small style="color:var(--text-muted)"><?= htmlspecialchars($student['course'] ?? '') ?> - <?= htmlspecialchars(!empty($student['year_level']) ? substr($student['year_level'], 0, 1) . ($student['section'] ?? '') : '') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email"><?= htmlspecialchars($student['email']) ?></td>
                                    <td data-label="Status">
                                        <?php if ($student['is_verified'] == 2): ?>
                                            <span class="status-badge approved">Approved</span>
                                        <?php else: ?>
                                            <span class="status-badge rejected">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions">
                                        <button class="action-btn" style="background:var(--text-muted); color:white;" onclick="archiveStudent(<?= $student['id'] ?>)"><i class="fas fa-archive"></i> Archive</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Custom Modal -->
    <div id="customModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Confirm Action</h3>
                <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p id="modalMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="modal-btn btn-confirm" id="modalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('registrar-theme-color');
            if (savedTheme) document.documentElement.style.setProperty('--accent-gold', savedTheme);
            const savedBg = localStorage.getItem('registrar-bg-color');
            if (savedBg) document.documentElement.style.setProperty('--main-bg', savedBg);
            const savedSidebarBg = localStorage.getItem('registrar-sidebar-bg');
            if (savedSidebarBg) document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBg);
        });

        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.pending-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') cb.checked = source.checked;
            });
            updateBulkButtons();
        }

        function updateBulkButtons() {
            const checked = document.querySelectorAll('.pending-checkbox:checked').length;
            document.getElementById('btnBulkApprove').disabled = checked === 0;
            document.getElementById('btnBulkReject').disabled = checked === 0;
        }

        let modalConfirmCallback = null;

        function showModal(title, message, callback, confirmColorClass = 'btn-confirm') {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalMessage').textContent = message;
            
            const confirmBtn = document.getElementById('modalConfirmBtn');
            confirmBtn.className = 'modal-btn ' + confirmColorClass;
            
            modalConfirmCallback = callback;
            document.getElementById('customModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('customModal').style.display = 'none';
            modalConfirmCallback = null;
        }

        document.getElementById('modalConfirmBtn').addEventListener('click', () => {
            if (modalConfirmCallback) modalConfirmCallback();
            closeModal();
        });

        function submitBulk(action) {
            const form = document.getElementById('bulkForm');
            document.getElementById('bulkStatus').value = action;
            const actionText = action === 'approved' ? 'approve' : 'reject';
            const confirmColor = action === 'approved' ? 'btn-approve' : 'btn-reject';
            
            showModal('Confirm Action', `Are you sure you want to ${actionText} the selected students?`, () => {
                form.submit();
            }, confirmColor);
        }

        function submitSingle(id, action) {
            const checkboxes = document.querySelectorAll('.pending-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            const targetCb = document.querySelector(`.pending-checkbox[value="${id}"]`);
            if(targetCb) targetCb.checked = true;
            submitBulk(action);
        }

        function archiveStudent(id) {
            showModal('Confirm Archive', 'Are you sure you want to archive this student record?', () => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process_student_status.php';
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'student_id[]';
                idInput.value = id;
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = 'archive';
                form.appendChild(idInput);
                form.appendChild(statusInput);
                document.body.appendChild(form);
                form.submit();
            }, 'btn-confirm');
        }

        // The search box goes through assets/js/search_helper.js (every word, any order,
        // no buttons); the dropdowns are exact matches on the row's data-*.
        function applyFilters() {
            let courseFilter = document.getElementById('courseFilter').value.toLowerCase();
            let yearFilter = document.getElementById('yearFilter').value.toLowerCase();
            let sectionFilter = document.getElementById('sectionFilter').value.toLowerCase();

            let tables = [document.getElementById('pendingTable'), document.getElementById('processedTable')];
            SearchKit.filterTables(document.getElementById('searchInput'), tables, row => {
                let rowCourse = (row.getAttribute('data-course') || '').toLowerCase();
                let rowYear = (row.getAttribute('data-year') || '').toLowerCase();
                let rowSection = (row.getAttribute('data-section') || '').toLowerCase();
                return (courseFilter === '' || rowCourse === courseFilter)
                    && (yearFilter === '' || rowYear === yearFilter)
                    && (sectionFilter === '' || rowSection === sectionFilter);
            }, !!(courseFilter || yearFilter || sectionFilter));

            // A hidden student must never be caught by a bulk action.
            tables.forEach(table => table && table.querySelectorAll('tbody tr').forEach(row => {
                if (row.style.display === 'none') {
                    let cb = row.querySelector('.pending-checkbox');
                    if (cb) cb.checked = false;
                }
            }));
            
            const selectAllCb = document.getElementById('selectAllPending');
            if(selectAllCb) selectAllCb.checked = false;
            updateBulkButtons();
        }

        SearchKit.bind(document.getElementById('searchInput'), applyFilters);
        document.getElementById('courseFilter').addEventListener('change', applyFilters);
        document.getElementById('yearFilter').addEventListener('change', applyFilters);
        document.getElementById('sectionFilter').addEventListener('change', applyFilters);
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
