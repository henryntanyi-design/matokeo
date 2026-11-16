<?php
/** Hifadhi picha ya ripoti (school logo / stamp / signature) papo hapo kupitia AJAX. */
require_once __DIR__ . '/../lib/grading.php';
require_school_admin();
header('Content-Type: application/json; charset=utf-8');

$reply = function ($ok, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
};

if (!is_post()) $reply(false, ['error' => 'Method not allowed.'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $reply(false, ['error' => 'Session expired. Refresh the page and try again.'], 419);
}

$type = (string)($_POST['type'] ?? '');
$action = (string)($_POST['action'] ?? 'upload');

if ($action === 'delete') {
    try {
        report_branding_clear(active_school_id(), $type);
        $reply(true);
    } catch (Exception $e) {
        $reply(false, ['error' => $e->getMessage()], 400);
    }
}

$file = $_FILES['file'] ?? [];
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $reply(false, ['error' => 'Please choose an image first.'], 400);
}

try {
    $school_id = active_school_id();
    report_branding_upload($school_id, $type, $file);
    $brand = report_branding_get($school_id);
    $reply(true, ['src' => report_branding_src($brand[$type] ?? '')]);
} catch (Exception $e) {
    $reply(false, ['error' => $e->getMessage()], 400);
}
