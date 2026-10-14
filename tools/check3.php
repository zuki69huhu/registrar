<?php
require __DIR__ . '/../includes/db.php';
$res = $mysqli->query('SELECT id, role, email, institution, course FROM users ORDER BY id DESC LIMIT 5');
while($row = $res->fetch_assoc()){
    print_r($row);
}
