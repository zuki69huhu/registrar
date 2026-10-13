<?php
/**
 * Registrar queue for student requests.
 *
 * Shows every form filed by students of the registrar's own institution, lets
 * the registrar read the whole filled form, approve it with a claim schedule
 * (date, time and where), refuse it with a written note, or record that the
 * document was actually handed over.
 *
 * Every decision goes through process_request_status.php.
 */

session_start();
require_once 'db.php';
require_once 'request_config.php';
require_once 'name_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    header("Location: index.php?tab=login");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: index.php?tab=login");
    exit;
}

$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, institution FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: logout.php");
    exit;
}

$full_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['middle_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
if ($full_name === '') $full_name = $user_data['email'];
$initials = strtoupper(substr($user_data['first_name'] ?? 'R', 0, 1) . substr($user_data['last_name'] ?? 'O', 0, 1));
$user_institution = $user_data['institution'] ?? '';

// Sidebar badges
$pending_count = 0;
$res_pending = $mysqli->query("SELECT COUNT(*) AS count FROM documents WHERE status = 'pending'");
if ($res_pending) $pending_count = (int)$res_pending->fetch_assoc()['count'];

$pending_students_count = 0;
if (!empty($user_institution)) {
    $stmt_ps = $mysqli->prepare("SELECT COUNT(*) AS count FROM users WHERE role = 'student' AND is_verified = 1 AND institution = ?");
    $stmt_ps->bind_param("s", $user_institution);
    $stmt_ps->execute();
    $pending_students_count = (int)($stmt_ps->get_result()->fetch_assoc()['count'] ?? 0);
    $stmt_ps->close();
} else {
    $res_ps = $mysqli->query("SELECT COUNT(*) AS count FROM users WHERE role = 'student' AND is_verified = 1");
    if ($res_ps) $pending_students_count = (int)$res_ps->fetch_assoc()['count'];
}
$pending_requests_count = pendingRequestCount($mysqli);

// ── Filters ─────────────────────────────────────────────────────────────────
$allowed_status = ['pending', 'approved', 'claimed', 'rejected', 'cancelled'];
$status_filter = $_GET['status'] ?? 'pending';
if ($status_filter !== 'all' && !in_array($status_filter, $allowed_status, true)) {
    $status_filter = 'pending';
}
$search = trim($_GET['q'] ?? '');
$form_filter = trim($_GET['form'] ?? 'all');

// Requests are always scoped to the registrar's institution, with the same
// unfiltered fallback the other registrar pages use when it is not set.
// The institution and the search narrow the counts as well as the rows; the
// status and form filters do not, so each one can show what picking it gives.
$base_where = [];
$base_params = [];
$base_types  = '';

if (!empty($user_institution)) {
    $base_where[] = 'u.institution = ?';
    $base_params[] = $user_institution;
    $base_types .= 's';
}
if ($search !== '') {
    $base_where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ? OR r.form_title LIKE ? OR r.id = ?)';
    $like = '%' . $search . '%';
    array_push($base_params, $like, $like, $like, $like, (int)ltrim($search, '#'));
    $base_types .= 'ssssi';
}

// ── What the filters can offer ──────────────────────────────────────────────
// One grouped query, read three ways. It runs before the rows are fetched so an
// unknown ?form= is corrected first, instead of quietly returning nothing.
$facets = [];
$facet_sql = "SELECT r.form_type, r.status, r.form_title, COUNT(*) AS c
              FROM student_requests r JOIN users u ON r.user_id = u.id"
           . ($base_where ? ' WHERE ' . implode(' AND ', $base_where) : '')
           . " GROUP BY r.form_type, r.status, r.form_title";
$fstmt = $mysqli->prepare($facet_sql);
if ($fstmt) {
    if ($base_types !== '') $fstmt->bind_param($base_types, ...$base_params);
    $fstmt->execute();
    $facets = $fstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fstmt->close();
}

// Every form the institution offers, plus any older form still sitting in the
// table, so a filter never hides rows it cannot name.
$form_titles  = [];
$form_options = [];
foreach (getRequestFormsForInstitution($user_institution) as $ft => $f) {
    $form_options[$ft] = ['label' => $f['short'] ?? $f['title'], 'icon' => $f['icon']];
}
foreach ($facets as $row) {
    $ft = $row['form_type'] ?: 'graduation';
    if (!isset($form_titles[$ft])) $form_titles[$ft] = $row['form_title'];
    if (!isset($form_options[$ft])) {
        $form_options[$ft] = ['label' => requestFormLabel($user_institution, $ft, $row['form_title']), 'icon' => 'fa-file-lines'];
    }
}
if ($form_filter !== 'all' && !isset($form_options[$form_filter])) {
    $form_filter = 'all';
}

// The status tabs count what the chosen form would give, and the form chips
// count what the chosen status would give, so every number is what you get by
// clicking it.
$counts = array_fill_keys($allowed_status, 0);
$counts['all'] = 0;
$form_counts = ['all' => 0];

foreach ($facets as $row) {
    $c  = (int)$row['c'];
    $ft = $row['form_type'] ?: 'graduation';

    if ($form_filter === 'all' || $form_filter === $ft) {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + $c;
        $counts['all'] += $c;
    }
    if ($status_filter === 'all' || $status_filter === $row['status']) {
        $form_counts[$ft] = ($form_counts[$ft] ?? 0) + $c;
        $form_counts['all'] += $c;
    }
}

// ── The rows themselves ─────────────────────────────────────────────────────
$where  = $base_where;
$params = $base_params;
$types  = $base_types;

if ($status_filter !== 'all') {
    $where[] = 'r.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}
if ($form_filter !== 'all') {
    $where[] = 'r.form_type = ?';
    $params[] = $form_filter;
    $types .= 's';
}

$sql = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.student_id, u.course, u.email AS student_email,
               u.year_level, u.section, u.institution,
               reg.first_name AS reviewer_first, reg.last_name AS reviewer_last,
               rel.first_name AS releaser_first, rel.last_name AS releaser_last
        FROM student_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users reg ON r.reviewed_by = reg.id
        LEFT JOIN users rel ON r.released_by = rel.id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY (r.status = 'pending') DESC, u.last_name ASC, r.submitted_at DESC";

$requests = [];
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Requests due to be claimed today, so the front desk knows who is coming
$due_today = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'approved' && !empty($r['claim_at']) && date('Y-m-d', strtotime($r['claim_at'])) === date('Y-m-d')) {
        $due_today++;
    }
}

// ── What the modals need. The filled form itself is fetched from
//    request_form_view.php when View is opened, so none of it is embedded here.
$modal_data = [];
foreach ($requests as $r) {
    $meta = requestStatusMeta($r['status']);
    $modal_data[$r['id']] = [
        'id'            => (int)$r['id'],
        'ref'           => '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
        'status'        => $r['status'],
        'statusLabel'   => $meta['label'],
        'statusColor'   => $meta['color'],
        'formTitle'     => $r['form_title'],
        'studentName'   => formatPersonNameRow($r),
        'studentId'     => formatStudentId($r['student_id']),
        'submittedAt'   => date('M j, Y 	 g:i A', strtotime($r['submitted_at'])),
        'claimDate'     => !empty($r['claim_at']) ? date('Y-m-d', strtotime($r['claim_at'])) : '',
        'claimTime'     => !empty($r['claim_at']) ? date('H:i', strtotime($r['claim_at'])) : '09:00',
        'claimLocation' => $r['claim_location'] ?: REQUEST_DEFAULT_CLAIM_LOCATION,
    ];
}

$flash = $_SESSION['flash'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash'], $_SESSION['flash_type']);

/**
 * A link to this page with one filter changed and the rest kept. Every tab,
 * chip and Clear link goes through it, so switching one filter never silently
 * drops another.
 */
$filter_link = function (array $overrides = []) use ($status_filter, $form_filter, $search) {
    $params = array_merge(['status' => $status_filter, 'form' => $form_filter, 'q' => $search], $overrides);
    // `form` defaults to all when absent, so it may be dropped; `status` defaults
    // to pending, so status=all has to stay in the URL or the All tab would
    // silently land back on Pending.
    if (($params['form'] ?? '') === 'all') unset($params['form']);
    $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
    return 'registrar_requests.php' . ($params ? '?' . http_build_query($params) : '');
};

$filters_on = ($status_filter !== 'pending') || ($form_filter !== 'all') || ($search !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Requests - Registrar Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --sidebar-bg: #0a1628; --main-bg: #0f172a; --card-bg: #1e293b;
            --accent-gold: #c9a227; --accent-green: #10b981; --accent-orange: #f59e0b;
            --accent-red: #ef4444; --accent-purple: #8b5cf6; --accent-blue: #3b82f6;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8; --text-muted: #64748b;
            --border: #1e293b; --sidebar-width: 240px;
        }
        body { font-family: 'Inter', sans-serif; background: var(--main-bg); color: var(--text-primary); display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: var(--sidebar-width); background: var(--sidebar-bg, #0f172a); display: flex; flex-direction: column; border-right: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; }
        .logo-box { width: 48px; height: 48px; background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 2px; }
        .sidebar-brand { font-size: 1.15rem; font-weight: 700; color: white; }
        .sidebar-nav { flex: 1; padding: 20px 12px; overflow-y: auto; min-height: 0; }
        .nav-section-label { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; padding: 0 8px; margin: 16px 0 8px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: var(--text-secondary); text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.06); color: var(--text-primary); }
        .nav-link.active { background: rgba(201,162,39,0.15); color: var(--accent-gold); border-left: 3px solid var(--accent-gold); padding-left: 9px; }
        .nav-link i { width: 24px; text-align: center; font-size: 18px; }
        .nav-badge { margin-left: auto; background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 20px; min-width: 20px; text-align: center; }
        .sidebar-bottom { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.05); }
        .logout-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #ef4444; text-decoration: none; border-radius: 8px; font-weight: 500; }
        .logout-link:hover { background: rgba(239,68,68,0.1); }
        .logout-link i { width: 24px; text-align: center; font-size: 18px; }

        /* MAIN */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,0.05); gap: 16px; }
        .topbar h1 { font-size: 1.25rem; }
        .user-avatar { width: 36px; height: 36px; background: #2d3f55; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .content { padding: 28px 32px; overflow-y: auto; }

        .card { background: var(--card-bg); border-radius: 14px; padding: 24px; border: 1px solid rgba(255,255,255,0.04); }

        /* STATS */
        .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--card-bg); border: 1px solid rgba(255,255,255,0.04); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 0.75rem; color: var(--text-secondary); margin-top: 3px; }

        /* FILTERS */
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        .tabs { display: flex; flex-wrap: wrap; gap: 8px; }
        .tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); background: rgba(255,255,255,0.04); text-decoration: none; border: 1px solid transparent; transition: all 0.15s; }
        .tab:hover { background: rgba(255,255,255,0.08); color: var(--text-primary); }
        .tab.active { background: rgba(201,162,39,0.15); color: var(--accent-gold); border-color: rgba(201,162,39,0.4); }
        .tab .count { background: rgba(255,255,255,0.1); padding: 1px 7px; border-radius: 999px; font-size: 0.7rem; }
        .search-form { display: flex; gap: 8px; }
        .search-form input { background: #0a1628; border: 1px solid rgba(255,255,255,0.08); border-radius: 9px; padding: 9px 13px; color: var(--text-primary); font-family: inherit; font-size: 0.85rem; min-width: 230px; }
        .search-form input:focus { outline: none; border-color: var(--accent-gold); }
        .search-form button { background: var(--accent-gold); border: none; color: #fff; padding: 0 15px; border-radius: 9px; cursor: pointer; }

        .filter-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: -4px 0 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); }
        .filter-label { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; }
        .chip { display: inline-flex; align-items: center; gap: 7px; padding: 7px 13px; border-radius: 9px; font-size: 0.78rem; font-weight: 600; color: var(--text-secondary); background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07); text-decoration: none; transition: all 0.15s; white-space: nowrap; }
        .chip:hover { background: rgba(255,255,255,0.09); color: var(--text-primary); }
        .chip.active { background: rgba(59,130,246,0.15); color: #93c5fd; border-color: rgba(59,130,246,0.45); }
        .chip .count { background: rgba(255,255,255,0.1); padding: 1px 7px; border-radius: 999px; font-size: 0.7rem; }
        .reset-filters { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; font-weight: 600; color: var(--text-muted); text-decoration: none; padding: 6px 10px; border-radius: 8px; }
        .reset-filters:hover { color: var(--accent-red); background: rgba(239,68,68,0.1); }
        .result-line { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 12px; }

        /* TABLE */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead th { font-size: 0.72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; padding: 0 12px 12px 0; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); white-space: nowrap; }
        tbody tr { border-bottom: 1px solid rgba(255,255,255,0.03); }
        tbody tr:last-child { border-bottom: none; }
        tbody td { padding: 14px 12px 14px 0; font-size: 0.85rem; color: var(--text-primary); vertical-align: middle; }
        .cell-id { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 0.78rem; color: var(--text-secondary); white-space: nowrap; }
        .cell-name { font-weight: 600; }
        .cell-sub { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }
        .cell-filed { display: flex; flex-direction: column; }
        .cell-filed .cell-date { font-weight: 500; white-space: nowrap; }
        .cell-filed .cell-time { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; white-space: nowrap; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }

        .row-actions { display: flex; flex-wrap: wrap; gap: 7px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 12px; border-radius: 8px; border: none; font-size: 0.75rem; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
        .btn:hover { filter: brightness(1.15); }
        .btn-view { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .btn-approve { background: var(--accent-green); color: #fff; }
        .btn-reject { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .btn-claim { background: var(--accent-blue); color: #fff; }
        .btn-ghost { background: rgba(255,255,255,0.07); color: var(--text-secondary); }
        .btn-lg { padding: 11px 20px; font-size: 0.85rem; border-radius: 10px; }

        .empty { text-align: center; padding: 56px 20px; color: var(--text-muted); }
        .empty i { font-size: 40px; display: block; margin-bottom: 14px; opacity: 0.5; }

        .flash { padding: 14px 16px; border-radius: 11px; margin-bottom: 20px; font-size: 0.88rem; font-weight: 500; display: flex; gap: 10px; align-items: flex-start; line-height: 1.5; }
        .flash.success { background: rgba(16,185,129,0.12); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.3); }
        .flash.error   { background: rgba(239,68,68,0.12); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .flash.info    { background: rgba(59,130,246,0.12); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); }

        /* MODALS */
        .modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(2,6,23,0.75); padding: 20px; overflow-y: auto; }
        .modal.open { display: flex; align-items: flex-start; justify-content: center; }
        .modal-box { background: var(--card-bg); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; width: 100%; max-width: 760px; margin: auto; box-shadow: 0 24px 60px rgba(0,0,0,0.5); animation: rise 0.2s ease; }
        .modal-box.narrow { max-width: 480px; }
        @keyframes rise { from { transform: translateY(14px); opacity: 0; } to { transform: none; opacity: 1; } }
        .modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 22px 24px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .modal-head h3 { font-size: 1.05rem; }
        .modal-head .sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 4px; }
        .modal-close { background: none; border: none; color: var(--text-secondary); font-size: 22px; cursor: pointer; line-height: 1; padding: 0 4px; }
        .modal-close:hover { color: var(--text-primary); }
        .modal-body { padding: 22px 24px; max-height: 62vh; overflow-y: auto; }
        .modal-foot { padding: 16px 24px 22px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-field label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-field input, .form-field textarea {
            width: 100%; padding: 10px 12px; border: 1px solid rgba(255,255,255,0.1); border-radius: 9px;
            background: #0a1628; color: var(--text-primary); font-family: inherit; font-size: 0.87rem;
        }
        .form-field input:focus, .form-field textarea:focus { outline: none; border-color: var(--accent-gold); }
        .form-field textarea { resize: vertical; min-height: 92px; }
        .form-hint { font-size: 0.73rem; color: var(--text-muted); margin-top: 6px; line-height: 1.5; }

        .mobile-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1100; background: var(--accent-gold); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .mobile-toggle i { font-size: 20px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        body.sidebar-open .sidebar-overlay { display: block; }
        body.sidebar-open .mobile-toggle { display: none !important; }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; left: 0; top: 0; bottom: 0; height: 100%; z-index: 1050; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.active { transform: translateX(0) !important; }
            .sidebar .sidebar-logo { padding-top: 65px; }
            .mobile-toggle { display: block; }
            .topbar { padding: 15px; flex-direction: column; align-items: stretch; gap: 14px; }
            .content { padding: 20px; }
        }
        @media (max-width: 860px) {
            .form-row { grid-template-columns: 1fr; }
            .modal-body { max-height: none; }
            .modal { padding: 0; }
            .modal-box { border-radius: 0; min-height: 100%; }
        }
        @media (max-width: 768px) {
            .toolbar { flex-direction: column; align-items: stretch; }
            .search-form input { min-width: 0; flex: 1; }
            .tabs, .chips { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
            .tab, .chip { flex-shrink: 0; }
            .filter-row { flex-wrap: nowrap; }
            .filter-label { display: none; }
            .reset-filters { margin-left: 0; flex-shrink: 0; }
            table thead { display: none; }
            table tbody tr { display: block; margin-bottom: 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 8px 10px; }
            table tbody td { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; border: none; padding: 8px 4px; }
            table tbody td::before { content: attr(data-label); font-weight: 700; color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; flex-shrink: 0; }
            table tbody td > * { text-align: right; }
            table tbody td[data-label="Filed"] .cell-filed { align-items: flex-end; }
            .row-actions { justify-content: flex-end; }
        }
    </style>
    <link rel="stylesheet" href="request_form.css?v=4">
    <link rel="stylesheet" href="request_viewer.css?v=2">
    <link rel="stylesheet" href="registrar_mobile.css?v=2">
    <link rel="stylesheet" href="mobile_fixes.css?v=1">
    <link rel="stylesheet" href="ui_dialog.css?v=1">
    <script src="ui_dialog.js?v=1" defer></script>
</head>
<body>
    <header class="mobile-appbar">
        <button class="mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <a href="registrar_dashboard.php" class="mobile-appbar-brand">
            <img src="logo.jpg" alt="">
            <span>Registrar</span>
        </a>
    </header>
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box"><img src="logo.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;"></div>
            <span class="sidebar-brand">Registrar</span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="registrar_dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="analytics.php" class="nav-link"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="calendar.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendar</a>
            <?= submissionsNavLink($mysqli) ?>
            <a href="reject.php" class="nav-link"><i class="fas fa-times-circle"></i> Rejected Documents</a>
            <a href="registrar_requests.php" class="nav-link active"><i class="fas fa-file-signature"></i> Document Requests <?= $pending_requests_count > 0 ? '<span class="nav-badge">'.$pending_requests_count.'</span>' : '' ?></a>
            <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications <?= $pending_count > 0 ? '<span class="nav-badge">'.$pending_count.'</span>' : '' ?></a>
            <a href="archive.php" class="nav-link"><i class="fas fa-archive"></i> Archived Students</a>
            <a href="typesofdocuments.php" class="nav-link"><i class="fas fa-folder-open"></i> Document Types</a>
            <a href="users_approved.php" class="nav-link"><i class="fas fa-user-check"></i> Student Approvals <?= $pending_students_count > 0 ? '<span class="nav-badge">'.$pending_students_count.'</span>' : '' ?></a>
            <a href="registrar_settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <div>
                <h1>Document Requests</h1>
                <p style="font-size:0.8rem; color:var(--text-secondary)">
                    Forms filed online by <?= htmlspecialchars($user_institution ?: 'all') ?> students — approve them and set when the document may be claimed.
                </p>
            </div>
            <div style="display:flex; align-items:center; gap:12px">
                <div style="text-align:right">
                    <div style="font-size:0.85rem; font-weight:600"><?= htmlspecialchars($full_name) ?></div>
                    <div style="font-size:0.7rem; color:var(--text-muted)">Registrar<?= $user_institution ? ' · ' . htmlspecialchars($user_institution) : '' ?></div>
                </div>
                <div class="user-avatar"><?= $initials ?></div>
            </div>
        </div>

        <div class="content">
            <?php if ($flash): ?>
                <div class="flash <?= htmlspecialchars(in_array($flash_type, ['success','error','info'], true) ? $flash_type : 'success') ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-circle-exclamation' : ($flash_type === 'info' ? 'fa-circle-info' : 'fa-circle-check') ?>" style="margin-top:2px"></i>
                    <span><?= htmlspecialchars($flash) ?></span>
                </div>
            <?php endif; ?>

            <div class="stat-cards">
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.15); color:var(--accent-orange)"><i class="fas fa-hourglass-half"></i></div>
                    <div><div class="stat-value"><?= $counts['pending'] ?></div><div class="stat-label">Waiting for review</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.15); color:var(--accent-green)"><i class="fas fa-circle-check"></i></div>
                    <div><div class="stat-value"><?= $counts['approved'] ?></div><div class="stat-label">Ready for claim</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(59,130,246,0.15); color:var(--accent-blue)"><i class="fas fa-calendar-day"></i></div>
                    <div><div class="stat-value"><?= $due_today ?></div><div class="stat-label">Due to be claimed today</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(139,92,246,0.15); color:var(--accent-purple)"><i class="fas fa-hand-holding-heart"></i></div>
                    <div><div class="stat-value"><?= $counts['claimed'] ?></div><div class="stat-label">Already claimed</div></div>
                </div>
            </div>

            <div class="card">
                <div class="toolbar">
                    <div class="tabs">
                        <?php
                        $tabs = [
                            'pending'   => ['Pending', 'fa-hourglass-half'],
                            'approved'  => ['Ready for claim', 'fa-circle-check'],
                            'claimed'   => ['Claimed', 'fa-hand-holding-heart'],
                            'rejected'  => ['Not approved', 'fa-circle-xmark'],
                            'cancelled' => ['Cancelled', 'fa-ban'],
                            'all'       => ['All', 'fa-layer-group'],
                        ];
                        foreach ($tabs as $key => [$label, $icon]):
                            $url = $filter_link(['status' => $key]);
                        ?>
                            <a class="tab <?= $status_filter === $key ? 'active' : '' ?>" href="<?= htmlspecialchars($url) ?>">
                                <i class="fas <?= $icon ?>"></i> <?= $label ?>
                                <span class="count"><?= $counts[$key] ?? 0 ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <form class="search-form" method="GET" action="registrar_requests.php">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <input type="hidden" name="form" value="<?= htmlspecialchars($form_filter) ?>">
                        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, student ID or #reference">
                        <button type="submit" aria-label="Search"><i class="fas fa-search"></i></button>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-ghost" href="<?= htmlspecialchars($filter_link(['q' => ''])) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (count($form_options) > 1): ?>
                <div class="filter-row">
                    <span class="filter-label"><i class="fas fa-filter"></i> Form</span>
                    <div class="chips">
                        <a class="chip <?= $form_filter === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars($filter_link(['form' => 'all'])) ?>">
                            <i class="fas fa-layer-group"></i> All forms
                            <span class="count"><?= (int)($form_counts['all'] ?? 0) ?></span>
                        </a>
                        <?php foreach ($form_options as $ft => $opt): ?>
                            <a class="chip <?= $form_filter === $ft ? 'active' : '' ?>" href="<?= htmlspecialchars($filter_link(['form' => $ft])) ?>">
                                <i class="fas <?= htmlspecialchars($opt['icon']) ?>"></i> <?= htmlspecialchars($opt['label']) ?>
                                <span class="count"><?= (int)($form_counts[$ft] ?? 0) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($filters_on): ?>
                        <a class="reset-filters" href="registrar_requests.php"><i class="fas fa-rotate-left"></i> Reset</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="result-line">
                    <?= count($requests) ?> <?= count($requests) === 1 ? 'request' : 'requests' ?>
                    <?php if ($form_filter !== 'all'): ?>
                        &middot; <?= htmlspecialchars($form_options[$form_filter]['label'] ?? $form_filter) ?>
                    <?php endif; ?>
                    <?php if ($search !== ''): ?>
                        &middot; matching &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
                    <?php endif; ?>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="empty">
                        <i class="fas fa-inbox"></i>
                        <div style="font-weight:600; color:var(--text-secondary)">Nothing here</div>
                        <div style="font-size:0.82rem; margin-top:6px">
                            <?php if ($search !== ''): ?>
                                No request matches &ldquo;<?= htmlspecialchars($search) ?>&rdquo;<?= $form_filter !== 'all' ? ' in ' . htmlspecialchars($form_options[$form_filter]['label'] ?? $form_filter) : '' ?>.
                            <?php elseif ($form_filter !== 'all'): ?>
                                No <?= htmlspecialchars($form_options[$form_filter]['label'] ?? $form_filter) ?> request with this status yet.
                            <?php else: ?>
                                No requests with this status yet.
                            <?php endif; ?>
                        </div>
                        <?php if ($filters_on): ?>
                            <a class="btn btn-ghost btn-lg" style="margin-top:16px" href="registrar_requests.php"><i class="fas fa-rotate-left"></i> Reset filters</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Student</th>
                                    <th>Form</th>
                                    <th>Filed</th>
                                    <th>Status</th>
                                    <th>Claim schedule</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $r):
                                $m = requestStatusMeta($r['status']);
                                $is_today = $r['status'] === 'approved' && !empty($r['claim_at']) && date('Y-m-d', strtotime($r['claim_at'])) === date('Y-m-d');
                            ?>
                                <tr>
                                    <td data-label="Student ID"><span class="cell-id"><?= htmlspecialchars(formatStudentId($r['student_id'])) ?></span></td>
                                    <td data-label="Student">
                                        <div>
                                            <div class="cell-name"><?= htmlspecialchars(formatPersonNameRow($r)) ?></div>
                                            <div class="cell-sub"><?= htmlspecialchars($r['course'] ?: '—') ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Form">
                                        <div>
                                            <div><?= htmlspecialchars($r['form_title']) ?></div>
                                            <div class="cell-sub">#<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Filed">
                                        <div class="cell-filed">
                                            <div class="cell-date"><?= !empty($r['submitted_at']) ? date('M j, Y', strtotime($r['submitted_at'])) : '—' ?></div>
                                            <?php if (!empty($r['submitted_at'])): ?>
                                                <div class="cell-sub cell-time"><i class="far fa-clock"></i> <?= date('g:i A', strtotime($r['submitted_at'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge" style="background: <?= $m['color'] ?>26; color: <?= $m['color'] ?>;">
                                            <i class="fas <?= $m['icon'] ?>"></i> <?= htmlspecialchars($m['label']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Claim schedule">
                                        <div>
                                            <?php if (!empty($r['claimed_at'])): ?>
                                                <div>Released <?= formatClaimSchedule($r['claimed_at']) ?></div>
                                                <div class="cell-sub">To <?= htmlspecialchars($r['claimed_by_name'] ?: formatPersonNameRow($r)) ?></div>
                                            <?php elseif (!empty($r['claim_at'])): ?>
                                                <div><?= formatClaimSchedule($r['claim_at']) ?></div>
                                                <div class="cell-sub"><?= $is_today ? '<span style="color:var(--accent-blue); font-weight:700">Today</span>' : htmlspecialchars($r['claim_location'] ?: REQUEST_DEFAULT_CLAIM_LOCATION) ?></div>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted)">Not scheduled</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="row-actions">
                                            <button class="btn btn-view" onclick="openView(<?= (int)$r['id'] ?>)"><i class="fas fa-eye"></i> View</button>
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <button class="btn btn-approve" onclick="openApprove(<?= (int)$r['id'] ?>, 'approve')"><i class="fas fa-check"></i> Approve</button>
                                                <button class="btn btn-reject" onclick="openReject(<?= (int)$r['id'] ?>)"><i class="fas fa-xmark"></i> Reject</button>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                                <button class="btn btn-claim" onclick="openClaim(<?= (int)$r['id'] ?>)"><i class="fas fa-hand-holding-heart"></i> Mark claimed</button>
                                                <button class="btn btn-ghost" onclick="openApprove(<?= (int)$r['id'] ?>, 'reschedule')"><i class="fas fa-calendar-day"></i> Reschedule</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Approve / reschedule ── -->
    <div class="modal" id="approveModal" onclick="if (event.target === this) closeModal('approveModal')">
        <div class="modal-box narrow">
            <form method="POST" action="process_request_status.php">
                <div class="modal-head">
                    <div>
                        <h3 id="approveTitle">Approve request</h3>
                        <div class="sub" id="approveSub"></div>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('approveModal')" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="approveId">
                    <input type="hidden" name="action" id="approveAction" value="approve">
                    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                    <div class="form-row">
                        <div class="form-field">
                            <label for="claimDate">Claim date</label>
                            <input type="date" name="claim_date" id="claimDate" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-field">
                            <label for="claimTime">Claim time</label>
                            <input type="time" name="claim_time" id="claimTime" value="09:00" required>
                        </div>
                    </div>
                    <div class="form-field" style="margin-bottom:14px">
                        <label for="claimLocation">Where to claim it</label>
                        <input type="text" name="claim_location" id="claimLocation" maxlength="255" value="<?= htmlspecialchars(REQUEST_DEFAULT_CLAIM_LOCATION) ?>">
                    </div>
                    <div class="form-field">
                        <label for="approveNote">Note to the student <span style="font-weight:400; color:var(--text-muted)">(optional)</span></label>
                        <textarea name="registrar_note" id="approveNote" maxlength="1000" placeholder="e.g. Bring your school ID and the official receipt."></textarea>
                        <div class="form-hint">The student is notified by email and in the portal with this schedule.</div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost btn-lg" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn btn-approve btn-lg" id="approveSubmit"><i class="fas fa-check"></i> Approve &amp; set schedule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Reject ── -->
    <div class="modal" id="rejectModal" onclick="if (event.target === this) closeModal('rejectModal')">
        <div class="modal-box narrow">
            <form method="POST" action="process_request_status.php">
                <div class="modal-head">
                    <div>
                        <h3>Do not approve this request</h3>
                        <div class="sub" id="rejectSub"></div>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('rejectModal')" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="rejectId">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <div class="form-field">
                        <label for="rejectNote">Reason <span style="color:var(--accent-red)">*</span></label>
                        <textarea name="registrar_note" id="rejectNote" minlength="<?= REQUEST_MIN_NOTE_LENGTH ?>" maxlength="1000" required
                                  placeholder="Tell the student exactly what is missing so they can fix it and file again."></textarea>
                        <div class="form-hint">This is sent to the student by email and shown on their Requests page.</div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost btn-lg" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-reject btn-lg"><i class="fas fa-xmark"></i> Send and close request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Mark as claimed ── -->
    <div class="modal" id="claimModal" onclick="if (event.target === this) closeModal('claimModal')">
        <div class="modal-box narrow">
            <form method="POST" action="process_request_status.php">
                <div class="modal-head">
                    <div>
                        <h3>Record the release</h3>
                        <div class="sub" id="claimSub"></div>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('claimModal')" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="claimId">
                    <input type="hidden" name="action" value="claim">
                    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <div class="form-row">
                        <div class="form-field">
                            <label for="claimedDate">Date released</label>
                            <input type="date" name="claimed_date" id="claimedDate" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-field">
                            <label for="claimedTime">Time released</label>
                            <input type="time" name="claimed_time" id="claimedTime" required>
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="claimedBy">Received by</label>
                        <input type="text" name="claimed_by_name" id="claimedBy" maxlength="191">
                        <div class="form-hint">Defaults to the student. Change it if someone else picked the document up on their behalf.</div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost btn-lg" onclick="closeModal('claimModal')">Cancel</button>
                    <button type="submit" class="btn btn-claim btn-lg"><i class="fas fa-hand-holding-heart"></i> Mark as claimed</button>
                </div>
            </form>
        </div>
    </div>

<script src="request_form.js?v=1"></script>
<script src="request_viewer.js?v=3"></script>
<script>
const REQUESTS = <?= json_encode($modal_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal.open')) document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(m => closeModal(m.id));
});

/* The filled form opens in the shared viewer (request_viewer.js), the same one
   the student's Requests page uses, so the sheet and its zoom, print and
   download controls behave identically on both. The decisions that belong to
   the registrar are passed in as footer actions. */
function openView(id) {
    const r = REQUESTS[id];
    if (!r) return;

    const actions = [];
    if (r.status === 'pending') {
        actions.push({ label: ' Reject', icon: 'fa-xmark', className: 'rv-btn-reject', onClick: () => openReject(r.id) });
        actions.push({ label: ' Approve & set schedule', icon: 'fa-check', className: 'rv-btn-approve', onClick: () => openApprove(r.id, 'approve') });
    } else if (r.status === 'approved') {
        actions.push({ label: ' Reschedule', icon: 'fa-calendar-day', onClick: () => openApprove(r.id, 'reschedule') });
        actions.push({ label: ' Mark as claimed', icon: 'fa-hand-holding-heart', className: 'rv-btn-claim', onClick: () => openClaim(r.id) });
    }

    openRequestForm({
        id: r.id,
        title: r.formTitle,
        subtitle: r.ref + ' · ' + r.studentName + ' (' + r.studentId + ') · Filed ' + r.submittedAt,
        status: { label: r.statusLabel, color: r.statusColor },
        theme: 'dark',
        actions: actions
    });
}

/** Today + n days as YYYY-MM-DD, used to suggest a sensible claim date. */
function inDays(n) {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function openApprove(id, mode) {
    const r = REQUESTS[id];
    if (!r) return;

    const rescheduling = mode === 'reschedule';
    document.getElementById('approveTitle').textContent = rescheduling ? 'Move the claim schedule' : 'Approve request';
    document.getElementById('approveSub').textContent = r.ref + ' · ' + r.studentName;
    document.getElementById('approveId').value = id;
    document.getElementById('approveAction').value = rescheduling ? 'reschedule' : 'approve';
    document.getElementById('approveSubmit').innerHTML = rescheduling
        ? '<i class="fas fa-calendar-day"></i> Save new schedule'
        : '<i class="fas fa-check"></i> Approve &amp; set schedule';

    // Rescheduling starts from what was set; a new approval suggests three days out.
    document.getElementById('claimDate').value = rescheduling && r.claimDate ? r.claimDate : inDays(3);
    document.getElementById('claimTime').value = r.claimTime || '09:00';
    document.getElementById('claimLocation').value = r.claimLocation;
    document.getElementById('approveNote').value = '';

    openModal('approveModal');
}

function openReject(id) {
    const r = REQUESTS[id];
    if (!r) return;
    document.getElementById('rejectSub').textContent = r.ref + ' · ' + r.studentName;
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectNote').value = '';
    openModal('rejectModal');
}

function openClaim(id) {
    const r = REQUESTS[id];
    if (!r) return;
    const now = new Date();
    document.getElementById('claimSub').textContent = r.ref + ' · ' + r.studentName;
    document.getElementById('claimId').value = id;
    document.getElementById('claimedDate').value = inDays(0);
    document.getElementById('claimedTime').value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    document.getElementById('claimedBy').value = r.studentName;
    openModal('claimModal');
}

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('registrar-theme-color');
    if (savedTheme) document.documentElement.style.setProperty('--accent-gold', savedTheme);
    const savedBg = localStorage.getItem('registrar-bg-color');
    if (savedBg) document.documentElement.style.setProperty('--main-bg', savedBg);
    const savedSidebarBg = localStorage.getItem('registrar-sidebar-bg');
    if (savedSidebarBg) document.documentElement.style.setProperty('--sidebar-bg', savedSidebarBg);
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
