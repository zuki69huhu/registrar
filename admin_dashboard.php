<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/name_helper.php';
require_once 'includes/audit_log_helper.php';
require_once 'includes/programs.php';

// Access control: Only allow users with the 'systemadmin' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin' || $_SESSION['email'] !== 'Admin') {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if ($user_id === null && $_SESSION['role'] !== 'systemadmin') {
    header("Location: index.php?tab=login");
    exit;
}

if ($_SESSION['email'] === 'Admin') {
    $full_name = 'System Administrator';
    $initials = 'SA';
} else {
    // Fetch user details from the database
    $stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email FROM users WHERE id = ?");
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
}

// --- FETCH DASHBOARD STATISTICS ---
$stats = ['total_docs' => 0, 'approved' => 0, 'pending' => 0, 'active_students' => 0];

// Active Students (Verified accounts)
$res = $mysqli->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND is_verified = 2");
if ($res) $stats['active_students'] = $res->fetch_assoc()['count'];

// Document Submissions (Counts)
$res = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM documents");
if ($res) {
    $data = $res->fetch_assoc();
    $stats['total_docs'] = $data['total'] ?? 0;
    $stats['approved'] = $data['approved'] ?? 0;
    $stats['pending'] = $data['pending'] ?? 0;
}

// Recent Submissions List
$recent_submissions = [];
$sub_query = "SELECT d.*, u.first_name, u.last_name FROM documents d 
              JOIN users u ON d.user_id = u.id 
              ORDER BY d.uploaded_at DESC LIMIT 5";
$res = $mysqli->query($sub_query);
if ($res) while($row = $res->fetch_assoc()) $recent_submissions[] = $row;

// --- FETCH PENDING STUDENT REGISTRATIONS ---
$pending_students = [];
$student_query = "SELECT id, first_name, last_name, email, student_id, created_at FROM users WHERE role = 'student' AND is_verified = 1 ORDER BY created_at DESC LIMIT 5";
$res_students = $mysqli->query($student_query);
if ($res_students) while($row = $res_students->fetch_assoc()) $pending_students[] = $row;

// --- COURSE MANAGEMENT BACKEND ---
$message = "";
$messageType = "";

try {
    $mysqli->query("SELECT 1 FROM courses LIMIT 1");
} catch (Exception $e) {
    try { $mysqli->query("DROP TABLE IF EXISTS courses"); } catch (Exception $dropEx) {}
    $createTableQuery = "
    CREATE TABLE courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_name VARCHAR(255) NOT NULL,
        course_code VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $mysqli->query($createTableQuery);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_course') {
    $course_name = trim($_POST['course_name']);
    $course_code = trim($_POST['course_code']);

    if (!empty($course_name) && !empty($course_code)) {
        try {
            $stmt = $mysqli->prepare("INSERT INTO courses (course_name, course_code) VALUES (?, ?)");
            $stmt->bind_param("ss", $course_name, $course_code);
            if ($stmt->execute()) {
                logAudit($mysqli, $_SESSION['user_id'] ?? null, 'COURSE_ADDED', "Added course {$course_name} ({$course_code}).");
                $message = "Course added successfully!";
                $messageType = "success";
            } else {
                $message = "Failed to add course. It might already exist.";
                $messageType = "error";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please fill in all fields.";
        $messageType = "error";
    }
}

$courses = [];
try {
    $result = $mysqli->query("SELECT * FROM courses ORDER BY created_at DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
} catch (Exception $e) {
    $message = "Error fetching courses: " . $e->getMessage();
    $messageType = "error";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Dashboard - School Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --sidebar-bg: #0f172a;
            --main-bg: #0f172a;
            --card-bg: #1e293b;
            --accent-gold: #c9a227;
            --accent-green: #10b981;
            --accent-orange: #f59e0b;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
            --accent-blue: #3b82f6;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border: #1e293b;
            --sidebar-width: 240px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--main-bg);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

        /* ─── SIDEBAR ─── */
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

        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 18px;
        }

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
            padding: 16px 12px;
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

        .logout-link i {
            width: 24px;
            text-align: center;
            font-size: 18px;
        }

        .logout-link:hover {
            background: rgba(239,68,68,0.1);
        }

        /* ─── MAIN CONTENT ─── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ─── TOPBAR ─── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
            background: #0f172a;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            gap: 24px;
        }

        .topbar-left h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .topbar-left p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .search-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 9px 16px;
            min-width: 260px;
        }

        .search-bar i {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .search-bar input {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            width: 100%;
        }

        .search-bar input::placeholder {
            color: var(--text-muted);
        }

        .topbar-icon-btn {
            position: relative;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1e293b;
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: background 0.2s;
            border: none;
        }

        .topbar-icon-btn:hover {
            background: #2d3f55;
        }

        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s, box-shadow 0.2s, background 0.2s;
            font-size: 0.8rem;
        }
        .action-btn:hover { transform: scale(1.1); }
        .action-btn:active { transform: scale(0.95); }
        .btn-check { background: rgba(16,185,129,0.2); color: #10b981; }
        .btn-wrong { background: rgba(239,68,68,0.2); color: #ef4444; }
        .btn-view {
            width: auto;
            height: auto;
            padding: 6px 14px;
            border-radius: 8px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            text-decoration: none;
            font-weight: 600;
            gap: 6px;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-view:hover {
            transform: translateY(-2px) scale(1) !important;
            box-shadow: 0 6px 15px rgba(59, 130, 246, 0.4);
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
            color: white;
        }

        .topbar-icon-btn .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 14px;
            height: 14px;
            background: #ef4444;
            border-radius: 50%;
            font-size: 0.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: #2d3f55;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        .user-info .name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-info .role {
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        /* ─── CONTENT AREA ─── */
        .content {
            flex: 1;
            padding: 28px 32px;
            overflow-y: auto;
        }

        /* ─── STAT CARDS ─── */
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 22px 24px;
            position: relative;
            border: 1px solid rgba(255,255,255,0.04);
            text-decoration: none;
            display: block;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--accent-gold);
            box-shadow: 0 10px 20px -10px rgba(0, 0, 0, 0.5);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-icon.blue    { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .stat-icon.green   { background: rgba(16,185,129,0.15); color: #10b981; }
        .stat-icon.orange  { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .stat-icon.purple  { background: rgba(139,92,246,0.15); color: #8b5cf6; }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        /* ─── BOTTOM SECTION ─── */
        .bottom-section {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 20px;
        }

        /* ─── TABLE CARD ─── */
        .table-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .view-all {
            font-size: 0.8rem;
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 500;
        }

        .view-all:hover { text-decoration: underline; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 0 0 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }

        tbody tr:last-child { border-bottom: none; }

        tbody td {
            padding: 14px 0;
            font-size: 0.85rem;
            color: var(--text-primary);
            vertical-align: middle;
        }

        .doc-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doc-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .doc-icon.pdf  { background: rgba(239,68,68,0.15); color: #ef4444; }
        .doc-icon.docx { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .doc-icon.img  { background: rgba(245,158,11,0.15); color: #f59e0b; }

        .doc-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .doc-meta {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-badge.approved  { background: rgba(16,185,129,0.15); color: #10b981; }
        .status-badge.approved::before  { background: #10b981; }
        .status-badge.pending   { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .status-badge.pending::before   { background: #f59e0b; }
        .status-badge.rejected  { background: rgba(239,68,68,0.15); color: #ef4444; }
        .status-badge.rejected::before  { background: #ef4444; }
        .status-badge.in-review { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .status-badge.in-review::before { background: #3b82f6; }

        /* ─── ACTIVITY CARD ─── */
        .activity-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            flex-shrink: 0;
            color: white;
        }

        .av-sj { background: #10b981; }
        .av-mc { background: #3b82f6; }
        .av-er { background: #f59e0b; }
        .av-dk { background: #8b5cf6; }

        .activity-text {
            flex: 1;
        }

        .activity-text strong {
            font-weight: 600;
            color: var(--text-primary);
        }

        .activity-text span {
            color: var(--text-secondary);
            font-size: 0.83rem;
        }

        .activity-time {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Responsive Table for Students */
        @media (max-width: 768px) {
            .student-table thead, .table-card table thead { display: none; }
            .student-table tr, .table-card table tr {
                display: block;
                margin-bottom: 15px;
                background: var(--card-bg);
                border: 1px solid rgba(255,255,255,0.04);
                border-radius: 10px;
                padding: 10px;
            }
            .student-table td, .table-card table td { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                border: none; padding: 8px 10px; 
            }
            .student-table td::before, .table-card table td::before { 
                content: attr(data-label); 
                font-weight: 700; color: var(--text-muted); font-size: 12px; 
                text-transform: uppercase;
            }
        }

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

        .reject-modal, .broadcast-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        .reject-modal-content, .broadcast-modal-content {
            background: var(--card-bg, #1e293b);
            padding: 28px;
            border-radius: 14px;
            width: 90%;
            max-width: 450px;
            color: var(--text-primary, #f1f5f9);
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .reject-modal-content h3, .broadcast-modal-content h3 {
            margin-bottom: 12px;
        }
        .reject-modal-content textarea, .broadcast-modal-content textarea {
            width: 100%;
            height: 120px;
            padding: 12px;
            border-radius: 8px;
            border: 2px solid var(--accent-gold, #c9a227);
            background: rgba(0,0,0,0.2);
            color: white;
            resize: vertical;
            margin-bottom: 20px;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        .reject-modal-actions, .broadcast-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .reject-modal-actions button, .broadcast-modal-actions button {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-family: inherit;
        }
        .reject-modal-actions .btn-cancel, .broadcast-modal-actions .btn-cancel {
            background: var(--main-bg);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        .reject-modal-actions .btn-confirm, .broadcast-modal-actions .btn-confirm {
            background: #ef4444;
            color: white;
        }
        .broadcast-modal-actions .btn-confirm {
            background: var(--accent-gold);
            color: #000;
        }

        #calendar-container {
            background: var(--card-bg);
            padding: 24px;
             border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.04);
        }
        #calendar { min-height: 500px; }
        .fc .fc-toolbar-title, .fc .fc-col-header-cell-cushion, .fc .fc-daygrid-day-number {
            color: var(--text-primary);
        }

        /* ─── RESPONSIVE ADDITIONS ─── */
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
            .search-bar { min-width: unset; flex: 1; }
            .stat-cards { grid-template-columns: repeat(4, 1fr); gap: 10px; }
            .stat-card { padding: 12px 8px; border-radius: 10px; }
            .stat-card-header { margin-bottom: 8px; justify-content: center; }
            .stat-icon { width: 32px; height: 32px; font-size: 0.85rem; border-radius: 8px; }
            .stat-value { font-size: 1.1rem; text-align: center; margin-bottom: 4px; }
            .stat-label { font-size: 0.55rem; text-align: center; line-height: 1.2; word-wrap: break-word; }
            .bottom-section { grid-template-columns: 1fr; }
            .content { padding: 15px; }
            .mobile-topbar-logo { display: block !important; }
        }

        @media (max-width: 768px) {
            .stat-cards { gap: 6px; }
            .stat-card { padding: 10px 5px; }
            .stat-value { font-size: 1rem; }
            .stat-label { font-size: 0.5rem; }
        }

        /* Ensure the iframe fills its container */
        #view-document-section .table-card {
            height: 75vh;
            display: flex;
            flex-direction: column;
            padding: 12px; 
            overflow: hidden;
            width: 100%;
            border-radius: 14px;
            background: transparent;
            transition: all 0.3s ease;
        }

        #doc-viewer {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px; 
            background: transparent;
        }
        /* Adjust content area to allow full height for viewer */
        .content {
            padding: 28px 32px;
        }

        /* Back Button Style */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 10px 16px;
            background: var(--card-bg);
            color: var(--text-secondary);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            width: fit-content;
        }
        .back-button:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border-color: var(--accent-gold);
        }

        /* ─── SCROLLBAR ─── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2d3f55; border-radius: 3px; }
        /* Side Preview Panel Styles */
        .preview-panel {
            position: fixed;
            right: -450px;
            top: 0;
            width: 450px;
            height: 100vh;
            background: var(--card-bg, #1e293b);
            box-shadow: -5px 0 25px rgba(0,0,0,0.3);
            z-index: 3000;
            transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            border-left: 1px solid var(--border-color);
        }
        .preview-panel.active { right: 0; }
        .preview-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg, #1e293b);
            color: var(--text-primary);
        }
        .preview-body {
            flex: 1;
            padding: 0;
            overflow: hidden;
            background: var(--main-bg, #0f172a);
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        #preview-frame { width: 100%; height: 100%; border: none; background: white; }
        #preview-image { position: absolute; top: 10%; left: 10%; width: 80%; height: 80%; object-fit: contain; margin: auto; }
        .btn-close-preview {
            background: rgba(255,255,255,0.1);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-close-preview:hover { background: #fee2e2; color: #dc2626; }
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
                padding: 15px !important;
                padding-left: 65px !important;
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 15px !important;
            }
            .topbar-right { justify-content: space-between !important; }
            .content { padding: 20px; }
            .main-content { margin-left: 0; }
            .grid-cards { grid-template-columns: 1fr !important; }
            .course-management-grid { grid-template-columns: 1fr !important; }
            .main-content { overflow-y: auto !important; height: auto !important; }
        }
        @media (max-width: 768px) {
            .preview-panel {
                width: 100%;
                right: -100%;
                border-left: none;
            }
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 15px; background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px;}
            td { border: none; border-bottom: 1px solid rgba(255,255,255,0.05); position: relative; padding: 10px; padding-left: 50% !important; text-align: right; }
            td:last-child { border-bottom: none; }
            td:before { position: absolute; top: 50%; left: 10px; transform: translateY(-50%); width: 45%; padding-right: 10px; white-space: nowrap; font-weight: 600; content: attr(data-label); text-align: left; color: var(--text-muted); }
            .action-buttons { justify-content: flex-end; }
        }
        /* ─── AUDIT TRAIL ─── */
        .audit-header { flex-wrap: wrap; gap: 12px; margin-bottom: 18px; align-items: flex-start; }
        .audit-subtitle { font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; }
        .audit-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .audit-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); background: #1e293b; color: var(--text-primary); font-size: 0.8rem; font-weight: 600; font-family: inherit; cursor: pointer; transition: background 0.2s, border-color 0.2s; white-space: nowrap; }
        .audit-btn:hover:not(:disabled) { background: #273449; border-color: rgba(255,255,255,0.16); }
        .audit-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .audit-btn-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
        .audit-btn-primary:hover:not(:disabled) { background: #2563eb; border-color: #2563eb; }
        .audit-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; background: var(--card-bg); border: 1px solid rgba(255,255,255,0.04); border-radius: 14px; padding: 16px; margin-bottom: 14px; }
        .audit-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .audit-field > span { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .audit-field select, .audit-field input { width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); background: #0f172a; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; outline: none; color-scheme: dark; }
        .audit-field select:focus, .audit-field input:focus { border-color: #3b82f6; }
        .audit-field-wide { grid-column: span 2; }
        .audit-clear { height: 38px; }
        .audit-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; scroll-margin-top: 16px; }
        .audit-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 0.75rem; font-family: inherit; border: 1px solid rgba(255,255,255,0.08); background: transparent; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; }
        .audit-chip b { color: var(--text-primary); font-weight: 700; }
        .audit-chip:hover { border-color: rgba(255,255,255,0.2); }
        .audit-chip.active { background: rgba(59,130,246,0.15); border-color: #3b82f6; color: #93c5fd; }
        .audit-table-card { overflow-x: auto; }
        .audit-table tbody { transition: opacity 0.2s; }
        .audit-table.audit-loading tbody { opacity: 0.5; }
        .audit-cell { display: flex; flex-direction: column; gap: 2px; }
        .audit-cell strong { font-weight: 600; color: var(--text-primary); font-size: 0.85rem; }
        .audit-muted { color: var(--text-muted); font-size: 0.75rem; }
        .audit-person { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .audit-role { font-size: 0.65rem; font-weight: 600; padding: 1px 7px; border-radius: 999px; text-transform: capitalize; background: rgba(148,163,184,0.15); color: #cbd5e1; }
        .audit-role-student { background: rgba(16,185,129,0.15); color: #34d399; }
        .audit-role-registrar { background: rgba(59,130,246,0.15); color: #93c5fd; }
        .audit-role-systemadmin { background: rgba(201,162,39,0.18); color: #c9a227; }
        .audit-affected { margin-top: 6px; font-size: 0.78rem; color: var(--text-secondary); }
        .audit-affected > span { color: var(--text-muted); }
        .audit-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .audit-success { background: rgba(16,185,129,0.15); color: #10b981; }
        .audit-danger { background: rgba(239,68,68,0.15); color: #f87171; }
        .audit-warning { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .audit-info { background: rgba(59,130,246,0.15); color: #60a5fa; }
        .audit-purple { background: rgba(139,92,246,0.15); color: #a78bfa; }
        .audit-neutral { background: rgba(100,116,139,0.2); color: #94a3b8; }
        .audit-desc { color: var(--text-secondary); font-size: 0.83rem; min-width: 220px; line-height: 1.45; }
        .audit-state { text-align: center; padding: 28px !important; color: var(--text-muted); }
        .audit-state .audit-btn { margin-left: 8px; }
        .audit-pagination { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding-top: 14px; font-size: 0.8rem; color: var(--text-secondary); }
        .audit-pages { display: flex; align-items: center; gap: 10px; }
        @media (max-width: 768px) {
            .audit-filters { grid-template-columns: 1fr 1fr; padding: 12px; }
            .audit-field-wide, .audit-clear { grid-column: 1 / -1; }
            .audit-header-actions { width: 100%; }
            .audit-header-actions .audit-btn { flex: 1; }
            .audit-pagination { justify-content: center; text-align: center; }
            .audit-table-card { overflow-x: visible; }
            .audit-desc { min-width: 0; }
            .table-card table.audit-table tr { padding: 12px; }
            .table-card table.audit-table td { display: block; padding: 7px 2px !important; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
            .table-card table.audit-table td:last-child { border-bottom: none; }
            .table-card table.audit-table td::before { position: static; transform: none; display: block; width: auto; margin-bottom: 3px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; white-space: normal; }
            .table-card table.audit-table td.audit-state { text-align: center; }
            .table-card table.audit-table td.audit-state::before { content: none; }
        }
        @media (max-width: 420px) {
            .audit-filters { grid-template-columns: 1fr; }
        }
        /* ─── REPORTS & ANALYTICS ─── */
        .report-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 14px;
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.04);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }
        .report-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 1 1 220px; }
        .report-field label {
            font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em;
            text-transform: uppercase; color: var(--text-muted);
        }
        .report-select {
            width: 100%; padding: 10px 12px; border-radius: 10px;
            background: rgba(255,255,255,0.05); color: var(--text-primary);
            border: 1px solid rgba(255,255,255,0.1); outline: none;
            font-family: inherit; font-size: 0.85rem; cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .report-select:hover, .report-select:focus { border-color: var(--accent-gold); background: rgba(255,255,255,0.08); }
        .report-select option { color: #0f172a; background: #ffffff; }
        .report-refresh {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px; cursor: pointer;
            background: rgba(201,162,39,0.12); color: var(--accent-gold);
            border: 1px solid rgba(201,162,39,0.35);
            font-family: inherit; font-size: 0.82rem; font-weight: 600;
            transition: all 0.2s ease; white-space: nowrap;
        }
        .report-refresh:hover { background: rgba(201,162,39,0.22); }
        .report-stamp { font-size: 0.72rem; color: var(--text-muted); margin-left: auto; align-self: center; }

        .report-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .report-kpi {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.04);
            border-left: 3px solid var(--kpi-color, var(--accent-blue));
            border-radius: 12px;
            padding: 16px 18px;
            min-width: 0;
        }
        .report-kpi-label {
            font-size: 0.68rem; font-weight: 700; letter-spacing: 0.05em;
            text-transform: uppercase; color: var(--text-muted);
            display: flex; align-items: center; gap: 7px; margin-bottom: 10px;
        }
        .report-kpi-label i { color: var(--kpi-color, var(--accent-blue)); }
        .report-kpi-value { font-size: 1.55rem; font-weight: 700; line-height: 1.1; color: var(--text-primary); word-break: break-word; }
        .report-kpi-sub { font-size: 0.72rem; color: var(--text-secondary); margin-top: 7px; line-height: 1.4; }

        .report-grid { display: grid; grid-template-columns: minmax(0, 1.9fr) minmax(0, 1fr); gap: 18px; margin-bottom: 18px; }
        .report-card {
            background: var(--card-bg); border: 1px solid rgba(255,255,255,0.04);
            border-radius: 14px; padding: 20px; min-width: 0;
        }
        .report-card h3 { font-size: 0.92rem; font-weight: 600; color: var(--text-primary); }
        .report-card-sub { font-size: 0.74rem; color: var(--text-muted); margin-top: 4px; font-weight: 400; line-height: 1.5; }
        .report-chart-box { position: relative; width: 100%; height: 360px; margin-top: 16px; }
        .report-chart-box.is-donut { height: 300px; }
        .report-empty {
            display: none; flex-direction: column; align-items: center; justify-content: center;
            gap: 10px; height: 100%; color: var(--text-muted); text-align: center; font-size: 0.82rem;
        }
        .report-empty i { font-size: 1.7rem; opacity: 0.45; }
        .report-chart-box.is-empty canvas { display: none; }
        .report-chart-box.is-empty .report-empty { display: flex; }

        .report-progress { flex: 1; min-width: 60px; height: 7px; border-radius: 99px; background: rgba(255,255,255,0.08); overflow: hidden; }
        .report-progress span { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #10b981, #34d399); }
        .report-table-wrap { overflow-x: auto; margin-top: 14px; }
        table.report-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 660px; }
        table.report-table th {
            text-align: left; padding: 11px 12px; font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);
            border-bottom: 1px solid rgba(255,255,255,0.08); white-space: nowrap;
        }
        table.report-table td { padding: 13px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); color: var(--text-secondary); }
        table.report-table tr:last-child td { border-bottom: none; }
        table.report-table td.rt-name { color: var(--text-primary); font-weight: 600; }
        .rt-pill { display: inline-block; padding: 3px 9px; border-radius: 99px; font-weight: 700; font-size: 0.72rem; white-space: nowrap; }
        .rt-pill.ok { background: rgba(16,185,129,0.15); color: #34d399; }
        .rt-pill.warn { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .rt-pill.bad { background: rgba(239,68,68,0.15); color: #f87171; }

        @media (max-width: 1024px) {
            .report-grid { grid-template-columns: 1fr; }
            .report-chart-box { height: 330px; }
        }
        @media (max-width: 768px) {
            .report-kpis { grid-template-columns: repeat(auto-fit, minmax(142px, 1fr)); gap: 10px; }
            .report-kpi { padding: 13px 14px; }
            .report-kpi-value { font-size: 1.25rem; }
            .report-kpi-label { font-size: 0.62rem; margin-bottom: 8px; }
            .report-kpi-sub { font-size: 0.66rem; }
            .report-card { padding: 16px 14px; }
            .report-chart-box { height: 300px; }
            .report-chart-box.is-donut { height: 265px; }
            .report-toolbar { padding: 14px; gap: 12px; }
            .report-field { flex: 1 1 100%; }
            .report-refresh { width: 100%; }
            .report-stamp { margin-left: 0; }
            /* Stacked card rows, matching the mobile table pattern used elsewhere. */
            .report-table-wrap { overflow-x: visible; }
            table.report-table { min-width: 0; }
            table.report-table thead { display: none; }
            table.report-table tr {
                display: block; background: rgba(255,255,255,0.02);
                border: 1px solid rgba(255,255,255,0.05); border-radius: 10px;
                padding: 8px 12px; margin-bottom: 12px;
            }
            table.report-table td {
                display: flex; justify-content: space-between; align-items: center; gap: 14px;
                padding: 9px 0 !important; text-align: right;
                border-bottom: 1px solid rgba(255,255,255,0.04);
            }
            table.report-table td:last-child { border-bottom: none; }
            table.report-table td::before {
                position: static; transform: none; width: auto; padding: 0;
                content: attr(data-label); text-align: left; white-space: nowrap;
                font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
                letter-spacing: 0.04em; color: var(--text-muted);
            }
            table.report-table td.rt-progress-cell { display: block; text-align: left; }
            table.report-table td.rt-progress-cell::before { display: block; margin-bottom: 7px; }
        }
        @media (max-width: 480px) {
            .report-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .report-chart-box { height: 275px; }
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
        /* Student number ahead of the name in the audit trail */
        .audit-sid {
            display: inline-block;
            margin-right: 7px;
            padding: 1px 7px;
            border-radius: 6px;
            background: rgba(148,163,184,0.15);
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
            font-size: 0.72rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
    </style>
    <script src="assets/js/search_helper.js?v=3"></script>
    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box">
                <img src="assets/img/logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
            </div>
            <span class="sidebar-brand">System Admin</span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="javascript:void(0)" class="nav-link active" onclick="showSection('dashboard', this)"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('reports', this)"><i class="fas fa-chart-bar"></i> Reports & Analytics</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('accounts', this)"><i class="fas fa-users-cog"></i> Account Management</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('audit', this)"><i class="fas fa-history"></i> Audit Trail</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('backup', this)"><i class="fas fa-database"></i> Backup & Restore</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('institutions', this)"><i class="fas fa-university"></i> Institutions</a>
            <a href="javascript:void(0)" class="nav-link" onclick="showSection('archive', this)"><i class="fas fa-archive"></i> Archive</a>

        </nav>
        <div class="sidebar-bottom">
            <a href="index.php?tab=login" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div> <!-- /sidebar -->

    <!-- MAIN CONTENT -->
    <div class="main-content" style="flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; width: 100%;">
        
        <!-- TOPBAR -->
        <div class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 32px; background: var(--card-bg, #1e293b); border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.05)); z-index: 100;">
            <div class="topbar-left">
                <div class="search-bar" style="display: flex; align-items: center; background: rgba(255,255,255,0.05); padding: 8px 16px; border-radius: 20px; gap: 10px;">
                    <i class="fas fa-search" style="color: var(--text-muted, #94a3b8);"></i>
                    <input type="text" id="dashboardSearch" placeholder="Search across dashboard..." style="outline: none; background: transparent; border: none; color: white; width: 200px;">
                </div>
            </div>
            
            <div class="topbar-right" style="display: flex; align-items: center; gap: 20px;">
                <button class="topbar-icon-btn" onclick="openBroadcastModal()" title="Broadcast Notification">
                    <i class="fas fa-bullhorn"></i>
                </button>

                <div class="user-profile">
                    <div class="user-avatar">
                        A
                    </div>
                    <div class="user-info">
                        <div class="name">Admin</div>
                        <div class="role">Registrar Office</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTENT AREA -->
        <div class="content">
            <!-- DASHBOARD SECTION -->
            <div id="dashboard-section" class="content-section active">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Dashboard Overview</h2>
                </div>
                
                <div class="stat-cards">
                    <a href="submissions.php" class="stat-card">
                        <div class="stat-card-header">
                            <span class="stat-label">Total Documents</span>
                            <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                        </div>
                        <div class="stat-value"><?= number_format($stats['total_docs']) ?></div>
                    </a>
                    
                    <a href="submissions.php" class="stat-card">
                        <div class="stat-card-header">
                            <span class="stat-label">Approved</span>
                            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        </div>
                        <div class="stat-value"><?= number_format($stats['approved']) ?></div>
                    </a>
                    
                    <a href="submissions.php" class="stat-card">
                        <div class="stat-card-header">
                            <span class="stat-label">Pending</span>
                            <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        </div>
                        <div class="stat-value"><?= number_format($stats['pending']) ?></div>
                    </a>
                    
                    <a href="javascript:void(0)" class="stat-card">
                        <div class="stat-card-header">
                            <span class="stat-label">Active Students</span>
                            <div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div>
                        </div>
                        <div class="stat-value"><?= number_format($stats['active_students']) ?></div>
                    </a>
                </div>
                
                <div class="card-header" style="margin-top: 30px; margin-bottom: 20px;">
                    <h2>Events Calendar</h2>
                </div>
                <div id="calendar-container">
                    <div id="calendar"></div>
                </div>

            </div><!-- /dashboard-section -->

            <!-- REPORTS SECTION -->
            <div id="reports-section" class="content-section">
                <div class="card-header" style="margin-bottom: 18px;">
                    <div>
                        <h2>Reports &amp; Analytics</h2>
                        <div class="report-card-sub">Document compliance for active students only &mdash; archived and unapproved accounts are excluded.</div>
                    </div>
                </div>
                <div class="report-toolbar">
                    <div class="report-field">
                        <label for="course-filter">Program / Course</label>
                        <select id="course-filter" class="report-select">
                            <option value="all">All Programs/Courses</option>
                            <?php 
                            // Fetch distinct courses/programs from users
                            $active_progs = [];
                            $res = $mysqli->query("SELECT DISTINCT course FROM users WHERE course IS NOT NULL AND course != '' ORDER BY course");
                            if ($res) while($r = $res->fetch_assoc()) $active_progs[] = $r['course'];
                            
                            // Combine with courses from courses table just in case
                            foreach ($courses as $c) {
                                if (!in_array($c['course_code'], $active_progs)) {
                                    $active_progs[] = $c['course_code'];
                                }
                            }
                            sort($active_progs);
                            
                            foreach ($active_progs as $prog): 
                            ?>
                            <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="report-field">
                        <label for="institution-filter">Institution</label>
                        <select id="institution-filter" class="report-select">
                            <option value="all">All Institutions</option>
                            <option value="ISAP">ISAP</option>
                            <option value="MCNP">MCNP</option>
                        </select>
                    </div>
                    <button type="button" class="report-refresh" onclick="renderReports()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <div class="report-stamp" id="report-stamp"></div>
                </div>

                <div class="report-kpis" id="report-kpis"></div>

                <div class="report-grid">
                    <div class="report-card">
                        <h3>Requirement status by program</h3>
                        <div class="report-card-sub">Counts the latest file per student per document type, so re-uploads are not double counted.</div>
                        <div class="report-chart-box" id="report-bar-box">
                            <canvas id="reportsChart"></canvas>
                            <div class="report-empty">
                                <i class="fas fa-chart-bar"></i>
                                <span>No active students match this filter.</span>
                            </div>
                        </div>
                    </div>
                    <div class="report-card">
                        <h3>Overall compliance</h3>
                        <div class="report-card-sub">Share of every requirement expected from active students.</div>
                        <div class="report-chart-box is-donut" id="report-donut-box">
                            <canvas id="reportsDonut"></canvas>
                            <div class="report-empty">
                                <i class="fas fa-chart-pie"></i>
                                <span>Nothing to summarise yet.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="report-card">
                    <h3>Program breakdown</h3>
                    <div class="report-card-sub">Every active student is expected to submit each required document type.</div>
                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Program</th>
                                    <th>Students</th>
                                    <th>Submitted</th>
                                    <th>Approved</th>
                                    <th>Pending</th>
                                    <th>Rejected</th>
                                    <th>Missing</th>
                                    <th>Completion</th>
                                </tr>
                            </thead>
                            <tbody id="report-table-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ACCOUNTS SECTION -->
            <div id="accounts-section" class="content-section">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Account Management</h2>
                </div>
                <div class="table-card">
                    <table class="student-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $users_query = "SELECT * FROM users WHERE role IN ('student', 'registrar') AND is_archived = 0 ORDER BY role, first_name";
                            $users_res = $mysqli->query($users_query);
                            if ($users_res):
                                while ($u = $users_res->fetch_assoc()):
                                    $fullname = formatPersonNameRow($u);
                                    if ($fullname === '') $fullname = 'N/A';
                                    // Students carry a student number, registrars a teacher ID
                                    $row_id = $u['role'] === 'student' ? ($u['student_id'] ?? '') : ($u['teacher_id'] ?? '');
                            ?>
                            <tr>
                                <td data-label="ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($row_id)) ?></td>
                                <td data-label="Name" class="cell-student-name"><?= htmlspecialchars($fullname) ?></td>
                                <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                                <td data-label="Role" style="text-transform: capitalize;"><?= htmlspecialchars($u['role']) ?></td>
                                <td data-label="Status">
                                    <?php if($u['is_verified'] == 2): ?>
                                        <span class="status-badge approved">Active</span>
                                    <?php elseif($u['is_verified'] == 1): ?>
                                        <span class="status-badge pending">Pending</span>
                                    <?php else: ?>
                                        <span class="status-badge rejected">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions">
                                    <form action="process_user_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to change this user\'s status?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <?php if($u['is_verified'] == 2): ?>
                                            <button type="submit" name="action" value="deactivate" class="action-btn btn-wrong" title="Deactivate User"><i class="fas fa-ban"></i></button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="activate" class="action-btn btn-check" title="Activate User"><i class="fas fa-check"></i></button>
                                        <?php endif; ?>
                                    </form>
                                    <form action="process_user_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to ARCHIVE this user?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" name="action" value="archive" class="action-btn" style="background:rgba(245,158,11,0.15); color:#f59e0b;" title="Archive User"><i class="fas fa-archive"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ARCHIVE SECTION -->
            <div id="archive-section" class="content-section">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Archived Accounts</h2>
                </div>
                <div class="table-card">
                    <table class="student-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $archived_query = "SELECT * FROM users WHERE role IN ('student', 'registrar') AND is_archived = 1 ORDER BY role, first_name";
                            $archived_res = $mysqli->query($archived_query);
                            if ($archived_res && $archived_res->num_rows > 0):
                                while ($u = $archived_res->fetch_assoc()):
                                    $fullname = formatPersonNameRow($u);
                                    if ($fullname === '') $fullname = 'N/A';
                                    // Students carry a student number, registrars a teacher ID
                                    $row_id = $u['role'] === 'student' ? ($u['student_id'] ?? '') : ($u['teacher_id'] ?? '');
                            ?>
                            <tr>
                                <td data-label="ID" class="cell-student-id"><?= htmlspecialchars(formatStudentId($row_id)) ?></td>
                                <td data-label="Name" class="cell-student-name"><?= htmlspecialchars($fullname) ?></td>
                                <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                                <td data-label="Role" style="text-transform: capitalize;"><?= htmlspecialchars($u['role']) ?></td>
                                <td data-label="Status">
                                    <span class="status-badge" style="background:rgba(100,116,139,0.2); color:#94a3b8;"><i class="fas fa-archive"></i> Archived</span>
                                </td>
                                <td data-label="Actions">
                                    <form action="process_user_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to RESTORE this archived user?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" name="action" value="unarchive" class="action-btn" style="background:rgba(59,130,246,0.15); color:#3b82f6;" title="Unarchive User"><i class="fas fa-undo"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding: 20px; color: var(--text-muted);">No archived accounts found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- AUDIT SECTION -->
            <div id="audit-section" class="content-section">
                <div class="card-header audit-header">
                    <div>
                        <h2>Audit Trail</h2>
                        <p class="audit-subtitle">Logins, account changes, and document actions across the system.</p>
                    </div>
                    <div class="audit-header-actions">
                        <button type="button" class="audit-btn" onclick="loadAuditLogs()" title="Refresh"><i class="fas fa-rotate-right"></i><span>Refresh</span></button>
                        <button type="button" class="audit-btn audit-btn-primary" onclick="exportAuditCsv()" title="Export the filtered entries"><i class="fas fa-file-csv"></i><span>Export CSV</span></button>
                    </div>
                </div>

                <div class="audit-filters">
                    <label class="audit-field audit-field-wide">
                        <span>Program / Course</span>
                        <select id="auditProgram">
                            <option value="">All Courses</option>
                            <?php foreach (getProgramFilterOptions($mysqli) as $program_opt): ?>
                            <option value="<?= htmlspecialchars($program_opt['institution'] . '|' . $program_opt['course']) ?>"><?= htmlspecialchars($program_opt['course'] . ' (' . $program_opt['institution'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="audit-field">
                        <span>Action</span>
                        <select id="auditAction"><option value="">All Actions</option></select>
                    </label>
                    <label class="audit-field audit-field-wide">
                        <span>Search</span>
                        <input type="search" id="auditSearch" placeholder="Name, email, or details" autocomplete="off">
                    </label>
                    <label class="audit-field">
                        <span>From</span>
                        <input type="date" id="auditFrom">
                    </label>
                    <label class="audit-field">
                        <span>To</span>
                        <input type="date" id="auditTo">
                    </label>
                    <button type="button" class="audit-btn audit-clear" onclick="clearAuditFilters()"><i class="fas fa-xmark"></i><span>Clear filters</span></button>
                </div>

                <div class="audit-chips" id="auditChips"></div>

                <div class="table-card audit-table-card">
                    <table class="student-table audit-table" id="auditTable">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>User</th>
                                <th>Institution / Program</th>
                                <th>Action</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditBody">
                            <tr class="audit-state-row"><td colspan="5" class="audit-state"><i class="fas fa-spinner fa-spin"></i> Loading audit logs...</td></tr>
                        </tbody>
                    </table>
                    <div class="audit-pagination">
                        <span id="auditRange"></span>
                        <div class="audit-pages">
                            <button type="button" class="audit-btn" id="auditPrev" onclick="changeAuditPage(-1)" disabled><i class="fas fa-chevron-left"></i><span>Prev</span></button>
                            <span id="auditPageInfo"></span>
                            <button type="button" class="audit-btn" id="auditNext" onclick="changeAuditPage(1)" disabled><span>Next</span><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                // ─── AUDIT TRAIL ───
                // Filtering and paging happen on the server (fetch_audit_logs.php), so results cover every log entry.
                const auditState = { page: 1, controller: null, searchTimer: null };

                function auditEscape(value) {
                    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                }

                function auditParams(extra = {}) {
                    const params = new URLSearchParams();
                    const values = {
                        program: document.getElementById('auditProgram').value,
                        action: document.getElementById('auditAction').value,
                        q: document.getElementById('auditSearch').value.trim(),
                        from: document.getElementById('auditFrom').value,
                        to: document.getElementById('auditTo').value,
                        ...extra
                    };
                    Object.entries(values).forEach(([key, value]) => { if (value) params.set(key, value); });
                    return params;
                }

                function auditActionLabel(action) {
                    return action.toLowerCase().split('_')
                        .map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
                        .replace(/\bDb\b/, 'Database').replace(/\bCsv\b/, 'CSV');
                }

                function auditActionStyle(action) {
                    if (/FAILED|BLOCKED|REJECTED|DEACTIVATED|DELETED/.test(action)) return ['danger', 'fa-circle-xmark'];
                    if (action === 'LOGIN') return ['success', 'fa-right-to-bracket'];
                    if (action === 'LOGOUT') return ['neutral', 'fa-right-from-bracket'];
                    if (/APPROVED|ACTIVATED|RESTORED|UNARCHIVED|VERIFIED/.test(action)) return ['success', 'fa-circle-check'];
                    if (/ARCHIVED/.test(action)) return ['neutral', 'fa-box-archive'];
                    if (/PASSWORD/.test(action)) return ['warning', 'fa-key'];
                    if (/BACKUP|RESTORE_DB|EXPORT/.test(action)) return ['purple', 'fa-database'];
                    if (/UPLOAD/.test(action)) return ['info', 'fa-file-arrow-up'];
                    return ['info', 'fa-circle-info'];
                }

                function auditTimeAgo(seconds) {
                    seconds = Math.max(0, Number(seconds) || 0);
                    if (seconds < 60) return 'just now';
                    const units = [[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
                    for (const [size, name] of units) {
                        if (seconds >= size) {
                            const n = Math.floor(seconds / size);
                            return `${n} ${name}${n > 1 ? 's' : ''} ago`;
                        }
                    }
                }

                function auditPersonHtml(person, fallback) {
                    if (!person) return `<span class="audit-muted">${fallback}</span>`;
                    const roleLabel = person.role === 'systemadmin' ? 'Admin' : person.role;
                    const role = person.role ? `<span class="audit-role audit-role-${auditEscape(person.role)}">${auditEscape(roleLabel)}</span>` : '';
                    const email = person.name && person.email ? `<small class="audit-muted">${auditEscape(person.email)}</small>` : '';
                    // The student number leads, then the surname-first name
                    const sid = person.student_id ? `<span class="audit-sid">${auditEscape(person.student_id)}</span>` : '';
                    return `<div class="audit-person">${sid}<strong>${auditEscape(person.name || person.email)}</strong>${role}</div>${email}`;
                }

                function auditProgramText(person) {
                    if (!person || !person.institution) return '';
                    return person.course ? `${person.course} (${person.institution})` : person.institution;
                }

                function renderAuditRow(row) {
                    const [tone, icon] = auditActionStyle(row.action);
                    const actorFallback = row.action.startsWith('LOGIN') ? 'Not signed in' : 'System';
                    const target = row.target && (!row.actor || row.target.id !== row.actor.id) ? row.target : null;

                    let program = '<span class="audit-muted">&mdash;</span>';
                    if (row.actor && row.actor.institution) {
                        program = `<strong>${auditEscape(row.actor.institution)}</strong>` +
                            (row.actor.course ? `<small class="audit-muted">${auditEscape(row.actor.course)}</small>` : '');
                    }
                    if (target) {
                        const targetProgram = auditProgramText(target);
                        program += `<div class="audit-affected"><span>Affected:</span> ${target.student_id ? auditEscape(target.student_id) + ' — ' : ''}${auditEscape(target.name || target.email)}` +
                            (targetProgram ? `<br><small class="audit-muted">${auditEscape(targetProgram)}</small>` : '') + '</div>';
                    }

                    return `<tr>
                        <td data-label="Date &amp; Time"><div class="audit-cell"><strong>${auditEscape(row.time)}</strong><small class="audit-muted" title="${auditEscape(row.timestamp)}">${auditTimeAgo(row.age_seconds)}</small></div></td>
                        <td data-label="User"><div class="audit-cell">${auditPersonHtml(row.actor, actorFallback)}</div></td>
                        <td data-label="Program"><div class="audit-cell">${program}</div></td>
                        <td data-label="Action"><span class="audit-badge audit-${tone}"><i class="fas ${icon}"></i> ${auditEscape(auditActionLabel(row.action))}</span></td>
                        <td data-label="Details" class="audit-desc">${auditEscape(row.description)}</td>
                    </tr>`;
                }

                function auditStateRow(html) {
                    return `<tr class="audit-state-row"><td colspan="5" class="audit-state">${html}</td></tr>`;
                }

                function renderAuditActions(actions) {
                    const select = document.getElementById('auditAction');
                    const key = actions.join(',');
                    if (select.dataset.key === key) return;
                    const current = select.value;
                    select.dataset.key = key;
                    select.innerHTML = '<option value="">All Actions</option>' +
                        actions.map(a => `<option value="${auditEscape(a)}">${auditEscape(auditActionLabel(a))}</option>`).join('');
                    select.value = actions.includes(current) ? current : '';
                }

                function renderAuditChips(counts) {
                    const current = document.getElementById('auditAction').value;
                    const total = counts.reduce((sum, c) => sum + c.count, 0);
                    const chip = (value, label, count) =>
                        `<button type="button" class="audit-chip${current === value ? ' active' : ''}" data-action="${auditEscape(value)}">${auditEscape(label)}<b>${count.toLocaleString()}</b></button>`;
                    document.getElementById('auditChips').innerHTML =
                        chip('', 'All', total) + counts.map(c => chip(c.action, auditActionLabel(c.action), c.count)).join('');
                }

                async function loadAuditLogs(resetPage = false) {
                    if (resetPage) auditState.page = 1;
                    if (auditState.controller) auditState.controller.abort();
                    const controller = new AbortController();
                    auditState.controller = controller;

                    const table = document.getElementById('auditTable');
                    const body = document.getElementById('auditBody');
                    table.classList.add('audit-loading');
                    if (!body.querySelector('tr:not(.audit-state-row)')) {
                        body.innerHTML = auditStateRow('<i class="fas fa-spinner fa-spin"></i> Loading audit logs...');
                    }

                    try {
                        const response = await fetch('fetch_audit_logs.php?' + auditParams({ page: auditState.page }), {
                            signal: controller.signal,
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) throw new Error(data.error || 'Request failed');

                        auditState.page = data.page;
                        renderAuditActions(data.actions);
                        renderAuditChips(data.action_counts);
                        body.innerHTML = data.rows.length
                            ? data.rows.map(renderAuditRow).join('')
                            : auditStateRow('<i class="fas fa-magnifying-glass"></i> No audit logs match these filters.');

                        const start = data.total ? (data.page - 1) * data.per_page + 1 : 0;
                        const end = Math.min(data.page * data.per_page, data.total);
                        document.getElementById('auditRange').textContent = data.total
                            ? `Showing ${start.toLocaleString()}–${end.toLocaleString()} of ${data.total.toLocaleString()} entries`
                            : 'No entries';
                        document.getElementById('auditPageInfo').textContent = `Page ${data.page} of ${data.total_pages}`;
                        document.getElementById('auditPrev').disabled = data.page <= 1;
                        document.getElementById('auditNext').disabled = data.page >= data.total_pages;
                    } catch (err) {
                        if (err.name === 'AbortError') return;
                        body.innerHTML = auditStateRow('<i class="fas fa-triangle-exclamation"></i> Could not load audit logs. <button type="button" class="audit-btn" onclick="loadAuditLogs()">Try again</button>');
                        document.getElementById('auditRange').textContent = '';
                        document.getElementById('auditPageInfo').textContent = '';
                    } finally {
                        if (auditState.controller === controller) {
                            auditState.controller = null;
                            table.classList.remove('audit-loading');
                        }
                    }
                }

                function changeAuditPage(delta) {
                    auditState.page = Math.max(1, auditState.page + delta);
                    loadAuditLogs();
                    document.getElementById('auditChips').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                function clearAuditFilters() {
                    ['auditProgram', 'auditAction', 'auditSearch', 'auditFrom', 'auditTo'].forEach(id => { document.getElementById(id).value = ''; });
                    document.getElementById('auditFrom').max = '';
                    document.getElementById('auditTo').min = '';
                    const topSearch = document.getElementById('dashboardSearch');
                    if (topSearch) topSearch.value = '';
                    loadAuditLogs(true);
                }

                function exportAuditCsv() {
                    window.location.href = 'fetch_audit_logs.php?' + auditParams({ format: 'csv' });
                }

                function queueAuditSearch() {
                    clearTimeout(auditState.searchTimer);
                    auditState.searchTimer = setTimeout(() => loadAuditLogs(true), 300);
                }

                document.addEventListener('DOMContentLoaded', () => {
                    ['auditProgram', 'auditAction'].forEach(id => {
                        document.getElementById(id).addEventListener('change', () => loadAuditLogs(true));
                    });
                    const from = document.getElementById('auditFrom');
                    const to = document.getElementById('auditTo');
                    from.addEventListener('change', () => { to.min = from.value; loadAuditLogs(true); });
                    to.addEventListener('change', () => { from.max = to.value; loadAuditLogs(true); });
                    document.getElementById('auditSearch').addEventListener('input', queueAuditSearch);
                    document.getElementById('auditChips').addEventListener('click', e => {
                        const chip = e.target.closest('.audit-chip');
                        if (!chip) return;
                        document.getElementById('auditAction').value = chip.dataset.action;
                        loadAuditLogs(true);
                    });
                });
            </script>

            <!-- BACKUP/RESTORE SECTION -->
            <div id="backup-section" class="content-section">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Database Backup & Restore</h2>
                </div>
                <div class="grid-cards" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="table-card">
                        <h3>Backup Database</h3>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 10px 0 20px;">Download a complete backup of the database as a .bak file.</p>
                        <a href="backup_db.php" class="action-btn btn-check" style="width: auto; padding: 10px 20px; text-decoration: none;"><i class="fas fa-download" style="margin-right: 8px;"></i> Download Backup (.bak)</a>
                    </div>
                    <div class="table-card">
                        <h3>Restore Database</h3>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 10px 0 20px;">Upload a .sql or .bak file to restore the database.</p>
                        <form action="restore_db.php" method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center;">
                            <input type="file" name="backup_file" accept=".sql,.bak" required style="padding: 5px; color: white;">
                            <button type="submit" class="action-btn btn-view" style="width: auto; padding: 10px 20px;"><i class="fas fa-upload" style="margin-right: 8px;"></i> Restore</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- INSTITUTIONS SECTION -->
            <div id="institutions-section" class="content-section">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h2>Institutions & Programs</h2>
                </div>
                
                <div id="institutions-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
                    <div class="stat-card" style="cursor: pointer; text-align: center; border-color: #3b82f6; background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(29,78,216,0.05));" onclick="showProgramsList('MCNP')">
                        <div style="font-size: 3rem; font-weight: 800; color: #3b82f6; margin-bottom: 10px;">MCNP</div>
                        <div style="color: var(--text-secondary); font-size: 0.9rem;">Medical Colleges of Northern Philippines</div>
                    </div>
                    
                    <div class="stat-card" style="cursor: pointer; text-align: center; border-color: #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(185,28,28,0.05));" onclick="showProgramsList('ISAP')">
                        <div style="font-size: 3rem; font-weight: 800; color: #ef4444; margin-bottom: 10px;">ISAP</div>
                        <div style="color: var(--text-secondary); font-size: 0.9rem;">International School of Asia and the Pacific</div>
                    </div>
                </div>

                <div id="programs-view" style="display: none;">
                    <button class="back-button" onclick="backToInstitutions()"><i class="fas fa-arrow-left"></i> Back to Institutions</button>
                    <h2 id="institution-title" style="margin-bottom: 20px; color: var(--accent-gold); font-size: 1.5rem;"></h2>
                    
                    <div id="programs-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 15px; margin-bottom: 30px;">
                        <!-- Programs will be injected here -->
                    </div>
                    
                    <div id="students-view" style="display: none;">
                        <button class="back-button" onclick="backToPrograms()"><i class="fas fa-arrow-left"></i> Back to Programs</button>
                        <h3 id="program-title" style="margin-bottom: 15px; color: var(--text-primary);"></h3>
                        <div class="table-card">
                            <div style="overflow-x: auto;">
                                <table id="students-table">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Student Name</th>
                                            <th>Year Level</th>
                                            <th>Submitted Docs</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="students-table-body">
                                        <!-- Students will be injected here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Old calendar section removed -->

            <!-- VIEW DOCUMENT SECTION (Deprecated, now using modal) -->
            <div id="view-document-section" class="content-section" style="display:none;"></div>

        </div><!-- /content -->
    </div><!-- /main -->

    <!-- Side Preview Panel -->
    <div id="preview-panel" class="preview-panel">
        <div class="preview-header">
            <h3 style="margin:0; font-size: 16px;"><i class="fas fa-file-image"></i> <span id="preview-title">Document Preview</span></h3>
            <button class="btn-close-preview" onclick="closePreview()"><i class="fas fa-times"></i></button>
        </div>
        <div class="preview-body">
            <iframe id="preview-frame" src=""></iframe>
            <img id="preview-image" src="" style="display: none;">
        </div>
    </div>
    <!-- Broadcast Modal -->
    <div id="broadcastModal" class="broadcast-modal">
        <div class="broadcast-modal-content">
            <h3>Broadcast Notification</h3>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 10px;">Send a message to all students in your institution.</p>
            <form action="process_broadcast.php" method="POST">
                <textarea name="message" placeholder="Enter your notification message..." required></textarea>
                <div class="broadcast-modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeBroadcastModal()">Cancel</button>
                    <button type="submit" class="btn-confirm">Send Broadcast</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openBroadcastModal() {
            document.getElementById('broadcastModal').style.display = 'flex';
        }

        function closeBroadcastModal() {
            document.getElementById('broadcastModal').style.display = 'none';
        }
        // Search functionality
        // Searches the tables of the section on screen (search_helper.js: every
        // word, any order, buttons ignored), and again whenever the section changes
        // or new rows are rendered.
        const dashboardSearchInput = document.getElementById('dashboardSearch');
        window.runDashboardSearch = SearchKit.bind(dashboardSearchInput, function () {
            let activeSection = document.querySelector('.content-section.active');
            if (!activeSection) return;

            // The audit trail is paged on the server, so search it there instead of hiding rows
            if (activeSection.id === 'audit-section') {
                if (document.getElementById('auditSearch').value !== dashboardSearchInput.value) {
                    document.getElementById('auditSearch').value = dashboardSearchInput.value;
                    queueAuditSearch();
                }
                SearchKit.report(dashboardSearchInput, [], false);
                return;
            }

            SearchKit.filterTables(dashboardSearchInput, Array.from(activeSection.querySelectorAll('table')));
        });

        function openRejectModal(id, type) {
            if (type === 'document') {
                document.getElementById('rejectDocId').value = id;
                document.getElementById('rejectForm').action = 'process_document_status.php';
            }
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        function viewDocument(path, name) {
            document.getElementById('preview-title').innerText = name || 'Document Preview';
            const ext = path.split('.').pop().toLowerCase();
            const isImage = ['jpg','jpeg','png','gif'].includes(ext);
            
            if (isImage) {
                document.getElementById('preview-frame').style.display = 'none';
                document.getElementById('preview-image').src = path;
                document.getElementById('preview-image').style.display = 'block';
            } else {
                document.getElementById('preview-image').style.display = 'none';
                document.getElementById('preview-frame').src = path;
                document.getElementById('preview-frame').style.display = 'block';
            }
            document.getElementById('preview-panel').classList.add('active');
        }

        function closePreview() {
            document.getElementById('preview-panel').classList.remove('active');
            setTimeout(() => {
                document.getElementById('preview-frame').src = '';
                document.getElementById('preview-image').src = '';
            }, 300);
        }

        function showSection(sectionId, element) {
            localStorage.setItem('admin-active-section', sectionId);
            if (window.innerWidth <= 1024) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar) sidebar.classList.remove('active');
            }
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            
            document.getElementById(sectionId + '-section').classList.add('active');
            if (element) element.classList.add('active');
            // Keep the search box's filter on the newly shown section's tables.
            if (window.runDashboardSearch && sectionId !== 'audit') window.runDashboardSearch();
            if (sectionId === 'audit') loadAuditLogs();
            if (sectionId === 'reports' && window.renderReports) window.renderReports();

            // Calendar initialization moved to DOMContentLoaded
        }
        
        const instPrograms = <?= json_encode(getInstitutionPrograms()) ?>;

        function showProgramsList(institution) {
            document.getElementById('institutions-cards').style.display = 'none';
            document.getElementById('programs-view').style.display = 'block';
            document.getElementById('students-view').style.display = 'none';
            document.getElementById('institution-title').innerText = institution + ' Programs';
            
            const grid = document.getElementById('programs-grid');
            grid.style.display = 'grid';
            grid.innerHTML = '';
            
            const programs = instPrograms[institution] || [];
            programs.forEach(prog => {
                const div = document.createElement('div');
                div.className = 'stat-card';
                div.style.cursor = 'pointer';
                div.style.padding = '15px';
                div.innerHTML = `<div style="font-weight: 600; color: var(--text-primary); font-size: 0.9rem;">${prog}</div>`;
                div.onclick = () => fetchStudentsForProgram(prog);
                grid.appendChild(div);
            });
        }

        function backToInstitutions() {
            document.getElementById('programs-view').style.display = 'none';
            document.getElementById('institutions-cards').style.display = 'grid';
        }

        function backToPrograms() {
            document.getElementById('students-view').style.display = 'none';
            document.getElementById('programs-grid').style.display = 'grid';
        }

        // Names and document lists come from the database; never paste them into HTML raw.
        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
        }

        function fetchStudentsForProgram(program) {
            document.getElementById('programs-grid').style.display = 'none';
            document.getElementById('students-view').style.display = 'block';
            document.getElementById('program-title').innerText = "Students in " + program;
            
            const tbody = document.getElementById('students-table-body');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading...</td></tr>';
            
            fetch(`fetch_program_students.php?program=${encodeURIComponent(program)}`)
                .then(res => res.json())
                .then(data => {
                    tbody.innerHTML = '';
                    if (data.status === 'success' && data.data.length > 0) {
                        data.data.forEach(student => {
                            const statusHtml = student.is_verified == 2 
                                ? '<span class="status-badge approved">Verified</span>' 
                                : '<span class="status-badge pending">Pending</span>';
                            
                            tbody.innerHTML += `
                                <tr>
                                    <td data-label="Student ID" class="cell-student-id">${escapeHtml(student.display_id)}</td>
                                    <td data-label="Student Name" class="cell-student-name">${escapeHtml(student.display_name)}</td>
                                    <td data-label="Year Level" style="color:var(--text-secondary);">${escapeHtml(student.year_level || 'N/A')}</td>
                                    <td data-label="Submitted Docs" style="font-size: 0.8rem; color:var(--text-secondary);">${escapeHtml(student.submitted_docs || 'None')}</td>
                                    <td data-label="Status">${statusHtml}</td>
                                </tr>
                            `;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color: var(--text-muted);">No students found for this program.</td></tr>';
                    }
                    // Rows that arrive after the admin typed a search are filtered too.
                    if (window.runDashboardSearch) window.runDashboardSearch();
                })
                .catch(err => {
                    console.error(err);
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color: #ef4444;">Error fetching data.</td></tr>';
                });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const initialSection = urlParams.get('section') || localStorage.getItem('admin-active-section');
            if (initialSection) {
                const navLinks = document.querySelectorAll('.nav-link');
                let targetLink = null;
                navLinks.forEach(link => {
                    if (link.getAttribute('onclick') && link.getAttribute('onclick').includes("showSection('" + initialSection + "'")) {
                        targetLink = link;
                    }
                });
                showSection(initialSection, targetLink);
            }

            setTimeout(() => {
                const calendarEl = document.getElementById('calendar');
                if (calendarEl) {
                    const calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        height: 550,
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek'
                        }
                    });
                    calendar.render();
                }
            }, 100);

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
            /* ─── Reports & Analytics ─── */
            let reportsBarChart = null;
            let reportsDonutChart = null;
            let reportsRequestId = 0;
            let reportsCache = null;
            let reportsResizeTimer = null;
            let reportsCenterPct = 0;

            const REPORT_PALETTE = {
                approved: '#10b981',
                pending: '#f59e0b',
                rejected: '#ef4444',
                missing: '#475569'
            };

            function reportIsMobile() { return window.innerWidth <= 768; }
            function reportFontSize() { return reportIsMobile() ? 10 : 11.5; }
            function reportNum(n) { return Number(n || 0).toLocaleString(); }

            function reportEscape(s) {
                const d = document.createElement('div');
                d.textContent = (s === null || s === undefined) ? '' : s;
                return d.innerHTML;
            }

            // Long program names are wrapped onto several lines so the axis stays readable.
            function reportWrapLabel(text) {
                const limit = reportIsMobile() ? 11 : 16;
                const lines = [];
                let line = '';
                String(text).split(' ').forEach(word => {
                    if (!line) line = word;
                    else if ((line + ' ' + word).length <= limit) line += ' ' + word;
                    else { lines.push(line); line = word; }
                });
                if (line) lines.push(line);
                return lines.slice(0, 3);
            }

            function renderReportKpis(totals, meta) {
                const box = document.getElementById('report-kpis');
                if (!box) return;
                const required = Number(meta.required_types || 0);
                const cards = [
                    {
                        label: 'Active Students', icon: 'fa-user-graduate', color: 'var(--accent-blue)',
                        value: reportNum(totals.students),
                        sub: reportNum(totals.uploaders) + ' have uploaded at least once'
                    },
                    {
                        label: 'Requirements In', icon: 'fa-file-upload', color: 'var(--accent-purple)',
                        value: reportNum(totals.requirements) + ' / ' + reportNum(totals.expected),
                        sub: reportNum(totals.total_uploads) + ' files received incl. re-uploads'
                    },
                    {
                        label: 'Approved', icon: 'fa-check-circle', color: 'var(--accent-green)',
                        value: reportNum(totals.approved),
                        sub: (totals.approval_rate || 0) + '% of submitted requirements'
                    },
                    {
                        label: 'Pending Review', icon: 'fa-clock', color: 'var(--accent-orange)',
                        value: reportNum(totals.pending),
                        sub: 'Awaiting a registrar decision'
                    },
                    {
                        label: 'Rejected', icon: 'fa-times-circle', color: 'var(--accent-red)',
                        value: reportNum(totals.rejected),
                        sub: 'Needs a corrected re-upload'
                    },
                    {
                        label: 'Completion', icon: 'fa-chart-pie', color: 'var(--accent-gold)',
                        value: (totals.completion_rate || 0) + '%',
                        sub: required ? required + ' documents required per student' : 'No document types configured'
                    }
                ];
                box.innerHTML = cards.map(c => `
                    <div class="report-kpi" style="--kpi-color: ${c.color};">
                        <div class="report-kpi-label"><i class="fas ${c.icon}"></i>${c.label}</div>
                        <div class="report-kpi-value">${c.value}</div>
                        <div class="report-kpi-sub">${c.sub}</div>
                    </div>`).join('');
            }

            function renderReportTable(rows) {
                const body = document.getElementById('report-table-body');
                if (!body) return;
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:26px; color:var(--text-muted);">No active students match this filter.</td></tr>';
                    return;
                }
                body.innerHTML = rows.map(r => {
                    const rate = Number(r.completion_rate || 0);
                    const cls = rate >= 75 ? 'ok' : (rate >= 40 ? 'warn' : 'bad');
                    return `
                    <tr>
                        <td class="rt-name" data-label="Program">${reportEscape(r.course)}</td>
                        <td data-label="Students">${reportNum(r.total_students)}</td>
                        <td data-label="Submitted">${reportNum(r.requirements)} / ${reportNum(r.expected)}</td>
                        <td data-label="Approved" style="color:#34d399; font-weight:600;">${reportNum(r.approved)}</td>
                        <td data-label="Pending" style="color:#fbbf24; font-weight:600;">${reportNum(r.pending)}</td>
                        <td data-label="Rejected" style="color:#f87171; font-weight:600;">${reportNum(r.rejected)}</td>
                        <td data-label="Missing">${reportNum(r.missing)}</td>
                        <td class="rt-progress-cell" data-label="Completion">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div class="report-progress"><span style="width:${Math.min(100, rate)}%"></span></div>
                                <span class="rt-pill ${cls}">${rate}%</span>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }

            // Writes the completion figure into the middle of the doughnut.
            const reportDonutCenter = {
                id: 'reportDonutCenter',
                afterDatasetsDraw(chart) {
                    const meta = chart.getDatasetMeta(0);
                    const arc = meta && meta.data && meta.data[0];
                    if (!arc) return;
                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#f1f5f9';
                    ctx.font = '700 22px Inter, sans-serif';
                    ctx.fillText(reportsCenterPct + '%', arc.x, arc.y - 9);
                    ctx.fillStyle = '#64748b';
                    ctx.font = '700 9px Inter, sans-serif';
                    ctx.fillText('COMPLETE', arc.x, arc.y + 12);
                    ctx.restore();
                }
            };

            function renderReportCharts(rows, totals) {
                const barBox = document.getElementById('report-bar-box');
                const donutBox = document.getElementById('report-donut-box');
                const barCanvas = document.getElementById('reportsChart');
                const donutCanvas = document.getElementById('reportsDonut');
                if (!barCanvas || !donutCanvas || typeof Chart === 'undefined') return;

                if (reportsBarChart) { reportsBarChart.destroy(); reportsBarChart = null; }
                if (reportsDonutChart) { reportsDonutChart.destroy(); reportsDonutChart = null; }

                const hasRows = rows.length > 0;
                const donutValues = [totals.approved, totals.pending, totals.rejected, totals.missing].map(v => Number(v || 0));
                const donutTotal = donutValues.reduce((a, b) => a + b, 0);
                if (barBox) barBox.classList.toggle('is-empty', !hasRows);
                if (donutBox) donutBox.classList.toggle('is-empty', donutTotal === 0);
                if (!hasRows) return;

                const fullLabels = rows.map(r => r.course);
                const tickFont = { size: reportFontSize(), family: 'Inter' };
                // Several programs on a narrow screen read better as horizontal bars.
                const horizontal = reportIsMobile() && rows.length > 3;

                reportsBarChart = new Chart(barCanvas, {
                    type: 'bar',
                    data: {
                        labels: rows.map(r => reportWrapLabel(r.course)),
                        datasets: [
                            { label: 'Approved', data: rows.map(r => Number(r.approved || 0)), backgroundColor: REPORT_PALETTE.approved },
                            { label: 'Pending', data: rows.map(r => Number(r.pending || 0)), backgroundColor: REPORT_PALETTE.pending },
                            { label: 'Rejected', data: rows.map(r => Number(r.rejected || 0)), backgroundColor: REPORT_PALETTE.rejected },
                            { label: 'Not submitted', data: rows.map(r => Number(r.missing || 0)), backgroundColor: REPORT_PALETTE.missing }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: horizontal ? 'y' : 'x',
                        datasets: { bar: { maxBarThickness: 58, borderRadius: 4, borderSkipped: false } },
                        scales: {
                            x: {
                                stacked: true, beginAtZero: true,
                                ticks: { color: '#94a3b8', font: tickFont, autoSkip: false, maxRotation: 0, precision: 0 },
                                grid: { color: horizontal ? 'rgba(255,255,255,0.06)' : 'transparent', drawTicks: false },
                                border: { display: false }
                            },
                            y: {
                                stacked: true, beginAtZero: true,
                                ticks: { color: '#94a3b8', font: tickFont, precision: 0 },
                                grid: { color: horizontal ? 'transparent' : 'rgba(255,255,255,0.06)', drawTicks: false },
                                border: { display: false }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top', align: 'end',
                                labels: {
                                    color: '#cbd5e1', usePointStyle: true, pointStyle: 'circle',
                                    boxWidth: 8, boxHeight: 8, padding: 14,
                                    font: { size: reportFontSize(), family: 'Inter' }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15,23,42,0.96)',
                                borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1,
                                titleColor: '#f1f5f9', bodyColor: '#cbd5e1', footerColor: '#94a3b8',
                                padding: 12, cornerRadius: 8, boxPadding: 5,
                                footerFont: { weight: '500', size: 10.5 },
                                callbacks: {
                                    title: items => fullLabels[items[0].dataIndex],
                                    label: ctx => {
                                        const row = rows[ctx.dataIndex];
                                        const value = Number(horizontal ? ctx.parsed.x : ctx.parsed.y) || 0;
                                        const expected = Number(row.expected || 0);
                                        const pct = expected ? Math.round((value / expected) * 100) : 0;
                                        return ' ' + ctx.dataset.label + ': ' + reportNum(value) + (expected ? ' (' + pct + '%)' : '');
                                    },
                                    footer: items => {
                                        const row = rows[items[0].dataIndex];
                                        return reportNum(row.total_students) + ' students, ' + reportNum(row.expected) + ' expected requirements';
                                    }
                                }
                            }
                        },
                        animation: { duration: 500 }
                    }
                });

                reportsDonutChart = new Chart(donutCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Approved', 'Pending', 'Rejected', 'Not submitted'],
                        datasets: [{
                            data: donutValues,
                            backgroundColor: [REPORT_PALETTE.approved, REPORT_PALETTE.pending, REPORT_PALETTE.rejected, REPORT_PALETTE.missing],
                            borderColor: 'rgba(15,23,42,0.85)',
                            borderWidth: 2,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '64%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#cbd5e1', usePointStyle: true, pointStyle: 'circle',
                                    boxWidth: 8, boxHeight: 8, padding: 12,
                                    font: { size: reportFontSize(), family: 'Inter' }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15,23,42,0.96)',
                                borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1,
                                titleColor: '#f1f5f9', bodyColor: '#cbd5e1',
                                padding: 12, cornerRadius: 8, boxPadding: 5,
                                callbacks: {
                                    label: ctx => {
                                        const value = Number(ctx.parsed) || 0;
                                        const pct = donutTotal ? Math.round((value / donutTotal) * 100) : 0;
                                        return ' ' + ctx.label + ': ' + reportNum(value) + ' (' + pct + '%)';
                                    }
                                }
                            }
                        },
                        animation: { duration: 500 }
                    },
                    plugins: [reportDonutCenter]
                });
            }

            function renderReports() {
                if (!document.getElementById('reportsChart')) return;
                const courseEl = document.getElementById('course-filter');
                const instEl = document.getElementById('institution-filter');
                const stamp = document.getElementById('report-stamp');
                const course = courseEl ? courseEl.value : 'all';
                const institution = instEl ? instEl.value : 'all';
                if (stamp) stamp.textContent = 'Loading...';

                const reqId = ++reportsRequestId;
                fetch('fetch_report_data.php?course=' + encodeURIComponent(course) + '&institution=' + encodeURIComponent(institution))
                    .then(res => res.json())
                    .then(payload => {
                        if (reqId !== reportsRequestId) return; // a newer request already won
                        if (payload.error) throw new Error(payload.error);
                        reportsCache = payload;
                        const rows = payload.rows || [];
                        const totals = payload.totals || {};
                        reportsCenterPct = Number(totals.completion_rate || 0);
                        renderReportKpis(totals, payload.meta || {});
                        renderReportTable(rows);
                        renderReportCharts(rows, totals);
                        if (stamp) stamp.textContent = payload.meta ? 'Updated ' + payload.meta.generated_at : '';
                    })
                    .catch(err => {
                        console.error('Error fetching report data:', err);
                        if (stamp) stamp.textContent = 'Could not load report data';
                        renderReportTable([]);
                        renderReportCharts([], {});
                    });
            }
            window.renderReports = renderReports;

            ['course-filter', 'institution-filter'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', renderReports);
            });

            // Re-draw on resize so label wrapping and bar orientation follow the viewport.
            window.addEventListener('resize', () => {
                clearTimeout(reportsResizeTimer);
                reportsResizeTimer = setTimeout(() => {
                    const section = document.getElementById('reports-section');
                    if (reportsCache && section && section.classList.contains('active')) {
                        renderReportCharts(reportsCache.rows || [], reportsCache.totals || {});
                    }
                }, 220);
            });

            // Charts mis-size inside a hidden section, so only load once it is visible.
            const reportsSectionEl = document.getElementById('reports-section');
            if (reportsSectionEl && reportsSectionEl.classList.contains('active')) {
                renderReports();
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


