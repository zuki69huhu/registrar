<?php
require_once 'db.php';
header('Content-Type: application/json');

$query = "SELECT id, title, start, end FROM events";
$result = $mysqli->query($query);
$events = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
}
echo json_encode($events);
