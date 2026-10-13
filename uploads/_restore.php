<?php
/**
 * Serves an upload that is missing from disk, rebuilding it from the database
 * copy (upload_store_helper.php). uploads/.htaccess sends every request for a
 * file that does not exist here; once written back, Apache serves it directly.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_store_helper.php';

$rel = str_replace('\\', '/', (string)($_GET['f'] ?? ''));
if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || $rel[0] === '_') {
    http_response_code(404);
    exit;
}

$path = 'uploads/' . $rel;
if (!ensureUploadOnDisk($mysqli, $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

$abs = __DIR__ . '/' . $rel;
header('Content-Type: ' . uploadMimeFromPath($abs));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($abs);
