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

/**
 * The form of a name used to spot duplicates: "PSA", "psa", "P.S.A." and "P S A"
 * all reduce to "psa", so a near-copy of an existing type is refused.
 * typesofdocuments.php repeats this in JS (docTypeKey) for the instant check.
 */
function docTypeKey($name) {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
    return preg_replace('/[^a-z0-9]/', '', strtolower($ascii !== false ? $ascii : $name));
}

/** The existing type whose name matches $name's key, other than $exclude_id; null if none. */
function findSimilarDocType($mysqli, $name, $exclude_id = 0) {
    $key = docTypeKey($name);
    $res = $mysqli->query("SELECT id, name FROM document_types");
    while ($res && ($row = $res->fetch_assoc())) {
        if ((int)$row['id'] !== (int)$exclude_id && docTypeKey((string)$row['name']) === $key) {
            return $row['name'];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim(preg_replace('/\s+/u', ' ', $_POST['name'] ?? ''));

    if (($action === 'add' || $action === 'update') && $name !== '') {
        if (docTypeKey($name) === '') {
            $_SESSION['flash'] = 'A document type name must contain letters or numbers.';
            header("Location: typesofdocuments.php");
            exit;
        }
        $similar = findSimilarDocType($mysqli, $name, $action === 'update' ? $id : 0);
        if ($similar !== null) {
            $_SESSION['flash'] = "Error: '{$name}' is the same as the existing document type '{$similar}'.";
            header("Location: typesofdocuments.php");
            exit;
        }
    }

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
            $stmt = $mysqli->prepare("SELECT name FROM document_types WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$old) {
                $_SESSION['flash'] = 'That document type no longer exists.';
                break;
            }
            $old_name = (string)$old['name'];
            if ($old_name === $name) {
                $_SESSION['flash'] = "No changes made to '{$name}'.";
                break;
            }

            // Uploads point at their type by name (documents.document_type), so they are
            // renamed in the same step; otherwise they would drop out of every checklist.
            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare("UPDATE document_types SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $name, $id);
                $stmt->execute();
                $stmt->close();

                $stmt = $mysqli->prepare("UPDATE documents SET document_type = ? WHERE document_type = ?");
                $stmt->bind_param("ss", $name, $old_name);
                $stmt->execute();
                $moved = $stmt->affected_rows;
                $stmt->close();

                $mysqli->commit();
                logAudit($mysqli, $_SESSION['user_id'] ?? null, 'DOCUMENT_TYPE_UPDATED', "Renamed document type '{$old_name}' to '{$name}' ({$moved} uploaded document(s) kept under the new name).");
                $_SESSION['flash'] = "Document type renamed from '{$old_name}' to '{$name}' successfully! {$moved} uploaded document(s) kept.";
            } catch (mysqli_sql_exception $e) {
                $mysqli->rollback();
                $_SESSION['flash'] = "Error updating document type: " . $e->getMessage();
            }
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
