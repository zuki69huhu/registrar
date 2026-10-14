<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/config.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? '';
$role = $_POST['role'] ?? '';

// New fields from the registration form
$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_id = trim($_POST['student_id'] ?? '');
$teacher_id = trim($_POST['teacher_id'] ?? '');

if (!$email) {
    $_SESSION['flash'] = 'Please enter a valid email.';
    header('Location: index.php'); exit;
}
if (strlen($password) < 6 || $password !== $password_confirm) {
    $_SESSION['flash'] = 'Password must be at least 6 chars and match confirmation.';
    header('Location: index.php'); exit;
}
// Basic validation for new fields
if (empty($first_name) || empty($last_name)) {
    $_SESSION['flash'] = 'First name and last name are required.';
    header('Location: index.php'); exit;
}
if ($role === 'student' && empty($student_id)) {
    $_SESSION['flash'] = 'Student ID is required for student registration.';
    header('Location: index.php'); exit;
}
if ($role === 'registrar' && empty($teacher_id)) {
    $_SESSION['flash'] = 'Teacher ID is required for registrar registration.';
    header('Location: index.php'); exit;
}
if (!in_array($role, ['student', 'registrar', 'systemadmin'])) {
    $_SESSION['flash'] = 'Please select a valid role.';
    header('Location: index.php'); exit;
}

// Check if user exists
$stmt = $mysqli->prepare('SELECT id, is_verified FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Generate 6-digit OTP
$otp = random_int(100000, 999999);
$otp_hash = password_hash((string)$otp, PASSWORD_DEFAULT);
error_log("REGISTER: Generated OTP: " . $otp . ", Hashed OTP: " . $otp_hash . ", Email: " . $email);
$expires = time() + 10 * 60; // 10 minutes

$hashed_pass = password_hash($password, PASSWORD_DEFAULT);

// Convert empty IDs to null to avoid UNIQUE constraint violations in the database
$student_id = $student_id === '' ? null : $student_id;
$teacher_id = $teacher_id === '' ? null : $teacher_id;
$institution = trim($_POST['institution'] ?? '');
$institution = $institution === '' ? null : $institution;
$year_level = trim($_POST['year_level'] ?? '');
$year_level = $year_level === '' ? null : $year_level;
$course = trim($_POST['department'] ?? '');
$course = $course === '' ? null : $course;
$section = trim($_POST['section'] ?? '');
$section = $section === '' ? null : strtoupper($section);

// Ensure 'section' column exists in users table
$check_col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'section'");
if ($check_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN section VARCHAR(50) NULL AFTER year_level");
}


if ($user) {
    // Update password and set new OTP (if account already exists but not verified, allow re-send OTP)
    $stmt = $mysqli->prepare('UPDATE users SET password = ?, role = ?, first_name = ?, middle_name = ?, last_name = ?, student_id = ?, teacher_id = ?, institution = ?, year_level = ?, course = ?, section = ?, otp_hash = ?, otp_expires = ?, otp_requested_at = NOW(), otp_attempts = 0 WHERE email = ?');
    $stmt->bind_param('ssssssssssssis', $hashed_pass, $role, $first_name, $middle_name, $last_name, $student_id, $teacher_id, $institution, $year_level, $course, $section, $otp_hash, $expires, $email);
    $stmt->execute();
    $stmt->close();
    $user_id = $user['id'];
} else {
    // Insert new
    $stmt = $mysqli->prepare('INSERT INTO users (email, password, role, first_name, middle_name, last_name, student_id, teacher_id, institution, year_level, course, section, otp_hash, otp_expires, otp_requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->bind_param('sssssssssssssi', $email, $hashed_pass, $role, $first_name, $middle_name, $last_name, $student_id, $teacher_id, $institution, $year_level, $course, $section, $otp_hash, $expires);
    $stmt->execute();
    $user_id = $stmt->insert_id;
    $stmt->close();
}

// Send OTP email using PHPMailer
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port = SMTP_PORT;

    // Add these options to bypass SSL verification issues on local servers
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
    $mail->Subject = 'Your OTP code';
    $mail->Body = "
        <p>Your verification code is: <strong>{$otp}</strong></p>
        <p>This code expires in 10 minutes.</p>
    ";

    $mail->send();
    // store email in session for verify page
    $_SESSION['email'] = $email;
    $_SESSION['flash'] = 'OTP sent to ' . htmlentities($email) . '. Check your inbox.';
    header('Location: verify_otp.php');
    exit;
} catch (Exception $e) {
    // If email send fails, you may want to remove OTP from DB or let user try again.
    $_SESSION['flash'] = 'Could not send OTP: ' . $mail->ErrorInfo;
    header('Location: index.php'); exit;
}
