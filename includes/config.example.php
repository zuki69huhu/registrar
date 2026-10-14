<?php
// Copy this file to includes/config.php and fill in the values for this machine.
// includes/config.php is ignored by git, so real passwords are never committed.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');            // default XAMPP root has no password
define('DB_NAME', 'registrar');

// SMTP used for OTPs and notification e-mails
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_USER', 'user@example.com');
define('SMTP_PASS', 'app-password-or-api-key');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');     // 'tls' or 'ssl'

define('FROM_EMAIL', 'registrar@example.com');
define('FROM_NAME', 'REGISTRAR Office');
