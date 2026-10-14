<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an email using PHPMailer and SMTP settings from config.php
 * 
 * @param string $to Email address of the recipient
 * @param string $subject Subject of the email
 * @param string $body HTML body of the email
 * @return bool True on success, false on failure
 */
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;

        // Bypass SSL verification issues on local servers
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);

        // The portal's text is UTF-8; PHPMailer defaults to ISO-8859-1, which
        // garbles ñ, dashes and the like in both the subject and the body.
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        // The combined MCNP+ISAP seal. logo.jpg is really a PNG, and PHPMailer
        // takes the type from the extension, so it is embedded from a correctly
        // named copy with its type stated; otherwise Gmail may not show it.
        $email_logo = __DIR__ . '/logo_email.png';
        if (file_exists($email_logo)) {
            $mail->addEmbeddedImage($email_logo, 'logo_img', 'logo.png', 'base64', 'image/png');
            $mail->send();
            return true;
        }

        $logo_path = __DIR__ . '/isaplogo_glow.png';
        if (!file_exists($logo_path)) {
            $logo_path = __DIR__ . '/logo_glow.png';
            if (!file_exists($logo_path)) {
                $logo_path = __DIR__ . '/isaplogo.png';
                if (!file_exists($logo_path)) {
                    $logo_path = __DIR__ . '/logo.png';
                    if (!file_exists($logo_path)) {
                        $logo_path = __DIR__ . '/isaplogo.jpg';
                        if (!file_exists($logo_path)) {
                            $logo_path = __DIR__ . '/logo.jpg';
                        }
                    }
                }
            }
        }
        if (file_exists($logo_path)) {
            $mail->addEmbeddedImage($logo_path, 'logo_img');
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}
