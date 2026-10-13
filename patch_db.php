<?php
require_once 'db.php';
$mysqli->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0");
$mysqli->query("ALTER TABLE notifications ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY");
echo "Done";
