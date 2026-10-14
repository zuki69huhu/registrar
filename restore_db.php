<?php
session_start();
require_once 'db.php';
require_once 'audit_log_helper.php';

// Only allow systemadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin') {
    die('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    $file = $_FILES['backup_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = "Upload failed with error code: " . $file['error'];
        header("Location: admin_dashboard.php");
        exit;
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (!in_array(strtolower($ext), ['sql', 'bak'])) {
        $_SESSION['flash'] = "Invalid file format. Please upload a .sql or .bak file.";
        header("Location: admin_dashboard.php");
        exit;
    }
    
    $mysql_path = 'c:\xampp\mysql\bin\mysql.exe';
    if (!file_exists($mysql_path)) {
        $mysql_path = 'mysql'; // Fallback to PATH
    }
    
    $db_host = DB_HOST;
    $db_user = escapeshellarg(DB_USER);
    $db_name = escapeshellarg(DB_NAME);
    $db_pass = DB_PASS ? "-p" . escapeshellarg(DB_PASS) : "";
    
    $uploaded_file = escapeshellarg($file['tmp_name']);
    
    $command = "\"$mysql_path\" --host=$db_host --user=$db_user $db_pass $db_name < $uploaded_file";
    
    exec($command, $output, $return_var);
    
    if ($return_var === 0) {
        logAudit($mysqli, $_SESSION['user_id'], 'RESTORE_DB', "Database restored from file: {$file['name']}");
        $_SESSION['flash'] = "Database restored successfully!";
    } else {
        $_SESSION['flash'] = "Database restore failed. Command exited with code: $return_var";
    }
} else {
    $_SESSION['flash'] = "No file uploaded.";
}

header("Location: admin_dashboard.php");
exit;
?>
