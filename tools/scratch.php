<?php
require_once __DIR__ . '/../includes/db.php';

echo "=== TABLES ===\n";
$res = $mysqli->query('SHOW TABLES');
while($row = $res->fetch_row()) echo $row[0]."\n";

echo "\n=== DOCUMENTS TABLE ===\n";
$res = $mysqli->query('DESCRIBE documents');
while($row = $res->fetch_assoc()) echo $row['Field'].' | '.$row['Type'].' | '.$row['Null'].' | '.$row['Key']."\n";

echo "\n=== USERS TABLE ===\n";
$res = $mysqli->query('DESCRIBE users');
while($row = $res->fetch_assoc()) echo $row['Field'].' | '.$row['Type'].' | '.$row['Null'].' | '.$row['Key']."\n";

echo "\n=== NOTIFICATIONS TABLE ===\n";
$res = $mysqli->query('DESCRIBE notifications');
while($row = $res->fetch_assoc()) echo $row['Field'].' | '.$row['Type'].' | '.$row['Null'].' | '.$row['Key']."\n";

echo "\n=== DOCUMENT_TYPES ROWS ===\n";
$res = $mysqli->query('SELECT id, name FROM document_types');
while($row = $res->fetch_assoc()) echo $row['id'].' | '.$row['name']."\n";

