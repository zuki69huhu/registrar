<?php
// config.php — fill these before testing
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP root has no password
define('DB_NAME', 'registrar');

// SMTP config — replace with your SMTP credentials
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'darylsuyu02@gmail.com');      // your SMTP username
define('SMTP_PASS', 'ttrw xvcd name fukl');        // e.g., Gmail App Password or SMTP API key
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'

define('FROM_EMAIL', 'no-reply@yourdomain.com');  // what users see in "From"
define('FROM_NAME', 'REGISTRAR Office');
