<?php
session_start();
require_once 'db.php';
require_once 'profile_config.php';

ensureProfileColumn($mysqli);

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

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course, institution, contact_number, address, section, profile_data FROM users WHERE id = ?");
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
$profile = decodeStudentProfile($user_data['profile_data'] ?? '');
// From what is saved, not from a half-typed form that failed validation.
$profile_missing = profileMissingFields($user_data, $profile);
// Set by process_profile.php only on the save that completed the profile, and
// read once, so the congratulations appear a single time.
$profile_just_completed = !empty($_SESSION['profile_completed']) && !$profile_missing;
unset($_SESSION['profile_completed']);

// A save that failed validation comes back here with what the student typed,
// so nothing has to be entered twice. The sidebar keeps showing the saved values.
$form_year_level = $user_year_level;
$form_section = $user_section;
$form_course = $user_course;
if (isset($_SESSION['profile_old']) && is_array($_SESSION['profile_old'])) {
    $old = $_SESSION['profile_old'];
    $form_year_level = $old['year_level'] ?? $form_year_level;
    $form_section = $old['section'] ?? $form_section;
    $form_course = $old['course'] ?? $form_course;
    $user_contact_number = $old['contact_number'] ?? $user_contact_number;
    $user_address = $old['address'] ?? $user_address;
    if (is_array($old['profile'] ?? null)) $profile = $old['profile'];
}
$profile_errors = $_SESSION['profile_errors'] ?? [];
$flash = $_SESSION['flash'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['profile_old'], $_SESSION['profile_errors'], $_SESSION['flash'], $_SESSION['flash_type']);

$profile_age = profileAge($profile['birth_date'] ?? '');
$profile_max_year = (int)date('Y') + 1;

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
    <script src="theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
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
    <link rel="stylesheet" href="student_profile.css?v=9">
    <script src="student_profile.js?v=3" defer></script>
    <script src="address_picker.js?v=4" defer></script>
    <script src="school_picker.js?v=1" defer></script>
</head>
<body class="student-shell" data-profile-missing="<?= count($profile_missing) ?>">
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

        <?php if ($flash !== ''): ?>
            <div class="pf-flash<?= $flash_type === 'error' ? ' is-error' : '' ?>" role="status">
                <?= htmlspecialchars($flash) ?>
                <?php if ($profile_errors): ?>
                    <ul>
                        <?php foreach ($profile_errors as $profile_error): ?>
                            <li><?= htmlspecialchars($profile_error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card" id="edit-profile" style="margin-bottom: 24px;">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
                <button type="button" class="pf-btn is-ghost" data-open-profile style="padding: 8px 14px; font-size: 13px;">
                    <i class="fas fa-eye"></i> View my profile
                </button>
            </div>
            <?php if ($profile_missing): ?>
                <div class="pf-missing" role="status">
                    <i class="fas fa-circle-exclamation"></i>
                    <div>
                        <strong>Your profile is not complete yet.</strong>
                        <?= count($profile_missing) ?> required <?= count($profile_missing) === 1 ? 'detail is' : 'details are' ?> still missing
                        (marked <span class="pf-req">*</span> below): <?= htmlspecialchars(implode(', ', $profile_missing)) ?>.
                    </div>
                </div>
            <?php endif; ?>
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
                            <option value="1st Year" <?= $form_year_level === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                            <option value="2nd Year" <?= $form_year_level === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3rd Year" <?= $form_year_level === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4th Year" <?= $form_year_level === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                            <option value="Irregular" <?= $form_year_level === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Section</label>
                        <select name="section" class="form-select">
                            <option value="">Select Section...</option>
                            <?php foreach (range('A', 'Z') as $letter): ?>
                                <option value="<?= $letter ?>" <?= $form_section === (string)$letter ? 'selected' : '' ?>>Section <?= $letter ?></option>
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
                                <option value="BS Accountancy" <?= $form_course === 'BS Accountancy' ? 'selected' : '' ?>>BS Accountancy</option>
                                <option value="BS Business administration" <?= $form_course === 'BS Business administration' ? 'selected' : '' ?>>BS Business Administration</option>
                                <option value="BS Computer engineering" <?= $form_course === 'BS Computer engineering' ? 'selected' : '' ?>>BS Computer Engineering</option>
                                <option value="BS Criminology" <?= $form_course === 'BS Criminology' ? 'selected' : '' ?>>BS Criminology</option>
                                <option value="BS Custom Administration" <?= $form_course === 'BS Custom Administration' ? 'selected' : '' ?>>BS Custom Administration</option>
                                <option value="BS Hospitality management" <?= $form_course === 'BS Hospitality management' ? 'selected' : '' ?>>BS Hospitality Management</option>
                                <option value="BS Information Technology" <?= $form_course === 'BS Information Technology' ? 'selected' : '' ?>>BS Information Technology</option>
                                <option value="BS Physical education" <?= $form_course === 'BS Physical education' ? 'selected' : '' ?>>BS Physical Education</option>
                                <option value="BS Psychology" <?= $form_course === 'BS Psychology' ? 'selected' : '' ?>>BS Psychology</option>
                                <option value="BS Secondary education" <?= $form_course === 'BS Secondary education' ? 'selected' : '' ?>>BS Secondary Education</option>
                                <option value="BS Social work" <?= $form_course === 'BS Social work' ? 'selected' : '' ?>>BS Social Work</option>
                                <option value="BS Tourism management" <?= $form_course === 'BS Tourism management' ? 'selected' : '' ?>>BS Tourism Management</option>
                            </optgroup>
                            <?php endif; ?>
                            <?php if ($user_institution === 'MCNP' || empty($user_institution)): ?>
                            <optgroup label="MCNP">
                                <option value="BS Medical laboratory science" <?= $form_course === 'BS Medical laboratory science' ? 'selected' : '' ?>>BS Medical Lab Science</option>
                                <option value="BS Midwifery" <?= $form_course === 'BS Midwifery' ? 'selected' : '' ?>>BS Midwifery</option>
                                <option value="BS Nursing" <?= $form_course === 'BS Nursing' ? 'selected' : '' ?>>BS Nursing</option>
                                <option value="BS Pharmacy" <?= $form_course === 'BS Pharmacy' ? 'selected' : '' ?>>BS Pharmacy</option>
                                <option value="BS Physical therapy" <?= $form_course === 'BS Physical therapy' ? 'selected' : '' ?>>BS Physical Therapy</option>
                                <option value="BS Radiologic technology" <?= $form_course === 'BS Radiologic technology' ? 'selected' : '' ?>>BS Radiologic Technology</option>
                                <option value="Dental technology" <?= $form_course === 'Dental technology' ? 'selected' : '' ?>>Dental Technology</option>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <?php
                // Region -> province -> city / municipality -> barangay, filled by
                // address_picker.js from psgc/. $legacy is an address typed before
                // the picker existed: shown so the student can pick the same place.
                $place_picker = function ($name, $id, $place, array $opts = []) {
                    $place = is_array($place) ? $place : [];
                    $levels = ['region' => 'Region', 'province' => 'Province', 'city' => 'City / Municipality', 'barangay' => 'Barangay'];
                    $html = '<div class="pf-place" data-place-group id="' . $id . '_group">';
                    foreach ($levels as $level => $label) {
                        $html .= '<div><label class="form-label pf-place-label" for="' . $id . '_' . $level . '">' . $label . '</label>'
                               . '<select class="form-select" id="' . $id . '_' . $level . '" name="' . $name . '[' . $level . ']" data-place="' . $level . '"'
                               . ' data-value="' . htmlspecialchars($place[$level] ?? '') . '">'
                               . '<option value="">' . htmlspecialchars(($place[$level] ?? '') !== '' ? $place[$level] : 'Loading...') . '</option></select></div>';
                    }
                    if (!empty($opts['street'])) {
                        $html .= '<div class="pf-place-street"><label class="form-label pf-place-label" for="' . $id . '_street">House No. / Street / Purok</label>'
                               . '<input type="text" class="form-input" id="' . $id . '_street" name="' . $name . '[street]" maxlength="150"'
                               . ' placeholder="Leave blank if none" value="' . htmlspecialchars($place['street'] ?? '') . '"></div>';
                    }
                    if (!empty($opts['zip'])) {
                        $html .= '<div><label class="form-label pf-place-label" for="' . $id . '_zip">ZIP Code' . (!empty($opts['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</label>'
                               . '<input type="text" class="form-input" id="' . $id . '_zip" name="' . $name . '[zip]" inputmode="numeric" maxlength="4" pattern="\d{4}"'
                               . ' title="4 digits, e.g. 3500" placeholder="e.g. 3500" value="' . htmlspecialchars($place['zip'] ?? '') . '"></div>';
                    }
                    $html .= '</div>';
                    if (!empty($opts['legacy']) && trim((string)($place['region'] ?? '')) === '') {
                        $html .= '<span class="pf-hint pf-legacy"><i class="fas fa-circle-info"></i> Typed before: <strong>'
                               . htmlspecialchars($opts['legacy']) . '</strong> &mdash; please pick it from the lists above.</span>';
                    }
                    return $html;
                };

                // One input of the profile, drawn from its definition in profile_config.php.
                $profile_input = function ($key, $field) use ($profile, $profile_age, $place_picker, $user_data) {
                    if ($field['type'] === 'address') {
                        $legacy = $key === 'home' ? ($user_data['address'] ?? '') : ($key === 'guardian_place' ? ($profile['guardian_address'] ?? '') : '');
                        echo '<div class="is-wide"><div class="pf-place-head"><span class="form-label">' . htmlspecialchars($field['label'])
                           . (!empty($field['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</span>';
                        if (!empty($field['copy_home'])) {
                            // Most families share one address: copy the permanent one in a click.
                            echo '<button type="button" class="pf-copy-place" data-copy-from="pf_home_group" data-copy-to="pf_' . $key . '_group">'
                               . '<i class="fas fa-copy"></i> Same as my permanent address</button>';
                        }
                        echo '</div>';
                        echo $place_picker('profile[' . $key . ']', 'pf_' . $key, $profile[$key] ?? [], [
                            'street' => true, 'zip' => !empty($field['zip']), 'required' => !empty($field['required']), 'legacy' => is_string($legacy) ? $legacy : '',
                        ]);
                        echo '</div>';
                        return;
                    }
                    // A parent's name used to be one box; until it is split, show what was typed.
                    $legacy_name = ['father_last_name' => 'father_name', 'mother_last_name' => 'mother_name'][$key] ?? null;
                    $value = htmlspecialchars($profile[$key] ?? '');
                    $id = 'pf_' . $key;
                    $name = 'profile[' . $key . ']';
                    echo '<div' . (!empty($field['wide']) ? ' class="is-wide"' : '') . '>';
                    echo '<label class="form-label" for="' . $id . '">' . htmlspecialchars($field['label']) . (!empty($field['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</label>';
                    if ($field['type'] === 'select') {
                        echo '<select class="form-select" id="' . $id . '" name="' . $name . '"><option value="">Select...</option>';
                        foreach ($field['options'] as $option) {
                            echo '<option' . (($profile[$key] ?? '') === $option ? ' selected' : '') . '>' . htmlspecialchars($option) . '</option>';
                        }
                        echo '</select>';
                    } elseif ($field['type'] === 'date') {
                        echo '<input class="form-input" type="date" id="' . $id . '" name="' . $name . '" value="' . $value . '" max="' . date('Y-m-d') . '">';
                        echo '<span class="pf-hint" id="pfAge" aria-live="polite">' . ($profile_age !== null ? 'Age: ' . $profile_age . ' years old' : 'Your age is worked out from this.') . '</span>';
                    } elseif ($field['type'] === 'tel') {
                        echo '<input class="form-input" type="tel" inputmode="tel" autocomplete="off" id="' . $id . '" name="' . $name . '" value="' . $value . '" maxlength="20" pattern="' . PROFILE_PHONE_HTML_PATTERN . '" title="Digits only, e.g. 09123456789" placeholder="e.g. 09123456789">';
                    } else {
                        echo '<input class="form-input" type="text" id="' . $id . '" name="' . $name . '" value="' . $value . '" maxlength="' . (int)($field['max'] ?? 120) . '" placeholder="' . htmlspecialchars($field['placeholder'] ?? '') . '">';
                    }
                    if ($legacy_name && ($profile[$key] ?? '') === '' && !empty($profile[$legacy_name])) {
                        echo '<span class="pf-hint pf-legacy">Typed before as one line: <strong>' . htmlspecialchars($profile[$legacy_name]) . '</strong></span>';
                    }
                    echo '</div>';
                };
                $profile_sections = getProfileSections();
                ?>

                <div class="pf-section">
                    <h4><i class="fas <?= $profile_sections['personal']['icon'] ?>"></i> <?= htmlspecialchars($profile_sections['personal']['title']) ?></h4>
                    <p>Your name and student ID come from your registration. Ask the Registrar's Office if either needs correcting.</p>
                </div>
                <div class="pf-grid">
                    <div>
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-input pf-readonly" value="<?= htmlspecialchars($last_name) ?>" readonly>
                    </div>
                    <div>
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-input pf-readonly" value="<?= htmlspecialchars($first_name) ?>" readonly>
                    </div>
                    <div>
                        <label class="form-label">Middle Name</label>
                        <input type="text" class="form-input pf-readonly" value="<?= htmlspecialchars($middle_name) ?>" readonly>
                    </div>
                    <?php foreach ($profile_sections['personal']['fields'] as $key => $field) $profile_input($key, $field); ?>
                </div>

                <div class="pf-section">
                    <h4><i class="fas fa-address-book"></i> Contact Details</h4>
                </div>
                <div class="pf-grid">
                    <div>
                        <label class="form-label" for="pf_contact_number">Contact Number <span class="pf-req" title="Required">*</span></label>
                        <input type="tel" inputmode="tel" id="pf_contact_number" name="contact_number" placeholder="e.g. 09123456789" value="<?= htmlspecialchars($user_contact_number) ?>" class="form-input" maxlength="20" pattern="<?= PROFILE_PHONE_HTML_PATTERN ?>" title="Digits only, e.g. 09123456789">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="text" class="form-input pf-readonly" value="<?= htmlspecialchars($user_email) ?>" readonly>
                    </div>
                </div>

                <?php foreach ($profile_sections as $section_key => $profile_section): ?>
                    <?php if ($section_key === 'personal') continue; ?>
                    <div class="pf-section">
                        <h4><i class="fas <?= $profile_section['icon'] ?>"></i> <?= htmlspecialchars($profile_section['title']) ?></h4>
                        <?php if (!empty($profile_section['note'])): ?><p><?= htmlspecialchars($profile_section['note']) ?></p><?php endif; ?>
                    </div>
                    <div class="pf-grid">
                        <?php foreach ($profile_section['fields'] as $key => $field) $profile_input($key, $field); ?>
                    </div>
                <?php endforeach; ?>

                <div class="pf-section">
                    <h4><i class="fas fa-graduation-cap"></i> Educational Background</h4>
                    <p>List every school you attended, starting from the first. Give the years you were there, e.g. 2012 to 2018.</p>
                </div>
                <?php foreach (getProfileEducationLevels() as $level_key => $level): ?>
                    <?php
                    $edu = $profile['education'][$level_key] ?? [];
                    $edu_name = 'education[' . $level_key . ']';
                    $edu_id = 'pf_edu_' . $level_key;
                    ?>
                    <div class="pf-edu">
                        <div class="pf-edu-title">
                            <?= htmlspecialchars($level['label']) ?>
                            <?php if (empty($level['always'])): ?><small>&ndash; optional</small><?php endif; ?>
                        </div>
                        <div class="pf-edu-grid"<?= !empty($level['school_list']) ? ' data-school-picker data-levels="' . (int)$level['school_list'] . '"' : '' ?>>
                            <?php /* Address first: once the city is chosen, school_picker.js lists that place's schools. */ ?>
                            <div class="pf-edu-place">
                                <label class="form-label">School Address</label>
                                <?= $place_picker($edu_name . '[place]', $edu_id . '_place', $edu, ['legacy' => $edu['address'] ?? '']) ?>
                            </div>
                            <div class="pf-edu-school">
                                <label class="form-label" for="<?= $edu_id ?>_<?= !empty($level['school_list']) ? 'pick' : 'school' ?>">Name of School<?php if (!empty($level['always'])): ?> <span class="pf-req" title="Required">*</span><?php endif; ?></label>
                                <?php if (!empty($level['school_list'])): ?>
                                    <select class="form-select" id="<?= $edu_id ?>_pick" data-school-select disabled>
                                        <option value="">Choose the school's city / municipality first</option>
                                    </select>
                                <?php endif; ?>
                                <input type="text" class="form-input" id="<?= $edu_id ?>_school" name="<?= $edu_name ?>[school]" maxlength="150"
                                       <?= !empty($level['school_list']) ? 'data-school-other placeholder="Type the name of your school"' : 'placeholder="Name of the college"' ?>
                                       value="<?= htmlspecialchars($edu['school'] ?? '') ?>">
                            </div>
                            <div>
                                <label class="form-label" for="<?= $edu_id ?>_from">S.Y. From</label>
                                <input type="number" inputmode="numeric" class="form-input pf-year-from" id="<?= $edu_id ?>_from" name="<?= $edu_name ?>[year_from]" min="<?= PROFILE_MIN_SCHOOL_YEAR ?>" max="<?= $profile_max_year ?>" placeholder="YYYY" value="<?= htmlspecialchars($edu['year_from'] ?? '') ?>">
                            </div>
                            <div>
                                <label class="form-label" for="<?= $edu_id ?>_to">S.Y. To</label>
                                <input type="number" inputmode="numeric" class="form-input pf-year-to" id="<?= $edu_id ?>_to" name="<?= $edu_name ?>[year_to]" min="<?= PROFILE_MIN_SCHOOL_YEAR ?>" max="<?= $profile_max_year ?>" placeholder="YYYY" value="<?= htmlspecialchars($edu['year_to'] ?? '') ?>">
                            </div>
                            <?php if (!empty($level['extra'])): ?>
                                <div class="is-wide">
                                    <label class="form-label" for="<?= $edu_id ?>_extra"><?= htmlspecialchars($level['extra']) ?></label>
                                    <input type="text" class="form-input" id="<?= $edu_id ?>_extra" name="<?= $edu_name ?>[extra]" maxlength="100" placeholder="<?= htmlspecialchars($level['extra_placeholder'] ?? '') ?>" value="<?= htmlspecialchars($edu['extra'] ?? '') ?>">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="pf-actions">
                    <button type="submit" class="pf-btn is-primary">
                        <i class="fas fa-floppy-disk"></i> Save Profile Changes
                    </button>
                </div>
            </form>
        </div>

        <div class="card" id="appearance" style="margin-bottom: 24px;">
            <div class="card-header">
                <h3><i class="fas fa-circle-half-stroke"></i> Appearance</h3>
            </div>
            <div class="theme-choices">
                <button type="button" class="theme-choice" data-theme-choice="light" aria-pressed="false">
                    <span class="theme-choice-icon"><i class="fas fa-sun"></i></span>
                    <span><strong>Light mode</strong><small>Bright background, dark text</small></span>
                    <i class="fas fa-circle-check theme-choice-check"></i>
                </button>
                <button type="button" class="theme-choice" data-theme-choice="dark" aria-pressed="false">
                    <span class="theme-choice-icon"><i class="fas fa-moon"></i></span>
                    <span><strong>Dark mode</strong><small>Dark background, light text</small></span>
                    <i class="fas fa-circle-check theme-choice-check"></i>
                </button>
            </div>
            <p class="theme-note">Applies right away to every page of your portal on this device.</p>
        </div>
    </div>
</div>

<!-- Toast notification -->
<?php if ($profile_just_completed): ?>
<div class="pf-done" id="pfDone" role="dialog" aria-modal="true" aria-labelledby="pfDoneTitle">
    <div class="pf-done-backdrop" data-pf-done-close></div>
    <div class="pf-done-card" tabindex="-1">
        <div class="pf-done-icon"><i class="fas fa-circle-check"></i></div>
        <h3 id="pfDoneTitle">Your profile is complete!</h3>
        <p>Every required detail is filled in. Keep it up to date whenever something changes &mdash; the registrar uses it for your records and forms.</p>
        <div class="pf-done-actions">
            <button type="button" class="pf-btn is-ghost" data-pf-done-close data-open-profile><i class="fas fa-eye"></i> View my profile</button>
            <button type="button" class="pf-btn is-primary" data-pf-done-close>Done</button>
        </div>
    </div>
</div>
<script>
    // Shown once, right after the save that completed the profile.
    (function () {
        var modal = document.getElementById('pfDone');
        if (!modal) return;
        var close = function () { modal.hidden = true; document.removeEventListener('keydown', onKey); };
        var onKey = function (event) { if (event.key === 'Escape') close(); };
        modal.addEventListener('click', function (event) { if (event.target.closest('[data-pf-done-close]')) close(); });
        document.addEventListener('keydown', onKey);
        modal.querySelector('.pf-done-card').focus();
    })();
</script>
<?php endif; ?>
<script>
    // Edit Profile: show the age as soon as a birth date is picked, and stop a
    // school's ending year from being earlier than its starting year.
    (function () {
        var birth = document.getElementById('pf_birth_date');
        var ageHint = document.getElementById('pfAge');
        if (birth && ageHint) {
            birth.addEventListener('input', function () {
                var parts = birth.value.split('-');
                if (parts.length !== 3) { ageHint.textContent = 'Your age is worked out from this.'; return; }
                var today = new Date();
                var age = today.getFullYear() - parts[0];
                var beforeBirthday = (today.getMonth() + 1) < parts[1] ||
                    ((today.getMonth() + 1) === +parts[1] && today.getDate() < parts[2]);
                if (beforeBirthday) age--;
                ageHint.textContent = (age >= 0 && age < 130) ? 'Age: ' + age + ' years old' : 'Please check this date.';
            });
        }
        document.querySelectorAll('.pf-edu').forEach(function (row) {
            var from = row.querySelector('.pf-year-from');
            var to = row.querySelector('.pf-year-to');
            function check() {
                var wrong = from.value && to.value && +from.value > +to.value;
                to.setCustomValidity(wrong ? 'The ending year cannot be before the starting year.' : '');
            }
            from.addEventListener('input', check);
            to.addEventListener('input', check);
        });
    })();
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
