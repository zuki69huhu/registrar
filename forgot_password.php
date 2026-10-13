<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $_SESSION['flash_error'] = "Please enter a valid email address.";
    } else {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Generate OTP
            $otp = random_int(100000, 999999);
            $otp_hash = password_hash((string)$otp, PASSWORD_DEFAULT);
            $expires = time() + 10 * 60; // 10 minutes expiration

            $stmt = $mysqli->prepare("UPDATE users SET otp_hash = ?, otp_expires = ?, otp_requested_at = NOW(), otp_attempts = 0 WHERE id = ?");
            $stmt->bind_param("sii", $otp_hash, $expires, $user['id']);
            $stmt->execute();
            $stmt->close();

            // Send Email via PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = SMTP_SECURE;
                $mail->Port = SMTP_PORT;

                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom(FROM_EMAIL, FROM_NAME);
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset OTP';
                $mail->Body = "
                    <p>You requested a password reset.</p>
                    <p>Your verification code is: <strong style='font-size: 24px;'>{$otp}</strong></p>
                    <p>This code expires in 10 minutes.</p>
                ";

                $mail->send();
                $_SESSION['reset_email'] = $email;
                header("Location: verify_reset_otp.php");
                exit;
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Failed to send email. Please try again later.";
            }
        } else {
            $_SESSION['flash_error'] = "Email not found in our records.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; min-height: 100dvh; margin: 0; padding: 16px; }
        .card { background: transparent; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        input, button { font-size: 16px; }
        @media (max-width: 480px) { .card { padding: 28px 20px; } }
        h2 { margin-top: 0; color: #1e293b; }
        p { color: #64748b; font-size: 14px; margin-bottom: 20px; }
        input[type="email"] { width: 100%; padding: 12px; margin-bottom: 20px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
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
        <h2>Forgot Password</h2>
        <p>Enter your registered email address to receive a verification code.</p>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="error"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="email" name="email" placeholder="Email Address" required>
            <button type="submit">Send Code</button>
        </form>
        <a href="index.php?tab=login" class="back">Back to Login</a>
    </div>
</body>
</html>
