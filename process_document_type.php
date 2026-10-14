<?php
session_start();
require_once 'db.php';
require_once 'audit_log_helper.php';

// Access control: Only allow users with the 'registrar' role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    $_SESSION['flash'] = 'Access denied. You must be a registrar to manage document types.';
    header("Location: index.php?tab=login");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');

    switch ($action) {
        case 'add':
            if (empty($name)) {
                $_SESSION['flash'] = 'Document type name cannot be empty.';
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt = $mysqli->prepare("INSERT INTO document_types (name) VALUES (?)");
            if (!$stmt) {
                $_SESSION['flash'] = "Database error: " . $mysqli->error;
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                logAudit($mysqli, $_SESSION['user_id'] ?? null, 'DOCUMENT_TYPE_ADDED', "Added required document type '{$name}'.");
                $_SESSION['flash'] = "Document type '{$name}' added successfully!";
            } else {
                if ($mysqli->errno == 1062) { // Duplicate entry error
                    $_SESSION['flash'] = "Error: Document type '{$name}' already exists.";
                } else {
                    $_SESSION['flash'] = "Error adding document type: " . $stmt->error;
                }
            }
            $stmt->close();
            break;

        case 'update':
            if (!$id || empty($name)) {
                $_SESSION['flash'] = 'Invalid request for update.';
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt = $mysqli->prepare("UPDATE document_types SET name = ? WHERE id = ?");
            if (!$stmt) {
                $_SESSION['flash'] = "Database error: " . $mysqli->error;
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt->bind_param("si", $name, $id);
            if ($stmt->execute()) {
                logAudit($mysqli, $_SESSION['user_id'] ?? null, 'DOCUMENT_TYPE_UPDATED', "Renamed document type #{$id} to '{$name}'.");
                $_SESSION['flash'] = "Document type '{$name}' updated successfully!";
            } else {
                if ($mysqli->errno == 1062) { // Duplicate entry error
                    $_SESSION['flash'] = "Error: Document type '{$name}' already exists.";
                } else {
                    $_SESSION['flash'] = "Error updating document type: " . $stmt->error;
                }
            }
            $stmt->close();
            break;

        case 'delete':
            if (!$id) {
                $_SESSION['flash'] = 'Invalid request for delete.';
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt = $mysqli->prepare("DELETE FROM document_types WHERE id = ?");
            if (!$stmt) {
                $_SESSION['flash'] = "Database error: " . $mysqli->error;
                header("Location: typesofdocuments.php");
                exit;
            }
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                logAudit($mysqli, $_SESSION['user_id'] ?? null, 'DOCUMENT_TYPE_DELETED', "Deleted document type #{$id}.");
                $_SESSION['flash'] = "Document type deleted successfully!";
            } else {
                $_SESSION['flash'] = "Error deleting document type: " . $stmt->error;
            }
            $stmt->close();
            break;

        default:
            $_SESSION['flash'] = 'Unknown action.';
            break;
    }
} else {
    $_SESSION['flash'] = 'Invalid request method.';
}

header("Location: typesofdocuments.php");
exit;
?>
