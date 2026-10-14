<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/name_helper.php';
require_once 'includes/notification_helper.php';

header('Content-Type: application/json');

// Access control: Only registrar can send reminders
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$student_id = (int)($_POST['student_id'] ?? 0);
$missing_docs = trim($_POST['missing_docs'] ?? '');

if ($student_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

if (empty($missing_docs)) {
    echo json_encode(['success' => false, 'message' => 'No missing documents specified']);
    exit;
}

// Verify student exists
$stmt = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, student_id FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

// Build notification message
$docs_list = explode(',', $missing_docs);
$docs_formatted = implode(', ', array_map('trim', $docs_list));
$doc_count = count($docs_list);

$message = "📋 Document Reminder: You have {$doc_count} missing document(s) that need to be submitted. "
         . "The following documents are still required: {$docs_formatted}. "
         . "Please submit these documents at your earliest convenience to complete your requirements.";

$subject = "Document Submission Reminder - {$doc_count} Missing Document(s)";

// Use the existing notification helper to send notification + email
$result = sendNotificationAndEmail($mysqli, $student_id, $message, 'warning', $subject);

if ($result) {
    echo json_encode([
        'success' => true, 
        'message' => 'Reminder sent to ' . formatStudentId($student['student_id'] ?? '') . ' — ' . formatPersonNameRow($student)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send notification. Please try again.']);
}
