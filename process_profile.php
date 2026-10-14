<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/upload_store_helper.php';
require_once 'includes/profile_config.php';
require_once 'includes/audit_log_helper.php';

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

    // The rest of the profile (birth date, parents, guardian, schools) is
    // checked against profile_config.php, the same definitions the form was
    // drawn from. Nothing is saved while any answer is wrong; the form comes
    // back exactly as the student left it, with the reasons listed.
    ensureProfileColumn($mysqli);
    [$profile_clean, $profile_errors] = validateStudentProfile($_POST['profile'] ?? [], $_POST['education'] ?? []);
    if ($contact_number !== '' && !preg_match(PROFILE_PHONE_PATTERN, $contact_number)) {
        $profile_errors[] = 'Contact Number is not a valid phone number.';
    }
    if ($profile_errors) {
        $_SESSION['flash'] = 'Your profile was not saved. Please fix the following:';
        $_SESSION['flash_type'] = 'error';
        $_SESSION['profile_errors'] = $profile_errors;
        $_SESSION['profile_old'] = [
            'year_level' => $year_level,
            'section' => $section,
            'course' => $course,
            'contact_number' => $contact_number,
            'profile' => $profile_clean,
        ];
        header("Location: student_settings.php#edit-profile");
        exit;
    }
    $profile_json = packStudentProfile($profile_clean);

    // Was the profile incomplete before this save and complete after it? Only
    // then does Settings congratulate the student (once, in a modal).
    $was_missing = [];
    $before = $mysqli->prepare("SELECT contact_number, address, profile_data FROM users WHERE id = ?");
    if ($before) {
        $before->bind_param("i", $user_id);
        $before->execute();
        $before_row = $before->get_result()->fetch_assoc() ?: [];
        $before->close();
        $was_missing = profileMissingFields($before_row, decodeStudentProfile($before_row['profile_data'] ?? ''));
    }
    $now_missing = profileMissingFields(['contact_number' => $contact_number], decodeStudentProfile($profile_json));

    // users.address is the permanent address as one line, which the request
    // forms print. It now comes from the picked address; a student who has not
    // picked one yet keeps the address they typed before (NULL leaves it alone).
    $address = profileComposePlace($profile_clean['home'] ?? []);
    if (!profilePlaceComplete($profile_clean['home'] ?? null)) $address = null;

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
            $_SESSION['flash'] = 'Invalid file type for profile picture. Only JPG, PNG, and WebP are allowed.'; $_SESSION['flash_type'] = 'error';
            header("Location: student_settings.php");
            exit;
        }

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $profile_picture = $target_path;
            storeUploadInDb($mysqli, $target_path);
        } else {
            $_SESSION['flash'] = 'Failed to upload profile picture.'; $_SESSION['flash_type'] = 'error';
            header("Location: student_settings.php");
            exit;
        }
    }

    $stmt = $mysqli->prepare("UPDATE users SET year_level = ?, section = ?, course = ?, contact_number = ?, address = COALESCE(?, address), profile_picture = ?, profile_data = ? WHERE id = ?");
    if (!$stmt) {
        $_SESSION['flash'] = 'Database error updating profile.'; $_SESSION['flash_type'] = 'error';
    } else {
        $stmt->bind_param("sssssssi", $year_level, $section, $course, $contact_number, $address, $profile_picture, $profile_json, $user_id);
        if ($stmt->execute()) {
            if ($was_missing && !$now_missing) {
                $_SESSION['profile_completed'] = true;
            }
            $_SESSION['flash'] = 'Profile updated successfully!';
            logAudit($mysqli, $user_id, 'PROFILE_UPDATED', 'Updated their student profile.', $user_id);
        } else {
            $_SESSION['flash'] = 'Failed to update profile: ' . $stmt->error; $_SESSION['flash_type'] = 'error';
        }
        $stmt->close();
    }
}

header("Location: student_settings.php");
exit;
?>
