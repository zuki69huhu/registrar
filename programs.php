<?php
/**
 * Programs (courses) offered per institution.
 * Keep in sync with the registration form in index.php, which saves the chosen program in users.course.
 */
function getInstitutionPrograms() {
    return [
        'ISAP' => ['BS Accountancy', 'BS Business administration', 'BS Computer engineering', 'BS Criminology', 'BS Custom Administration', 'BS Hospitality management', 'BS Information Technology', 'BS Physical education', 'BS Psychology', 'BS Secondary education', 'BS Social work', 'BS Tourism management'],
        'MCNP' => ['BS Medical laboratory science', 'BS Midwifery', 'BS Nursing', 'BS Pharmacy', 'BS Physical therapy', 'BS Radiologic technology', 'Dental technology'],
    ];
}

/**
 * Every program as ['institution' => ..., 'course' => ...], including course values already saved
 * on users that are not in the list above. Sorted by institution, then course name.
 */
function getProgramFilterOptions($mysqli) {
    $options = [];
    foreach (getInstitutionPrograms() as $institution => $courses) {
        foreach ($courses as $course) {
            $options[strtolower("$institution|$course")] = ['institution' => $institution, 'course' => $course];
        }
    }

    $res = $mysqli->query("SELECT DISTINCT institution, course FROM users WHERE institution IS NOT NULL AND institution <> '' AND course IS NOT NULL AND course <> ''");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = strtolower($row['institution'] . '|' . $row['course']);
            if (!isset($options[$key])) {
                $options[$key] = ['institution' => $row['institution'], 'course' => $row['course']];
            }
        }
    }

    $options = array_values($options);
    usort($options, function ($a, $b) {
        return strcmp($a['institution'], $b['institution']) ?: strcasecmp($a['course'], $b['course']);
    });
    return $options;
}
