<?php
session_start();
require_once 'db.php';

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

// Fetch user documents
$docs_stmt = $mysqli->prepare("SELECT d.id, d.file_name, d.file_path, d.status, d.uploaded_at, d.document_type, d.feedback, d.reviewed_at, r.first_name AS reviewer_first, r.last_name AS reviewer_last
                               FROM documents d
                               LEFT JOIN users r ON d.reviewed_by = r.id
                               WHERE d.user_id = ? ORDER BY d.uploaded_at DESC");
$docs_stmt->bind_param("i", $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docs_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=4" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=11">
    <title>My Documents - School Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #1a365d; --bg-color: #f1f5f9; --sidebar-bg: #ffffff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: #0f172a; display: flex; min-height: 100vh; }
        
        .sidebar { width: 280px; background: var(--sidebar-bg); border-right: 1px solid #e2e8f0; padding: 0; display: flex; flex-direction: column; position: fixed; height: 100%; top: 0; bottom: 0; transition: 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 24px 20px 0 20px; }
        .logo-area { margin-bottom: 32px; display: flex; align-items: center; gap: 8px; }
        .logo-icon { background: transparent; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .logo-text { font-size: 20px; font-weight: 700; color: #1e293b; }
        
        .nav-menu { padding: 0 20px 16px 20px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 12px; color: #475569; font-weight: 500; margin-bottom: 4px; text-decoration: none; transition: 0.2s; }
        .nav-item:hover { background: #f1f5f9; color: #1e293b; }
        .nav-item.active { background: var(--primary-color); color: white; }
        .nav-item i { width: 24px; font-size: 18px; text-align: center; }

        .main-content { flex: 1; margin-left: 280px; padding: 32px 40px; }
        .card { background: white; border-radius: 20px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .upload-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 24px; border: 2px dashed #cbd5e1; }
        .btn-request { background: var(--primary-color); color: white; padding: 10px 20px; border-radius: 10px; border: none; cursor: pointer; font-weight: 600; }
        
        .doc-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .doc-table th, .doc-table td { text-align: left; padding: 15px; border-bottom: 1px solid #f1f5f9; }
        .doc-table th { color: #64748b; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-pending { background: #fef9c3; color: #854d0e; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }

        /* The registrar's written reason, shown to the student */
        .review-meta { font-size: 11.5px; color: #94a3b8; margin-top: 6px; }
        .review-note {
            margin-top: 6px; padding: 8px 10px; max-width: 280px;
            background: #fef2f2; border-left: 3px solid #dc2626; border-radius: 8px;
            font-size: 12.5px; line-height: 1.45; color: #7f1d1d; overflow-wrap: anywhere;
        }
        @media (max-width: 768px) { .review-note { max-width: 100%; } }

        .mobile-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1100; background: var(--primary-color); color: white; border: none; padding: 0; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3); width: 45px; height: 45px; align-items: center; justify-content: center; }
        .mobile-toggle i { font-size: 20px; }

        .sidebar-bottom { padding: 12px 20px; border-top: 1px solid #e2e8f0; margin-top: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; color: #dc2626; font-weight: 500; text-decoration: none; padding: 10px 14px; border-radius: 12px; transition: background 0.2s; }
        .logout-btn i { width: 24px; font-size: 18px; text-align: center; }
        .logout-btn:hover { background: #fef2f2; }

        /* Mobile Responsiveness */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0) !important; }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { display: flex !important; }
            .doc-table thead { display: none; }
            .doc-table tr { display: block; margin-bottom: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; }
            .doc-table td { display: flex; justify-content: space-between; align-items: center; border: none; padding: 8px 10px; }
            .doc-table td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 12px; }
            .doc-table td[data-label="Action"] { flex-wrap: wrap; gap: 8px; }
            /* The reviewer line and the registrar's note go under the badge at full
               width; beside it they were squeezed into a column a few letters wide. */
            .doc-table td[data-label="Status"] { flex-wrap: wrap; row-gap: 8px; }
            .doc-table td[data-label="Status"] .review-meta,
            .doc-table td[data-label="Status"] .review-note { flex: 1 1 100%; max-width: 100%; margin: 0; text-align: left; }
            .doc-table td[data-label="Action"] .action-container { flex: 1; justify-content: flex-end; }
        }
    </style>
    <style>
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

        /* Beautified Action Buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            font-family: inherit;
        }
        @media (max-width: 480px) {
            .action-container .btn-action { flex: 1; }
        }

        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .btn-view:hover {
            background: #3b82f6;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
            transform: translateY(-2px);
        }

        .btn-download {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .btn-download:hover {
            background: #10b981;
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
            transform: translateY(-2px);
        }
        
        .action-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }
    </style>
    <link rel="stylesheet" href="doc_viewer.css?v=1">
    <link rel="stylesheet" href="student_mobile.css?v=1">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="student_profile.css?v=15">
    <script src="student_profile.js?v=5" defer></script>
</head>
<body class="student-shell">
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
                <?php if (!empty($user_course) || !empty($display_year_section)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $display_year_section)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-menu">
            <a href="student_dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> <span>Overview</span></a>
            <a href="my_document.php" class="nav-item active"><i class="fas fa-file-alt"></i> <span>My Documents</span></a>
            <a href="student_calendar.php" class="nav-item"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a>
            <a href="submissions.php" class="nav-item"><i class="fas fa-paper-plane"></i> <span>Submissions</span></a>
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
            <a href="student_notifications.php" class="nav-item"><i class="fas fa-bell"></i> <span>Notifications</span> <?php if ($unread_count > 0): ?><span class="nav-badge" style="margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 7px; border-radius: 20px;"><?= $unread_count ?></span><?php endif; ?></a>
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
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </div>
    </div>

    <div class="main-content">
        <h1 style="margin-bottom: 24px;">My Documents</h1>
        
        <?php if (isset($_SESSION['flash'])): ?>
            <div style="padding: 15px; background: #dcfce7; color: #15803d; border-radius: 10px; margin-bottom: 20px;">
                <?= $_SESSION['flash']; unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div style="overflow-x: auto;">
                <table class="doc-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th>Filename</th>
                            <th>Upload Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                            <tr><td colspan="5" style="text-align:center; color: #94a3b8;">No documents found.</td></tr>
                        <?php else: foreach ($documents as $doc): ?>
                            <tr>
                                <td data-label="Document Type" style="font-weight: 600; color: var(--primary-color);"><?= htmlspecialchars($doc['document_type'] ?? 'General') ?></td>
                                <td data-label="Filename"><?= htmlspecialchars($doc['file_name']) ?></td>
                                <td data-label="Date"><?= date('M d, Y', strtotime($doc['uploaded_at'])) ?></td>
                                <?php
                                    $doc_status = strtolower($doc['status']);
                                    $doc_words = [
                                        'pending'  => 'Waiting for registrar review',
                                        'approved' => 'Approved by registrar',
                                        'rejected' => 'Not approved',
                                    ];
                                    $doc_note = trim($doc['feedback'] ?? '');
                                    $doc_reviewer = trim(($doc['reviewer_first'] ?? '') . ' ' . ($doc['reviewer_last'] ?? ''));
                                ?>
                                <td data-label="Status">
                                    <span class="status-badge status-<?= $doc_status ?>">
                                        <?= htmlspecialchars($doc_words[$doc_status] ?? ucfirst($doc_status)) ?>
                                    </span>
                                    <?php if ($doc_reviewer !== '' || !empty($doc['reviewed_at'])): ?>
                                        <div class="review-meta">
                                            Reviewed<?= $doc_reviewer !== '' ? ' by ' . htmlspecialchars($doc_reviewer) : '' ?><?= !empty($doc['reviewed_at']) ? ' &middot; ' . date('M d, Y', strtotime($doc['reviewed_at'])) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($doc_status === 'rejected' && $doc_note !== ''): ?>
                                        <div class="review-note"><strong>Registrar note:</strong> <?= htmlspecialchars($doc_note) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <div class="action-container">
                                        <button type="button" onclick="openPreview(this.dataset.file, this.dataset.name)" data-file="<?= htmlspecialchars($doc['file_path']) ?>" data-name="<?= htmlspecialchars($doc['document_type'] ?: $doc['file_name']) ?>" class="btn-action btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="btn-action btn-download">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
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
</script>
<script src="doc_viewer.js?v=1"></script>
</body>
</html>
