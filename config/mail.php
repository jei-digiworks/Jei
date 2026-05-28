<?php
// config/mail.php
// Configuration for real SMTP email sending.
// Fill in your Gmail, Mailgun, or custom SMTP server details below to physically receive emails!

define('SMTP_ENABLED', true); // Set to true to enable real SMTP sending!
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587); // 587 for TLS, 465 for SSL, or 25
define('SMTP_SECURE', 'tls'); // 'tls', 'ssl', or 'none'
define('SMTP_AUTH', true);
define('SMTP_USER', 'rdgtennislesson@gmail.com');
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'YOUR_SMTP_APP_PASSWORD'); // Defined in config/credentials.php
}
define('SMTP_FROM', 'rdgtennislesson@gmail.com');
define('SMTP_FROM_NAME', 'RDG Tennis Lesson');
