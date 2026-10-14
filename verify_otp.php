<?php
session_start();
require_once 'includes/db.php';

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $_SESSION['flash'] = 'Please register first.';
    header('Location: index.php'); 
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['otp'] ?? '');
    
    if (!preg_match('/^\d{6}$/', $input)) {
        $error = 'Please enter a valid 6-digit code.';
    } else {
        // Kunin ang user data para sa verification
        $stmt = $mysqli->prepare("SELECT id, otp_hash, otp_expires, otp_attempts, is_verified, email FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Account not found.';
        } elseif ((int)$user['is_verified'] === 1) {
            $_SESSION['flash'] = 'Account already verified. Please login.';
            header('Location: index.php'); 
            exit;
        } elseif (is_null($user['otp_hash']) || $user['otp_hash'] === '0' || empty($user['otp_hash'])) {
            $error = 'No active OTP found. Please request a new code.';
            error_log("No OTP hash found for user: " . $email);
        } elseif ($user['otp_expires'] < time()) {
            $error = 'OTP has expired. Please request a new code.';
            error_log("OTP expired for user: " . $email . " (Expires: " . $user['otp_expires'] . ", Current: " . time() . ")");
            
            // Clear expired OTP
            $clear_stmt = $mysqli->prepare("UPDATE users SET otp_hash = NULL, otp_expires = NULL WHERE id = ?");
            $clear_stmt->bind_param("i", $user['id']);
            $clear_stmt->execute();
            $clear_stmt->close();
        } elseif ($user['otp_attempts'] >= 5) {
            $error = 'Too many failed attempts. Please request a new OTP.';
            error_log("Too many attempts for user: " . $email . " (Attempts: " . $user['otp_attempts'] . ")");
        } else {
            // Debug logging
            error_log("=== OTP VERIFICATION DEBUG ===");
            error_log("Email: " . $email);
            error_log("Input OTP: " . $input);
            error_log("Stored Hash: " . $user['otp_hash']);
            error_log("Hash Length: " . strlen($user['otp_hash']));
            error_log("Current Time: " . time() . " (" . date('Y-m-d H:i:s') . ")");
            error_log("Expires Time: " . $user['otp_expires'] . " (" . date('Y-m-d H:i:s', $user['otp_expires']) . ")");
            
            // Verify the OTP
            $password_verify_result = password_verify($input, $user['otp_hash']);
            error_log("Password verify result: " . ($password_verify_result ? "TRUE" : "FALSE"));
            
            if ($password_verify_result) {
                error_log("SUCCESS: OTP verified for user: " . $email);
                
                // Update user as verified
                $update_stmt = $mysqli->prepare("UPDATE users SET is_verified = 1, otp_hash = NULL, otp_expires = NULL, otp_attempts = 0 WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                
                if ($update_stmt->execute()) {
                    error_log("Database updated successfully for user: " . $email);
                    require_once 'includes/audit_log_helper.php';
                    logAudit($mysqli, $user['id'], 'EMAIL_VERIFIED', 'Verified email address with OTP after registration.', $user['id']);
                    
                    unset($_SESSION['email']); // Linisin ang verification session
                    $_SESSION['flash'] = 'Email verified successfully! You can now sign in to your account.';
                    header('Location: index.php?tab=login'); 
                    exit;
                } else {
                    error_log("Database update failed: " . $update_stmt->error);
                    $error = 'Verification failed. Please try again.';
                }
                $update_stmt->close();
            } else {
                // Increment attempt count
                $new_attempts = $user['otp_attempts'] + 1;
                $remaining = 5 - $new_attempts;
                
                $attempt_stmt = $mysqli->prepare("UPDATE users SET otp_attempts = ? WHERE id = ?");
                $attempt_stmt->bind_param("ii", $new_attempts, $user['id']);
                $attempt_stmt->execute();
                $attempt_stmt->close();
                
                error_log("FAILED: OTP verification failed for user: " . $email . " (Attempts: " . $new_attempts . "/5)");
                $error = "Incorrect code. Please try again. {$remaining} attempts remaining.";
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; max-width: 450px; margin: 50px auto; padding: 20px; overflow-wrap: anywhere; }
        .error { color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        .success { color: #155724; background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        .info { color: #0c5460; background: #d1ecf1; border: 1px solid #bee5eb; padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        input { padding: 10px; font-size: 18px; width: 180px; text-align: center; letter-spacing: 5px; }
        button { padding: 10px 20px; font-size: 16px; margin-left: 10px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #0056b3; }
        form { margin: 25px 0; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .otp-box { background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; }
        @media (max-width: 480px) {
            body { margin: 16px auto; padding: 16px; }
            .otp-box { padding: 16px; }
            input { width: 100%; }
            button { width: 100%; margin: 12px 0 0; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
</head>
<body>
    <div class="otp-box">
        <h2>Enter OTP</h2>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="info"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <p>Enter the 6-digit code sent to:<br>
        <strong><?= htmlspecialchars($email) ?></strong></p>

        <form method="post">
            <input 
                required 
                name="otp" 
                type="text" 
                pattern="\d{6}" 
                maxlength="6" 
                placeholder="123456"
                autocomplete="off"
                autofocus
            >
            <button type="submit">Verify</button>
        </form>

        <p>
            <a href="resend_otp.php">Resend OTP</a>
        </p>
        
        <hr>
        <p style="font-size: 12px; color: #666;">
            <strong>Note:</strong> Check your spam/junk folder if you don't see the email.
            <br>The OTP expires in 10 minutes.
        </p>
    </div>
</body>
</html>
