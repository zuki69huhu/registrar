<?php
/**
 * The claim slip as a small PDF — the one thing a student may download.
 *
 * The filled form itself stays view-only for them (request_form_view.php); the
 * paper they actually need at the counter is this slip: the reference number,
 * when and where to claim it, and a line for the releasing clerk to sign.
 *
 * A slip only exists once the registrar has set a schedule, so it is served for
 * 'approved' and 'claimed' requests only. Access follows download_request_form.php:
 * the owning student, or a registrar of that student's institution.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/request_config.php';
require_once 'includes/name_helper.php';
require_once 'vendor/autoload.php';

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

function slipDeny($message, $code = 403) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!$user_id || !in_array($role, ['registrar', 'student'], true)) {
    slipDeny('Please sign in to download this claim slip.', 401);
}

$request_id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$request_id) slipDeny('Invalid request.', 400);

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

if (!$request) slipDeny('That request no longer exists.', 404);

if ($role === 'student') {
    if ((int)$request['user_id'] !== $user_id) slipDeny('You can only download your own claim slip.');
} else {
    $reg_stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
    $reg_stmt->bind_param("i", $user_id);
    $reg_stmt->execute();
    $registrar_institution = $reg_stmt->get_result()->fetch_assoc()['institution'] ?? '';
    $reg_stmt->close();
    if (!empty($registrar_institution) && !empty($request['student_institution'])
        && $request['student_institution'] !== $registrar_institution) {
        slipDeny('This request belongs to another institution.');
    }
}

if (!in_array($request['status'], ['approved', 'claimed'], true) || empty($request['claim_at'])) {
    slipDeny('There is no claim slip yet. The registrar sets the claim schedule when the request is approved.', 409);
}

$request['institution'] = $request['institution'] ?: $request['student_institution'];
$form = getRequestForm($request['institution'], $request['form_type'] ?? 'graduation');

$meta     = requestStatusMeta($request['status']);
$ref      = '#' . str_pad((string)$request['id'], 5, '0', STR_PAD_LEFT);
$student  = formatPersonNameRow($request);
$head     = $form['letterhead'] ?? [];
$claim_ts = strtotime($request['claim_at']);

/** A5 slip: a letterhead strip, the schedule, and a line to sign on release. */
class ClaimSlipPdf extends FPDF
{
    const W = 128;                  // content width at 10mm margins on A5
    const PEN = [18, 49, 122];      // ink blue, as on the printed forms

    public function __construct() {
        parent::__construct('P', 'mm', 'A5');
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 10);
    }

    /** FPDF core fonts are cp1252; the portal's text is UTF-8. */
    public function t($text) {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', (string)$text);
        return $out === false ? (string)$text : $out;
    }

    public function ink() { $this->SetTextColor(0, 0, 0); }
    public function pen() { $this->SetTextColor(self::PEN[0], self::PEN[1], self::PEN[2]); }

    public static function rgb($hex, array $fallback = [0, 0, 0]) {
        $hex = ltrim((string)$hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return $fallback;
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** The size at which $text fits one line $w wide, as the forms do it. */
    public function fitSize($text, $style, $size, $w, $min = 5.5) {
        $this->SetFont('Arial', $style, $size);
        while ($size > $min && $this->GetStringWidth($text) > $w) {
            $size = max($min, round($size - 0.25, 2));
            $this->SetFont('Arial', $style, $size);
        }
        return $size;
    }

    /** "LABEL" above the answer, written on a rule — never cut off, never wrapped. */
    public function field($x, $y, $w, $label, $value) {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 6.2);
        $this->SetTextColor(110, 120, 135);
        $this->Cell($w, 3.2, $this->t(strtoupper($label)), 0, 2, 'L');

        $text = $this->t($value);
        $this->SetFont('Arial', 'B', $this->fitSize($text, 'B', 9, $w - 1));
        $this->pen();
        $this->Cell($w, 4.6, $text, 0, 1, 'L');
        $this->ink();
        $this->SetDrawColor(203, 213, 225);
        $this->Line($x, $y + 8.1, $x + $w, $y + 8.1);
        $this->SetDrawColor(0, 0, 0);
    }
}

$logo_name = basename($head['logo'] ?? 'assets/img/logo.jpg');
$logo = is_file(__DIR__ . '/assets/img/' . $logo_name) ? __DIR__ . '/assets/img/' . $logo_name
      : (is_file(__DIR__ . '/assets/img/logo.jpg') ? __DIR__ . '/assets/img/logo.jpg' : null);

$pdf = new ClaimSlipPdf();
$pdf->AddPage();
$W = ClaimSlipPdf::W;

// ── Letterhead strip ────────────────────────────────────────────────────────
$top = $pdf->GetY();
if ($logo) $pdf->Image($logo, 10, $top, 14, 14, 'PNG');

[$nr, $ng, $nb] = ClaimSlipPdf::rgb($head['color'] ?? '', [192, 0, 0]);
$pdf->SetTextColor($nr, $ng, $nb);
$name = $pdf->t($head['name'] ?? ($request['institution'] ?? ''));
$pdf->SetXY(27, $top + 1);
$pdf->SetFont('Arial', 'B', $pdf->fitSize($name, 'B', 9.5, $W - 18));
$pdf->Cell($W - 17, 4.6, $name, 0, 2, 'L');

$pdf->SetTextColor(0, 31, 95);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($W - 17, 3.8, $pdf->t($head['address'] ?? ''), 0, 2, 'L');
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell($W - 17, 3.8, $pdf->t($head['office'] ?? "OFFICE OF THE REGISTRAR"), 0, 1, 'L');
$pdf->ink();

$pdf->SetY(max($pdf->GetY(), $top + 15) + 2);
$pdf->SetDrawColor(85, 133, 191);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 10 + $W, $pdf->GetY());
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);
$pdf->Ln(4);

// ── Title and status ────────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell($W - 30, 6, $pdf->t('CLAIM SLIP'), 0, 0, 'L');
[$sr, $sg, $sb] = ClaimSlipPdf::rgb($meta['color'] ?? '', [100, 116, 139]);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor((int)round(255 - (255 - $sr) * 0.16), (int)round(255 - (255 - $sg) * 0.16), (int)round(255 - (255 - $sb) * 0.16));
$pdf->SetTextColor($sr, $sg, $sb);
$pdf->Cell(30, 5.4, $pdf->t(strtoupper($meta['label'])), 0, 1, 'C', true);
$pdf->ink();

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(90, 100, 115);
$pdf->Cell($W, 4.4, $pdf->t($form['title'] ?? 'Registrar request'), 0, 1, 'L');
$pdf->ink();
$pdf->Ln(2);

// ── Who and what ────────────────────────────────────────────────────────────
$half = $W / 2;
$y = $pdf->GetY();
$pdf->field(10, $y, $half - 3, 'Reference no.', $ref);
$pdf->field(10 + $half, $y, $half - 3, 'Student no.', formatStudentId($request['student_id'] ?? ''));

$y += 11;
$pdf->field(10, $y, $W, 'Claimant', $student);

$y += 11;
$pdf->field(10, $y, $half - 3, 'Course', $request['course'] ?? '');
$pdf->field(10 + $half, $y, $half - 3, 'Filed', !empty($request['submitted_at']) ? date('F j, Y', strtotime($request['submitted_at'])) : '');
$pdf->SetY($y + 12);

// ── The schedule, boxed, because it is what the slip is for ─────────────────
$boxY = $pdf->GetY();
$boxH = 30;
$pdf->SetFillColor(240, 249, 255);
$pdf->Rect(10, $boxY, $W, $boxH, 'F');
$pdf->SetDrawColor(125, 170, 215);
$pdf->Rect(10, $boxY, $W, $boxH);
$pdf->SetDrawColor(0, 0, 0);

$pdf->field(14, $boxY + 3, $half - 8, 'Claim date', date('l, F j, Y', $claim_ts));
$pdf->field(10 + $half, $boxY + 3, $half - 8, 'Claim time', date('g:i A', $claim_ts));
$pdf->field(14, $boxY + 15, $W - 8, 'Where', $request['claim_location'] ?: REQUEST_DEFAULT_CLAIM_LOCATION);
$pdf->SetY($boxY + $boxH + 3);

if (!empty($request['registrar_note'])) {
    $pdf->SetFont('Arial', 'B', 6.2);
    $pdf->SetTextColor(110, 120, 135);
    $pdf->Cell($W, 3.2, $pdf->t("NOTE FROM THE REGISTRAR"), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->ink();
    $pdf->MultiCell($W, 3.6, $pdf->t($request['registrar_note']), 0, 'L');
    $pdf->Ln(1.5);
}

$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(90, 100, 115);
$pdf->MultiCell($W, 3.6, $pdf->t('Bring a valid ID and this slip. If you cannot make it, message the registrar\'s office so your schedule can be moved.'), 0, 'L');
$pdf->ink();

// ── Release, filled at the counter (or already, for a claimed request) ──────
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 6.2);
$pdf->SetTextColor(110, 120, 135);
$pdf->Cell($W, 3.2, $pdf->t("FOR THE REGISTRAR'S OFFICE"), 0, 1, 'L');
$pdf->ink();

$y = $pdf->GetY() + 1;
$releaser = trim(($request['releaser_first'] ?? '') . ' ' . ($request['releaser_last'] ?? ''));
$pdf->field(10, $y, $half - 3, 'Received by', $request['claimed_by_name'] ?? '');
$pdf->field(10 + $half, $y, $half - 3, 'Released by', $releaser);
$y += 11;
$pdf->field(10, $y, $W, 'Date released', !empty($request['claimed_at']) ? date('F j, Y g:i A', strtotime($request['claimed_at'])) : '');

$pdf->SetY($y + 12);
$pdf->SetFont('Arial', 'I', 6.2);
$pdf->SetTextColor(120, 120, 120);
$pdf->MultiCell($W, 3.2, $pdf->t(
    'Generated by the ' . ($request['institution'] ?? '') . ' document portal on '
    . date('F j, Y \a\t g:i A') . '. Reference ' . $ref . '.'
), 0, 'L');

$name_part = preg_replace('/[^A-Za-z0-9._-]+/', '_', $student);
$pdf->Output('D', 'Claim_Slip_' . trim($name_part, '_') . '_' . ltrim($ref, '#') . '.pdf');
exit;
