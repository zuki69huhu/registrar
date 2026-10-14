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

// Fetch user details from the database
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, student_id, profile_picture, year_level, course, section FROM users WHERE id = ?");
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
$initials = strtoupper(substr($user_data['first_name'] ?? 'S', 0, 1) . substr($user_data['last_name'] ?? 'T', 0, 1)); // S for Student, T for Test

$user_email = $user_data['email'] ?? ''; // Define user_email
$user_profile_picture = $user_data['profile_picture'] ?? null;
$user_year_level = $user_data['year_level'] ?? '';
$user_course = $user_data['course'] ?? '';
$user_section = $user_data['section'] ?? '';

$display_year_section = '';
if (!empty($user_year_level)) {
    $year_num = substr($user_year_level, 0, 1);
    $display_year_section = $year_num . $user_section;
}

$user_student_id = $user_data['student_id'] ?? '';
$first_name = $user_data['first_name'] ?? 'S';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
    <title>Academic Calendar - Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FullCalendar & Google Calendar Plugin -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/@fullcalendar/google-calendar@6.1.10/index.global.min.js'></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #1a365d; --bg-color: #f1f5f9; --sidebar-bg: #ffffff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: #0f172a; display: flex; min-height: 100vh; width: 100%; }

        /* Dashboard Layout */
        .dashboard {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar */
        .sidebar { width: 280px; background: var(--sidebar-bg); border-right: 1px solid #e2e8f0; padding: 0; display: flex; flex-direction: column; position: fixed; height: 100%; top: 0; bottom: 0; overflow-y: auto; }
        .sidebar-header { padding: 24px 20px 0 20px; }
        .logo-area { margin-bottom: 32px; display: flex; align-items: center; gap: 8px; }
        .logo-icon { background: transparent; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .logo-text { font-size: 20px; font-weight: 700; color: #1e293b; }
        .student-info { background: #f8fafc; border-radius: 16px; padding: 16px; margin-bottom: 24px; border: 1px solid #e2e8f0; }
        .student-name { font-weight: 700; font-size: 16px; color: #0f172a; }
        .student-id { font-size: 13px; color: #64748b; margin-top: 4px; }
        .nav-menu { padding: 0 20px 16px 20px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 12px; color: #475569; font-weight: 500; margin-bottom: 4px; text-decoration: none; transition: all 0.2s; cursor: pointer; }
        .nav-item i { width: 24px; font-size: 18px; text-align: center; }
        .nav-item.active { background: var(--primary-color); color: white; }
        .nav-item.active i { color: white; }
        .nav-item:not(.active):hover { background: #f1f5f9; color: #1e293b; }
        .sidebar-bottom { padding: 12px 20px; border-top: 1px solid #e2e8f0; margin-top: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; color: #dc2626; font-weight: 500; text-decoration: none; padding: 10px 14px; border-radius: 12px; transition: background 0.2s; }
        .logout-btn i { width: 24px; font-size: 18px; text-align: center; }
        .logout-btn:hover { background: #fef2f2; }

        /* Main Content */
        .main-content { flex: 1; margin-left: 280px; padding: 28px 32px; display: flex; flex-direction: column; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 24px; font-weight: 700; color: #0f172a; }
        .user-avatar { width: 44px; height: 44px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #1e293b; }

        /* Calendar Card */
        #calendar-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px; }
        #calendar { min-height: 700px; }
        
        /* FullCalendar Custom Styling */
        .fc { --fc-border-color: #e2e8f0; --fc-button-bg-color: var(--primary-color); --fc-button-border-color: transparent; }
        .fc .fc-toolbar-title { font-size: 1.2rem; font-weight: 700; color: #0f172a; }
        .fc .fc-button-primary:hover { opacity: 0.9; }
        .fc .fc-button-active { background-color: var(--primary-color) !important; }
        .fc-event { cursor: pointer; padding: 2px 5px; white-space: normal !important; overflow: hidden; }
        .fc-event-title { white-space: normal !important; word-wrap: break-word; font-size: 0.85rem; }
        /* In the month grid on a phone a day is ~45px wide: one clipped line reads
           better than a title broken into single syllables. */
        @media (max-width: 640px) {
            .fc-daygrid-event, .fc-daygrid-event .fc-event-title { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; font-size: 0.7rem; }
            .fc-daygrid-event { padding: 1px 3px; }
            .fc .fc-toolbar { flex-wrap: wrap; gap: 8px; justify-content: center; }
            .fc .fc-toolbar-title { font-size: 1.1rem; }
        }
        .fc .fc-col-header-cell-cushion, .fc .fc-daygrid-day-number { color: #0f172a; }

        .mobile-toggle {
            display: none; position: fixed; top: 15px; left: 15px; z-index: 1100;
            background: var(--primary-color); color: white; border: none; padding: 0;
            border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            width: 45px; height: 45px; align-items: center; justify-content: center;
        }
        .mobile-toggle i { font-size: 20px; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; transition: 0.3s; }
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar.active { transform: translateX(0) !important; }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .mobile-toggle { display: flex !important; }
            .top-bar { padding-left: 55px; flex-direction: column; align-items: flex-start; gap: 15px; }
        }
        @media (max-width: 500px) {
            .fc .fc-toolbar { flex-direction: column; gap: 10px; }
        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
    </style>
    <link rel="stylesheet" href="student_mobile.css?v=1">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="student_profile.css?v=9">
    <script src="student_profile.js?v=3" defer></script>
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
                <div class="student-id">ID: <?= htmlspecialchars($user_student_id ?: 'N/A') ?></div>
                <?php if (!empty($user_course) || !empty($display_year_section)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $display_year_section)) ?>
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
            <a href="student_calendar.php" class="nav-item active">
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
                <h1 class="page-title">Academic Calendar</h1>
                <p style="font-size: 0.8rem; color: #64748b;">View important dates and events.</p>
            </div>
        </div>

        <div id="calendar-card">
            <div id="calendar"></div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: window.innerWidth < 640 ? 'listMonth' : 'dayGridMonth', // a phone starts on the readable list
            // 1. Enter your Google API Key here:
            googleCalendarApiKey: 'YOUR_API_KEY_HERE', // Replace with actual API key

            eventSources: [
                {
                    // 2. Enter your Calendar ID here:
                    googleCalendarId: 'en.philippines#holiday@group.v.calendar.google.com', // Example: Philippine Holidays
                    className: 'holiday-event',
                    color: 'var(--primary-color)' // Use a color consistent with student dashboard theme
                },
                {
                    url: 'fetch_events_json.php' // Registrar events
                },
                {
                    // Awareness Months and Static Holidays
                    events: function(fetchInfo, successCallback, failureCallback) {
                        let events = [];
                        let years = [fetchInfo.start.getFullYear(), fetchInfo.end.getFullYear()];
                        years = [...new Set(years)]; // unique years in current view
                        
                        years.forEach(year => {
                            events.push(
                                // Awareness Months (1st day of the month)
                                { title: 'Zero Waste Month', start: year + '-01-01', allDay: true, color: '#10b981' },
                                { title: 'National Arts Month', start: year + '-02-01', allDay: true, color: '#f59e0b' },
                                { title: 'Women\'s Month', start: year + '-03-01', allDay: true, color: '#ec4899' },
                                { title: 'Earth Month', start: year + '-04-01', allDay: true, color: '#84cc16' },
                                { title: 'Heritage Month', start: year + '-05-01', allDay: true, color: '#b45309' },
                                { title: 'Pride Month', start: year + '-06-01', allDay: true, color: '#8b5cf6' },
                                { title: 'Nutrition Month', start: year + '-07-01', allDay: true, color: '#eab308' },
                                { title: 'Buwan ng Wika', start: year + '-08-01', allDay: true, color: '#ef4444' },
                                { title: 'Peace Month', start: year + '-09-01', allDay: true, color: '#3b82f6' },
                                { title: 'Mental Health Awareness Month', start: year + '-10-01', allDay: true, color: '#14b8a6' },
                                { title: 'Reading Month', start: year + '-11-01', allDay: true, color: '#f97316' },
                                { title: 'Human Rights Month', start: year + '-12-01', allDay: true, color: '#6366f1' },
                                
                                // Common PH Holidays & Observances (Static Dates)
                                { title: 'New Year\'s Day', start: year + '-01-01', allDay: true, color: '#ef4444' },
                                { title: 'Valentine\'s Day', start: year + '-02-14', allDay: true, color: '#ec4899' },
                                { title: 'EDSA Revolution Anniversary', start: year + '-02-25', allDay: true, color: '#eab308' },
                                { title: 'Araw ng Kagitingan', start: year + '-04-09', allDay: true, color: '#ef4444' },
                                { title: 'Labor Day', start: year + '-05-01', allDay: true, color: '#ef4444' },
                                { title: 'Independence Day', start: year + '-06-12', allDay: true, color: '#3b82f6' },
                                { title: 'Ninoy Aquino Day', start: year + '-08-21', allDay: true, color: '#eab308' },
                                { title: 'World Teachers\' Day', start: year + '-10-05', allDay: true, color: '#14b8a6' },
                                { title: 'All Saints\' Day', start: year + '-11-01', allDay: true, color: '#8b5cf6' },
                                { title: 'All Souls\' Day', start: year + '-11-02', allDay: true, color: '#8b5cf6' },
                                { title: 'Bonifacio Day', start: year + '-11-30', allDay: true, color: '#ef4444' },
                                { title: 'Feast of the Immaculate Conception', start: year + '-12-08', allDay: true, color: '#3b82f6' },
                                { title: 'Christmas Eve', start: year + '-12-24', allDay: true, color: '#10b981' },
                                { title: 'Christmas Day', start: year + '-12-25', allDay: true, color: '#ef4444' },
                                { title: 'Rizal Day', start: year + '-12-30', allDay: true, color: '#64748b' },
                                { title: 'New Year\'s Eve', start: year + '-12-31', allDay: true, color: '#f59e0b' }
                            );
                        });
                        successCallback(events);
                    }
                }
            ],
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listMonth'
            },
            eventClick: function(arg) {
                // Opens event details in a new tab if a URL exists
                if (arg.event.url) {
                    arg.jsEvent.preventDefault();
                    window.open(arg.event.url, '_blank');
                }
            },
            // Loading feedback
            loading: function(bool) {
                calendarEl.style.opacity = bool ? '0.5' : '1';
            }
        });
        calendar.render();

        // Load theme color
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
</script>
</body>
</html>
