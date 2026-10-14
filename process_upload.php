<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/upload_store_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    header("Location: index.php"); exit;
}

$user_id = $_SESSION['user_id'];
$upload_dir = 'uploads/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
    $file_name = basename($_FILES['document']['name']);
    $target_path = $upload_dir . time() . '_' . safeUploadFileName($file_name);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    if (in_array($file_ext, $allowed)) {
        if (move_uploaded_file($_FILES['document']['tmp_name'], $target_path)) {
            $stmt = $mysqli->prepare("INSERT INTO documents (user_id, file_name, file_path) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $file_name, $target_path);
            if ($stmt->execute()) {
                storeUploadInDb($mysqli, $target_path);
                $_SESSION['flash'] = "Document uploaded successfully!";
            } else {
                $_SESSION['flash'] = "Database error.";
            }
            $stmt->close();
        } else {
            $_SESSION['flash'] = "Upload failed.";
        }
    } else {
        $_SESSION['flash'] = "Invalid file type. Only PDF, PNG, and JPEG files are allowed.";
    }
} else {
    $_SESSION['flash'] = "No file selected.";
}
header("Location: my_document.php");
exit;
