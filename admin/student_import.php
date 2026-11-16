<?php
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

/** Safu zinazohitajika kwenye template ya OASIS (zenye rangi + PREMS_NO). */
const SIM_REQUIRED = ['PREMSNO', 'FIRSTNAME', 'LASTNAME', 'SEX', 'CLASS', 'GUARDIANNAME', 'GUARDIANPHONE'];

function sim_cell_str($v) {
    if (is_float($v) && floor($v) == $v) return (string)(int)$v;
    return trim((string)$v);
}
function sim_norm_head($v) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$v));
}
function sim_tmp_dir() {
    $d = __DIR__ . '/../storage/tmp';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function sim_cleanup_error_files() {
    foreach (glob(sim_tmp_dir() . '/students_errors_*.xlsx') as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
}

/**
 * Hifadhi nakala ya faili la mtumiaji ikiwa na safu ya ERRORS na mistari yenye makosa
 * iliyotiwa rangi nyekundu, kisha rudisha token ya kupakua.
 */
function sim_error_workbook($src, $headerRowIndex, array $errorsByRow) {
    if (!$errorsByRow || !load_vendor()) return null;
    try {
        $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($src);
        $ws = $wb->getSheet(0);
        $headerRow = $headerRowIndex + 1;
        $errColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestColumn()) + 1;
        $errCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($errColIdx);

        $ws->setCellValue($errCol . $headerRow, 'ERRORS');
        $headStyle = $ws->getStyle($errCol . $headerRow);
        $headStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $headStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFB00020');
        $headStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        foreach ($errorsByRow as $rowNo => $errs) {
            $cell = $ws->getCell($errCol . $rowNo);
            $cell->setValueExplicit(implode('; ', $errs), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $cs = $ws->getStyle($errCol . $rowNo);
            $cs->getFont()->getColor()->setARGB('FF9C0006');
            $cs->getAlignment()->setWrapText(true)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            $ws->getStyle('B' . $rowNo . ':' . $errCol . $rowNo)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFC7CE');
        }
        $ws->getColumnDimension($errCol)->setWidth(65);

        $token = bin2hex(random_bytes(16));
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb))->save(sim_tmp_dir() . '/students_errors_' . $token . '.xlsx');
        return $token;
    } catch (Throwable $e) {
        return null;
    }
}

/** Namba ya kituo + jina la shule (kwa ajili ya template na deje). */
$schoolBot = q_one('SELECT name, centre_no FROM schools WHERE id = ?', [$school_id]) ?: ['name' => 'SHULE', 'centre_no' => ''];
$schoolName = strtoupper(trim((string)$schoolBot['name']));
$schoolFull = $schoolName . (stripos($schoolName, 'SECONDARY') === false ? ' SECONDARY SCHOOL' : '');
$centre = trim((string)$schoolBot['centre_no']);

/** Safu zinazopatikana kwenye mfumo sasa (darasa + madi). */
$classes = q_all('SELECT id, name FROM classes ORDER BY sort_order, id') ?: [];
$classByName = [];
foreach ($classes as $c) $classByName[strtolower(trim((string)$c['name']))] = (int)$c['id'];
$classOpts = [];
foreach ($classes as $c) $classOpts[] = ['name' => (string)$c['name'], 'id' => (int)$c['id']];

/** Wanafunzi waliolojitishwa tayari kwenye mfumo (kwa PREMS No / Admission No). */
$existingPrems = []; $existingAdm = [];
foreach (q_all('SELECT prems_no FROM students WHERE prems_no IS NOT NULL AND prems_no <> ""') as $x) {
    $existingPrems[strtoupper(trim((string)$x['prems_no']))] = true;
}
foreach (q_all('SELECT admission_no FROM students WHERE school_id = ?', [$school_id]) as $x) {
    $existingAdm[strtoupper(trim((string)$x['admission_no']))] = true;
}

/*
 * Template ya OASIS yenye taarifa za shule inayopakua.
 * Inatumiwa na faili la kwenye server; itakupa template yenye rangi na vichwa vya lazima.
 */
if (isset($_GET['template'])) {
    if (!load_vendor()) { http_response_code(500); die('Template ya .xlsx inahitaji PhpSpreadsheet. Endesha "composer install".'); }
    $tpl = __DIR__ . '/../storage/templates/iepas_students_template.xlsx';
    if (!is_file($tpl)) { http_response_code(500); die('Template ya wanafunzi haipatikani (storage/templates/iepas_students_template.xlsx).'); }

    $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($tpl);
    $ws = $wb->getSheet(0);
    $monthYear = strtoupper(date('F Y'));

    $tokens = [
        'KITUO - JINA LA SHULE SECONDARY SCHOOL'           => ($centre !== '' ? $centre . ' - ' : '') . $schoolFull,
        'JINA LA MTIHANI STUDENTS UPLOAD , CURRENT MONTH & YEAR' => $schoolName . ' STUDENTS UPLOAD , ' . $monthYear,
        'JINA LA SHULE SECONDARY SCHOOL'                    => $schoolFull,
        'JINA LA SHULE'                                    => $schoolName,
        'JINA LA MTIHANI'                                   => $schoolName,
        'CURRENT MONTH & YEAR'                              => $monthYear,
    ];
    foreach (range('B', 'M') as $col) {
        for ($r = 1; $r <= 5; $r++) {
            $cell = $ws->getCell($col . $r);
            $val = $cell->getValue();
            if (!is_string($val) || $val === '') continue;
            $new = str_replace(array_keys($tokens), array_values($tokens), $val);
            if ($new !== $val) $cell->setValueExplicit($new, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    // PREMS_NO iwe na rangi sawa na vichwa vingine vya lazima.
    $ws->getCell('C5')->getStyle()->getFont()->getColor()->setARGB('FF994807');

    // Ondoa mifano na uandae mistari tupu kwa wanafunzi; S/N ijazwe kiotomatiki.
    $maxRow = (int)$ws->getHighestRow();
    for ($r = 6; $r <= $maxRow; $r++) {
        foreach (range('B', 'M') as $col) $ws->setCellValue($col . $r, null);
        $ws->setCellValue('B' . $r, $r - 5);
    }
    // PREMS No na namba ya simu zihifadhiwe kama maandishi (sifuri ya mwanzo isipotee).
    $ws->getStyle('C6:C' . $maxRow)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
    $ws->getStyle('M6:M' . $maxRow)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

    $centrePart = $centre !== '' ? $centre . '_' : '';
    send_xlsx($wb, 'OASIS-' . $centrePart . $schoolFull . "-STUDENT'S UPLOAD_Template");
}

/* ---------- Pakua faili la marekebisho (lenye makosa) ---------- */
if (isset($_GET['errors'])) {
    $token = preg_replace('/[^a-f0-9]/', '', (string)$_GET['errors']);
    $path = sim_tmp_dir() . '/students_errors_' . $token . '.xlsx';
    if (strlen($token) !== 32 || !is_file($path)) {
        http_response_code(404);
        die('Faili la marekebisho halipatikani au muda wake umeisha. Pakia faili lako tena.');
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="wanafunzi_marekebisho_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit();
}

sim_cleanup_error_files();

$report = null;
$isAjax = (($_POST['ajax'] ?? '') === '1');
if (is_post()) {
    csrf_check();
    $resp = ['ok' => false, 'errors' => []];
    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Faili halikupakiwa vizuri.');
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) throw new Exception('Faili ni kubwa kuliko 5MB.');
        $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') throw new Exception('Tumia faili la Excel (.xlsx) pekee. CSV haikubaliki.');

        $rows = read_table($_FILES['file']['tmp_name'], 'xlsx');
        $hi = -1; $map = [];
        foreach (array_slice($rows, 0, 10, true) as $i => $r) {
            $m = [];
            foreach ($r as $ci => $v) { $k = sim_norm_head($v); if ($k !== '') $m[$k] = $ci; }
            if (isset($m['PREMSNO']) && isset($m['FIRSTNAME'])) { $hi = $i; $map = $m; break; }
        }
        if ($hi < 0) throw new Exception('Kichwa cha jedwali (PREMS_NO, FIRST_NAME, ...) hakikupatikana. Tumia template iliyotolewa na mfumo.');
        foreach (SIM_REQUIRED as $need) if (!isset($map[$need])) throw new Exception("Safu '$need' haipo kwenye faili. Tumia template iliyotolewa na mfumo.");

        $get = function ($r, $k) use ($map) {
            return isset($map[$k]) && isset($r[$map[$k]]) ? sim_cell_str($r[$map[$k]]) : '';
        };

        $ok = []; $errors = []; $errByRow = []; $seen = []; $skipped = [];
        for ($i = $hi + 1; $i < count($rows); $i++) {
            $r = $rows[$i]; $line = $i + 1;
            $prems = $get($r, 'PREMSNO');
            $fn = $get($r, 'FIRSTNAME');
            $ln = $get($r, 'LASTNAME');
            if ($prems === '' && $fn === '' && $ln === '') continue;

            $mn = $get($r, 'MIDDLENAME');
            $sx = strtoupper($get($r, 'SEX'));
            $sx = in_array($sx, ['M', 'MALE', 'ME'], true) ? 'M' : (in_array($sx, ['F', 'FEMALE', 'KE'], true) ? 'F' : '');
            $clRaw = $get($r, 'CLASS');
            $cl = strtolower($clRaw);
            $stream = $get($r, 'STREAM');
            $nat = strtoupper($get($r, 'NATIONALITY'));
            if ($nat === '') $nat = 'TANZANIA';
            $gn = $get($r, 'GUARDIANNAME');
            $gp = $get($r, 'GUARDIANPHONE');

            $dobRaw = isset($map['DOB']) && isset($r[$map['DOB']]) ? $r[$map['DOB']] : '';
            $dob = null;
            if ($dobRaw !== '' && $dobRaw !== null) {
                if (is_numeric($dobRaw) && $dobRaw > 20000 && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
                    $dob = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($dobRaw));
                } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', trim((string)$dobRaw), $m)) {
                    $dob = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                } elseif (strtotime((string)$dobRaw)) {
                    $dob = date('Y-m-d', strtotime((string)$dobRaw));
                }
            }

            $e = [];
            $premsKey = strtoupper($prems);
            if ($prems !== '' && (isset($existingPrems[$premsKey]) || isset($existingAdm[$premsKey]))) {
                $skipped[] = 'Line ' . $line . ': PREMS No "' . $prems . '" already exists in the system.';
                continue;
            }
            if ($prems === '') $e[] = 'PREMS_NO empty';
            elseif (isset($seen[$premsKey])) $e[] = 'PREMS No "' . $prems . '" duplicated in file';
            if ($fn === '') $e[] = 'FIRST_NAME empty';
            if ($ln === '') $e[] = 'LAST_NAME empty';
            if ($sx === '') $e[] = 'SEX not M/F';
            if (!isset($classByName[$cl])) $e[] = 'Class "' . $clRaw . '" not found';
            if ($gn === '') $e[] = 'GUARDIAN_NAME empty';
            if ($gp === '') $e[] = 'GUARDIAN_PHONE empty';
            if ($e) { $errors[] = 'Line ' . $line . ': ' . implode('; ', $e); $errByRow[$line] = $e; continue; }

            $seen[$premsKey] = true;
            $ok[] = [$prems, $prems, $fn, $mn, $ln, $sx, $dob, $nat, $classByName[$cl], $stream, $gn, $gp];
        }

        if ($errors) {
            $token = sim_error_workbook($_FILES['file']['tmp_name'], $hi, $errByRow);
            $resp = ['ok' => false, 'errors' => $errors, 'download' => $token ? url('admin/student_import.php?errors=' . $token) : null];
            $report = ['errors' => $errors, 'download' => $resp['download'], 'okCount' => $ok ? count($ok) : 0, 'skippedCount' => count($skipped)];
        } elseif (!$ok && !$skipped) {
            $resp = ['ok' => false, 'errors' => ['No student rows were found.']];
            $report = ['errors' => $resp['errors'], 'download' => null, 'okCount' => 0, 'skippedCount' => 0];
        } else {
            db()->beginTransaction();
            $ins = db()->prepare('INSERT INTO students (school_id, admission_no, prems_no, first_name, middle_name, last_name, sex, dob, nationality, class_id, stream, guardian_name, guardian_phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($ok as $row) $ins->execute(array_merge([$school_id], $row));
            db()->commit();
            $msg = 'Students ' . count($ok) . ' imported successfully.';
            if ($skipped) $msg .= ' ' . count($skipped) . ' students already existed (skipped).';
            $resp = ['ok' => true, 'saved' => count($ok), 'message' => $msg];
            if (!$isAjax) {
                flash('success', 'Students <b>' . count($ok) . '</b> imported.' . ($skipped ? ' <b>' . count($skipped) . '</b> skipped (already in the system).' : '') . ($ok ? ' Generate PINs next.' : ''));
                redirect('admin/students.php');
            }
        }
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        $resp = ['ok' => false, 'errors' => [$e->getMessage()], 'download' => null];
        $report = ['errors' => $resp['errors'], 'download' => null, 'okCount' => 0, 'skippedCount' => 0];
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp);
        exit();
    }
}

layout_start('Import Students', 'students'); ?>

<style>
.sim-hero{position:relative;overflow:hidden;border-radius:20px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);margin-bottom:18px;box-shadow:0 16px 34px -22px rgba(11,61,145,.95)}
.sim-hero:before,.sim-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.sim-hero:before{width:250px;height:250px;right:-70px;top:-130px;background:rgba(255,255,255,.10)}
.sim-hero:after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.sim-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.sim-ic{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.26);flex:none}
.sim-kicker{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:#ffd764;display:flex;align-items:center;gap:6px}
.sim-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.sim-hero p{margin:0;font-size:.82rem;color:rgba(255,255,255,.82)}
.sim-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-size:.74rem;font-weight:700;
  padding:.38rem .85rem;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.sim-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.sim-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.sim-btn:hover{transform:translateY(-1px)}
.sim-btn.solid{background:#fff;color:#0b3d91}
.sim-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.sim-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.sim-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.sim-card{background:#fff;border:1px solid #e6ecf8;border-radius:16px;overflow:hidden;box-shadow:0 2px 14px rgba(11,61,145,.07)}
.sim-sec-h{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #eef2fa;background:linear-gradient(180deg,#fbfdff,#fff);flex-wrap:wrap}
.sim-sec-ic{flex:0 0 auto;width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eef3fd;color:#0b3d91;font-size:1rem}
.sim-sec-t{font-weight:800;color:#16203a;font-size:.94rem;line-height:1.2}
.sim-sec-s{font-size:.73rem;color:#8a99b0;margin-top:2px}
.sim-pill{margin-left:auto;font-size:.67rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#0b3d91;background:#eef3fd;border:1px solid #dbe6fb;border-radius:999px;padding:5px 11px;white-space:nowrap}
.sim-card-b{padding:16px 18px}

.sim-roll{display:flex;flex-wrap:wrap;gap:22px 30px;padding:8px 4px}
.sim-roll-h{flex:0 0 100%}
.sim-roll-h .lbl{font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a99b0;margin-top:4px}
.sim-roll .w{position:relative}
.sim-roll .w .x{position:absolute;left:0;top:-14px;transform:translateX(-1px)}
.sim-roll .w .r{
  display:flex;align-items:center;gap:10px;font-size:.76rem;font-weight:700;
  border:1px dashed #c9d6ec;border-radius:11px;padding:7px 12px;background:#fff;color:#33415c;max-width:340px}
.sim-roll.in .w .r{border-style:solid;border-color:#2c6ed5;background:#eef4fe;color:#0b3d91}
.sim-roll .w .ico{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;flex:none;font-size:.78rem}
.sim-roll .r .v{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px}

.sim-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(218px,1fr));gap:10px}
.sim-field{display:flex;flex-direction:column;gap:3px;padding:9px 11px;border:1px solid #e3eaf7;border-radius:10px;background:#fff}
.sim-field .k{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#8a99b0}
.sim-field .k i{color:#2c6ed5;margin-left:3px}
.sim-field .v{font-size:.84rem;color:#16203a;font-weight:600}
.sim-field .v .badge{background:#eef3fd;color:#0b3d91;border:1px solid #d9e6fb;border-radius:6px;font-size:.66rem;font-weight:800;padding:1px 6px;letter-spacing:.02em}
.sim-field.done{background:#eef7ef;border-color:#cfe3d1}
.sim-field.done .k{color:#10903f}
.sim-field.note{background:#fff8e6;border-color:#f5dfb2}
.sim-field.note .k{color:#8a5a00}
.sim-field.danger{background:#fdeaea;border-color:#f0c4c4}
.sim-field.danger .k{color:#c92a2a}
.sim-field.danger .v{font-weight:700}

.policy-card{background:#fff;border:1px solid #e6ecf8;border-radius:14px;padding:13px 16px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.policy-card .row{display:flex;align-items:flex-start;gap:9px}
.policy-card .row i{width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;color:#1b5bc4;font-size:.68rem;margin-top:2px;flex:none}
.policy-card .row+ .row{margin-top:8px}

.sim-upload{border:2px dashed #c9d6ec;border-radius:14px;padding:22px;text-align:center;background:#fff;transition:border-color .16s,background .16s}
.sim-upload.dragover{border-color:#2c6ed5;background:#eef4fe}
.sim-upload .ico{width:54px;height:54px;margin:0 auto 10px;border-radius:14px;display:grid;place-items:center;
  background:#eef3fd;color:#0b3d91;font-size:1.4rem;border:1px solid #d9e6fb}
.sim-upload .title{font-weight:800;color:#16203a;font-size:.92rem}
.sim-upload .sub{font-size:.74rem;color:#8a99b0;margin-top:4px}
.sim-upload .fname{margin-top:10px;font-size:.84rem;color:#0b3d91;font-weight:700;background:#eef4fe;border:1px solid #d9e6fb;border-radius:8px;padding:5px 10px;display:inline-block;max-width:340px;word-break:break-all}

.sim-inp{display:flex;align-items:center;gap:10px}
.sim-inp .ico{flex:none}
.sim-inp input.form-control{font-size:.86rem;color:#16203a;height:auto;padding:.5rem .72rem;border-color:#dfe7f5;border-radius:10px}
.sim-inp input.form-control:focus{border-color:#2c6ed5;box-shadow:0 0 0 .18rem rgba(44,110,213,.12)}

.sim-progress{min-height:0}
.sim-progress .wrap{display:flex;flex-direction:column;gap:8px}
.sim-progress .title{font-size:.74rem;font-weight:700;color:#5b6b85;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.sim-progress .bar{height:22px}
.sim-progress .bar .p{font-weight:800;font-size:.78rem;position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#fff}
.sim-progress .bar.done{animation:none}
.sim-progress .status{font-size:.76rem;color:#8a99b0;margin-top:3px}
.sim-progress .status.done{color:#0b8a3e}
.sim-progress .status.err{color:#c92a2a;font-weight:700}

.sim-result{display:flex;flex-direction:column;gap:10px;border:1px solid #e6ecf8;border-radius:14px;overflow:hidden;background:#fff}
.sim-ok{background:#eef7ef;border-top:3px solid #1b7f4b}
.sim-err{border-top:3px solid #c92a2a}
.sim-err.has{background:#fdeaea}
.sim-result .row{display:flex;align-items:flex-start;gap:10px;padding:11px 14px}
.sim-result .row .ico{flex:none;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:.8rem}
.sim-result .sim-ok .ico{background:#1b7f4b;color:#fff}
.sim-result .sim-err .ico{background:#c92a2a;color:#fff}
.sim-result .row .txt{flex:1 1 auto;font-size:.84rem}
.sim-result .row .txt b{display:block;color:#16203a;font-size:.86rem;font-weight:800;margin-bottom:2px}
.sim-result .row .txt .small{color:#8a99b0}
.sim-result .row .txt .good{color:#10903f;font-weight:700}
.sim-result .row .txt a.btn{display:inline-block;margin-top:6px}
.sim-more{font-size:.76rem;color:#8a99b0;text-align:right;padding:0 14px 9px}
.sim-empty{text-align:center;padding:42px 16px;color:#8a99b0}
.sim-empty i{font-size:2.4rem;display:block;color:#c3cfe4;margin-bottom:8px}
.sim-empty .t{font-weight:800;color:#16203a;font-size:.98rem;margin-bottom:4px}
.sim-empty .s{font-size:.78rem}

.sim-shimmer{display:inline-block;width:10px;height:10px;border-radius:50%;background:#cfe1f5;animation:shimmer 1.4s infinite}
@keyframes shimmer{0%{transform:scale(.8);opacity:.6}50%{transform:scale(1.2);opacity:1}100%{transform:scale(.8);opacity:.6}}
@media (max-width:575.98px){
  .sim-hero{padding:18px}
  .sim-hero h1{font-size:1.2rem}
  .sim-date{margin-left:0}
  .sim-roll{gap:14px;align-items:stretch}
}
</style>

<div class="sim-hero no-print">
  <div class="sim-head">
    <span class="sim-ic"><i class="bi bi-kanban"></i></span>
    <div>
      <div class="sim-kicker"><i class="bi bi-speedometer2"></i> Bulk import</div>
      <h1>Import Students</h1>
      <p><?php echo h($schoolFull); ?> &middot; school admins only</p>
    </div>
    <span class="sim-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
  </div>
  <div class="sim-actions">
    <a class="sim-btn solid" href="?template=1"><i class="bi bi-file-earmark-excel"></i> Download template</a>
    <a class="sim-btn ghost" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-arrow-left"></i> Back to students</a>
  </div>
</div>

<div class="row g-4 align-items-start">
  <div class="col-12">
    <div class="sim-card">
      <div class="sim-sec-h">
        <span class="sim-sec-ic"><i class="bi bi-file-earmark-excel"></i> File to upload</span>
        <span class="sim-pill"><i class="bi bi-asterisk"></i> Required</span>
      </div>
      <div class="sim-card-b">
        <div class="sim-upload" id="uploadZone">
          <span class="ico"><i class="bi bi-cloud-arrow-up"></i></span>
          <div class="title">Drop your Excel file here, or click to browse</div>
          <div class="sub">File type: Excel (.xlsx). Max size: 5MB.</div>
          <?php if (!empty($_FILES['file']['name'])): ?>
            <div class="fname"><i class="bi bi-file-earmark"></i> <?php echo h($_FILES['file']['name']); ?></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" id="importForm" class="sim-inp mt-3"><?php echo csrf_field(); ?>
            <span class="ico"><i class="bi bi-folder2-open"></i></span>
            <input type="file" name="file" id="importFile" accept=".xlsx" class="form-control form-control-sm flex-grow-1" required>
            <button type="submit" class="btn btn-primary btn-sm" id="importBtn"><i class="bi bi-upload me-1"></i>Import</button>
          </form>
        </div>
        <div class="mt-3 small text-muted">
          <i class="bi bi-info-circle me-1"></i> Uploads run through AJAX. If the page looks stuck, refresh and try again.
        </div>

        <div class="mt-4 pt-3" style="border-top:1px dashed #d6e2f5">
          <div id="importProgress" class="sim-progress d-none mb-3">
            <div class="wrap">
              <div class="title">Uploading</div>
              <div class="progress bar" role="progressbar" aria-label="Upload progress">
                <div id="importBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
              </div>
              <div id="importStatus" class="status"></div>
            </div>
          </div>
          <div id="importResult"></div>
          <?php if ($report): ?>
            <div class="sim-result<?php echo empty($report['errors']) ? '' : ' sim-err has'; ?>">
              <?php if (empty($report['errors'])): ?>
                <div class="sim-ok">
                  <div class="row">
                    <span class="ico"><i class="bi bi-check-circle-fill"></i></span>
                    <div class="txt">
                      <b class="good">Students imported successfully</b>
                      <div class="small"><?php echo $report['okCount']; ?> new students added to <?php echo h($schoolFull); ?>.</div>
                      <?php if ($report['skippedCount'] > 0): ?>
                        <div class="small"><i class="bi bi-dash-circle me-1"></i> <?php echo $report['skippedCount']; ?> students skipped (already in the system).</div>
                      <?php endif; ?>
                      <a class="btn btn-outline-success btn-sm" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-people me-1"></i> View students</a>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!empty($report['errors'])): ?>
                <div class="sim-err">
                  <div class="row">
                    <span class="ico"><i class="bi bi-exclamation-triangle-fill"></i></span>
                    <div class="txt">
                      <b>No students were added</b>
                      <div class="small"><?php echo count($report['errors']); ?> row<?php echo count($report['errors']) === 1 ? '' : 's'; ?> with errors. Download the corrected file and import again.</div>
                      <?php if (!empty($report['download'])): ?>
                        <a class="btn btn-danger btn-sm" href="<?php echo h($report['download']); ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i> Download file with errors</a>
                        <span class="ms-1">Fix the highlighted rows, then import again.</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="sim-more">
                    <?php
                      $shown = array_slice($report['errors'], 0, 20);
                      $more = count($report['errors']) - count($shown);
                    ?>
                    <?php foreach ($shown as $e): ?>
                      <div style="font-size:.78rem;color:#7a3b3b;padding:2px 0 2px 6px;display:flex;gap:6px;align-items:flex-start"><i class="bi bi-dot"></i><span><?php echo h($e); ?></span></div>
                    <?php endforeach; ?>
                    <?php if ($more > 0): ?>
                      <div class="small">... and <?php echo $more; ?> more row<?php echo $more === 1 ? '' : 's'; ?> with errors. Download the corrected file to see them all.</div>
                    <?php endif; ?>
                  </div>
                  <?php if ($report['okCount'] > 0 || $report['skippedCount'] > 0): ?>
                    <div class="sim-ok mt-3" style="border-top:1px solid #cfe3d1">
                      <div class="row">
                        <span class="ico"><i class="bi bi-emoji-smile"></i></span>
                        <div class="txt">
                          <b class="good">The file is partly valid</b>
                          <div class="small">
                            <?php echo $report['okCount']; ?> row<?php echo $report['okCount'] === 1 ? '' : 's'; ?> would be imported, and <?php echo $report['skippedCount']; ?> student<?php echo $report['skippedCount'] === 1 ? '' : 's'; ?> would be skipped.
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif (is_post()): ?>
            <div class="sim-empty">
              <i class="bi bi-emoji-frown"></i>
              <div class="t">Upload started, but no result was returned.</div>
              <div class="s">Refresh the page and try again, or use the download template link to prepare your file.</div>
            </div>
          <?php else: ?>
            <div class="sim-empty">
              <i class="bi bi-cloud-arrow-up"></i>
              <div class="t">Waiting for an upload</div>
              <div class="s">Drop an Excel file above, or choose it from the file button.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('importForm');
  var fileInput = document.getElementById('importFile');
  var zone = document.getElementById('uploadZone');
  var btn = document.getElementById('importBtn');
  if (!form || !fileInput) return;

  /* Drag-and-drop highlight on the upload zone, tied to the file input. */
  if (zone) {
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', function (e) { zone.classList.remove('dragover'); });
    zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('dragover');
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
        fileInput.files = e.dataTransfer.files;
      }
    });
    fileInput.addEventListener('change', function () { zone.classList.remove('dragover'); });
  }

  if (window.FormData && window.XMLHttpRequest) {
    form.addEventListener('submit', function (ev) {
      if (!fileInput.files.length) return;
      ev.preventDefault();

      /* Read file size early for a quick preview. */
      var file = fileInput.files[0];

      var wrap = document.getElementById('importProgress');
      var bar = document.getElementById('importBar');
      var status = document.getElementById('importStatus');
      var result = document.getElementById('importResult');
      var btn2 = document.getElementById('importBtn');

      if (!wrap || !bar) return;
      wrap.classList.remove('d-none');
      result.innerHTML = '';
      bar.className = 'progress-bar progress-bar-striped progress-bar-animated';
      bar.style.width = '0%'; bar.textContent = '0%';
      status.textContent = 'Uploading file...';
      btn2.disabled = true;

      /* Re-render the upload zone with the chosen file name. */
      if (zone) {
        var fnameEl = zone.querySelector('.fname');
        if (fnameEl) fnameEl.remove();
        var choice = document.createElement('div');
        choice.className = 'fname';
        choice.innerHTML = '<i class="bi bi-file-earmark"></i> ' + file.name;
        zone.appendChild(choice);
        var sub = zone.querySelector('.sub'); if (sub) sub.style.display = 'none';
        var title = zone.querySelector('.title'); if (title) title.style.display = 'none';
        zone.querySelector('.ico').style.display = 'none';
      }

      var fd = new FormData(form);
      fd.append('ajax', '1');

      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action') || window.location.href, true);
      xhr.responseType = 'json';

      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var p = Math.round(e.loaded / e.total * 100);
        if (p > 99) p = 99;
        bar.style.width = p + '%'; bar.textContent = p + '%';
        status.textContent = 'Uploading file... ' + p + '%';
      };
      xhr.onload = function () {
        bar.style.width = '100%'; bar.textContent = '100%';
        bar.classList.remove('progress-bar-animated');
        btn2.disabled = false;
        var data = xhr.response;
        if (!data) { try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; } }
        if (xhr.status !== 200 || !data) {
          bar.classList.add('bg-danger');
          status.textContent = 'Upload failed.';
          result.innerHTML = '<div class="alert alert-danger small">Upload failed. Refresh the page and try again.</div>';
          return;
        }
        if (data.ok) {
          bar.classList.add('bg-success');
          status.textContent = 'Done.';
          status.classList.add('done');
          result.innerHTML = '<div class="alert alert-success"><b>' + data.message + '</b><br>Redirecting to the student list...</div>';
          setTimeout(function () { window.location.href = 'students.php'; }, 1800);
        } else {
          bar.classList.add('bg-danger');
          status.textContent = 'Reviewing file... found errors.';
          status.classList.add('err');
          var html = '<div class="alert alert-danger small"><b>No students were added.</b><ul class="mb-2">';
          var errs = data.errors || [];
          for (var i = 0; i < errs.length && i < 40; i++) {
            html += '<li>' + String(errs[i]).replace(/[&<>\"']/g, function (c) {
              return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c];
            }) + '</li>';
          }
          html += '</ul>';
          if (errs.length > 40) html += '<div class="mb-2">... and ' + (errs.length - 40) + ' more rows with errors.</div>';
          if (data.download) html += '<a class="btn btn-danger btn-sm" href="' + data.download + '"><i class="bi bi-file-earmark-arrow-down me-1"></i> Download file with errors</a> <span class="ms-1">Fix the highlighted rows, then import again.</span>';
          html += '</div>';
          result.innerHTML = html;
        }
      };
      xhr.onerror = function () {
        btn2.disabled = false;
        bar.classList.add('bg-danger');
        status.textContent = 'Upload failed.';
        result.innerHTML = '<div class="alert alert-danger small">Upload failed. Check your connection and try again.</div>';
      };
      xhr.send(fd);
    });
  }
})();
</script>
<?php layout_end();
