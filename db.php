<?php
require_once __DIR__ . '/config.php';

// Philippine time everywhere. php.ini says Europe/Berlin, so without this every
// date() and every "x hours ago" was six hours off from what MySQL stored.
date_default_timezone_set('Asia/Manila');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    die("DB connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');
// NOW() / CURRENT_TIMESTAMP in Philippine time too, whatever the machine's clock zone.
try { $mysqli->query("SET time_zone = '+08:00'"); } catch (Exception $e) {}

// users.is_active / is_archived are read by the sign-out check below and by process_login.php,
// so add them before anything selects them (check first: an ALTER on every request is costly).
try {
    $have_cols = [];
    $cols_res = $mysqli->query("SHOW COLUMNS FROM users WHERE Field IN ('is_active', 'is_archived')");
    while ($cols_res && ($col = $cols_res->fetch_assoc())) $have_cols[$col['Field']] = true;
    if (empty($have_cols['is_active']))   $mysqli->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1");
    if (empty($have_cols['is_archived'])) $mysqli->query("ALTER TABLE users ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}

// A signed-in account that has been deactivated or archived is signed out on its
// next request, not only at its next login.
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    try {
        $active_stmt = $mysqli->prepare("SELECT is_active, is_archived, role FROM users WHERE id = ?");
        $active_stmt->bind_param("i", $_SESSION['user_id']);
        $active_stmt->execute();
        $active_row = $active_stmt->get_result()->fetch_assoc();
        $active_stmt->close();
        if ($active_row && $active_row['role'] !== 'systemadmin'
            && ((int)$active_row['is_active'] === 0 || (int)$active_row['is_archived'] === 1)) {
            $_SESSION = ['flash' => 'Your account has been ' . ((int)$active_row['is_active'] === 0 ? 'deactivated' : 'archived')
                                  . '. Please contact the registrar office.'];
            header("Location: index.php?tab=login");
            exit;
        }
    } catch (Exception $e) {}
}

// Auto-migrate
try { $mysqli->query("ALTER TABLE notifications ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY"); } catch (Exception $e) {}
try { $mysqli->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}

// Who reviewed a document and when. Documents are reviewed by a registrar only;
// there is no automatic approval, so these stay NULL while a document is pending.
try { $mysqli->query("ALTER TABLE documents ADD COLUMN reviewed_by INT NULL"); } catch (Exception $e) {}
try { $mysqli->query("ALTER TABLE documents ADD COLUMN reviewed_at DATETIME NULL"); } catch (Exception $e) {}

// Auto-create Admin Account if not exists
try {
    $admin_email = 'Admin';
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $admin_email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $hash = password_hash('adminmcnp-isap', PASSWORD_DEFAULT);
            $role = 'systemadmin';
            $verified = 2;
            $insert = $mysqli->prepare("INSERT INTO users (first_name, last_name, email, password, role, is_verified) VALUES ('System', 'Admin', ?, ?, ?, ?)");
            if ($insert) {
                $insert->bind_param("sssi", $admin_email, $hash, $role, $verified);
                $insert->execute();
                $insert->close();
            }
        }
        $stmt->close();
    }
} catch (Exception $e) {}
