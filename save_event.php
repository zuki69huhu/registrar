<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/notification_helper.php';
require_once 'includes/audit_log_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['role'] === 'registrar') {
    $title = trim($_POST['title']);
    
    // Convert datetime-local format (YYYY-MM-DDTHH:MM) to MySQL format (YYYY-MM-DD HH:MM:SS)
    // and ensure no errors occur during date parsing
    $start = date('Y-m-d H:i:s', strtotime($_POST['start_datetime']));
    $end = date('Y-m-d H:i:s', strtotime($_POST['end_datetime']));
    
    // 1. Save to events table
    $stmt = $mysqli->prepare("INSERT INTO events (title, start, end) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $title, $start, $end);
    
    if ($stmt->execute()) {
        $event_id = $stmt->insert_id;
        logAudit($mysqli, $_SESSION['user_id'] ?? null, 'EVENT_CREATED', "Created event '{$title}' ({$start} to {$end}) and notified all students.");
        
        // 2. Automatically notify ALL students
        $msg = "New Academic Event: " . $title . " scheduled on " . date('M d, Y h:i A', strtotime($start));
        $type = "info";
        
        // Get all student IDs
        $student_res = $mysqli->query("SELECT id FROM users WHERE role = 'student'");
        
        if ($student_res) {
            while ($student = $student_res->fetch_assoc()) {
                sendNotificationAndEmail($mysqli, $student['id'], $msg, $type, "New Academic Event Scheduled");
            }
        }
        
        $_SESSION['flash'] = "Event saved and students notified successfully!";
    } else {
        $_SESSION['flash'] = "Error saving event.";
    }
    $stmt->close();
}

header("Location: calendar.php");
exit;
