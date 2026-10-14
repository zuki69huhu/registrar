<?php
require __DIR__ . '/../includes/db.php';
$res = $mysqli->query('SHOW TABLES');
while($row = $res->fetch_array()) echo $row[0] . "\n";
