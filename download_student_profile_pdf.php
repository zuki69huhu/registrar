<?php
/**
 * ?id=N  The whole Student Profile of one student as a one-page A4 PDF, for a
 * registrar of that student's institution (the "Download PDF" button in the
 * profile modal). A student downloads their own (any id is ignored), laid
 * out as a one-page resume instead (profile_resume_pdf.php).
 *
 * It is the same formal sheet as the Document Summary cover of
 * download_merged_pdf.php - the institution's letterhead, then one ruled table
 * (profile_pdf_helper.php) - with the student's picture in a 2x2 box beside
 * the first rows, and every answer of the profile: personal information,
 * contact, the family with their addresses, every school, and what is still
 * missing. A blank answer prints as a dash. Logged as STUDENT_PROFILE_PDF_EXPORTED.
 */
session_start();
require_once 'db.php';
require_once 'upload_store_helper.php';
require_once 'name_helper.php';
require_once 'audit_log_helper.php';
require_once 'profile_pdf_helper.php';

$viewer_role = $_SESSION['role'] ?? '';
if (!in_array($viewer_role, ['registrar', 'student'], true) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

ensureProfileColumn($mysqli);
$user = fetchStudentForViewer($mysqli, (int)($_GET['id'] ?? 0), (int)$_SESSION['user_id'], $viewer_role);
if (!$user) {
    http_response_code(404);
    exit('Student not found.');
}

$profile = decodeStudentProfile($user['profile_data'] ?? '');
$v = [StudentSheetPdf::class, 'v'];
$institution = strtoupper(trim((string)($user['institution'] ?? '')));
$student_no = trim((string)($user['student_id'] ?? ''));
$year_sec = trim((string)($user['year_level'] ?? '') . (!empty($user['section']) ? ' - ' . $user['section'] : ''), ' -');

$birth = profileBirthDate($profile['birth_date'] ?? '');
$age = profileAge($profile['birth_date'] ?? '');
$home = $profile['home'] ?? [];
$home_line = profilePlaceComplete($home) ? profileComposePlace($home) : (string)($user['address'] ?? '');
$place = fn($key) => profileComposePlace($profile[$key] ?? []);
$father = sheetPersonName($profile['father_last_name'] ?? '', $profile['father_first_name'] ?? '', $profile['father_middle_name'] ?? '')
    ?: (string)($profile['father_name'] ?? '');
$mother = sheetPersonName($profile['mother_last_name'] ?? '', $profile['mother_first_name'] ?? '', $profile['mother_middle_name'] ?? '')
    ?: (string)($profile['mother_name'] ?? '');
$guardian_place = $place('guardian_place') ?: (string)($profile['guardian_address'] ?? '');
$missing = profileMissingFields($user, $profile);

// The picture, brought back from the database copy if the file is not on this computer.
$photo = trim((string)($user['profile_picture'] ?? ''));
$photo_file = null;
if ($photo !== '' && strpos($photo, '..') === false && strncmp($photo, 'uploads/', 8) === 0 && ensureUploadOnDisk($mysqli, $photo)) {
    $photo_file = __DIR__ . '/' . $photo;
}

$pdf = new StudentSheetPdf();
$pdf->SetTitle('Student Profile - ' . formatPersonNameRow($user), true);
$pdf->SetAuthor($institution . ' Office of the Registrar', true);

if ($viewer_role === 'student') {
    // The student's own copy is laid out like a resume (profile_resume_pdf.php).
    require_once 'profile_resume_pdf.php';
    renderStudentResume($pdf, $user, $profile, $photo_file);
} else {
$pdf->sheetHeader($institution, 'STUDENT PROFILE');

$h = 10;                                    // row height, mm
$photo_w = 33;                              // the 2x2 box beside the first three rows
$beside = StudentSheetPdf::W - $photo_w;

$pdf->band('I. PERSONAL INFORMATION');
$photo_y = $pdf->cursor;
$pdf->row([['Student ID', $v($student_no), 0.27], ['Course / Program', $v($user['course'] ?? ''), 0.5], ['Year & Section', $v($year_sec), 0.23]], $h, $beside);
$pdf->row([['Last Name', $v($user['last_name'] ?? ''), 0.31], ['First Name', $v($user['first_name'] ?? ''), 0.31], ['Middle Name', $v($user['middle_name'] ?? ''), 0.24], ['Ext. Name', $v($profile['extension_name'] ?? ''), 0.14]], $h, $beside);
$pdf->row([['Date of Birth', $birth ? $birth->format('F j, Y') : '-', 0.27], ['Age', $age !== null ? (string)$age : '-', 0.1], ['Sex', $v($profile['sex'] ?? ''), 0.15], ['Civil Status', $v($profile['civil_status'] ?? ''), 0.2], ['Nationality', $v($profile['nationality'] ?? ''), 0.28]], $h, $beside);
$pdf->photoBox($photo_file, StudentSheetPdf::L + $beside, $photo_y, $photo_w, 3 * $h);
$pdf->row([['Place of Birth', $v($profile['birth_place'] ?? ''), 0.45], ['Religion', $v($profile['religion'] ?? ''), 0.3], ['Institution', $v($institution), 0.25]], $h);
$pdf->row([['Permanent Address', $v($home_line), 0.6, true], ['Region', $v($home['region'] ?? ''), 0.28, true], ['ZIP Code', $v($home['zip'] ?? ''), 0.12]], $h);
$pdf->row([['Contact Number', $v($user['contact_number'] ?? ''), 0.21], ['Email Address', $v($user['email'] ?? ''), 0.37], ['Disability', $v($profile['disability'] ?? ''), 0.2, true], ['Indigenous People Group', $v($profile['ip_group'] ?? ''), 0.22, true]], $h);

$pdf->band('II. FAMILY BACKGROUND');
$pdf->row([["Father's Name", $v($father), 0.3, true], ['Occupation', $v($profile['father_occupation'] ?? ''), 0.18, true], ['Contact Number', $v($profile['father_contact'] ?? ''), 0.16], ['Address', $v($place('father_place')), 0.36, true]], $h);
$pdf->row([["Mother's Maiden Name", $v($mother), 0.3, true], ['Occupation', $v($profile['mother_occupation'] ?? ''), 0.18, true], ['Contact Number', $v($profile['mother_contact'] ?? ''), 0.16], ['Address', $v($place('mother_place')), 0.36, true]], $h);
$pdf->row([['Guardian / Person to Contact', $v($profile['guardian_name'] ?? ''), 0.3, true], ['Relationship', $v($profile['guardian_relationship'] ?? ''), 0.18, true], ['Contact Number', $v($profile['guardian_contact'] ?? ''), 0.16], ['Address', $v($guardian_place), 0.36, true]], $h);

$pdf->band('III. EDUCATIONAL BACKGROUND');
foreach (getProfileEducationLevels() as $key => $level) {
    $row = $profile['education'][$key] ?? [];
    $pdf->row([
        [$level['short'] ?? $level['label'], $v($row['school'] ?? ''), 0.4, true],
        ['School Address', $v($row['address'] ?? ''), 0.31, true],
        ['School Year', $v(profileSchoolYears($row)), 0.13],
        // Only senior high (track / strand) and college (course) have this answer.
        [$level['extra'] ?? '', !empty($level['extra']) ? $v($row['extra'] ?? '') : '', 0.16, true],
    ], $h);
}

$pdf->band('IV. PROFILE STATUS');
$pdf->row([
    ['Status', $missing ? 'Incomplete (' . count($missing) . ' missing)' : 'Complete', 0.24],
    ['Missing Details', $missing ? implode(', ', $missing) : 'None - every required detail is filled in.', 0.76, true],
], $h);

$pdf->signatures(sheetPreparerName($mysqli, $_SESSION['user_id']), 24);
$pdf->footnote('Student details as kept on the registrar portal. A dash (-) marks an answer not yet given.');

}

logAudit($mysqli, (int)$_SESSION['user_id'], 'STUDENT_PROFILE_PDF_EXPORTED',
    ($viewer_role === 'student' ? 'Downloaded their own Student Profile PDF' : 'Downloaded the Student Profile PDF of ' . formatPersonNameRow($user)) . ($student_no !== '' ? " ($student_no)" : '') . '.', (int)$user['id']);

// "2023-10156, Ruales, John Philip A., Student Profile.pdf"
$file = implode(', ', array_filter([$student_no, trim((string)$user['last_name']),
    trim($user['first_name'] . (!empty($user['middle_name']) ? ' ' . mb_strtoupper(mb_substr($user['middle_name'], 0, 1)) . '.' : '')),
    'Student Profile'], 'strlen'));
$file = preg_replace('/[^A-Za-z0-9,.\- ]/', '_', @iconv('UTF-8', 'ASCII//TRANSLIT', $file) ?: $file) . '.pdf';

header('Cache-Control: private, no-store');
$pdf->Output(isset($_GET['inline']) ? 'I' : 'D', $file);
