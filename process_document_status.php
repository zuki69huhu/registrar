<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/upload_config.php';
require_once 'includes/notification_helper.php';
require_once 'includes/audit_log_helper.php';

// Access control: Only allow logged-in registrars
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied. You must be a registrar to perform this action.';
    header("Location: index.php"); // Redirect to login or appropriate page
    exit;
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid request method.';
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'submissions.php'));
    exit;
}

$document_id = filter_var($_POST['doc_id'] ?? '', FILTER_VALIDATE_INT);
$status      = trim($_POST['status'] ?? '');
$feedback    = trim($_POST['feedback'] ?? '');
$redirect_to = $_POST['redirect_to'] ?? $_SERVER['HTTP_REFERER'] ?? 'submissions.php';

// Only ever redirect back inside the app
if (preg_match('#^(https?:)?//#i', $redirect_to)) {
    $redirect_to = 'submissions.php';
}

function statusError($message, $redirect_to) {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = 'error';
    header("Location: " . $redirect_to);
    exit;
}

// Validate inputs
if (!$document_id) {
    statusError('Invalid document ID.', $redirect_to);
}

$allowed_statuses = ['approved', 'rejected'];
if (!in_array($status, $allowed_statuses, true)) {
    statusError('Invalid status provided.', $redirect_to);
}

// A rejection must always carry the registrar's note explaining why.
if ($status === 'rejected' && mb_strlen($feedback) < DOC_MIN_FEEDBACK_LENGTH) {
    statusError('Please write the reason for rejecting the document so the student knows what to fix.', $redirect_to);
}
// An approval note is optional; nothing else is stored as feedback.
if ($status === 'approved' && $feedback === '') {
    $feedback = null;
}

// Get the document, the student it belongs to, and the student's institution
$registrar_id = $_SESSION['user_id'] ?? null;
$registrar_institution = '';
if ($registrar_id) {
    $reg_stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
    $reg_stmt->bind_param("i", $registrar_id);
    $reg_stmt->execute();
    $registrar_institution = ($reg_stmt->get_result()->fetch_assoc()['institution'] ?? '');
    $reg_stmt->close();
}

$doc_stmt = $mysqli->prepare("SELECT d.user_id, d.document_type, d.file_name, d.status AS current_status, u.institution
                              FROM documents d
                              JOIN users u ON d.user_id = u.id
                              WHERE d.id = ?");
$doc_stmt->bind_param("i", $document_id);
$doc_stmt->execute();
$doc_data = $doc_stmt->get_result()->fetch_assoc();
$doc_stmt->close();

if (!$doc_data) {
    statusError('That document no longer exists.', $redirect_to);
}

// Registrars only decide on documents from their own institution
if (!empty($registrar_institution) && !empty($doc_data['institution'])
    && $doc_data['institution'] !== $registrar_institution) {
    statusError('You can only review documents from your own institution.', $redirect_to);
}

$doc_name = $doc_data['document_type'] ?: $doc_data['file_name'];

// Nothing to do if the document is already in that state with the same note
if ($doc_data['current_status'] === $status && $status === 'approved') {
    $_SESSION['flash'] = "'{$doc_name}' is already approved.";
    $_SESSION['flash_type'] = 'info';
    header("Location: " . $redirect_to);
    exit;
}

// Update document status, the registrar's note, and who reviewed it
$stmt = $mysqli->prepare("UPDATE documents SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
$stmt->bind_param("ssii", $status, $feedback, $registrar_id, $document_id);

if ($stmt->execute()) {
    $_SESSION['flash'] = ($status === 'approved')
        ? "'{$doc_name}' has been approved."
        : "'{$doc_name}' has been rejected and the student was sent your note.";
    $_SESSION['flash_type'] = ($status === 'approved') ? 'success' : 'info';

    $audit_details = "Marked {$doc_name} (document #{$document_id}) as {$status}." . ($feedback ? " Note: {$feedback}" : '');
    logAudit($mysqli, $registrar_id, 'DOCUMENT_' . strtoupper($status), $audit_details, $doc_data['user_id'] ?? null);

    // Send Notification to user if user_id exists
    if (!empty($doc_data['user_id'])) {
        if ($status === 'approved') {
            $notif_type = 'success';
            $message = "Your document '{$doc_name}' has been reviewed and APPROVED by the registrar.";
            if ($feedback) {
                $message .= " Note from the registrar: {$feedback}";
            }
            $subject = 'Document Approved';
        } else {
            $notif_type = 'warning';
            $message = "Your document '{$doc_name}' was NOT approved by the registrar. Reason: {$feedback} "
                     . "Please upload a corrected copy from your Document Checklist.";
            $subject = 'Document Not Approved';
        }

        sendNotificationAndEmail($mysqli, $doc_data['user_id'], $message, $notif_type, $subject);
    }
} else {
    $_SESSION['flash'] = "Failed to update document status: " . $stmt->error;
    $_SESSION['flash_type'] = 'error';
}
$stmt->close();

header("Location: " . $redirect_to);
exit;
