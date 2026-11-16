<?php
/**
 * NAKILI faili hii kuwa config.local.php kisha jaza thamani halisi:
 *     cp config.example.php config.local.php
 *
 * config.local.php HAIPAKIWI kwenye Git (ipo kwenye .gitignore), hivyo
 * nenosiri halisi halitaingia GitHub. Hii ndiyo faili ya kutumia kwenye
 * server halisi na kwenye kompyuta yako ya kazi.
 *
 * Unaweza kuacha lolote ambalo huliuhitaji likiwa commented; chaguo-msingi
 * za config.php zitachukua nafasi yake.
 */

// ===== Database =====
define('DB_HOST', 'localhost');
define('DB_NAME', 'matokeo_db');
define('DB_USER', 'root');
define('DB_PASS', '');            // Weka nenosiri halisi; usiipush popote

// ===== Mfumo =====
// define('APP_NAME', 'Operations & Academic School Information (OASIS)');
// define('TIMEZONE', 'Africa/Dar_es_Salaam');
// define('BASE_URL', '/matokeo');   // Washa tu kama viungo havifanyi kazi

// ===== Barua pepe (hiari) =====
// define('APP_URL', 'http://localhost/matokeo');   // Inapendekezwa kuiweka
// define('MAIL_FROM', 'no-reply@shule.go.tz');
// define('MAIL_FROM_NAME', 'Operations & Academic School Information (OASIS)');
// define('MAIL_LOG', false);        // true = hifadhi barua kwenye storage/mail.log

// SMTP (inahitaji "composer install" kwa PHPMailer):
// define('SMTP_HOST', 'smtp.gmail.com');
// define('SMTP_PORT', 587);
// define('SMTP_SECURE', 'tls');
// define('SMTP_USER', 'jina@gmail.com');
// define('SMTP_PASS', 'app-password');
