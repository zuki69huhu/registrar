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
$res_pending = $mysqli->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'");
if ($res_pending) {
    $pending_count = $res_pending->fetch_assoc()['count'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=4" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=11">
    <title>Academic Calendar - Registrar Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FullCalendar & Google Calendar Plugin -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/@fullcalendar/google-calendar@6.1.10/index.global.min.js'></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --sidebar-bg: #0f172a; --main-bg: #0f172a; --card-bg: #1e293b;
            --accent-gold: #c9a227; --text-primary: #f1f5f9; --text-secondary: #94a3b8;
            --text-muted: #64748b; --sidebar-width: 240px;
        }
        body { font-family: 'Inter', sans-serif; background: var(--main-bg); color: var(--text-primary); display: flex; min-height: 100vh; }

        /* Sidebar & Navigation */
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
            overflow-y: auto;
            min-height: 0;
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
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
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
            padding: 20px 12px;
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
        .logout-link:hover { background: rgba(239,68,68,0.1); }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }

        /* Main Content */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .content { padding: 28px 32px; overflow-y: auto; }
        
        #calendar-card { background: var(--card-bg); padding: 24px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.04); }
        #calendar { min-height: 500px; }
        
        /* FullCalendar Custom Styling */
        .fc { --fc-border-color: rgba(255,255,255,0.05); --fc-button-bg-color: #2d3f55; --fc-button-border-color: transparent; }
        .fc .fc-toolbar-title { font-size: 1.2rem; font-weight: 700; }
        .fc .fc-button-primary:hover { background-color: var(--accent-gold); }
        .fc .fc-button-active { background-color: var(--accent-gold) !important; }
        .fc-event { cursor: pointer; padding: 2px 5px; white-space: normal !important; overflow: hidden; }
        .fc-event-title { white-space: normal !important; word-wrap: break-word; font-size: 0.85rem; }
        /* The list view: FullCalendar paints its day headings light grey, which
           hid the light text of this dark page. */
        .fc .fc-list-day-cushion, .fc .fc-list-table th { background: #0f172a; color: var(--text-primary, #f1f5f9); }
        .fc .fc-list-event:hover td { background: rgba(255,255,255,0.04); }
        .fc-theme-standard .fc-list, .fc-theme-standard .fc-list td, .fc-theme-standard .fc-list th { border-color: rgba(255,255,255,0.08); }
        .fc .fc-list-empty { background: transparent; color: var(--text-muted, #94a3b8); }
        /* In the month grid on a phone a day is ~45px wide: one clipped line reads
           better than a title broken into single syllables. */
        @media (max-width: 640px) {
            .fc-daygrid-event, .fc-daygrid-event .fc-event-title { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; font-size: 0.7rem; }
            .fc-daygrid-event { padding: 1px 3px; }
            .fc .fc-toolbar { flex-wrap: wrap; gap: 8px; justify-content: center; }
            .fc .fc-toolbar-title { font-size: 1.1rem; }
        }

        /* Mobile Menu Button */
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


        /* Modal Style for Adding Events */
        #eventModal {
            display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); align-items: center; justify-content: center;
        }
        .modal-content {
            background: var(--card-bg); padding: 30px; border-radius: 15px; width: 400px;
            border: 1px solid var(--accent-gold);
        }
        .modal-content input, .modal-content textarea {
            width: 100%; padding: 10px; margin: 10px 0; background: #0a1628; border: 1px solid #2d3f55; color: white; border-radius: 5px;
        }

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
            .topbar-right { justify-content: space-between; }
            .content { padding: 20px; }
            .main { margin-left: 0; }
            .mobile-topbar-logo { display: block !important; }
        }
        @media (max-width: 600px) {
            .modal-content { width: 95%; padding: 20px; }
            .fc .fc-toolbar { flex-direction: column; gap: 10px; }
        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
    </style>
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
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
            <a href="calendar.php" class="nav-link active"><i class="fas fa-calendar-alt"></i> Calendar</a>
            <?= submissionsNavLink($mysqli) ?>
            <a href="reject.php" class="nav-link"><i class="fas fa-times-circle"></i> Rejected Documents</a>
            <?= requestsNavLink($mysqli) ?>
            <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications <?= $pending_count > 0 ? '<span class="nav-badge">'.$pending_count.'</span>' : '' ?></a>
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

    <div class="main">
        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1>Academic Calendar</h1>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px">
                <div style="text-align:right">
                    <div style="font-size:0.85rem; font-weight:600"><?= htmlspecialchars($full_name) ?></div>
                    <div style="font-size:0.7rem; color:var(--text-muted)">Registrar</div>
                </div>
                <div style="width:36px; height:36px; background:#2d3f55; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700"><?= $initials ?></div>
            </div>
        </div>

        <div class="content">
            <?php if(isset($_SESSION['flash'])): ?>
                <div style="background: rgba(16,185,129,0.2); color: #10b981; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <?= $_SESSION['flash']; unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>

            <div id="calendar-card">
                <div id="calendar"></div>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div id="eventModal">
        <div class="modal-content">
            <h2 style="margin-bottom:15px;">Add Academic Event</h2>
            <form action="save_event.php" method="POST">
                <label>Event Title</label>
                <input type="text" name="title" required placeholder="e.g. Midterm Exams">
                <label>Start Date & Time</label>
                <input type="datetime-local" name="start_datetime" id="start_datetime" required>
                <label>End Date & Time</label>
                <input type="datetime-local" name="end_datetime" required>
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="submit" style="flex:1; padding:10px; background:var(--accent-gold); border:none; border-radius:5px; cursor:pointer; font-weight:bold;">Save & Notify Students</button>
                    <button type="button" onclick="closeModal()" style="padding:10px; background:#ef4444; border:none; border-radius:5px; color:white; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function closeModal() { document.getElementById('eventModal').style.display = 'none'; }

        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 640 ? 'listMonth' : 'dayGridMonth', // a phone starts on the readable list
                selectable: true,
                height: 550,
                eventSources: [
                    {
                        googleCalendarId: 'en.philippines#holiday@group.v.calendar.google.com', 
                        className: 'holiday-event',
                        color: 'var(--accent-gold)'
                    },
                    {
                        url: 'fetch_events_json.php'
                    },
                    {
                        // Awareness Months and Static Holidays
                        events: function(fetchInfo, successCallback, failureCallback) {
                            let events = [];
                            let years = [fetchInfo.start.getFullYear(), fetchInfo.end.getFullYear()];
                            years = [...new Set(years)];
                            
                            years.forEach(year => {
                                events.push(
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
                initialView: window.innerWidth < 640 ? 'listMonth' : 'dayGridMonth', // a phone starts on the readable list
                select: function(info) {
                    document.getElementById('eventModal').style.display = 'flex';
                    // Auto-fill date
                    document.getElementById('start_datetime').value = info.startStr + "T08:00";
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


