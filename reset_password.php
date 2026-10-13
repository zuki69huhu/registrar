<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['can_reset_password']) || empty($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $_SESSION['flash_error'] = "Password must be at least 6 characters.";
    } elseif ($password !== $password_confirm) {
        $_SESSION['flash_error'] = "Passwords do not match.";
    } else {
        $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed_pass, $_SESSION['reset_email']);
        
        if ($stmt->execute()) {
            require_once 'audit_log_helper.php';
            $uid_stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
            $uid_stmt->bind_param("s", $_SESSION['reset_email']);
            $uid_stmt->execute();
            $reset_user_id = $uid_stmt->get_result()->fetch_assoc()['id'] ?? null;
            $uid_stmt->close();
            logAudit($mysqli, $reset_user_id, 'PASSWORD_RESET', 'Password reset using email OTP.', $reset_user_id);

            unset($_SESSION['can_reset_password']);
            unset($_SESSION['reset_email']);
            $_SESSION['flash'] = "Password has been successfully reset. You can now login.";
            header("Location: index.php?tab=login");
            exit;
        } else {
            $_SESSION['flash_error'] = "Failed to update password. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; min-height: 100dvh; margin: 0; padding: 16px; }
        .card { background: transparent; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        input, button { font-size: 16px; }
        @media (max-width: 480px) { .card { padding: 28px 20px; } }
        h2 { margin-top: 0; color: #1e293b; }
        p { color: #64748b; font-size: 14px; margin-bottom: 20px; }
        input[type="password"] { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #1a365d; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; margin-top: 5px; }
        button:hover { background: #1e40af; }
        .error { color: #ef4444; font-size: 14px; margin-bottom: 15px; }
    </style>
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body>
    <div class="card">
        <h2>Set New Password</h2>
        <p>Please enter your new password below.</p>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="error"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="New Password" required minlength="6">
            <input type="password" name="password_confirm" placeholder="Confirm New Password" required minlength="6">
            <button type="submit">Update Password</button>
        </form>
    </div>
</body>
</html>
