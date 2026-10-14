<?php
session_start();
require_once 'includes/db.php';
require_once 'vendor/autoload.php';
require_once 'includes/upload_store_helper.php';

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

$stmt = $mysqli->prepare("SELECT d.file_path, d.file_name, d.document_type, u.student_id, u.last_name, u.first_name, u.middle_name, u.course, u.year_level, u.section FROM documents d JOIN users u ON d.user_id = u.id WHERE d.id IN ($ids_placeholder)");
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
$first_row = $rows[0];
$student_no = trim((string)($first_row['student_id'] ?? ''));
$last_name = $first_row['last_name'] ?? '';
$first_name = $first_row['first_name'] ?? '';
$middle_name = $first_row['middle_name'] ?? '';
$course = $first_row['course'] ?? '';
$year_level = $first_row['year_level'] ?? '';
$section = $first_row['section'] ?? '';
$year_sec = (!empty($year_level) ? substr($year_level, 0, 1) : '') . $section;

// Collect all unique document types
$doc_types = [];
foreach ($rows as $r) {
    $dtype = !empty($r['document_type']) && $r['document_type'] !== 'General' ? $r['document_type'] : $r['file_name'];
    if (!in_array($dtype, $doc_types)) {
        $doc_types[] = $dtype;
    }
}

$pdf->AddPage('P', 'A4');

// Background color - light gray
$pdf->SetFillColor(245, 245, 245);
$pdf->Rect(0, 0, 210, 297, 'F');

// Header bar
$pdf->SetFillColor(30, 58, 95); // dark navy
$pdf->Rect(0, 0, 210, 50, 'F');

$pdf->SetFont('Helvetica', 'B', 22);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(15, 12);
$pdf->Cell(180, 12, 'Student Document Summary', 0, 1, 'C');

$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(200, 215, 235);
$pdf->SetXY(15, 28);
$pdf->Cell(180, 8, 'Registrar Office - Document Package', 0, 1, 'C');

// Student Info Section
$pdf->SetTextColor(50, 50, 50);

// Info card background
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(20, 65, 170, 104, 'F');
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(20, 65, 170, 104, 'D');

// Section title
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetTextColor(30, 58, 95);
$pdf->SetXY(30, 72);
$pdf->Cell(150, 8, 'Student Information', 0, 1, 'L');

// Divider line
$pdf->SetDrawColor(30, 58, 95);
$pdf->SetLineWidth(0.5);
$pdf->Line(30, 82, 180, 82);

// Student details
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetTextColor(80, 80, 80);

$info_y = 90;
$label_x = 35;
$value_x = 90;

// Student ID — always the first detail on the cover
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'Student ID:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, $student_no !== '' ? $student_no : 'N/A', 0, 1, 'L');

// Last Name
$info_y += 12;
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'Last Name:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, $last_name, 0, 1, 'L');

// First Name
$info_y += 12;
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'First Name:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, $first_name, 0, 1, 'L');

// Middle Name
$info_y += 12;
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'Middle Name:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, !empty($middle_name) ? $middle_name : 'N/A', 0, 1, 'L');

// Course/Program
$info_y += 12;
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'Course/Program:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, !empty($course) ? $course : 'N/A', 0, 1, 'L');

// Year & Section
$info_y += 12;
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetXY($label_x, $info_y);
$pdf->Cell(50, 8, 'Year & Section:', 0, 0, 'L');
$pdf->SetFont('Helvetica', '', 11);
$pdf->SetXY($value_x, $info_y);
$pdf->Cell(90, 8, !empty($year_sec) ? $year_sec : 'N/A', 0, 1, 'L');

// Document Types Section
$doc_section_y = 180;

$pdf->SetFillColor(255, 255, 255);
$doc_box_height = 30 + (count($doc_types) * 10);
$pdf->Rect(20, $doc_section_y, 170, $doc_box_height, 'F');
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(20, $doc_section_y, 170, $doc_box_height, 'D');

$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetTextColor(30, 58, 95);
$pdf->SetXY(30, $doc_section_y + 7);
$pdf->Cell(150, 8, 'Document Types Included', 0, 1, 'L');

// Divider
$pdf->SetDrawColor(30, 58, 95);
$pdf->Line(30, $doc_section_y + 17, 180, $doc_section_y + 17);

$pdf->SetFont('Helvetica', '', 11);
$pdf->SetTextColor(80, 80, 80);
$dt_y = $doc_section_y + 23;
foreach ($doc_types as $idx => $dtype) {
    $pdf->SetXY(40, $dt_y);
    $pdf->Cell(5, 8, chr(149), 0, 0, 'L'); // bullet
    $pdf->Cell(130, 8, ($idx + 1) . '. ' . $dtype, 0, 1, 'L');
    $dt_y += 10;
}

// Footer with date
$pdf->SetFont('Helvetica', 'I', 9);
$pdf->SetTextColor(150, 150, 150);
$pdf->SetXY(15, 280);
$pdf->Cell(180, 8, 'Generated on ' . date('F d, Y h:i A'), 0, 0, 'C');

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
