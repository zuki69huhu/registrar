<?php
session_start();
require_once 'db.php';
require_once 'notification_helper.php';
require_once 'audit_log_helper.php';

// Access control: Only allow logged-in registrars
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied. You must be a registrar to perform this action.';
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid request method.';
    header("Location: registrar_dashboard.php");
    exit;
}

$message = trim($_POST['message'] ?? '');

if (empty($message)) {
    $_SESSION['flash'] = 'Broadcast message cannot be empty.';
    header("Location: registrar_dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header("Location: index.php");
    exit;
}

// Get the institution of the current registrar
$stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$registrar = $res->fetch_assoc();
$stmt->close();

if (!$registrar || empty($registrar['institution'])) {
    $_SESSION['flash'] = 'You do not have an institution assigned. Cannot broadcast.';
    header("Location: registrar_dashboard.php");
    exit;
}

$institution = $registrar['institution'];

// Get all students of that institution
$stmt = $mysqli->prepare("SELECT id FROM users WHERE role = 'student' AND institution = ?");
$stmt->bind_param("s", $institution);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($students)) {
    $_SESSION['flash'] = 'No students found in your institution to notify.';
    header("Location: registrar_dashboard.php");
    exit;
}

// Each announcement gets its own subject, taken from its opening words. With one
// fixed subject Gmail threads every broadcast together and hides whatever repeats
// (the header, the footer) behind "•••", so students saw only part of it.
$preview = preg_replace('/\s+/u', ' ', $message);
$subject = 'Announcement: ' . (mb_strlen($preview) > 60 ? rtrim(mb_substr($preview, 0, 60)) . '…' : $preview);

// Insert notifications
$count = 0;
foreach ($students as $student) {
    $sid = $student['id'];
    if (sendNotificationAndEmail($mysqli, $sid, $message, 'info', $subject, 'announcement')) {
        $count++;
    }
}

logAudit($mysqli, $user_id, 'BROADCAST_SENT', "Sent announcement to {$count} {$institution} student(s): " . mb_substr($message, 0, 200));
$_SESSION['flash'] = "Broadcast sent successfully to {$count} {$institution} student(s).";
header("Location: registrar_dashboard.php");
exit;
