<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/audit_log_helper.php';

// Only allow systemadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin') {
    die('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id_target = intval($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($user_id_target <= 0 || empty($action)) {
        header("Location: admin_dashboard.php");
        exit;
    }

    // Don't allow modifying the default Admin
    $check_stmt = $mysqli->prepare("SELECT email FROM users WHERE id = ?");
    $check_stmt->bind_param("i", $user_id_target);
    $check_stmt->execute();
    $res = $check_stmt->get_result();
    $target_user = $res->fetch_assoc();
    $check_stmt->close();
    
    if (!$target_user || $target_user['email'] === 'Admin') {
        $_SESSION['flash'] = "Cannot modify this user.";
        header("Location: admin_dashboard.php");
        exit;
    }

    $admin_id = $_SESSION['user_id'] ?? null;

    if ($action === 'activate') {
        $stmt = $mysqli->prepare("UPDATE users SET is_verified = 2 WHERE id = ?");
        $stmt->bind_param("i", $user_id_target);
        if ($stmt->execute()) {
            logAudit($mysqli, $admin_id, 'USER_ACTIVATED', "Activated user account {$target_user['email']}.", $user_id_target);
        }
        $stmt->close();
    } elseif ($action === 'deactivate') {
        $stmt = $mysqli->prepare("UPDATE users SET is_verified = 0 WHERE id = ?"); // or 1 for pending, 0 for unverified/inactive
        $stmt->bind_param("i", $user_id_target);
        if ($stmt->execute()) {
            logAudit($mysqli, $admin_id, 'USER_DEACTIVATED', "Deactivated user account {$target_user['email']}.", $user_id_target);
        }
        $stmt->close();
    } elseif ($action === 'archive') {
        $stmt = $mysqli->prepare("UPDATE users SET is_archived = 1 WHERE id = ?");
        $stmt->bind_param("i", $user_id_target);
        if ($stmt->execute()) {
            logAudit($mysqli, $admin_id, 'USER_ARCHIVED', "Archived user account {$target_user['email']}.", $user_id_target);
        }
        $stmt->close();
    } elseif ($action === 'unarchive') {
        $stmt = $mysqli->prepare("UPDATE users SET is_archived = 0 WHERE id = ?");
        $stmt->bind_param("i", $user_id_target);
        if ($stmt->execute()) {
            logAudit($mysqli, $admin_id, 'USER_UNARCHIVED', "Unarchived user account {$target_user['email']}.", $user_id_target);
        }
        $stmt->close();
    }
    $redirect_section = 'accounts';
    if ($action === 'unarchive') {
        $redirect_section = 'archive';
    }

    header("Location: admin_dashboard.php?section=" . $redirect_section);
    exit;
}

header("Location: admin_dashboard.php");
exit;
?>
