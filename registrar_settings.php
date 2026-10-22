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

// Account Information: the registrar edits their own name and e-mail. The
// institution is not theirs to change - it decides which students they see.
$account_msg = $_SESSION['account_msg'] ?? '';
$account_msg_type = $_SESSION['account_msg_type'] ?? 'success';
$account_old = $_SESSION['account_old'] ?? null;
unset($_SESSION['account_msg'], $_SESSION['account_msg_type'], $_SESSION['account_old']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $clean = fn($v) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, 100);
    $first = $clean($_POST['first_name'] ?? '');
    $middle = $clean($_POST['middle_name'] ?? '');
    $last = $clean($_POST['last_name'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $error = '';
    if ($first === '' || $last === '') {
        $error = 'First name and last name are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Please enter a valid e-mail address.';
    } else {
        $dup = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $dup->bind_param("si", $email, $user_id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) $error = 'That e-mail address is already used by another account.';
        $dup->close();
    }
    if ($error === '') {
        $up = $mysqli->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
        $up->bind_param("ssssi", $first, $middle, $last, $email, $user_id);
        if ($up->execute()) {
            $_SESSION['email'] = $email;     // the session carries the e-mail it signed in with
            require_once 'audit_log_helper.php';
            logAudit($mysqli, $user_id, 'ACCOUNT_UPDATED', 'Updated their account information (name / e-mail).', $user_id);
            $_SESSION['account_msg'] = 'Your account information has been updated.';
            $_SESSION['account_msg_type'] = 'success';
        } else {
            $error = 'Your account information could not be saved. Please try again.';
        }
        $up->close();
    }
    if ($error !== '') {
        $_SESSION['account_msg'] = $error;
        $_SESSION['account_msg_type'] = 'error';
        $_SESSION['account_old'] = ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'email' => $email];
    }
    header("Location: registrar_settings.php#account");
    exit;
}

// Fetch user details from the database (including institution to fix the bug)
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

// Handle password change
$password_msg = '';
$password_msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Fetch current password hash
    $pw_stmt = $mysqli->prepare("SELECT password FROM users WHERE id = ?");
    $pw_stmt->bind_param("i", $user_id);
    $pw_stmt->execute();
    $pw_result = $pw_stmt->get_result()->fetch_assoc();
    $pw_stmt->close();

    if (!password_verify($current, $pw_result['password'])) {
        $password_msg = 'Current password is incorrect.';
        $password_msg_type = 'error';
    } elseif (strlen($new_pass) < 6) {
        $password_msg = 'New password must be at least 6 characters.';
        $password_msg_type = 'error';
    } elseif ($new_pass !== $confirm) {
        $password_msg = 'New passwords do not match.';
        $password_msg_type = 'error';
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $up_stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
        $up_stmt->bind_param("si", $hash, $user_id);
        if ($up_stmt->execute()) {
            $password_msg = 'Password changed successfully!';
            $password_msg_type = 'success';
        } else {
            $password_msg = 'Failed to update password. Please try again.';
            $password_msg_type = 'error';
        }
        $up_stmt->close();
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
    <title>Settings - Registrar Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
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

        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 20px; min-width: 20px; text-align: center; }

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

        .logout-link:hover { background: rgba(239,68,68,0.1); }

        /* MAIN CONTENT */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
            background: var(--main-bg);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .topbar-left h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
        .topbar-left p { font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px; }

        .content {
            flex: 1;
            padding: 28px 32px;
            overflow-y: auto;
        }

        /* SETTINGS GRID */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .settings-grid .full-width {
            grid-column: 1 / -1;
        }

        /* CARDS */
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 0;
            border: 1px solid rgba(255,255,255,0.06);
            overflow: hidden;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            border-color: rgba(255,255,255,0.1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .card-header-icon.gold { background: rgba(201,162,39,0.15); color: var(--accent-gold); }
        .card-header-icon.blue { background: rgba(59,130,246,0.15); color: var(--accent-blue); }
        .card-header-icon.green { background: rgba(16,185,129,0.15); color: var(--accent-green); }
        .card-header-icon.purple { background: rgba(139,92,246,0.15); color: var(--accent-purple); }
        .card-header-icon.red { background: rgba(239,68,68,0.15); color: var(--accent-red); }

        .card-header-text h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-header-text p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .card-body {
            padding: 24px;
        }

        /* COLOR SETTING ROW */
        .color-setting {
            margin-bottom: 24px;
        }

        .color-setting:last-child {
            margin-bottom: 0;
        }

        .color-setting-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .color-setting-label i {
            font-size: 12px;
            opacity: 0.6;
        }

        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .custom-color-picker {
            width: 48px;
            height: 48px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            cursor: pointer;
            padding: 2px;
            background: none;
            outline: none;
            transition: border-color 0.2s, transform 0.2s;
            flex-shrink: 0;
        }

        .custom-color-picker:hover {
            border-color: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .custom-color-picker::-webkit-color-swatch-wrapper { padding: 2px; }
        .custom-color-picker::-webkit-color-swatch { border: none; border-radius: 6px; }
        .custom-color-picker::-moz-color-swatch { border: none; border-radius: 6px; }

        .color-hex-input {
            width: 100px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.25);
            color: var(--text-primary);
            font-family: 'Inter', monospace;
            font-size: 13px;
            font-weight: 500;
            text-transform: uppercase;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .color-hex-input:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,0.15);
        }

        /* PRESET SWATCHES */
        .preset-swatches {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .swatch {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .swatch:hover {
            transform: scale(1.15);
            border-color: rgba(255,255,255,0.4);
        }

        .swatch.active {
            border-color: #fff;
            box-shadow: 0 0 0 2px rgba(255,255,255,0.3);
        }

        .swatch.active::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }

        /* RESET BUTTON */
        .reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: rgba(239,68,68,0.1);
            color: var(--accent-red);
            border: 1px solid rgba(239,68,68,0.2);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            margin-top: 8px;
        }

        .reset-btn:hover {
            background: rgba(239,68,68,0.2);
            border-color: rgba(239,68,68,0.3);
            transform: translateY(-1px);
        }

        /* ACCOUNT INFO */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .info-item {
            padding: 14px 16px;
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .info-item label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 6px;
        }

        .info-item span {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-word;
        }

        /* USER AVATAR */
        .user-avatar-section {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .user-avatar {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--accent-gold), #d4a017);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(201,162,39,0.3);
        }

        .user-avatar-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-avatar-info p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .user-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(201,162,39,0.15);
            color: var(--accent-gold);
            margin-top: 6px;
        }

        /* PASSWORD FORM */
        /* Account Information: the details, or - after Edit - the form in their place */
        #account .card-header { flex-wrap: nowrap; }
        #account .card-header-text { min-width: 0; }
        .acct-edit-btn {
            margin-left: auto; align-self: center; display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 14px; border-radius: 10px; cursor: pointer;
            background: rgba(59,130,246,0.14); color: #93c5fd; border: 1px solid rgba(59,130,246,0.35);
            font-family: inherit; font-size: 0.8rem; font-weight: 600;
        }
        .acct-edit-btn:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .acct-form { display: none; }
        #account.is-editing .acct-form { display: block; }
        #account.is-editing .info-grid, #account.is-editing .acct-edit-btn { display: none; }
        .acct-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
        .acct-grid .acct-wide { grid-column: 1 / -1; }
        .acct-req { color: #f87171; }
        .acct-hint { display: block; margin: 6px 0 0; font-size: 0.75rem; color: var(--text-muted, #94a3b8); }
        .acct-form .form-input:disabled { opacity: 0.6; cursor: not-allowed; }
        .acct-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .acct-actions .submit-btn { width: auto; margin: 0; }
        .acct-cancel {
            padding: 10px 18px; border-radius: 10px; cursor: pointer; font-family: inherit; font-weight: 600;
            background: transparent; color: var(--text-secondary, #cbd5e1); border: 1px solid rgba(255,255,255,0.15);
        }
        .acct-cancel:hover { background: rgba(255,255,255,0.06); }
        @media (max-width: 600px) {
            .acct-grid { grid-template-columns: 1fr; }
            .acct-actions > * { flex: 1 1 100%; justify-content: center; }
            .acct-form .form-input { font-size: 16px; }
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.25);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(201,162,39,0.15);
        }

        .form-input::placeholder {
            color: var(--text-muted);
        }

        .submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--accent-gold), #d4a017);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            box-shadow: 0 4px 12px rgba(201,162,39,0.3);
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(201,162,39,0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* ALERT MESSAGES */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(16,185,129,0.1);
            color: var(--accent-green);
            border: 1px solid rgba(16,185,129,0.2);
        }

        .alert-error {
            background: rgba(239,68,68,0.1);
            color: var(--accent-red);
            border: 1px solid rgba(239,68,68,0.2);
        }

        /* TOAST */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 14px 24px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: white;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .toast-info {
            background: linear-gradient(135deg, var(--accent-gold), #b8941e);
        }

        /* LIVE PREVIEW */
        .live-preview {
            margin-top: 20px;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.06);
            background: rgba(0,0,0,0.15);
        }

        .live-preview-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 12px;
        }

        .preview-mini {
            display: flex;
            border-radius: 8px;
            overflow: hidden;
            height: 80px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .preview-sidebar-mini {
            width: 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 4px;
            gap: 4px;
        }

        .preview-sidebar-mini .dot {
            width: 24px;
            height: 3px;
            border-radius: 2px;
            opacity: 0.3;
        }

        .preview-sidebar-mini .dot.active {
            opacity: 1;
        }

        .preview-main-mini {
            flex: 1;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .preview-main-mini .bar {
            height: 6px;
            border-radius: 3px;
            opacity: 0.15;
        }

        .preview-main-mini .bar.accent {
            width: 40%;
            opacity: 0.6;
        }

        /* MOBILE TOGGLE */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1100;
            background: var(--accent-gold);
            color: white;
            border: none;
            padding: 0;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            align-items: center;
            justify-content: center;
        }
        .mobile-toggle i { font-size: 18px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        /* ─── RESPONSIVE ─── */
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
            .mobile-toggle { display: flex; }
            .topbar {
                padding: 15px;
                padding-left: 65px;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .content { padding: 20px; }
            .mobile-topbar-logo { display: block !important; }
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .content { padding: 14px; }
            .card-body { padding: 18px; }
            .color-picker-row { gap: 8px; }
            .preset-swatches { gap: 4px; }
            .swatch { width: 28px; height: 28px; }
            .custom-color-picker { width: 40px; height: 40px; }
            .toast { left: 14px; right: 14px; bottom: 14px; }
            .user-avatar-section {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
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
    <!-- SIDEBAR -->
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
            <a href="typesofdocuments.php" class="nav-link"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
            <a href="registrar_settings.php" class="nav-link active"><i class="fas fa-cog"></i> Settings</a>
        </nav>

        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">
        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center; gap: 15px;">
                <img src="logo.jpg" class="mobile-topbar-logo" style="width: 45px; height: 45px; border-radius: 8px; display: none; object-fit: contain; background: transparent; padding: 2px;" alt="Logo">
                <div>
                    <h1><i class="fas fa-cog" style="font-size: 20px; margin-right: 8px; opacity: 0.7;"></i>Settings</h1>
                    <p>Personalize your registrar dashboard experience.</p>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="settings-grid">

                <!-- ACCOUNT INFO CARD -->
                <div class="card<?= $account_old ? ' is-editing' : '' ?>" id="account">
                    <div class="card-header">
                        <div class="card-header-icon blue"><i class="fas fa-user-circle"></i></div>
                        <div class="card-header-text">
                            <h3>Account Information</h3>
                            <p>Your profile details</p>
                        </div>
                        <button type="button" class="acct-edit-btn" id="acctEditBtn" aria-controls="acctForm"
                                aria-expanded="<?= $account_old ? 'true' : 'false' ?>"><i class="fas fa-pen"></i> <span>Edit</span></button>
                    </div>
                    <div class="card-body">
                        <?php if ($account_msg): ?>
                            <div class="alert alert-<?= $account_msg_type === 'error' ? 'error' : 'success' ?>">
                                <i class="fas fa-<?= $account_msg_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                                <?= htmlspecialchars($account_msg) ?>
                            </div>
                        <?php endif; ?>
                        <div class="user-avatar-section">
                            <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                            <div class="user-avatar-info">
                                <h4><?= htmlspecialchars($full_name) ?></h4>
                                <p><?= htmlspecialchars($user_data['email'] ?? '') ?></p>
                                <div class="user-role-badge"><i class="fas fa-shield-halved"></i> Registrar</div>
                            </div>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <label>First Name</label>
                                <span><?= htmlspecialchars($user_data['first_name'] ?? '—') ?></span>
                            </div>
                            <div class="info-item">
                                <label>Last Name</label>
                                <span><?= htmlspecialchars($user_data['last_name'] ?? '—') ?></span>
                            </div>
                            <div class="info-item">
                                <label>Middle Name</label>
                                <span><?= htmlspecialchars($user_data['middle_name'] ?? '—') ?></span>
                            </div>
                            <div class="info-item">
                                <label>Email Address</label>
                                <span><?= htmlspecialchars($user_data['email'] ?? '—') ?></span>
                            </div>
                            <?php if (!empty($user_inst)): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <label>Institution</label>
                                <span><?= htmlspecialchars($user_inst) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php $acct = $account_old ?: $user_data; ?>
                        <form method="POST" class="acct-form" id="acctForm" autocomplete="off">
                            <input type="hidden" name="update_account" value="1">
                            <div class="acct-grid">
                                <div class="form-group">
                                    <label for="acct_first">First Name <span class="acct-req">*</span></label>
                                    <input type="text" id="acct_first" name="first_name" class="form-input" maxlength="100" required value="<?= htmlspecialchars($acct['first_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="acct_middle">Middle Name</label>
                                    <input type="text" id="acct_middle" name="middle_name" class="form-input" maxlength="100" value="<?= htmlspecialchars($acct['middle_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="acct_last">Last Name <span class="acct-req">*</span></label>
                                    <input type="text" id="acct_last" name="last_name" class="form-input" maxlength="100" required value="<?= htmlspecialchars($acct['last_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="acct_email">Email Address <span class="acct-req">*</span></label>
                                    <input type="email" id="acct_email" name="email" class="form-input" maxlength="255" required value="<?= htmlspecialchars($acct['email'] ?? '') ?>">
                                </div>
                                <?php if (!empty($user_inst)): ?>
                                <div class="form-group acct-wide">
                                    <label>Institution</label>
                                    <input type="text" class="form-input" value="<?= htmlspecialchars($user_inst) ?>" readonly disabled>
                                    <small class="acct-hint">Set by the system administrator - it decides which students you see.</small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <p class="acct-hint">Your e-mail address is also what you sign in with.</p>
                            <div class="acct-actions">
                                <button type="button" class="acct-cancel" id="acctCancel">Cancel</button>
                                <button type="submit" class="submit-btn"><i class="fas fa-floppy-disk"></i> Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- CHANGE PASSWORD CARD -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon red"><i class="fas fa-lock"></i></div>
                        <div class="card-header-text">
                            <h3>Change Password</h3>
                            <p>Update your account password</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($password_msg): ?>
                            <div class="alert alert-<?= $password_msg_type ?>">
                                <i class="fas fa-<?= $password_msg_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                                <?= htmlspecialchars($password_msg) ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" autocomplete="off">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" class="form-input" placeholder="Enter current password" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-input" placeholder="Enter new password (min 6 chars)" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-input" placeholder="Re-enter new password" required minlength="6">
                            </div>
                            <button type="submit" name="change_password" class="submit-btn">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>


    <script>
        // ─── ACCOUNT INFORMATION: Edit opens the form in place of the details ───
        (function () {
            const card = document.getElementById('account');
            const edit = document.getElementById('acctEditBtn');
            const cancel = document.getElementById('acctCancel');
            const form = document.getElementById('acctForm');
            if (!card || !edit || !cancel || !form) return;
            const start = new FormData(form);
            edit.addEventListener('click', () => {
                card.classList.add('is-editing');
                edit.setAttribute('aria-expanded', 'true');
                document.getElementById('acct_first').focus();
            });
            cancel.addEventListener('click', () => {
                start.forEach((value, name) => { if (form.elements[name]) form.elements[name].value = value; });
                card.classList.remove('is-editing');
                edit.setAttribute('aria-expanded', 'false');
                edit.focus();
            });
        })();

        // ─── SIDEBAR TOGGLE ───
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
