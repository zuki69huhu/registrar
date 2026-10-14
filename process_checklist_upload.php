<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/upload_config.php';
require_once 'includes/audit_log_helper.php';
require_once 'includes/notification_helper.php';
require_once 'includes/upload_store_helper.php';

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['flash'] = 'Access denied. You must be a student to upload documents.';
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $_SESSION['flash'] = 'User not logged in.';
    header("Location: index.php?tab=login");
    exit;
}

$max_mb = (int)(DOC_MAX_UPLOAD_BYTES / 1048576);

function uploadError($message) {
    $_SESSION['flash'] = "❌ Upload failed. Reason: {$message}";
    $_SESSION['flash_type'] = 'error';
    header("Location: checklist.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid request or no file selected.';
    header("Location: checklist.php");
    exit;
}

// A request larger than post_max_size arrives with empty $_POST and $_FILES
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    uploadError("The file is too large. Maximum size is {$max_mb} MB.");
}

$document_type = trim($_POST['document_type'] ?? '');
$file = $_FILES['document_file'] ?? null;

if (empty($document_type)) {
    uploadError('Document type is missing.');
}

if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    uploadError('Please choose a file to upload.');
}
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > DOC_MAX_UPLOAD_BYTES) {
    uploadError("The file is too large. Maximum size is {$max_mb} MB.");
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    uploadError("The file could not be uploaded (error code {$file['error']}). Please try again.");
}
if ($file['size'] === 0) {
    uploadError('The selected file is empty.');
}

$raw_file_name = basename($file['name']);
$file_ext = strtolower(pathinfo($raw_file_name, PATHINFO_EXTENSION));
if (!in_array($file_ext, DOC_ALLOWED_EXTENSIONS, true)) {
    uploadError('Invalid file type. Only PDF, PNG, and JPEG files are allowed.');
}

// Standardize document file name to short format based on document type
// e.g. Form 138 -> form138.jpg / form138.png, PSA -> psa.jpg, etc.
$doc_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $document_type));
if (empty($doc_slug)) {
    $doc_slug = 'document';
}
$file_name = $doc_slug . '.' . $file_ext;

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Unique file path on disk to prevent collisions
$target_path = $upload_dir . time() . '_' . (int)$user_id . '_' . safeUploadFileName($file_name);

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    uploadError('The file could not be saved on the server. Please try again.');
}

// Insert document record with pending status first
$stmt = $mysqli->prepare("INSERT INTO documents (user_id, file_name, file_path, status, document_type) VALUES (?, ?, ?, ?, ?)");
if (!$stmt) {
    unlink($target_path);
    uploadError('Database error preparing statement: ' . $mysqli->error);
}
$status = 'pending';
$stmt->bind_param("issss", $user_id, $file_name, $target_path, $status, $document_type);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    unlink($target_path);
    uploadError('Failed to record document in database: ' . $error);
}
$doc_id = $mysqli->insert_id;
$stmt->close();

// Keep a copy in the DB so the file survives moving the project or restoring a backup
storeUploadInDb($mysqli, $target_path);

// ─── AWAIT REGISTRAR REVIEW ───
// Nothing is decided automatically: the document stays 'pending' until a registrar
// approves it, or rejects it with a written reason (process_document_status.php).
logAudit($mysqli, $user_id, 'DOCUMENT_UPLOADED', "Uploaded {$document_type} ({$file_name}). Waiting for registrar review.", $user_id);

$notif_message = "Your document '{$document_type}' ({$file_name}) has been submitted and is now waiting for the registrar to review it. You will be notified once it has been checked.";
sendNotificationAndEmail($mysqli, $user_id, $notif_message, 'info', "Document Submitted for Review");

$_SESSION['flash'] = "Document '{$file_name}' for '{$document_type}' was submitted successfully. It is now pending review by the registrar.";
$_SESSION['flash_type'] = 'info';

header("Location: checklist.php");
exit;
