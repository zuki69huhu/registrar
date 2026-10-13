<?php
session_start();
require_once 'db.php';

$email = $_SESSION['reset_email'] ?? null;
if (!$email) {
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['otp'] ?? '');
    
    if (!preg_match('/^\d{6}$/', $input)) {
        $_SESSION['flash_error'] = 'Please enter a valid 6-digit code.';
    } else {
        $stmt = $mysqli->prepare("SELECT id, otp_hash, otp_expires, otp_attempts FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $_SESSION['flash_error'] = 'Account not found.';
        } elseif (is_null($user['otp_hash']) || empty($user['otp_hash'])) {
            $_SESSION['flash_error'] = 'No active OTP found. Please request a new code.';
        } elseif ($user['otp_expires'] < time()) {
            $_SESSION['flash_error'] = 'OTP has expired. Please request a new code.';
        } elseif ($user['otp_attempts'] >= 5) {
            $_SESSION['flash_error'] = 'Too many failed attempts. Please request a new OTP.';
        } else {
            if (password_verify($input, $user['otp_hash'])) {
                // Clear OTP kung tama
                $update_stmt = $mysqli->prepare("UPDATE users SET otp_hash = NULL, otp_expires = NULL, otp_attempts = 0 WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                $update_stmt->close();

                $_SESSION['can_reset_password'] = true;
                header('Location: reset_password.php');
                exit;
            } else {
                $new_attempts = $user['otp_attempts'] + 1;
                $attempt_stmt = $mysqli->prepare("UPDATE users SET otp_attempts = ? WHERE id = ?");
                $attempt_stmt->bind_param("ii", $new_attempts, $user['id']);
                $attempt_stmt->execute();
                $attempt_stmt->close();
                
                $_SESSION['flash_error'] = "Incorrect code. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; min-height: 100dvh; margin: 0; padding: 16px; }
        .card { background: transparent; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; overflow-wrap: anywhere; }
        button { font-size: 16px; }
        @media (max-width: 480px) { .card { padding: 28px 20px; } }
        h2 { margin-top: 0; color: #1e293b; }
        p { color: #64748b; font-size: 14px; margin-bottom: 20px; }
        input[type="text"] { width: 100%; padding: 12px; margin-bottom: 20px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; text-align: center; font-size: 24px; letter-spacing: 5px; }
        button { width: 100%; padding: 12px; background: #1a365d; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
        button:hover { background: #1e40af; }
        .error { color: #ef4444; font-size: 14px; margin-bottom: 15px; }
        .back { display: block; text-align: center; margin-top: 20px; color: #3b82f6; text-decoration: none; font-size: 14px; }
    </style>
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body>
    <div class="card">
        <h2>Verify Code</h2>
        <p>Enter the 6-digit code sent to <br><strong><?= htmlspecialchars($email) ?></strong></p>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="error"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="otp" pattern="\d{6}" maxlength="6" placeholder="123456" required autocomplete="off">
            <button type="submit">Verify</button>
        </form>
        <a href="forgot_password.php" class="back">Back</a>
    </div>
</body>
</html>
