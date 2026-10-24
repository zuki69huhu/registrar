<?php
/**
 * The Student Profile form, drawn once for both people who may fill it in:
 *
 *   'student'    student_settings.php (Edit Profile), posted to process_profile.php
 *   'registrar'  the registrar's profile modal (student_profile_view.php?mode=edit),
 *                posted to process_registrar_profile.php
 *
 * Every field comes from profile_config.php, so the two can never disagree. The
 * only differences are who may change what: a student sees their name and
 * e-mail read-only (they ask the Registrar's Office to correct them), while a
 * registrar may also correct the name and the student number. The e-mail is
 * read-only for both, because it is the student's sign-in.
 *
 * A student fills in their name and date of birth ONE time: once a profile
 * has been saved with a date of birth (users.identity_edited_at, set by
 * process_profile.php, or a birth date already on file), both are disabled for
 * them and only the registrar can change them. Either
 * editor sees the formal photo guidelines; a student confirms them before a
 * new picture is accepted.
 *
 * The address dropdowns are filled by address_picker.js and the school lists by
 * school_picker.js; student_profile.js wires the age hint and the year checks
 * (wireProfileForm), for the page and for the modal alike.
 */

require_once __DIR__ . '/profile_config.php';
require_once __DIR__ . '/programs.php';

define('PROFILE_PICTURE_MAX_BYTES', 5 * 1024 * 1024);
define('PROFILE_YEAR_LEVELS', ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Irregular']);

/**
 * Region -> province -> city / municipality -> barangay, filled by
 * address_picker.js from psgc/. $opts: street, zip, required, legacy (an
 * address typed before the picker existed, shown so the same place can be picked).
 */
function profileFormPlacePicker($name, $id, $place, array $opts = []) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $place = is_array($place) ? $place : [];
    $levels = ['region' => 'Region', 'province' => 'Province', 'city' => 'City / Municipality', 'barangay' => 'Barangay'];
    $html = '<div class="pf-place" data-place-group id="' . $id . '_group">';
    foreach ($levels as $level => $label) {
        $html .= '<div><label class="form-label pf-place-label" for="' . $id . '_' . $level . '">' . $label . '</label>'
               . '<select class="form-select" id="' . $id . '_' . $level . '" name="' . $name . '[' . $level . ']" data-place="' . $level . '"'
               . ' data-value="' . $h($place[$level] ?? '') . '">'
               . '<option value="">' . $h(($place[$level] ?? '') !== '' ? $place[$level] : 'Loading...') . '</option></select></div>';
    }
    if (!empty($opts['street'])) {
        $html .= '<div class="pf-place-street"><label class="form-label pf-place-label" for="' . $id . '_street">House No. / Street / Purok</label>'
               . '<input type="text" class="form-input" id="' . $id . '_street" name="' . $name . '[street]" maxlength="150"'
               . ' placeholder="Leave blank if none" value="' . $h($place['street'] ?? '') . '"></div>';
    }
    if (!empty($opts['zip'])) {
        $html .= '<div><label class="form-label pf-place-label" for="' . $id . '_zip">ZIP Code' . (!empty($opts['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</label>'
               . '<input type="text" class="form-input" id="' . $id . '_zip" name="' . $name . '[zip]" inputmode="numeric" maxlength="4" pattern="\d{4}"'
               . ' title="4 digits, e.g. 3500" placeholder="e.g. 3500" value="' . $h($place['zip'] ?? '') . '"></div>';
    }
    $html .= '</div>';
    if (!empty($opts['legacy']) && trim((string)($place['region'] ?? '')) === '') {
        $html .= '<span class="pf-hint pf-legacy"><i class="fas fa-circle-info"></i> Typed before: <strong>'
               . $h($opts['legacy']) . '</strong> &mdash; please pick it from the lists above.</span>';
    }
    return $html;
}

/**
 * Echoes every field of the profile form (not the <form> tag, the hidden
 * inputs or the buttons, which belong to the page).
 *
 * $user  the `users` row, or the values a failed save brought back:
 *        first_name, middle_name, last_name, student_id, email, institution,
 *        year_level, section, course, contact_number, address
 * $profile  the decoded profile_data (or what a failed save brought back)
 * $editor   'student' | 'registrar'
 */
function renderProfileForm(array $user, array $profile, $editor = 'student') {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $is_registrar = $editor === 'registrar';
    $whose = $is_registrar ? "the student's" : 'my';
    // Name and date of birth are filled in once: locked after the first save with
    // a date of birth. `birth_date_saved` is what is on file, not what a failed
    // save brought back.
    $identity_locked = !$is_registrar
        && (!empty($user['identity_edited_at']) || trim((string)($user['birth_date_saved'] ?? '')) !== '');
    $profile_age = profileAge($profile['birth_date'] ?? '');
    $max_year = (int)date('Y') + 1;
    $institution = (string)($user['institution'] ?? '');
    $year_level = (string)($user['year_level'] ?? '');
    $section = (string)($user['section'] ?? '');
    $course = (string)($user['course'] ?? '');

    // One input of the profile, drawn from its definition in profile_config.php.
    $profile_input = function ($key, $field) use ($profile, $profile_age, $user, $h, $whose, $identity_locked) {
        if ($field['type'] === 'address') {
            $legacy = $key === 'home' ? ($user['address'] ?? '') : ($key === 'guardian_place' ? ($profile['guardian_address'] ?? '') : '');
            echo '<div class="is-wide"><div class="pf-place-head"><span class="form-label">' . $h($field['label'])
               . (!empty($field['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</span>';
            if (!empty($field['copy_home'])) {
                // Most families share one address: copy the permanent one in a click.
                echo '<button type="button" class="pf-copy-place" data-copy-from="pf_home_group" data-copy-to="pf_' . $key . '_group">'
                   . '<i class="fas fa-copy"></i> Same as ' . $whose . ' permanent address</button>';
            }
            echo '</div>';
            echo profileFormPlacePicker('profile[' . $key . ']', 'pf_' . $key, $profile[$key] ?? [], [
                'street' => true, 'zip' => !empty($field['zip']), 'required' => !empty($field['required']), 'legacy' => is_string($legacy) ? $legacy : '',
            ]);
            echo '</div>';
            return;
        }
        // A parent's name used to be one box; until it is split, show what was typed.
        $legacy_name = ['father_last_name' => 'father_name', 'mother_last_name' => 'mother_name'][$key] ?? null;
        $value = $h($profile[$key] ?? '');
        $id = 'pf_' . $key;
        $name = 'profile[' . $key . ']';
        echo '<div' . (!empty($field['wide']) ? ' class="is-wide"' : '') . '>';
        echo '<label class="form-label" for="' . $id . '">' . $h($field['label']) . (!empty($field['required']) ? ' <span class="pf-req" title="Required">*</span>' : '') . '</label>';
        if ($field['type'] === 'select') {
            echo '<select class="form-select" id="' . $id . '" name="' . $name . '"><option value="">Select...</option>';
            foreach ($field['options'] as $option) {
                echo '<option' . (($profile[$key] ?? '') === $option ? ' selected' : '') . '>' . $h($option) . '</option>';
            }
            echo '</select>';
        } elseif ($field['type'] === 'date') {
            $locked = $identity_locked && $value !== '';
            echo '<input class="form-input' . ($locked ? ' pf-readonly' : '') . '" type="date" id="' . $id . '" name="' . $name . '" value="' . $value . '" max="' . date('Y-m-d') . '" data-pf-birth'
               . ($locked ? ' disabled title="Locked - ask the Registrar\'s Office to change it"' : '') . '>';
            echo '<span class="pf-hint" data-pf-age aria-live="polite">' . ($profile_age !== null ? 'Age: ' . $profile_age . ' years old' : 'The age is worked out from this.') . '</span>';
        } elseif ($field['type'] === 'tel') {
            echo '<input class="form-input" type="tel" inputmode="tel" autocomplete="off" id="' . $id . '" name="' . $name . '" value="' . $value . '" maxlength="20" pattern="' . PROFILE_PHONE_HTML_PATTERN . '" title="Digits only, e.g. 09123456789" placeholder="e.g. 09123456789">';
        } else {
            // Place of birth locks with the name and date of birth.
            $locked = $key === 'birth_place' && $identity_locked && $value !== '';
            echo '<input class="form-input' . ($locked ? ' pf-readonly' : '') . '" type="text" id="' . $id . '" name="' . $name . '" value="' . $value . '" maxlength="' . (int)($field['max'] ?? 120) . '" placeholder="' . $h($field['placeholder'] ?? '') . '"'
               . ($locked ? ' disabled title="Locked - ask the Registrar\'s Office to change it"' : '') . '>';
        }
        if ($legacy_name && ($profile[$key] ?? '') === '' && !empty($profile[$legacy_name])) {
            echo '<span class="pf-hint pf-legacy">Typed before as one line: <strong>' . $h($profile[$legacy_name]) . '</strong></span>';
        }
        echo '</div>';
    };

    // A read-only box, or for the registrar an editable one.
    $name_box = function ($key, $label, $required = false, $max = 100) use ($user, $h, $is_registrar, $identity_locked) {
        $editable = $is_registrar || ($key !== 'student_id' && !$identity_locked);
        echo '<div><label class="form-label" for="pf_' . $key . '">' . $h($label)
           . ($editable && $required ? ' <span class="pf-req" title="Required">*</span>' : '') . '</label>';
        if ($editable) {
            echo '<input type="text" class="form-input" id="pf_' . $key . '" name="' . $key . '" maxlength="' . (int)$max . '"'
               . ($required ? ' required' : '') . ' value="' . $h($user[$key] ?? '') . '">';
        } else {
            echo '<input type="text" class="form-input pf-readonly" id="pf_' . $key . '" value="' . $h($user[$key] ?? '') . '" disabled'
               . ' title="Locked - ask the Registrar\'s Office to change it">';
        }
        echo '</div>';
    };

    // The institution's programs, plus a saved course the list no longer has,
    // so opening and saving the form never silently clears it.
    $programs = getInstitutionPrograms();
    $groups = $institution !== '' && isset($programs[$institution]) ? [$institution => $programs[$institution]] : $programs;
    $listed = array_merge(...array_values($groups));
    $sections = getProfileSections();
    ?>
    <div class="pf-photo" data-pf-photo>
        <div class="pf-photo-preview">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="<?= $h($user['profile_picture']) ?>" alt="Current profile picture" data-pf-photo-img>
            <?php else: ?>
                <span class="pf-photo-empty" data-pf-photo-img><i class="fas fa-user"></i><small>2x2 photo</small></span>
            <?php endif; ?>
        </div>
        <div class="pf-photo-body">
            <label class="form-label" for="pf_profile_picture">Profile Picture</label>
            <ul class="pf-photo-rules">
                <li><i class="fas fa-user-tie"></i> <strong>Formal attire</strong> &mdash; collared shirt or blouse, or the school uniform</li>
                <li><i class="fas fa-square"></i> <strong>Plain white background</strong>, no other people or objects</li>
                <li><i class="fas fa-face-smile"></i> Face the camera, <strong>whole face and both ears visible</strong>, neutral expression</li>
                <li><i class="fas fa-ban"></i> No cap, sunglasses, filters, stickers or heavy edits</li>
                <li><i class="fas fa-sun"></i> Recent (last 6 months), clear and well-lit, like a 2x2 ID photo</li>
            </ul>
            <div class="file-input-wrapper">
                <input type="file" id="pf_profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-pf-photo-input>
            </div>
            <span class="pf-hint">JPG, PNG or WebP, up to <?= (int)(PROFILE_PICTURE_MAX_BYTES / 1048576) ?> MB. Leave empty to keep the current picture.</span>
            <?php if (!$is_registrar): ?>
                <label class="pf-photo-confirm" data-pf-photo-confirm hidden>
                    <input type="checkbox" name="photo_confirm" value="1">
                    <span>I confirm this is a recent, formal photo of myself that follows the guidelines above.</span>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div>
            <label class="form-label" for="pf_year_level">Year Level</label>
            <select id="pf_year_level" name="year_level" class="form-select">
                <option value="">Select Year Level...</option>
                <?php foreach (PROFILE_YEAR_LEVELS as $level): ?>
                    <option value="<?= $h($level) ?>" <?= $year_level === $level ? 'selected' : '' ?>><?= $h($level) ?></option>
                <?php endforeach; ?>
                <?php if ($year_level !== '' && !in_array($year_level, PROFILE_YEAR_LEVELS, true)): ?>
                    <option value="<?= $h($year_level) ?>" selected><?= $h($year_level) ?></option>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="pf_section">Section</label>
            <select id="pf_section" name="section" class="form-select">
                <option value="">Select Section...</option>
                <?php foreach (range('A', 'Z') as $letter): ?>
                    <option value="<?= $letter ?>" <?= $section === (string)$letter ? 'selected' : '' ?>>Section <?= $letter ?></option>
                <?php endforeach; ?>
                <?php if ($section !== '' && !in_array($section, range('A', 'Z'), true)): ?>
                    <option value="<?= $h($section) ?>" selected>Section <?= $h($section) ?></option>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <div class="form-grid-2">
        <div>
            <label class="form-label">Institution</label>
            <input type="text" value="<?= $h($institution) ?>" readonly class="form-input pf-readonly">
        </div>
        <div>
            <label class="form-label" for="pf_course">Course / Program</label>
            <select id="pf_course" name="course" class="form-select">
                <option value="">Select Course...</option>
                <?php foreach ($groups as $group => $courses): ?>
                    <optgroup label="<?= $h($group) ?>">
                        <?php foreach ($courses as $option): ?>
                            <option value="<?= $h($option) ?>" <?= $course === $option ? 'selected' : '' ?>><?= $h(ucwords($option)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <?php if ($course !== '' && !in_array($course, $listed, true)): ?>
                    <option value="<?= $h($course) ?>" selected><?= $h($course) ?></option>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <div class="pf-section">
        <h4><i class="fas <?= $sections['personal']['icon'] ?>"></i> <?= $h($sections['personal']['title']) ?></h4>
        <?php if ($is_registrar): ?>
            <p>As registrar you may also correct the student's name and student ID. The e-mail is their sign-in and stays as it is.</p>
        <?php elseif ($identity_locked): ?>
            <p><i class="fas fa-lock"></i> Your name, date of birth and place of birth are locked. Ask the Registrar's Office if any of them needs correcting.</p>
        <?php endif; ?>
    </div>
    <div class="pf-grid">
        <?php
        $name_box('last_name', 'Last Name', true);
        $name_box('first_name', 'First Name', true);
        $name_box('middle_name', 'Middle Name');
        if ($is_registrar) $name_box('student_id', 'Student ID', false, 30);
        foreach ($sections['personal']['fields'] as $key => $field) $profile_input($key, $field);
        ?>
    </div>

    <div class="pf-section">
        <h4><i class="fas fa-address-book"></i> Contact Details</h4>
    </div>
    <div class="pf-grid">
        <div>
            <label class="form-label" for="pf_contact_number">Contact Number <span class="pf-req" title="Required">*</span></label>
            <input type="tel" inputmode="tel" id="pf_contact_number" name="contact_number" placeholder="e.g. 09123456789" value="<?= $h($user['contact_number'] ?? '') ?>" class="form-input" maxlength="20" pattern="<?= PROFILE_PHONE_HTML_PATTERN ?>" title="Digits only, e.g. 09123456789">
        </div>
        <div>
            <label class="form-label" for="pf_email">Email</label>
            <input type="text" id="pf_email" class="form-input pf-readonly" value="<?= $h($user['email'] ?? '') ?>" readonly>
        </div>
    </div>

    <?php foreach ($sections as $section_key => $profile_section): ?>
        <?php if ($section_key === 'personal') continue; ?>
        <div class="pf-section">
            <h4><i class="fas <?= $profile_section['icon'] ?>"></i> <?= $h($profile_section['title']) ?></h4>
            <?php if (!empty($profile_section['note'])): ?><p><?= $h($profile_section['note']) ?></p><?php endif; ?>
        </div>
        <div class="pf-grid">
            <?php foreach ($profile_section['fields'] as $key => $field) $profile_input($key, $field); ?>
        </div>
    <?php endforeach; ?>

    <div class="pf-section">
        <h4><i class="fas fa-graduation-cap"></i> Educational Background</h4>
        <p>Every school attended, starting from the first, with the years spent there, e.g. 2012 to 2018.</p>
    </div>
    <?php foreach (getProfileEducationLevels() as $level_key => $level): ?>
        <?php
        $edu = $profile['education'][$level_key] ?? [];
        $edu_name = 'education[' . $level_key . ']';
        $edu_id = 'pf_edu_' . $level_key;
        ?>
        <div class="pf-edu">
            <div class="pf-edu-title">
                <?= $h($level['label']) ?>
                <?php if (empty($level['always'])): ?><small>&ndash; optional</small><?php endif; ?>
            </div>
            <div class="pf-edu-grid"<?= !empty($level['school_list']) ? ' data-school-picker data-levels="' . (int)$level['school_list'] . '"' : '' ?>>
                <?php /* Address first: once the city is chosen, school_picker.js lists that place's schools. */ ?>
                <div class="pf-edu-place">
                    <label class="form-label">School Address</label>
                    <?= profileFormPlacePicker($edu_name . '[place]', $edu_id . '_place', $edu, ['legacy' => $edu['address'] ?? '']) ?>
                </div>
                <div class="pf-edu-school">
                    <label class="form-label" for="<?= $edu_id ?>_<?= !empty($level['school_list']) ? 'pick' : 'school' ?>">Name of School<?php if (!empty($level['always'])): ?> <span class="pf-req" title="Required">*</span><?php endif; ?></label>
                    <?php if (!empty($level['school_list'])): ?>
                        <select class="form-select" id="<?= $edu_id ?>_pick" data-school-select disabled>
                            <option value="">Choose the school's city / municipality first</option>
                        </select>
                    <?php endif; ?>
                    <input type="text" class="form-input" id="<?= $edu_id ?>_school" name="<?= $edu_name ?>[school]" maxlength="150"
                           <?= !empty($level['school_list']) ? 'data-school-other placeholder="Type the name of the school"' : 'placeholder="Name of the college"' ?>
                           value="<?= $h($edu['school'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label" for="<?= $edu_id ?>_from">S.Y. From</label>
                    <input type="number" inputmode="numeric" class="form-input pf-year-from" id="<?= $edu_id ?>_from" name="<?= $edu_name ?>[year_from]" min="<?= PROFILE_MIN_SCHOOL_YEAR ?>" max="<?= $max_year ?>" placeholder="YYYY" value="<?= $h($edu['year_from'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label" for="<?= $edu_id ?>_to">S.Y. To</label>
                    <input type="number" inputmode="numeric" class="form-input pf-year-to" id="<?= $edu_id ?>_to" name="<?= $edu_name ?>[year_to]" min="<?= PROFILE_MIN_SCHOOL_YEAR ?>" max="<?= $max_year ?>" placeholder="YYYY" value="<?= $h($edu['year_to'] ?? '') ?>">
                </div>
                <?php if (!empty($level['extra'])): ?>
                    <div class="is-wide">
                        <label class="form-label" for="<?= $edu_id ?>_extra"><?= $h($level['extra']) ?></label>
                        <input type="text" class="form-input" id="<?= $edu_id ?>_extra" name="<?= $edu_name ?>[extra]" maxlength="100" placeholder="<?= $h($level['extra_placeholder'] ?? '') ?>" value="<?= $h($edu['extra'] ?? '') ?>">
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php
}

/**
 * Saves an uploaded profile picture from $_FILES[$field].
 *
 * Returns [$path, $error]: [null, ''] when nothing was chosen, [path, ''] on
 * success (the file is on disk and in the database copy), [null, reason] when
 * it was refused. The bytes must really be a picture, not just named like one.
 */
function saveProfilePictureUpload($mysqli, $field = 'profile_picture') {
    require_once __DIR__ . '/upload_store_helper.php';
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > PROFILE_PICTURE_MAX_BYTES) {
        return [null, 'The profile picture is larger than ' . (int)(PROFILE_PICTURE_MAX_BYTES / 1048576) . ' MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'The profile picture could not be uploaded. Please try again.'];

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $info = @getimagesize($file['tmp_name']);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !$info
        || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [null, 'Invalid file type for profile picture. Only JPG, PNG, and WebP are allowed.'];
    }

    $dir = 'uploads/profiles/';
    if (!is_dir(__DIR__ . '/' . $dir)) mkdir(__DIR__ . '/' . $dir, 0777, true);
    $path = $dir . time() . '_' . safeUploadFileName(basename((string)$file['name']));
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $path)) {
        return [null, 'Failed to upload profile picture.'];
    }
    storeUploadInDb($mysqli, $path);
    return [$path, ''];
}
