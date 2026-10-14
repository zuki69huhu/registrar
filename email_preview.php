<?php
/**
 * Email Template Preview — Generates a sample email to preview in the browser.
 * Visit: http://localhost/REGISTRAR/email_preview.php
 *        ?variant=announcement for a registrar broadcast
 * 
 * This file is for development/testing only.
 */
require_once __DIR__ . '/includes/email_template.php';

// Sample data to preview
$student_name = 'Juan Dela Cruz';
$message = "Your document 'Transcript of Records' has been approved by the Registrar's Office. You may now proceed to pick up your document at the Registrar window during office hours (Monday to Friday, 8:00 AM to 5:00 PM).\n\nPlease bring a valid ID for verification.";
$type = $_GET['type'] ?? 'success';
$subject = $_GET['subject'] ?? 'Document Status Update';
$registrar_name = 'Maria Santos';
$institution = 'MCNP-ISAP';
$variant = $_GET['variant'] ?? '';
if ($variant === 'announcement') {
    $message = "Good day, students! Please be advised that the Office of the Registrar will be closed on Friday, September 25, 2026, in observance of the school foundation day.

All requests filed on that day will be processed on Monday. Thank you.";
    $subject = $_GET['subject'] ?? 'Announcement: Good day, students! Please be advised that the Office of…';
}

$html = buildEmailTemplate($student_name, $message, $type, $subject, $registrar_name, $institution, $variant);

// The same seal sendEmail() embeds.
$logo_file = 'assets/img/logo.jpg';
if (file_exists(__DIR__ . '/assets/img/logo_email.png')) {
    $logo_file = 'assets/img/logo_email.png';
} elseif (file_exists(__DIR__ . '/assets/img/isaplogo_glow.png')) {
    $logo_file = 'isaplogo_glow.png';
} elseif (file_exists(__DIR__ . '/assets/img/logo_glow.png')) {
    $logo_file = 'logo_glow.png';
} elseif (file_exists(__DIR__ . '/assets/img/isaplogo.png')) {
    $logo_file = 'isaplogo.png';
} elseif (file_exists(__DIR__ . '/assets/img/logo.png')) {
    $logo_file = 'assets/img/logo.png';
} elseif (file_exists(__DIR__ . '/assets/img/isaplogo.jpg')) {
    $logo_file = 'isaplogo.jpg';
}

echo str_replace('cid:logo_img', $logo_file, $html);
?>
