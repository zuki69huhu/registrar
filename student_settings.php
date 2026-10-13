<?php
session_start();
require_once 'db.php';

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course, institution, contact_number, address, section FROM users WHERE id = ?");
if (!$stmt) {
    $_SESSION['flash'] = "System error. Please try again later.";
    header("Location: index.php?tab=login"); exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
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
$user_institution = $user_data['institution'] ?? '';
$user_contact_number = $user_data['contact_number'] ?? '';
$user_address = $user_data['address'] ?? '';
$user_section = $user_data['section'] ?? '';

$display_year_section = '';
if (!empty($user_year_level)) {
    $year_num = substr($user_year_level, 0, 1);
    $display_year_section = $year_num . $user_section;
}

$full_name_parts = [];
if (!empty($first_name)) $full_name_parts[] = $first_name;
if (!empty($middle_name)) $full_name_parts[] = $middle_name;
if (!empty($last_name)) $full_name_parts[] = $last_name;
$full_name = implode(' ', $full_name_parts);

if (empty($full_name)) {
    $full_name = $user_email;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Student Portal</title>
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

        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        .card-header {
            margin-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 16px;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }
        
        .color-options {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .custom-color-picker {
            width: 50px;
            height: 50px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            padding: 0;
            background: none;
            outline: none;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
                transition: 0.3s;
                width: 280px;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .sidebar.active {
                transform: translateX(0) !important;
            }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .mobile-toggle {
                display: flex !important;
            }
            .top-bar { padding-left: 55px; }
        /* COLOR SETTING ROW */
        .color-setting { margin-bottom: 24px; }
        .color-setting:last-child { margin-bottom: 0; }
        .color-setting-label { font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .color-setting-label i { font-size: 12px; opacity: 0.6; }
        .color-picker-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        
        .custom-color-picker { width: 48px; height: 48px; border: 2px solid rgba(0,0,0,0.1); border-radius: 12px; cursor: pointer; padding: 2px; background: none; outline: none; transition: border-color 0.2s, transform 0.2s; flex-shrink: 0; }
        .custom-color-picker:hover { border-color: rgba(0,0,0,0.3); transform: scale(1.05); }
        .custom-color-picker::-webkit-color-swatch-wrapper { padding: 2px; }
        .custom-color-picker::-webkit-color-swatch { border: none; border-radius: 6px; }
        
        .color-hex-input { width: 100px; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.1); background: rgba(255,255,255,0.8); color: #1e293b; font-family: 'Inter', monospace; font-size: 13px; font-weight: 500; text-transform: uppercase; transition: border-color 0.2s, box-shadow 0.2s; }
        .color-hex-input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(26,54,93,0.15); }
        
        /* PRESET SWATCHES */
        .preset-swatches { display: flex; gap: 6px; flex-wrap: wrap; }
        .swatch { width: 32px; height: 32px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; transition: all 0.2s; position: relative; }
        .swatch:hover { transform: scale(1.15); border-color: rgba(0,0,0,0.4); }
        .swatch.active { border-color: #333; box-shadow: 0 0 0 2px rgba(0,0,0,0.1); }
        .swatch.active::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
        
        /* RESET BUTTON */
        .reset-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; margin-top: 8px; }
        .reset-btn:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.3); transform: translateY(-1px); }
        
        /* LIVE PREVIEW */
        .live-preview { margin-top: 20px; padding: 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); background: rgba(0,0,0,0.02); }
        .live-preview-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 12px; }
        .preview-mini { display: flex; border-radius: 8px; overflow: hidden; height: 80px; border: 1px solid rgba(0,0,0,0.08); }
        .preview-sidebar-mini { width: 60px; display: flex; flex-direction: column; align-items: center; padding: 8px 4px; gap: 4px; }
        .preview-sidebar-mini .dot { width: 24px; height: 3px; border-radius: 2px; opacity: 0.3; background: #cbd5e1; }
        .preview-sidebar-mini .dot.active { opacity: 1; }
        .preview-main-mini { flex: 1; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
        .preview-main-mini .bar { height: 6px; border-radius: 3px; opacity: 0.15; background: #cbd5e1; }
        .preview-main-mini .bar.accent { width: 40%; opacity: 0.8; }
        
        /* TOAST */
        .toast { position: fixed; bottom: 30px; right: 30px; padding: 14px 24px; border-radius: 12px; font-size: 13px; font-weight: 600; color: white; z-index: 9999; display: flex; align-items: center; gap: 10px; transform: translateY(100px); opacity: 0; transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 8px 32px rgba(0,0,0,0.3); backdrop-filter: blur(10px); }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast-success { background: linear-gradient(135deg, #10b981, #059669); }
        .toast-info { background: linear-gradient(135deg, #1a365d, #0f2142); }

        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        /* Mobile form responsiveness */
        .form-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        .form-select {
            appearance: none;
            -webkit-appearance: none;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
            padding-right: 32px;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
        }
        .file-input-wrapper {
            position: relative;
            width: 100%;
        }
        .file-input-wrapper input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            background: #f8fafc;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
        }
        .file-input-wrapper input[type="file"]:hover {
            border-color: var(--primary-color);
            background: #eff6ff;
        }
        .file-input-wrapper input[type="file"]::file-selector-button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 10px;
            font-family: 'Inter', sans-serif;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 16px !important;
            }
            .card {
                padding: 16px;
                border-radius: 14px;
            }
            .page-title {
                font-size: 20px !important;
            }
            .form-grid-3 {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .form-grid-2 {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .form-select {
                font-size: 13px;
                white-space: normal;
                text-overflow: ellipsis;
            }
            .file-input-wrapper input[type="file"] {
                font-size: 12px;
                padding: 12px 10px;
            }
            .file-input-wrapper input[type="file"]::file-selector-button {
                padding: 8px 16px;
                font-size: 13px;
                display: block;
                margin-bottom: 8px;
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 420px) {
            .main-content {
                padding: 12px !important;
            }
            .card {
                padding: 14px;
            }
            .top-bar {
                margin-bottom: 20px;
            }
            .form-label {
                font-size: 13px;
            }
            .form-input, .form-select {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
    <link rel="stylesheet" href="student_mobile.css?v=1">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body class="student-shell">
<div class="dashboard">
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="student_dashboard.php" class="mobile-appbar-brand">
            <img src="logo.jpg" alt="">
            <span>Student Portal</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-area">
                <div class="logo-icon">
                    <img src="logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
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
                <?php if (!empty($user_course) || !empty($display_year_section) || !empty($user_institution)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim(($user_institution ? $user_institution . ' - ' : '') . $user_course . ' ' . $display_year_section)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-menu">
            <a href="student_dashboard.php" class="nav-item">
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
            <?php
            $unread_count = 0;
            if (isset($_SESSION['user_id'])) {
                $unread_stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
                if ($unread_stmt) {
                    $unread_stmt->bind_param("i", $_SESSION['user_id']);
                    $unread_stmt->execute();
                    $unread_res = $unread_stmt->get_result()->fetch_assoc();
                    $unread_count = $unread_res['c'] ?? 0;
                    $unread_stmt->close();
                }
            }
            ?>
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
            <a href="student_settings.php" class="nav-item active">
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
                <h1 class="page-title">Settings</h1>
                <p>Manage your account preferences and personalization.</p>
            </div>
        </div>

        <?php if (isset($_SESSION['flash'])): ?>
            <div style="padding: 15px; background: #dcfce7; color: #15803d; border-radius: 10px; margin-bottom: 20px; font-weight: 500;">
                <?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
            </div>
            <form action="process_profile.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="current_profile_picture" value="<?= htmlspecialchars($user_profile_picture ?? '') ?>">
                
                <div class="form-grid-3">
                    <div>
                        <label class="form-label">Profile Picture</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Year Level</label>
                        <select name="year_level" class="form-select">
                            <option value="">Select Year Level...</option>
                            <option value="1st Year" <?= $user_year_level === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                            <option value="2nd Year" <?= $user_year_level === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3rd Year" <?= $user_year_level === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4th Year" <?= $user_year_level === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                            <option value="Irregular" <?= $user_year_level === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Section</label>
                        <select name="section" class="form-select">
                            <option value="">Select Section...</option>
                            <?php foreach (range('A', 'Z') as $letter): ?>
                                <option value="<?= $letter ?>" <?= $user_section === (string)$letter ? 'selected' : '' ?>>Section <?= $letter ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label class="form-label">Institution</label>
                        <input type="text" value="<?= htmlspecialchars($user_institution) ?>" readonly class="form-input" style="background: #f1f5f9; color: #64748b; cursor: not-allowed;">
                    </div>
                    <div>
                        <label class="form-label">Course / Program</label>
                        <select name="course" class="form-select">
                            <option value="">Select Course...</option>
                            <?php if ($user_institution === 'ISAP' || empty($user_institution)): ?>
                            <optgroup label="ISAP">
                                <option value="BS Accountancy" <?= $user_course === 'BS Accountancy' ? 'selected' : '' ?>>BS Accountancy</option>
                                <option value="BS Business administration" <?= $user_course === 'BS Business administration' ? 'selected' : '' ?>>BS Business Administration</option>
                                <option value="BS Computer engineering" <?= $user_course === 'BS Computer engineering' ? 'selected' : '' ?>>BS Computer Engineering</option>
                                <option value="BS Criminology" <?= $user_course === 'BS Criminology' ? 'selected' : '' ?>>BS Criminology</option>
                                <option value="BS Custom Administration" <?= $user_course === 'BS Custom Administration' ? 'selected' : '' ?>>BS Custom Administration</option>
                                <option value="BS Hospitality management" <?= $user_course === 'BS Hospitality management' ? 'selected' : '' ?>>BS Hospitality Management</option>
                                <option value="BS Information Technology" <?= $user_course === 'BS Information Technology' ? 'selected' : '' ?>>BS Information Technology</option>
                                <option value="BS Physical education" <?= $user_course === 'BS Physical education' ? 'selected' : '' ?>>BS Physical Education</option>
                                <option value="BS Psychology" <?= $user_course === 'BS Psychology' ? 'selected' : '' ?>>BS Psychology</option>
                                <option value="BS Secondary education" <?= $user_course === 'BS Secondary education' ? 'selected' : '' ?>>BS Secondary Education</option>
                                <option value="BS Social work" <?= $user_course === 'BS Social work' ? 'selected' : '' ?>>BS Social Work</option>
                                <option value="BS Tourism management" <?= $user_course === 'BS Tourism management' ? 'selected' : '' ?>>BS Tourism Management</option>
                            </optgroup>
                            <?php endif; ?>
                            <?php if ($user_institution === 'MCNP' || empty($user_institution)): ?>
                            <optgroup label="MCNP">
                                <option value="BS Medical laboratory science" <?= $user_course === 'BS Medical laboratory science' ? 'selected' : '' ?>>BS Medical Lab Science</option>
                                <option value="BS Midwifery" <?= $user_course === 'BS Midwifery' ? 'selected' : '' ?>>BS Midwifery</option>
                                <option value="BS Nursing" <?= $user_course === 'BS Nursing' ? 'selected' : '' ?>>BS Nursing</option>
                                <option value="BS Pharmacy" <?= $user_course === 'BS Pharmacy' ? 'selected' : '' ?>>BS Pharmacy</option>
                                <option value="BS Physical therapy" <?= $user_course === 'BS Physical therapy' ? 'selected' : '' ?>>BS Physical Therapy</option>
                                <option value="BS Radiologic technology" <?= $user_course === 'BS Radiologic technology' ? 'selected' : '' ?>>BS Radiologic Technology</option>
                                <option value="Dental technology" <?= $user_course === 'Dental technology' ? 'selected' : '' ?>>Dental Technology</option>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" placeholder="e.g. 09123456789" value="<?= htmlspecialchars($user_contact_number) ?>" class="form-input">
                </div>

                <div style="margin-bottom: 20px;">
                    <label class="form-label">Address</label>
                    <textarea name="address" rows="3" class="form-input" style="resize: vertical;"><?= htmlspecialchars($user_address) ?></textarea>
                </div>

                <div>
                    <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;">
                        Save Profile Changes
                    </button>
                </div>
            </form>
        </div>

        <div class="card" style="border: 1px solid rgba(0,0,0,0.05); padding: 24px; border-radius: 16px; background: white; margin-bottom: 24px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; background: rgba(26,54,93,0.1); color: var(--primary-color);"><i class="fas fa-palette"></i></div>
                <div>
                    <h3 style="font-size: 15px; font-weight: 600; color: #1e293b; margin: 0;">Theme Personalization</h3>
                    <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Customize the look and feel of your dashboard — changes apply instantly</p>
                </div>
            </div>
            
            <!-- Accent Color -->
            <div class="color-setting">
                <div class="color-setting-label"><i class="fas fa-droplet"></i> Accent Theme Color</div>
                <div class="color-picker-row">
                    <input type="color" id="custom-theme-color" class="custom-color-picker" oninput="setThemeColor(this.value); syncHex('theme-hex', this.value); updateSwatches('theme'); updatePreview()" onchange="setThemeColor(this.value); showToast('Accent color updated!')" title="Pick accent color">
                    <input type="text" id="theme-hex" class="color-hex-input" maxlength="7" placeholder="#1a365d" oninput="applyHex(this, 'custom-theme-color', setThemeColor); updateSwatches('theme'); updatePreview()">
                    <div class="preset-swatches" id="theme-swatches"></div>
                </div>
            </div>

            <!-- Background Color -->
            <div class="color-setting">
                <div class="color-setting-label"><i class="fas fa-fill-drip"></i> Background Color</div>
                <div class="color-picker-row">
                    <input type="color" id="custom-bg-color" class="custom-color-picker" oninput="setBgColor(this.value); syncHex('bg-hex', this.value); updateSwatches('bg'); updatePreview()" onchange="setBgColor(this.value); showToast('Background color updated!')" title="Pick background color">
                    <input type="text" id="bg-hex" class="color-hex-input" maxlength="7" placeholder="#f1f5f9" oninput="applyHex(this, 'custom-bg-color', setBgColor); updateSwatches('bg'); updatePreview()">
                    <div class="preset-swatches" id="bg-swatches"></div>
                </div>
            </div>

            <!-- Sidebar Color -->
            <div class="color-setting">
                <div class="color-setting-label"><i class="fas fa-columns"></i> Sidebar Color</div>
                <div class="color-picker-row">
                    <input type="color" id="custom-sidebar-color" class="custom-color-picker" oninput="setSidebarBg(this.value); syncHex('sidebar-hex', this.value); updateSwatches('sidebar'); updatePreview()" onchange="setSidebarBg(this.value); showToast('Sidebar color updated!')" title="Pick sidebar color">
                    <input type="text" id="sidebar-hex" class="color-hex-input" maxlength="7" placeholder="#ffffff" oninput="applyHex(this, 'custom-sidebar-color', setSidebarBg); updateSwatches('sidebar'); updatePreview()">
                    <div class="preset-swatches" id="sidebar-swatches"></div>
                </div>
            </div>

            <!-- Live Preview -->
            <div class="live-preview">
                <div class="live-preview-label"><i class="fas fa-eye" style="margin-right: 4px;"></i> Live Preview</div>
                <div class="preview-mini" id="live-preview-box">
                    <div class="preview-sidebar-mini" id="preview-sidebar">
                        <div class="dot active" id="preview-accent-dot"></div>
                        <div class="dot"></div>
                        <div class="dot"></div>
                        <div class="dot"></div>
                    </div>
                    <div class="preview-main-mini" id="preview-main">
                        <div class="bar accent" id="preview-accent-bar"></div>
                        <div class="bar" style="width: 80%;"></div>
                        <div class="bar" style="width: 60%;"></div>
                        <div class="bar" style="width: 90%;"></div>
                    </div>
                </div>
            </div>

            <!-- Reset Button -->
            <button class="reset-btn" onclick="resetToDefaults()">
                <i class="fas fa-rotate-left"></i> Reset to Defaults
            </button>
        </div>

    </div>
</div>

<!-- Toast notification -->
<div class="toast toast-info" id="toast"></div>

<script>
    // ─── PRESET COLORS ───
    const presets = {
        theme: ['#1a365d','#c9a227','#10b981','#8b5cf6','#ef4444','#f59e0b','#ec4899','#06b6d4','#84cc16','#f97316'],
        bg:    ['#f1f5f9','#0f172a','#1a1a2e','#f8fafc','#f3f4f6','#ffffff','#1e1e2e','#0d1117','#0c0c1d','#0b132b'],
        sidebar: ['#ffffff','#0f172a','#1a1a2e','#f8fafc','#f1f5f9','#1e1e2e','#0d1117','#171717','#0c0a09','#0b132b']
    };

    function buildSwatches(type) {
        const container = document.getElementById(type + '-swatches');
        if (!container) return;
        container.innerHTML = '';
        presets[type].forEach(color => {
            const swatch = document.createElement('div');
            swatch.className = 'swatch';
            swatch.style.background = color;
            swatch.title = color;
            swatch.onclick = () => {
                document.getElementById(type === 'theme' ? 'custom-theme-color' : type === 'bg' ? 'custom-bg-color' : 'custom-sidebar-color').value = color;
                document.getElementById(type === 'theme' ? 'theme-hex' : type === 'bg' ? 'bg-hex' : 'sidebar-hex').value = color;
                if (type === 'theme') setThemeColor(color);
                else if (type === 'bg') setBgColor(color);
                else setSidebarBg(color);
                updateSwatches(type);
                updatePreview();
                showToast('Color applied!');
            };
            container.appendChild(swatch);
        });
        updateSwatches(type);
    }

    function updateSwatches(type) {
        const pickerId = type === 'theme' ? 'custom-theme-color' : type === 'bg' ? 'custom-bg-color' : 'custom-sidebar-color';
        const currentColor = document.getElementById(pickerId).value.toLowerCase();
        const container = document.getElementById(type + '-swatches');
        if (!container) return;
        container.querySelectorAll('.swatch').forEach(s => {
            const swatchColor = rgbToHex(s.style.backgroundColor) || s.title;
            s.classList.toggle('active', swatchColor.toLowerCase() === currentColor);
        });
    }

    function rgbToHex(rgb) {
        if (rgb.startsWith('#')) return rgb;
        const match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
        if (!match) return '';
        return '#' + [match[1], match[2], match[3]].map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
    }

    function syncHex(hexId, value) {
        document.getElementById(hexId).value = value;
    }

    function applyHex(input, pickerId, fn) {
        const val = input.value;
        if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
            document.getElementById(pickerId).value = val;
            fn(val);
        }
    }

    function setThemeColor(color) {
        document.documentElement.style.setProperty('--primary-color', color);
        localStorage.setItem('student-theme-color', color);
    }
    
    function setBgColor(color) {
        document.documentElement.style.setProperty('--bg-color', color);
        localStorage.setItem('student-bg-color', color);
    }
    
    function setSidebarBg(color) {
        document.documentElement.style.setProperty('--sidebar-bg', color);
        localStorage.setItem('student-sidebar-bg', color);
    }

    // ─── RESET ───
    function resetToDefaults() {
        const defaults = { theme: '#1a365d', bg: '#f1f5f9', sidebar: '#ffffff' };

        setThemeColor(defaults.theme);
        setBgColor(defaults.bg);
        setSidebarBg(defaults.sidebar);

        document.getElementById('custom-theme-color').value = defaults.theme;
        document.getElementById('theme-hex').value = defaults.theme;
        document.getElementById('custom-bg-color').value = defaults.bg;
        document.getElementById('bg-hex').value = defaults.bg;
        document.getElementById('custom-sidebar-color').value = defaults.sidebar;
        document.getElementById('sidebar-hex').value = defaults.sidebar;

        updateSwatches('theme');
        updateSwatches('bg');
        updateSwatches('sidebar');
        updatePreview();
        showToast('Reset to default colors!', 'success');
    }

    // ─── LIVE PREVIEW ───
    function updatePreview() {
        const accent = document.getElementById('custom-theme-color').value;
        const bg = document.getElementById('custom-bg-color').value;
        const sidebar = document.getElementById('custom-sidebar-color').value;

        document.getElementById('preview-sidebar').style.background = sidebar;
        document.getElementById('preview-main').style.background = bg;
        document.getElementById('preview-accent-dot').style.background = accent;
        document.getElementById('preview-accent-bar').style.background = accent;
    }

    // ─── TOAST ───
    function showToast(msg, type = 'info') {
        const toast = document.getElementById('toast');
        toast.className = 'toast toast-' + type;
        toast.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'paint-brush') + '"></i> ' + msg;
        toast.classList.add('show');
        clearTimeout(window._toastTimer);
        window._toastTimer = setTimeout(() => toast.classList.remove('show'), 2500);
    }

    // Load saved color on page load
    document.addEventListener('DOMContentLoaded', () => {
        const savedColor = localStorage.getItem('student-theme-color') || '#1a365d';
        document.documentElement.style.setProperty('--primary-color', savedColor);
        if (/^#[0-9A-F]{6}$/i.test(savedColor)) { document.getElementById('custom-theme-color').value = savedColor; document.getElementById('theme-hex').value = savedColor; }
        
        const savedBgColor = localStorage.getItem('student-bg-color') || '#f1f5f9';
        document.documentElement.style.setProperty('--bg-color', savedBgColor);
        if (/^#[0-9A-F]{6}$/i.test(savedBgColor)) { document.getElementById('custom-bg-color').value = savedBgColor; document.getElementById('bg-hex').value = savedBgColor; }
        
        const savedSidebarBgColor = localStorage.getItem('student-sidebar-bg') || '#ffffff';
        document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBgColor);
        if (/^#[0-9A-F]{6}$/i.test(savedSidebarBgColor)) { document.getElementById('custom-sidebar-color').value = savedSidebarBgColor; document.getElementById('sidebar-hex').value = savedSidebarBgColor; }
        
        buildSwatches('theme');
        buildSwatches('bg');
        buildSwatches('sidebar');
        updatePreview();
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
