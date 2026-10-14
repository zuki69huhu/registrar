<?php
session_start();
require_once 'includes/db.php';

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
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course, section FROM users WHERE id = ?");
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
// Fetch Real Notifications from database
$notif_stmt = $mysqli->prepare("SELECT id, message, created_at, type, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$real_notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

$unread_count = 0;
foreach ($real_notifications as $n) {
    if (empty($n['is_read'])) $unread_count++;
}

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

$user_student_id = $user_data['student_id'] ?? '';
$first_name = $user_data['first_name'] ?? 'S';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="assets/js/theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="assets/css/theme_mode.css?v=9">
    <title>Notifications - Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #1a365d; --bg-color: #f1f5f9; --sidebar-bg: #ffffff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: #0f172a; display: flex; min-height: 100vh; }

        .dashboard {
            display: flex;
            width: 100%;
            min-height: 100vh;
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
        .main-content { flex: 1; margin-left: 280px; padding: 32px 40px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .page-title { font-size: 24px; font-weight: 700; color: #0f172a; }
        .user-avatar { width: 44px; height: 44px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #1e293b; }

        /* Notifications Card */
        .notif-card { background: white; border-radius: 20px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .notif-item {
            padding: 16px; border-radius: 12px; margin-bottom: 8px; border: 1px solid #f1f5f9;
            display: flex; align-items: flex-start; gap: 14px; transition: all 0.2s; cursor: pointer;
            -webkit-tap-highlight-color: rgba(0,0,0,0.05); user-select: none;
        }
        .notif-item:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }
        .notif-item:active { background: #f1f5f9; }
        .notif-item:last-child { margin-bottom: 0; }
        .notif-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: 2px; }
        .notif-content { flex: 1; min-width: 0; }
        .notif-message { font-size: 15px; line-height: 1.5; color: #1e293b; overflow-wrap: anywhere; }
        .notif-time { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .notif-item.unread-highlight {
            background: rgba(59, 130, 246, 0.08); border-left: 4px solid var(--primary-color);
            border-color: rgba(59, 130, 246, 0.2) rgba(59, 130, 246, 0.2) rgba(59, 130, 246, 0.2) var(--primary-color);
        }
        .notif-item.unread-highlight .notif-message { font-weight: 600; }
        .mark-read-btn { display: inline-block; background: #e2e8f0; color: #475569; padding: 8px 16px; border-radius: 10px; border: none; cursor: pointer; font-weight: 500; font-size: 14px; transition: background 0.2s; text-decoration: none; }
        .mark-read-btn:hover { background: #cbd5e1; }
        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 7px; border-radius: 20px; }

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
            overflow-wrap: anywhere;
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

        .mobile-toggle {
            display: none; position: fixed; top: 15px; left: 15px; z-index: 1100;
            background: var(--primary-color); color: white; border: none; padding: 0;
            border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            width: 45px; height: 45px; align-items: center; justify-content: center;
        }
        .mobile-toggle i { font-size: 20px; }

        @media (max-width: 1024px) {
            .dashboard { flex-direction: column; width: 100%; }
            .sidebar {
                position: fixed;
                left: 0; top: 0; height: 100%;
                z-index: 1050;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active { transform: translateX(0) !important; }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .main-content { margin-left: 0; padding: 20px 16px; width: 100%; }
            .mobile-toggle { display: flex !important; }
            .top-bar {
                padding-left: 55px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .mobile-topbar-logo { display: none !important; }
            .notif-card { padding: 16px; border-radius: 14px; }
        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
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
<div class="dashboard">

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
            <a href="student_calendar.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Calendar</span>
            </a>
            <a href="submissions.php" class="nav-item">
                <i class="fas fa-paper-plane"></i>
                <span>Submissions</span>
            </a>
            <a href="student_notifications.php" class="nav-item active">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($unread_count > 0): ?><span class="nav-badge"><?= $unread_count ?></span><?php endif; ?>
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
            <div class="top-bar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="assets/img/logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1 class="page-title">Notifications</h1>
                    <p>Stay updated with important information and updates.</p>
                </div>
            </div>
        </div>

        <div class="notif-card">
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <?php if ($unread_count > 0): ?>
                <a href="mark_notification_read.php?id=all" class="mark-read-btn">Mark all as read</a>
                <?php endif; ?>
            </div>
            <?php if (empty($real_notifications)): ?>
                <p style="text-align: center; color: #94a3b8; padding: 20px 0;">No new notifications.</p>
            <?php else: ?>
                <?php foreach ($real_notifications as $notif): 
                    $is_unread = empty($notif['is_read']);
                    $unread_class = $is_unread ? 'unread-highlight' : '';
                    $notif_id = (int)$notif['id'];
                    $notif_time = date('M d, Y h:i A', strtotime($notif['created_at']));
                    
                    $msg_lower = strtolower($notif['message']);
                    $action_url = '';
                    $action_text = '';
                    if (strpos($msg_lower, 'reject') !== false || strpos($msg_lower, 'missing') !== false || strpos($msg_lower, 'document') !== false || strpos($msg_lower, 'form') !== false) {
                        $action_url = 'my_document.php';
                        $action_text = 'Go to My Documents';
                    } elseif (strpos($msg_lower, 'exam') !== false || strpos($msg_lower, 'schedule') !== false || strpos($msg_lower, 'sched') !== false || strpos($msg_lower, 'calendar') !== false) {
                        $action_url = 'student_calendar.php';
                        $action_text = 'Go to Calendar';
                    }
                ?>
                    <div class="notif-item <?= $unread_class ?>" 
                         id="notif-row-<?= $notif_id ?>"
                         data-id="<?= $notif_id ?>"
                         data-message="<?= htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8') ?>"
                         data-time="<?= htmlspecialchars($notif_time, ENT_QUOTES, 'UTF-8') ?>"
                         data-action-url="<?= htmlspecialchars($action_url, ENT_QUOTES, 'UTF-8') ?>"
                         data-action-text="<?= htmlspecialchars($action_text, ENT_QUOTES, 'UTF-8') ?>"
                         data-unread="<?= $is_unread ? '1' : '0' ?>"
                         onclick="openNotifModal(this)"
                         onkeydown="if(event.key==='Enter'||event.key===' '){openNotifModal(this);event.preventDefault();}"
                         role="button"
                         tabindex="0"
                         title="Click to view notification details">
                        <div class="notif-icon" style="color: var(--primary-color);"><i class="fas fa-info-circle"></i></div>
                        <div class="notif-content">
                            <div class="notif-message">
                                <?= htmlspecialchars($notif['message']) ?>
                            </div>
                            <div class="notif-time"><?= $notif_time ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
