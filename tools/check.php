<?php
require __DIR__ . '/../includes/db.php';
$res = $mysqli->query('DESCRIBE users');
while($row = $res->fetch_assoc()){
    print_r($row);
}
