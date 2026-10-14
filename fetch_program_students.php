<?php
session_start();
require_once 'db.php';
require_once 'name_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'systemadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['program'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Program is required']);
    exit;
}

$program = trim($_GET['program']);

    $stmt = $mysqli->prepare("
        SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, u.student_id, u.year_level, u.is_verified,
               GROUP_CONCAT(d.document_type SEPARATOR ', ') as submitted_docs
        FROM users u
        LEFT JOIN documents d ON u.id = d.user_id
        WHERE u.role = 'student' AND u.course = ?
        GROUP BY u.id
        ORDER BY u.last_name ASC, u.first_name ASC
    ");
$stmt->bind_param("s", $program);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    // Surname first, and a student number that is never blank on screen
    $row['display_id'] = formatStudentId($row['student_id'] ?? '');
    $row['display_name'] = formatPersonNameRow($row);
    $students[] = $row;
}

$stmt->close();

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'data' => $students]);
