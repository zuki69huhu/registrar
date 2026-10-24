<?php
/**
 * The student's own Student Profile as a one-page, resume-style A4 PDF
 * (download_student_profile_pdf.php for a student; the registrar keeps the
 * formal ruled sheet).
 *
 *   header band   the school's seal and name at the upper left, the full name,
 *                 course, year & section and student ID, and the 2x2 photo
 *                 (50.8 mm) at the upper right
 *   contact strip e-mail, phone, permanent address
 *   left column   personal details and student information
 *   right column  education as a timeline, then the family
 *
 * It is drawn on StudentSheetPdf (profile_pdf_helper.php), so it shares the
 * Tahoma fonts, the cp1252 conversion t() and the photo cropping. The accent is
 * the institution's own colour from its letterhead. A blank answer prints as a
 * dash, as on the registrar's sheet.
 */

require_once __DIR__ . '/profile_pdf_helper.php';

function renderStudentResume(StudentSheetPdf $pdf, array $user, array $profile, $photo_file) {
    $t = [$pdf, 't'];
    $v = [StudentSheetPdf::class, 'v'];
    $institution = strtoupper(trim((string)($user['institution'] ?? '')));
    $head = (getRequestForm($institution, 'graduation') ?: [])['letterhead'] ?? [];
    $hex = ltrim($head['color'] ?? '#1f4e9c', '#');
    $accent = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $navy = [22, 36, 64];
    $ink = [15, 23, 42];
    $muted = [100, 116, 139];
    $L = 14; $R = 196;                       // page margins, mm

    $pdf->AddPage('P', 'A4');

    // ── Header band: the seal and school at the upper left, the 2x2 photo at the upper right ──
    $band = 66;
    $pdf->SetFillColor(...$navy);
    $pdf->Rect(0, 0, 210, $band, 'F');
    $pdf->SetFillColor(...$accent);
    $pdf->Rect(0, $band, 210, 1.6, 'F');

    // 2x2 photo (50.8 mm square) in a white frame
    $ps = 50.8; $px = $R - $ps; $py = 7.6;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($px - 1.2, $py - 1.2, $ps + 2.4, $ps + 2.4, 'F');
    $prepared = $photo_file ? $pdf->preparePhoto($photo_file, $ps, $ps) : null;
    if ($prepared) {
        $pdf->Image($prepared, $px, $py, $ps, $ps, 'JPG');
    } else {
        $pdf->SetFillColor(226, 232, 240);
        $pdf->Rect($px, $py, $ps, $ps, 'F');
        $initials = strtoupper(mb_substr((string)$user['first_name'], 0, 1) . mb_substr((string)$user['last_name'], 0, 1));
        $pdf->SetFont('Tahoma', 'B', 28);
        $pdf->SetTextColor(...$navy);
        $pdf->SetXY($px, $py + $ps / 2 - 9);
        $pdf->Cell($ps, 12, $t($initials), 0, 2, 'C');
        $pdf->SetFont('Tahoma', 'B', 7);
        $pdf->SetTextColor(...$muted);
        $pdf->Cell($ps, 5, '2x2 PHOTO', 0, 0, 'C');
    }
    $tw = $px - 8 - $L;                     // text area, clear of the photo

    // Seal and school, like a letterhead corner
    $seal = $head['logo'] ?? 'logo.jpg';
    $seal_path = is_file(__DIR__ . '/' . $seal) ? __DIR__ . '/' . $seal : (is_file(__DIR__ . '/logo.jpg') ? __DIR__ . '/logo.jpg' : null);
    $sx = $L;
    if ($seal_path) {
        $pdf->Image($seal_path, $L, 7, 18, 18);
        $sx = $L + 22;
    }
    $school = $t(mb_strtoupper($head['name'] ?? $institution));
    $fs = 10;
    $pdf->SetFont('Tahoma', 'B', $fs);
    while ($fs > 7 && $pdf->GetStringWidth($school) > $tw - ($sx - $L)) {
        $fs -= 0.25;
        $pdf->SetFont('Tahoma', 'B', $fs);
    }
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($sx, 10);
    $pdf->Cell($tw - ($sx - $L), 5, $school, 0, 2, 'L');
    $pdf->SetFont('Tahoma', '', 8);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell($tw - ($sx - $L), 4.5, $t('Office of the Registrar  |  Student Profile'), 0, 2, 'L');
    $pdf->SetDrawColor(51, 65, 85);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($L, 29.5, $L + $tw, 29.5);

    // Name, fitted to the space beside the photo
    $name = trim(implode(' ', array_filter([$user['first_name'] ?? '', $user['middle_name'] ?? '', $user['last_name'] ?? '',
                                            $profile['extension_name'] ?? ''])));
    $name = $t(mb_strtoupper($name));
    $size = 21;
    $pdf->SetFont('Tahoma', 'B', $size);
    while ($size > 12 && $pdf->GetStringWidth($name) > $tw) {
        $size -= 0.5;
        $pdf->SetFont('Tahoma', 'B', $size);
    }
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($L, 33);
    $pdf->Cell($tw, 10, $name, 0, 2, 'L');

    $pdf->SetFont('Tahoma', 'B', 12);
    $pdf->SetTextColor(250, 204, 21);
    $pdf->SetX($L);
    $pdf->Cell($tw, 7, $t($v($user['course'] ?? '')), 0, 2, 'L');

    $year_sec = trim((string)($user['year_level'] ?? '') . (!empty($user['section']) ? ' - Section ' . $user['section'] : ''), ' -');
    $pdf->SetFont('Tahoma', '', 10);
    $pdf->SetTextColor(203, 213, 225);
    $pdf->SetX($L);
    $pdf->Cell($tw, 6, $t(implode('   |   ', array_filter([$year_sec, 'Student ID: ' . $v($user['student_id'] ?? '')]))), 0, 2, 'L');

    // ── Contact strip ──
    $home = $profile['home'] ?? [];
    $home_line = profilePlaceComplete($home) ? profileComposePlace($home) : (string)($user['address'] ?? '');
    if (!empty($home['zip'])) $home_line .= ' ' . $home['zip'];
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Rect(0, $band + 1.6, 210, 13, 'F');
    $items = [['EMAIL', $user['email'] ?? ''], ['PHONE', $user['contact_number'] ?? ''], ['ADDRESS', $home_line]];
    $widths = [62, 36, $R - $L - 98];
    $x = $L;
    foreach ($items as $i => [$label, $value]) {
        $pdf->SetFont('Tahoma', 'B', 6.5);
        $pdf->SetTextColor(...$accent);
        $pdf->SetXY($x, $band + 4);
        $pdf->Cell($widths[$i], 3.5, $label, 0, 2, 'L');
        $value = $t($v($value));
        $fs = 9;
        $pdf->SetFont('Tahoma', 'B', $fs);
        while ($fs > 6 && $pdf->GetStringWidth($value) > $widths[$i] - 2) {
            $fs -= 0.5;
            $pdf->SetFont('Tahoma', 'B', $fs);
        }
        $pdf->SetTextColor(...$ink);
        $pdf->Cell($widths[$i] - 2, 5, $value, 0, 0, 'L');
        $x += $widths[$i];
    }

    // ── Section title, shared by both columns ──
    $title = function ($text, $x, $y, $w) use ($pdf, $t, $navy, $accent) {
        $pdf->SetFont('Tahoma', 'B', 10.5);
        $pdf->SetTextColor(...$navy);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 6, $t($text), 0, 0, 'L');
        $pdf->SetDrawColor(...$accent);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($x, $y + 6.6, $x + 14, $y + 6.6);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($x + 14, $y + 6.6, $x + $w, $y + 6.6);
        return $y + 10;
    };

    // ── Left column: personal details ──
    $lx = $L; $lw = 60; $y0 = $band + 22;
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect($lx - 3, $y0 - 3, $lw + 6, 283 - $y0, 'F');
    $detail = function ($label, $value, $y) use ($pdf, $t, $v, $lx, $lw, $muted, $ink) {
        $pdf->SetFont('Tahoma', '', 6.8);
        $pdf->SetTextColor(...$muted);
        $pdf->SetXY($lx, $y);
        $pdf->Cell($lw, 3.6, $t(strtoupper($label)), 0, 2, 'L');
        $pdf->SetFont('Tahoma', 'B', 9.5);
        $pdf->SetTextColor(...$ink);
        $pdf->MultiCell($lw, 4.4, $t($v($value)), 0, 'L');
        return $pdf->GetY() + 2.2;
    };
    $birth = profileBirthDate($profile['birth_date'] ?? '');
    $age = profileAge($profile['birth_date'] ?? '');
    $y = $title('PERSONAL DETAILS', $lx, $y0, $lw);
    foreach ([
        ['Date of Birth', $birth ? $birth->format('F j, Y') : ''],
        ['Age', $age !== null ? $age . ' years old' : ''],
        ['Place of Birth', $profile['birth_place'] ?? ''],
        ['Sex', $profile['sex'] ?? ''],
        ['Civil Status', $profile['civil_status'] ?? ''],
        ['Nationality', $profile['nationality'] ?? ''],
        ['Religion', $profile['religion'] ?? ''],
        ['Disability', $profile['disability'] ?? ''],
        ['Indigenous People Group', $profile['ip_group'] ?? ''],
    ] as [$label, $value]) {
        $y = $detail($label, $value, $y);
    }
    $y = $title('STUDENT INFORMATION', $lx, $y + 3, $lw);
    foreach ([
        ['Institution', $head['name'] ?? $institution],
        ['Course / Program', $user['course'] ?? ''],
        ['Year & Section', $year_sec],
        ['Student ID', $user['student_id'] ?? ''],
    ] as [$label, $value]) {
        $y = $detail($label, $value, $y);
    }

    // ── Right column: education timeline ──
    $rx = $lx + $lw + 10; $rw = $R - $rx;
    $y = $title('EDUCATIONAL BACKGROUND', $rx, $y0, $rw);
    $levels = getProfileEducationLevels();
    $keys = array_keys($levels);
    foreach ($keys as $n => $key) {
        $level = $levels[$key];
        $row = $profile['education'][$key] ?? [];
        $school = trim((string)($row['school'] ?? ''));
        if ($school === '' && empty($level['always'])) continue;     // an optional level left blank
        $top = $y;
        // the dot, and the line down to the next school
        $pdf->SetFillColor(...($school === '' ? [203, 213, 225] : $accent));
        $pdf->Rect($rx, $top + 1.2, 2.6, 2.6, 'F');
        $tx2 = $rx + 7; $tw2 = $rw - 7;
        $years = profileSchoolYears($row);
        $pdf->SetFont('Tahoma', 'B', 7);
        $pdf->SetTextColor(...$accent);
        $pdf->SetXY($tx2, $top);
        $pdf->Cell($tw2 - 30, 4.6, $t(strtoupper($level['short'] ?? $level['label'])), 0, 0, 'L');
        $pdf->SetFont('Tahoma', 'B', 8.5);
        $pdf->SetTextColor(...$muted);
        $pdf->Cell(30, 4.6, $t($years !== '' ? 'S.Y. ' . $years : ''), 0, 1, 'R');
        $pdf->SetFont('Tahoma', 'B', 10.5);
        $pdf->SetTextColor(...$ink);
        $pdf->SetX($tx2);
        $pdf->MultiCell($tw2, 5, $t($school !== '' ? $school : 'Not provided yet'), 0, 'L');
        $pdf->SetFont('Tahoma', '', 8.8);
        $pdf->SetTextColor(...$muted);
        if (!empty($row['address'])) { $pdf->SetX($tx2); $pdf->MultiCell($tw2, 4.3, $t($row['address']), 0, 'L'); }
        if (!empty($level['extra']) && !empty($row['extra'])) { $pdf->SetX($tx2); $pdf->MultiCell($tw2, 4.3, $t($level['extra'] . ': ' . $row['extra']), 0, 'L'); }
        $y = $pdf->GetY() + 3.5;
        if ($n < count($keys) - 1) {
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetLineWidth(0.5);
            $pdf->Line($rx + 1.3, $top + 4.4, $rx + 1.3, $y + 0.8);
        }
    }

    // ── Right column: family ──
    $person = function ($caption, $name, $lines, $y) use ($pdf, $t, $v, $rx, $rw, $accent, $ink, $muted) {
        $pdf->SetFont('Tahoma', 'B', 7);
        $pdf->SetTextColor(...$accent);
        $pdf->SetXY($rx, $y);
        $pdf->Cell($rw, 4.4, $t(strtoupper($caption)), 0, 2, 'L');
        $pdf->SetFont('Tahoma', 'B', 10.5);
        $pdf->SetTextColor(...$ink);
        $pdf->MultiCell($rw, 5, $t($v($name)), 0, 'L');
        $pdf->SetFont('Tahoma', '', 8.8);
        $pdf->SetTextColor(...$muted);
        $line = implode('   |   ', array_filter($lines, fn($x) => trim((string)$x) !== ''));
        if ($line !== '') { $pdf->SetX($rx); $pdf->MultiCell($rw, 4.3, $t($line), 0, 'L'); }
        return $pdf->GetY() + 3;
    };
    $full = fn($last, $first, $middle) => sheetPersonName($last, $first, $middle);
    $place = fn($key) => profileComposePlace($profile[$key] ?? []);
    $y = $title('FAMILY BACKGROUND', $rx, $y + 2, $rw);
    $y = $person('Father', $full($profile['father_last_name'] ?? '', $profile['father_first_name'] ?? '', $profile['father_middle_name'] ?? '') ?: ($profile['father_name'] ?? ''),
                 [$profile['father_occupation'] ?? '', $profile['father_contact'] ?? '', $place('father_place')], $y);
    $y = $person("Mother (maiden name)", $full($profile['mother_last_name'] ?? '', $profile['mother_first_name'] ?? '', $profile['mother_middle_name'] ?? '') ?: ($profile['mother_name'] ?? ''),
                 [$profile['mother_occupation'] ?? '', $profile['mother_contact'] ?? '', $place('mother_place')], $y);
    $y = $person('Guardian / Person to contact', $profile['guardian_name'] ?? '',
                 [$profile['guardian_relationship'] ?? '', $profile['guardian_contact'] ?? '', $place('guardian_place') ?: ($profile['guardian_address'] ?? '')], $y);

    // ── Footer ──
    $missing = profileMissingFields($user, $profile);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($L, 285, $R, 285);
    $pdf->SetFont('Tahoma', '', 7.2);
    $pdf->SetTextColor(...$muted);
    $pdf->SetXY($L, 286);
    $pdf->Cell(120, 5, $t('Student Profile from the ' . ($head['name'] ?? $institution) . ' registrar portal - ' . date('F j, Y')), 0, 0, 'L');
    $pdf->SetFont('Tahoma', 'B', 7.2);
    $pdf->SetTextColor(...($missing ? [185, 28, 28] : [4, 120, 87]));
    $pdf->Cell($R - $L - 120, 5, $t($missing ? 'Profile incomplete: ' . count($missing) . ' detail(s) missing' : 'Profile complete'), 0, 0, 'R');
}
