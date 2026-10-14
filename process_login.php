<?php
session_start();
require_once "db.php"; // this defines $mysqli (MySQLi)
require_once "audit_log_helper.php";

// Only handle POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php?tab=login");
    exit;
}

$email = trim($_POST['email']);
$password = trim($_POST['password']);

// Fetch user
$stmt = $mysqli->prepare("SELECT id, email, password, role, is_verified FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password'])) {
    // Enforce case-sensitivity for system admin account
    if ($user['role'] === 'systemadmin' && $email !== $user['email']) {
        logAudit($mysqli, null, 'LOGIN_FAILED', 'Failed admin login: email letter case did not match.', $user['id']);
        $_SESSION['flash'] = "Invalid email or password.";
        header("Location: index.php?tab=login");
        exit;
    }

    // Check verification and approval status
    if ($user['is_verified'] == 0) {
        logAudit($mysqli, null, 'LOGIN_BLOCKED', 'Login blocked: email address not verified yet.', $user['id']);
        $_SESSION['flash'] = "Please verify your email via OTP first.";
        header("Location: index.php?tab=login");
        exit;
    }
    if ($user['role'] === 'student') {
        if ($user['is_verified'] == 1) {
            logAudit($mysqli, null, 'LOGIN_BLOCKED', 'Login blocked: account is waiting for registrar approval.', $user['id']);
            $_SESSION['flash'] = "Your account is pending registrar approval.";
            header("Location: index.php?tab=login");
            exit;
        } elseif ($user['is_verified'] == 3) {
            logAudit($mysqli, null, 'LOGIN_BLOCKED', 'Login blocked: registration was rejected.', $user['id']);
            $_SESSION['flash'] = "Your registration was rejected. Please contact the registrar office.";
            header("Location: index.php?tab=login");
            exit;
        }
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    require_once "audit_log_helper.php";
    logAudit($mysqli, $user['id'], 'LOGIN', 'User logged in successfully.');

    // --- Manage Email History for Login Suggestions ---
    $email_history = [];
    if (isset($_COOKIE['email_history'])) {
        // Decode existing history from cookie
        $email_history = json_decode($_COOKIE['email_history'], true);
        if (!is_array($email_history)) { // Sanity check
            $email_history = [];
        }
    }

    // Remove the current email if it already exists to move it to the front
    $email_history = array_diff($email_history, [$email]);

    // Add the current email to the beginning of the history
    array_unshift($email_history, $email);

    // Keep only the last 5 unique emails
    $email_history = array_slice($email_history, 0, 5);

    // Save the updated history back to the cookie for 1 year
    setcookie('email_history', json_encode($email_history), time() + (86400 * 365), "/");
    // --- End Email History Management ---

    // Handle "Remember Me" functionality
    if (isset($_POST['remember'])) {
        // Set a cookie for 30 days
        setcookie('remember_email', $email, time() + (86400 * 30), "/"); // 86400 = 1 day
    } else {
        // If "Remember Me" is not checked, clear the cookie
        if (isset($_COOKIE['remember_email'])) {
            setcookie('remember_email', '', time() - 3600, "/");
        }
    }

    // 🔹 Redirect based on role
    switch ($user['role']) {
        case 'systemadmin':
            header("Location: admin_dashboard.php");
            break;
        case 'registrar':
            header("Location: registrar_dashboard.php");
            break;
        default:
            header("Location: student_dashboard.php");
            break;
    }
    exit;
} else {
    if ($user) {
        logAudit($mysqli, null, 'LOGIN_FAILED', 'Failed login: wrong password.', $user['id']);
    } else {
        logAudit($mysqli, null, 'LOGIN_FAILED', 'Failed login with unregistered email "' . mb_substr($email, 0, 120) . '".');
    }
    $_SESSION['flash'] = "Invalid email or password.";
    header("Location: index.php?tab=login");
    exit;
}
