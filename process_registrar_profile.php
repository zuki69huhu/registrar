<?php
/**
 * A registrar saves a student's profile from the profile modal
 * (student_profile_view.php?id=N&mode=edit, posted by student_profile.js).
 *
 * The answers are checked by validateStudentProfile() - exactly what the
 * student's own Edit Profile is checked against - plus the parts only a
 * registrar may change: the name and the student ID. Nothing is saved while
 * any answer is wrong. Only a student of the registrar's own institution can be
 * edited (fetchStudentForViewer), the same scoping as every registrar page.
 *
 * Answers JSON: {ok: true, message, name, missing} or {ok: false, errors: [...]}.
 * The change is written to the audit trail as STUDENT_PROFILE_UPDATED with the
 * student as the target, and the student gets an in-portal notification (no
 * e-mail, so saving stays instant).
 */
session_start();
require_once 'db.php';
require_once 'profile_form.php';
require_once 'name_helper.php';
require_once 'audit_log_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function ($status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SESSION['role'] ?? '') !== 'registrar' || empty($_SESSION['user_id'])) {
    $reply(403, ['ok' => false, 'errors' => ['Please sign in again as a registrar.']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'errors' => ['Use the Save button to send changes.']]);
}
// A file larger than post_max_size arrives with an empty $_POST.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $reply(413, ['ok' => false, 'errors' => ['The upload is too large. Choose a smaller profile picture.']]);
}

ensureProfileColumn($mysqli);
$registrar_id = (int)$_SESSION['user_id'];
$student = fetchStudentForViewer($mysqli, (int)($_POST['id'] ?? 0), $registrar_id, 'registrar');
if (!$student) {
    $reply(404, ['ok' => false, 'errors' => ['This student was not found in your institution.']]);
}
$student_id = (int)$student['id'];

$text = fn($key, $max) => profileCleanText($_POST[$key] ?? '', $max);
$first_name = $text('first_name', 100);
$middle_name = $text('middle_name', 100);
$last_name = $text('last_name', 100);
$student_no = $text('student_id', 30);
$year_level = $text('year_level', 50);
$section = $text('section', 50);
$course = $text('course', 100);
$contact_number = $text('contact_number', 20);

[$profile_clean, $errors] = validateStudentProfile($_POST['profile'] ?? [], $_POST['education'] ?? []);
if ($first_name === '') $errors[] = 'First Name is required.';
if ($last_name === '') $errors[] = 'Last Name is required.';
if ($contact_number !== '' && !preg_match(PROFILE_PHONE_PATTERN, $contact_number)) {
    $errors[] = 'Contact Number is not a valid phone number.';
}
if ($student_no !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ ]*$/', $student_no)) {
    $errors[] = 'Student ID may only hold letters, numbers and dashes.';
}
if ($student_no !== '' && $student_no !== (string)$student['student_id']) {
    $dup = $mysqli->prepare("SELECT id FROM users WHERE student_id = ? AND id <> ? AND role = 'student' LIMIT 1");
    $dup->bind_param('si', $student_no, $student_id);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) $errors[] = "Student ID $student_no already belongs to another student.";
    $dup->close();
}
if ($year_level !== '' && !in_array($year_level, PROFILE_YEAR_LEVELS, true) && $year_level !== (string)$student['year_level']) {
    $errors[] = 'Choose a Year Level from the list.';
}
if ($section !== '' && !preg_match('/^[A-Z]$/', $section) && $section !== (string)$student['section']) {
    $errors[] = 'Choose a Section from the list.';
}
if ($course !== '' && $course !== (string)$student['course']) {
    $programs = getInstitutionPrograms()[$student['institution'] ?? ''] ?? array_merge(...array_values(getInstitutionPrograms()));
    if (!in_array($course, $programs, true)) $errors[] = 'Choose a Course / Program from the list.';
}
if ($errors) {
    $reply(422, ['ok' => false, 'errors' => array_values(array_unique($errors))]);
}

// Answers typed before a field was split (a parent's one-line name, the
// guardian's typed address) are kept until the split field is filled in, so a
// registrar's save never throws away what the student entered.
$old_profile = decodeStudentProfile($student['profile_data'] ?? '');
foreach (['father_name' => 'father_last_name', 'mother_name' => 'mother_last_name', 'guardian_address' => 'guardian_place'] as $legacy => $replacement) {
    $now = $profile_clean[$replacement] ?? '';
    $empty = is_array($now) ? trim(implode('', $now)) === '' : trim((string)$now) === '';
    if (!empty($old_profile[$legacy]) && $empty) $profile_clean[$legacy] = $old_profile[$legacy];
}
$profile_json = packStudentProfile($profile_clean);

[$new_picture, $picture_error] = saveProfilePictureUpload($mysqli);
if ($picture_error !== '') {
    $reply(422, ['ok' => false, 'errors' => [$picture_error]]);
}
$profile_picture = $new_picture ?: ($student['profile_picture'] ?? null);

// users.address is the permanent address as one line (the request forms print
// it); an address typed before the picker existed stays until one is picked.
$address = profilePlaceComplete($profile_clean['home'] ?? null) ? profileComposePlace($profile_clean['home']) : null;

$stmt = $mysqli->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, student_id = ?, year_level = ?, section = ?,
                                 course = ?, contact_number = ?, address = COALESCE(?, address), profile_picture = ?, profile_data = ?
                          WHERE id = ? AND role = 'student'");
if (!$stmt) {
    $reply(500, ['ok' => false, 'errors' => ['The profile could not be saved. Please try again.']]);
}
$student_no_db = $student_no !== '' ? $student_no : null;
$stmt->bind_param('sssssssssssi', $first_name, $middle_name, $last_name, $student_no_db, $year_level, $section,
                  $course, $contact_number, $address, $profile_picture, $profile_json, $student_id);
if (!$stmt->execute()) {
    $stmt->close();
    $reply(500, ['ok' => false, 'errors' => ['The profile could not be saved. Please try again.']]);
}
$stmt->close();

$display = formatPersonName($first_name, $middle_name, $last_name);
$label = $display . ($student_no !== '' ? " ($student_no)" : '');
logAudit($mysqli, $registrar_id, 'STUDENT_PROFILE_UPDATED', "Updated the student profile of $label.", $student_id);

// A note in the student's portal, so a change is never a surprise.
$note = $mysqli->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')");
if ($note) {
    $message = "The Registrar's Office updated your Student Profile. You can review it in Settings > Edit Profile.";
    $note->bind_param('is', $student_id, $message);
    $note->execute();
    $note->close();
}

$missing = profileMissingFields(['contact_number' => $contact_number], decodeStudentProfile($profile_json));
$reply(200, [
    'ok' => true,
    'message' => 'Profile saved.',
    'name' => $display,
    'missing' => count($missing),
]);
