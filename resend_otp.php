<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $_SESSION['flash'] = 'No email in session. Please register first.';
    header('Location: index.php'); 
    exit;
}

// Check if user exists
$stmt = $mysqli->prepare("SELECT id, otp_requested_at FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['flash'] = 'Account not found.';
    header('Location: index.php'); 
    exit;
}

// Rate limiting - prevent spam (30 seconds cooldown)
if ($user['otp_requested_at']) {
    $last_request = strtotime($user['otp_requested_at']);
    $time_diff = time() - $last_request;
    if ($time_diff < 30) {
        $_SESSION['flash'] = "Please wait " . (30 - $time_diff) . " seconds before requesting another OTP.";
        header('Location: verify_otp.php');
        exit;
    }
}

// Generate new 6-digit OTP
$otp = random_int(100000, 999999);
$otp_hash = password_hash((string)$otp, PASSWORD_DEFAULT);
$otp_expires = time() + (10 * 60); // 10 minutes from now

// Debug logging
error_log("=== NEW OTP GENERATED ===");
error_log("Email: " . $email);
error_log("OTP: " . $otp);
error_log("OTP Hash: " . $otp_hash);
error_log("Expires at: " . date('Y-m-d H:i:s', $otp_expires) . " (Timestamp: " . $otp_expires . ")");

// Update database with new OTP
$update_sql = "UPDATE users SET 
                otp_hash = ?, 
                otp_expires = ?, 
                otp_requested_at = NOW(), 
                otp_attempts = 0 
                WHERE id = ?";
                
$stmt = $mysqli->prepare($update_sql);
$stmt->bind_param("sii", $otp_hash, $otp_expires, $user['id']);

if (!$stmt->execute()) {
    error_log("Database update failed: " . $stmt->error);
    $_SESSION['flash'] = 'Failed to save OTP. Please try again.';
    header('Location: verify_otp.php');
    exit;
}
$stmt->close();

// Verify the update worked
$check_stmt = $mysqli->prepare("SELECT otp_hash, otp_expires FROM users WHERE id = ?");
$check_stmt->bind_param("i", $user['id']);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$updated_user = $check_result->fetch_assoc();
$check_stmt->close();

error_log("Database verification - Hash saved: " . ($updated_user['otp_hash'] ? "YES" : "NO"));
error_log("Database verification - Expires saved: " . ($updated_user['otp_expires'] ? $updated_user['otp_expires'] : "NO"));

// Send email with OTP
$mail = new PHPMailer(true);
try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;

    // Add these options to bypass SSL verification issues on local servers
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // Recipients
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($email);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Your OTP Verification Code';
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2>Email Verification</h2>
            <p>Your OTP verification code is:</p>
            <div style='background-color: #f4f4f4; padding: 15px; text-align: center; font-size: 32px; letter-spacing: 5px; font-weight: bold;'>
                {$otp}
            </div>
            <p>This code will expire in <strong>10 minutes</strong>.</p>
            <p>If you didn't request this code, please ignore this email.</p>
            <hr>
            <small>This is an automated message, please do not reply.</small>
        </div>
    ";
    $mail->AltBody = "Your OTP verification code is: {$otp}\nThis code expires in 10 minutes.";
    
    $mail->send();
    $_SESSION['flash'] = "A new OTP code has been sent to {$email}. Please check your inbox.";
    error_log("Email sent successfully to: " . $email);
    
} catch (Exception $e) {
    error_log("Mail error: " . $mail->ErrorInfo);
    $_SESSION['flash'] = "Failed to send OTP email. Error: " . $mail->ErrorInfo;
}

header('Location: verify_otp.php');
exit;
?>
