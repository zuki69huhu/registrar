<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db.php';

// Check if user is logged in
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (ob_get_length()) ob_end_clean();
    header("Location: index.php?tab=login");
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=index.php?tab=login"><script>window.location.href="index.php?tab=login";</script><title>Redirecting...</title>    <link rel="stylesheet" href="assets/css/mobile_fixes.css?v=1">
    <link rel="stylesheet" href="assets/css/ui_dialog.css?v=1">
    <script src="assets/js/ui_dialog.js?v=1" defer></script>
</head><body style="font-family:sans-serif;text-align:center;padding:40px;background:#f8fafc;"><p>Redirecting to login...</p><p><a href="index.php?tab=login">Click here if not redirected</a></p></body></html>';
    exit;
}

$id = $_GET['id'] ?? null;
$redirect = $_GET['redirect'] ?? 'student_notifications.php';

// Validate redirect target to prevent open redirects
$allowed_redirects = [
    'student_notifications.php',
    'student_dashboard.php',
    'my_document.php',
    'student_calendar.php',
    'checklist.php',
    'submissions.php',
    'student_settings.php'
];

$parsed_path = basename(parse_url($redirect, PHP_URL_PATH));
if (!in_array($parsed_path, $allowed_redirects)) {
    $redirect = 'student_notifications.php';
    $parsed_path = 'student_notifications.php';
}

if ($id === 'all') {
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
} elseif (is_numeric($id)) {
    $notif_id = (int)$id;
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $notif_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // If redirecting to student_notifications.php and doesn't already have view_id, attach it
    if ($parsed_path === 'student_notifications.php' && strpos($redirect, 'view_id=') === false) {
        $delimiter = (strpos($redirect, '?') === false) ? '?' : '&';
        $redirect .= $delimiter . 'view_id=' . $notif_id;
    }
}

// Return JSON response if requested via AJAX or fetch
if (isset($_GET['ajax']) || 
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (ob_get_length()) ob_end_clean();
header("Location: " . $redirect);
$safe_redirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
$js_redirect = json_encode($redirect);
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url={$safe_redirect}">
    <title>Redirecting...</title>
    <script>window.location.href = {$js_redirect};</script>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; text-align: center;">
    <div style="background: white; border-radius: 16px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); max-width: 400px; width: 100%;">
        <div style="font-size: 32px; margin-bottom: 16px;">🔄</div>
        <h3 style="margin: 0 0 10px 0; color: #0f172a;">Opening notification...</h3>
        <p style="margin: 0 0 20px 0; font-size: 14px; color: #64748b;">If you are not redirected automatically, please click below:</p>
        <a href="{$safe_redirect}" style="display: inline-block; background: #1a365d; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">Continue &rarr;</a>
    </div>
</body>
</html>
HTML;
exit;
