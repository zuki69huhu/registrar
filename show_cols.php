<?php
require 'db.php';
$res = $mysqli->query('SHOW COLUMNS FROM users');
while($row = $res->fetch_assoc()) echo $row['Field'] . ' - ' . $row['Type'] . "\n";
