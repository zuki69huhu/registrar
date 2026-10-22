<?php
/**
 * Student profiles as an Excel workbook, for a registrar.
 *
 *   export_student_profiles.php                 every active student of the registrar's institution
 *   export_student_profiles.php?course=&year=   narrowed the same way Analytics narrows its table
 *   export_student_profiles.php?id=N            one student (from the profile modal)
 *
 * One row per student, one column per answer of the Student Profile
 * (profile_config.php), plus the account details the registrar already keeps.
 * A blank answer stays an empty cell; the last two columns say whether the
 * profile is complete and what is still missing.
 */
session_start();
require_once 'db.php';
require_once 'name_helper.php';
require_once 'profile_config.php';
require_once 'audit_log_helper.php';
require_once 'xlsx_writer.php';

if (($_SESSION['role'] ?? '') !== 'registrar' || empty($_SESSION['user_id'])) {
    $_SESSION['flash'] = 'Access denied.';
    header('Location: index.php?tab=login');
    exit;
}

ensureProfileColumn($mysqli);

$stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$institution = trim((string)($stmt->get_result()->fetch_assoc()['institution'] ?? ''));
$stmt->close();

// Same students as Analytics: approved accounts, scoped to the registrar's institution.
$where = "u.role = 'student' AND u.is_verified = 2";
$types = '';
$params = [];
if ($institution !== '') { $where .= ' AND u.institution = ?'; $types .= 's'; $params[] = $institution; }
$one_id = (int)($_GET['id'] ?? 0);
$course = trim((string)($_GET['course'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));
if ($one_id > 0) {
    $where .= ' AND u.id = ?'; $types .= 'i'; $params[] = $one_id;
} else {
    if ($course !== '') { $where .= ' AND u.course = ?';     $types .= 's'; $params[] = $course; }
    if ($year !== '')   { $where .= ' AND u.year_level = ?'; $types .= 's'; $params[] = $year; }
}

$stmt = $mysqli->prepare("SELECT u.id, u.student_id, u.last_name, u.first_name, u.middle_name, u.email, u.contact_number,
                                 u.address, u.course, u.year_level, u.section, u.institution, u.profile_data
                          FROM users u WHERE $where ORDER BY u.last_name ASC, u.first_name ASC");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($one_id > 0 && !$students) {
    http_response_code(404);
    exit('Student not found.');
}

$place = fn($p, $key) => is_array($p) ? trim((string)($p[$key] ?? '')) : '';
$columns = [
    // [header, width]
    ['Student ID', 14], ['Last Name', 16], ['First Name', 18], ['Middle Name', 16], ['Ext. Name', 9],
    ['Sex', 8], ['Date of Birth', 13], ['Age', 6], ['Civil Status', 12], ['Place of Birth', 24],
    ['Nationality', 12], ['Religion', 16], ['Disability', 14], ['Indigenous People Group', 18],
    ['Institution', 11], ['Course / Program', 30], ['Year Level', 10], ['Section', 8],
    ['Email Address', 30], ['Contact Number', 15],
    ['Region', 16], ['Province', 18], ['City / Municipality', 20], ['Barangay', 18], ['House No. / Street / Purok', 22], ['ZIP Code', 9],
    ['Permanent Address', 40],
    ["Father's Last Name", 16], ["Father's Given Name", 18], ["Father's Middle Name", 16], ["Father's Occupation", 16], ["Father's Contact", 15], ["Father's Address", 36],
    ["Mother's Maiden Last Name", 16], ["Mother's Given Name", 18], ["Mother's Maiden Middle Name", 16], ["Mother's Occupation", 16], ["Mother's Contact", 15], ["Mother's Address", 36],
    ['Guardian', 22], ['Relationship', 13], ["Guardian's Contact", 15], ["Guardian's Address", 36],
];
foreach (getProfileEducationLevels() as $key => $level) {
    $label = $level['short'] ?? $level['label'];
    $columns[] = [$label, 30];
    $columns[] = [$label . ' - Address', 30];
    $columns[] = [$label . ' - S.Y.', 12];
    if (!empty($level['extra'])) $columns[] = [$label . ' - ' . $level['extra'], 18];
}
$columns[] = ['Profile Complete', 10];
$columns[] = ['Missing Details', 50];

$rows = [];
foreach ($students as $s) {
    $p = decodeStudentProfile($s['profile_data'] ?? '');
    $birth = profileBirthDate($p['birth_date'] ?? '');
    $age = profileAge($p['birth_date'] ?? '');
    $home = $p['home'] ?? [];
    $home_line = profilePlaceComplete($home) ? profileComposePlace($home) : trim((string)($s['address'] ?? ''));
    $missing = profileMissingFields($s, $p);

    $row = [
        trim((string)$s['student_id']), $s['last_name'], $s['first_name'], $s['middle_name'], $p['extension_name'] ?? '',
        $p['sex'] ?? '', $birth ? $birth->format('m/d/Y') : '', $age !== null ? (string)$age : '', $p['civil_status'] ?? '', $p['birth_place'] ?? '',
        $p['nationality'] ?? '', $p['religion'] ?? '', $p['disability'] ?? '', $p['ip_group'] ?? '',
        $s['institution'], $s['course'], $s['year_level'], $s['section'],
        $s['email'], $s['contact_number'],
        $place($home, 'region'), $place($home, 'province'), $place($home, 'city'), $place($home, 'barangay'), $place($home, 'street'), $place($home, 'zip'),
        $home_line,
        // A parent's name typed as one line before it was split still comes through.
        ($p['father_last_name'] ?? '') !== '' || ($p['father_first_name'] ?? '') !== '' ? ($p['father_last_name'] ?? '') : ($p['father_name'] ?? ''),
        $p['father_first_name'] ?? '', $p['father_middle_name'] ?? '', $p['father_occupation'] ?? '', $p['father_contact'] ?? '',
        profileComposePlace($p['father_place'] ?? []),
        ($p['mother_last_name'] ?? '') !== '' || ($p['mother_first_name'] ?? '') !== '' ? ($p['mother_last_name'] ?? '') : ($p['mother_name'] ?? ''),
        $p['mother_first_name'] ?? '', $p['mother_middle_name'] ?? '', $p['mother_occupation'] ?? '', $p['mother_contact'] ?? '',
        profileComposePlace($p['mother_place'] ?? []),
        $p['guardian_name'] ?? '', $p['guardian_relationship'] ?? '', $p['guardian_contact'] ?? '',
        profileComposePlace($p['guardian_place'] ?? []) ?: ($p['guardian_address'] ?? ''),
    ];
    foreach (getProfileEducationLevels() as $key => $level) {
        $edu = $p['education'][$key] ?? [];
        $row[] = $edu['school'] ?? '';
        $row[] = $edu['address'] ?? '';
        $row[] = profileSchoolYears($edu);
        if (!empty($level['extra'])) $row[] = $edu['extra'] ?? '';
    }
    $row[] = $missing ? 'No' : 'Yes';
    $row[] = implode(', ', $missing);
    $rows[] = array_map(fn($v) => is_string($v) || is_numeric($v) ? (string)$v : '', $row);
}

// File name: one student, or the scope of the list.
if ($one_id > 0) {
    $s = $students[0];
    $file = 'Student Profile - ' . trim((string)$s['student_id']) . ' - ' . formatPersonNameRow($s);
} else {
    $file = 'Student Profiles - ' . implode(' - ', array_filter([$institution, $course, $year, date('Y-m-d')]));
}
$file = trim(preg_replace('/[^A-Za-z0-9 ,.\-]+/', '_', $file)) . '.xlsx';

logAudit($mysqli, $_SESSION['user_id'], 'STUDENT_PROFILES_EXPORTED',
    $one_id > 0 ? 'Downloaded the student profile of ' . formatPersonNameRow($students[0]) . ' as Excel.'
                : 'Downloaded ' . count($rows) . ' student profile(s) as Excel' . ($course !== '' || $year !== '' ? ' (' . trim($course . ' ' . $year) . ')' : '') . '.',
    $one_id > 0 ? $one_id : null);

$bytes = xlsxBuild($one_id > 0 ? 'Student Profile' : 'Student Profiles', array_column($columns, 0), $rows, array_column($columns, 1));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
exit;
