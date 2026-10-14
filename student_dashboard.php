<?php
session_start();
require_once 'includes/db.php'; // Assuming db.php defines $mysqli

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php?tab=login");
    exit;
}

// Fetch user details from the database
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    // If user_id is not in session, redirect to login
    header("Location: index.php?tab=login");
    exit;
}

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course, section FROM users WHERE id = ?");
if (!$stmt) {
    // Handle cases where the database query fails to prepare
    $_SESSION['flash'] = "System error. Please try again later.";
    header("Location: index.php?tab=login"); exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    // User not found in DB, destroy session and redirect
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$first_name = $user_data['first_name'] ?? '';
$middle_name = $user_data['middle_name'] ?? '';
$last_name = $user_data['last_name'] ?? '';
$user_student_id = $user_data['student_id'] ?? '';
$user_email = $user_data['email'] ?? '';
$user_profile_picture = $user_data['profile_picture'] ?? null;
$user_year_level = $user_data['year_level'] ?? '';
$user_course = $user_data['course'] ?? '';
$user_section = $user_data['section'] ?? '';

$display_year_section = '';
if (!empty($user_year_level)) {
    $year_num = substr($user_year_level, 0, 1);
    $display_year_section = $year_num . $user_section;
}

// Construct full name, handling optional middle name
$full_name_parts = [];
if (!empty($first_name)) $full_name_parts[] = $first_name;
if (!empty($middle_name)) $full_name_parts[] = $middle_name;
if (!empty($last_name)) $full_name_parts[] = $last_name;
$full_name = implode(' ', $full_name_parts);

// Fallback if full name is still empty (e.g., if only email is stored)
if (empty($full_name)) {
    $full_name = $user_email;
}

// Fetch user documents from database
$docs_stmt = $mysqli->prepare("SELECT id, file_name, file_path, status, uploaded_at FROM documents WHERE user_id = ? ORDER BY uploaded_at DESC");
$docs_stmt->bind_param("i", $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docs_stmt->close();

// Calculate statistics for the dashboard
$count_total = count($documents);
$count_approved = 0;
$count_pending = 0;
foreach ($documents as $doc) {
    $s = strtolower($doc['status'] ?? '');
    if ($s === 'approved') $count_approved++;
    elseif ($s === 'pending' || empty($s)) $count_pending++;
}

// Fetch Real Notifications for the student
$notif_stmt = $mysqli->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$real_notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

$unread_stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_res = $unread_stmt->get_result()->fetch_assoc();
$unread_count = $unread_res['c'] ?? 0;
$unread_stmt->close();


// Get the latest activity date
$last_upload = !empty($documents) ? date('M d, Y', strtotime($documents[0]['uploaded_at'])) : 'No uploads';

// Helper for file icons based on extension
function getFileIconClass($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'fa-file-pdf doc-icon-pdf';
    if (in_array($ext, ['doc', 'docx'])) return 'fa-file-word doc-icon-word';
    if (in_array($ext, ['jpg', 'jpeg', 'png'])) return 'fa-file-image doc-icon-img';
    return 'fa-file';
}

// Get greeting based on current time
date_default_timezone_set('Asia/Manila'); 
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="assets/js/theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="assets/css/theme_mode.css?v=9">
    <title>Student Dashboard - Registrar System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a365d;
            --bg-color: #f1f5f9;
            --sidebar-bg: #ffffff;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: #0f172a;
        }

        /* Dashboard Layout */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid #e2e8f0;
            padding: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100%;
            top: 0;
            bottom: 0;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 24px 20px 0 20px;
        }

        .logo-area {
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logo-icon {
            background: transparent;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 2px;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }

        .student-info {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }

        .student-name {
            font-weight: 700;
            font-size: 16px;
            color: #0f172a;
        }

        .student-id {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }

        .sidebar-status {
            margin-top: 12px;
            margin-bottom: 24px;
            padding: 8px 12px;
            background: #f0fdf4;
            border-radius: 10px;
            border: 1px solid #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: 600;
        }

        .nav-menu {
            padding: 0 20px 16px 20px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #475569;
            font-weight: 500;
            margin-bottom: 4px;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }

        .nav-item i {
            width: 24px;
            font-size: 18px;
            text-align: center;
        }

        .nav-item.active {
            background: var(--primary-color);
            color: white;
        }

        .nav-item.active i {
            color: white;
        }

        .nav-item:not(.active):hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .sidebar-bottom {
            padding: 12px 20px;
            border-top: 1px solid #e2e8f0;
            margin-top: 20px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #dc2626;
            font-weight: 500;
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 12px;
            transition: background 0.2s;
        }

        .logout-btn i {
            width: 24px;
            font-size: 18px;
            text-align: center;
        }

        .logout-btn:hover {
            background: #fef2f2;
        }

        /* Document Table & Upload Styles */
        .doc-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .doc-table th, .doc-table td { text-align: left; padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .doc-table th { color: #64748b; font-weight: 600; }
        
        .doc-name-cell { display: flex; align-items: center; gap: 10px; }
        .doc-icon-pdf { color: #ef4444; }
        .doc-icon-word { color: #3b82f6; }
        .doc-icon-img { color: #f59e0b; }

        .upload-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 2px dashed #cbd5e1;
        }
        
        .file-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .mobile-toggle {
            display: none; position: fixed; top: 15px; left: 15px; z-index: 1100;
            background: var(--primary-color); color: white; border: none; padding: 0;
            border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            width: 45px; height: 45px; align-items: center; justify-content: center;
        }
        .mobile-toggle i { font-size: 20px; }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px 40px;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #1e293b;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-title {
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            background: #eef2ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .stat-sub {
            font-size: 12px;
            color: #64748b;
        }

        /* Two Column Layout */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .card-header a {
            color: var(--primary-color);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }

        .request-list, .notification-list {
            padding: 0 24px;
        }

        .request-item, .notification-item {
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .request-info h4 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .request-date {
            font-size: 12px;
            color: #94a3b8;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-approved {
            background: #dcfce7;
            color: #15803d;
        }

        .status-pending {
            background: #fef9c3;
            color: #854d0e;
        }

        .notification-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .notification-text {
            font-size: 14px;
            color: #1e293b;
            overflow-wrap: anywhere;
        }

        .notification-time {
            font-size: 11px;
            color: #94a3b8;
        }

        .view-all {
            padding: 16px 24px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }

        .view-all a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }

        .enroll-status-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            margin-bottom: 24px;
        }

        .enroll-status-card h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-detail {
            background: #f0fdf4;
            padding: 12px 16px;
            border-radius: 12px;
            color: #166534;
            font-weight: 500;
            font-size: 14px;
        }

        .btn-request {
            background: var(--primary-color);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-request:hover {
            opacity: 0.9;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
                transition: 0.3s;
                width: 280px;
            }
            .sidebar.active {
                transform: translateX(0) !important;
            }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .main-content {
                margin-left: 0;
                padding: 24px;
            }
            .mobile-toggle {
                display: flex !important;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .two-columns {
                grid-template-columns: 1fr;
            }
        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        /* Notification Modal Styles */
        .notif-modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 99999; display: none; align-items: center; justify-content: center;
            padding: 16px; animation: fadeIn 0.2s ease-out;
        }
        .notif-modal-content {
            background: #ffffff; border-radius: 18px; width: 100%; max-width: 520px;
            max-height: 90vh; display: flex; flex-direction: column;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
            overflow: hidden; animation: slideUp 0.25s ease-out; border: 1px solid #e2e8f0;
        }
        .notif-modal-header {
            padding: 18px 20px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .notif-modal-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(26, 54, 93, 0.1); color: var(--primary-color);
            display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
            flex-shrink: 0;
        }
        .notif-modal-close {
            background: transparent; border: none; font-size: 28px; color: #94a3b8;
            cursor: pointer; line-height: 1; width: 40px; height: 40px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .notif-modal-close:hover, .notif-modal-close:active { color: #0f172a; background: #f1f5f9; }
        .notif-modal-body {
            padding: 20px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            flex: 1;
        }
        .notif-modal-footer {
            padding: 14px 20px; background: #f8fafc; border-top: 1px solid #f1f5f9;
            display: flex; justify-content: flex-end; gap: 10px;
            flex-shrink: 0;
        }
        .btn-modal-action {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            background: var(--primary-color); color: white; text-decoration: none;
            border-radius: 10px; font-size: 0.88rem; font-weight: 600; transition: opacity 0.2s;
        }
        .btn-modal-action:hover { opacity: 0.9; }
        .btn-modal-close {
            padding: 10px 18px; background: #e2e8f0; color: #475569;
            border: none; border-radius: 10px; font-size: 0.88rem; font-weight: 600; cursor: pointer; transition: background 0.2s;
        }
        .btn-modal-close:hover, .btn-modal-close:active { background: #cbd5e1; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
    <link rel="stylesheet" href="assets/css/student_mobile.css?v=1">
    <link rel="stylesheet" href="assets/css/student_sidebar.css?v=1">
    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="assets/css/student_profile.css?v=9">
    <script src="assets/js/student_profile.js?v=3" defer></script>
</head>
<body class="student-shell">
<div class="dashboard">
    <!-- Mobile Menu Button -->
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="student_dashboard.php" class="mobile-appbar-brand">
            <img src="assets/img/logo.jpg" alt="">
            <span>Student Portal</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-area">
                <div class="logo-icon">
                    <img src="assets/img/logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="logo-text">Student Portal</div>
            </div>

            <div class="student-info" style="text-align: center;">
                <?php if ($user_profile_picture): ?>
                    <img src="<?= htmlspecialchars($user_profile_picture) ?>" alt="Profile" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--primary-color);">
                <?php else: ?>
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin: 0 auto 10px auto;">
                        <?= strtoupper(substr($first_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="student-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="student-id">ID: <?= htmlspecialchars($user_student_id) ?></div>
                <?php if (!empty($user_course) || !empty($display_year_section)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $display_year_section)) ?>
                    </div>
                <?php endif; ?>
            </div>
            

        </div>

        <div class="nav-menu">
            <a href="student_dashboard.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Overview</span>
            </a>
            <a href="my_document.php" class="nav-item">
                <i class="fas fa-file-alt"></i>
                <span>My Documents</span>
            </a>
            <a href="student_calendar.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Calendar</span>
            </a>
            <a href="submissions.php" class="nav-item">
                <i class="fas fa-paper-plane"></i>
                <span>Submissions</span>
            </a>
            <a href="student_notifications.php" class="nav-item">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($unread_count > 0): ?><span class="nav-badge" style="margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 7px; border-radius: 20px;"><?= $unread_count ?></span><?php endif; ?>
            </a>
            <a href="checklist.php" class="nav-item">
                <i class="fas fa-list-check"></i>
                <span>Document Checklist</span>
            </a>
            <a href="student_request.php" class="nav-item"><i class="fas fa-file-signature"></i> <span>Requests</span></a>
            <a href="student_settings.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="top-bar-left">
                <h1 class="page-title"><?= $greeting ?>, <?= htmlspecialchars($first_name) ?>!</h1>
                <p>Here's a summary of your documents and account status.</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">My Documents</span>
                    <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
                </div>
                <div class="stat-value"><?= $count_total ?></div>
                <div class="stat-sub">Total uploaded</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Validated</span>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-value"><?= $count_approved ?></div>
                <div class="stat-sub">Approved requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">In Progress</span>
                    <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                </div>
                <div class="stat-value"><?= $count_pending ?></div>
                <div class="stat-sub">Pending review</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Last Activity</span>
                    <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                </div>
                <div class="stat-value" style="font-size: 1.5rem; margin-top: 10px;"><?= $last_upload ?></div>
                <div class="stat-sub">Recent document upload</div>
            </div>
        </div>

        <!-- Notifications Section -->
        <div class="two-columns">
            <!-- Notifications Column (Matches image notifications area) -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bell" style="margin-right: 8px;"></i> Notifications</h3>
                    <?php if ($unread_count > 0): ?>
                    <a href="mark_notification_read.php?id=all&redirect=student_dashboard.php">Mark all read</a>
                    <?php endif; ?>
                </div>
                <div class="notification-list">
                    <?php if (empty($real_notifications)): ?>
                        <div class="notification-item">
                            <div class="notification-text">No new updates.</div>
                        </div>
                    <?php else: foreach ($real_notifications as $n): 
                        $is_unread = empty($n['is_read']);
                        $is_unread = empty($n['is_read']);
                        $unread_bg = $is_unread ? 'background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--primary-color); padding-left: 12px;' : '';
                        $msg_lower = strtolower($n['message']);
                        $action_url = '';
                        $action_text = '';
                        if (strpos($msg_lower, 'reject') !== false || strpos($msg_lower, 'missing') !== false || strpos($msg_lower, 'document') !== false || strpos($msg_lower, 'form') !== false) {
                            $action_url = 'my_document.php';
                            $action_text = 'Go to My Documents';
                        } elseif (strpos($msg_lower, 'exam') !== false || strpos($msg_lower, 'schedule') !== false || strpos($msg_lower, 'sched') !== false || strpos($msg_lower, 'calendar') !== false) {
                            $action_url = 'student_calendar.php';
                            $action_text = 'Go to Calendar';
                        }
                        $notif_time = date('M d, Y h:i A', strtotime($n['created_at']));
                        $short_time = date('M d, h:i A', strtotime($n['created_at']));
                    ?>
                        <div class="notification-item <?= $is_unread ? 'unread-highlight' : '' ?>" 
                             id="notif-row-<?= (int)$n['id'] ?>"
                             data-id="<?= (int)$n['id'] ?>"
                             data-message="<?= htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8') ?>"
                             data-time="<?= htmlspecialchars($notif_time, ENT_QUOTES, 'UTF-8') ?>"
                             data-action-url="<?= htmlspecialchars($action_url, ENT_QUOTES, 'UTF-8') ?>"
                             data-action-text="<?= htmlspecialchars($action_text, ENT_QUOTES, 'UTF-8') ?>"
                             data-unread="<?= $is_unread ? '1' : '0' ?>"
                             onclick="openNotifModal(this)"
                             onkeydown="if(event.key==='Enter'||event.key===' '){openNotifModal(this);event.preventDefault();}"
                             role="button"
                             tabindex="0"
                             title="Click to view notification details"
                             style="cursor: pointer; transition: background 0.2s; <?= $unread_bg ?>" 
                             onmouseover="this.style.background='rgba(0,0,0,0.02)'" 
                             onmouseout="this.style.background='<?= $is_unread ? 'rgba(59, 130, 246, 0.08)' : 'transparent' ?>'">
                            <div class="notification-text" style="<?= $is_unread ? 'font-weight: 600;' : '' ?>">
                                <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 8px;"></i>
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                            <div class="notification-time">
                                <?= $short_time ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="view-all">
                    <a href="student_notifications.php">See all notifications <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </div>

        <!-- Optional footer hint: matches the image's "My Recent Requests & Notifications are aligned -->
        <div style="font-size: 12px; color: #94a3b8; text-align: center; padding: 16px 0;">
            <i class="fas fa-shield-alt"></i> Registrar System • Student Portal
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const savedColor = localStorage.getItem('student-theme-color');
        if (savedColor) {
            document.documentElement.style.setProperty('--primary-color', savedColor);
        }
        const savedBgColor = localStorage.getItem('student-bg-color');
        if (savedBgColor) {
            document.documentElement.style.setProperty('--bg-color', savedBgColor);
        }
        const savedSidebarBgColor = localStorage.getItem('student-sidebar-bg');
        if (savedSidebarBgColor) {
            document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBgColor);
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

    function openNotifModal(element) {
        if (!element) return;
        const id = element.getAttribute('data-id');
        const message = element.getAttribute('data-message');
        const time = element.getAttribute('data-time');
        const actionUrl = element.getAttribute('data-action-url');
        const actionText = element.getAttribute('data-action-text');
        const isUnread = element.getAttribute('data-unread') === '1';

        document.getElementById('modalNotifMessage').textContent = message;
        document.getElementById('modalNotifTime').textContent = time;

        const actionBtn = document.getElementById('modalActionBtn');
        if (actionUrl && actionText) {
            actionBtn.href = actionUrl;
            actionBtn.innerHTML = '<i class="fas fa-arrow-right"></i> ' + actionText;
            actionBtn.style.display = 'inline-flex';
        } else {
            actionBtn.style.display = 'none';
        }

        const modal = document.getElementById('notifDetailModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        if (isUnread) {
            element.setAttribute('data-unread', '0');
            element.classList.remove('unread-highlight');
            element.style.background = 'transparent';
            const textDiv = element.querySelector('.notification-text');
            if (textDiv) textDiv.style.fontWeight = 'normal';

            // Update badge count in UI
            const badges = document.querySelectorAll('.nav-badge');
            badges.forEach(b => {
                let count = parseInt(b.textContent.trim()) || 0;
                if (count > 1) {
                    b.textContent = count - 1;
                } else {
                    b.remove();
                }
            });

            // Mark as read in DB asynchronously
            fetch('mark_notification_read.php?id=' + encodeURIComponent(id) + '&ajax=1')
                .catch(err => console.error('Error marking read:', err));
        }
    }

    function closeNotifModal(e) {
        if (e && e.target !== document.getElementById('notifDetailModal') && !e.target.classList.contains('notif-modal-close') && !e.target.classList.contains('btn-modal-close')) {
            return;
        }
        const modal = document.getElementById('notifDetailModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // Close modal on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeNotifModal();
        }
    });

    // Auto-open modal if view_id is passed in URL query
    function checkAutoOpenModal() {
        const urlParams = new URLSearchParams(window.location.search);
        const viewId = urlParams.get('view_id');
        if (viewId) {
            const row = document.getElementById('notif-row-' + viewId);
            if (row) {
                openNotifModal(row);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAutoOpenModal);
    } else {
        checkAutoOpenModal();
    }
    window.addEventListener('pageshow', checkAutoOpenModal);
</script>

<!-- Notification Details Modal -->
<div id="notifDetailModal" class="notif-modal-overlay" style="display:none;" onclick="closeNotifModal(event)">
    <div class="notif-modal-content" onclick="event.stopPropagation()">
        <div class="notif-modal-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="notif-modal-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0;">Notification Details</h3>
                    <span id="modalNotifTime" style="font-size: 0.78rem; color: #64748b;"></span>
                </div>
            </div>
            <button type="button" class="notif-modal-close" onclick="closeNotifModal()">&times;</button>
        </div>
        <div class="notif-modal-body">
            <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 8px;">Message</div>
            <div id="modalNotifMessage" style="font-size: 0.95rem; line-height: 1.6; color: #1e293b; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; white-space: pre-wrap; word-break: break-word;"></div>
        </div>
        <div class="notif-modal-footer">
            <a id="modalActionBtn" href="#" class="btn-modal-action" style="display:none;"></a>
            <button type="button" class="btn-modal-close" onclick="closeNotifModal()">Close</button>
        </div>
    </div>
</div>
</body>
</html>
