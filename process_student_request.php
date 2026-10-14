<?php
/**
 * Student side of the request flow: file a form, or cancel one that is still pending.
 *
 * The form is validated against the definition in request_config.php, so the
 * browser-side `required` attributes and this check always agree. A request is
 * always stored as `pending`: only a registrar can move it on, in
 * process_request_status.php.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/request_config.php';
require_once 'includes/notification_helper.php';
require_once 'includes/audit_log_helper.php';
require_once 'includes/name_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['flash'] = 'Access denied.';
    $_SESSION['flash_type'] = 'error';
    header("Location: index.php?tab=login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student_request.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

function requestFail($message) {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = 'error';
    header("Location: student_request.php");
    exit;
}

// The student's own record supplies the institution and the name on the audit trail.
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, student_id, institution FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    header("Location: logout.php");
    exit;
}

$institution = $student['institution'] ?? '';
$action = $_POST['action'] ?? 'submit';

// ─────────────────────────────────────────────────────────────────────────────
// Cancel — only the student's own request, and only while nobody has acted on it
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    $request_id = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
    if (!$request_id) requestFail('Invalid request.');

    $stmt = $mysqli->prepare("SELECT id, form_title, status FROM student_requests WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $request_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) requestFail('That request no longer exists.');
    if ($row['status'] !== 'pending') {
        requestFail('This request has already been reviewed, so it can no longer be cancelled. Please contact the registrar.');
    }

    $stmt = $mysqli->prepare("UPDATE student_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->bind_param("ii", $request_id, $user_id);
    $stmt->execute();
    $stmt->close();

    logAudit($mysqli, $user_id, 'REQUEST_CANCELLED', "Cancelled request #{$request_id} ({$row['form_title']}).", $user_id);

    $_SESSION['flash'] = 'Your request has been cancelled. You can file a new one whenever you are ready.';
    $_SESSION['flash_type'] = 'info';
    header("Location: student_request.php");
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Submit
// ─────────────────────────────────────────────────────────────────────────────
$form_type = trim($_POST['form_type'] ?? '');
$form = getRequestForm($institution, $form_type);
if (!$form) {
    requestFail('That form is not available for your institution yet.');
}

// One open request per form at a time, so the registrar never sees duplicates.
$stmt = $mysqli->prepare("SELECT id FROM student_requests
                          WHERE user_id = ? AND form_type = ? AND status IN ('pending','approved')");
$stmt->bind_param("is", $user_id, $form_type);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    requestFail('You already have an active ' . $form['title'] . ' request. Cancel it first if you need to change your answers.');
}

$sections = getRequestFormSections($form_type);
if (!$sections) {
    requestFail('Unknown form.');
}

$current_year = (int)date('Y');
$form_data = [];
$missing = [];

foreach ($sections as $section) {
    foreach ($section['fields'] as $field) {
        $name  = $field['name'];

        // A tick box is simply present or absent, and is never required.
        if (($field['type'] ?? 'text') === 'checkbox') {
            $form_data[$name] = !empty($_POST[$name]);
            continue;
        }

        $value = trim((string)($_POST[$name] ?? ''));

        if ($value === '') {
            if (!empty($field['required'])) $missing[] = $field['label'];
            $form_data[$name] = '';
            continue;
        }

        switch ($field['type']) {
            case 'date':
                $ts = strtotime($value);
                if (!$ts) requestFail("Please enter a valid {$field['label']}.");
                if ($ts > time()) requestFail("{$field['label']} cannot be in the future.");
                $value = date('Y-m-d', $ts);
                break;

            case 'year':
                if (!preg_match('/^\d{4}$/', $value) || (int)$value < 1950 || (int)$value > $current_year + 1) {
                    requestFail("Please enter a valid 4-digit year for \"{$field['label']}\".");
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    requestFail('Please enter a valid email address.');
                }
                break;

            case 'select':
                if (!in_array($value, $field['options'] ?? [], true)) {
                    requestFail("Please choose a valid {$field['label']}.");
                }
                break;
        }

        // The paper form asks for capital letters; the names are stored that way.
        if (in_array($name, ['last_name', 'first_name', 'middle_name'], true)) {
            $value = mb_strtoupper($value);
        }

        $form_data[$name] = mb_substr($value, 0, 500);
    }
}

if ($missing) {
    requestFail('Please complete these required fields: ' . implode(', ', $missing) . '.');
}

// ── Parts that only one form has ────────────────────────────────────────────
if ($form_type === 'graduation') {
    // Documents the student is attaching / has already submitted
    $is_transferee = !empty($_POST['is_transferee']);
    $checked = $_POST['requirements'] ?? [];
    if (!is_array($checked)) $checked = [];

    $requirements = [];
    foreach (getGraduationRequirements() as $item) {
        if ($item['group'] === 'transferee' && !$is_transferee) continue;
        $requirements[$item['key']] = isset($checked[$item['key']]);
    }
    $form_data['is_transferee'] = $is_transferee;
    $form_data['requirements']  = $requirements;

    // Declarations — every one has to be affirmed
    $declared = $_POST['declarations'] ?? [];
    if (!is_array($declared)) $declared = [];
    $declarations = [];
    foreach (getGraduationDeclarations() as $item) {
        if (!isset($declared[$item['key']])) {
            requestFail('You must confirm every statement under "Declarations" before submitting.');
        }
        $declarations[$item['key']] = true;
    }
    $form_data['declarations'] = $declarations;

    $signed_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['middle_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
} else {
    // Form 137: the printed letter ticks First or Second Request, never both.
    $form_data['first_request']  = ($form_data['request_type'] ?? '') === 'First Request';
    $form_data['second_request'] = ($form_data['request_type'] ?? '') === 'Second Request';

    $signed_name = trim((string)($form_data['student_name'] ?? ''));
}

if (empty($_POST['waiver'])) {
    requestFail('Please tick the confirmation at the bottom of the form before submitting.');
}
$form_data['waiver_accepted'] = true;
$form_data['waiver_text']     = $form['confirmation'] ?? '';
$form_data['form_code']       = $form['code'];
$form_data['signed_name']     = $signed_name ?: formatPersonName($student['first_name'] ?? '', $student['middle_name'] ?? '', $student['last_name'] ?? '');
$form_data['signed_at']       = date('Y-m-d H:i:s');

$student_note = mb_substr(trim($_POST['student_note'] ?? ''), 0, 1000);
$form_title   = $form['title'];
$payload      = json_encode($form_data, JSON_UNESCAPED_UNICODE);

$stmt = $mysqli->prepare("INSERT INTO student_requests
    (user_id, institution, form_type, form_title, form_data, status, student_note)
    VALUES (?, ?, ?, ?, ?, 'pending', ?)");
$stmt->bind_param("isssss", $user_id, $institution, $form_type, $form_title, $payload, $student_note);

if (!$stmt->execute()) {
    $stmt->close();
    requestFail('Your request could not be saved. Please try again.');
}
$request_id = $stmt->insert_id;
$stmt->close();

logAudit(
    $mysqli,
    $user_id,
    'REQUEST_SUBMITTED',
    "Filed {$form_title} (request #{$request_id}).",
    $user_id
);

sendNotificationAndEmail(
    $mysqli,
    $user_id,
    "Your {$form_title} has been received and is now waiting for the registrar's review. "
    . "You will be notified once it is approved, together with the date and time you may claim it.",
    'info',
    'Request Received'
);

// The page shows a receipt modal rather than a one-line flash, so it is handed
// the request itself and looks the rest up.
$_SESSION['request_submitted'] = [
    'id'        => (int)$request_id,
    'form_type' => $form_type,
];
header("Location: student_request.php");
exit;
