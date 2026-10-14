<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/audit_log_helper.php';
require_once 'includes/upload_store_helper.php';

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['flash'] = 'Access denied. You must be a student to perform this action.';
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $_SESSION['flash'] = 'User not logged in.';
    header("Location: index.php?tab=login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid request method.';
    header("Location: checklist.php");
    exit;
}

$document_id = filter_var($_POST['document_id'] ?? '', FILTER_VALIDATE_INT);

if (!$document_id) {
    $_SESSION['flash'] = 'Invalid document ID.';
    header("Location: checklist.php");
    exit;
}

// Fetch document details to verify ownership and get file path
$stmt = $mysqli->prepare("SELECT file_path, file_name, document_type FROM documents WHERE id = ? AND user_id = ?");
if (!$stmt) {
    $_SESSION['flash'] = "Database error preparing statement: " . $mysqli->error;
    header("Location: checklist.php");
    exit;
}
$stmt->bind_param("ii", $document_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$document = $result->fetch_assoc();
$stmt->close();

if (!$document) {
    $_SESSION['flash'] = 'Document not found or you do not have permission to delete it.';
    header("Location: checklist.php");
    exit;
}

$file_path = $document['file_path'];

// Delete file from server
if (file_exists($file_path)) {
    if (!unlink($file_path)) {
        $_SESSION['flash'] = '❌ Failed to delete the file from the server.';
        $_SESSION['flash_type'] = 'error';
        header("Location: checklist.php");
        exit;
    }
} else {
    // Log if file not found, but proceed with DB deletion as it might be a stale record
    error_log("Attempted to delete non-existent file: " . $file_path);
}
deleteStoredUpload($mysqli, $file_path);

// Delete record from database
$stmt = $mysqli->prepare("DELETE FROM documents WHERE id = ? AND user_id = ?");
if (!$stmt) {
    $_SESSION['flash'] = "❌ Database error preparing statement: " . $mysqli->error;
    $_SESSION['flash_type'] = 'error';
    header("Location: checklist.php");
    exit;
}
$stmt->bind_param("ii", $document_id, $user_id);

if ($stmt->execute()) {
    logAudit($mysqli, $user_id, 'DOCUMENT_DELETED', 'Deleted ' . ($document['document_type'] ?: 'document') . " ({$document['file_name']}).", $user_id);
    $_SESSION['flash'] = '✅ Document deleted successfully!';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash'] = '❌ Failed to delete document record from database: ' . $stmt->error;
    $_SESSION['flash_type'] = 'error';
}
$stmt->close();

header("Location: checklist.php");
exit;
?>
