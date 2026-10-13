<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_template.php';

/**
 * Inserts a notification into the database and sends a beautifully
 * formatted HTML email to the user.
 * 
 * @param mysqli $mysqli The database connection
 * @param int $user_id The ID of the user
 * @param string $message The notification message
 * @param string $type The notification type (info, success, warning, error)
 * @param string $subject (Optional) The email subject. Defaults to "New Notification"
 * @param string $variant (Optional) 'announcement' for a registrar broadcast (see buildEmailTemplate)
 * @return bool True if notification inserted successfully, False otherwise
 */
function sendNotificationAndEmail($mysqli, $user_id, $message, $type = 'info', $subject = 'New Notification', $variant = '') {
    // 1. Insert into notifications table
    $notif_stmt = $mysqli->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)");
    if (!$notif_stmt) {
        return false;
    }
    
    $notif_stmt->bind_param("iss", $user_id, $message, $type);
    $inserted = $notif_stmt->execute();
    $notif_stmt->close();
    
    if ($inserted) {
        // 2. Fetch the user's email and name
        $user_stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email, institution FROM users WHERE id = ?");
        if ($user_stmt) {
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_data = $user_res->fetch_assoc()) {
                $email = $user_data['email'];
                
                // Build the student's full name
                $name_parts = [];
                if (!empty($user_data['first_name'])) $name_parts[] = $user_data['first_name'];
                if (!empty($user_data['middle_name'])) $name_parts[] = $user_data['middle_name'];
                if (!empty($user_data['last_name'])) $name_parts[] = $user_data['last_name'];
                $student_name = implode(' ', $name_parts) ?: 'Student';
                
                $institution = $user_data['institution'] ?? '';
                
                // Get the registrar name if one is logged in
                $registrar_name = 'Registrar Office';
                if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'registrar') {
                    $reg_stmt = $mysqli->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    if ($reg_stmt) {
                        $reg_stmt->bind_param("i", $_SESSION['user_id']);
                        $reg_stmt->execute();
                        $reg_res = $reg_stmt->get_result();
                        if ($reg_data = $reg_res->fetch_assoc()) {
                            $registrar_name = trim(($reg_data['first_name'] ?? '') . ' ' . ($reg_data['last_name'] ?? ''));
                            if (empty($registrar_name)) $registrar_name = 'Registrar Office';
                        }
                        $reg_stmt->close();
                    }
                }
                
                // 3. Build beautiful HTML email using the template
                $html_body = buildEmailTemplate(
                    $student_name,
                    $message,
                    $type,
                    $subject,
                    $registrar_name,
                    $institution,
                    $variant
                );
                
                // 4. Send email
                sendEmail($email, $subject, $html_body);
            }
            $user_stmt->close();
        }
    }
    
    return $inserted;
}
