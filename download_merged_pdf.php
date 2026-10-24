<?php
session_start();
require_once 'db.php';
require_once 'upload_store_helper.php';
require_once 'profile_pdf_helper.php';   // the letterhead and ruled table, shared with the profile PDF

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied.';
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['doc_ids'])) {
    $_SESSION['flash'] = 'No documents selected for download.';
    header("Location: submissions.php");
    exit;
}

$doc_ids = $_POST['doc_ids'];
if (!is_array($doc_ids)) {
    $doc_ids = [$doc_ids];
}

$ids_placeholder = implode(',', array_fill(0, count($doc_ids), '?'));
$types = str_repeat('i', count($doc_ids));

$stmt = $mysqli->prepare("SELECT d.file_path, d.file_name, d.document_type, u.student_id, u.last_name, u.first_name, u.middle_name, u.course, u.year_level, u.section, u.institution, u.email, u.contact_number, u.address, u.profile_data FROM documents d JOIN users u ON d.user_id = u.id WHERE d.id IN ($ids_placeholder)");
$stmt->bind_param($types, ...$doc_ids);
$stmt->execute();
$res = $stmt->get_result();

// Collect all rows first so we can build the cover page before adding documents
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

if (empty($rows)) {
    $_SESSION['flash'] = 'No valid documents found.';
    header("Location: submissions.php");
    exit;
}

$pdf = new StudentSheetPdf();

// === COVER PAGE ===
// One A4 page: the institution's seal and title, then what the student filled
// in on their Student Profile (Settings > Edit Profile), then the documents
// that follow. A blank answer prints as a dash, so the registrar sees what is
// still missing rather than a shorter list.
$first_row = $rows[0];
$student_no = trim((string)($first_row['student_id'] ?? ''));
$last_name = $first_row['last_name'] ?? '';
$first_name = $first_row['first_name'] ?? '';
$middle_name = $first_row['middle_name'] ?? '';
$course = $first_row['course'] ?? '';
$year_level = $first_row['year_level'] ?? '';
$section = $first_row['section'] ?? '';
$year_sec = (!empty($year_level) ? substr($year_level, 0, 1) : '') . $section;
$profile = decodeStudentProfile($first_row['profile_data'] ?? '');

// Collect all unique document types
$doc_types = [];
foreach ($rows as $r) {
    $dtype = !empty($r['document_type']) && $r['document_type'] !== 'General' ? $r['document_type'] : $r['file_name'];
    if (!in_array($dtype, $doc_types)) {
        $doc_types[] = $dtype;
    }
}

$v = [StudentSheetPdf::class, 'v'];
$institution = strtoupper(trim((string)($first_row['institution'] ?? '')));

$birth = profileBirthDate($profile['birth_date'] ?? '');
$age = profileAge($profile['birth_date'] ?? '');
$home = $profile['home'] ?? [];
$home_line = profilePlaceComplete($home) ? profileComposePlace($home) : (string)($first_row['address'] ?? '');
$father = sheetPersonName($profile['father_last_name'] ?? '', $profile['father_first_name'] ?? '', $profile['father_middle_name'] ?? '')
    ?: (string)($profile['father_name'] ?? '');
$mother = sheetPersonName($profile['mother_last_name'] ?? '', $profile['mother_first_name'] ?? '', $profile['mother_middle_name'] ?? '')
    ?: (string)($profile['mother_name'] ?? '');

$pdf->sheetHeader($institution, 'STUDENT DOCUMENT SUMMARY');

$pdf->band('I. PERSONAL INFORMATION');
$pdf->row([['Student ID', $v($student_no), 0.25], ['Course / Program', $v($course), 0.5], ['Year & Section', $v($year_sec), 0.25]]);
$pdf->row([['Last Name', $v($last_name), 0.3], ['First Name', $v($first_name), 0.3], ['Middle Name', $v($middle_name), 0.25], ['Ext. Name', $v($profile['extension_name'] ?? ''), 0.15]]);
$pdf->row([['Date of Birth', $birth ? $birth->format('F j, Y') : '-', 0.25], ['Age', $age !== null ? (string)$age : '-', 0.1], ['Sex', $v($profile['sex'] ?? ''), 0.15], ['Civil Status', $v($profile['civil_status'] ?? ''), 0.2], ['Nationality', $v($profile['nationality'] ?? ''), 0.3]]);
$pdf->row([['Place of Birth', $v($profile['birth_place'] ?? ''), 0.5], ['Religion', $v($profile['religion'] ?? ''), 0.25], ['Institution', $v($institution), 0.25]]);
$pdf->row([['Permanent Address', $v($home_line), 0.82], ['ZIP Code', $v($home['zip'] ?? ''), 0.18]]);
$pdf->row([['Contact Number', $v($first_row['contact_number'] ?? ''), 0.25], ['Email Address', $v($first_row['email'] ?? ''), 0.4], ['Disability', $v($profile['disability'] ?? ''), 0.15], ['Indigenous People Group', $v($profile['ip_group'] ?? ''), 0.2]]);

$pdf->band('II. FAMILY BACKGROUND');
$pdf->row([["Father's Name", $v($father), 0.45], ['Occupation', $v($profile['father_occupation'] ?? ''), 0.3], ['Contact Number', $v($profile['father_contact'] ?? ''), 0.25]]);
$pdf->row([["Mother's Maiden Name", $v($mother), 0.45], ['Occupation', $v($profile['mother_occupation'] ?? ''), 0.3], ['Contact Number', $v($profile['mother_contact'] ?? ''), 0.25]]);
$pdf->row([['Guardian / Person to Contact', $v($profile['guardian_name'] ?? ''), 0.45], ['Relationship', $v($profile['guardian_relationship'] ?? ''), 0.3], ['Contact Number', $v($profile['guardian_contact'] ?? ''), 0.25]]);

$pdf->band('III. DOCUMENTS INCLUDED (' . count($doc_types) . ')');
// Two per line, numbered, in the order they follow this page.
for ($i = 0; $i < count($doc_types); $i += 2) {
    $cells = [['Document ' . ($i + 1), $doc_types[$i], 0.5]];
    $cells[] = isset($doc_types[$i + 1]) ? ['Document ' . ($i + 2), $doc_types[$i + 1], 0.5] : ['', '', 0.5];
    $pdf->row($cells, 9.5);
}

// Who generated it: the signed-in registrar.
$pdf->signatures(sheetPreparerName($mysqli, $_SESSION['user_id']));
$pdf->footnote('Student details as entered by the student on the registrar portal. A dash (-) marks an answer not yet given.');

// === ADD DOCUMENT PAGES ===
$has_pages = true;

foreach ($rows as $row) {
    $file_path = $row['file_path'];
    if (!ensureUploadOnDisk($mysqli, $file_path)) {
        continue;
    }
    
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if ($ext === 'pdf') {
        try {
            $pageCount = $pdf->setSourceFile($file_path);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }
        } catch (Exception $e) {
            // If PDF is encrypted or unsupported, skip
            continue;
        }
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
        try {
            // A4 page size in mm: 210 x 297
            $pdf->AddPage('P', 'A4');
            
            // Get image size
            list($width, $height) = getimagesize($file_path);
            
            // Calculate ratio to fit A4
            $maxWidth = 210;
            $maxHeight = 297;
            
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = $width * $ratio;
            $newHeight = $height * $ratio;
            
            // Center image
            $x = ($maxWidth - $newWidth) / 2;
            $y = ($maxHeight - $newHeight) / 2;
            
            $pdf->Image($file_path, $x, $y, $newWidth, $newHeight);
        } catch (Exception $e) {
            continue;
        }
    }
}

if ($has_pages) {
    $middle_initial = !empty($middle_name) ? strtoupper(substr($middle_name, 0, 1)) . '.' : '';
    $doc_type_label = count($doc_types) === 1 ? $doc_types[0] : 'Documents';
    
    // Format: Student ID, Last name, First name, MI, Program, Document Type
    $pdf_filename = sprintf("%s, %s, %s, %s, %s, %s", 
        $student_no, $last_name, $first_name, $middle_initial, $course, $doc_type_label
    );
    $pdf_filename = ltrim($pdf_filename, ', ');
    // Sanitize and clean up empty parts
    $pdf_filename = preg_replace('/,\s*,/', ',', $pdf_filename);
    $pdf_filename = preg_replace('/[^a-zA-Z0-9, \-\.]/', '_', $pdf_filename);
    $pdf_filename = rtrim(trim($pdf_filename), ',') . '.pdf';

    $pdf->Output('D', $pdf_filename);
} else {
    $_SESSION['flash'] = 'Failed to generate PDF. Selected files may be invalid or missing.';
    header("Location: submissions.php");
}
exit;
