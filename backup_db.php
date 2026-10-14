<?php
session_start();
require_once 'db.php';
require_once 'audit_log_helper.php';

// Only allow systemadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin') {
    die('Access denied.');
}

// Ensure mysqldump is accessible (assuming standard XAMPP path or it's in PATH)
// Adjust the path to mysqldump.exe if necessary.
$mysqldump_path = 'c:\xampp\mysql\bin\mysqldump.exe';
if (!file_exists($mysqldump_path)) {
    $mysqldump_path = 'mysqldump'; // Fallback to PATH
}

$db_host = DB_HOST;
$db_user = escapeshellarg(DB_USER);
$db_name = escapeshellarg(DB_NAME);
$db_pass = DB_PASS ? "-p" . escapeshellarg(DB_PASS) : "";

$filename = "backup_" . date("Y-m-d_H-i-s") . ".bak";
$backup_file = sys_get_temp_dir() . '/' . $filename;

$command = "\"$mysqldump_path\" --host=$db_host --user=$db_user $db_pass $db_name > \"$backup_file\"";

// Execute command
exec($command, $output, $return_var);

if ($return_var === 0 && file_exists($backup_file)) {
    logAudit($mysqli, $_SESSION['user_id'], 'BACKUP_DB', "Database backup created: $filename");
    
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($backup_file));
    readfile($backup_file);
    unlink($backup_file); // delete temp file
    exit;
} else {
    $_SESSION['flash'] = "Backup failed. Command exited with code: $return_var";
    header("Location: admin_dashboard.php");
    exit;
}
?>
