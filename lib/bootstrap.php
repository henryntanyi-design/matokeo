<?php
/** Msingi wa mfumo: session, database, usalama na vitendea kazi vya pamoja. PHP 7.4+ */
require_once __DIR__ . '/../config.php';
date_default_timezone_set(TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    session_name('MATOKEOSESS');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

if (!defined('BASE_URL')) {
    $doc  = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $app  = str_replace('\\', '/', dirname(__DIR__));
    $base = ($doc !== '' && stripos($app, $doc) === 0) ? substr($app, strlen($doc)) : '';
    define('BASE_URL', rtrim($base, '/'));
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('<h3>Imeshindikana kuunganisha database</h3><p>Hakikisha MySQL imewaka, umeimport <code>database/schema.sql</code>, na mipangilio ya <code>config.php</code> ni sahihi.</p><pre>'
        . htmlspecialchars($e->getMessage()) . '</pre>');
}

/* ---------- Database helpers ---------- */
function db() { global $pdo; return $pdo; }
function q($sql, $params = []) { $st = db()->prepare($sql); $st->execute($params); return $st; }
function q_all($sql, $params = []) { return q($sql, $params)->fetchAll(); }
function q_one($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function q_val($sql, $params = []) { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

/** Angalia kama safu (column) ipo kwenye jedwali ili kuepusha SQL errors */
function db_column_exists($table, $column) {
    static $cache = [];
    $key = "$table.$column";
    if (!isset($cache[$key])) {
        try {
            $db_name = DB_NAME;
            $res = q_one("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$db_name, $table, $column]);
            $cache[$key] = ($res !== null);
        } catch (Exception $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** Pata jina sahihi la safu ya jinsia kwenye jedwali la students ('sex' au 'gender') */
function student_gender_column() {
    if (db_column_exists('students', 'sex')) return 'sex';
    if (db_column_exists('students', 'gender')) return 'gender';
    return '';
}

/* ---------- Helpers za jumla ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url($p = '') { return BASE_URL . '/' . ltrim($p, '/'); }
function redirect($p) { header('Location: ' . (preg_match('#^https?://#', $p) ? $p : url($p))); exit(); }
function is_post() { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function full_name(array $r) { return trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); }
function sex_label($s) { return strtoupper($s) === 'F' ? 'Female' : 'Male'; }

function csrf_token() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        die('Ombi si halali au session imeisha. Rudi nyuma, refresh ukurasa kisha jaribu tena.');
    }
}

function flash($type, $msg) { $_SESSION['flash'][] = [$type, $msg]; }
function flash_show() {
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $f) echo '<div class="alert alert-' . h($f[0]) . ' py-2">' . $f[1] . '</div>';
    unset($_SESSION['flash']);
}
/** Flash kama pop-up (toast) inayotokea juu ya ukurasa na kujificha yenyewe. */
function flash_toasts() {
    if (empty($_SESSION['flash'])) return;
    $ic = ['success' => 'check-circle-fill', 'danger' => 'exclamation-triangle-fill', 'warning' => 'exclamation-circle-fill', 'info' => 'info-circle-fill'];
    echo '<div class="flash-top no-print">';
    foreach ($_SESSION['flash'] as $f) {
        $t = isset($ic[$f[0]]) ? $f[0] : 'info';
        echo '<div class="flash-toast flash-' . $t . '" role="status"><span class="flash-ic"><i class="bi bi-' . $ic[$t] . '"></i></span>'
            . '<div class="flash-msg">' . $f[1] . '</div>'
            . '<button type="button" class="flash-x" aria-label="Close">&times;</button></div>';
    }
    echo '</div>';
    unset($_SESSION['flash']);
}

/* ---------- Mipangilio ya mfumo (ya pamoja) ---------- */
function setting($key, $default = '') {
    static $c = null;
    if ($c === null) {
        $c = [];
        try { foreach (q_all('SELECT setting_key, setting_value FROM settings') as $r) $c[$r['setting_key']] = $r['setting_value']; } catch (Exception $e) {}
    }
    return (isset($c[$key]) && $c[$key] !== '') ? $c[$key] : $default;
}
function set_setting($key, $value) {
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}
function system_name() { return setting('system_name', APP_NAME); }
function current_year() { return q_one('SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1'); }

/* ---------- Uthibitishaji ---------- */
function user() {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) $u = q_one('SELECT * FROM users WHERE id = ? AND active = 1', [(int)$_SESSION['uid']]);
    }
    return $u;
}
function student() {
    static $s = false;
    if ($s === false) {
        $s = null;
        if (!empty($_SESSION['sid'])) $s = q_one("SELECT st.*, c.name AS class_name, c.level FROM students st JOIN classes c ON c.id = st.class_id WHERE st.id = ? AND st.status <> 'inactive'", [(int)$_SESSION['sid']]);
    }
    return $s;
}
function is_super() { $u = user(); return $u && $u['role'] === 'super'; }
/** Nguvu za usimamizi wa shule (admin wa shule, au super akiwa ameingia kwenye shule). */
function is_admin() { $u = user(); return $u && in_array($u['role'], ['super', 'admin'], true); }
function is_teacher() { $u = user(); return $u && $u['role'] === 'teacher'; }

/** Nafasi/wadhifa wa mtumiaji ndani ya shule. */
function user_positions() {
    return [
        'teacher' => 'Teacher',
        'academic_master' => 'Academic Master',
        'second_master' => 'Second Master',
        'headmaster' => 'Headmaster',
        'admin' => 'Admin',
    ];
}
/** Wadhifa unaoonekana kwa mtumiaji (hutumia `position`, akikosa hutumia `role`). */
function user_title($u) {
    if (!$u) return '';
    if (($u['role'] ?? '') === 'super') return 'Super Admin';
    $p = (string)($u['position'] ?? '');
    $labels = user_positions();
    if ($p !== '' && isset($labels[$p])) return $labels[$p];
    return ucfirst((string)($u['role'] ?? ''));
}
/** Nafasi za uongozi zinazoingia kama admin; Teacher pekee hubaki teacher. */
function role_for_position($position) {
    return $position === 'teacher' ? 'teacher' : 'admin';
}

/** Shule inayofanyiwa kazi: admin/teacher = shule yake; super = shule aliyoingia; mwanafunzi = shule yake. */
function active_school_id() {
    $u = user();
    if ($u) return $u['role'] === 'super' ? (int)($_SESSION['active_school'] ?? 0) : (int)$u['school_id'];
    $s = student();
    return $s ? (int)$s['school_id'] : 0;
}
function school_by_id($id) {
    static $c = [];
    $id = (int)$id;
    if (!$id) return null;
    if (!array_key_exists($id, $c)) $c[$id] = q_one('SELECT * FROM schools WHERE id = ?', [$id]);
    return $c[$id];
}
function active_school() { return school_by_id(active_school_id()); }
function school_label() { 
    $s = active_school(); 
    if ($s && !empty($s['name'])) {
        $name = strtoupper(trim($s['name']));
        // Ongeza SECONDARY SCHOOL kama haipo tayari kwenye jina
        if (strpos($name, 'SECONDARY') === false) {
            return $name . ' SECONDARY SCHOOL';
        }
        return $name;
    }
    return system_name(); 
}
/** Namba ya kituo; kama haijawekwa tumia ya muda (S + id ya shule). */
function school_centre(array $school) {
    $c = strtoupper(trim((string)($school['centre_no'] ?? '')));
    return $c !== '' ? $c : 'S' . str_pad((string)$school['id'], 4, '0', STR_PAD_LEFT);
}
function enter_school($id) { $_SESSION['active_school'] = (int)$id; }
function leave_school() { unset($_SESSION['active_school']); }

function deny($msg = 'Huna ruhusa ya kuona ukurasa huu.') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:30px"><h3>403</h3><p>' . h($msg) . '</p><a href="' . h(url('index.php')) . '">Rudi mwanzo</a></div>');
}
function require_staff($roles = ['super', 'admin', 'teacher']) {
    $u = user();
    if (!$u) redirect('login.php');
    if (!empty($u['must_change']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php') { flash('warning', 'Kwa usalama, weka nenosiri lako jipya kwanza (la muda ulilopewa halitumiki tena baada ya hapa).'); redirect('profile.php?force=1'); }
    if (!in_array($u['role'], $roles, true)) deny();
    return $u;
}
function require_super() { return require_staff(['super']); }
/** Ukurasa wa ngazi ya wilaya: super tu, na hakuna shule hai (menyu ya wilaya). */
function require_district() { $u = require_staff(['super']); leave_school(); return $u; }
/** Ukurasa wa shule: lazima kuwe na shule hai (super aliyeingia au admin/teacher wa shule). */
function require_school_staff($roles = ['super', 'admin', 'teacher']) {
    $u = require_staff($roles);
    $sid = active_school_id();
    if (!$sid) {
        if ($u['role'] === 'super') { flash('info', 'Chagua shule kwanza (bonyeza "Ingia" kwenye shule).'); redirect('super/schools.php'); }
        deny('Akaunti yako haijaunganishwa na shule.');
    }
    $sc = school_by_id($sid);
    if (!$sc || !(int)$sc['active']) deny('Shule hii haipo au imezimwa.');
    return $u;
}
function require_school_admin() { return require_school_staff(['super', 'admin']); }
function require_student() {
    $s = student();
    if (!$s) redirect('login.php?t=student');
    if (!empty($s['must_change']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php') { flash('warning', 'Kwa usalama, weka PIN yako mpya kwanza.'); redirect('profile.php?force=1'); }
    return $s;
}
function home_url() {
    $u = user();
    if ($u) return $u['role'] === 'super' ? 'super/dashboard.php' : ($u['role'] === 'admin' ? 'admin/dashboard.php' : 'teacher/dashboard.php');
    if (student()) return 'student/dashboard.php';
    return 'login.php';
}

/** Je, mwalimu huyu amepangiwa darasa na somo hili? */
function teacher_has(int $teacher_id, int $class_id, int $subject_id) {
    return (bool)q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?', [$teacher_id, $class_id, $subject_id]);
}


/* ---------- Nenosiri, PIN na barua pepe ---------- */
/** Nenosiri: jina (herufi tu, hadi 12) + "@" + herufi/namba 6 za nasibu (bila zinazofanana: 0/O, 1/l/I). */
function generate_password($name = '') {
    $base = (string)$name;
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base); if ($t !== false && $t !== '') $base = $t; }
    $base = preg_replace('/[^A-Za-z]/', '', $base);
    $base = (strlen($base) < 3) ? 'Admin' : ucfirst(strtolower(substr($base, 0, 12)));
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $rand = '';
    for ($i = 0; $i < 6; $i++) $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $base . '@' . $rand;
}
function generate_pin() { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
/** Neno la mwisho la jina kamili (surname). */
function surname_of($fullName) { $p = preg_split('/\s+/', trim((string)$fullName)); return $p ? end($p) : ''; }

/** URL kamili kwa barua pepe. Tumia APP_URL kwenye config.php ili isitegemee kichwa cha ombi (Host). */
function absolute_url($path) {
    if (defined('APP_URL') && APP_URL !== '') return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host)) $host = 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

/** Tuma barua: SMTP (PHPMailer) ikiwa imewekwa, vinginevyo mail(). Ikishindwa, nakala huandikwa storage/mail.log. */
function send_mail($to, $subject, $body) {
    $to = str_replace(["\r", "\n"], '', (string)$to);
    $from = str_replace(["\r", "\n"], '', (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : 'no-reply@localhost');
    $name = str_replace(["\r", "\n"], '', defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : system_name());
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $ok = false;
    $auto = __DIR__ . '/../vendor/autoload.php';
    if (is_file($auto)) require_once $auto;
    if (defined('SMTP_HOST') && SMTP_HOST !== '' && class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP(); $m->Host = SMTP_HOST; $m->Port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            $su = defined('SMTP_USER') ? SMTP_USER : '';
            if ($su !== '') { $m->SMTPAuth = true; $m->Username = $su; $m->Password = defined('SMTP_PASS') ? SMTP_PASS : ''; }
            $sec = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
            if ($sec === 'ssl' || $sec === 'tls') $m->SMTPSecure = $sec;
            $m->CharSet = 'UTF-8'; $m->setFrom($from, $name); $m->addAddress($to); $m->isHTML(false); $m->Subject = $subject; $m->Body = $body;
            $m->send(); $ok = true;
        } catch (\Throwable $e) { $ok = false; }
    } elseif (function_exists('mail')) {
        $headers = 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        $ok = (bool)@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
    if (!$ok || (defined('MAIL_LOG') && MAIL_LOG)) {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/mail.log', '[' . date('Y-m-d H:i:s') . '] ' . ($ok ? 'SENT' : 'NOT SENT (angalia mipangilio ya barua)') . " TO: $to\nSUBJECT: $subject\n$body\n-----\n", FILE_APPEND | LOCK_EX);
    }
    return $ok;
}