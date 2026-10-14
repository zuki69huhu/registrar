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
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email FROM users WHERE id = ?");
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

// Fetch all document types
$document_types = [];
$query = "SELECT id, name FROM document_types ORDER BY name ASC";
$res = $mysqli->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $document_types[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=3" data-key="registrar-theme" data-base="dark" data-clear="registrar-theme-color,registrar-bg-color,registrar-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
    <title>Document Types - Registrar Portal</title>
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
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .user-avatar { width: 36px; height: 36px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }
        
        /* CONTENT */
        .content { padding: 28px 32px; overflow-y: auto; }
        .card { background: var(--card-bg); border-radius: 14px; padding: 24px; border: 1px solid rgba(255,255,255,0.04); }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .card-header h2 { font-size: 1rem; font-weight: 600; color: var(--text-primary); }
        .add-button { background: var(--accent-gold); color: white; padding: 8px 15px; border-radius: 8px; border: none; cursor: pointer; font-weight: 500; transition: background 0.2s; }
        .add-button:hover { background: #b38f1f; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        thead th { font-size: 0.72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; padding: 0 0 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        tbody tr { border-bottom: 1px solid rgba(255,255,255,0.03); }
        tbody tr:last-child { border-bottom: none; }
        tbody td { padding: 14px 0; font-size: 0.85rem; color: var(--text-primary); vertical-align: middle; }

        .action-buttons { display: flex; gap: 8px; }
        .action-btn {
            padding: 6px 10px; border-radius: 6px; border: none; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
        }
        .btn-edit { background: var(--accent-blue); color: white; }
        .btn-delete { background: var(--accent-red); color: white; }
        .action-btn:hover { opacity: 0.8; }

        /* Flash Message */
        .flash-message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .flash-message.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .flash-message.error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .flash-message.success::before { content: "\f058"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .flash-message.error::before { content: "\f06a"; font-family: "Font Awesome 6 Free"; font-weight: 900; }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0; top: 0;
            width: 100%; height: 100%; /* Full width and height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Black w/ opacity */
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: var(--card-bg);
            margin: auto;
            padding: 30px;
            border: 1px solid var(--border);
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
        }
        .close-button {
            color: var(--text-secondary);
            position: absolute;
            top: 15px; right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover, .close-button:focus {
            color: var(--text-primary);
            text-decoration: none;
            cursor: pointer;
        }
        .modal-content h3 {
            color: var(--text-primary);
            margin-bottom: 20px;
            font-size: 1.2rem;
        }
        .modal-content label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        .modal-content input[type="text"],
        .modal-content textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #0a1628;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
        }
        .modal-content textarea {
            resize: vertical;
            min-height: 80px;
        }
        .modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .modal-buttons button {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        .modal-buttons .btn-save { background: var(--accent-green); color: white; }
        .modal-buttons .btn-save:hover { background: #0c9c6e; }
        .modal-buttons .btn-cancel { background: var(--text-muted); color: white; }
        .modal-buttons .btn-cancel:hover { background: #5a6b7d; }

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
            .mobile-topbar-logo { display: block !important; }
        }
        @media (max-width: 768px) {
            table thead { display: none; }
            table tr {
                display: block;
                margin-bottom: 15px;
                background: var(--card-bg);
                border: 1px solid rgba(255,255,255,0.04);
                border-radius: 10px;
                padding: 10px;
            }
            table td { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                border: none; padding: 8px 10px; 
            }
            table td::before { 
                content: attr(data-label); 
                font-weight: 700; color: var(--text-muted); font-size: 12px; 
                text-transform: uppercase;
            }
        }
        .sidebar-nav { overflow-y: auto; min-height: 0; }
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
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1>Document Types</h1>
                    <p style="font-size:0.8rem; color:var(--text-secondary)">Manage categories of documents for students</p>
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
                <div class="flash-message <?= strpos($_SESSION['flash'], 'successfully') !== false ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($_SESSION['flash']); ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>All Document Types</h2>
                    <button class="add-button" onclick="openModal()"><i class="fas fa-plus"></i> Add New Type</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($document_types)): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No document types defined yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($document_types as $type): ?>
                                <tr>
                                    <td data-label="Name"><?= htmlspecialchars($type['name']) ?></td>
                                    <td data-label="Actions">
                                        <div class="action-buttons">
                                            <button class="action-btn btn-edit" onclick="openModal(<?= $type['id'] ?>, '<?= htmlspecialchars(addslashes($type['name'])) ?>')"><i class="fas fa-edit"></i> Edit</button>
                                            <form action="process_document_type.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this document type?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $type['id'] ?>">
                                                <button type="submit" class="action-btn btn-delete"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Document Type Modal -->
    <div id="documentTypeModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">Add Document Type</h3>
            <form action="process_document_type.php" method="POST">
                <input type="hidden" name="id" id="typeId">
                <input type="hidden" name="action" id="formAction" value="add">
                
                <div class="form-group">
                    <label for="typeName">Document Type Name</label>
                    <input type="text" id="typeName" name="name" required>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Document Type</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id = '', name = '') {
            document.getElementById('modalTitle').innerText = id ? 'Edit Document Type' : 'Add Document Type';
            document.getElementById('typeId').value = id;
            document.getElementById('typeName').value = name;
            document.getElementById('formAction').value = id ? 'update' : 'add';
            document.getElementById('documentTypeModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('documentTypeModal').style.display = 'none';
            // Clear form fields when closing
            document.getElementById('typeId').value = '';
            document.getElementById('typeName').value = '';
            document.getElementById('formAction').value = 'add'; // Reset to add
        }

        // Close modal if user clicks outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('documentTypeModal');
            if (event.target == modal) {
                closeModal();
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
