<?php
session_start();
require_once 'db.php';
require_once 'upload_store_helper.php';
require_once 'profile_form.php';
require_once 'audit_log_helper.php';

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

    // What is saved right now: the "before" of this save.
    $before_row = [];
    $before = $mysqli->prepare("SELECT first_name, middle_name, last_name, contact_number, address, profile_data, profile_picture, identity_edited_at FROM users WHERE id = ?");
    if ($before) {
        $before->bind_param("i", $user_id);
        $before->execute();
        $before_row = $before->get_result()->fetch_assoc() ?: [];
        $before->close();
    }
    $old_profile = decodeStudentProfile($before_row['profile_data'] ?? '');
    // Was the profile incomplete before this save and complete after it? Only
    // then does Settings congratulate the student (once, in a modal).
    $was_missing = $before_row ? profileMissingFields($before_row, $old_profile) : [];

    [$profile_clean, $profile_errors] = validateStudentProfile($_POST['profile'] ?? [], $_POST['education'] ?? []);
    if ($contact_number !== '' && !preg_match(PROFILE_PHONE_PATTERN, $contact_number)) {
        $profile_errors[] = 'Contact Number is not a valid phone number.';
    }

    // Name and date of birth are filled in ONE time: once a date of birth is on
    // file (or users.identity_edited_at is set), both are locked for the student
    // and only the registrar can change them. The save that first stores a date
    // of birth records the lock.
    $first_name = (string)($before_row['first_name'] ?? '');
    $middle_name = (string)($before_row['middle_name'] ?? '');
    $last_name = (string)($before_row['last_name'] ?? '');
    $old_birth = (string)($old_profile['birth_date'] ?? '');
    $identity_changed = false;
    if (!empty($before_row['identity_edited_at']) || $old_birth !== '') {
        // Locked: whatever was posted, keep what is on file.
        if ($old_birth !== '') $profile_clean['birth_date'] = $old_birth;
        if (trim((string)($old_profile['birth_place'] ?? '')) !== '') $profile_clean['birth_place'] = $old_profile['birth_place'];
    } else {
        $name_rule = "/^\p{L}[\p{L} .'\-]*$/u";
        foreach (['first_name' => 'First Name', 'middle_name' => 'Middle Name', 'last_name' => 'Last Name'] as $key => $label) {
            $$key = profileCleanText($_POST[$key] ?? $$key, 100);
            if ($$key === '' && $key !== 'middle_name') {
                $profile_errors[] = "$label is required.";
            } elseif ($$key !== '' && !preg_match($name_rule, $$key)) {
                $profile_errors[] = "$label may only contain letters, spaces, periods, apostrophes and hyphens.";
            }
        }
        // This save locks them once the date of birth is filled in.
        $identity_changed = ($profile_clean['birth_date'] ?? '') !== '';
    }
    // A new picture must follow the formal photo guidelines, which the student confirms.
    if (!empty($_FILES['profile_picture']['name']) && empty($_POST['photo_confirm'])) {
        $profile_errors[] = 'Please tick the box confirming that your new profile picture follows the formal photo guidelines, then choose the picture again.';
    }
    if ($profile_errors) {
        $_SESSION['flash'] = 'Your profile was not saved. Please fix the following:';
        $_SESSION['flash_type'] = 'error';
        $_SESSION['profile_errors'] = $profile_errors;
        $_SESSION['profile_old'] = [
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
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

    $now_missing = profileMissingFields(['contact_number' => $contact_number], decodeStudentProfile($profile_json));

    // users.address is the permanent address as one line, which the request
    // forms print. It now comes from the picked address; a student who has not
    // picked one yet keeps the address they typed before (NULL leaves it alone).
    $address = profileComposePlace($profile_clean['home'] ?? []);
    if (!profilePlaceComplete($profile_clean['home'] ?? null)) $address = null;

    // The saved picture stays unless a new one is uploaded. It is read from the
    // database, never from the form, so a posted path can not point it elsewhere.
    $profile_picture = $before_row['profile_picture'] ?? null;

    // A new picture is checked and stored by profile_form.php, the same rules the registrar's editor uses.
    [$new_picture, $picture_error] = saveProfilePictureUpload($mysqli);
    if ($picture_error !== '') {
        $_SESSION['flash'] = $picture_error; $_SESSION['flash_type'] = 'error';
        header("Location: student_settings.php");
        exit;
    }
    if ($new_picture) $profile_picture = $new_picture;

    $identity_flag = $identity_changed ? 1 : 0;
    $stmt = $mysqli->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, year_level = ?, section = ?, course = ?, contact_number = ?,
                                     address = COALESCE(?, address), profile_picture = ?, profile_data = ?,
                                     identity_edited_at = IF(? = 1, NOW(), identity_edited_at)
                              WHERE id = ?");
    if (!$stmt) {
        $_SESSION['flash'] = 'Database error updating profile.'; $_SESSION['flash_type'] = 'error';
    } else {
        $stmt->bind_param("ssssssssssii", $first_name, $middle_name, $last_name, $year_level, $section, $course, $contact_number,
                          $address, $profile_picture, $profile_json, $identity_flag, $user_id);
        if ($stmt->execute()) {
            if ($was_missing && !$now_missing) {
                $_SESSION['profile_completed'] = true;
            }
            $_SESSION['flash'] = 'Profile updated successfully!';
            logAudit($mysqli, $user_id, 'PROFILE_UPDATED', 'Updated their student profile.', $user_id);
            if ($identity_changed) {
                $now = trim("$first_name $middle_name $last_name") . ', born ' . $profile_clean['birth_date']
                     . (($profile_clean['birth_place'] ?? '') !== '' ? ' in ' . $profile_clean['birth_place'] : '');
                logAudit($mysqli, $user_id, 'PROFILE_IDENTITY_LOCKED', "Filled in their name, date of birth and place of birth (\"$now\"); they are now locked.", $user_id);
                $_SESSION['flash'] = 'Profile updated successfully! Your name, date of birth and place of birth are now locked; ask the Registrar\'s Office for any correction.';
            }
        } else {
            $_SESSION['flash'] = 'Failed to update profile: ' . $stmt->error; $_SESSION['flash_type'] = 'error';
        }
        $stmt->close();
    }
}

header("Location: student_settings.php");
exit;
?>
