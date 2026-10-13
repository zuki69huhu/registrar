<?php
session_start();
require_once 'db.php';
require_once 'upload_store_helper.php';

// Access control: Only allow users with the 'student' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['flash'] = 'Access denied.';
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $_SESSION['flash'] = 'User not logged in.';
    header("Location: index.php?tab=login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year_level = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Current profile picture to safely update
    $profile_picture = $_POST['current_profile_picture'] ?? null;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $upload_dir = 'uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = basename($file['name']);
        $unique_file_name = time() . '_' . safeUploadFileName($file_name);
        $target_path = $upload_dir . $unique_file_name;
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($file_ext, $allowed_extensions)) {
            $_SESSION['flash'] = 'Invalid file type for profile picture. Only JPG, PNG, and WebP are allowed.';
            header("Location: student_settings.php");
            exit;
        }

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $profile_picture = $target_path;
            storeUploadInDb($mysqli, $target_path);
        } else {
            $_SESSION['flash'] = 'Failed to upload profile picture.';
            header("Location: student_settings.php");
            exit;
        }
    }

    $stmt = $mysqli->prepare("UPDATE users SET year_level = ?, section = ?, course = ?, contact_number = ?, address = ?, profile_picture = ? WHERE id = ?");
    if (!$stmt) {
        $_SESSION['flash'] = 'Database error updating profile.';
    } else {
        $stmt->bind_param("ssssssi", $year_level, $section, $course, $contact_number, $address, $profile_picture, $user_id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = 'Profile updated successfully!';
        } else {
            $_SESSION['flash'] = 'Failed to update profile: ' . $stmt->error;
        }
        $stmt->close();
    }
}

header("Location: student_settings.php");
exit;
?>
