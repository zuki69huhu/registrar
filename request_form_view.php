<?php
/**
 * Serves a filed request drawn as the paper form (request_form_render.php).
 *
 *   ?id=N            the whole standalone page, for opening in a tab
 *   ?id=N&mode=fragment   just the sheet, for the registrar's View modal to fetch
 *   ?id=N&mode=print      the standalone page with the print dialog already open
 *
 * A registrar may open any request from their own institution; a student may
 * only open their own.
 */

session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'request_form_render.php';

$mode = $_GET['mode'] ?? 'page';
if (!in_array($mode, ['page', 'fragment', 'print'], true)) $mode = 'page';

/** Fragments are fetched by JS, so they answer with a status code, not a redirect. */
function formViewDeny($message, $mode, $code = 403) {
    http_response_code($code);
    if ($mode === 'fragment') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Not available</title>'
           . '<body style="font-family:system-ui,sans-serif;padding:40px;color:#334155">'
           . '<h1 style="font-size:18px">' . htmlspecialchars($message) . '</h1></body>';
    }
    exit;
}

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!$user_id || !in_array($role, ['registrar', 'student'], true)) {
    formViewDeny('Please sign in to view this form.', $mode, 401);
}

$request_id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$request_id) {
    formViewDeny('Invalid request.', $mode, 400);
}

$stmt = $mysqli->prepare("SELECT r.*, u.first_name, u.middle_name, u.last_name, u.student_id, u.course,
                                 u.email AS student_email, u.institution AS student_institution,
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

if (!$request) {
    formViewDeny('That request no longer exists.', $mode, 404);
}

if ($role === 'student') {
    if ((int)$request['user_id'] !== $user_id) {
        formViewDeny('You can only open your own request.', $mode);
    }
} else {
    // Registrars are scoped to their institution, like every other registrar query.
    $reg_stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
    $reg_stmt->bind_param("i", $user_id);
    $reg_stmt->execute();
    $registrar_institution = $reg_stmt->get_result()->fetch_assoc()['institution'] ?? '';
    $reg_stmt->close();

    if (!empty($registrar_institution) && !empty($request['student_institution'])
        && $request['student_institution'] !== $registrar_institution) {
        formViewDeny('This request belongs to another institution.', $mode);
    }
}

// The form definition follows the student's institution, not the viewer's.
$request['institution'] = $request['institution'] ?: $request['student_institution'];
$form = getRequestForm($request['institution'], $request['form_type'] ?? 'graduation');
if (!$form) {
    formViewDeny('There is no printable form for this request.', $mode, 404);
}

$document = renderRequestFormDocument($request, $form);
$ref = '#' . str_pad((string)$request['id'], 5, '0', STR_PAD_LEFT);

if ($mode === 'fragment') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $document;
    exit;
}

$title = $form['title'] . ' ' . $ref;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="request_form.css?v=6">
    <style>
        body { margin: 0; background: #e2e8f0; font-family: Arial, Helvetica, sans-serif; }
        .sheet-wrap { display: flex; justify-content: center; padding: 24px 16px 48px; }
        .sheet-bar {
            position: sticky; top: 0; z-index: 5;
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            padding: 12px 18px; background: #0f172a; color: #f1f5f9;
        }
        .sheet-bar strong { font-size: 14px; }
        .sheet-bar .spacer { flex: 1; }
        .sheet-btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: 9px; border: none; cursor: pointer;
            font: 600 13px/1 Arial, Helvetica, sans-serif; text-decoration: none;
            background: #334155; color: #f1f5f9;
        }
        .sheet-btn:hover { background: #475569; }
        .sheet-btn.primary { background: #c9a227; color: #fff; }
        .sheet-btn.primary:hover { filter: brightness(1.1); }
        /* The sheet is a fixed A4 width. On a narrower screen it is scaled down to
           fit rather than left to scroll sideways; printing ignores the scaling. */
        .sheet-fit { position: relative; }
        .sheet-fit > .rf-doc { transform-origin: top left; }
        @media print {
            .sheet-bar { display: none; }
            .sheet-wrap { padding: 0; display: block; }
            .sheet-fit { width: auto !important; height: auto !important; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
    <div class="sheet-bar">
        <strong><?= htmlspecialchars($title) ?></strong>
        <span class="spacer"></span>
        <?php if ($role !== 'student'): ?>
            <button type="button" class="sheet-btn primary" onclick="window.print()">Print</button>
            <a class="sheet-btn" href="download_request_form.php?id=<?= (int)$request['id'] ?>">Download PDF</a>
        <?php elseif (in_array($request['status'], ['approved', 'claimed'], true) && !empty($request['claim_at'])): ?>
            <!-- A student reads the form here; the claim slip is the paper they keep. -->
            <a class="sheet-btn primary" href="download_claim_slip.php?id=<?= (int)$request['id'] ?>">Download claim slip</a>
        <?php endif; ?>
        <button type="button" class="sheet-btn" onclick="window.close()">Close</button>
    </div>
    <div class="sheet-wrap"><div class="sheet-fit" id="sheetFit"><?= $document ?></div></div>

    <script src="request_form.js?v=1"></script>
    <script>
        // Scale the sheet down to whatever width the screen actually has. The
        // wrapper is resized to the scaled box so the page scrolls normally.
        (function () {
            const fit = document.getElementById('sheetFit');
            const doc = fit.querySelector('.rf-doc');
            if (!doc) return;

            // Fit each answer to its printed line first, at the sheet's real size.
            if (typeof fitRequestSheet === 'function') fitRequestSheet(doc);

            function apply() {
                doc.style.transform = 'none';
                fit.style.width = fit.style.height = '';
                const natural = { w: doc.offsetWidth, h: doc.offsetHeight };
                if (!natural.w) return;

                const avail = fit.parentElement.clientWidth - 32;
                const scale = Math.min(1, avail / natural.w);
                if (scale >= 1) return;

                doc.style.transform = 'scale(' + scale + ')';
                fit.style.width  = Math.round(natural.w * scale) + 'px';
                fit.style.height = Math.round(natural.h * scale) + 'px';
            }

            window.addEventListener('resize', apply);
            // Printing must measure the unscaled sheet, and re-fit afterwards.
            window.addEventListener('beforeprint', () => {
                doc.style.transform = 'none';
                fit.style.width = fit.style.height = '';
            });
            window.addEventListener('afterprint', apply);
            window.addEventListener('load', apply);
            apply();
        })();
    </script>
    <?php if ($mode === 'print'): ?>
    <script>
        // Wait for the logo so it is not missing from the printed sheet.
        window.addEventListener('load', () => setTimeout(() => window.print(), 150));
    </script>
    <?php endif; ?>
</body>
</html>
