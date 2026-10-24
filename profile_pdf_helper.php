<?php
/**
 * The registrar's formal student sheets, drawn with FPDF/FPDI:
 *
 *   download_merged_pdf.php          STUDENT DOCUMENT SUMMARY, the cover page in
 *                                    front of the merged documents
 *   download_student_profile_pdf.php STUDENT PROFILE, the whole Student Profile
 *                                    with the student's picture
 *
 * Both are one A4 page on the institution's own letterhead (the seal, colour and
 * lines of getRequestForm($institution, 'graduation')), then one ruled table: a
 * navy band per part, and boxes that each hold a small caption over the answer.
 * StudentSheetPdf is that drawing, kept in one place so the two sheets always
 * look alike.
 *
 * The letterhead is Times, as on the institution's printed forms; everything
 * below it is Tahoma (fonts/, cp1252), answers at 12pt. The fonts are cp1252,
 * so every string goes through t() (Peñablanca, Ñ, the en dash). An answer is fitted to its box - smaller writing, never cut
 * off; a box marked to wrap may take two lines first.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/profile_config.php';
require_once __DIR__ . '/request_config.php';   // the institution's letterhead

use setasign\Fpdi\Fpdi;

class StudentSheetPdf extends Fpdi
{
    const L = 15;            // left margin, mm
    const W = 180;           // usable width, mm
    const NAVY = [31, 56, 100];
    const RULE = [90, 90, 90];

    /** Where the next band or row is drawn, mm from the top. */
    public $cursor = 64;
    /** Temporary files (the prepared photo) removed once the PDF is sent. */
    private $temp_files = [];

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetAutoPageBreak(false);
        $this->SetMargins(self::L, 10, self::L);
        // Tahoma, subset to cp1252 (fonts/, about 13 KB each, so the PDF stays small).
        $core = $this->fontpath;
        $this->fontpath = __DIR__ . '/fonts/';
        $this->AddFont('Tahoma', '', 'tahoma.php');
        $this->AddFont('Tahoma', 'B', 'tahomabd.php');
        $this->fontpath = $core;
    }

    /** The core fonts' definitions are loaded from FPDF's own folder; only Tahoma's bytes are embedded, from fonts/. */
    protected function _putfonts()
    {
        $core = $this->fontpath;
        $this->fontpath = __DIR__ . '/fonts/';
        parent::_putfonts();
        $this->fontpath = $core;
    }

    public function __destruct()
    {
        foreach ($this->temp_files as $file) @unlink($file);
    }

    /** UTF-8 to the cp1252 of the core fonts. */
    public function t($text)
    {
        $text = (string)$text;
        $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        return $out === false ? $text : $out;
    }

    /** An answer, or a dash for a blank one, so what is missing still shows. */
    public static function v($value)
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : '-';
    }

    /**
     * A new page with the letterhead, the office line, $title and the date it
     * was generated. Leaves the cursor where the table starts.
     */
    public function sheetHeader($institution, $title)
    {
        $L = self::L; $W = self::W;
        $institution = strtoupper(trim((string)$institution));
        $head = (getRequestForm($institution, 'graduation') ?: [])['letterhead'] ?? [];
        $seal = $head['logo'] ?? 'logo.jpg';
        $seal_path = is_file(__DIR__ . '/' . $seal) ? __DIR__ . '/' . $seal : (is_file(__DIR__ . '/logo.jpg') ? __DIR__ . '/logo.jpg' : null);
        $hex = ltrim($head['color'] ?? '#1e3a5f', '#');

        $this->AddPage('P', 'A4');
        $this->SetTextColor(0, 0, 0);

        // ── Letterhead: seal on the left, the school centred, ruled off ──
        if ($seal_path) {
            $this->Image($seal_path, $L + 2, 11, 24, 24);
        }
        // The name is centred between equal margins that clear the seal, and set
        // smaller if it would not fit there.
        $name_w = $W - 2 * 30;
        $school = $this->t($head['name'] ?? 'OFFICE OF THE REGISTRAR');
        $name_size = 15;
        $this->SetFont('Times', 'B', $name_size);
        while ($name_size > 10 && $this->GetStringWidth($school) > $name_w) {
            $name_size -= 0.5;
            $this->SetFont('Times', 'B', $name_size);
        }
        $this->SetTextColor(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $this->SetXY($L + 30, 12);
        $this->Cell($name_w, 7, $school, 0, 1, 'C');
        $this->SetFont('Times', '', 10);
        $this->SetTextColor(0, 31, 95);
        foreach (array_filter([$head['address'] ?? '', $head['contact'] ?? '',
                               trim(($head['website'] ?? '') . (isset($head['email']) ? '   |   ' . $head['email'] : ''))]) as $info) {
            $this->SetX($L);
            $this->Cell($W, 5, $this->t($info), 0, 1, 'C');
        }
        $this->SetDrawColor(85, 133, 191);
        $this->SetLineWidth(0.8);
        $this->Line($L, 39, $L + $W, 39);
        $this->SetLineWidth(0.25);
        $this->Line($L, 40.2, $L + $W, 40.2);

        // ── Title ──
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Tahoma', 'B', 11);
        $this->SetXY($L, 43);
        $this->Cell($W, 5, $this->t($head['office'] ?? 'OFFICE OF THE REGISTRAR'), 0, 1, 'C');
        $this->SetFont('Tahoma', 'B', 15);
        $this->SetX($L);
        $this->Cell($W, 8, $this->t($title), 0, 1, 'C');
        $this->SetFont('Tahoma', '', 8.5);
        $this->SetTextColor(70, 70, 70);
        $this->SetX($L);
        $this->Cell($W, 5, $this->t('Date generated: ' . date('F j, Y, g:i A')), 0, 1, 'R');

        $this->cursor = 64;
    }

    /** A navy band across the table, with the part's title in white. */
    public function band($title)
    {
        $this->SetFillColor(...self::NAVY);
        $this->SetDrawColor(...self::RULE);
        $this->SetLineWidth(0.25);
        $this->Rect(self::L, $this->cursor, self::W, 6.5, 'DF');
        $this->SetFont('Tahoma', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(self::L + 3, $this->cursor);
        $this->Cell(self::W - 6, 6.5, $this->t($title), 0, 0, 'L');
        $this->cursor += 6.5;
    }

    /**
     * One row of boxes. $cells: [[caption, answer, width share, wrap?], ...],
     * the shares adding up to 1 of $width (the whole table unless given).
     */
    public function row(array $cells, $h = 10.5, $width = null)
    {
        $width = $width ?? self::W;
        $x = self::L;
        $y = $this->cursor;
        foreach ($cells as $cell) {
            [$caption, $answer, $share] = $cell;
            $wrap = !empty($cell[3]);
            $w = $width * $share;
            $this->SetDrawColor(...self::RULE);
            $this->SetLineWidth(0.25);
            $this->Rect($x, $y, $w, $h, 'D');

            $caption = $this->t(strtoupper($caption));
            $cap_size = 6.8;
            $this->SetFont('Tahoma', '', $cap_size);
            while ($cap_size > 5 && $this->GetStringWidth($caption) > $w - 3.6) {
                $cap_size -= 0.2;
                $this->SetFont('Tahoma', '', $cap_size);
            }
            $this->SetTextColor(95, 95, 95);
            $this->SetXY($x + 1.8, $y + 0.8);
            $this->Cell($w - 3.6, 3.6, $caption, 0, 0, 'L');

            $this->answer($this->t($answer), $x + 1.8, $y, $w - 3.6, $h, $wrap);
            $x += $w;
        }
        $this->cursor += $h;
    }

    /** Writes an answer into its box: one line, smaller when long; two lines when allowed. */
    private function answer($text, $x, $y, $w, $h, $wrap)
    {
        $this->SetTextColor(15, 23, 42);
        $size = 12;
        $this->SetFont('Tahoma', 'B', $size);
        $floor = $wrap ? 7.5 : 5;      // below that a wrapping box takes two lines instead
        while ($size > $floor && $this->GetStringWidth($text) > $w - 0.4) {
            $size -= 0.5;
            $this->SetFont('Tahoma', 'B', $size);
        }
        if (!$wrap || $this->GetStringWidth($text) <= $w - 0.4) {
            $this->SetXY($x, $y + 4.6);
            $this->Cell($w, 5, $text, 0, 0, 'L');
            return;
        }
        // Two lines, as large as they fit.
        for ($size = 7.5; $size >= 5; $size -= 0.5) {
            $this->SetFont('Tahoma', 'B', $size);
            $lines = $this->wrapLines($text, $w - 0.4);
            if (count($lines) <= 2) break;
        }
        $lines = array_slice($lines, 0, 2);
        // Between the caption (ends 4.4mm down) and the bottom rule.
        $room = $h - 4.8;
        $line_h = min($size * 0.42, $room / 2);
        $top = $y + 4.4 + ($room - $line_h * count($lines)) / 2;
        foreach ($lines as $i => $line) {
            $this->SetXY($x, $top + $i * $line_h);
            $this->Cell($w, $line_h, $line, 0, 0, 'L');
        }
    }

    /** Greedy word wrap with the current font. */
    private function wrapLines($text, $w)
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && $this->GetStringWidth($try) > $w) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    /**
     * The student's picture in a ruled box at ($x, $y), cropped to fill it.
     * Without a usable picture the box says so, like the 2x2 box on a paper form.
     */
    public function photoBox($photo_file, $x, $y, $w, $h)
    {
        $this->SetDrawColor(...self::RULE);
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $w, $h, 'D');
        $pad = 1.4;
        $prepared = $photo_file ? $this->preparePhoto($photo_file, $w - 2 * $pad, $h - 2 * $pad) : null;
        if ($prepared) {
            $this->Image($prepared, $x + $pad, $y + $pad, $w - 2 * $pad, $h - 2 * $pad, 'JPG');
            return;
        }
        $this->SetFillColor(241, 245, 249);
        $this->Rect($x + $pad, $y + $pad, $w - 2 * $pad, $h - 2 * $pad, 'F');
        $this->SetFont('Tahoma', 'B', 7.5);
        $this->SetTextColor(148, 163, 184);
        $this->SetXY($x, $y + $h / 2 - 4);
        $this->Cell($w, 4, '2x2 PHOTO', 0, 2, 'C');
        $this->SetFont('Tahoma', '', 6.5);
        $this->Cell($w, 4, 'not uploaded', 0, 0, 'C');
    }

    /**
     * The picture as a small JPEG cropped to the box: upright (a phone photo's
     * EXIF turn applied), any transparency on white, the face kept by cropping
     * a tall picture from near the top. WebP and interlaced PNGs, which FPDF
     * cannot read, come out as an ordinary JPEG too. Null when it is not a picture.
     */
    public function preparePhoto($file, $w_mm, $h_mm)
    {
        if (!function_exists('imagecreatefromstring') || !is_file($file)) return null;
        $data = @file_get_contents($file);
        $src = $data !== false ? @imagecreatefromstring($data) : false;
        if (!$src) return null;

        if (function_exists('exif_read_data') && strncmp($data, "\xFF\xD8", 2) === 0) {
            // All eight EXIF orientations: [mirror first, then turn (degrees anticlockwise)].
            $exif = @exif_read_data($file);
            [$flip, $turn] = [
                2 => [IMG_FLIP_HORIZONTAL, 0], 3 => [0, 180], 4 => [IMG_FLIP_VERTICAL, 0],
                5 => [IMG_FLIP_VERTICAL, -90], 6 => [0, -90], 7 => [IMG_FLIP_HORIZONTAL, -90], 8 => [0, 90],
            ][(int)($exif['Orientation'] ?? 1)] ?? [0, 0];
            if ($flip) imageflip($src, $flip);
            if ($turn) {
                $rotated = imagerotate($src, $turn, 0);
                if ($rotated) $src = $rotated;
            }
        }

        $sw = imagesx($src); $sh = imagesy($src);
        $ratio = $w_mm / $h_mm;
        if ($sw / $sh > $ratio) {             // too wide: crop the sides evenly
            $cw = (int)round($sh * $ratio); $ch = $sh;
            $cx = (int)(($sw - $cw) / 2); $cy = 0;
        } else {                              // too tall: keep the top, where the face is
            $cw = $sw; $ch = (int)round($sw / $ratio);
            $cx = 0; $cy = (int)(($sh - $ch) * 0.2);
        }
        $out_w = min(480, $cw);
        $out_h = max(1, (int)round($out_w / $ratio));
        $out = imagecreatetruecolor($out_w, $out_h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, $cx, $cy, $out_w, $out_h, $cw, $ch);
        unset($src);

        $temp = tempnam(sys_get_temp_dir(), 'sp_');
        $ok = $temp && imagejpeg($out, $temp, 88);
        unset($out);
        if (!$ok) return null;
        $this->temp_files[] = $temp;
        return $temp;
    }

    /** "Prepared by" (the signed-in registrar) and "Received / Verified by" lines. */
    public function signatures($preparer, $gap = 30)
    {
        $L = self::L; $W = self::W;
        $sig_y = min($this->cursor + $gap, 270);       // close under the table, never into the footer
        $sig_w = 70;
        foreach ([[$L + 5, $preparer, 'Prepared by (Registrar)'], [$L + $W - 5 - $sig_w, '', 'Received / Verified by']] as [$sx, $who, $caption]) {
            $this->SetFont('Tahoma', 'B', 10);
            $this->SetTextColor(15, 23, 42);
            $this->SetXY($sx, $sig_y - 5.5);
            $this->Cell($sig_w, 5, $this->t(strtoupper($who)), 0, 0, 'C');
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.3);
            $this->Line($sx, $sig_y, $sx + $sig_w, $sig_y);
            $this->SetFont('Tahoma', '', 8);
            $this->SetTextColor(70, 70, 70);
            $this->SetXY($sx, $sig_y + 1);
            $this->Cell($sig_w, 4, $this->t($caption), 0, 0, 'C');
        }
    }

    /** The small print at the foot of the page. */
    public function footnote($text)
    {
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(self::L, 283, self::L + self::W, 283);
        $this->SetFont('Tahoma', '', 7.5);
        $this->SetTextColor(120, 120, 120);
        $this->SetXY(self::L, 284);
        $this->Cell(self::W, 5, $this->t($text), 0, 0, 'C');
    }
}

/** "First Middle Last" for a signature line or a parent's name. */
function sheetPersonName($last, $first, $middle)
{
    return trim(implode(' ', array_filter([trim((string)$first), trim((string)$middle), trim((string)$last)])));
}

/** The signed-in user's name, for "Prepared by". */
function sheetPreparerName($mysqli, $user_id)
{
    $stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
    if (!$stmt) return '';
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return sheetPersonName($row['last_name'] ?? '', $row['first_name'] ?? '', $row['middle_name'] ?? '');
}
