<?php
require_once __DIR__ . '/lib/bootstrap.php';

/* Futa session yote kabla ya kurudi kwenye ukurasa wa mwanzo. */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

/* Logout hurudisha kwenye homepage (index.php), si kwenye login page. */
header('Location: ' . url('index.php'));
exit();
