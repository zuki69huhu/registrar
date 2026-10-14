<?php
/**
 * A student's profile as a read-only HTML fragment.
 *
 *   (no id)  the signed-in student's own profile
 *   ?id=N    that student, for a registrar - and only a student of the
 *            registrar's own institution, the same scoping every registrar
 *            page uses. A student can never name someone else: the id is
 *            ignored for them.
 *   ?status=1  JSON {"missing": N} for the signed-in student: how many required
 *            answers their profile still lacks. student_profile.js turns it
 *            into the red badge on their picture.
 *
 * student_profile.js fetches this the first time a profile is opened and
 * injects it into the modal, so no page pays for a profile until it is
 * actually wanted. Nothing here can be edited - the form lives in
 * student_settings.php. Every value goes through $e(), because the fragment is
 * injected with innerHTML.
 */
session_start();
require_once 'db.php';
require_once 'profile_config.php';

$want_status = isset($_GET['status']);
header('Content-Type: ' . ($want_status ? 'application/json' : 'text/html') . '; charset=utf-8');
header('Cache-Control: private, no-store');

$viewer_role = $_SESSION['role'] ?? '';
$viewer_id = (int)($_SESSION['user_id'] ?? 0);
if (!$viewer_id || !in_array($viewer_role, ['student', 'registrar'], true)) {
    http_response_code(403);
    echo $want_status ? '{"error":"unauthorized"}' : '<p class="sp-empty">Please sign in again to view this profile.</p>';
    exit;
}

$is_own = $viewer_role === 'student';
$target_id = $is_own ? $viewer_id : (int)($_GET['id'] ?? 0);

ensureProfileColumn($mysqli);

if ($want_status) {
    if (!$is_own) {
        http_response_code(403);
        echo '{"error":"unauthorized"}';
        exit;
    }
    $stmt = $mysqli->prepare("SELECT contact_number, address, profile_data FROM users WHERE id = ?");
    $stmt->bind_param('i', $viewer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['missing' => $row ? count(profileMissingFields($row, decodeStudentProfile($row['profile_data'] ?? ''))) : 0]);
    exit;
}

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture,
                                 year_level, section, course, institution, contact_number, address, profile_data
                          FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param('i', $target_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// A registrar sees only their own institution's students. A student outside it
// is reported exactly like one that does not exist.
if ($user && !$is_own) {
    $stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
    $stmt->bind_param('i', $viewer_id);
    $stmt->execute();
    $viewer_institution = $stmt->get_result()->fetch_assoc()['institution'] ?? '';
    $stmt->close();
    if (!empty($viewer_institution) && ($user['institution'] ?? '') !== $viewer_institution) {
        $user = null;
    }
}

if (!$user) {
    http_response_code(404);
    echo '<p class="sp-empty">Profile not found.</p>';
    exit;
}

$e = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$profile = decodeStudentProfile($user['profile_data'] ?? '');
// What a complete profile still needs - the same list that drives the badge.
$missing = profileMissingFields($user, $profile);

// One label/value cell. A blank answer is shown as a dash.
$cell = function ($label, $value, $wide = false) use ($e) {
    $value = trim((string)$value);
    return '<div class="sp-item' . ($wide ? ' is-wide' : '') . '"><dt>' . $e($label) . '</dt>'
         . '<dd' . ($value === '' ? ' class="is-blank">&mdash;' : '>' . $e($value)) . '</dd></div>';
};

$full_name = trim(implode(' ', array_filter([$user['first_name'], $user['middle_name'], $user['last_name']])));
if ($full_name === '') $full_name = $user['email'];

$year_section = trim(($user['year_level'] ?? '') . (!empty($user['section']) ? ' – Section ' . $user['section'] : ''), ' –');
$chips = array_filter([$user['institution'] ?? '', $user['course'] ?? '', $year_section]);

$birth = profileBirthDate($profile['birth_date'] ?? '');
$age = profileAge($profile['birth_date'] ?? '');
?>
<div class="sp-head">
    <?php if (!empty($user['profile_picture'])): ?>
        <img class="sp-photo" src="<?= $e($user['profile_picture']) ?>" alt="">
    <?php else: ?>
        <div class="sp-photo is-initial"><?= $e(strtoupper(mb_substr($user['first_name'] ?: $user['email'], 0, 1))) ?></div>
    <?php endif; ?>
    <div class="sp-head-text">
        <div class="sp-name"><?= $e($full_name) ?></div>
        <div class="sp-id">Student ID: <?= $e($user['student_id'] ?: '—') ?></div>
        <?php if ($chips): ?>
            <div class="sp-chips">
                <?php foreach ($chips as $chip): ?><span><?= $e($chip) ?></span><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<section class="sp-sec">
    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
    <dl class="sp-grid">
        <?= $cell('Last Name', $user['last_name']) ?>
        <?= $cell('First Name', $user['first_name']) ?>
        <?= $cell('Middle Name', $user['middle_name']) ?>
        <?= $cell('Extension Name', $profile['extension_name'] ?? '') ?>
        <?= $cell('Date of Birth', $birth ? $birth->format('F j, Y') : '') ?>
        <?= $cell('Age', $age !== null ? $age . ' years old' : '') ?>
        <?php foreach (getProfileSections()['personal']['fields'] as $key => $field): ?>
            <?php if ($key === 'birth_date' || $key === 'extension_name') continue; ?>
            <?= $cell($field['label'], $profile[$key] ?? '') ?>
        <?php endforeach; ?>
    </dl>
</section>

<section class="sp-sec">
    <h3><i class="fas fa-address-book"></i> Contact Details</h3>
    <dl class="sp-grid">
        <?= $cell('Contact Number', $user['contact_number']) ?>
        <?= $cell('Email', $user['email']) ?>
        <?php
        // The picked permanent address; an address typed before the picker existed still shows.
        $home = $profile['home'] ?? [];
        $home_line = profilePlaceComplete($home) ? profileComposePlace($home) : ($user['address'] ?? '');
        ?>
        <?= $cell('Permanent Address', $home_line, true) ?>
        <?= $cell('Region', $home['region'] ?? '') ?>
        <?= $cell('ZIP Code', $home['zip'] ?? '') ?>
    </dl>
</section>

<?php foreach (getProfileSections() as $section_key => $section): ?>
    <?php if ($section_key === 'personal' || $section_key === 'home') continue; /* both shown above */ ?>
    <section class="sp-sec">
        <h3><i class="fas <?= $e($section['icon']) ?>"></i> <?= $e($section['title']) ?></h3>
        <dl class="sp-grid">
            <?php foreach ($section['fields'] as $key => $field): ?>
                <?php
                if ($field['type'] === 'address') {
                    // A picked address as one line; one typed before the picker existed still shows.
                    $value = profileComposePlace($profile[$key] ?? []);
                    if ($value === '' && $key === 'guardian_place') $value = $profile['guardian_address'] ?? '';
                } else {
                    $value = $profile[$key] ?? '';
                    // A parent's name used to be one box: show it until it is split.
                    $legacy = ['father_last_name' => 'father_name', 'mother_last_name' => 'mother_name'][$key] ?? null;
                    if ($legacy && $value === '' && !empty($profile[$legacy])) {
                        echo $cell(($key === 'father_last_name' ? "Father's" : "Mother's") . ' Name (entered before)', $profile[$legacy]);
                    }
                }
                ?>
                <?= $cell($field['label'], is_string($value) ? $value : '', !empty($field['wide'])) ?>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endforeach; ?>

<section class="sp-sec">
    <h3><i class="fas fa-graduation-cap"></i> Educational Background</h3>
    <ol class="sp-edu">
        <?php foreach (getProfileEducationLevels() as $key => $level): ?>
            <?php
            $row = $profile['education'][$key] ?? [];
            $school = $row['school'] ?? '';
            if ($school === '' && empty($level['always'])) continue;
            $years = profileSchoolYears($row);
            ?>
            <li class="<?= $school === '' ? 'is-blank' : '' ?>">
                <div class="sp-edu-level"><?= $e($level['short'] ?? $level['label']) ?></div>
                <?php if ($school === ''): ?>
                    <div class="sp-edu-school">Not provided yet</div>
                <?php else: ?>
                    <div class="sp-edu-school"><?= $e($school) ?></div>
                    <?php if (!empty($row['address'])): ?>
                        <div class="sp-edu-meta"><i class="fas fa-location-dot"></i> <?= $e($row['address']) ?></div>
                    <?php endif; ?>
                    <?php if ($years !== ''): ?>
                        <div class="sp-edu-meta"><i class="fas fa-calendar"></i> S.Y. <?= $e($years) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['extra'])): ?>
                        <div class="sp-edu-meta"><i class="fas fa-book"></i> <?= $e($level['extra']) ?>: <?= $e($row['extra']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<div class="sp-foot<?= $missing ? ' is-missing' : '' ?>">
    <i class="fas <?= $missing ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
    <span>
        <?php if ($missing): ?>
            <strong><?= count($missing) ?> required <?= count($missing) === 1 ? 'detail is' : 'details are' ?> still missing:</strong>
            <?= $e(implode(', ', $missing)) ?>.
        <?php else: ?>
            <strong>This profile is complete.</strong>
        <?php endif; ?>
        <?php if ($is_own): ?>
            <?= $missing ? 'Fill them in at' : 'It is view-only here; to change anything, go to' ?>
            <a href="student_settings.php#edit-profile">Settings &rsaquo; Edit Profile</a>.
        <?php else: ?>
            It is view-only: students keep it up to date from their own Settings.
        <?php endif; ?>
    </span>
</div>
