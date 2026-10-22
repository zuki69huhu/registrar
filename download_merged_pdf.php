<?php
session_start();
require_once 'db.php';
require_once 'vendor/autoload.php';
require_once 'upload_store_helper.php';
require_once 'profile_config.php';
require_once 'request_config.php';   // the institution's letterhead

use setasign\Fpdi\Fpdi;

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

$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);

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

// FPDF's core fonts are cp1252: Peñablanca, Ñ and the dash need converting.
$t = function ($text) {
    $text = (string)$text;
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    return $out === false ? $text : $out;
};
$v = fn($value) => trim((string)$value) !== '' ? trim((string)$value) : '-';
$name = function ($last, $first, $middle) {
    return trim(implode(' ', array_filter([trim((string)$first), trim((string)$middle), trim((string)$last)])));
};

$institution = strtoupper(trim((string)($first_row['institution'] ?? '')));
// The institution's own letterhead, the one its printed forms carry.
$head = (getRequestForm($institution, 'graduation') ?: [])['letterhead'] ?? [];
$seal = $head['logo'] ?? 'logo.jpg';
$seal_path = is_file(__DIR__ . '/' . $seal) ? __DIR__ . '/' . $seal : (is_file(__DIR__ . '/logo.jpg') ? __DIR__ . '/logo.jpg' : null);
$hex = ltrim($head['color'] ?? '#1e3a5f', '#');
$school_rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

$birth = profileBirthDate($profile['birth_date'] ?? '');
$age = profileAge($profile['birth_date'] ?? '');
$home = $profile['home'] ?? [];
$home_line = profilePlaceComplete($home) ? profileComposePlace($home) : (string)($first_row['address'] ?? '');
$father = $name($profile['father_last_name'] ?? '', $profile['father_first_name'] ?? '', $profile['father_middle_name'] ?? '')
    ?: (string)($profile['father_name'] ?? '');
$mother = $name($profile['mother_last_name'] ?? '', $profile['mother_first_name'] ?? '', $profile['mother_middle_name'] ?? '')
    ?: (string)($profile['mother_name'] ?? '');

// Who generated it: the signed-in registrar.
$preparer = '';
$prep = $mysqli->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
if ($prep) {
    $prep->bind_param('i', $_SESSION['user_id']);
    $prep->execute();
    $prep_row = $prep->get_result()->fetch_assoc() ?: [];
    $prep->close();
    $preparer = $name($prep_row['last_name'] ?? '', $prep_row['first_name'] ?? '', $prep_row['middle_name'] ?? '');
}

$L = 15; $W = 180;                       // margins and usable width, mm
$navy = [31, 56, 100];
$line = [90, 90, 90];
$pdf->AddPage('P', 'A4');
$pdf->SetTextColor(0, 0, 0);

// ── Letterhead: seal on the left, the school centred, ruled off ──
if ($seal_path) {
    $pdf->Image($seal_path, $L + 2, 11, 24, 24);
}
// The name is centred between equal margins that clear the seal, and set
// smaller if it would not fit there.
$name_w = $W - 2 * 30;
$school = $t($head['name'] ?? 'OFFICE OF THE REGISTRAR');
$name_size = 15;
$pdf->SetFont('Times', 'B', $name_size);
while ($name_size > 10 && $pdf->GetStringWidth($school) > $name_w) {
    $name_size -= 0.5;
    $pdf->SetFont('Times', 'B', $name_size);
}
$pdf->SetTextColor($school_rgb[0], $school_rgb[1], $school_rgb[2]);
$pdf->SetXY($L + 30, 12);
$pdf->Cell($name_w, 7, $school, 0, 1, 'C');
$pdf->SetFont('Times', '', 10);
$pdf->SetTextColor(0, 31, 95);
foreach (array_filter([$head['address'] ?? '', $head['contact'] ?? '',
                       trim(($head['website'] ?? '') . (isset($head['email']) ? '   |   ' . $head['email'] : ''))]) as $info) {
    $pdf->SetX($L);
    $pdf->Cell($W, 5, $t($info), 0, 1, 'C');
}
$pdf->SetDrawColor(85, 133, 191);
$pdf->SetLineWidth(0.8);
$pdf->Line($L, 39, $L + $W, 39);
$pdf->SetLineWidth(0.25);
$pdf->Line($L, 40.2, $L + $W, 40.2);

// ── Title ──
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Times', 'B', 11);
$pdf->SetXY($L, 43);
$pdf->Cell($W, 5, $t($head['office'] ?? 'OFFICE OF THE REGISTRAR'), 0, 1, 'C');
$pdf->SetFont('Times', 'B', 15);
$pdf->SetX($L);
$pdf->Cell($W, 8, 'STUDENT DOCUMENT SUMMARY', 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 8.5);
$pdf->SetTextColor(70, 70, 70);
$pdf->SetX($L);
$pdf->Cell($W, 5, $t('Date generated: ' . date('F j, Y, g:i A')), 0, 1, 'R');

// ── One ruled table: a band per part, then boxes with a caption and the answer ──
$y = 64;
$band = function ($title) use ($pdf, $t, &$y, $L, $W, $navy, $line) {
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetDrawColor($line[0], $line[1], $line[2]);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($L, $y, $W, 6.5, 'DF');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($L + 3, $y);
    $pdf->Cell($W - 6, 6.5, $t($title), 0, 0, 'L');
    $y += 6.5;
};
// $cells: [[caption, answer, width share], ...]; shares add up to 1
$row = function (array $cells, $h = 10.5) use ($pdf, $t, &$y, $L, $W, $line) {
    $x = $L;
    foreach ($cells as [$caption, $answer, $share]) {
        $w = $W * $share;
        $pdf->SetDrawColor($line[0], $line[1], $line[2]);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($x, $y, $w, $h, 'D');
        $caption = $t(strtoupper($caption));
        $cap_size = 6.8;
        $pdf->SetFont('Helvetica', '', $cap_size);
        while ($cap_size > 5 && $pdf->GetStringWidth($caption) > $w - 3.6) {
            $cap_size -= 0.2;
            $pdf->SetFont('Helvetica', '', $cap_size);
        }
        $pdf->SetTextColor(95, 95, 95);
        $pdf->SetXY($x + 1.8, $y + 0.8);
        $pdf->Cell($w - 3.6, 3.6, $caption, 0, 0, 'L');
        // The answer is fitted to its box: smaller writing, never cut off.
        $text = $t($answer);
        $size = 10;
        $pdf->SetFont('Helvetica', 'B', $size);
        while ($size > 6 && $pdf->GetStringWidth($text) > $w - 4) {
            $size -= 0.5;
            $pdf->SetFont('Helvetica', 'B', $size);
        }
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetXY($x + 1.8, $y + 4.6);
        $pdf->Cell($w - 3.6, 5, $text, 0, 0, 'L');
        $x += $w;
    }
    $y += $h;
};

$band('I. PERSONAL INFORMATION');
$row([['Student ID', $v($student_no), 0.25], ['Course / Program', $v($course), 0.5], ['Year & Section', $v($year_sec), 0.25]]);
$row([['Last Name', $v($last_name), 0.3], ['First Name', $v($first_name), 0.3], ['Middle Name', $v($middle_name), 0.25], ['Ext. Name', $v($profile['extension_name'] ?? ''), 0.15]]);
$row([['Date of Birth', $birth ? $birth->format('F j, Y') : '-', 0.25], ['Age', $age !== null ? (string)$age : '-', 0.1], ['Sex', $v($profile['sex'] ?? ''), 0.15], ['Civil Status', $v($profile['civil_status'] ?? ''), 0.2], ['Nationality', $v($profile['nationality'] ?? ''), 0.3]]);
$row([['Place of Birth', $v($profile['birth_place'] ?? ''), 0.5], ['Religion', $v($profile['religion'] ?? ''), 0.25], ['Institution', $v($institution), 0.25]]);
$row([['Permanent Address', $v($home_line), 0.82], ['ZIP Code', $v($home['zip'] ?? ''), 0.18]]);
$row([['Contact Number', $v($first_row['contact_number'] ?? ''), 0.25], ['Email Address', $v($first_row['email'] ?? ''), 0.4], ['Disability', $v($profile['disability'] ?? ''), 0.15], ['Indigenous People Group', $v($profile['ip_group'] ?? ''), 0.2]]);

$band('II. FAMILY BACKGROUND');
$row([["Father's Name", $v($father), 0.45], ['Occupation', $v($profile['father_occupation'] ?? ''), 0.3], ['Contact Number', $v($profile['father_contact'] ?? ''), 0.25]]);
$row([["Mother's Maiden Name", $v($mother), 0.45], ['Occupation', $v($profile['mother_occupation'] ?? ''), 0.3], ['Contact Number', $v($profile['mother_contact'] ?? ''), 0.25]]);
$row([['Guardian / Person to Contact', $v($profile['guardian_name'] ?? ''), 0.45], ['Relationship', $v($profile['guardian_relationship'] ?? ''), 0.3], ['Contact Number', $v($profile['guardian_contact'] ?? ''), 0.25]]);

$band('III. DOCUMENTS INCLUDED (' . count($doc_types) . ')');
// Two per line, numbered, in the order they follow this page.
for ($i = 0; $i < count($doc_types); $i += 2) {
    $cells = [['Document ' . ($i + 1), $doc_types[$i], 0.5]];
    $cells[] = isset($doc_types[$i + 1]) ? ['Document ' . ($i + 2), $doc_types[$i + 1], 0.5] : ['', '', 0.5];
    $row($cells, 9.5);
}

// ── Signatures ──
$sig_y = min($y + 30, 270);           // close under the table, never into the footer
$sig_w = 70;
foreach ([[$L + 5, $preparer, 'Prepared by (Registrar)'], [$L + $W - 5 - $sig_w, '', 'Received / Verified by']] as [$sx, $who, $caption]) {
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY($sx, $sig_y - 5.5);
    $pdf->Cell($sig_w, 5, $t(strtoupper($who)), 0, 0, 'C');
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($sx, $sig_y, $sx + $sig_w, $sig_y);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(70, 70, 70);
    $pdf->SetXY($sx, $sig_y + 1);
    $pdf->Cell($sig_w, 4, $t($caption), 0, 0, 'C');
}

// Footer
$pdf->SetDrawColor(200, 200, 200);
$pdf->SetLineWidth(0.2);
$pdf->Line($L, 283, $L + $W, 283);
$pdf->SetFont('Helvetica', 'I', 7.5);
$pdf->SetTextColor(120, 120, 120);
$pdf->SetXY($L, 284);
$pdf->Cell($W, 5, $t('Student details as entered by the student on the registrar portal. A dash (-) marks an answer not yet given.'), 0, 0, 'C');

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
