<?php
require_once __DIR__ . '/config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    die("DB connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

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
