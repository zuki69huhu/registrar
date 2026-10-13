<?php
/**
 * Registrar side of the request flow. This is the only place a request leaves
 * `pending`, the same way process_document_status.php is the only place a
 * document does.
 *
 *   approve  — sets the claim schedule (date, time, where) and tells the student
 *   reschedule — moves the claim schedule of an already approved request
 *   reject   — refuses it, with a written note the student receives
 *   claim    — records that the document was actually handed over, date and time
 *
 * A registrar may only act on requests filed by students of their own
 * users.institution.
 */

session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'name_helper.php';
require_once 'notification_helper.php';
require_once 'audit_log_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied. You must be a registrar to perform this action.';
    $_SESSION['flash_type'] = 'error';
    header("Location: index.php?tab=login");
    exit;
}

$redirect_to = $_POST['redirect_to'] ?? 'registrar_requests.php';
if (preg_match('#^(https?:)?//#i', $redirect_to)) {
    $redirect_to = 'registrar_requests.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $redirect_to);
    exit;
}

function requestStatusError($message, $redirect_to) {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = 'error';
    header("Location: " . $redirect_to);
    exit;
}

$registrar_id = (int)($_SESSION['user_id'] ?? 0);
$request_id   = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
$action       = trim($_POST['action'] ?? '');

if (!$request_id) requestStatusError('Invalid request.', $redirect_to);
if (!in_array($action, ['approve', 'reschedule', 'reject', 'claim'], true)) {
    requestStatusError('Unknown action.', $redirect_to);
}

// The registrar's institution scopes everything below.
$registrar_institution = '';
$registrar_name = 'the registrar';
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, institution FROM users WHERE id = ?");
$stmt->bind_param("i", $registrar_id);
$stmt->execute();
if ($reg = $stmt->get_result()->fetch_assoc()) {
    $registrar_institution = $reg['institution'] ?? '';
    $registrar_name = trim(($reg['first_name'] ?? '') . ' ' . ($reg['last_name'] ?? '')) ?: 'the registrar';
}
$stmt->close();

// The request, plus the student it belongs to
$stmt = $mysqli->prepare("SELECT r.*, u.first_name, u.middle_name, u.last_name, u.student_id, u.institution AS student_institution
                          FROM student_requests r
                          JOIN users u ON r.user_id = u.id
                          WHERE r.id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) requestStatusError('That request no longer exists.', $redirect_to);

if (!empty($registrar_institution) && !empty($request['student_institution'])
    && $request['student_institution'] !== $registrar_institution) {
    requestStatusError('You can only act on requests from your own institution.', $redirect_to);
}

$student_id_fk = (int)$request['user_id'];
$student_name  = formatPersonNameRow($request);
$form_title    = $request['form_title'] ?: 'request';

/** Combines the date and time inputs into one DATETIME, or fails with a message. */
function claimDateTime($date, $time, $redirect_to, $label = 'claim schedule') {
    $date = trim((string)$date);
    $time = trim((string)$time);
    if ($date === '' || $time === '') {
        requestStatusError("Please set both the date and the time for the {$label}.", $redirect_to);
    }
    $ts = strtotime($date . ' ' . $time);
    if (!$ts) requestStatusError("That {$label} is not a valid date and time.", $redirect_to);
    return date('Y-m-d H:i:s', $ts);
}

switch ($action) {

    // ─────────────────────────────────────────────────────────────────────────
    case 'approve':
    case 'reschedule':
        if ($action === 'approve' && $request['status'] !== 'pending') {
            requestStatusError('Only a pending request can be approved.', $redirect_to);
        }
        if ($action === 'reschedule' && $request['status'] !== 'approved') {
            requestStatusError('Only an approved request can be rescheduled.', $redirect_to);
        }

        $claim_at = claimDateTime($_POST['claim_date'] ?? '', $_POST['claim_time'] ?? '', $redirect_to);

        // A pick-up in the past would be meaningless to the student.
        if (strtotime($claim_at) < strtotime(date('Y-m-d'))) {
            requestStatusError('The claim date cannot be in the past.', $redirect_to);
        }

        $location = trim($_POST['claim_location'] ?? '');
        if ($location === '') $location = REQUEST_DEFAULT_CLAIM_LOCATION;
        $location = mb_substr($location, 0, 255);

        $note = mb_substr(trim($_POST['registrar_note'] ?? ''), 0, 1000);
        if ($note === '') $note = null;

        $stmt = $mysqli->prepare("UPDATE student_requests
                                  SET status = 'approved', claim_at = ?, claim_location = ?, registrar_note = ?,
                                      reviewed_by = ?, reviewed_at = NOW()
                                  WHERE id = ?");
        $stmt->bind_param("sssii", $claim_at, $location, $note, $registrar_id, $request_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) requestStatusError('The request could not be updated. Please try again.', $redirect_to);

        $when = formatClaimSchedule($claim_at);

        if ($action === 'approve') {
            logAudit($mysqli, $registrar_id, 'REQUEST_APPROVED',
                "Approved {$form_title} (request #{$request_id}) for {$student_name}. Claim on {$when} at {$location}."
                . ($note ? " Note: {$note}" : ''), $student_id_fk);

            $message = "Your {$form_title} has been APPROVED. You may claim it on {$when} at the {$location}. "
                     . "Please bring a valid ID.";
            if ($note) $message .= " Note from the registrar: {$note}";

            sendNotificationAndEmail($mysqli, $student_id_fk, $message, 'success', 'Request Approved — Ready for Claim');

            $_SESSION['flash'] = "{$form_title} for {$student_name} approved. Claim schedule: {$when}.";
            $_SESSION['flash_type'] = 'success';
        } else {
            logAudit($mysqli, $registrar_id, 'REQUEST_RESCHEDULED',
                "Moved the claim schedule of request #{$request_id} ({$student_name}) to {$when} at {$location}.", $student_id_fk);

            $message = "The claim schedule for your {$form_title} has been changed. The new schedule is {$when} at the {$location}.";
            if ($note) $message .= " Note from the registrar: {$note}";

            sendNotificationAndEmail($mysqli, $student_id_fk, $message, 'info', 'Claim Schedule Updated');

            $_SESSION['flash'] = "Claim schedule for {$student_name} moved to {$when}.";
            $_SESSION['flash_type'] = 'success';
        }
        break;

    // ─────────────────────────────────────────────────────────────────────────
    case 'reject':
        if (!in_array($request['status'], ['pending', 'approved'], true)) {
            requestStatusError('This request has already been closed.', $redirect_to);
        }

        $note = trim($_POST['registrar_note'] ?? '');
        if (mb_strlen($note) < REQUEST_MIN_NOTE_LENGTH) {
            requestStatusError('Please write the reason so the student knows what to fix.', $redirect_to);
        }
        $note = mb_substr($note, 0, 1000);

        $stmt = $mysqli->prepare("UPDATE student_requests
                                  SET status = 'rejected', registrar_note = ?, claim_at = NULL, claim_location = NULL,
                                      reviewed_by = ?, reviewed_at = NOW()
                                  WHERE id = ?");
        $stmt->bind_param("sii", $note, $registrar_id, $request_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) requestStatusError('The request could not be updated. Please try again.', $redirect_to);

        logAudit($mysqli, $registrar_id, 'REQUEST_REJECTED',
            "Did not approve {$form_title} (request #{$request_id}) for {$student_name}. Reason: {$note}", $student_id_fk);

        sendNotificationAndEmail(
            $mysqli,
            $student_id_fk,
            "Your {$form_title} was NOT approved. Reason: {$note} You may file the request again once this is settled.",
            'warning',
            'Request Not Approved'
        );

        $_SESSION['flash'] = "{$form_title} for {$student_name} was not approved, and your note was sent to the student.";
        $_SESSION['flash_type'] = 'info';
        break;

    // ─────────────────────────────────────────────────────────────────────────
    case 'claim':
        if ($request['status'] !== 'approved') {
            requestStatusError('Only an approved request can be marked as claimed.', $redirect_to);
        }

        $claimed_at = claimDateTime($_POST['claimed_date'] ?? '', $_POST['claimed_time'] ?? '', $redirect_to, 'release date and time');
        if (strtotime($claimed_at) > time() + 300) {
            requestStatusError('The release date and time cannot be in the future.', $redirect_to);
        }

        $received_by = mb_substr(trim($_POST['claimed_by_name'] ?? ''), 0, 191);
        if ($received_by === '') $received_by = $student_name;

        $stmt = $mysqli->prepare("UPDATE student_requests
                                  SET status = 'claimed', claimed_at = ?, claimed_by_name = ?, released_by = ?
                                  WHERE id = ?");
        $stmt->bind_param("ssii", $claimed_at, $received_by, $registrar_id, $request_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) requestStatusError('The request could not be updated. Please try again.', $redirect_to);

        $when = formatClaimSchedule($claimed_at);

        logAudit($mysqli, $registrar_id, 'REQUEST_CLAIMED',
            "Released {$form_title} (request #{$request_id}) for {$student_name} on {$when}. Received by {$received_by}.",
            $student_id_fk);

        sendNotificationAndEmail(
            $mysqli,
            $student_id_fk,
            "Your {$form_title} was released on {$when} and received by {$received_by}. "
            . "If this was not you, please contact the registrar's office immediately.",
            'success',
            'Request Claimed'
        );

        $_SESSION['flash'] = "{$form_title} for {$student_name} marked as claimed on {$when}.";
        $_SESSION['flash_type'] = 'success';
        break;
}

header("Location: " . $redirect_to);
exit;
