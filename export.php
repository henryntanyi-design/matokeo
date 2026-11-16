<?php
/**
 * Export: PDF / Excel (.xlsx).
 *  - type=council: matokeo ya wilaya (super tu)
 *  - wafanyakazi wa shule: matokeo ya darasa au ripoti ya mwanafunzi (shule iliyo hai)
 *  - mwanafunzi: ripoti yake tu na ikiwa shule yake imeichapisha
 */
require_once __DIR__ . '/lib/export.php';
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0); $student_id = (int)($_GET['student_id'] ?? 0);
$format = in_array($_GET['format'] ?? 'pdf', ['pdf', 'xlsx'], true) ? $_GET['format'] : 'pdf';
$rawIds = $_GET['student_ids'] ?? [];
$student_ids = array_values(array_filter(array_map('intval', is_array($rawIds) ? $rawIds : explode(',', (string)$rawIds))));
$names = !isset($_GET['names']) || $_GET['names'] !== '0';
$exam = get_exam($exam_id);
if (!$exam) { http_response_code(404); die('Mtihani haupatikani.'); }
$u = user(); $s = student();

/* ---- Matokeo ya wilaya ---- */
if (($_GET['type'] ?? '') === 'council') {
    if (!$u || $u['role'] !== 'super') deny('Ni kwa ofisi ya wilaya tu.');
    $c = compute_council($exam_id, $class_id);
    if (!$c) { http_response_code(404); die('Matokeo hayapatikani.'); }
    $base = 'council_exam' . $exam_id . '_class' . $class_id;
    if ($format === 'xlsx') send_xlsx(xlsx_council($c), $base);
    send_pdf(render_council($c), 'District Results', $base, true);
}

/* ---- Shule moja ---- */
if ($u) {
    $school_id = active_school_id();
    if (!$school_id) deny('Chagua shule kwanza.');
    if (!exam_has_school($exam_id, $school_id)) { http_response_code(404); die('Mtihani haushirikishi shule hii.'); }
    if ($u['role'] === 'teacher' && !q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ?', [$u['id'], $class_id])) deny('Huna ruhusa ya darasa hili.');
    if (!q_val('SELECT 1 FROM exam_classes WHERE exam_id = ? AND class_id = ?', [$exam_id, $class_id])) { http_response_code(404); die('Darasa si la mtihani huu.'); }
} elseif ($s) {
    $school_id = (int)$s['school_id'];
    if (!is_published($exam_id, $school_id)) deny('Matokeo bado hayajachapishwa.');
    $cand = q_one('SELECT class_id FROM exam_candidates WHERE exam_id = ? AND student_id = ?', [$exam_id, $s['id']]);
    if (!$cand) deny('Hukusajiliwa kwenye mtihani huu.');
    $student_id = (int)$s['id']; $class_id = (int)$cand['class_id'];   // mwanafunzi hawezi kuomba ripoti ya mwingine
} else redirect('login.php');

$d = compute_results($exam_id, $class_id, $school_id);
if (!$d) { http_response_code(404); die('Matokeo hayapatikani.'); }
$base = 'results_' . $d['necta_no'] . '_exam' . $exam_id . '_class' . $class_id;

if ($student_id) {
    $mine = null; foreach ($d['candidates'] as $c) if ((int)$c['student_id'] === $student_id) $mine = $c;
    if (!$mine) { http_response_code(404); die('Mwanafunzi hajasajiliwa kwenye mtihani huu.'); }
    $candNo = preg_replace('/[^A-Za-z0-9]/', '', (string)$mine['cand_no']);
    $sName = trim(preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper((string)$mine['name'])));
    $base .= '_' . $candNo;
    // Jina la faili/hati la mwanafunzi: JINA_LA_MWANAFUNZI_CAND.NO
    $fn = ($sName !== '' ? str_replace(' ', '_', $sName) . '_' : '') . $candNo;
    if ($format === 'xlsx') send_xlsx(xlsx_student($d, $mine), $fn);
    if ($format === 'html') { echo page_shell(($sName !== '' ? $sName . ' - ' : '') . 'RIPOTI YA MAENDELEO', render_student_report($d, $mine), false, false); exit(); }
    $inline = isset($_GET['inline']) && $_GET['inline'] === '1';
    send_pdf(render_student_report($d, $mine), ($sName !== '' ? $sName . ' - ' : '') . 'RIPOTI YA MAENDELEO', $fn, false, $inline);
}
if ($student_ids) {
    // Bulk: ripoti za wanafunzi walioteuliwa (PDF kwa kila mmoja, ndani ya ZIP)
    $want = array_flip($student_ids);
    $files = [];
    foreach ($d['candidates'] as $c) {
        if (!isset($want[(int)$c['student_id']])) continue;
        $nm = trim(preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper((string)$c['name'])));
        $cn = preg_replace('/[^A-Za-z0-9]/', '', (string)$c['cand_no']);
        $files[($nm !== '' ? str_replace(' ', '_', $nm) . '_' : '') . $cn] = render_student_report($d, $c);
    }
    if (!$files) { http_response_code(404); die('Hakuna wanafunzi walioteuliwa.'); }
    send_pdf_zip($files, 'Exam_Reports_' . preg_replace('/[^A-Za-z0-9]/', '_', (string)$d['class']['name']) . '_' . count($files) . '_students');
}
if ($format === 'xlsx') {
    // Jina la faili kwa muundo wa template: SHULE_WILAYA DC_MTIHANI_KIDATO_Overall Results
    $district = strtoupper(trim((string)($d['school']['district'] ?? '')));
    $fn = strtoupper(trim((string)$d['school']['school_name'])) . '_' . ($district !== '' ? $district : 'WILAYA') . ' DC_'
        . strtoupper(trim((string)$d['exam']['exam_name'])) . '_' . strtoupper(trim((string)$d['class']['name'])) . '_Overall Results';
    send_xlsx(xlsx_school($d, $names), $fn);
}
// PDF ya matokeo ya darasa: muundo rasmi wa NECTA (CSEE/ACSEE).
// Jedwali la ukurasa wa results.php na Excel hutumia muundo wa template ("Overall Results").
$centre = trim((string)($d['necta_no'] ?? ''));
$schoolName = trim((string)($d['school']['school_name'] ?? ''));
$base = ($centre !== '' ? $centre . '_' : '') . ($schoolName !== '' ? $schoolName : 'SCHOOL') . ' SECONDARY SCHOOL_RESULTS_' . date('Y');
send_pdf(render_necta_results($d), 'Exam Results', $base, true);
