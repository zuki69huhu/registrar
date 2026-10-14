<?php
require_once __DIR__ . '/db.php';

// Create the audit_logs table, or upgrade an older one (idempotent)
try {
    $mysqli->query("
    CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $audit_columns = [];
    $audit_col_res = $mysqli->query("SHOW COLUMNS FROM audit_logs");
    while ($audit_col = $audit_col_res->fetch_assoc()) {
        $audit_columns[$audit_col['Field']] = true;
    }
    if (!isset($audit_columns['target_user_id'])) {
        $mysqli->query("ALTER TABLE audit_logs
            ADD COLUMN target_user_id INT NULL AFTER user_id,
            ADD INDEX idx_audit_target (target_user_id),
            ADD INDEX idx_audit_created (created_at),
            ADD INDEX idx_audit_action (action),
            ADD CONSTRAINT fk_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL");
    }
} catch (Exception $e) {
    error_log('Audit log table setup failed: ' . $e->getMessage());
}

/**
 * Logs an action to the audit_logs table. Never throws: a logging failure must not break the action itself.
 *
 * @param mysqli   $mysqli         Database connection
 * @param int|null $user_id        User who performed the action (null when not signed in)
 * @param string   $action         UPPER_SNAKE action name (e.g. 'LOGIN', 'DOCUMENT_APPROVED')
 * @param string   $description    Human-readable details
 * @param int|null $target_user_id User affected by the action (e.g. the student whose document was approved)
 * @return bool True on success, false on failure
 */
function logAudit($mysqli, $user_id, $action, $description, $target_user_id = null) {
    try {
        $stmt = $mysqli->prepare("INSERT INTO audit_logs (user_id, target_user_id, action, description) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $user_id = $user_id === null ? null : (int)$user_id;
        $target_user_id = $target_user_id === null ? null : (int)$target_user_id;
        $stmt->bind_param("iiss", $user_id, $target_user_id, $action, $description);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Audit log failed ({$action}): " . $e->getMessage());
        return false;
    }
}
