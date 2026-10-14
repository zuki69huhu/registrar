<?php
/**
 * The filed request as a downloadable PDF — the same sheet request_form_render.php
 * draws on screen, rebuilt with FPDF so the registrar gets a real file to keep,
 * attach or send rather than a browser print-out.
 *
 * Like the HTML version, every ruled line of the blank form is drawn whether or
 * not it was answered, and the answers are written on those lines in ink blue,
 * so the pre-printed form and what was filled into it stay distinguishable.
 *
 * The filled sheet is the registrar's copy: only a registrar of the student's
 * own institution may download it. A student reads their form in the viewer and
 * downloads the claim slip instead (download_claim_slip.php).
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/request_config.php';
require_once 'includes/name_helper.php';
require_once 'vendor/autoload.php';

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

function pdfDeny($message, $code = 403) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!$user_id || !in_array($role, ['registrar', 'student'], true)) {
    pdfDeny('Please sign in to download this form.', 401);
}

if ($role === 'student') {
    pdfDeny('Your filed form is view-only. You can download your claim slip once the registrar approves this request.');
}

$request_id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$request_id) pdfDeny('Invalid request.', 400);

$stmt = $mysqli->prepare("SELECT r.*, u.first_name, u.middle_name, u.last_name, u.student_id, u.course,
                                 u.institution AS student_institution,
                                 reg.first_name AS reviewer_first, reg.last_name AS reviewer_last,
                                 rel.first_name AS releaser_first, rel.last_name AS releaser_last
                          FROM student_requests r
                          JOIN users u ON r.user_id = u.id
                          LEFT JOIN users reg ON r.reviewed_by = reg.id
                          LEFT JOIN users rel ON r.released_by = rel.id
                          WHERE r.id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) pdfDeny('That request no longer exists.', 404);

// Registrars are scoped to their institution, like every other registrar query.
$reg_stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
$reg_stmt->bind_param("i", $user_id);
$reg_stmt->execute();
$registrar_institution = $reg_stmt->get_result()->fetch_assoc()['institution'] ?? '';
$reg_stmt->close();
if (!empty($registrar_institution) && !empty($request['student_institution'])
    && $request['student_institution'] !== $registrar_institution) {
    pdfDeny('This request belongs to another institution.');
}

$request['institution'] = $request['institution'] ?: $request['student_institution'];
$form = getRequestForm($request['institution'], $request['form_type'] ?? 'graduation');
if (!$form) pdfDeny('There is no printable form for this request.', 404);

$data = decodeRequestForm($request['form_data'] ?? '');

/**
 * The sheet, in FPDF terms. The letterhead is the page header so both pages
 * carry it, and row() solves FPDF's usual problem of keeping a wrapping cell
 * and its neighbours inside one matching set of borders — while also drawing
 * the rule the answer sits on.
 */
class RequestFormPdf extends FPDF
{
    public $head = [];
    public $formName = 'APPLICATION FOR GRADUATION';
    public $underlineTitle = false;
    public $titleBelow = false;     // the title on its own line under the box
    public $watermark = '';
    public $watermarkColor = [120, 120, 120];
    private $logo = null;

    const W = 190;                  // content width at 10mm margins
    const PEN = [18, 49, 122];      // ink blue, for anything that was filled in

    public function __construct($logo = null)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->logo = $logo;
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 12);
    }

    /** FPDF core fonts are cp1252; the portal's text is UTF-8. */
    public function t($text)
    {
        $text = (string)$text;
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
        return $out === false ? $text : $out;
    }

    public function ink()  { $this->SetTextColor(0, 0, 0); }
    public function pen()  { $this->SetTextColor(self::PEN[0], self::PEN[1], self::PEN[2]); }

    /** "#c00000" -> [192, 0, 0]; falls back to black on anything unexpected. */
    public static function rgb($hex, array $fallback = [0, 0, 0])
    {
        $hex = ltrim((string)$hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return $fallback;
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public function Header()
    {
        $h = $this->head;

        // Measured off the printed original and scaled to the 190mm content width
        // (its rule is 894px wide, so 1px there = 0.2125mm here): the seal is
        // 10.5% of the rule's width, 3.2% in and 1.2% below it; the lines are
        // ~12pt, centred on the WHOLE width, 5.2mm apart; rule-to-rule is 27.4mm.
        $ruleTop = 11;
        // A second e-mail address (MCNP's registrar@) is a fifth line, one pitch lower.
        $email2 = trim((string)($h['email2'] ?? ''));
        $ruleBottom = $ruleTop + 27.4 + ($email2 !== '' ? 5.2 : 0);

        $this->SetDrawColor(85, 133, 191);
        $this->SetLineWidth(0.5);
        $this->Line(10, $ruleTop, 200, $ruleTop);

        if ($this->logo) {
            $this->Image($this->logo, 16.2, $ruleTop + 2.3, 20, 20, 'PNG');
        }

        [$nr, $ng, $nb] = self::rgb($h['color'] ?? '', [192, 0, 0]);
        $this->SetTextColor($nr, $ng, $nb);
        $this->SetFont('Arial', 'B', 12.3);
        $this->SetXY(10, $ruleTop + 4.2);
        $this->Cell(self::W, 5.2, $this->t($h['name'] ?? ''), 0, 1, 'C');

        $this->SetTextColor(0, 31, 95);   // navy, as on the original
        $this->SetFont('Arial', '', 11.8);
        $this->SetX(10);
        $this->Cell(self::W, 5.2, $this->t($h['address'] ?? ''), 0, 1, 'C');
        $this->SetX(10);
        $this->Cell(self::W, 5.2, $this->t($h['contact'] ?? ''), 0, 1, 'C');
        $emailEnd = $this->webLine($h, $this->GetY());
        if ($email2 !== '') {
            // Right-aligned under the first address, as on the original.
            $this->SetFont('Arial', 'U', 11.8);
            $this->SetTextColor(0, 0, 0);
            $w = $this->GetStringWidth($this->t($email2));
            $this->SetXY($emailEnd - $w, $this->GetY());
            $this->Cell($w, 5.2, $this->t($email2), 0, 1, 'L');
        }
        $this->ink();

        $this->Line(10, $ruleBottom, 200, $ruleBottom);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0, 0, 0);
        $y = $ruleBottom;

        // Titles on the left, the QMS document-control box on the right. A form
        // without a document number has no box, so the titles centre on the sheet.
        // The original sets the box ~2.4mm under the rule.
        $top = $y + 2.5;
        $hasBox = !empty($h['doc_no']);
        $boxW = 72;
        $boxX = 10 + self::W - $boxW;
        $titleW = $hasBox ? self::W - $boxW - 4 : self::W;

        $titleBelow = $this->titleBelow && $hasBox;
        if ($titleBelow) {
            // MCNP's Form 007: the office line stands beside the box, level with
            // its middle, and the title is centred on the whole sheet below it.
            $this->SetXY(10, $top + 3.7);
            $this->SetFont('Arial', 'B', 10.5);
            $this->Cell($titleW, 5, $this->t($h['office'] ?? ''), 0, 1, 'R');
        } else {
            $this->SetXY(10, $top + 1);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($titleW, 5, $this->t($h['office'] ?? ''), 0, 2, 'C');
            $this->Ln(4);
            $this->SetX(10);
            $this->SetFont('Arial', $this->underlineTitle ? 'BU' : 'B', 10);
            $this->Cell($titleW, 5, $this->t($this->formName), 0, 1, 'C');
        }
        $this->SetFont('Arial', 'B', 10);

        if ($hasBox) {
            $this->SetXY($boxX, $top);
            $this->SetFont('Arial', '', 6.5);
            $this->MultiCell($boxW, 3.1, $this->t("Document No.\n" . $h['doc_no']), 1, 'L');
            $this->SetX($boxX);
            $this->MultiCell($boxW, 3.1, $this->t('Effective Date:  ' . ($h['effective'] ?? '') . "\nRevision No. " . ($h['revision'] ?? '')), 1, 'L');
        }

        if ($titleBelow) {
            $this->SetXY(10, $top + 12.4 + 3);
            $this->SetFont('Arial', $this->underlineTitle ? 'BU' : 'B', 11);
            $this->Cell(self::W, 5, $this->t($this->formName), 0, 1, 'C');
            $this->SetFont('Arial', 'B', 10);
        }

        // ~17mm from the rule to the first line of the form, as on the original.
        $this->SetY(max($this->GetY(), $top + 14) + 1.5);
        $this->drawWatermark();
    }

    /**
     * "Website: www…   E-Mail Address: admin…", centred on the whole width.
     * Returns where the e-mail address ends, for a second one to align under.
     */
    private function webLine(array $h, $y)
    {
        $size = 11.8;
        $gap  = 12.1;   // the original's 57px between the two halves
        $parts = [
            ['Website: ',                 '',  [0, 31, 95]],
            [$h['website'] ?? '',         'U', [0, 0, 0]],
            [null, null, null],           // the gap
            ['E-Mail Address: ',          '',  [0, 31, 95]],
            [$h['email'] ?? '',           'U', [0, 0, 0]],
        ];

        $total = 0;
        foreach ($parts as [$text, $style]) {
            if ($text === null) { $total += $gap; continue; }
            $this->SetFont('Arial', $style, $size);
            $total += $this->GetStringWidth($this->t($text));
        }

        $x = 10 + (self::W - $total) / 2;
        foreach ($parts as [$text, $style, $rgb]) {
            if ($text === null) { $x += $gap; continue; }
            $this->SetFont('Arial', $style, $size);
            $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
            $w = $this->GetStringWidth($this->t($text));
            $this->SetXY($x, $y);
            $this->Cell($w, 5.2, $this->t($text), 0, 0, 'L');
            $x += $w;
        }
        $this->SetFont('Arial', '', $size);
        $this->SetXY(10, $y + 5.2);
        return $x;
    }

    /** A closed request says so across the sheet, so a stale print-out is obvious. */
    private function drawWatermark()
    {
        if ($this->watermark === '') return;
        $this->SetFont('Arial', 'B', 50);
        [$r, $g, $b] = $this->watermarkColor;
        // FPDF has no alpha, so a pale tint of the status colour stands in for it.
        $this->SetTextColor(
            (int)round(255 - (255 - $r) * 0.16),
            (int)round(255 - (255 - $g) * 0.16),
            (int)round(255 - (255 - $b) * 0.16)
        );
        $saveY = $this->GetY();
        $this->SetXY(10, 130);
        $this->Cell(self::W, 20, $this->t($this->watermark), 0, 0, 'C');
        $this->ink();
        $this->SetY($saveY);
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 5, $this->t('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
        $this->ink();
    }

    /**
     * Writes a paragraph whose {b}/{i}/{u} markers switch the font mid-sentence,
     * so the letter keeps the emphasis the printed original has. Write() flows
     * across lines, so the segments join into one justified-looking paragraph.
     */
    public function writeRich($h, $text, $size = 9.5)
    {
        $parts = preg_split('/(\{\/?[biu]\})/', (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $on = ['b' => false, 'i' => false, 'u' => false];

        foreach ($parts as $part) {
            if (preg_match('/^\{(\/?)([biu])\}$/', $part, $m)) {
                $on[$m[2]] = ($m[1] === '');
                continue;
            }
            if ($part === '') continue;
            $style = ($on['b'] ? 'B' : '') . ($on['i'] ? 'I' : '') . ($on['u'] ? 'U' : '');
            $this->SetFont('Arial', $style, $size);
            $this->Write($h, $this->t($part));
        }
        $this->SetFont('Arial', '', $size);
    }

    /**
     * The size, no larger than $size and no smaller than $min, at which $text
     * (already cp1252) fits on one line $w wide — the way a hand writes smaller
     * in a short blank instead of running past it.
     */
    public function fitSize($text, $style, $size, $w, $min = 5.5)
    {
        $this->SetFont('Arial', $style, $size);
        while ($size > $min && $this->GetStringWidth($text) > $w) {
            $size = max($min, round($size - 0.25, 2));
            $this->SetFont('Arial', $style, $size);
        }
        return $size;
    }

    /** True when a block of this height still fits below the current position. */
    public function fits($height)
    {
        return $this->GetY() + $height <= $this->PageBreakTrigger;
    }

    /** Lines a string needs inside a cell of width $w — the standard FPDF recipe. */
    public function NbLines($w, $txt)
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += isset($cw[$c]) ? $cw[$c] : 0;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; }
                else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    /**
     * One table row whose cells may wrap independently.
     *
     * Each cell: ['w'=>mm, 'text'=>string, 'style'=>'B'|'I'|'', 'size'=>pt,
     *             'align'=>'L'|'C'|'R', 'color'=>[r,g,b], 'fill'=>[r,g,b],
     *             'indent'=>mm, 'border'=>bool,
     *             'label'=>pre-printed caption — the value is then written in ink
     *                      blue on a rule, exactly as on the paper form]
     */
    public function row(array $cells, $minHeight = 5, $lineHeight = 3.6)
    {
        // Per cell: the printed label's width, the size the answer is written at,
        // and how many lines it takes. A labelled answer is written smaller to
        // fit its one printed line rather than spilling onto more lines — which
        // is what used to push a long school name onto a third page. Only cells
        // marked 'wrap' (the free-text notes) may take several lines.
        $lines = 1;
        $cellLines = [];
        $labelWs = [];
        $sizes = [];
        foreach ($cells as $i => $c) {
            $size  = $c['size'] ?? 8;
            $style = $c['style'] ?? '';
            $text  = $this->t($c['text'] ?? '');

            $labelW = 0;
            if (($c['label'] ?? '') !== '') {
                $this->SetFont('Arial', 'B', $size);   // labels are always bold
                $labelW = $this->GetStringWidth($this->t($c['label'])) + 2;
            }
            // Must match the MultiCell widths used when drawing below.
            $avail = $labelW ? $c['w'] - ($c['indent'] ?? 0) - $labelW - 1.5
                             : $c['w'] - ($c['indent'] ?? 0) - 1;

            if ($labelW && empty($c['wrap'])) {
                $maxLines = $c['lines'] ?? 1;   // the printed lines this blank has
                $this->SetFont('Arial', $style, $size);
                while ($size > 5.5 && $this->NbLines(max(6, $avail), $text) > $maxLines) {
                    $size = max(5.5, round($size - 0.25, 2));
                    $this->SetFont('Arial', $style, $size);
                }
            }
            $this->SetFont('Arial', $style, $size);
            $n = $this->NbLines(max(6, $avail), $text);

            $labelWs[$i] = $labelW;
            $sizes[$i] = $size;
            $cellLines[$i] = $n;
            $lines = max($lines, $n);
        }
        $height = max($minHeight, $lines * $lineHeight + 1.4);

        // A row that would straddle the page break starts on the next page instead.
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }

        $x = 10;
        $y = $this->GetY();

        foreach ($cells as $i => $c) {
            $w = $c['w'];

            if (!empty($c['fill'])) {
                $this->SetFillColor($c['fill'][0], $c['fill'][1], $c['fill'][2]);
                $this->Rect($x, $y, $w, $height, 'F');
            }
            if ($c['border'] ?? true) {
                $this->Rect($x, $y, $w, $height);
            }

            $indent = $c['indent'] ?? 0;
            $this->SetXY($x + $indent, $y + 0.7);

            if (($c['label'] ?? '') !== '') {
                // "LABEL:" stays pre-printed black; the answer is written on a rule.
                $this->SetFont('Arial', 'B', $c['size'] ?? 8);
                $this->ink();
                $labelW = $labelWs[$i];
                $this->Cell($labelW, $lineHeight, $this->t($c['label']), 0, 0, 'L');

                $ruleLeft  = $x + $indent + $labelW;
                $ruleRight = $x + $w - 1.5;

                $this->SetFont('Arial', $c['style'] ?? '', $sizes[$i]);
                if (!empty($c['color'])) {
                    $this->SetTextColor($c['color'][0], $c['color'][1], $c['color'][2]);
                } else {
                    $this->pen();
                }
                $this->MultiCell($ruleRight - $ruleLeft, $lineHeight, $this->t($c['text'] ?? ''), 0, $c['align'] ?? 'L');
                $this->ink();

                // The rule under every line the answer occupies, so a blank field
                // still shows the line the printed form has.
                $this->SetDrawColor(0, 0, 0);
                for ($k = 1; $k <= $cellLines[$i]; $k++) {
                    $ly = $y + 0.7 + $lineHeight * $k - 0.6;
                    if ($ly > $y + $height - 0.4) break;
                    $this->Line($ruleLeft, $ly, $ruleRight, $ly);
                }
            } else {
                $this->SetFont('Arial', $c['style'] ?? '', $c['size'] ?? 8);
                if (!empty($c['color'])) $this->SetTextColor($c['color'][0], $c['color'][1], $c['color'][2]);
                $this->MultiCell($w - $indent - 1, $lineHeight, $this->t($c['text'] ?? ''), 0, $c['align'] ?? 'L');
                $this->ink();
            }

            $x += $w;
        }

        $this->SetXY(10, $y + $height);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Build the document
// ─────────────────────────────────────────────────────────────────────────────

$meta       = requestStatusMeta($request['status']);
$ref        = '#' . str_pad((string)$request['id'], 5, '0', STR_PAD_LEFT);
$student    = formatPersonNameRow($request);
$ticked     = $data['requirements'] ?? [];
$declared   = $data['declarations'] ?? [];
$transferee = !empty($data['is_transferee']);

// The institution's own letterhead logo, falling back to the shipped one.
$logo_name = basename($form['letterhead']['logo'] ?? 'assets/img/logo.jpg');
$logo = is_file(__DIR__ . '/assets/img/' . $logo_name) ? __DIR__ . '/assets/img/' . $logo_name
      : (is_file(__DIR__ . '/assets/img/logo.jpg') ? __DIR__ . '/assets/img/logo.jpg' : null);
$pdf = new RequestFormPdf($logo);
$pdf->head = $form['letterhead'] ?? [];
// The heading printed on the sheet, which may differ from how the portal
// labels the request (two request types share Form 007's heading).
$pdf->formName = $form['sheet_title'] ?? strtoupper($form['title']);
// Set before AddPage(), which is what draws the header.
$pdf->underlineTitle = !empty($form['underline_title']);
$pdf->titleBelow = !empty($form['title_below']);

// Only a closed request gets stamped; pending and approved sheets stay clean.
$stamp = ['rejected' => [166, 27, 27], 'claimed' => [20, 80, 140], 'cancelled' => [75, 85, 99]];
if (isset($stamp[$request['status']])) {
    $pdf->watermark = strtoupper($meta['label']);
    $pdf->watermarkColor = $stamp[$request['status']];
}

$pdf->AliasNbPages();
$pdf->AddPage();

$W = RequestFormPdf::W;

// The letters are one page each, nothing like the graduation checklist below.
if (in_array($request['form_type'] ?? 'graduation', ['form137', 'otr', 'nstp'], true)) {
    buildLetterSheet($pdf, $request, $data, $form, $meta, $ref, $student);
    $pdf->Output('D', requestPdfFileName($form, $student, $ref));
    exit;
}

// ── Note, course and the photo box ──────────────────────────────────────────
// On the original the note and the course line stand side by side with the
// photo box, rather than the course line running underneath it.
$photoW = 34;
$photoH = 28;
$leftW  = $W - $photoW - 6;
$noteTop = $pdf->GetY();

$pdf->SetFont('Arial', 'BI', 8.5);
$pdf->MultiCell($leftW, 4, $pdf->t("Note: Please write in CAPITAL LETTERS and use a ballpoint pen.\n(Important: Print Complete Name as it should appear in your graduation records.)"), 0, 'L');

$pdf->Rect(10 + $W - $photoW, $noteTop - 1, $photoW, $photoH);
$pdf->SetXY(10 + $W - $photoW, $noteTop + ($photoH - 12) / 2 - 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell($photoW, 4, $pdf->t("Latest\n2X2\nphoto"), 0, 'C');

// The course, written on the rule the blank form prints, centred in the space
// left of the photo box.
$courseY = $noteTop + 14;
$ruleW = $leftW * 0.78;
$ruleX = 10 + ($leftW - $ruleW) / 2;
$pdf->SetXY(10, $courseY);
$course = $pdf->t($data['course'] ?? '');
$pdf->SetFont('Arial', 'B', $pdf->fitSize($course, 'B', 9.5, $ruleW - 2));
$pdf->pen();
$pdf->Cell($leftW, 5, $course, 0, 1, 'C');
$pdf->ink();
$pdf->Line($ruleX, $courseY + 5, $ruleX + $ruleW, $courseY + 5);
$pdf->SetXY(10, $courseY + 5.5);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($leftW, 4, $pdf->t('(COURSE)'), 0, 1, 'C');

$pdf->SetY($noteTop + $photoH + 1);

// ── Personal information ────────────────────────────────────────────────────
$third = $W / 3;
// The printed boxes are not equal thirds; these follow the blank form.
$pdf->row([
    ['w' => $W * 0.36, 'label' => 'LAST NAME:',   'text' => $data['last_name'] ?? '',   'style' => 'B'],
    ['w' => $W * 0.30, 'label' => 'FIRST NAME:',  'text' => $data['first_name'] ?? '',  'style' => 'B'],
    ['w' => $W * 0.34, 'label' => 'MIDDLE NAME:', 'text' => $data['middle_name'] ?? '', 'style' => 'B'],
], 7);
$pdf->row([
    ['w' => $W * 0.48, 'label' => 'DATE OF BIRTH:', 'text' => !empty($data['date_of_birth']) ? date('F j, Y', strtotime($data['date_of_birth'])) : '', 'style' => 'B'],
    ['w' => $W * 0.22, 'label' => 'SEX:',           'text' => $data['sex'] ?? '',          'style' => 'B'],
    ['w' => $W * 0.30, 'label' => 'CIVIL STATUS:',  'text' => $data['civil_status'] ?? '', 'style' => 'B'],
], 6);
$pdf->row([
    ['w' => $W, 'label' => 'PERMANENT ADDRESS:', 'text' => $data['permanent_address'] ?? '', 'style' => 'B'],
], 6);

// ── Education ───────────────────────────────────────────────────────────────
$pdf->row([['w' => $W, 'text' => 'EDUCATION:', 'style' => 'B']], 4.6);
$schoolW = $W * 0.64;
$yearW   = $W - $schoolW;
$education = [
    ['ELEMENTARY:',         $data['elementary'] ?? '',  'YEAR GRADUATED:', $data['elementary_year'] ?? ''],
    ['JUNIOR HIGH SCHOOL:', $data['junior_high'] ?? '', 'YEAR GRADUATED:', $data['junior_high_year'] ?? ''],
    ['SENIOR HIGH SCHOOL:', $data['senior_high'] ?? '', 'YEAR GRADUATED:', $data['senior_high_year'] ?? ''],
    ['COLLEGE (for transferees & graduates of any course):', $data['college'] ?? '', 'YEAR ENDED:', $data['college_year'] ?? ''],
];
foreach ($education as $n => [$label, $value, $yearLabel, $yearValue]) {
    // COLLEGE is double height on the original: its label is so long that the
    // answer needs a second line.
    $isCollege = ($n === 3);
    $pdf->row([
        ['w' => $schoolW, 'label' => $label,     'text' => $value,     'style' => 'B', 'lines' => $isCollege ? 2 : 1],
        ['w' => $yearW,   'label' => $yearLabel, 'text' => $yearValue, 'style' => 'B'],
    ], $isCollege ? 8.6 : 6);
}

// ── Deadline, with the blank form's rule drawn under it ─────────────────────
$deadline = !empty($request['claim_at']) ? date('F j, Y', strtotime($request['claim_at'])) : '';
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 8.5);
$pre  = 'All document requirements mentioned below must be submitted not later than ';
$post = " at the Registrar's Office.";
$lineY = $pdf->GetY();
$pdf->Cell($pdf->GetStringWidth($pdf->t($pre)), 4.5, $pdf->t($pre), 0, 0, 'L');
$ruleX = $pdf->GetX();
$ruleW = 42;
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->pen();
$pdf->Cell($ruleW, 4.5, $pdf->t($deadline), 0, 0, 'C');
$pdf->ink();
$pdf->Line($ruleX, $lineY + 4.2, $ruleX + $ruleW, $lineY + 4.2);
$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 4.5, $pdf->t($post), 0, 1, 'L');
$pdf->Ln(1.5);

// ── Documents ───────────────────────────────────────────────────────────────
$docW = $W * 0.62;
$remW = $W * 0.21;
$sigW = $W - $docW - $remW;
$grey = [242, 242, 242];

$pdf->row([
    ['w' => $docW, 'text' => 'DOCUMENTS', 'style' => 'B', 'fill' => $grey],
    ['w' => $remW, 'text' => 'REMARKS',   'style' => 'B', 'align' => 'C', 'fill' => $grey],
    ['w' => $sigW, 'text' => 'SIGNATURE', 'style' => 'B', 'align' => 'C', 'fill' => $grey],
], 5);

/** The REMARKS column: what the student said about this document. */
$remark = function ($done, $optional = false) {
    if ($done) return ['text' => 'Submitted', 'style' => 'B', 'color' => RequestFormPdf::PEN];
    return $optional
        ? ['text' => 'N/A', 'style' => 'I', 'color' => [107, 114, 128]]
        : ['text' => 'Not yet', 'style' => '', 'color' => [166, 27, 27]];
};
$declaredMark = function ($done) {
    return $done
        ? ['text' => 'Yes', 'style' => 'B', 'color' => RequestFormPdf::PEN]
        : ['text' => 'No', 'style' => '', 'color' => [166, 27, 27]];
};

/** One checklist line: label on the left, remark in the middle, blank signature. */
$docRow = function ($label, ?array $mark = null, $indent = 0) use ($pdf, $docW, $remW, $sigW) {
    $pdf->row([
        ['w' => $docW, 'text' => $label, 'indent' => $indent],
        ['w' => $remW, 'text' => $mark['text'] ?? '', 'style' => $mark['style'] ?? '', 'color' => $mark['color'] ?? null, 'align' => 'C', 'size' => 7.5],
        ['w' => $sigW, 'text' => ''],
    ], 4.2, 3.0);
};

$docRow('1.   Form 137/TOR from last school attended',                  $remark(!empty($ticked['form137'])));
$docRow('2.   PSA Birth Certificate (Photocopy)',                       $remark(!empty($ticked['psa_birth'])));
$docRow('3.   One 2x2 picture (white background w/ name tag)',          $remark(!empty($ticked['photo_2x2'])));
$docRow('4.   PSA Approved Correction of Name/Birthdate, if necessary', $remark(!empty($ticked['psa_correction']), true));

$docRow('5.   For Transferees:', null);
$na = ['text' => 'N/A', 'style' => 'I', 'color' => [107, 114, 128]];
$docRow("-  Certificate of Good Moral Character\n   from school last attended", $transferee ? $remark(!empty($ticked['good_moral'])) : $na, 6);
$docRow("-  Honorable Dismissal/Transfer Credential\n   from school last attended", $transferee ? $remark(!empty($ticked['honorable'])) : $na, 6);
$docRow('-  Police Clearance/NBI Clearance (latest)', $transferee ? $remark(!empty($ticked['police_clearance'])) : $na, 6);

$docRow('6.   Completion Forms',                              $remark(!empty($ticked['completion_forms'])));
$docRow('7.   NSTP Grades/Diploma/Certificate of Completion', $remark(!empty($ticked['nstp'])));

$docRow('8.   In Addition:', null);
$docRow("-  I must not have failing grade/s and/or incomplete grade/s in any\n   academic or non-academic subject/s.", $declaredMark(!empty($declared['no_failing_grades'])), 6);
$docRow("-  I must obtain clearance from all department of the Institution\n   concerned.", $declaredMark(!empty($declared['department_clearance'])), 6);
$docRow('-  I must have paid all my financial obligation to the school.', $declaredMark(!empty($declared['financial_obligations'])), 6);

$docRow('9.   Department requirement:', null);
$docRow("-  Must have submitted all practicum requirements specified by the\n   practicum coordinator", $declaredMark(!empty($declared['practicum'])), 6);
$docRow("-  Must have been cleared from obligations in department affiliated\n   organization/s", $declaredMark(!empty($declared['organization_clearance'])), 6);

// The four pairs of ruled lines the printed form leaves for the department.
$rowsOfRules = 4;
$ruleStep = 4.5;
$blockH = $rowsOfRules * $ruleStep + 1.5;
if (!$pdf->fits($blockH)) $pdf->AddPage();
$blockY = $pdf->GetY();
$pdf->Rect(10, $blockY, $docW, $blockH);
$pdf->Rect(10 + $docW, $blockY, $remW, $blockH);
$pdf->Rect(10 + $docW + $remW, $blockY, $sigW, $blockH);
$gap = 8;
$lineW = ($docW - 16 - 6 - $gap) / 2;
for ($r = 0; $r < $rowsOfRules; $r++) {
    $ly = $blockY + $ruleStep * ($r + 1);
    $pdf->Line(16, $ly, 16 + $lineW, $ly);
    $pdf->Line(16 + $lineW + $gap, $ly, 16 + 2 * $lineW + $gap, $ly);
}
$pdf->SetXY(10, $blockY + $blockH);

$docRow('-  Final grade/s 10 days before graduation', null, 6);

$docRow('10.  OTHERS:', null);
$docRow('', null);
$docRow('', null);
$docRow('', null);

// ── PAGE 2 ──────────────────────────────────────────────────────────────────
$pdf->AddPage();

$pdf->Ln(6);
$pdf->SetFont('Arial', 'B', 9);
$pdf->MultiCell($W, 4.6, $pdf->t($data['waiver_text'] ?? getGraduationWaiverText()), 0, 'J');

$signedName = trim((string)($data['signed_name'] ?? '')) ?: $student;
$signedAt   = !empty($data['signed_at']) ? date('F j, Y', strtotime($data['signed_at']))
            : (!empty($request['submitted_at']) ? date('F j, Y', strtotime($request['submitted_at'])) : '');

$pdf->Ln(22);
$sigW2 = 100;
$sigX  = 10 + $W - $sigW2;
$pdf->SetX($sigX);
$signedText = $pdf->t($signedName);
$pdf->SetFont('Arial', 'B', $pdf->fitSize($signedText, 'B', 9.5, $sigW2 - 2));
$pdf->pen();
$pdf->Cell($sigW2, 5, $signedText, 0, 1, 'C');
$pdf->ink();
$pdf->Line($sigX, $pdf->GetY(), $sigX + $sigW2, $pdf->GetY());
$pdf->SetX($sigX);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($sigW2, 4.5, $pdf->t('SIGNATURE OVER PRINTED NAME'), 0, 1, 'C');

// "Date: ____" keeps its rule too
$dateY = $pdf->GetY() + 2;
$pdf->SetXY($sigX, $dateY);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($pdf->GetStringWidth($pdf->t('Date:')) + 2, 4.5, $pdf->t('Date:'), 0, 0, 'L');
$dateRuleX = $pdf->GetX();
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->pen();
$pdf->Cell($sigX + $sigW2 - $dateRuleX, 4.5, $pdf->t($signedAt), 0, 1, 'C');
$pdf->ink();
$pdf->Line($dateRuleX, $dateY + 4.3, $sigX + $sigW2, $dateY + 4.3);

if (!empty($data['waiver_accepted'])) {
    $pdf->SetX($sigX);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($sigW2, 5, $pdf->t('Accepted electronically through the student portal - Reference ' . $ref), 0, 1, 'L');
    $pdf->ink();
}

// ── Signature blocks ────────────────────────────────────────────────────────
$pdf->Ln(14);
$slotX = 70;
$slotW = 80;

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(60, 5, $pdf->t('Recommending Approval:'), 0, 1, 'L');
foreach (($form['recommending'] ?? []) as $slot) {
    $pdf->Ln(9);
    $pdf->Line($slotX, $pdf->GetY(), $slotX + $slotW, $pdf->GetY());
    $pdf->SetX($slotX);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell($slotW, 4.5, $pdf->t($slot), 0, 1, 'C');
}

$pdf->Ln(10);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(60, 5, $pdf->t('Approved by:'), 0, 1, 'L');
foreach (($form['approvers'] ?? []) as $slot) {
    $pdf->Ln(9);
    $pdf->SetX($slotX);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($slotW, 4.5, $pdf->t($slot['name']), 0, 1, 'C');
    $pdf->Line($slotX, $pdf->GetY(), $slotX + $slotW, $pdf->GetY());
    $pdf->SetX($slotX);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell($slotW, 4.5, $pdf->t($slot['title']), 0, 1, 'C');
}

// ── The portal's own footer: decision and claim stub ────────────────────────
pdfRegistrarStub($pdf, $request, $meta, $ref, $student, $grey);

$fileName = requestPdfFileName($form, $student, $ref);

$pdf->Output('D', $fileName);
exit;

/**
 * The portal's own footer: who decided, when it may be claimed, and when it was
 * released. Both sheets end with it, so it lives here rather than being copied.
 */
function pdfRegistrarStub(RequestFormPdf $pdf, array $request, array $meta, $ref, $student, array $grey)
{
    $W = RequestFormPdf::W;
    $third = $W / 3;
    $pdf->Ln(8);
    $reviewer = trim(($request['reviewer_first'] ?? '') . ' ' . ($request['reviewer_last'] ?? ''));
    $releaser = trim(($request['releaser_first'] ?? '') . ' ' . ($request['releaser_last'] ?? ''));

    $pdf->row([
        ['w' => $W - 45, 'text' => "FOR THE REGISTRAR'S OFFICE", 'style' => 'B', 'size' => 8.5, 'fill' => $grey],
        ['w' => 45, 'text' => strtoupper($meta['label']), 'style' => 'B', 'size' => 8.5, 'align' => 'C', 'fill' => $grey],
    ], 6);

    $pdf->row([
        ['w' => $third, 'label' => 'REFERENCE NO.:', 'text' => $ref, 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'STUDENT NO.:',   'text' => formatStudentId($request['student_id'] ?? ''), 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'FILED:',         'text' => !empty($request['submitted_at']) ? date('F j, Y', strtotime($request['submitted_at'])) : '', 'style' => 'B', 'size' => 7.5],
    ], 6.5);
    $pdf->row([
        ['w' => $third, 'label' => 'REVIEWED BY:', 'text' => $reviewer, 'style' => 'B', 'size' => 7.5],
        ['w' => $third * 2, 'label' => 'REVIEWED ON:', 'text' => !empty($request['reviewed_at']) ? date('F j, Y g:i A', strtotime($request['reviewed_at'])) : '', 'style' => 'B', 'size' => 7.5],
    ], 6.5);
    $pdf->row([
        ['w' => $third, 'label' => 'CLAIM DATE:', 'text' => !empty($request['claim_at']) ? date('l, F j, Y', strtotime($request['claim_at'])) : '', 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'CLAIM TIME:', 'text' => !empty($request['claim_at']) ? date('g:i A', strtotime($request['claim_at'])) : '', 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'WHERE:',      'text' => $request['claim_location'] ?? '', 'style' => 'B', 'size' => 7.5],
    ], 6.5);
    $pdf->row([
        ['w' => $third, 'label' => 'RELEASED ON:', 'text' => !empty($request['claimed_at']) ? date('F j, Y g:i A', strtotime($request['claimed_at'])) : '', 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'RECEIVED BY:', 'text' => $request['claimed_by_name'] ?? '', 'style' => 'B', 'size' => 7.5],
        ['w' => $third, 'label' => 'RELEASED BY:', 'text' => $releaser, 'style' => 'B', 'size' => 7.5],
    ], 6.5);

    if (!empty($request['student_note'])) {
        $pdf->row([['w' => $W, 'label' => "STUDENT'S MESSAGE:", 'text' => $request['student_note'], 'size' => 7.5, 'wrap' => true]], 6.5);
    }
    if (!empty($request['registrar_note'])) {
        $pdf->row([['w' => $W, 'label' => "REGISTRAR'S NOTE:", 'text' => $request['registrar_note'], 'size' => 7.5, 'wrap' => true]], 6.5);
    }

    $pdf->SetFont('Arial', 'I', 6.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell($W, 3.4, $pdf->t(
        'Generated by the ' . ($request['institution'] ?? '') . ' document portal for ' . $student
        . ' on ' . date('F j, Y \a\t g:i A') . '. Reference ' . $ref . '.'
    ), 0, 'L');

    // ── Send ────────────────────────────────────────────────────────────────────
}

/** "Request_for_Form_137-A_Suyu__Daryl_R_00001.pdf" — one rule for every form. */
function requestPdfFileName(array $form, $student, $ref)
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$student);
    $title = preg_replace('/[^A-Za-z0-9]+/', '_', (string)($form['title'] ?? 'Request'));
    return trim($title, '_') . '_' . trim($name, '_') . '_' . ltrim($ref, '#') . '.pdf';
}

/**
 * The letters the registrar sends on a student's behalf: Request for Form 137-A
 * (Form 007) and Request for NSTP Serial Number. One page each, and one builder,
 * because they share a skeleton — letterhead, date, addressee, pre-printed body,
 * a one-row table, closing and signatory. The form's config supplies the table's
 * columns and says whether the sheet carries the tick boxes and Ctrl No. box
 * that only Form 137 has.
 */
function buildLetterSheet(RequestFormPdf $pdf, array $request, array $data, array $form, array $meta, $ref, $student)
{
    $W = RequestFormPdf::W;
    $grey = [242, 242, 242];

    // The letter is dated when the registrar signs it; until then, when it was filed.
    $letter_date = !empty($request['reviewed_at']) ? $request['reviewed_at'] : ($request['submitted_at'] ?? '');
    $letter_date = $letter_date ? date('F j, Y', strtotime($letter_date)) : '';

    // ── Date, right-aligned ──
    $pdf->Ln(12);
    $dateY = $pdf->GetY();
    $dateW = 60;
    $dateX = 10 + $W - $dateW;
    $pdf->SetXY($dateX, $dateY);
    $pdf->SetFont('Arial', '', 9.5);
    $labelW = $pdf->GetStringWidth($pdf->t('Date:')) + 2;
    $pdf->Cell($labelW, 5, $pdf->t('Date:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->pen();
    $pdf->Cell($dateW - $labelW, 5, $pdf->t($letter_date), 0, 1, 'C');
    $pdf->ink();
    $pdf->Line($dateX + $labelW, $dateY + 4.8, $dateX + $dateW, $dateY + 4.8);

    // ── Addressee: THE REGISTRAR plus the three ruled lines ──
    $pdf->SetXY(10, $dateY + 7);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Cell(60, 5, $pdf->t('THE REGISTRAR'), 0, 1, 'L');

    $addrW = $W * 0.74;
    $addrLines = [
        trim((string)($data['school_name'] ?? '')),
        trim((string)($data['school_address'] ?? '')),
        '',
    ];
    foreach ($addrLines as $text) {
        $y = $pdf->GetY() + 1;
        $pdf->SetXY(10, $y);
        $line = $pdf->t($text);
        $pdf->SetFont('Arial', 'B', $pdf->fitSize($line, 'B', 9.5, $addrW - 2));
        $pdf->pen();
        $pdf->Cell($addrW, 5, $line, 0, 1, 'L');
        $pdf->ink();
        $pdf->Line(10, $y + 5, 10 + $addrW, $y + 5);
        $pdf->Ln(1);
    }

    // ── Salutation and the pre-printed body ──
    $pdf->Ln(6);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell($W, 5, $pdf->t($form['salutation'] ?? 'Dear Sir/Madam:'), 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetX(10);
    $pdf->writeRich(5, $form['body'] ?? '', 9.5);
    $pdf->Ln(7);

    // ── The one row the letter carries ──
    $columns = $form['table'] ?? [];
    // The NSTP table prints the college's name and address in one cell.
    $school_full = trim(trim((string)($data['school_name'] ?? '')) . ', ' . trim((string)($data['school_address'] ?? '')), ' ,');
    $values = $data + ['school_full' => $school_full];

    $header = [];
    $row = [];
    foreach ($columns as $col) {
        $w = $W * ((float)$col['width'] / 100);
        $header[] = ['w' => $w, 'text' => $col['label'], 'style' => 'B', 'size' => 8.5, 'align' => 'C'];
        $row[]    = ['w' => $w, 'text' => $values[$col['field']] ?? '', 'style' => 'B', 'size' => 8.5,
                     'align' => 'C', 'color' => RequestFormPdf::PEN];
    }
    if ($header) {
        $pdf->row($header, 7, 3.6);
        $pdf->row($row, 9, 3.6);
    }

    // ── Closing and signatory ──
    $pdf->Ln(4);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell($W, 5, $pdf->t($form['closing'] ?? ''), 0, 1, 'C');

    $sig = $form['signatory'] ?? ['name' => '', 'title' => ''];
    $sigW = 90;
    $sigX = 10 + $W - $sigW - 8;

    $pdf->Ln(5);
    $pdf->SetX($sigX);
    $pdf->Cell($sigW, 5, $pdf->t('Very truly yours,'), 0, 1, 'C');
    $pdf->Ln(11);
    $pdf->SetX($sigX);
    $pdf->SetFont('Arial', 'BU', 9.5);
    $pdf->Cell($sigW, 5, $pdf->t($sig['name'] ?? ''), 0, 1, 'C');
    $pdf->SetX($sigX);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->Cell($sigW, 4.6, $pdf->t($sig['title'] ?? ''), 0, 1, 'C');

    // ── The four boxes, on the one letter that prints them ──
    if (!empty($form['options'])) {
        $ticks = [
            'first_request'     => !empty($data['first_request']),
            'second_request'    => !empty($data['second_request']),
            'urgent'            => !empty($data['urgent']),
            'entrust_to_bearer' => !empty($data['entrust_to_bearer']),
        ];
        $pdf->Ln(8);
        $boxW = 7;
        $boxH = 5;
        foreach (getForm007Options() as $opt) {
            $y = $pdf->GetY();
            $pdf->Rect(10, $y, $boxW, $boxH);
            if (!empty($ticks[$opt['key']])) {
                $pdf->SetXY(10, $y);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->pen();
                $pdf->Cell($boxW, $boxH, $pdf->t('X'), 0, 0, 'C');
                $pdf->ink();
            }
            $pdf->SetXY(10 + $boxW + 3, $y);
            $pdf->SetFont('Arial', '', 9.5);
            $pdf->Cell(100, $boxH, $pdf->t($opt['label']), 0, 1, 'L');
        }
    }

    // ── Ctrl No., the registrar's own box ──
    if (!empty($form['ctrl_no'])) {
        $pdf->Ln(5);
        $ctrlY = $pdf->GetY();
        $pdf->Rect(10, $ctrlY, 75, 8);
        $pdf->SetXY(12, $ctrlY);
        $pdf->SetFont('Arial', '', 9.5);
        $pdf->Cell(71, 8, $pdf->t('Ctrl No. :'), 0, 1, 'L');
        $pdf->SetXY(10, $ctrlY + 8);
    }

    pdfRegistrarStub($pdf, $request, $meta, $ref, $student, $grey);
}
