<?php
/**
 * Student page for filing a registrar form online.
 *
 * Which form appears depends on users.institution: ISAP students get the
 * Application for Graduation, MCNP has none yet (see request_config.php).
 * The form arrives already filled with whatever the portal knows about the
 * student; anything it cannot know is asked for.
 *
 * Once filed, the same page tracks the request: pending -> ready for claim
 * (with the date and time the registrar set) -> claimed.
 */

session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'profile_config.php';

ensureProfileColumn($mysqli);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, student_id, email, profile_picture,
                                 year_level, course, section, institution, contact_number, address, profile_data
                          FROM users WHERE id = ?");
if (!$stmt) {
    $_SESSION['flash'] = "System error. Please try again later.";
    header("Location: index.php?tab=login"); exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$first_name  = $user_data['first_name'] ?? '';
$middle_name = $user_data['middle_name'] ?? '';
$last_name   = $user_data['last_name'] ?? '';
$user_student_id = $user_data['student_id'] ?? '';
$user_email  = $user_data['email'] ?? '';
$user_profile_picture = $user_data['profile_picture'] ?? null;
$user_year_level = $user_data['year_level'] ?? '';
$user_course = $user_data['course'] ?? '';
$user_section = $user_data['section'] ?? '';
$user_institution = $user_data['institution'] ?? '';

$display_year_section = '';
if (!empty($user_year_level)) {
    $display_year_section = substr($user_year_level, 0, 1) . $user_section;
}

$full_name = trim(implode(' ', array_filter([$first_name, $middle_name, $last_name])));
if ($full_name === '') $full_name = $user_email;

// Sidebar notification badge
$unread_count = 0;
$unread_stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $user_id);
    $unread_stmt->execute();
    $unread_count = (int)($unread_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $unread_stmt->close();
}

// What this student may file, and everything they have filed so far
$available_forms = getRequestFormsForInstitution($user_institution);

$requests = [];
$req_stmt = $mysqli->prepare("SELECT r.*, reg.first_name AS reviewer_first, reg.last_name AS reviewer_last
                              FROM student_requests r
                              LEFT JOIN users reg ON r.reviewed_by = reg.id
                              WHERE r.user_id = ?
                              ORDER BY r.submitted_at DESC");
$req_stmt->bind_param("i", $user_id);
$req_stmt->execute();
$requests = $req_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$req_stmt->close();

// A student may have one open request per form, so both are tracked. The rows
// are newest first, so the first one seen for a form is also the latest, which
// is what a re-filed request prefills from.
$open_by_form = [];
$last_by_form = [];
foreach ($requests as $r) {
    $ft = $r['form_type'] ?: 'graduation';
    if (isRequestOpen($r['status']) && !isset($open_by_form[$ft])) $open_by_form[$ft] = $r;
    if (!isset($last_by_form[$ft])) $last_by_form[$ft] = decodeRequestForm($r['form_data']);
}
// What the student can still file right now.
$fileable = array_diff_key($available_forms, $open_by_form);

// Not a users column, but the Form 137 letter asks for the name in one piece.
$user_data['full_name'] = $full_name;

// The Student Profile (Settings > Edit Profile): birth date, sex, civil status
// and the schools attended fill the matching fields of every form.
$student_profile = decodeStudentProfile($user_data['profile_data'] ?? '');

/** Prefer what the portal knows about the student, then what they typed last time. */
function prefillValue($field, $user_data, $last_form_data) {
    if (!empty($field['autofill']) && !empty($user_data[$field['autofill']])) {
        $value = trim((string)$user_data[$field['autofill']]);
        if (in_array($field['name'], ['last_name', 'first_name', 'middle_name'], true)) {
            $value = mb_strtoupper($value);
        }
        return $value;
    }
    return (string)($last_form_data[$field['name']] ?? '');
}

// A request filed a moment ago gets a receipt modal instead of a flash line.
$just_submitted = null;
if (!empty($_SESSION['request_submitted']['id'])) {
    $submitted_id = (int)$_SESSION['request_submitted']['id'];
    foreach ($requests as $r) {
        if ((int)$r['id'] === $submitted_id) { $just_submitted = $r; break; }
    }
}
unset($_SESSION['request_submitted']);

// If the row somehow cannot be read back, fall back to a flash line rather than
// leaving the student with no confirmation at all.
if (isset($submitted_id) && !$just_submitted) {
    $_SESSION['flash'] = 'Your request was submitted and is now pending review by the registrar.';
    $_SESSION['flash_type'] = 'success';
}

$flash = $_SESSION['flash'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash'], $_SESSION['flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="theme_mode.js?v=3" data-key="student-theme" data-base="light" data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
    <link rel="stylesheet" href="theme_mode.css?v=9">
    <title>Requests - Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #1a365d; --bg-color: #f1f5f9; --sidebar-bg: #ffffff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: #0f172a; display: flex; min-height: 100vh; }

        /* ── SIDEBAR (same shell as the other student pages) ── */
        .sidebar { width: 280px; background: var(--sidebar-bg); border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; position: fixed; height: 100%; top: 0; bottom: 0; transition: 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 24px 20px 0 20px; }
        .logo-area { margin-bottom: 32px; display: flex; align-items: center; gap: 8px; }
        .logo-icon { background: transparent; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .logo-text { font-size: 20px; font-weight: 700; color: #1e293b; }
        .student-info { background: #f8fafc; border-radius: 16px; padding: 16px; margin-bottom: 24px; border: 1px solid #e2e8f0; }
        .student-name { font-weight: 700; font-size: 16px; color: #0f172a; }
        .student-id { font-size: 13px; color: #64748b; margin-top: 4px; }
        .nav-menu { padding: 0 20px 16px 20px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 12px; color: #475569; font-weight: 500; margin-bottom: 4px; text-decoration: none; transition: 0.2s; }
        .nav-item:hover { background: #f1f5f9; color: #1e293b; }
        .nav-item.active { background: var(--primary-color); color: white; }
        .nav-item i { width: 24px; font-size: 18px; text-align: center; }
        .sidebar-bottom { padding: 12px 20px; border-top: 1px solid #e2e8f0; margin-top: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; color: #dc2626; font-weight: 500; text-decoration: none; padding: 10px 14px; border-radius: 12px; transition: background 0.2s; }
        .logout-btn i { width: 24px; font-size: 18px; text-align: center; }
        .logout-btn:hover { background: #fef2f2; }
        .mobile-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1100; background: var(--primary-color); color: white; border: none; padding: 0; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3); width: 45px; height: 45px; align-items: center; justify-content: center; }
        .mobile-toggle i { font-size: 20px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        /* ── PAGE ── */
        .main-content { flex: 1; margin-left: 280px; padding: 32px 40px; min-width: 0; }
        .page-head { margin-bottom: 24px; }
        .page-head h1 { font-size: 26px; letter-spacing: -0.02em; }
        .page-head p { color: #64748b; margin-top: 6px; font-size: 14px; }

        .card { background: white; border-radius: 20px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card-title { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700; color: #0f172a; }
        .card-title i { color: var(--primary-color); }
        .card-sub { color: #64748b; font-size: 13px; margin-top: 6px; line-height: 1.55; }

        .flash { padding: 14px 16px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5; }
        .flash.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .flash.error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .flash.info    { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }

        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; white-space: nowrap; }

        /* ── ACTIVE REQUEST: tracker + claim slip ── */
        /* ── SUBMIT RECEIPT ── */
        .receipt { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .receipt-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.6); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); opacity: 0; transition: opacity 0.2s ease; }
        .receipt-dialog {
            position: relative; width: 100%; max-width: 440px; background: white;
            border-radius: 22px; padding: 34px 28px 26px; text-align: center;
            box-shadow: 0 24px 60px rgba(15,23,42,0.28);
            opacity: 0; transform: translateY(14px) scale(0.97);
            transition: opacity 0.22s ease, transform 0.22s ease;
            max-height: calc(100vh - 40px); overflow-y: auto;
        }
        .receipt.is-open .receipt-backdrop { opacity: 1; }
        .receipt.is-open .receipt-dialog { opacity: 1; transform: none; }
        .receipt-dialog:focus { outline: none; }
        body.receipt-open { overflow: hidden; }

        .receipt-ring {
            width: 72px; height: 72px; margin: 0 auto 18px; border-radius: 50%;
            background: #dcfce7; color: #16a34a;
            display: flex; align-items: center; justify-content: center; font-size: 30px;
            box-shadow: 0 0 0 8px rgba(22,163,74,0.09);
            animation: receiptPop 0.42s cubic-bezier(0.34, 1.56, 0.64, 1) 0.06s both;
        }
        @keyframes receiptPop { from { transform: scale(0.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .receipt-dialog h2 { font-size: 21px; letter-spacing: -0.02em; margin-bottom: 8px; }
        .receipt-sub { font-size: 14px; color: #64748b; line-height: 1.55; margin-bottom: 22px; }
        .receipt-sub strong { color: #0f172a; }

        .receipt-facts { display: grid; gap: 1px; background: #e2e8f0; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; text-align: left; }
        .receipt-facts > div { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 15px; background: #f8fafc; }
        .receipt-facts dt { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .receipt-facts dd { font-size: 13.5px; font-weight: 600; color: #0f172a; text-align: right; }
        .receipt-ref { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 15px !important; }

        .receipt-next { display: flex; gap: 9px; text-align: left; font-size: 12.5px; color: #475569; line-height: 1.55; margin: 18px 0 22px; }
        .receipt-next i { color: var(--primary-color); margin-top: 2px; flex-shrink: 0; }
        .receipt-actions { display: flex; gap: 10px; }
        .receipt-actions .btn { flex: 1; justify-content: center; }
        .receipt-close { position: absolute; top: 14px; right: 16px; width: 34px; height: 34px; border: none; background: transparent; color: #94a3b8; font-size: 26px; line-height: 1; border-radius: 10px; cursor: pointer; }
        .receipt-close:hover { background: #f1f5f9; color: #0f172a; }

        /* ── OPEN REQUESTS, FOLDED ── */
        .count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: var(--primary-color); color: white; font-size: 12px; font-weight: 700; margin-left: 2px; }
        .summary-list { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
        .summary-row {
            display: flex; align-items: center; gap: 13px; width: 100%; text-align: left;
            padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 14px;
            background: #fdfdfe; cursor: pointer; font-family: inherit; transition: all 0.18s;
        }
        .summary-row:hover { border-color: var(--primary-color); background: white; box-shadow: 0 6px 16px rgba(15,23,42,0.07); }
        .summary-row.is-open { border-color: var(--primary-color); background: white; }
        .summary-row-icon { width: 40px; height: 40px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .summary-row-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .summary-row-title { font-size: 14.5px; font-weight: 700; color: #0f172a; line-height: 1.35; }
        .summary-row-meta { font-size: 12px; color: #64748b; line-height: 1.45; }
        .summary-row-meta strong { color: #0f172a; }
        .summary-row-chev { color: #94a3b8; flex-shrink: 0; transition: transform 0.2s; }
        .summary-row.is-open .summary-row-chev { transform: rotate(180deg); color: var(--primary-color); }

        .tracker-card { border-top: 4px solid var(--track-color, #f59e0b); }
        .tracker-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 14px; }
        .tracker-head h2 { font-size: 18px; }
        .tracker-ref { font-size: 12.5px; color: #64748b; margin-top: 4px; }

        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 26px 0 8px; }
        .step { position: relative; text-align: center; padding-top: 34px; }
        .step::before { content: ''; position: absolute; top: 11px; left: 0; right: 0; height: 3px; background: #e2e8f0; }
        .step:first-child::before { left: 50%; }
        .step:last-child::before  { right: 50%; }
        .step.done::before, .step.current::before { background: #86efac; }
        .step:first-child.done::before { background: #86efac; }
        .step .dot { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 26px; height: 26px; border-radius: 50%; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 11px; border: 3px solid white; box-shadow: 0 0 0 3px #e2e8f0; }
        .step.done .dot { background: #16a34a; color: white; box-shadow: 0 0 0 3px #bbf7d0; }
        .step.current .dot { background: var(--track-color, #f59e0b); color: white; box-shadow: 0 0 0 3px color-mix(in srgb, var(--track-color, #f59e0b) 35%, white); animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { transform: translateX(-50%) scale(1); } 50% { transform: translateX(-50%) scale(1.12); } }
        .step .step-label { font-size: 12px; font-weight: 600; color: #475569; }
        .step .step-when  { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .step:not(.done):not(.current) .step-label { color: #94a3b8; font-weight: 500; }

        .claim-slip { margin-top: 24px; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 20px; background: linear-gradient(135deg, #f8fafc, #eef2ff); }
        .claim-slip.released { background: linear-gradient(135deg, #f0fdf4, #ecfdf5); border-color: #86efac; }
        .claim-slip h3 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 14px; }
        .claim-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; }
        .claim-item .k { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; font-weight: 600; }
        .claim-item .v { font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 4px; line-height: 1.4; }
        .claim-note { margin-top: 16px; font-size: 12.5px; color: #475569; display: flex; gap: 8px; line-height: 1.55; }

        .registrar-note { margin-top: 18px; padding: 12px 14px; border-radius: 12px; font-size: 13px; line-height: 1.55; overflow-wrap: anywhere; }
        .registrar-note.warn { background: #fef2f2; border-left: 3px solid #dc2626; color: #7f1d1d; }
        .registrar-note.calm { background: #f8fafc; border-left: 3px solid #94a3b8; color: #334155; }

        /* ── BUTTONS ── */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; font-family: inherit; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { filter: brightness(1.15); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(26,54,93,0.25); }
        .btn-ghost { background: #f1f5f9; color: #475569; }
        .btn-ghost:hover { background: #e2e8f0; }
        .btn-danger-ghost { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .btn-danger-ghost:hover { background: #fee2e2; }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }

        /* ── FORM ── */
        .form-section { border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; margin-bottom: 18px; background: #fdfdfe; }
        .form-section > h3 { display: flex; align-items: center; gap: 9px; font-size: 14.5px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .form-section > h3 i { color: var(--primary-color); width: 18px; text-align: center; }
        .form-section .section-note { font-size: 12.5px; color: #64748b; margin-bottom: 16px; line-height: 1.5; }

        .field-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .field { display: flex; flex-direction: column; grid-column: span var(--span, 1); min-width: 0; }
        .field label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .req-star { color: #dc2626; }
        .auto-tag { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 6px; }
        .field input, .field select, .field textarea {
            width: 100%; padding: 11px 13px; border: 1px solid #cbd5e1; border-radius: 10px;
            font-family: inherit; font-size: 14px; color: #0f172a; background: white; transition: border 0.15s, box-shadow 0.15s;
        }
        .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(26,54,93,0.12); }
        .field input[readonly] { background: #f1f5f9; color: #64748b; cursor: not-allowed; }
        .field textarea { resize: vertical; min-height: 78px; }

        .check-list { display: grid; gap: 10px; }
        .check-item { display: flex; align-items: flex-start; gap: 11px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; cursor: pointer; transition: all 0.15s; }
        .check-item:hover { border-color: #94a3b8; background: #f8fafc; }
        .check-item input { width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0; accent-color: var(--primary-color); cursor: pointer; }
        .check-item span { font-size: 13.5px; color: #334155; line-height: 1.5; }
        .check-item .opt { font-size: 11px; color: #94a3b8; font-weight: 600; }
        .check-item:has(input:checked) { border-color: #86efac; background: #f0fdf4; }
        .transferee-toggle { background: #fffbeb; border-color: #fde68a; }
        .transferee-toggle:has(input:checked) { border-color: #fbbf24; background: #fef3c7; }
        #transfereeBlock { display: none; margin-top: 10px; padding-left: 4px; border-left: 3px solid #fde68a; }
        #transfereeBlock.show { display: grid; gap: 10px; padding-left: 14px; }

        .waiver { margin-top: 6px; padding: 16px; border-radius: 14px; background: #fff7ed; border: 1px solid #fed7aa; }
        .waiver p { font-size: 13px; color: #7c2d12; line-height: 1.6; margin-bottom: 12px; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; margin-top: 22px; padding-top: 20px; border-top: 1px solid #e2e8f0; }

        /* ── HISTORY ── */
        .req-table { width: 100%; border-collapse: collapse; }
        .req-table th { text-align: left; padding: 12px 14px; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .req-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; vertical-align: top; }
        .req-table tr:last-child td { border-bottom: none; }
        .empty-state { text-align: center; padding: 44px 20px; color: #94a3b8; }
        .empty-state i { font-size: 38px; color: #cbd5e1; margin-bottom: 12px; display: block; }

        /* ── FORM PICKER ── */
        /* ── FORM FILTER (picker and history) ── */
        .form-filter { display: flex; align-items: center; gap: 10px; margin-top: 18px; }
        .filter-caption { font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .fchips { display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; }
        .fchip { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; border: 1px solid #e2e8f0; background: white; color: #475569; font: 600 12.5px/1 inherit; font-family: inherit; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
        .fchip:hover { border-color: #94a3b8; background: #f8fafc; }
        .fchip.is-active { background: var(--primary-color); border-color: var(--primary-color); color: white; }
        .fcount { background: rgba(15,23,42,0.08); padding: 2px 7px; border-radius: 999px; font-size: 11px; }
        .fchip.is-active .fcount { background: rgba(255,255,255,0.22); }
        .req-table tr[hidden] { display: none; }
        .ref-link { border: none; background: none; padding: 0; font: 700 13.5px/1 inherit; font-family: inherit; color: var(--primary-color); cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
        .ref-link:hover { filter: brightness(1.3); }

        .picker-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 18px; }
        .picker-card {
            display: flex; align-items: flex-start; gap: 14px; text-align: left;
            padding: 18px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fdfdfe;
            cursor: pointer; font-family: inherit; transition: all 0.18s; width: 100%;
        }
        .picker-card:hover { border-color: var(--primary-color); background: white; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15,23,42,0.08); }
        .picker-icon { width: 44px; height: 44px; flex-shrink: 0; border-radius: 12px; background: rgba(26,54,93,0.09); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .picker-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
        .picker-title { font-size: 15px; font-weight: 700; color: #0f172a; line-height: 1.35; }
        .picker-code { font-size: 11px; font-weight: 600; color: #1d4ed8; }
        .picker-desc { font-size: 12.5px; color: #64748b; line-height: 1.5; }
        .picker-go { color: #cbd5e1; align-self: center; flex-shrink: 0; }
        .picker-card:hover .picker-go { color: var(--primary-color); }

        .locked-card { text-align: center; padding: 44px 24px; }
        .locked-card .icon { width: 68px; height: 68px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 16px; }

        /* ── RESPONSIVE ── */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0) !important; }
            .sidebar .sidebar-header { padding-top: 55px; padding-bottom: 0; margin-bottom: -10px; }
            .sidebar .sidebar-bottom { padding-bottom: 25px; margin-top: 20px; }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { display: flex !important; }
        }
        @media (max-width: 900px) {
            .field-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .field { grid-column: span min(var(--span, 1), 2); }
        }
        @media (max-width: 640px) {
            .main-content { padding: 16px; }
            /* The badge and chevron wrap under the title rather than squeezing it. */
            .summary-row { flex-wrap: wrap; row-gap: 10px; padding: 14px; }
            .summary-row-main { flex: 1 1 100%; order: 2; }
            .summary-row-icon { order: 1; }
            .summary-row-chev { order: 1; margin-left: auto; }
            .summary-row .status-badge { order: 3; }
            /* On a phone the receipt rises from the bottom and its buttons stack. */
            .receipt { padding: 0; align-items: flex-end; }
            .receipt-dialog { max-width: none; border-radius: 22px 22px 0 0; padding: 30px 20px 22px; max-height: 92vh; transform: translateY(24px); }
            .receipt.is-open .receipt-dialog { transform: none; }
            .receipt-actions { flex-direction: column-reverse; }
            .receipt-facts > div { flex-direction: column; align-items: flex-start; gap: 4px; }
            .receipt-facts dd { text-align: left; }
            /* The chips scroll sideways rather than stacking into a tall block. */
            .form-filter { gap: 8px; }
            .filter-caption { display: none; }
            .fchips { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
            .fchip { flex-shrink: 0; }
            .card { padding: 18px; border-radius: 16px; }
            .page-head h1 { font-size: 21px; }
            .field-grid { grid-template-columns: 1fr; }
            .field { grid-column: 1 / -1; }
            .steps { grid-template-columns: 1fr; gap: 0; }
            .step { display: grid; grid-template-columns: 34px 1fr; text-align: left; padding: 0 0 20px 0; align-items: start; }
            .step::before { top: 26px; bottom: -4px; left: 12px; right: auto; width: 3px; height: auto; }
            .step:first-child::before { left: 12px; top: 26px; }
            .step:last-child::before { display: none; }
            .step .dot { position: static; transform: none; }
            .step.current .dot { animation: none; }
            .step .step-label, .step .step-when { grid-column: 2; padding-left: 10px; }
            .form-actions .btn { flex: 1 1 100%; }
            .req-table thead { display: none; }
            .req-table tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; padding: 6px; }
            .req-table td { display: flex; justify-content: space-between; gap: 14px; border: none; padding: 8px 10px; }
            .req-table td::before { content: attr(data-label); font-weight: 700; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; flex-shrink: 0; }
            .req-table td > * { text-align: right; }
        }

        /* Only the claim slip goes on paper. */
        @media print {
            .tracker-card[hidden] { display: block !important; }
            body { display: block; background: white; padding: 0; }
            .sidebar, .mobile-appbar, .sidebar-overlay, .page-head, .flash,
            .btn, .form-card, .form-picker, .history-card, .receipt, .no-print { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            .card { border: none; box-shadow: none; padding: 0; }
            .claim-slip { border: 2px solid #0f172a; background: white !important; }
        }
    </style>
    <link rel="stylesheet" href="request_form.css?v=6">
    <link rel="stylesheet" href="request_viewer.css?v=2">
    <link rel="stylesheet" href="student_mobile.css?v=1">
    <link rel="stylesheet" href="student_sidebar.css?v=1">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
    <link rel="stylesheet" href="student_profile.css?v=9">
    <script src="student_profile.js?v=3" defer></script>
</head>
<body class="student-shell">
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="student_dashboard.php" class="mobile-appbar-brand">
            <img src="logo.jpg" alt="">
            <span>Student Portal</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-area">
                <div class="logo-icon">
                    <img src="logo.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="logo-text">Student Portal</div>
            </div>
            <div class="student-info" style="text-align: center;">
                <?php if ($user_profile_picture): ?>
                    <img src="<?= htmlspecialchars($user_profile_picture) ?>" alt="Profile" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--primary-color);">
                <?php else: ?>
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin: 0 auto 10px auto;">
                        <?= strtoupper(substr($first_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="student-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="student-id">ID: <?= htmlspecialchars($user_student_id) ?></div>
                <?php if (!empty($user_course) || !empty($display_year_section)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $display_year_section)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-menu">
            <a href="student_dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> <span>Overview</span></a>
            <a href="my_document.php" class="nav-item"><i class="fas fa-file-alt"></i> <span>My Documents</span></a>
            <a href="student_calendar.php" class="nav-item"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a>
            <a href="submissions.php" class="nav-item"><i class="fas fa-paper-plane"></i> <span>Submissions</span></a>
            <a href="student_notifications.php" class="nav-item">
                <i class="fas fa-bell"></i> <span>Notifications</span>
                <?php if ($unread_count > 0): ?><span class="nav-badge" style="margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 7px; border-radius: 20px;"><?= $unread_count ?></span><?php endif; ?>
            </a>
            <a href="checklist.php" class="nav-item"><i class="fas fa-list-check"></i> <span>Document Checklist</span></a>
            <a href="student_request.php" class="nav-item active"><i class="fas fa-file-signature"></i> <span>Requests</span></a>
            <a href="student_settings.php" class="nav-item"><i class="fas fa-cog"></i> <span>Settings</span></a>
        </div>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-head">
            <h1>Requests</h1>
            <p>File a registrar form online and follow it from review all the way to the day you claim it.</p>
        </div>

        <?php if ($flash): ?>
            <div class="flash <?= htmlspecialchars(in_array($flash_type, ['success','error','info'], true) ? $flash_type : 'success') ?>">
                <i class="fas <?= $flash_type === 'error' ? 'fa-circle-exclamation' : ($flash_type === 'info' ? 'fa-circle-info' : 'fa-circle-check') ?>" style="margin-top:2px"></i>
                <span><?= htmlspecialchars($flash) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($open_by_form): ?>
        <?php
        // ── What the student is waiting on ───────────────────────────────────
        // One compact line each, with the claim schedule already on it, so the
        // page stays short; the full tracker only unfolds when it is asked for.
        ?>
        <div class="card summary-card no-print">
            <div class="card-title">
                <i class="fas fa-list-check"></i> My requests
                <span class="count-badge"><?= count($open_by_form) ?></span>
            </div>
            <div class="card-sub">Tap a request to open its full progress and claim details.</div>

            <div class="summary-list">
                <?php foreach ($open_by_form as $ft => $r):
                    $m = requestStatusMeta($r['status']);
                    $icon = $available_forms[$ft]['icon'] ?? 'fa-file-lines';
                ?>
                <button type="button" class="summary-row" data-target="tracker-<?= htmlspecialchars($ft) ?>"
                        aria-expanded="false" aria-controls="tracker-<?= htmlspecialchars($ft) ?>">
                    <span class="summary-row-icon" style="background: <?= $m['color'] ?>1a; color: <?= $m['color'] ?>;">
                        <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                    </span>
                    <span class="summary-row-main">
                        <span class="summary-row-title"><?= htmlspecialchars($r['form_title']) ?></span>
                        <span class="summary-row-meta">
                            #<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?>
                            &middot; Filed <?= date('M j, Y', strtotime($r['submitted_at'])) ?>
                            <?php if ($r['status'] === 'approved' && !empty($r['claim_at'])): ?>
                                &middot; <strong>Claim <?= date('D, M j', strtotime($r['claim_at'])) ?> at <?= date('g:i A', strtotime($r['claim_at'])) ?></strong>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="status-badge" style="background: <?= $m['color'] ?>1a; color: <?= $m['color'] ?>;">
                        <i class="fas <?= $m['icon'] ?>"></i> <?= htmlspecialchars($m['student']) ?>
                    </span>
                    <span class="summary-row-chev"><i class="fas fa-chevron-down"></i></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
        foreach ($open_by_form as $open_form_type => $active_request):
            $meta = requestStatusMeta($active_request['status']);
            $is_approved = $active_request['status'] === 'approved';
            $reviewer = trim(($active_request['reviewer_first'] ?? '') . ' ' . ($active_request['reviewer_last'] ?? ''));
        ?>
        <div class="card tracker-card" id="tracker-<?= htmlspecialchars($open_form_type) ?>" hidden style="--track-color: <?= $meta['color'] ?>">
            <div class="tracker-head">
                <div>
                    <h2><?= htmlspecialchars($active_request['form_title']) ?></h2>
                    <div class="tracker-ref">
                        Reference #<?= str_pad($active_request['id'], 5, '0', STR_PAD_LEFT) ?>
                        &middot; Filed <?= date('M j, Y \a\t g:i A', strtotime($active_request['submitted_at'])) ?>
                    </div>
                </div>
                <span class="status-badge" style="background: <?= $meta['color'] ?>1a; color: <?= $meta['color'] ?>;">
                    <i class="fas <?= $meta['icon'] ?>"></i> <?= htmlspecialchars($meta['student']) ?>
                </span>
            </div>

            <div class="steps">
                <div class="step done">
                    <div class="dot"><i class="fas fa-check"></i></div>
                    <div class="step-label">Filed</div>
                    <div class="step-when"><?= date('M j', strtotime($active_request['submitted_at'])) ?></div>
                </div>
                <div class="step <?= $is_approved ? 'done' : 'current' ?>">
                    <div class="dot"><i class="fas <?= $is_approved ? 'fa-check' : 'fa-hourglass-half' ?>"></i></div>
                    <div class="step-label">Registrar review</div>
                    <div class="step-when"><?= $is_approved && $active_request['reviewed_at'] ? date('M j', strtotime($active_request['reviewed_at'])) : 'In progress' ?></div>
                </div>
                <div class="step <?= $is_approved ? 'current' : '' ?>">
                    <div class="dot"><i class="fas fa-calendar-check"></i></div>
                    <div class="step-label">Ready for claim</div>
                    <div class="step-when"><?= $is_approved ? date('M j', strtotime($active_request['claim_at'])) : 'Waiting' ?></div>
                </div>
                <div class="step">
                    <div class="dot"><i class="fas fa-hand-holding-heart"></i></div>
                    <div class="step-label">Claimed</div>
                    <div class="step-when">Not yet</div>
                </div>
            </div>

            <?php if ($is_approved): ?>
                <div class="claim-slip">
                    <h3><i class="fas fa-receipt"></i> Claim Slip</h3>
                    <div class="claim-grid">
                        <div class="claim-item">
                            <div class="k">Claim date</div>
                            <div class="v"><?= date('l, F j, Y', strtotime($active_request['claim_at'])) ?></div>
                        </div>
                        <div class="claim-item">
                            <div class="k">Claim time</div>
                            <div class="v"><?= date('g:i A', strtotime($active_request['claim_at'])) ?></div>
                        </div>
                        <div class="claim-item">
                            <div class="k">Where</div>
                            <div class="v"><?= htmlspecialchars($active_request['claim_location'] ?: REQUEST_DEFAULT_CLAIM_LOCATION) ?></div>
                        </div>
                        <div class="claim-item">
                            <div class="k">Claimant</div>
                            <div class="v"><?= htmlspecialchars($full_name) ?></div>
                        </div>
                        <div class="claim-item">
                            <div class="k">Reference</div>
                            <div class="v">#<?= str_pad($active_request['id'], 5, '0', STR_PAD_LEFT) ?></div>
                        </div>
                    </div>
                    <div class="claim-note">
                        <i class="fas fa-circle-info" style="margin-top:2px"></i>
                        <span>Bring a valid ID and this slip. If you cannot make it, message the registrar's office so your schedule can be moved.</span>
                    </div>
                </div>
                <?php if (!empty($active_request['registrar_note'])): ?>
                    <div class="registrar-note calm"><strong>Note from <?= $reviewer !== '' ? htmlspecialchars($reviewer) : 'the registrar' ?>:</strong> <?= htmlspecialchars($active_request['registrar_note']) ?></div>
                <?php endif; ?>
                <div class="form-actions no-print">
                    <button type="button" class="btn btn-ghost js-view-form"
                            data-id="<?= (int)$active_request['id'] ?>"
                            data-title="<?= htmlspecialchars($active_request['form_title']) ?>"
                            data-subtitle="#<?= str_pad($active_request['id'], 5, '0', STR_PAD_LEFT) ?> &middot; Filed <?= date('M j, Y', strtotime($active_request['submitted_at'])) ?>"
                            data-status-label="<?= htmlspecialchars($meta['student']) ?>"
                            data-status-color="<?= $meta['color'] ?>">
                        <i class="fas fa-eye"></i> View my form
                    </button>
                    <a class="btn btn-primary" href="download_claim_slip.php?id=<?= (int)$active_request['id'] ?>">
                        <i class="fas fa-receipt"></i> Download claim slip
                    </a>
                </div>
            <?php else: ?>
                <div class="registrar-note calm">
                    <strong>What happens next:</strong> the registrar checks your application. Once it is approved you will get a notification and an email with the exact date and time you may claim it, and the schedule will appear right here.
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost js-view-form"
                            data-id="<?= (int)$active_request['id'] ?>"
                            data-title="<?= htmlspecialchars($active_request['form_title']) ?>"
                            data-subtitle="#<?= str_pad($active_request['id'], 5, '0', STR_PAD_LEFT) ?> &middot; Filed <?= date('M j, Y', strtotime($active_request['submitted_at'])) ?>"
                            data-status-label="<?= htmlspecialchars($meta['student']) ?>"
                            data-status-color="<?= $meta['color'] ?>">
                        <i class="fas fa-eye"></i> View my form
                    </button>
                    <form method="POST" action="process_student_request.php" onsubmit="return confirm('Cancel this request? You can file it again later.');">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="request_id" value="<?= (int)$active_request['id'] ?>">
                        <button type="submit" class="btn btn-danger-ghost"><i class="fas fa-ban"></i> Cancel this request</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php
        // ── The forms themselves ─────────────────────────────────────────────
        if (!$available_forms): ?>
            <div class="card locked-card">
                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                <h2 style="font-size:18px; margin-bottom:8px;">No online forms for <?= htmlspecialchars($user_institution ?: 'your institution') ?> yet</h2>
                <p style="color:#64748b; font-size:14px; max-width:520px; margin:0 auto; line-height:1.6;">
                    Online requests are currently available to ISAP students only. The <?= htmlspecialchars($user_institution ?: 'other') ?> forms are being prepared
                    and will show up here once the registrar's office releases them. In the meantime, please file your request at the registrar's office.
                </p>
            </div>
        <?php elseif (!$fileable): ?>
            <div class="card locked-card">
                <div class="icon" style="background:#dcfce7; color:#16a34a"><i class="fas fa-circle-check"></i></div>
                <h2 style="font-size:18px; margin-bottom:8px;">Everything you can file is already in progress</h2>
                <p style="color:#64748b; font-size:14px; max-width:520px; margin:0 auto; line-height:1.6;">
                    Follow the tracker above. Once a request is claimed or cancelled, you can file that form again.
                </p>
            </div>
        <?php else: ?>
            <div class="card form-picker" id="formPicker">
                <div class="card-title"><i class="fas fa-folder-open"></i> Forms you can file</div>
                <div class="card-sub">Pick one to fill up. Whatever the portal already knows about you is filled in for you.</div>

                <div class="picker-grid">
                    <?php foreach ($fileable as $ft => $f): ?>
                        <button type="button" class="picker-card" data-form="<?= htmlspecialchars($ft) ?>">
                            <span class="picker-icon"><i class="fas <?= htmlspecialchars($f['icon']) ?>"></i></span>
                            <span class="picker-body">
                                <span class="picker-title"><?= htmlspecialchars($f['title']) ?></span>
                                <span class="picker-code"><?= htmlspecialchars($f['code']) ?></span>
                                <span class="picker-desc"><?= htmlspecialchars($f['description']) ?></span>
                            </span>
                            <span class="picker-go"><i class="fas fa-chevron-right"></i></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($fileable as $ft => $f):
                $last_form_data = $last_by_form[$ft] ?? [];
                $fill_data = requestAutofillData($user_data, $student_profile, $ft);
            ?>
            <div class="card form-card" id="form-<?= htmlspecialchars($ft) ?>" hidden>
                <div style="display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:6px;">
                    <div>
                        <div class="card-title"><i class="fas <?= htmlspecialchars($f['icon']) ?>"></i> <?= htmlspecialchars($f['title']) ?></div>
                        <div class="card-sub"><?= htmlspecialchars($f['description']) ?></div>
                    </div>
                    <span class="status-badge" style="background:#eff6ff; color:#1d4ed8;"><?= htmlspecialchars($f['code']) ?></span>
                </div>

                <div class="flash info" style="margin: 18px 0 22px;">
                    <i class="fas fa-wand-magic-sparkles" style="margin-top:2px"></i>
                    <span>Fields marked <span class="auto-tag">auto</span> were filled in from your profile. Correct anything that is out of date before you submit — the registrar prints exactly what you send.</span>
                </div>

                <form method="POST" action="process_student_request.php" class="request-form">
                    <input type="hidden" name="action" value="submit">
                    <input type="hidden" name="form_type" value="<?= htmlspecialchars($ft) ?>">

                    <?php foreach (getRequestFormSections($ft) as $section): ?>
                        <div class="form-section">
                            <h3><i class="fas <?= $section['icon'] ?>"></i> <?= htmlspecialchars($section['title']) ?></h3>
                            <?php if (!empty($section['note'])): ?>
                                <div class="section-note"><?= htmlspecialchars($section['note']) ?></div>
                            <?php endif; ?>
                            <div class="field-grid">
                                <?php foreach ($section['fields'] as $field):
                                    $fid   = 'f_' . $ft . '_' . $field['name'];
                                    $type  = $field['type'] ?? 'text';
                                    $value = prefillValue($field, $fill_data, $last_form_data);
                                    $auto  = !empty($field['autofill']) && !empty($fill_data[$field['autofill']]);
                                    $required = !empty($field['required']);
                                    $attrs = ($required ? ' required' : '') . (!empty($field['readonly']) ? ' readonly' : '');
                                ?>
                                <div class="field" style="--span: <?= (int)($field['span'] ?? 1) ?>">
                                    <?php if ($type === 'checkbox'): ?>
                                        <label class="check-item" style="margin-top:22px">
                                            <input type="checkbox" id="<?= $fid ?>" name="<?= $field['name'] ?>" value="1"
                                                   <?= !empty($last_form_data[$field['name']]) ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars($field['label']) ?></span>
                                        </label>
                                    <?php else: ?>
                                        <label for="<?= $fid ?>">
                                            <?= htmlspecialchars($field['label']) ?>
                                            <?php if ($required): ?><span class="req-star">*</span><?php endif; ?>
                                            <?php if ($auto): ?><span class="auto-tag">auto</span><?php endif; ?>
                                        </label>
                                        <?php if ($type === 'textarea'): ?>
                                            <textarea id="<?= $fid ?>" name="<?= $field['name'] ?>"<?= $attrs ?> placeholder="<?= htmlspecialchars($field['placeholder'] ?? 'House/street, barangay, city/municipality, province') ?>"><?= htmlspecialchars($value) ?></textarea>
                                        <?php elseif ($type === 'select'): ?>
                                            <select id="<?= $fid ?>" name="<?= $field['name'] ?>"<?= $attrs ?>>
                                                <option value="">Select…</option>
                                                <?php foreach ($field['options'] as $opt): ?>
                                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $value === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($type === 'year'): ?>
                                            <input type="number" id="<?= $fid ?>" name="<?= $field['name'] ?>" value="<?= htmlspecialchars($value) ?>"
                                                   min="1950" max="<?= (int)date('Y') + 1 ?>" step="1" placeholder="e.g. <?= (int)date('Y') - 4 ?>"
                                                   inputmode="numeric"<?= $attrs ?>>
                                        <?php else: ?>
                                            <input type="<?= htmlspecialchars($type) ?>" id="<?= $fid ?>" name="<?= $field['name'] ?>"
                                                   value="<?= htmlspecialchars($value) ?>"
                                                   <?php if (!empty($field['placeholder'])): ?>placeholder="<?= htmlspecialchars($field['placeholder']) ?>"<?php endif; ?>
                                                   <?= $type === 'date' ? 'max="' . date('Y-m-d') . '"' : '' ?><?= $attrs ?>>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($ft === 'graduation'): ?>
                    <div class="form-section">
                        <h3><i class="fas fa-paperclip"></i> Document Requirements</h3>
                        <div class="section-note">Tick every document you have already submitted to the registrar's office. Anything left unticked will be listed as still outstanding.</div>
                        <div class="check-list">
                            <?php foreach (getGraduationRequirements() as $item): if ($item['group'] !== 'all') continue; ?>
                                <label class="check-item">
                                    <input type="checkbox" name="requirements[<?= $item['key'] ?>]" value="1">
                                    <span><?= htmlspecialchars($item['label']) ?>
                                        <?php if (!empty($item['optional'])): ?><br><span class="opt">Only if it applies to you</span><?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>

                            <label class="check-item transferee-toggle">
                                <input type="checkbox" name="is_transferee" id="isTransferee" value="1">
                                <span><strong>I am a transferee.</strong><br><span class="opt">Ticking this adds the extra documents transferees have to submit.</span></span>
                            </label>
                            <div id="transfereeBlock">
                                <?php foreach (getGraduationRequirements() as $item): if ($item['group'] !== 'transferee') continue; ?>
                                    <label class="check-item">
                                        <input type="checkbox" name="requirements[<?= $item['key'] ?>]" value="1">
                                        <span><?= htmlspecialchars($item['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-clipboard-check"></i> Declarations</h3>
                        <div class="section-note">All of these must be true before the registrar can process your application.</div>
                        <div class="check-list">
                            <?php foreach (getGraduationDeclarations() as $item): ?>
                                <label class="check-item">
                                    <input type="checkbox" name="declarations[<?= $item['key'] ?>]" value="1" required>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-section">
                        <h3><i class="fas fa-comment-dots"></i> Anything else the registrar should know?</h3>
                        <div class="section-note">Optional. Use this for corrections, pending documents, or a preferred claim date.</div>
                        <div class="field" style="--span: 3">
                            <textarea name="student_note" maxlength="1000" placeholder="e.g. My Form 137 is still being released by my previous school."></textarea>
                        </div>
                    </div>

                    <div class="waiver">
                        <p><?= htmlspecialchars($f['confirmation'] ?? '') ?></p>
                        <label class="check-item" style="background:white;">
                            <input type="checkbox" name="waiver" value="1" required>
                            <span>I have read and understood this, and I confirm that everything I entered above is true and correct.</span>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-ghost js-back">Back to forms</button>
                        <button type="submit" class="btn btn-primary js-submit"><i class="fas fa-paper-plane"></i> Submit request</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php
        // ── History ──────────────────────────────────────────────────────────
        $open_ids = array_map(function ($r) { return (int)$r['id']; }, $open_by_form);
        $past = array_filter($requests, function ($r) use ($open_ids) {
            return !in_array((int)$r['id'], $open_ids, true);
        });
        ?>
        <div class="card history-card">
            <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Request history</div>
            <div class="card-sub">Everything you have filed, including requests you already claimed.</div>

            <?php if (empty($past)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <div>No past requests yet.</div>
                </div>
            <?php else: ?>
                <?php
                // Only worth offering when the history actually mixes forms.
                $past_forms = [];
                foreach ($past as $r) {
                    $ft = $r['form_type'] ?: 'graduation';
                    if (!isset($past_forms[$ft])) {
                        $past_forms[$ft] = $available_forms[$ft]['short']
                            ?? ($available_forms[$ft]['title'] ?? $r['form_title']);
                    }
                }
                ?>
                <?php if (count($past_forms) > 1): ?>
                <div class="form-filter" id="historyFilter" role="group" aria-label="Filter the history">
                    <span class="filter-caption"><i class="fas fa-filter"></i> Show</span>
                    <div class="fchips">
                        <button type="button" class="fchip is-active" data-filter="all">
                            All <span class="fcount"><?= count($past) ?></span>
                        </button>
                        <?php foreach ($past_forms as $ft => $label): ?>
                            <button type="button" class="fchip" data-filter="<?= htmlspecialchars($ft) ?>">
                                <i class="fas <?= htmlspecialchars($available_forms[$ft]['icon'] ?? 'fa-file-lines') ?>"></i>
                                <?= htmlspecialchars($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="overflow-x:auto; margin-top:18px;">
                    <table class="req-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Form</th>
                                <th>Filed</th>
                                <th>Status</th>
                                <th>Claim / Released</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($past as $r):
                            $m = requestStatusMeta($r['status']);
                            $rev = trim(($r['reviewer_first'] ?? '') . ' ' . ($r['reviewer_last'] ?? ''));
                        ?>
                            <tr data-form="<?= htmlspecialchars($r['form_type'] ?: 'graduation') ?>">
                                <td data-label="Reference">
                                    <button type="button" class="ref-link js-view-form"
                                            data-id="<?= (int)$r['id'] ?>"
                                            data-title="<?= htmlspecialchars($r['form_title']) ?>"
                                            data-subtitle="#<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?> &middot; Filed <?= date('M j, Y', strtotime($r['submitted_at'])) ?>"
                                            data-status-label="<?= htmlspecialchars($m['student']) ?>"
                                            data-status-color="<?= $m['color'] ?>"
                                            title="Open this form">
                                        #<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?>
                                    </button>
                                </td>
                                <td data-label="Form"><?= htmlspecialchars($r['form_title']) ?></td>
                                <td data-label="Filed">
                                    <div>
                                        <div><?= !empty($r['submitted_at']) ? date('M j, Y', strtotime($r['submitted_at'])) : '—' ?></div>
                                        <?php if (!empty($r['submitted_at'])): ?>
                                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;"><i class="far fa-clock" style="font-size:0.7rem; margin-right:3px;"></i><?= date('g:i A', strtotime($r['submitted_at'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <div>
                                        <span class="status-badge" style="background: <?= $m['color'] ?>1a; color: <?= $m['color'] ?>;">
                                            <i class="fas <?= $m['icon'] ?>"></i> <?= htmlspecialchars($m['student']) ?>
                                        </span>
                                        <?php if ($r['status'] === 'rejected' && !empty($r['registrar_note'])): ?>
                                            <div class="registrar-note warn" style="margin-top:8px; max-width:320px;">
                                                <strong>Reason<?= $rev !== '' ? ' (' . htmlspecialchars($rev) . ')' : '' ?>:</strong>
                                                <?= htmlspecialchars($r['registrar_note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Claim / Released">
                                    <?php if (!empty($r['claimed_at'])): ?>
                                        <div><strong><?= formatClaimSchedule($r['claimed_at']) ?></strong></div>
                                        <div style="font-size:12px; color:#64748b; margin-top:3px;">Received by <?= htmlspecialchars($r['claimed_by_name'] ?: $full_name) ?></div>
                                    <?php elseif (!empty($r['claim_at'])): ?>
                                        <div><?= formatClaimSchedule($r['claim_at']) ?></div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php if ($just_submitted):
    $js_meta = requestStatusMeta($just_submitted['status']);
    $js_type = $just_submitted['form_type'] ?: 'graduation';
?>
<div class="receipt" id="receiptModal" role="dialog" aria-modal="true" aria-labelledby="receiptTitle">
    <div class="receipt-backdrop" data-receipt-close></div>
    <div class="receipt-dialog" role="document" tabindex="-1">
        <div class="receipt-ring"><i class="fas fa-check"></i></div>

        <h2 id="receiptTitle">Request submitted</h2>
        <p class="receipt-sub">
            Your <strong><?= htmlspecialchars($just_submitted['form_title']) ?></strong>
            is now with the registrar&rsquo;s office.
        </p>

        <dl class="receipt-facts">
            <div>
                <dt>Reference</dt>
                <dd class="receipt-ref">#<?= str_pad($just_submitted['id'], 5, '0', STR_PAD_LEFT) ?></dd>
            </div>
            <div>
                <dt>Filed</dt>
                <dd><?= date('M j, Y \a\t g:i A', strtotime($just_submitted['submitted_at'])) ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd><span class="status-badge" style="background: <?= $js_meta['color'] ?>1a; color: <?= $js_meta['color'] ?>;">
                    <i class="fas <?= $js_meta['icon'] ?>"></i> <?= htmlspecialchars($js_meta['student']) ?>
                </span></dd>
            </div>
        </dl>

        <p class="receipt-next">
            <i class="fas fa-circle-info"></i>
            <span>You will get a notification and an email once it is approved, with the exact date and time you may claim it.</span>
        </p>

        <div class="receipt-actions">
            <!-- Nothing to download yet: the claim slip only exists once the
                 registrar approves this and sets a schedule. -->
            <button type="button" class="btn btn-primary" data-receipt-close data-open-tracker="tracker-<?= htmlspecialchars($js_type) ?>">
                <i class="fas fa-list-check"></i> View my request
            </button>
        </div>

        <button type="button" class="receipt-close" data-receipt-close aria-label="Close">&times;</button>
    </div>
</div>

<script>
    // The receipt is shown once, straight after filing. Closing it through
    // "View my request" unfolds that request's tracker, so the student lands on it.
    (function () {
        const modal = document.getElementById('receiptModal');
        if (!modal) return;

        const dialog = modal.querySelector('.receipt-dialog');
        let openTracker = null;

        function close() {
            modal.classList.remove('is-open');
            document.body.classList.remove('receipt-open');
            setTimeout(() => modal.remove(), 220);
            if (openTracker) {
                const row = document.querySelector('.summary-row[data-target="' + openTracker + '"]');
                if (row && !row.classList.contains('is-open')) row.click();
            }
        }

        modal.querySelectorAll('[data-receipt-close]').forEach(el => {
            el.addEventListener('click', () => {
                openTracker = el.dataset.openTracker || null;
                close();
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
        });

        requestAnimationFrame(() => {
            modal.classList.add('is-open');
            document.body.classList.add('receipt-open');
            if (dialog) dialog.focus();
        });
    })();
</script>
<?php endif; ?>

<script src="request_form.js?v=1"></script>
<script src="request_viewer.js?v=3"></script>
<script>
    // Transferee documents only make sense once the student says they are one.
    (function () {
        const toggle = document.getElementById('isTransferee');
        const block  = document.getElementById('transfereeBlock');
        if (!toggle || !block) return;

        function sync() {
            block.classList.toggle('show', toggle.checked);
            if (!toggle.checked) {
                block.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
            }
        }
        toggle.addEventListener('change', sync);
        sync();
    })();

    // "View my form" opens the shared viewer rather than a new tab; the values
    // ride on data- attributes, never interpolated into inline JS.
    document.querySelectorAll('.js-view-form').forEach(btn => {
        btn.addEventListener('click', () => openRequestForm({
            id: btn.dataset.id,
            title: btn.dataset.title,
            subtitle: btn.dataset.subtitle,
            status: { label: btn.dataset.statusLabel, color: btn.dataset.statusColor },
            // The student reads their filed form here; the only paper they take
            // away is the claim slip, so Print and Download PDF stay off.
            allowPrint: false,
            allowDownload: false
        }));
    });

    // The trackers start folded, so a student with two open requests still sees
    // the whole page at a glance; each summary row unfolds its own.
    document.querySelectorAll('.summary-row').forEach(row => {
        row.addEventListener('click', () => {
            const panel = document.getElementById(row.dataset.target);
            if (!panel) return;
            const opening = panel.hidden;
            panel.hidden = !opening;
            row.classList.toggle('is-open', opening);
            row.setAttribute('aria-expanded', String(opening));
            if (opening) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    // One panel per form; the picker shows the chosen one and hides the rest.
    (function () {
        const picker = document.getElementById('formPicker');
        const panels = [...document.querySelectorAll('.form-card')];
        if (!panels.length) return;

        function show(formType) {
            panels.forEach(p => { p.hidden = p.id !== 'form-' + formType; });
            if (picker) picker.hidden = true;
            const open = document.getElementById('form-' + formType);
            if (open) open.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        function back() {
            panels.forEach(p => { p.hidden = true; });
            if (picker) {
                picker.hidden = false;
                picker.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        document.querySelectorAll('.picker-card').forEach(card => {
            card.addEventListener('click', () => show(card.dataset.form));
        });

        // Each filter narrows the items that sit beside it — the form cards in
        // the picker, the rows in the history — so one handler serves both.
        document.querySelectorAll('.form-filter').forEach(bar => {
            const scope = bar.closest('.card');
            const items = scope ? [...scope.querySelectorAll('[data-form]')] : [];
            bar.querySelectorAll('.fchip').forEach(chip => {
                chip.addEventListener('click', () => {
                    const want = chip.dataset.filter;
                    bar.querySelectorAll('.fchip').forEach(c => c.classList.toggle('is-active', c === chip));
                    items.forEach(item => {
                        item.hidden = !(want === 'all' || item.dataset.form === want);
                    });
                });
            });
        });
        document.querySelectorAll('.js-back').forEach(b => b.addEventListener('click', back));

        // With only one form to file there is nothing to choose, so open it.
        if (panels.length === 1) {
            panels[0].hidden = false;
            if (picker) picker.hidden = true;
        }
    })();

    // Stop a double tap from filing the same request twice.
    document.querySelectorAll('.request-form').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('.js-submit');
            if (!btn) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
        });
    });

    document.addEventListener('DOMContentLoaded', () => {
        const savedColor = localStorage.getItem('student-theme-color');
        if (savedColor) document.documentElement.style.setProperty('--primary-color', savedColor);
        const savedBgColor = localStorage.getItem('student-bg-color');
        if (savedBgColor) document.documentElement.style.setProperty('--bg-color', savedBgColor);
        const savedSidebarBgColor = localStorage.getItem('student-sidebar-bg');
        if (savedSidebarBgColor) document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBgColor);
    });

    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.body.classList.toggle('sidebar-open');
    }
    function closeSidebar() {
        document.querySelector('.sidebar').classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
</script>
</body>
</html>
