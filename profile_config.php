<?php
/**
 * The student profile: what it holds, how it is checked and how it is stored.
 *
 * This is the single source of truth for the profile. student_settings.php
 * draws the Edit Profile form from it, process_profile.php validates against
 * it, and student_profile_view.php prints the read-only profile from it, so the
 * three can never disagree about a field.
 *
 * Everything beyond the columns `users` already has (name, course, contact
 * number, address...) is kept as JSON in `users.profile_data`, the same way
 * student_requests.form_data keeps a filed form: adding a field here needs no
 * schema change. Age is never stored - it is worked out from the date of birth
 * every time, so it is always right.
 */

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/psgc_helper.php';

// A phone number: digits, optionally a leading +, spaces or dashes between.
define('PROFILE_PHONE_PATTERN', '/^\+?[0-9](?:[0-9 \-]{5,17})[0-9]$/');
// The same rule for the browser's pattern="" attribute.
define('PROFILE_PHONE_HTML_PATTERN', '\+?[0-9][0-9 \-]{5,17}[0-9]');
define('PROFILE_MIN_SCHOOL_YEAR', 1950);
define('PROFILE_MIN_AGE', 10);
define('PROFILE_MAX_AGE', 100);

/**
 * Adds users.profile_data when it is missing (the project's usual
 * "check, then ALTER" pattern). Never throws.
 */
function ensureProfileColumn($mysqli) {
    try {
        $res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'profile_data'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query("ALTER TABLE users ADD COLUMN profile_data TEXT NULL");
        }
        // When the student used their one-time correction of name / date of birth.
        $res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'identity_edited_at'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query("ALTER TABLE users ADD COLUMN identity_edited_at DATETIME NULL");
        }
    } catch (Exception $e) {
        error_log('Profile column setup failed: ' . $e->getMessage());
    }
}

/**
 * The profile's sections, in the order they are shown.
 * Field types: text | date | tel | select | address. `wide` spans the whole row.
 *
 * An `address` is never typed: it is picked region -> province -> city /
 * municipality -> barangay from the PSGC lists (address_picker.js, checked by
 * psgc_helper.php) and stored as an array with those four names, plus
 * `street` (house no. / street / purok) and, when the field has `zip`, `zip`.
 *
 * `required` does not block saving - a student may fill the profile in over
 * several visits. It marks what a complete profile must have: those fields get
 * a red asterisk in the form and are what profileMissingFields() reports, which
 * is what puts the red badge on the student's picture.
 */
function getProfileSections() {
    return [
        'personal' => [
            'title' => 'Personal Information',
            'icon' => 'fa-id-card',
            'fields' => [
                'extension_name' => ['label' => 'Extension Name', 'type' => 'text', 'max' => 10, 'placeholder' => 'Jr., III - leave blank if none'],
                'birth_date' => ['required' => true, 'label' => 'Date of Birth', 'type' => 'date'],
                'sex' => ['required' => true, 'label' => 'Sex', 'type' => 'select', 'options' => ['Male', 'Female']],
                'civil_status' => ['required' => true, 'label' => 'Civil Status', 'type' => 'select', 'options' => ['Single', 'Married', 'Widowed', 'Separated']],
                'birth_place' => ['required' => true, 'label' => 'Place of Birth', 'type' => 'text', 'max' => 150, 'placeholder' => 'City / Municipality, Province'],
                'nationality' => ['required' => true, 'label' => 'Nationality', 'type' => 'text', 'max' => 60, 'placeholder' => 'e.g. Filipino'],
                'religion' => ['label' => 'Religion', 'type' => 'text', 'max' => 60],
                'disability' => ['label' => 'Disability', 'type' => 'text', 'max' => 100, 'placeholder' => 'Leave blank if not applicable'],
                'ip_group' => ['label' => 'Indigenous People Group', 'type' => 'text', 'max' => 100, 'placeholder' => 'Leave blank if not applicable'],
            ],
        ],
        'home' => [
            'title' => 'Permanent Address',
            'icon' => 'fa-house',
            'note' => 'Choose your region first: each list then shows only the places inside the one you picked before it.',
            'fields' => [
                'home' => ['required' => true, 'label' => 'Permanent Address', 'type' => 'address', 'zip' => true, 'wide' => true],
            ],
        ],
        // Father and mother are two sections of their own, each with an address,
        // so the two never run together in one grid.
        'father' => [
            'title' => 'Father',
            'icon' => 'fa-person',
            'missing_prefix' => "Father's ",
            'fields' => [
                'father_last_name' => ['required' => true, 'label' => 'Last Name', 'type' => 'text', 'max' => 60],
                'father_first_name' => ['required' => true, 'label' => 'Given Name', 'type' => 'text', 'max' => 60],
                'father_middle_name' => ['label' => 'Middle Name', 'type' => 'text', 'max' => 60],
                'father_occupation' => ['label' => 'Occupation', 'type' => 'text', 'max' => 80],
                'father_contact' => ['label' => 'Contact Number', 'type' => 'tel'],
                'father_place' => ['label' => 'Address', 'type' => 'address', 'wide' => true, 'copy_home' => true],
            ],
        ],
        'mother' => [
            'title' => "Mother (maiden name)",
            'icon' => 'fa-person-dress',
            'missing_prefix' => "Mother's ",
            'fields' => [
                'mother_last_name' => ['required' => true, 'label' => 'Maiden Last Name', 'type' => 'text', 'max' => 60],
                'mother_first_name' => ['required' => true, 'label' => 'Given Name', 'type' => 'text', 'max' => 60],
                'mother_middle_name' => ['label' => 'Maiden Middle Name', 'type' => 'text', 'max' => 60],
                'mother_occupation' => ['label' => 'Occupation', 'type' => 'text', 'max' => 80],
                'mother_contact' => ['label' => 'Contact Number', 'type' => 'tel'],
                'mother_place' => ['label' => 'Address', 'type' => 'address', 'wide' => true, 'copy_home' => true],
            ],
        ],
        'guardian' => [
            'title' => 'Guardian / Person to Contact in an Emergency',
            'icon' => 'fa-user-shield',
            'missing_prefix' => 'Guardian: ',
            'fields' => [
                'guardian_name' => ['required' => true, 'label' => 'Full Name', 'type' => 'text', 'max' => 120],
                'guardian_relationship' => ['required' => true, 'label' => 'Relationship to You', 'type' => 'text', 'max' => 60, 'placeholder' => 'e.g. Aunt, Grandfather, Mother'],
                'guardian_contact' => ['required' => true, 'label' => 'Contact Number', 'type' => 'tel'],
                'guardian_place' => ['label' => 'Address', 'type' => 'address', 'wide' => true, 'copy_home' => true],
            ],
        ],
    ];
}

/**
 * The schools a student lists, earliest first. Each level stores `school`, the
 * picked `region` / `province` / `city` / `barangay`, `address` (those joined,
 * for the pages that print a school's address), `year_from` and `year_to`;
 * `extra` adds one more answer that only that level has. `always` levels are
 * shown on the profile even when blank, and their school name is required for
 * a complete profile. `school_list` is which schools the name is picked from
 * once the address is chosen (bits of school_picker.js: 1 elementary, 2 junior
 * high, 4 senior high); without it the name is typed - colleges are not in the
 * DepEd list.
 */
function getProfileEducationLevels() {
    return [
        'preschool' => ['label' => 'Kindergarten / Preschool', 'always' => false, 'school_list' => 1],
        'elementary' => ['label' => 'Elementary', 'always' => true, 'school_list' => 1],
        'junior_high' => ['label' => 'Junior High School', 'always' => true, 'school_list' => 2],
        'senior_high' => ['label' => 'Senior High School', 'always' => true, 'school_list' => 4, 'extra' => 'Track / Strand', 'extra_placeholder' => 'e.g. STEM, ABM, HUMSS, TVL'],
        'college' => ['label' => 'College (previous school, if you transferred)', 'short' => 'College (previous school)', 'always' => false, 'extra' => 'Course Taken'],
    ];
}

/** Trims, collapses runs of whitespace and caps the length of one answer. */
function profileCleanText($value, $max = 120) {
    if (!is_string($value)) return '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max);
}

/** A picked address as posted, cleaned: the four names, the street, the ZIP. */
function profileCleanPlace($in, $with_zip = false) {
    $in = is_array($in) ? $in : [];
    $place = [];
    foreach (['region', 'province', 'city', 'barangay'] as $level) {
        $place[$level] = profileCleanText($in[$level] ?? '', 120);
    }
    $place['street'] = profileCleanText($in['street'] ?? '', 150);
    if ($with_zip) $place['zip'] = profileCleanText($in['zip'] ?? '', 4);
    return $place;
}

/**
 * An address as one line, smallest part first: "Purok 3, Alimannao,
 * Peñablanca, Cagayan". The region is left out, as on an envelope.
 */
function profileComposePlace($place, $with_street = true) {
    if (!is_array($place)) return is_string($place) ? $place : '';
    $parts = [];
    foreach (($with_street ? ['street'] : []) + [1 => 'barangay', 2 => 'city', 3 => 'province'] as $key) {
        $value = trim((string)($place[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    // Metro Manila's "provinces" are its districts; on paper it is just Metro Manila.
    $line = implode(', ', $parts);
    return preg_replace('/Metro Manila - [A-Za-z]+ District$/', 'Metro Manila', $line);
}

/** True when every level down to the barangay was picked. */
function profilePlaceComplete($place) {
    if (!is_array($place)) return false;
    foreach (['region', 'province', 'city', 'barangay'] as $level) {
        if (trim((string)($place[$level] ?? '')) === '') return false;
    }
    return true;
}

/** users.profile_data as an array, whatever state the column is in. */
function decodeStudentProfile($json) {
    $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    if (!is_array($data)) $data = [];
    if (!isset($data['education']) || !is_array($data['education'])) $data['education'] = [];
    return $data;
}

/** A valid Y-m-d date of birth as a DateTime, otherwise null. */
function profileBirthDate($birth_date) {
    if (!is_string($birth_date) || $birth_date === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d', $birth_date);
    return ($date && $date->format('Y-m-d') === $birth_date) ? $date : null;
}

/** Age in whole years today, or null when there is no usable date of birth. */
function profileAge($birth_date) {
    $date = profileBirthDate($birth_date);
    if (!$date) return null;
    $today = new DateTime('today');
    return $date > $today ? null : $date->diff($today)->y;
}

/** "2012 - 2018" from a level's two years, or '' when neither is set. */
function profileSchoolYears($level) {
    $from = $level['year_from'] ?? '';
    $to = $level['year_to'] ?? '';
    if ($from !== '' && $to !== '') return $from . ' – ' . $to;
    if ($from !== '') return $from . ' – present';
    return (string)$to;
}

/**
 * Checks what the Edit Profile form posted.
 *
 * Returns [$clean, $errors]. $clean always holds what the student typed (so
 * the form can be shown again as they left it); it is only safe to store when
 * $errors is empty.
 */
function validateStudentProfile($profile_in, $education_in) {
    if (!is_array($profile_in)) $profile_in = [];
    if (!is_array($education_in)) $education_in = [];
    $clean = [];
    $errors = [];

    foreach (getProfileSections() as $section) {
        foreach ($section['fields'] as $key => $field) {
            if ($field['type'] === 'address') {
                // Saving half an address is fine (the profile badge says what is
                // missing); an address that does not exist is not.
                $place = profileCleanPlace($profile_in[$key] ?? [], !empty($field['zip']));
                $problem = psgcCheckPlace($place['region'], $place['province'], $place['city'], $place['barangay'], 0);
                if ($problem !== '') {
                    $errors[] = $field['label'] . ': ' . $problem . '.';
                }
                if (!empty($field['zip']) && $place['zip'] !== '' && !preg_match('/^\d{4}$/', $place['zip'])) {
                    $errors[] = 'ZIP Code must be 4 digits.';
                }
                $clean[$key] = $place;
                continue;
            }
            $value = profileCleanText($profile_in[$key] ?? '', $field['max'] ?? 30);
            if ($value !== '') {
                if ($field['type'] === 'select' && !in_array($value, $field['options'], true)) {
                    $value = '';
                } elseif ($field['type'] === 'tel' && !preg_match(PROFILE_PHONE_PATTERN, $value)) {
                    $errors[] = $field['label'] . ' is not a valid phone number.';
                } elseif ($field['type'] === 'date') {
                    $age = profileAge($value);
                    if ($age === null) {
                        $errors[] = $field['label'] . ' is not a valid date.';
                    } elseif ($age < PROFILE_MIN_AGE || $age > PROFILE_MAX_AGE) {
                        $errors[] = 'Please check your ' . strtolower($field['label']) . ' - it gives an age of ' . $age . '.';
                    }
                }
            }
            $clean[$key] = $value;
        }
    }

    $max_year = (int)date('Y') + 1;
    $clean['education'] = [];
    foreach (getProfileEducationLevels() as $key => $level) {
        $in = is_array($education_in[$key] ?? null) ? $education_in[$key] : [];
        $place = profileCleanPlace($in['place'] ?? [], false);
        $row = [
            'school' => profileCleanText($in['school'] ?? '', 150),
            'region' => $place['region'],
            'province' => $place['province'],
            'city' => $place['city'],
            'barangay' => $place['barangay'],
            'address' => profileComposePlace($place, false),
            'year_from' => profileCleanText($in['year_from'] ?? '', 4),
            'year_to' => profileCleanText($in['year_to'] ?? '', 4),
        ];
        if (!empty($level['extra'])) {
            $row['extra'] = profileCleanText($in['extra'] ?? '', 100);
        }
        $name = $level['short'] ?? $level['label'];

        $problem = psgcCheckPlace($place['region'], $place['province'], $place['city'], $place['barangay'], 0);
        if ($problem !== '') {
            $errors[] = "$name address: $problem.";
        }

        foreach (['year_from' => 'starting', 'year_to' => 'ending'] as $year_key => $which) {
            $year = $row[$year_key];
            if ($year === '') continue;
            if (!ctype_digit($year) || (int)$year < PROFILE_MIN_SCHOOL_YEAR || (int)$year > $max_year) {
                $errors[] = "$name: the $which year must be between " . PROFILE_MIN_SCHOOL_YEAR . " and $max_year.";
            }
        }
        if (ctype_digit($row['year_from']) && ctype_digit($row['year_to']) && (int)$row['year_from'] > (int)$row['year_to']) {
            $errors[] = "$name: the starting year cannot be after the ending year.";
        }
        if ($row['school'] === '' && implode('', $row) !== '') {
            $errors[] = "$name: please enter the name of the school.";
        }

        $clean['education'][$key] = $row;
    }

    return [$clean, $errors];
}

/**
 * The labels of the required answers a profile still lacks, in form order.
 * An empty list means the profile is complete.
 *
 * $user is the student's `users` row (contact_number, address) and $profile
 * the decoded profile_data. This is the one definition of "incomplete": the
 * badge on the picture, the notice in Settings and the footer of the profile
 * modal all read it, so they always agree.
 */
function profileMissingFields($user, $profile) {
    $missing = [];
    $filled = fn($value) => is_string($value) && trim($value) !== '';

    foreach (getProfileSections() as $section_key => $section) {
        foreach ($section['fields'] as $key => $field) {
            if (empty($field['required'])) continue;
            if ($field['type'] === 'address') {
                if (!profilePlaceComplete($profile[$key] ?? null)) $missing[] = $field['label'];
                if (!empty($field['zip']) && !$filled($profile[$key]['zip'] ?? '')) $missing[] = 'ZIP Code';
            } elseif (!$filled($profile[$key] ?? '')) {
                // "Father's Last Name", "Guardian: Full Name": a bare "Last Name" would not say whose.
                $missing[] = ($section['missing_prefix'] ?? '') . $field['label'];
            }
        }
        // The contact number lives in its own column; it sits after Personal Information.
        if ($section_key === 'personal' && !$filled($user['contact_number'] ?? '')) {
            $missing[] = 'Contact Number';
        }
    }
    foreach (getProfileEducationLevels() as $key => $level) {
        if (!empty($level['always']) && !$filled($profile['education'][$key]['school'] ?? '')) {
            $name = $level['short'] ?? $level['label'];
            $missing[] = preg_match('/school$/i', $name) ? $name : $name . ' school';
        }
    }
    return $missing;
}

/**
 * The `users` row of the student a signed-in user may look at, or null.
 *
 * A student only ever gets their own row ($student_id is ignored for them). A
 * registrar gets any student of their own institution - the scoping every
 * registrar page uses - and one outside it is reported exactly like one that
 * does not exist. Everyone else gets null. student_profile_view.php, the
 * registrar's profile editor, the photo download and the profile PDF all ask
 * this, so they can never disagree about who may see whom.
 */
function fetchStudentForViewer($mysqli, $student_id, $viewer_id, $viewer_role) {
    $viewer_id = (int)$viewer_id;
    if ($viewer_role === 'student') {
        $student_id = $viewer_id;
    } elseif ($viewer_role !== 'registrar') {
        return null;
    }
    $student_id = (int)$student_id;
    if ($student_id <= 0 || $viewer_id <= 0) return null;

    $stmt = $mysqli->prepare("SELECT id, first_name, middle_name, last_name, student_id, email, profile_picture,
                                     year_level, section, course, institution, contact_number, address, profile_data
                              FROM users WHERE id = ? AND role = 'student'");
    if (!$stmt) return null;
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($user && $viewer_role === 'registrar') {
        $stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
        $stmt->bind_param('i', $viewer_id);
        $stmt->execute();
        $viewer_institution = $stmt->get_result()->fetch_assoc()['institution'] ?? '';
        $stmt->close();
        if (!empty($viewer_institution) && ($user['institution'] ?? '') !== $viewer_institution) {
            return null;
        }
    }
    return $user;
}

/** Drops the blank answers, at every level, so the stored JSON holds only what was filled in. */
function packStudentProfile($clean) {
    $pack = function ($value) use (&$pack) {
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $item) {
            $item = $pack($item);
            if ($item !== '' && $item !== []) $out[$key] = $item;
        }
        return $out;
    };
    return json_encode($pack($clean), JSON_UNESCAPED_UNICODE);
}
