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

// Fetch pending documents count for notification badge
$pending_count = 0;
$pending_documents_list = [];
$res_pending_count = $mysqli->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'");
if ($res_pending_count) {
    $pending_count = $res_pending_count->fetch_assoc()['count'];
}

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

$all_docs_query = "SELECT d.id, d.status, d.file_name, d.uploaded_at, u.first_name, u.middle_name, u.last_name, u.student_id, u.course
                   FROM documents d JOIN users u ON d.user_id = u.id ";
if (!empty($user_inst)) {
    $stmt_all = $mysqli->prepare($all_docs_query . "WHERE u.institution = ? ORDER BY d.uploaded_at DESC LIMIT 50");
    $stmt_all->bind_param("s", $user_inst);
    $stmt_all->execute();
    $res_all = $stmt_all->get_result();
} else {
    $res_all = $mysqli->query($all_docs_query . "ORDER BY d.uploaded_at DESC LIMIT 50");
}
if ($res_all) {
    while($row = $res_all->fetch_assoc()) {
        $pending_documents_list[] = $row;
    }
}
function getCourseAbbr($course) {
    if (empty($course)) return '';
    $map = [
        'BS Accountancy' => 'BSA',
        'BS Business administration' => 'BSBA',
        'BS Computer engineering' => 'BSCpE',
        'BS Criminology' => 'BS Crim',
        'BS Custom Administration' => 'BSCA',
        'BS Hospitality management' => 'BSHM',
        'BS Information Technology' => 'BSIT',
        'BS Physical education' => 'BPEd',
        'BS Psychology' => 'BS Psych',
        'BS Secondary education' => 'BSEd',
        'BS Social work' => 'BSSW',
        'BS Tourism management' => 'BSTM',
        'BS Medical laboratory science' => 'BSMLS',
        'BS Midwifery' => 'BSM',
        'BS Nursing' => 'BSN',
        'BS Pharmacy' => 'BSPh',
        'BS Physical therapy' => 'BSPT',
        'BS Radiologic technology' => 'BSRT',
        'Dental technology' => 'DT'
    ];
    return $map[$course] ?? $course;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="assets/js/theme_mode.js?v=3" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="assets/css/theme_mode.css?v=9">
    <title>Notifications - Registrar Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --sidebar-bg: #0f172a; --main-bg: #0f172a; --card-bg: #1e293b;
            --accent-gold: #c9a227; --accent-green: #10b981; --accent-orange: #f59e0b;
            --accent-red: #ef4444; --accent-purple: #8b5cf6; --accent-blue: #3b82f6;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --text-muted: #64748b;
            --border: #1e293b; --sidebar-width: 240px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--main-bg);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg, #0f172a);
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .logo-box { width: 48px; height: 48px; background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .sidebar-brand { font-size: 1.15rem; font-weight: 700; }
        .sidebar-nav { flex: 1; padding: 20px 12px; }
        .nav-section-label { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; padding: 0 8px; margin: 16px 0 8px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: var(--text-secondary); text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.06); color: var(--text-primary); }
        .nav-link.active { background: rgba(201,162,39,0.15); color: var(--accent-gold); border-left: 3px solid var(--accent-gold); padding-left: 9px; }
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 7px; border-radius: 20px; }
        .sidebar-bottom { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.05); }
        .logout-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #ef4444; text-decoration: none; border-radius: 8px; }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }

        /* MAIN */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .user-avatar { width: 36px; height: 36px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }

        /* CONTENT */
        .content { padding: 28px 32px; overflow-y: auto; }
        .notif-card { background: var(--card-bg); border-radius: 14px; padding: 24px; border: 1px solid rgba(255,255,255,0.04); }
        .notif-item { padding: 16px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; gap: 16px; align-items: center; transition: background 0.2s; }
        .notif-item.unread-highlight { background: rgba(59, 130, 246, 0.15); border-left: 4px solid var(--accent-blue); padding-left: 12px; }
        .notif-item:hover { background: rgba(255,255,255,0.05); }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .notif-icon.blue { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .notif-icon.green { background: rgba(16,185,129,0.15); color: #10b981; }
        .notif-content { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .notif-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 4px; }
        .notif-time { font-size: 0.75rem; color: var(--text-muted); }

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

        #calendar-container {
            background: var(--card-bg);
            padding: 24px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.04);
        }
        .fc .fc-toolbar-title, .fc .fc-col-header-cell-cushion, .fc .fc-daygrid-day-number {
            color: var(--text-primary);
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3f55; border-radius: 3px; }

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
            .content { padding: 20px; }
            .mobile-topbar-logo { display: block !important; }
        }
        .sidebar-nav { overflow-y: auto; min-height: 0; }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
        /* Student number ahead of the name in each upload line */
        .notif-student-id {
            display: inline-block;
            margin-right: 8px;
            padding: 1px 7px;
            border-radius: 6px;
            background: rgba(148,163,184,0.15);
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
    </style>
    <link rel="stylesheet" href="assets/css/registrar_mobile.css?v=2">
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
            <div class="logo-box">
                <img src="assets/img/logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
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
            <a href="javascript:void(0)" class="nav-link active" onclick="showSection('notifications', this)"><i class="fas fa-bell"></i> Notifications <?= $pending_count > 0 ? '<span class="nav-badge">'.$pending_count.'</span>' : '' ?></a>
            <a href="archive.php" class="nav-link"><i class="fas fa-archive"></i> Archived Students</a>
            <a href="typesofdocuments.php" class="nav-link"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
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
                    <h1>Notifications</h1>
                    <p>Stay updated with system activities</p>
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

        <div class="content">
            <!-- NOTIFICATIONS SECTION -->
            <div id="notifications-section" class="content-section active">
            <div class="notif-card">
                <?php if (empty($pending_documents_list)): ?>
                    <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 20px 0;">
                        No new notifications.
                    </p>
                <?php else: ?>
                    <?php foreach ($pending_documents_list as $doc): 
                        $is_pending = ($doc['status'] === 'pending');
                        $unread_class = $is_pending ? 'unread-highlight' : '';
                    ?>
                        <a href="submissions.php" style="text-decoration: none; color: inherit; display: block;" class="notif-item <?= $unread_class ?>">
                            <div class="notif-icon blue">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title">
                                    <span class="notif-student-id"><?= htmlspecialchars(formatStudentId($doc['student_id'] ?? '')) ?></span>
                                    <strong><?= htmlspecialchars(formatPersonNameRow($doc)) ?></strong><?= !empty($doc['course']) ? ' (' . htmlspecialchars(getCourseAbbr($doc['course'])) . ')' : '' ?>
                                    uploaded a document: <?= htmlspecialchars($doc['file_name']) ?>
                                </div>
                                <div class="notif-time"><?= date('M d, Y h:i A', strtotime($doc['uploaded_at'])) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </div><!-- /notifications-section -->

            <!-- CALENDAR SECTION -->
            <div id="calendar-section" class="content-section">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Events Calendar</h2>
                </div>
                <div id="calendar-container">
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            
            const targetSection = document.getElementById(sectionId + '-section');
            if (targetSection) targetSection.classList.add('active');
            element.classList.add('active');

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
</body>
</html>
