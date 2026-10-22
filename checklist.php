<?php
session_start();
require_once 'db.php';
require_once 'upload_config.php';

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php?tab=login");
    exit;
}

// Fetch user details from the database
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

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

$user_student_id = $user_data['student_id'] ?? '';
$first_name = $user_data['first_name'] ?? 'S';

$full_name_parts = [];
if (!empty($first_name)) $full_name_parts[] = $first_name;
if (!empty($middle_name)) $full_name_parts[] = $middle_name;
if (!empty($last_name)) $full_name_parts[] = $last_name;
$full_name = implode(' ', $full_name_parts);

if (empty($full_name)) {
    $full_name = $user_email;
}

// Get greeting based on current time (optional, but consistent with dashboard)
date_default_timezone_set('Asia/Manila');
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
    <title>Document Checklist - Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #1a365d; --bg-color: #f1f5f9; --sidebar-bg: #ffffff; }
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

        .user-avatar {
            width: 44px;
            height: 44px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #1e293b;
        }

        /* Card styles for checklist */
        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            padding: 24px;
        }



        /* ── HEADER: how far along the student is, at a glance ── */
        .ck-head { padding: 22px 24px 20px; border-bottom: 1px solid #eef2f7; }
        .ck-head-top { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .ck-head h3 { font-size: 17px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        .ck-head h3 i { color: var(--primary-color); }
        .ck-sub { font-size: 13px; color: #64748b; margin-top: 5px; line-height: 1.5; }

        /* The ring reads as a share of the whole, the bar as distance travelled. */
        .ck-ring {
            position: relative; width: 76px; height: 76px; flex-shrink: 0; border-radius: 50%;
            background: conic-gradient(#10b981 calc(var(--pct, 0) * 1%), #e2e8f0 0);
            display: grid; place-items: center;
        }
        .ck-ring::before { content: ''; position: absolute; inset: 8px; background: #fff; border-radius: 50%; }
        .ck-ring-text { position: relative; text-align: center; line-height: 1.15; }
        .ck-ring-num { font-size: 17px; font-weight: 800; color: #0f172a; }
        .ck-ring-of { font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.07em; }

        .ck-bar { height: 8px; border-radius: 999px; background: #e9eef5; overflow: hidden; margin: 18px 0 12px; }
        .ck-bar-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #34d399, #10b981); transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1); }

        .ck-legend { display: flex; flex-wrap: wrap; gap: 8px; }
        .ck-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #64748b; }
        .ck-chip i { font-size: 10px; }
        .ck-chip.is-ok { background: rgba(16,185,129,0.12); color: #047857; }
        .ck-chip.is-wait { background: rgba(245,158,11,0.12); color: #b45309; }
        .ck-chip.is-bad { background: rgba(220,38,38,0.12); color: #b91c1c; }

        .ck-notes { margin-top: 16px; display: grid; gap: 8px; }
        .ck-note { display: flex; gap: 9px; font-size: 12.5px; line-height: 1.55; padding: 10px 12px; border-radius: 10px; }
        .ck-note i { margin-top: 2px; flex-shrink: 0; }
        .ck-note.warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .ck-note.info { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

        /* ── ROWS ──
           One row per required document: a status mark, the name and its badge,
           then whatever the student can do about it. The mark replaces both the
           old disabled checkbox and the duplicate icon that sat on the right. */
        .ck-list { list-style: none; margin: 0; padding: 10px; display: grid; gap: 8px; }
        .ck-row {
            display: grid; grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center; gap: 14px;
            padding: 14px 16px; border: 1px solid #e9eef5; border-radius: 14px;
            background: #fff; transition: border-color 0.18s, box-shadow 0.18s;
        }
        .ck-row:hover { border-color: #cbd5e1; box-shadow: 0 4px 14px rgba(15,23,42,0.05); }
        .ck-row.is-approved { background: #f7fdfa; border-color: #cdf0e0; }
        .ck-row.is-rejected { background: #fffafa; border-color: #fbd5d5; }

        .ck-mark { width: 34px; height: 34px; border-radius: 11px; display: grid; place-items: center; font-size: 14px; background: #f1f5f9; color: #94a3b8; }
        .ck-row.is-approved .ck-mark { background: rgba(16,185,129,0.14); color: #059669; }
        .ck-row.is-pending  .ck-mark { background: rgba(245,158,11,0.14); color: #d97706; }
        .ck-row.is-rejected .ck-mark { background: rgba(220,38,38,0.12); color: #dc2626; }

        .ck-main { min-width: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .ck-name { font-size: 15px; font-weight: 600; color: #0f172a; overflow-wrap: anywhere; }
        .ck-row.is-approved .ck-name { color: #47635a; }
        .ck-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .ck-note-slot { grid-column: 1 / -1; }
        .ck-empty { text-align: center; color: #94a3b8; padding: 44px 20px; }
        .ck-empty i { font-size: 34px; display: block; margin-bottom: 12px; color: #cbd5e1; }

        /* Status of the student's latest upload for this document type */
        /* Status of the student's latest upload for this document type */
        .doc-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 8px;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
            vertical-align: middle;
            background: #f1f5f9;
            color: #64748b;
        }
        .doc-status i { font-size: 10px; }
        .doc-status-pending { background: rgba(245,158,11,0.12); color: #b45309; }
        .doc-status-approved { background: rgba(16,185,129,0.12); color: #047857; }
        .doc-status-rejected { background: rgba(220,38,38,0.12); color: #b91c1c; }

        /* The registrar's written reason for not approving a document */
        .doc-reject-note {
            flex-basis: 100%;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 2px;
        }
        .doc-reject-note-head {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #b91c1c;
        }
        .doc-reject-note-body {
            margin: 6px 0 0;
            font-size: 13.5px;
            line-height: 1.5;
            color: #7f1d1d;
            overflow-wrap: anywhere;
        }
        .doc-reject-note-date {
            display: block;
            margin-top: 8px;
            font-size: 11.5px;
            color: #9b6a6a;
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
        }

        .btn-view, .btn-delete { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid transparent; padding: 8px 14px; border-radius: 9px; cursor: pointer; font-weight: 600; font-family: inherit; font-size: 13px; transition: all 0.18s; }
        .btn-view { background: rgba(26, 54, 93, 0.09); color: var(--primary-color); }
        .btn-view:hover { background: var(--primary-color); color: white; }
        .btn-delete { background: rgba(220, 38, 38, 0.08); color: #dc2626; border-color: rgba(220,38,38,0.16); }
        .btn-delete:hover { background: #dc2626; color: white; border-color: #dc2626; }

        /* ── UPLOAD ──
           The native file input is hidden but still the thing being clicked, so
           validation, `required` and keyboard focus all keep working; the label
           beside it is what the student actually sees. */
        .upload-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0; }
        .ck-file {
            position: relative; display: inline-flex; align-items: center; gap: 9px;
            max-width: 270px; padding: 5px 12px 5px 6px;
            border: 1px dashed #cbd5e1; border-radius: 11px; background: #f8fafc;
            cursor: pointer; transition: all 0.18s;
        }
        .ck-file:hover { border-color: var(--primary-color); background: #f1f5f9; }
        .ck-file:focus-within { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(26,54,93,0.12); }
        .file-input { position: absolute; width: 1px; height: 1px; padding: 0; margin: 0; opacity: 0; pointer-events: none; }
        .ck-file-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; font-size: 12.5px; font-weight: 600; color: #0f172a; white-space: nowrap; }
        .ck-file-name { font-size: 12.5px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ck-file.has-file { border-style: solid; border-color: #86efac; background: #f0fdf4; }
        .ck-file.has-file .ck-file-name { color: #047857; font-weight: 600; }

        .upload-button { background: var(--primary-color); color: white; border: none; padding: 9px 16px; border-radius: 9px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.18s; font-size: 13px; font-family: inherit; }
        .upload-button:hover:not(:disabled) { filter: brightness(1.18); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(26,54,93,0.22); }
        .upload-button:disabled { opacity: 0.45; cursor: not-allowed; transform: none; box-shadow: none; }
        .upload-error { flex-basis: 100%; font-size: 12px; color: #dc2626; font-weight: 600; }
        .upload-error[hidden] { display: none; }

        @media (max-width: 768px) {
            .main-content {
                padding: 16px !important;
            }
            .card {
                padding: 14px;
                border-radius: 14px;
            }
            .card > div {
                padding: 10px 0 !important;
            }
            .page-title {
                font-size: 20px !important;
            }
            .top-bar p {
                font-size: 13px;
            }
            .top-bar {
                margin-bottom: 20px;
            }

            /* The header stacks, and the ring shrinks beside the title. */
            .ck-head { padding: 18px 16px 16px; }
            .ck-head-top { gap: 14px; }
            .ck-ring { width: 62px; height: 62px; }
            .ck-ring::before { inset: 7px; }
            .ck-ring-num { font-size: 15px; }
            .ck-list { padding: 8px; }

            /* The actions drop to their own full-width line under the name. */
            .ck-row { grid-template-columns: auto minmax(0, 1fr); padding: 13px 14px; gap: 12px; }
            .ck-name { font-size: 14px; }
            .ck-actions { grid-column: 1 / -1; justify-content: stretch; gap: 8px; }
            .ck-actions .btn-view, .ck-actions .btn-delete { flex: 1; padding: 10px 14px; }
            .upload-form { width: 100%; }
            .ck-file { max-width: none; flex: 1 1 100%; }
            .ck-file-name { flex: 1; }
            .upload-button { width: 100%; justify-content: center; padding: 11px 16px; }

            .doc-reject-note { padding: 10px 12px; }
            .doc-reject-note-head { font-size: 12.5px; }
            .doc-reject-note-body { font-size: 13px; }
        }

        @media (max-width: 420px) {
            .main-content {
                padding: 12px !important;
            }
            .card {
                padding: 12px;
            }
            .ck-head { padding: 16px 14px 14px; }
            .ck-head-top { flex-direction: column; align-items: flex-start; }
            .ck-ring { width: 56px; height: 56px; }
            .ck-name { font-size: 13.5px; }
            .doc-status { white-space: normal; }
        }

        /* Phones: the summary (title, count, ring, bar, chips) stacks and centres,
           instead of the title sitting left with the ring squeezed beside it. */
        @media (max-width: 600px) {
            .ck-head-top { flex-direction: column; align-items: center; gap: 12px; }
            .ck-head-top > div:first-child { width: 100%; }
            .ck-head h3 { justify-content: center; }
            .ck-sub { margin-left: auto; margin-right: auto; text-align: center; }
            .ck-ring { width: 72px; height: 72px; }
            .ck-ring::before { inset: 8px; }
            .ck-ring-num { font-size: 16px; }
            .ck-legend { justify-content: center; }
        }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        /* ══════════════════════════════════════════════════════════════
           CENTERED MODAL — Upload Result
           ══════════════════════════════════════════════════════════════ */

        /* Backdrop overlay */
        .verify-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: vModalFadeIn 0.35s ease;
        }
        @keyframes vModalFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        /* Card */
        .verify-modal-card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.18), 0 0 0 1px rgba(255,255,255,0.1);
            text-align: center;
            padding: 40px 32px 32px;
            position: relative;
            animation: vModalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }
        @keyframes vModalSlideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Decorative gradient stripe at top of card */
        .verify-modal-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
        }
        .verify-modal-card.vm-success::before {
            background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7);
        }
        .verify-modal-card.vm-error::before {
            background: linear-gradient(90deg, #ef4444, #f87171, #fca5a5);
        }
        .verify-modal-card.vm-info::before {
            background: linear-gradient(90deg, #3b82f6, #60a5fa, #93c5fd);
        }

        /* Large icon circle */
        .verify-modal-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            animation: vIconPop 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.15s both;
        }
        @keyframes vIconPop {
            from { opacity: 0; transform: scale(0.3); }
            60%  { transform: scale(1.1); }
            to   { opacity: 1; transform: scale(1); }
        }
        .vm-success .verify-modal-icon {
            background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(52,211,153,0.1));
            color: #10b981;
            box-shadow: 0 8px 24px rgba(16,185,129,0.15);
        }
        .vm-error .verify-modal-icon {
            background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(248,113,113,0.1));
            color: #ef4444;
            box-shadow: 0 8px 24px rgba(239,68,68,0.15);
        }
        .vm-info .verify-modal-icon {
            background: linear-gradient(135deg, rgba(59,130,246,0.15), rgba(96,165,250,0.1));
            color: #3b82f6;
            box-shadow: 0 8px 24px rgba(59,130,246,0.15);
        }

        /* Title */
        .verify-modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .vm-success .verify-modal-title { color: #059669; }
        .vm-error .verify-modal-title   { color: #dc2626; }
        .vm-info .verify-modal-title     { color: #2563eb; }

        /* Message body */
        .verify-modal-msg {
            font-size: 14px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 16px;
            word-break: break-word;
        }

        /* Reason callout (only on reject) */
        .verify-modal-reason {
            background: rgba(239,68,68,0.06);
            border: 1px solid rgba(239,68,68,0.15);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            color: #b91c1c;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .verify-modal-reason i {
            margin-top: 2px;
            flex-shrink: 0;
            font-size: 14px;
        }

        /* Dismiss button */
        .verify-modal-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 36px;
            border-radius: 12px;
            border: none;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s, opacity 0.2s;
            color: #fff;
        }
        .verify-modal-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .verify-modal-btn:active {
            transform: translateY(0);
        }
        .vm-success .verify-modal-btn {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        .vm-error .verify-modal-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        .vm-info .verify-modal-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        /* Close X (top-right) */
        .verify-modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: none;
            background: rgba(0,0,0,0.04);
            color: #94a3b8;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: background 0.2s, color 0.2s;
        }
        .verify-modal-close:hover {
            background: rgba(0,0,0,0.08);
            color: #475569;
        }

        /* Auto-dismiss progress bar at bottom */
        .verify-modal-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            border-radius: 0 0 20px 20px;
            animation: vProgressShrink 10s linear forwards;
        }
        .vm-success .verify-modal-progress { background: #10b981; }
        .vm-error .verify-modal-progress   { background: #ef4444; }
        .vm-info .verify-modal-progress    { background: #3b82f6; }
        @keyframes vProgressShrink {
            from { width: 100%; }
            to   { width: 0%; }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .verify-modal-card {
                padding: 32px 20px 24px;
                border-radius: 16px;
                max-width: 100%;
            }
            .verify-modal-icon {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }
            .verify-modal-title { font-size: 18px; }
            .verify-modal-msg { font-size: 13px; }
            .verify-modal-btn { padding: 11px 28px; font-size: 13px; }
        }

        /* ══════════════════════════════════════════════════════════════
           DELETE CONFIRMATION MODAL
           ══════════════════════════════════════════════════════════════ */
        .delete-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10050;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .delete-modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }

        .delete-modal-card {
            background: #ffffff;
            border-radius: 22px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.3), 0 0 0 1px rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
            padding: 36px 30px 28px;
            text-align: center;
            transform: translateY(20px) scale(0.95);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
        }

        .delete-modal-backdrop.active .delete-modal-card {
            transform: translateY(0) scale(1);
        }

        .delete-modal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ef4444, #f87171, #fb7185);
        }

        .delete-modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: #f1f5f9;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .delete-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }

        .delete-modal-icon-wrapper {
            position: relative;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 18px;
            box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.3);
        }

        .delete-modal-icon-pulse {
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 2px solid rgba(239, 68, 68, 0.35);
            animation: deletePulseRing 2s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }

        @keyframes deletePulseRing {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.15); opacity: 0; }
            100% { transform: scale(0.95); opacity: 0; }
        }

        .delete-modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
            letter-spacing: -0.01em;
        }

        .delete-modal-doc-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 7px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 14px;
            max-width: 100%;
            word-break: break-word;
        }

        .delete-modal-doc-pill i {
            color: var(--primary-color, #1a365d);
        }

        .delete-modal-desc {
            font-size: 14px;
            color: #475569;
            line-height: 1.55;
            margin-bottom: 14px;
        }

        .delete-modal-warning-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: #991b1b;
            line-height: 1.5;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 22px;
        }

        .delete-modal-warning-box i {
            font-size: 14px;
            color: #ef4444;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .delete-modal-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-delete-cancel {
            flex: 1;
            padding: 12px 18px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-delete-cancel:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }

        .btn-delete-confirm {
            flex: 1.2;
            padding: 12px 20px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
            transition: all 0.2s ease;
        }

        .btn-delete-confirm:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.45);
        }

        .btn-delete-confirm:active {
            transform: translateY(0);
        }

        .btn-delete-confirm:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        @media (max-width: 480px) {
            .delete-modal-card {
                padding: 28px 18px 22px;
                border-radius: 16px;
            }
            .delete-modal-actions {
                flex-direction: column-reverse;
                gap: 8px;
            }
            .btn-delete-cancel,
            .btn-delete-confirm {
                width: 100%;
                flex: unset;
            }
        }

        /* Upload Loading Overlay */
        .verify-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(6px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 16px;
        }
        .verify-overlay.active { display: flex; }
        .verify-spinner {
            width: 50px; height: 50px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top: 4px solid #10b981;
            border-radius: 50%;
            animation: verifySpin 0.8s linear infinite;
        }
        @keyframes verifySpin { to { transform: rotate(360deg); } }
        .verify-text {
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            max-width: 320px;
        }
        .verify-subtext {
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            text-align: center;
            max-width: 300px;
        }
    </style>
    <link rel="stylesheet" href="doc_viewer.css?v=1">
    <link rel="stylesheet" href="student_mobile.css?v=1">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="student_profile.css?v=11">
    <script src="student_profile.js?v=3" defer></script>
</head>
<body class="student-shell">
<div class="dashboard">
    <!-- Mobile Menu Button -->
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
                       <a href="checklist.php" class="nav-item active">
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
                <h1 class="page-title">Document Checklist</h1>
                <p>Track required documents for your academic journey.</p>
            </div>
        </div>

        <?php if (isset($_SESSION['flash'])): ?>
            <?php
                $flash_msg = $_SESSION['flash'];
                $flash_type = $_SESSION['flash_type'] ?? 'info';
                $is_good = (strpos($flash_msg, '✅') !== false || $flash_type === 'success');
                $is_bad  = (strpos($flash_msg, '❌') !== false || $flash_type === 'error');

                // Parse the message for better display
                $reason_text = '';
                if (strpos($flash_msg, 'Reason: ') !== false) {
                    $parts = explode('Reason: ', $flash_msg, 2);
                    $main_msg = trim($parts[0]);
                    $reason_text = trim($parts[1]);
                } else {
                    $main_msg = $flash_msg;
                }

                // Clean emoji from main message for cleaner display
                $clean_msg = str_replace(['✅ ', '❌ '], '', $main_msg);

                if ($is_good) {
                    $vm_class = 'vm-success';
                    $icon = 'fa-circle-check';
                    $title = (stripos($clean_msg, 'delete') !== false) ? 'Document Deleted!' : 'Done!';
                    $btn_text = 'Great, Got it!';
                } elseif ($is_bad) {
                    $vm_class = 'vm-error';
                    $icon = 'fa-triangle-exclamation';
                    $title = (stripos($clean_msg, 'delete') !== false) ? 'Delete Failed' : 'Upload Failed';
                    $btn_text = 'I Understand';
                } elseif (stripos($clean_msg, 'pending review') !== false) {
                    // A new upload now waits for the registrar; nothing is decided automatically.
                    $vm_class = 'vm-info';
                    $icon = 'fa-paper-plane';
                    $title = 'Sent to the Registrar';
                    $btn_text = 'Got it!';
                } else {
                    $vm_class = 'vm-info';
                    $icon = 'fa-info-circle';
                    $title = 'Notice';
                    $btn_text = 'OK';
                }
            ?>
            <!-- Centered Verification Result Modal -->
            <div class="verify-modal-backdrop" id="verifyModal">
                <div class="verify-modal-card <?= $vm_class ?>">
                    <button class="verify-modal-close" onclick="dismissVerifyModal()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="verify-modal-icon">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <div class="verify-modal-title"><?= $title ?></div>
                    <div class="verify-modal-msg"><?= htmlspecialchars($clean_msg) ?></div>
                    <?php if (!empty($reason_text)): ?>
                        <div class="verify-modal-reason">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($reason_text) ?>
                        </div>
                    <?php endif; ?>
                    <button class="verify-modal-btn" onclick="dismissVerifyModal()">
                        <?= $btn_text ?>
                    </button>
                    <div class="verify-modal-progress"></div>
                </div>
            </div>
            <script>
                function dismissVerifyModal() {
                    const modal = document.getElementById('verifyModal');
                    if (!modal) return;
                    const card = modal.querySelector('.verify-modal-card');
                    card.style.animation = 'none';
                    card.offsetHeight; // reflow
                    card.style.transition = 'transform 0.25s ease, opacity 0.25s ease';
                    card.style.transform = 'translateY(20px) scale(0.95)';
                    card.style.opacity = '0';
                    modal.style.transition = 'opacity 0.3s ease';
                    modal.style.opacity = '0';
                    setTimeout(() => modal.remove(), 300);
                }
                // Auto-dismiss after 10 seconds
                setTimeout(() => dismissVerifyModal(), 10000);
            </script>
            <?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <?php
        // The whole checklist is read first so the header can say how far along
        // the student is before the rows are drawn.
        $checklist = [];
        $counts = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'not uploaded' => 0];

        $types_res = $mysqli->query("SELECT name FROM document_types ORDER BY name ASC");
        if ($types_res) {
            $doc_stmt = $mysqli->prepare("SELECT id, file_name, file_path, status, feedback, reviewed_at
                                          FROM documents WHERE user_id = ? AND document_type = ?
                                          ORDER BY uploaded_at DESC LIMIT 1");
            while ($type = $types_res->fetch_assoc()) {
                $tname = $type['name'];
                $doc_stmt->bind_param("is", $user_id, $tname);
                $doc_stmt->execute();
                $doc_info = $doc_stmt->get_result()->fetch_assoc();

                $status = $doc_info['status'] ?? 'not uploaded';
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $checklist[] = ['name' => $tname, 'status' => $status, 'doc' => $doc_info];
            }
            $doc_stmt->close();
        }

        $total    = count($checklist);
        $done     = $counts['approved'];
        $pct      = $total > 0 ? round($done / $total * 100) : 0;
        $all_done = $total > 0 && $done === $total;

        // Wording the student actually understands
        $status_labels = [
            'pending'      => 'Waiting for registrar review',
            'approved'     => 'Approved by registrar',
            'rejected'     => 'Not approved — please re-upload',
            'not uploaded' => 'Not uploaded',
        ];
        $status_icons = [
            'pending'      => 'fa-clock',
            'approved'     => 'fa-check',
            'rejected'     => 'fa-xmark',
            'not uploaded' => 'fa-arrow-up-from-bracket',
        ];
        ?>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="ck-head">
                <div class="ck-head-top">
                    <div style="min-width: 0;">
                        <h3><i class="fas fa-clipboard-list"></i> Required Documents</h3>
                        <p class="ck-sub">
                            <?php if ($total === 0): ?>
                                Nothing is required of you yet.
                            <?php elseif ($all_done): ?>
                                All set — every required document has been approved.
                            <?php else: ?>
                                <?= $done ?> of <?= $total ?> approved. <?= $total - $done ?> still to go.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($total > 0): ?>
                        <div class="ck-ring" style="--pct: <?= $pct ?>" role="img"
                             aria-label="<?= $done ?> of <?= $total ?> documents approved">
                            <span class="ck-ring-text">
                                <span class="ck-ring-num"><?= $done ?>/<?= $total ?></span><br>
                                <span class="ck-ring-of">done</span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($total > 0): ?>
                    <div class="ck-bar"><div class="ck-bar-fill" style="width: <?= $pct ?>%"></div></div>
                    <div class="ck-legend">
                        <?php if ($counts['approved']): ?>
                            <span class="ck-chip is-ok"><i class="fas fa-circle-check"></i> <?= $counts['approved'] ?> approved</span>
                        <?php endif; ?>
                        <?php if ($counts['pending']): ?>
                            <span class="ck-chip is-wait"><i class="fas fa-clock"></i> <?= $counts['pending'] ?> waiting for review</span>
                        <?php endif; ?>
                        <?php if ($counts['rejected']): ?>
                            <span class="ck-chip is-bad"><i class="fas fa-circle-xmark"></i> <?= $counts['rejected'] ?> to re-upload</span>
                        <?php endif; ?>
                        <?php if ($counts['not uploaded']): ?>
                            <span class="ck-chip"><i class="fas fa-arrow-up-from-bracket"></i> <?= $counts['not uploaded'] ?> not uploaded</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="ck-notes">
                    <p class="ck-note warn">
                        <i class="fas fa-circle-exclamation"></i>
                        <span>PDF, PNG and JPEG only, up to <?= (int)(DOC_MAX_UPLOAD_BYTES / 1048576) ?> MB. Word, Excel and PowerPoint files are not accepted.</span>
                    </p>
                    <p class="ck-note info">
                        <i class="fas fa-user-check"></i>
                        <span>Every upload is reviewed by the registrar. Your document stays <strong>pending</strong> until they approve it &mdash; if it is not approved, their written reason appears right here so you know what to fix before uploading again.</span>
                    </p>
                </div>
            </div>

            <?php if ($total === 0): ?>
                <div class="ck-empty">
                    <i class="fas fa-clipboard-check"></i>
                    <div>No required documents assigned yet.</div>
                </div>
            <?php else: ?>
            <ul class="ck-list">
                <?php foreach ($checklist as $item):
                    $tname   = $item['name'];
                    $status  = $item['status'];
                    $doc     = $item['doc'];
                    $doc_id  = $doc['id'] ?? null;
                    $doc_path = $doc['file_path'] ?? null;
                    $feedback = trim($doc['feedback'] ?? '');
                    $reviewed = $doc['reviewed_at'] ?? null;
                    $state   = str_replace(' ', '-', $status);
                ?>
                <li class="ck-row is-<?= $state ?>">
                    <span class="ck-mark"><i class="fas <?= $status_icons[$status] ?? 'fa-circle' ?>"></i></span>

                    <div class="ck-main">
                        <span class="ck-name"><?= htmlspecialchars($tname) ?></span>
                        <span class="doc-status doc-status-<?= $state ?>">
                            <i class="fas <?= $status === 'approved' ? 'fa-circle-check' : ($status === 'rejected' ? 'fa-circle-xmark' : ($status === 'pending' ? 'fa-clock' : 'fa-circle')) ?>"></i>
                            <?= htmlspecialchars($status_labels[$status] ?? ucfirst($status)) ?>
                        </span>
                    </div>

                    <div class="ck-actions">
                        <?php if ($doc_id): // the copy on file can always be opened ?>
                            <button type="button" class="btn-view" title="View the copy you sent"
                                    onclick="openPreview(this.dataset.file, this.dataset.name)"
                                    data-file="<?= htmlspecialchars($doc_path) ?>" data-name="<?= htmlspecialchars($tname) ?>">
                                <i class="fas fa-eye"></i> View
                            </button>
                        <?php endif; ?>

                        <?php if ($doc_id && $status !== 'rejected'): ?>
                            <button type="button" class="btn-delete" title="Delete this document"
                                    onclick="openDeleteModal(<?= (int)$doc_id ?>, this.dataset.name)"
                                    data-name="<?= htmlspecialchars($tname) ?>">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        <?php endif; ?>

                        <?php if (!$doc_id || $status === 'rejected'): ?>
                            <form action="process_checklist_upload.php" method="POST" enctype="multipart/form-data"
                                  class="upload-form" data-doctype="<?= htmlspecialchars($tname) ?>">
                                <input type="hidden" name="document_type" value="<?= htmlspecialchars($tname) ?>">
                                <label class="ck-file">
                                    <input type="file" name="document_file" class="file-input" accept=".pdf,.png,.jpg,.jpeg" required>
                                    <span class="ck-file-btn"><i class="fas fa-paperclip"></i> Choose file</span>
                                    <span class="ck-file-name">No file chosen</span>
                                </label>
                                <button type="submit" class="upload-button">
                                    <i class="fas fa-arrow-up-from-bracket"></i> <?= $status === 'rejected' ? 'Re-upload' : 'Upload' ?>
                                </button>
                                <div class="upload-error" role="alert" hidden></div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($status === 'rejected' && $feedback !== ''): ?>
                        <div class="ck-note-slot">
                            <div class="doc-reject-note" role="note">
                                <div class="doc-reject-note-head">
                                    <i class="fas fa-circle-exclamation"></i>
                                    <span>The registrar did not approve this document</span>
                                </div>
                                <p class="doc-reject-note-body"><?= nl2br(htmlspecialchars($feedback)) ?></p>
                                <?php if ($reviewed): ?>
                                    <span class="doc-reject-note-date">Reviewed on <?= date('M d, Y', strtotime($reviewed)) ?> at <?= date('g:i A', strtotime($reviewed)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div style="font-size: 12px; color: #94a3b8; text-align: center; padding: 16px 0;">
            <i class="fas fa-shield-alt"></i> Registrar System • Student Portal
        </div>
    </div>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="delete-modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="delete-modal-card" id="deleteModalCard">
        <button type="button" class="delete-modal-close" onclick="closeDeleteModal()" aria-label="Close modal">
            <i class="fas fa-times"></i>
        </button>
        
        <div class="delete-modal-icon-wrapper">
            <div class="delete-modal-icon-pulse"></div>
            <i class="fas fa-trash-alt"></i>
        </div>

        <h3 id="deleteModalTitle" class="delete-modal-title">Delete Document?</h3>

        <div class="delete-modal-doc-pill">
            <i class="fas fa-file-lines"></i>
            <span id="deleteModalDocName">Document</span>
        </div>

        <p class="delete-modal-desc">
            Are you sure you want to delete this document? This action is permanent and cannot be undone.
        </p>

        <div class="delete-modal-warning-box">
            <i class="fas fa-triangle-exclamation"></i>
            <div>Deleting this will remove your uploaded file and reset its approval status. You will need to upload a new copy if required.</div>
        </div>

        <form id="deleteDocumentForm" action="process_delete_document.php" method="POST" onsubmit="handleDeleteSubmit(event)">
            <input type="hidden" name="document_id" id="deleteModalDocId" value="">
            
            <div class="delete-modal-actions">
                <button type="button" class="btn-delete-cancel" onclick="closeDeleteModal()">
                    Cancel
                </button>
                <button type="submit" class="btn-delete-confirm" id="deleteConfirmSubmitBtn">
                    <i class="fas fa-trash-alt"></i>
                    <span>Delete Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(docId, docName) {
        const modal = document.getElementById('deleteConfirmModal');
        const idInput = document.getElementById('deleteModalDocId');
        const nameSpan = document.getElementById('deleteModalDocName');
        const submitBtn = document.getElementById('deleteConfirmSubmitBtn');

        if (!modal || !idInput || !nameSpan) return;

        idInput.value = docId;
        nameSpan.textContent = docName || 'Document';

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-trash-alt"></i> <span>Delete Document</span>';
        }

        modal.style.display = 'flex';
        void modal.offsetWidth; // Trigger reflow for transition
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        const cancelBtn = modal.querySelector('.btn-delete-cancel');
        if (cancelBtn) cancelBtn.focus();
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteConfirmModal');
        if (!modal) return;

        modal.classList.remove('active');
        document.body.style.overflow = '';

        setTimeout(() => {
            if (!modal.classList.contains('active')) {
                modal.style.display = 'none';
            }
        }, 250);
    }

    function handleDeleteSubmit(e) {
        const submitBtn = document.getElementById('deleteConfirmSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Deleting...</span>';
        }
    }

    // Close on backdrop click (outside card)
    document.addEventListener('click', (e) => {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal && e.target === modal) {
            closeDeleteModal();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const modal = document.getElementById('deleteConfirmModal');
            if (modal && modal.classList.contains('active')) {
                closeDeleteModal();
            }
        }
    });

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

<!-- Upload Loading Overlay -->
<div id="verifyOverlay" class="verify-overlay">
    <div class="verify-spinner"></div>
    <div class="verify-text" id="verifyText">Uploading document...</div>
    <div class="verify-subtext" id="verifySubtext">Sending your file to the registrar</div>
</div>

<script>
    // ─── DOCUMENT UPLOAD ───
    // Size/type are checked instantly in the browser and again on the server.
    // The registrar decides whether a document is accepted; nothing is approved automatically.
    const MAX_UPLOAD_BYTES = <?= (int)DOC_MAX_UPLOAD_BYTES ?>;
    const ALLOWED_UPLOAD_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    function formatBytes(bytes) {
        return bytes >= 1048576
            ? (bytes / 1048576).toFixed(1) + ' MB'
            : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function showVerifyOverlay(text, subtext) {
        document.getElementById('verifyText').textContent = text || 'Uploading document...';
        document.getElementById('verifySubtext').textContent = subtext || '';
        document.getElementById('verifyOverlay').classList.add('active');
    }

    function hideVerifyOverlay() {
        document.getElementById('verifyOverlay').classList.remove('active');
    }

    // Phone photos are often 3-8 MB and 4000px wide, far more than a registrar needs
    // to read a document. Shrinking them here makes the upload several times faster
    // on mobile data or ngrok. Small files, PDFs and anything that fails are sent as-is.
    const SHRINK_OVER_BYTES = 1.5 * 1048576;
    const SHRINK_MAX_SIDE = 2200;

    function loadImage(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable image')); };
            img.src = url;
        });
    }

    function getStandardDocName(docType, originalFileName) {
        const ext = (originalFileName && originalFileName.includes('.')) ? originalFileName.split('.').pop().toLowerCase() : 'jpg';
        const slug = (docType || 'document').toLowerCase().replace(/[^a-z0-9]/g, '') || 'document';
        return slug + '.' + ext;
    }

    async function shrinkImage(file, docType) {
        if (!/^image\/(jpeg|png)$/.test(file.type) || typeof DataTransfer === 'undefined') return file;
        const img = await loadImage(file);
        const longest = Math.max(img.naturalWidth, img.naturalHeight);
        if (file.size <= SHRINK_OVER_BYTES && longest <= SHRINK_MAX_SIDE) return file;

        const scale = Math.min(1, SHRINK_MAX_SIDE / longest);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; // a transparent PNG becomes white paper, not black
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.85));
        if (!blob || blob.size >= file.size) return file;
        const name = docType ? getStandardDocName(docType, 'file.jpg') : (file.name.replace(/\.[^.]+$/, '') + '.jpg');
        return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
    }

    function validateUploadFile(file) {
        if (!file) return 'Please choose a file to upload.';
        const ext = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
        if (!ALLOWED_UPLOAD_EXT.includes(ext)) return 'Only PDF, PNG, and JPEG files are allowed.';
        if (file.size === 0) return 'The selected file is empty.';
        if (file.size > MAX_UPLOAD_BYTES) {
            return `File is too large (${formatBytes(file.size)}). Maximum size is ${MAX_UPLOAD_BYTES / 1048576} MB.`;
        }
        return '';
    }

    function setUploadError(form, message) {
        const box = form.querySelector('.upload-error');
        box.textContent = message;
        box.hidden = !message;
        form.querySelector('.upload-button').disabled = !!message;
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.upload-form').forEach(form => {
            const input = form.querySelector('.file-input');
            const docType = form.dataset.doctype || '';

            const nameSlot = form.querySelector('.ck-file-name');
            const picker = form.querySelector('.ck-file');

            const button = form.querySelector('.upload-button');
            let shrinking = null;

            input.addEventListener('change', () => {
                let file = input.files[0];
                const cleanName = file ? getStandardDocName(docType, file.name) : 'No file chosen';
                if (nameSlot) nameSlot.textContent = cleanName;
                if (picker) picker.classList.toggle('has-file', !!file);
                setUploadError(form, '');
                if (!file) return;

                const ext = file.name.split('.').pop().toLowerCase();
                if (!ALLOWED_UPLOAD_EXT.includes(ext) || ext === 'pdf') {
                    setUploadError(form, validateUploadFile(file));
                    return;
                }

                // Shrink the photo before it is sent; the button waits until it is ready.
                const original = file;
                button.disabled = true;
                if (nameSlot) nameSlot.textContent = cleanName + ' (optimizing...)';
                shrinking = shrinkImage(original, docType).catch(() => original).then(result => {
                    if (input.files[0] !== original) return; // another file was picked meanwhile
                    if (result !== original) {
                        const dt = new DataTransfer();
                        dt.items.add(result);
                        input.files = dt.files;
                    }
                    if (nameSlot) {
                        nameSlot.textContent = result !== original
                            ? `${cleanName} (${formatBytes(original.size)} → ${formatBytes(result.size)})`
                            : cleanName;
                    }
                    setUploadError(form, validateUploadFile(result));
                }).finally(() => { shrinking = null; });
            });

            form.addEventListener('submit', e => {
                if (shrinking) { e.preventDefault(); return; }
                const file = input.files[0];
                const error = validateUploadFile(file);
                if (error) {
                    e.preventDefault();
                    setUploadError(form, error);
                    return;
                }
                const targetClean = getStandardDocName(docType, file.name);
                form.querySelector('.upload-button').disabled = true;
                showVerifyOverlay(
                    'Submitting your document...',
                    `Uploading ${targetClean} (${formatBytes(file.size)}). The registrar will review it and you will be notified of the result.`
                );
            });
        });
    });

    // Returning via the back button restores the page from cache; reset the upload UI.
    window.addEventListener('pageshow', e => {
        if (!e.persisted) return;
        hideVerifyOverlay();
        document.querySelectorAll('.upload-button').forEach(btn => { btn.disabled = false; });
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
