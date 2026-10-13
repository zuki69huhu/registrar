<?php
session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'name_helper.php';

// Access control: Allow registrar and student
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['registrar', 'student'])) {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'];

if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}
// Fetch user details from the database
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, student_id, profile_picture, year_level, course, institution, section FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: logout.php");
    exit;
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
$user_institution = $user_data['institution'] ?? '';
$first_name = $user_data['first_name'] ?? 'S';
$full_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['middle_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
if (empty($full_name)) $full_name = $user_data['email'];
$initials = strtoupper(substr($user_data['first_name'] ?? ($role === 'student' ? 'S' : 'R'), 0, 1) . substr($user_data['last_name'] ?? ($role === 'student' ? 'T' : 'O'), 0, 1));

// Fetch Submissions - Standardized to 'documents' table used in process_upload.php
$submissions = [];
$pending_count = 0;
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

if ($role === 'registrar') {
    if (!empty($user_institution)) {
        $query = "SELECT d.id, d.file_name as document_name, d.file_path, d.uploaded_at as submitted_at, d.status, d.feedback, d.document_type, d.reviewed_at, r.first_name AS reviewer_first, r.last_name AS reviewer_last, u.first_name, u.middle_name, u.last_name, u.student_id, u.course, u.year_level, u.section 
                  FROM documents d 
                  JOIN users u ON d.user_id = u.id 
                  LEFT JOIN users r ON d.reviewed_by = r.id 
                  WHERE u.institution = ? AND d.status = 'rejected'
                  ORDER BY d.uploaded_at DESC";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("s", $user_institution);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while($row = $res->fetch_assoc()) {
            $submissions[] = $row;
            if ($row['status'] === 'pending') $pending_count++;
        }
        $stmt->close();
    } else {
        $query = "SELECT d.id, d.file_name as document_name, d.file_path, d.uploaded_at as submitted_at, d.status, d.feedback, d.document_type, d.reviewed_at, r.first_name AS reviewer_first, r.last_name AS reviewer_last, u.first_name, u.middle_name, u.last_name, u.student_id, u.course, u.year_level, u.section 
                  FROM documents d 
                  JOIN users u ON d.user_id = u.id 
                  LEFT JOIN users r ON d.reviewed_by = r.id 
                  WHERE d.status = 'rejected'
                  ORDER BY d.uploaded_at DESC";
        $res = $mysqli->query($query);
        if ($res) while($row = $res->fetch_assoc()) {
            $submissions[] = $row;
            if ($row['status'] === 'pending') $pending_count++;
        }
    }
} else {
    // If student, only show their own documents
    $stmt = $mysqli->prepare("SELECT d.id, d.file_name as document_name, d.file_path, d.uploaded_at as submitted_at, d.status, d.feedback, d.document_type, d.reviewed_at, r.first_name AS reviewer_first, r.last_name AS reviewer_last, u.first_name, u.middle_name, u.last_name, u.student_id, u.course, u.year_level, u.section 
                              FROM documents d 
                              JOIN users u ON d.user_id = u.id 
                  LEFT JOIN users r ON d.reviewed_by = r.id 
                              WHERE d.user_id = ? 
                              ORDER BY d.uploaded_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) while($row = $res->fetch_assoc()) $submissions[] = $row;
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submissions - <?= ucfirst($role) ?> Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body.student-mode { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; display: flex; min-height: 100vh; }
        body.student-mode .sidebar { width: 280px; background: var(--sidebar-bg); border-right: 1px solid #e2e8f0; padding: 0; display: flex; flex-direction: column; position: fixed; height: 100vh; overflow-y: auto; }
        body.student-mode .sidebar-header { padding: 32px 24px 0 24px; }
        body.student-mode .main { flex: 1; margin-left: 280px; padding: 32px 40px; }
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
        body.student-mode {
            --primary-color: #1a365d;
            --bg-color: #f1f5f9;
            --sidebar-bg: #ffffff;
            background: var(--bg-color);
        }
        
        /* SIDEBAR */
        body:not(.student-mode) .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg, #0f172a);
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .logo-box-reg { width: 48px; height: 48px; background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .sidebar-brand { font-size: 1.15rem; font-weight: 700; color: white; }
        .sidebar-nav { flex: 1; padding: 20px 12px; }
        .nav-section-label { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; padding: 0 8px; margin: 16px 0 8px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: var(--text-secondary); text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.06); color: var(--text-primary); }
        .nav-link.active { background: rgba(201,162,39,0.15); color: var(--accent-gold); border-left: 3px solid var(--accent-gold); padding-left: 9px; }
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 20px; min-width: 20px; text-align: center; }
        
        /* Student specific overrides */
        body.student-mode .nav-menu { flex: 1; padding: 0 24px 24px 24px; }
        body.student-mode .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: #475569; font-weight: 500; margin-bottom: 8px; font-size: 16px; text-decoration: none; transition: all 0.2s; cursor: pointer; }
        body.student-mode .nav-link:not(.active):hover { background: #f1f5f9; color: #1e293b; }
        body.student-mode .nav-link i { width: 24px; font-size: 18px; text-align: center; }
        body.student-mode .nav-link.active { background: var(--primary-color); color: white; border-left: none; padding-left: 16px; }
        body.student-mode .nav-link.active i { color: white; }
        body.student-mode .logo-area { margin-bottom: 32px; display: flex; align-items: center; gap: 8px; }
        body.student-mode .logo-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: transparent; padding: 2px; }
        body.student-mode .student-info { background: #f8fafc; border-radius: 16px; padding: 16px; margin-bottom: 24px; border: 1px solid #e2e8f0; }
        body.student-mode .student-name { font-weight: 700; font-size: 16px; color: #0f172a; }
        body.student-mode .student-id { font-size: 13px; color: #64748b; margin-top: 4px; }

        .sidebar-bottom { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.05); }
        .logout-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #ef4444; text-decoration: none; border-radius: 8px; font-weight: 500; }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }
        body.student-mode .sidebar-bottom { padding: 16px 24px; border-top: 1px solid #e2e8f0; margin-top: auto; }
        body.student-mode .logout-btn { display: flex; align-items: center; gap: 12px; color: #dc2626; font-weight: 500; text-decoration: none; border-radius: 12px; padding: 12px 16px; transition: background 0.2s; font-size: 16px; cursor: pointer; }
        body.student-mode .logout-btn i { width: 24px; font-size: 18px; text-align: center; }
        body.student-mode .logout-btn:hover { background: #fef2f2; }

        /* MAIN */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        body.student-mode .main { margin-left: 280px; padding: 32px 40px; }

        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        body.student-mode .topbar { margin-bottom: 32px; border-bottom: none; padding: 0; }
        
        .search-bar { display: flex; align-items: center; gap: 8px; background: #1e293b; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 9px 16px; min-width: 260px; }
        body.student-mode .search-bar { background: white; border: 1px solid #e2e8f0; }
        body.student-mode .search-bar input { color: #0f172a; }

        .search-bar input { background: none; border: none; outline: none; color: white; font-size: 0.85rem; width: 100%; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .topbar-icon-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #1e293b; border-radius: 8px; color: var(--text-secondary); cursor: pointer; border: none; }
        body.student-mode .topbar-icon-btn { background: white; border: 1px solid #e2e8f0; color: #64748b; }

        .user-profile { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 36px; height: 36px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }
        body.student-mode .user-avatar { width: 44px; height: 44px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #1e293b; }

        body.student-mode .topbar-left h1 { font-size: 24px; font-weight: 700; color: #0f172a; }
        body.student-mode .topbar-left p { color: #64748b; font-size: 14px; margin-top: 4px; }

        /* CONTENT */
        .content { padding: 28px 32px; overflow-y: auto; }
        body.student-mode .content { padding: 0; }

        .table-card { background: var(--card-bg); border-radius: 14px; padding: 24px; border: 1px solid rgba(255,255,255,0.04); }
        body.student-mode .table-card { background: white; border: 1px solid #e2e8f0; color: #0f172a; }
        body.student-mode table thead th { color: #64748b; border-bottom: 1px solid #f1f5f9; }
        body.student-mode tbody td { border-bottom: 1px solid #f1f5f9; color: #1e293b; }

        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        table { width: 100%; border-collapse: collapse; }
        thead th { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; padding: 0 0 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        tbody td { padding: 14px 0; font-size: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.03); }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-badge.approved { background: rgba(16,185,129,0.15); color: #10b981; }
        .status-badge.rejected { background: rgba(239,68,68,0.15); color: #ef4444; }
        .status-badge.pending { background: rgba(245,158,11,0.15); color: #f59e0b; }

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
        body.student-mode .mobile-toggle { background: var(--primary-color); }
        .mobile-toggle i { font-size: 20px; }

        @media (max-width: 1024px) {
            body:not(.student-mode) .sidebar, body.student-mode .sidebar {
                position: fixed;
                left: 0; top: 0; height: 100vh;
                z-index: 1050;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active { transform: translateX(0) !important; }
            body:not(.student-mode) .sidebar .sidebar-logo { padding-top: 65px; }
            body.student-mode .sidebar .sidebar-header { padding-top: 65px; }
            .mobile-toggle { display: block; }
            body:not(.student-mode) .topbar, body.student-mode .topbar {
                padding: 15px;
                padding-left: 65px;
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
                margin-bottom: 0;
            }
            .topbar-right { justify-content: space-between; }
            .search-bar { min-width: unset; flex: 1; }
            body:not(.student-mode) .content, body.student-mode .content { padding: 20px; }
            body:not(.student-mode) .main, body.student-mode .main { margin-left: 0; padding: 0; }
            .mobile-topbar-logo { display: block !important; }
        }
        /* Responsive Table */
        @media (max-width: 768px) {
            .table-responsive {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }
            .student-table, .table-card table {
                display: block !important;
                width: 100% !important;
                white-space: normal !important;
                table-layout: fixed !important;
            }
            .student-table thead, .table-card table thead { display: none !important; }
            .student-table tbody, .table-card table tbody {
                display: block !important;
                width: 100% !important;
            }
            .student-table tr, .table-card table tr {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                margin-bottom: 16px !important;
                background: var(--card-bg);
                border: 1px solid rgba(255,255,255,0.06);
                border-radius: 14px !important;
                padding: 14px 16px !important;
                box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
                overflow: hidden !important;
            }
            body.student-mode .table-card table tr {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
            }
            .student-table td, .table-card table td { 
                display: flex !important; 
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 6px !important;
                border: none !important;
                padding: 10px 0 !important; 
                border-bottom: 1px solid rgba(255,255,255,0.04) !important;
                white-space: normal !important;
                word-break: break-all !important;
                overflow-wrap: anywhere !important;
                width: 100% !important;
                box-sizing: border-box !important;
                min-width: 0 !important;
            }
            body.student-mode tbody td {
                border-bottom: 1px solid #f1f5f9 !important;
            }
            .student-table td:last-child, .table-card table td:last-child {
                border-bottom: none !important;
                padding-bottom: 2px !important;
            }
            .student-table td::before, .table-card table td::before {
                content: attr(data-label);
                font-size: 0.68rem !important;
                font-weight: 700 !important;
                letter-spacing: 0.07em !important;
                text-transform: uppercase !important;
                color: var(--text-muted) !important;
                display: block !important;
                margin-bottom: 2px !important;
            }
            .student-table td[data-label=""]::before,
            .table-card table td[data-label=""]::before { content: none !important; }
            .student-table td, .table-card table td { padding-right: 0 !important; }
            .student-table td > *, .table-card table td > * { min-width: 0; }
            
            .cell-student-name { text-align: left !important; }
            .cell-student-name .stu { display: block; }
            .cell-student-name .stu small { display: block; margin-top: 2px; }
            .cell-student-id { width: 100% !important; }
            .cell-student-id .sid { display: inline-block; align-self: flex-start; }
            .note-modal-card { padding: 22px 18px 18px; }

            .row-actions {
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 8px !important;
                margin-top: 4px !important;
            }
            .row-actions .action-btn {
                width: 100% !important;
                min-height: 42px !important;
                padding: 10px 16px !important;
                font-size: 0.85rem !important;
                font-weight: 600 !important;
                border-radius: 9px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 8px !important;
                box-sizing: border-box !important;
                text-align: center !important;
                white-space: nowrap !important;
            }
            .row-actions .btn-view {
                background: #2563eb !important;
                color: #ffffff !important;
                box-shadow: 0 2px 6px rgba(37,99,235,0.2) !important;
            }
        }
        @media (max-width: 480px) {
            .table-card { padding: 12px 10px !important; }
            body:not(.student-mode) .content, body.student-mode .content { padding: 12px 8px !important; }
        }

        .action-btn {
            padding: 6px 12px; border-radius: 6px; border: none; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
        }
        .btn-approve { background: #10b981; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-view { background: #3b82f6; color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .action-btn:hover { opacity: 0.8; }

        /* Back Button */
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
        }
        .back-button:hover { background: rgba(255,255,255,0.08); color: white; border-color: var(--accent-gold); }
        body.student-mode .back-button { background: transparent; color: var(--primary-color); border: 1px solid #e2e8f0; }
        body.student-mode .back-button:hover { background: #f1f5f9; }
        
        .reject-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        .reject-modal-content {
            background: transparent;
            padding: 24px;
            border-radius: 14px;
            width: 90%;
            max-width: 400px;
            color: #1e293b;
        }
        /* Result of the last review action */
        .flash-banner {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 13px 16px; border-radius: 10px; margin-bottom: 18px;
            font-size: 0.88rem; line-height: 1.45; border-left: 4px solid;
            overflow-wrap: anywhere;
        }
        .flash-banner i { margin-top: 2px; }
        .flash-banner.flash-success { background: rgba(16,185,129,0.12); border-color: #10b981; color: #6ee7b7; }
        .flash-banner.flash-error   { background: rgba(239,68,68,0.12);  border-color: #ef4444; color: #fca5a5; }
        .flash-banner.flash-info    { background: rgba(59,130,246,0.12); border-color: #3b82f6; color: #93c5fd; }
        .flash-banner .flash-close { margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 1rem; opacity: 0.7; }
        .flash-banner .flash-close:hover { opacity: 1; }
        body.student-mode .flash-banner.flash-success { background: #ecfdf5; color: #047857; }
        body.student-mode .flash-banner.flash-error   { background: #fef2f2; color: #b91c1c; }
        body.student-mode .flash-banner.flash-info    { background: #eff6ff; color: #1d4ed8; }
        /* ── Student ID and Student are separate columns, with real space between them ── */
        thead th { padding-right: 20px; }
        tbody td { padding-right: 20px; }
        thead th:last-child, tbody td:last-child { padding-right: 0; }

        .cell-student-id { width: 1%; white-space: nowrap; vertical-align: middle; }
        .cell-student-id .sid {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 6px;
            background: rgba(148,163,184,0.14);
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            color: var(--text-secondary, #94a3b8);
        }
        body.student-mode .cell-student-id .sid { background: #f1f5f9; color: #475569; }
        .cell-student-name { font-weight: 600; white-space: normal; }
        .cell-student-name small { font-weight: 500; }
        .cell-student-name .stu small { display: block; margin-top: 2px; font-size: 0.75rem; }

        /* ── A rejected badge opens the registrar's note ── */
        .status-badge-btn {
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: filter 0.15s;
        }
        .status-badge-btn:hover { filter: brightness(1.25); }
        .status-badge-btn i { font-size: 0.7rem; opacity: 0.8; }

        .note-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
            z-index: 2200;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .note-modal.open { display: flex; }
        .note-modal-card {
            position: relative;
            width: 100%;
            max-width: 440px;
            max-height: 88vh;
            overflow-y: auto;
            padding: 26px 24px 22px;
            border-radius: 16px;
            background: var(--main-bg, #1e293b);
            color: var(--text-primary, #f1f5f9);
            box-shadow: 0 20px 50px rgba(0,0,0,0.45);
            text-align: center;
            animation: noteModalIn 0.22s ease;
        }
        body.student-mode .note-modal-card { background: #ffffff; color: #0f172a; }
        @keyframes noteModalIn {
            from { opacity: 0; transform: translateY(14px) scale(0.97); }
            to   { opacity: 1; transform: none; }
        }
        .note-modal-close {
            position: absolute; top: 12px; right: 12px;
            width: 32px; height: 32px;
            border: none; border-radius: 8px;
            background: transparent; color: var(--text-muted, #94a3b8);
            cursor: pointer; font-size: 0.95rem;
        }
        .note-modal-close:hover { background: rgba(148,163,184,0.15); }
        .note-modal-icon {
            width: 56px; height: 56px; margin: 0 auto 14px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
            background: rgba(239,68,68,0.15); color: #ef4444;
            font-size: 24px;
        }
        .note-modal-title { margin: 0 0 4px; font-size: 1.05rem; font-weight: 700; }
        .note-modal-doc {
            display: inline-block; margin: 10px 0 2px;
            padding: 5px 12px; border-radius: 20px;
            background: rgba(148,163,184,0.14);
            font-size: 0.82rem; font-weight: 600;
            overflow-wrap: anywhere;
        }
        body.student-mode .note-modal-doc { background: #f1f5f9; }
        .note-modal-student { margin-top: 8px; font-size: 0.8rem; color: var(--text-muted, #94a3b8); overflow-wrap: anywhere; }
        .note-modal-body {
            margin: 16px 0 0;
            padding: 14px 16px;
            border-radius: 12px;
            border-left: 4px solid #ef4444;
            background: rgba(239,68,68,0.09);
            font-size: 0.9rem; line-height: 1.55; text-align: left;
            white-space: pre-wrap; overflow-wrap: anywhere;
        }
        body.student-mode .note-modal-body { background: #fef2f2; color: #7f1d1d; }
        .note-modal-meta { margin-top: 12px; font-size: 0.74rem; color: var(--text-muted, #94a3b8); }
        .note-modal-meta:empty { display: none; }
        .note-modal-btn {
            margin-top: 18px; width: 100%;
            padding: 11px 16px; border: none; border-radius: 10px;
            background: #ef4444; color: #fff;
            font-family: inherit; font-size: 0.9rem; font-weight: 600; cursor: pointer;
        }
        .note-modal-btn:hover { background: #dc2626; }
        .action-btn { display: inline-flex; align-items: center; gap: 5px; }
        .row-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .reject-modal-content { max-height: 90vh; overflow-y: auto; }
        .reject-doc-name {
            display: block; margin-bottom: 14px; padding: 8px 12px; border-radius: 8px;
            background: rgba(148,163,184,0.12); font-size: 0.85rem; font-weight: 600;
            overflow-wrap: anywhere;
        }
        .reject-note-counter { font-size: 0.72rem; color: var(--text-muted); margin: -8px 0 14px; display: block; }
        .reject-note-counter.too-short { color: #ef4444; }
        @media (max-width: 640px) {
            .row-actions { width: 100%; }
            .row-actions .action-btn { flex: 1 1 auto; justify-content: center; padding: 9px 12px; font-size: 0.8rem; }
            .reject-modal-content { width: 94%; padding: 18px; }
            .reject-modal-actions { flex-direction: column-reverse; }
            .reject-modal-actions button { width: 100%; }
        }
        /* Filters above a table wrap instead of forcing the page wider than the screen */
        .table-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .table-filters > * { min-width: 0; }
        @media (max-width: 900px) {
            .table-filters { width: 100%; }
            .table-filters > * { flex: 1 1 150px; }
        }
        @media (max-width: 520px) {
            .table-filters > * { flex: 1 1 100%; }
            .table-filters button { justify-content: center; }
        }
    </style>
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <script src="search_helper.js?v=3"></script>
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="doc_viewer.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body class="<?= $role === 'student' ? 'student-mode' : '' ?>">
    <!-- Mobile Menu Button — students keep the floating button, registrars get the app bar -->
    <?php if ($role === 'student'): ?>
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <?php else: ?>
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="registrar_dashboard.php" class="mobile-appbar-brand">
            <img src="logo.jpg" alt="">
            <span><?= ucfirst($role) ?></span>
        </a>
    </header>
    <?php endif; ?>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>
    <?php if ($role === 'student'): ?>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-area">
                <div class="logo-icon">
                    <img src="logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="logo-text" style="font-weight:700; font-size:20px; color:#1e293b;">Student Portal</div>
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
        <nav class="nav-menu">
            <a href="student_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> <span>Overview</span></a>
            <a href="my_document.php" class="nav-link"><i class="fas fa-file-alt"></i> <span>My Documents</span></a>
            <a href="student_calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a>
            <a href="submissions.php" class="nav-link active"><i class="fas fa-paper-plane"></i> <span>Submissions</span></a>
            <a href="student_notifications.php" class="nav-link"><i class="fas fa-bell"></i> <span>Notifications</span></a>
            <a href="checklist.php" class="nav-link"><i class="fas fa-list-check"></i> <span>Document Checklist</span></a>
            <a href="student_request.php" class="nav-link"><i class="fas fa-file-signature"></i> <span>Requests</span></a>
            <a href="student_settings.php" class="nav-link">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </div>
    </div>
    <div class="main">
    <?php else: /* Registrar Sidebar */ ?>
        <div class="sidebar">
            <div class="sidebar-logo">
                <div class="logo-box-reg">
                    <img src="logo.jpg" alt="Logo"  style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <span class="sidebar-brand"><?= ucfirst($role) ?></span>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section-label">Main Menu</div>
                <a href="registrar_dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <a href="analytics.php" class="nav-link"><i class="fas fa-chart-bar"></i> Analytics</a>
                <a href="calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendar</a>
                <?= submissionsNavLink($mysqli) ?>
                <a href="reject.php" class="nav-link active"><i class="fas fa-times-circle"></i> Rejected Documents</a>
                <?= requestsNavLink($mysqli) ?>
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-bell"></i> Notifications <?= ($pending_count > 0) ? '<span class="nav-badge">'.$pending_count.'</span>' : '' ?>
                </a>
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
    <?php endif; ?>

        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1>Rejected Documents</h1>
                    <p><?= $role === 'registrar' ? 'View documents that have been rejected' : 'View your rejected documents' ?></p>
                </div>
            </div>
            <div class="topbar-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search submissions...">
                </div>
                <?php if($role === 'registrar'): ?><button class="topbar-icon-btn"><i class="fas fa-envelope"></i></button><?php endif; ?>
                <div class="user-profile">
                    <div class="user-info">
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <?php if (isset($_SESSION['flash'])): ?>
                <?php
                    $flash_type = $_SESSION['flash_type'] ?? 'info';
                    $flash_class = in_array($flash_type, ['success', 'error'], true) ? 'flash-' . $flash_type : 'flash-info';
                    $flash_icon = $flash_type === 'success' ? 'fa-circle-check' : ($flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info');
                ?>
                <div class="flash-banner <?= $flash_class ?>" role="status">
                    <i class="fas <?= $flash_icon ?>"></i>
                    <span><?= htmlspecialchars($_SESSION['flash']) ?></span>
                    <button type="button" class="flash-close" onclick="this.parentElement.remove()" aria-label="Dismiss"><i class="fas fa-times"></i></button>
                </div>
                <?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
            <?php endif; ?>

            <div id="submissions-section">
            <div class="table-card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h2>All Rejected</h2>
                    <?php if ($role === 'registrar'): ?>
                    <div class="table-filters">
                        <!-- Download As One removed for reject page -->
                        <select id="docTypeFilter" style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--main-bg); color: var(--text-primary); font-family: inherit;">
                            <option value="">All Documents</option>
                            <?php 
                                $unique_doc_types = [];
                                
                                // Fetch all document types from database
                                $dt_query = $mysqli->query("SELECT name FROM document_types ORDER BY name ASC");
                                if ($dt_query) {
                                    while ($dt_row = $dt_query->fetch_assoc()) {
                                        if (!empty($dt_row['name']) && !in_array($dt_row['name'], $unique_doc_types)) {
                                            $unique_doc_types[] = $dt_row['name'];
                                        }
                                    }
                                }
                                
                                // Also include any document types from existing submissions
                                foreach ($submissions as $sub) {
                                    $dtype = $sub['document_type'] ?? 'General';
                                    if (!in_array($dtype, $unique_doc_types)) {
                                        $unique_doc_types[] = $dtype;
                                    }
                                }
                                sort($unique_doc_types);
                                foreach ($unique_doc_types as $type): 
                            ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="courseFilter" style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--main-bg); color: var(--text-primary); font-family: inherit;">
                            <option value="">All Programs</option>
                            <?php if (empty($user_institution) || $user_institution === 'ISAP'): ?>
                                <option value="BS Accountancy">BS Accountancy</option>
                                <option value="BS Business administration">BS Business administration</option>
                                <option value="BS Computer engineering">BS Computer engineering</option>
                                <option value="BS Criminology">BS Criminology</option>
                                <option value="BS Custom Administration">BS Custom Administration</option>
                                <option value="BS Hospitality management">BS Hospitality management</option>
                                <option value="BS Information Technology">BS Information Technology</option>
                                <option value="BS Physical education">BS Physical education</option>
                                <option value="BS Psychology">BS Psychology</option>
                                <option value="BS Secondary education">BS Secondary education</option>
                                <option value="BS Social work">BS Social work</option>
                                <option value="BS Tourism management">BS Tourism management</option>
                            <?php endif; ?>
                            <?php if (empty($user_institution) || $user_institution === 'MCNP'): ?>
                                <option value="BS Medical laboratory science">BS Medical laboratory science</option>
                                <option value="BS Midwifery">BS Midwifery</option>
                                <option value="BS Nursing">BS Nursing</option>
                                <option value="BS Pharmacy">BS Pharmacy</option>
                                <option value="BS Physical therapy">BS Physical therapy</option>
                                <option value="BS Radiologic technology">BS Radiologic technology</option>
                                <option value="Dental technology">Dental technology</option>
                            <?php endif; ?>
                        </select>
                        <select id="yearFilter" style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--main-bg); color: var(--text-primary); font-family: inherit;">
                            <option value="">All Years</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                        <select id="sectionFilter" style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--main-bg); color: var(--text-primary); font-family: inherit;">
                            <option value="">All Sections</option>
                            <option value="A">Section A</option>
                            <option value="B">Section B</option>
                            <option value="C">Section C</option>
                            <option value="D">Section D</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <table id="submissionsTable">
                    <thead>
                        <tr>
                            <!-- Checkbox header removed -->
                            <th>Student ID</th>
                            <th>Student</th>
                            <th>Document Type</th>
                            <th>Document</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($submissions)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                                    No submissions found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $sub): ?>
                                <tr data-course="<?= htmlspecialchars($sub['course'] ?? '') ?>" data-year="<?= htmlspecialchars($sub['year_level'] ?? '') ?>" data-section="<?= htmlspecialchars($sub['section'] ?? '') ?>" data-doctype="<?= htmlspecialchars($sub['document_type'] ?? 'General') ?>">
                                    <!-- Checkbox cell removed -->
                                    <td data-label="Student ID" class="cell-student-id"><span class="sid"><?= htmlspecialchars(formatStudentId($sub['student_id'] ?? '')) ?></span></td>
                                    <td data-label="Student" class="cell-student-name">
                                        <span class="stu">
                                            <?= htmlspecialchars(formatPersonNameRow($sub)) ?>
                                            <?php
                                                $sub_year = $sub['year_level'] ?? '';
                                                $sub_section = $sub['section'] ?? '';
                                                $sub_disp = !empty($sub_year) ? substr($sub_year, 0, 1) . $sub_section : '';
                                                if (!empty($sub['course']) || !empty($sub_disp)):
                                            ?>
                                                <small style="color: var(--text-muted);"><?= htmlspecialchars(trim($sub['course'] ?? '')) ?><?= $sub_disp !== '' ? ' - ' . htmlspecialchars($sub_disp) : '' ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td data-label="Document Type" style="font-weight: 600;"><?= htmlspecialchars($sub['document_type'] ?? 'General') ?></td>
                                    <td data-label="Document"><span class="doc-file-name" style="word-break: break-all; overflow-wrap: anywhere;"><?= htmlspecialchars($sub['document_name']) ?></span></td>
                                    <td data-label="Date">
                                        <?php 
                                            $date = !empty($sub['submitted_at']) ? date('M d, Y', strtotime($sub['submitted_at'])) : 'N/A';
                                            $time = !empty($sub['submitted_at']) ? date('g:i A', strtotime($sub['submitted_at'])) : '';
                                        ?>
                                        <div class="cell-date-wrap">
                                            <span><?= $date ?></span>
                                            <?php if ($time): ?>
                                                <small style="display:block; font-size:0.75rem; color:var(--text-muted); margin-top:2px;"><i class="far fa-clock" style="font-size:0.7rem; margin-right:3px;"></i><?= $time ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php
                                        $sub_status    = 'rejected';
                                        $feedback_text = trim($sub['feedback'] ?? '');
                                        $reviewer_name = trim(($sub['reviewer_first'] ?? '') . ' ' . ($sub['reviewer_last'] ?? ''));
                                    ?>
                                    <?php
                                        $badge_words = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
                                        $badge_word  = $badge_words[$sub_status] ?? ucfirst($sub_status);
                                        $review_meta = '';
                                        if ($reviewer_name !== '' || !empty($sub['reviewed_at'])) {
                                            $review_meta = 'Reviewed'
                                                . ($reviewer_name !== '' ? ' by ' . $reviewer_name : '')
                                                . (!empty($sub['reviewed_at']) ? ' on ' . date('M d, Y \a\t g:i A', strtotime($sub['reviewed_at'])) : '');
                                        }
                                    ?>
                                    <td data-label="Status">
                                        <?php if ($sub_status === 'rejected'): ?>
                                            <button type="button" class="status-badge rejected status-badge-btn"
                                                    data-doc="<?= htmlspecialchars($sub['document_type'] ?: $sub['document_name']) ?>"
                                                    data-student="<?= htmlspecialchars(formatStudentId($sub['student_id'] ?? '') . ' — ' . formatPersonNameRow($sub)) ?>"
                                                    data-note="<?= htmlspecialchars($feedback_text !== '' ? $feedback_text : 'No reason was recorded for this rejection.') ?>"
                                                    data-meta="<?= htmlspecialchars($review_meta) ?>"
                                                    onclick="openNoteModal(this)"
                                                    title="See why this document was not approved">
                                                Rejected <i class="fas fa-circle-info"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="status-badge <?= $sub_status ?>"><?= htmlspecialchars($badge_word) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Action">
                                        <div class="row-actions">
                                            <button type="button" onclick="openPreview('<?= addslashes(htmlspecialchars($sub['file_path'])) ?>', '<?= addslashes(htmlspecialchars($sub['document_name'])) ?>')" class="action-btn btn-view" title="View Document"><i class="fas fa-eye"></i> View</button>
                                            <?php if ($role === 'registrar'): ?>
                                                <?php $doc_label = htmlspecialchars(json_encode($sub['document_type'] ?: $sub['document_name']), ENT_QUOTES); ?>
                                                <button class="action-btn btn-approve" onclick="handleStatus(<?= $sub['id'] ?>, 'approved', <?= $doc_label ?>)" title="Accept this document after all"><i class="fas fa-check"></i> Accept</button>
                                                <button class="action-btn btn-reject" onclick="handleStatus(<?= $sub['id'] ?>, 'rejected', <?= $doc_label ?>)" title="Rewrite the note sent to the student"><i class="fas fa-pen"></i> Edit note</button>
                                            <?php endif; ?>
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
    </div>
    
    <!-- Modal Preview Panel -->

    <!-- Why a document was not approved — the registrar's note -->
    <div id="noteModal" class="note-modal" role="dialog" aria-modal="true" aria-labelledby="noteModalTitle" onclick="if (event.target === this) closeNoteModal()">
        <div class="note-modal-card">
            <button type="button" class="note-modal-close" onclick="closeNoteModal()" aria-label="Close"><i class="fas fa-times"></i></button>
            <div class="note-modal-icon"><i class="fas fa-circle-exclamation"></i></div>
            <h3 class="note-modal-title" id="noteModalTitle">Why this was not approved</h3>
            <div class="note-modal-doc" id="noteModalDoc"></div>
            <div class="note-modal-student" id="noteModalStudent"></div>
            <p class="note-modal-body" id="noteModalBody"></p>
            <div class="note-modal-meta" id="noteModalMeta"></div>
            <button type="button" class="note-modal-btn" onclick="closeNoteModal()">Close</button>
        </div>
    </div>

    <!-- Reject Document Modal — the note written here is what the student receives -->
    <div id="rejectModal" class="reject-modal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
        <div class="reject-modal-content">
            <h3 id="rejectModalTitle">Note for the student</h3>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 10px;">Write why this document was not approved. The student gets it as a notification and an email, and it is shown on their checklist.</p>
            <span class="reject-doc-name" id="rejectDocName"></span>
            <form id="rejectForm" action="process_document_status.php" method="POST">
                <input type="hidden" name="doc_id" id="rejectDocId" value="">
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" name="redirect_to" value="reject.php">
                <textarea name="feedback" id="rejectFeedback" placeholder="Example: The birth certificate photo is blurry and the name cannot be read. Please retake it in good lighting." required minlength="5" maxlength="500" style="width: 100%; height: 110px; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.2); color: #f1f5f9; resize: vertical; margin-bottom: 15px; font-family: inherit;"></textarea>
                <span class="reject-note-counter" id="rejectCounter">0 / 500 characters</span>
                <div class="reject-modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-cancel" onclick="closeRejectModal()" style="padding: 8px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); background: transparent; color: #94a3b8; cursor: pointer; font-weight: 500;">Cancel</button>
                    <button type="submit" class="btn-confirm" id="rejectSubmitBtn" style="padding: 8px 16px; border-radius: 8px; border: none; background: #ef4444; color: white; cursor: pointer; font-weight: 500;">Send note</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // The note lives in data-* attributes on the badge, never in inline JS.
        function openNoteModal(badge) {
            const modal = document.getElementById('noteModal');
            if (!modal) return;

            document.getElementById('noteModalDoc').textContent = badge.dataset.doc || 'Document';
            document.getElementById('noteModalBody').textContent = badge.dataset.note || '';

            const student = document.getElementById('noteModalStudent');
            student.textContent = badge.dataset.student || '';
            student.hidden = !badge.dataset.student;

            document.getElementById('noteModalMeta').textContent = badge.dataset.meta || '';

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
            modal.querySelector('.note-modal-btn').focus();
        }

        function closeNoteModal() {
            const modal = document.getElementById('noteModal');
            if (!modal) return;
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const modal = document.getElementById('noteModal');
            if (modal && modal.classList.contains('open')) closeNoteModal();
        });
    </script>

    <script>
        // Download selected documents
        function toggleAllDocs(source) {
            const checkboxes = document.querySelectorAll('.doc-checkbox');
            checkboxes.forEach(cb => {
                // Only select visible rows
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = source.checked;
                }
            });
        }

        function downloadSelectedDocs() {
            const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
            if (checkboxes.length === 0) {
                uiAlert({ title: 'Nothing selected', message: 'Please select at least one document to download.', tone: 'info' });
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_merged_pdf.php';
            
            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'doc_ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
            setTimeout(() => document.body.removeChild(form), 100);
        }

        // Filtering and Search functionality
        // The search box goes through search_helper.js (every word, any order,
        // no buttons); the dropdowns are exact matches on the row's data-*.
        function applyFilters() {
            let docTypeFilter = document.getElementById('docTypeFilter') ? document.getElementById('docTypeFilter').value.toLowerCase() : '';
            let courseFilter = document.getElementById('courseFilter') ? document.getElementById('courseFilter').value.toLowerCase() : '';
            let yearFilter = document.getElementById('yearFilter') ? document.getElementById('yearFilter').value.toLowerCase() : '';
            let sectionFilter = document.getElementById('sectionFilter') ? document.getElementById('sectionFilter').value.toLowerCase() : '';
            
            SearchKit.filterTables(document.getElementById('searchInput'), [document.getElementById('submissionsTable')], row => {
                let rowDocType = (row.getAttribute('data-doctype') || '').toLowerCase();
                let rowCourse = (row.getAttribute('data-course') || '').toLowerCase();
                let rowYear = (row.getAttribute('data-year') || '').toLowerCase();
                let rowSection = (row.getAttribute('data-section') || '').toLowerCase();
                return (docTypeFilter === '' || rowDocType === docTypeFilter)
                    && (courseFilter === '' || rowCourse === courseFilter)
                    && (yearFilter === '' || rowYear === yearFilter)
                    && (sectionFilter === '' || rowSection === sectionFilter);
            }, !!(docTypeFilter || courseFilter || yearFilter || sectionFilter));
        }

        SearchKit.bind(document.getElementById('searchInput'), applyFilters);
        if (document.getElementById('docTypeFilter')) document.getElementById('docTypeFilter').addEventListener('change', applyFilters);
        if (document.getElementById('courseFilter')) document.getElementById('courseFilter').addEventListener('change', applyFilters);
        if (document.getElementById('yearFilter')) document.getElementById('yearFilter').addEventListener('change', applyFilters);
        if (document.getElementById('sectionFilter')) document.getElementById('sectionFilter').addEventListener('change', applyFilters);

        /* The document preview is the shared DocViewer (doc_viewer.js + doc_viewer.css),
           the same modal checklist.php and my_document.php use: it renders PDFs with
           PDF.js, pinch-zooms on a phone and keeps its close button reachable. Its
           openPreview(path, title) / closePreview() replace the copies this page
           used to carry, so every call site below is unchanged. */

        // The registrar decides every document; a rejection always carries a written note.
        function handleStatus(id, newStatus, docName) {
            if (newStatus === 'rejected') {
                openRejectModal(id, docName);
                return;
            }
            uiConfirm({
                title: 'Approve document',
                message: 'Accept "' + (docName || 'this document') + '" after all? The student will be notified that it was approved.',
                confirmLabel: 'Yes, approve',
                tone: 'success'
            }).then(ok => {
                if (!ok) return;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process_document_status.php';
                form.innerHTML = `<input type="hidden" name="doc_id" value="${id}"><input type="hidden" name="status" value="${newStatus}"><input type="hidden" name="redirect_to" value="reject.php">`;
                document.body.appendChild(form);
                form.submit();
            });
        }

        function openRejectModal(id, docName) {
            const modal = document.getElementById('rejectModal');
            const note = document.getElementById('rejectFeedback');
            const nameBox = document.getElementById('rejectDocName');

            document.getElementById('rejectDocId').value = id;
            if (nameBox) {
                nameBox.textContent = docName || 'Selected document';
                nameBox.hidden = !docName;
            }
            if (note) {
                note.value = '';
                updateRejectCounter();
            }

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            if (note) setTimeout(() => note.focus(), 50);
        }

        function updateRejectCounter() {
            const note = document.getElementById('rejectFeedback');
            const counter = document.getElementById('rejectCounter');
            const submit = document.getElementById('rejectSubmitBtn');
            if (!note || !counter) return;
            const len = note.value.trim().length;
            counter.textContent = `${note.value.length} / 500 characters`;
            counter.classList.toggle('too-short', len > 0 && len < 5);
            if (submit) submit.disabled = len < 5;
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const note = document.getElementById('rejectFeedback');
            if (note) {
                note.addEventListener('input', updateRejectCounter);
                updateRejectCounter();
            }
            const rejectForm = document.getElementById('rejectForm');
            if (rejectForm) {
                rejectForm.addEventListener('submit', e => {
                    const value = note ? note.value.trim() : '';
                    if (value.length < 5) {
                        e.preventDefault();
                        updateRejectCounter();
                        if (note) note.focus();
                        return;
                    }
                    // Send the trimmed note, and stop a double submit
                    if (note) note.value = value;
                    const submit = document.getElementById('rejectSubmitBtn');
                    if (submit) {
                        submit.disabled = true;
                        submit.textContent = 'Sending...';
                    }
                });
            }
        });

        // Escape closes the reject modal
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const modal = document.getElementById('rejectModal');
            if (modal && modal.style.display === 'flex') closeRejectModal();
        });

        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }
        function closeSidebar() {
            document.querySelector('.sidebar').classList.remove('active');
            document.body.classList.remove('sidebar-open');
        }

        // The colours the user picked in Settings, the same on every page.
        document.addEventListener('DOMContentLoaded', () => {
            if (document.body.classList.contains('student-mode')) {
                const savedColor = localStorage.getItem('student-theme-color');
                if (savedColor) document.body.style.setProperty('--primary-color', savedColor);
                const savedBgColor = localStorage.getItem('student-bg-color');
                if (savedBgColor) document.body.style.setProperty('--bg-color', savedBgColor);
                const savedSidebarBgColor = localStorage.getItem('student-sidebar-bg');
                if (savedSidebarBgColor) document.body.style.setProperty('--sidebar-bg', savedSidebarBgColor);
            } else {
                const savedTheme = localStorage.getItem('registrar-theme-color');
                if (savedTheme) document.body.style.setProperty('--accent-gold', savedTheme);
                const savedBg = localStorage.getItem('registrar-bg-color');
                if (savedBg) document.body.style.setProperty('--main-bg', savedBg);
                const savedSidebarBg = localStorage.getItem('registrar-sidebar-bg');
                if (savedSidebarBg) document.body.style.setProperty('--sidebar-bg', savedSidebarBg);
            }
        });
    </script>
    <script src="doc_viewer.js?v=1"></script>
</body>
</html>
