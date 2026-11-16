<?php
/**
 * Mipangilio ya jumla ya mfumo.
 *
 * SIRI ZA MAZINGIRA (database, SMTP, APP_URL) ziwe kwenye config.local.php,
 * ambayo haipakiwi kwenye Git. Nakili config.example.php kuwa config.local.php
 * na ujaze thamani halisi. Bila faili hilo, chaguo-msingi za development
 * (hapa chini) hutumika.
 */
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

// ===== Mipangilio ya database (chaguo-msingi cha development) =====
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'matokeo_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

if (!defined('APP_NAME')) define('APP_NAME', 'Operations & Academic School Information (OASIS)');
if (!defined('TIMEZONE')) define('TIMEZONE', 'Africa/Dar_es_Salaam');
// define('BASE_URL', '/matokeo');  // Washa tu kama viungo havifanyi kazi (kwa kawaida hugunduliwa kiotomatiki)

// ===== Barua pepe (kwa "Umesahau nenosiri") - YOTE HIARI =====
// Weka hizi kwenye config.local.php, sio hapa:
// APP_URL, MAIL_FROM, MAIL_FROM_NAME, SMTP_HOST, SMTP_PORT, SMTP_SECURE,
// SMTP_USER, SMTP_PASS, MAIL_LOG
