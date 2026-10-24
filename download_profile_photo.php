<?php
/**
 * A student's profile picture as a download - the original file, untouched.
 *
 *   ?id=N  that student, for a registrar of the student's institution
 *   (none) the signed-in student's own picture
 *
 * Served with Content-Disposition: attachment and a readable name
 * ("2023-10156 Ruales, John Philip A. - Photo.jpeg"). The stored path must lie
 * inside uploads/, so a tampered value can never serve another file. A picture
 * missing on this computer is written back from the database copy first.
 */
session_start();
require_once 'db.php';
require_once 'profile_config.php';
require_once 'name_helper.php';
require_once 'upload_store_helper.php';

$role = $_SESSION['role'] ?? '';
$user = fetchStudentForViewer($mysqli, (int)($_GET['id'] ?? 0), (int)($_SESSION['user_id'] ?? 0), $role);
$path = trim((string)($user['profile_picture'] ?? ''));
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

if (!$user || $path === '' || strpos($path, '..') !== false || strncmp($path, 'uploads/', 8) !== 0
    || !isset($types[$ext]) || !ensureUploadOnDisk($mysqli, $path) || !is_file(__DIR__ . '/' . $path)) {
    http_response_code(404);
    exit('No profile picture.');
}

$name = trim(trim((string)($user['student_id'] ?? '')) . ' ' . formatPersonNameRow($user)) . ' - Photo.' . $ext;
$ascii = preg_replace('/[^A-Za-z0-9,.\- ]/', '_', @iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'photo.' . $ext);

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize(__DIR__ . '/' . $path));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile(__DIR__ . '/' . $path);
