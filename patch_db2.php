<?php
require_once 'db.php';
if ($mysqli->query("ALTER TABLE users ADD COLUMN is_archived TINYINT(1) DEFAULT 0")) {
    echo "Added is_archived successfully.\n";
} else {
    echo "Error or already exists: " . $mysqli->error . "\n";
}
