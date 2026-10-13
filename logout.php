<?php
session_start();

if (isset($_SESSION['user_id'])) {
    require_once 'audit_log_helper.php';
    logAudit($mysqli, $_SESSION['user_id'], 'LOGOUT', 'User logged out.');
}

session_unset(); // Unset all session variables
session_destroy(); // Destroy the session

header("Location: index.php"); // Redirect to the login page
exit;
