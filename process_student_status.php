<?php
session_start();
require_once 'db.php';
require_once 'notification_helper.php';
require_once 'audit_log_helper.php';

// Access control: Only allow logged-in registrars
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied. You must be a registrar to perform this action.';
    header("Location: index.php?tab=login"); // Redirect to login or appropriate page
    exit;
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid request method.';
    header("Location: registrar_dashboard.php");
    exit;
}

$student_ids = $_POST['student_id'] ?? null;
$status = trim($_POST['status'] ?? '');

if (empty($student_ids)) {
    $_SESSION['flash'] = 'Invalid student ID(s).';
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'registrar_dashboard.php'));
    exit;
}

if (!is_array($student_ids)) {
    $student_ids = [$student_ids];
}

$allowed_statuses = ['approved', 'rejected', 'archive', 'restore'];
if (!in_array($status, $allowed_statuses)) {
    $_SESSION['flash'] = 'Invalid status provided.';
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'registrar_dashboard.php'));
    exit;
}

if ($status === 'archive' || $status === 'restore') {
    $archived_value = ($status === 'archive') ? 1 : 0;
    $stmt = $mysqli->prepare("UPDATE users SET is_archived = ? WHERE id = ? AND role = 'student'");
    $count = 0;
    foreach ($student_ids as $sid) {
        $sid = intval($sid);
        if ($sid > 0) {
            $stmt->bind_param("ii", $archived_value, $sid);
            if ($stmt->execute()) {
                $count++;
                if ($stmt->affected_rows > 0) {
                    logAudit($mysqli, $_SESSION['user_id'] ?? null, $status === 'archive' ? 'STUDENT_ARCHIVED' : 'STUDENT_RESTORED',
                        ($status === 'archive' ? 'Archived' : 'Restored') . " student account #{$sid}.", $sid);
                }
            }
        }
    }
    $_SESSION['flash'] = "Successfully {$status}d {$count} student(s).";
    $stmt->close();
} else {
    $is_verified_value = ($status === 'approved') ? 2 : 3;

    $stmt = $mysqli->prepare("UPDATE users SET is_verified = ? WHERE id = ? AND role = 'student'");
    $message_to_student = ($status === 'approved') ? "Your registration has been approved. You can now log in." : "Your registration has been rejected. Please contact the registrar's office for more information.";
    $notif_type = "info";

    $count = 0;
    foreach ($student_ids as $sid) {
        $sid = intval($sid);
        if ($sid > 0) {
            $stmt->bind_param("ii", $is_verified_value, $sid);
            if ($stmt->execute()) {
                $count++;
                logAudit($mysqli, $_SESSION['user_id'] ?? null, $status === 'approved' ? 'STUDENT_APPROVED' : 'STUDENT_REJECTED',
                    "Registration {$status} for student account #{$sid}.", $sid);
                sendNotificationAndEmail($mysqli, $sid, $message_to_student, $notif_type, "Registration Status Update");
            }
        }
    }

    $_SESSION['flash'] = "Successfully {$status} {$count} student(s).";

    $stmt->close();
}

$redirect = $_SERVER['HTTP_REFERER'] ?? 'registrar_dashboard.php';
header("Location: $redirect");
exit;
