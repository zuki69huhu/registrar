<?php
require __DIR__ . '/../includes/db.php';
$mysqli->query("ALTER TABLE users ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
echo "Done";
