<?php
/** Upload ya alama kwa somo husika (Excel/Template au Scan/Direct Entry). */
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/ocr.php';
require_once __DIR__ . '/lib/layout.php';
$u = require_school_staff(); $school_id = active_school_id();
$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0); 
$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0); 
$subject_id = (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

$exam = get_exam($exam_id);
if (!$exam || !exam_has_school($exam_id, $school_id)) { flash('danger', 'This exam is not available or does not belong to this school.'); redirect(home_url()); }
if ($u['role'] === 'teacher' && empty($exam['owner_school_id'])) { flash('danger', 'District exam scores are entered by the school administrator.'); redirect('teacher/dashboard.php'); }

if ($u['role'] !== 'teacher') $classes = exam_class_list($exam_id);
else $classes = q_all('SELECT DISTINCT c.* FROM teacher_assignments ta JOIN classes c ON c.id = ta.class_id JOIN exam_classes ec ON ec.class_id = ta.class_id AND ec.exam_id = ? WHERE ta.teacher_id = ? ORDER BY c.sort_order', [$exam_id, $u['id']]);

$class = null; foreach ($classes as $c) if ((int)$c['id'] === $class_id) $class = $c;

/** Masomo ya darasa ambayo mtumiaji anaruhusiwa kuingiza */
function allowed_subjects(array $exam, $class_id) {
    $out = []; foreach (exam_subject_list($exam['id'], $class_id) as $s) if (can_enter_scores($exam, active_school_id(), $class_id, $s['id'])) $out[(int)$s['id']] = $s;
    return $out;
}
function cs($v) { if (is_float($v) && floor($v) == $v) return (string)(int)$v; return trim((string)$v); }

/**
 * Wanafunzi wa darasa kwenye template ya somo, kwa mpangilio huo huo wa template.
 * Huchujwa kwa usajili wa somo (student_subjects); somo lisilo na usajili hutumia darasa zima.
 */
function score_sheet_candidates($exam_id, $school_id, $class_id, $subject_id = 0) {
    $rows = q_all('SELECT s.id AS student_id, ec.cand_no, s.admission_no, s.first_name, s.middle_name, s.last_name, s.sex
                  FROM students s
                  LEFT JOIN exam_candidates ec ON ec.student_id = s.id AND ec.exam_id = ?
                  WHERE s.school_id = ? AND s.class_id = ? AND s.status <> "inactive"
                  ORDER BY s.last_name, s.first_name', [(int)$exam_id, (int)$school_id, (int)$class_id]);
    if (!$subject_id) return $rows;
    $ids = subject_registered_ids($school_id, $class_id, $subject_id);
    if ($ids === null) return $rows;
    $set = array_flip($ids);
    return array_values(array_filter($rows, function ($r) use ($set) { return isset($set[(int)$r['student_id']]); }));
}

/** Alama zilizopo tayari kwa somo hili: student_id => [score, status]. */
function score_existing_map($exam_id, $school_id, $class_id, $subject_id) {
    $out = [];
    foreach (q_all('SELECT student_id, score, status FROM scores WHERE exam_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?',
                    [(int)$exam_id, (int)$school_id, (int)$class_id, (int)$subject_id]) as $r) {
        $out[(int)$r['student_id']] = $r;
    }
    return $out;
}

/** Jedwali la ukaguzi la alama zilizosomwa kwenye picha. */
function score_review_html(array $rows, array $existing) {
    ob_start(); ?>
<div class="table-responsive">
  <table class="table table-sm table-bordered align-middle mb-2">
    <thead class="table-light"><tr>
      <th>#</th><th>Admission No</th><th>Candidate No</th><th>Name</th><th>Sex</th><th style="width:110px">Score</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $sid = (int)$r['student_id']; $ex = $existing[$sid] ?? null;
        $val = (string)$r['score'];
        $state = (string)$r['state']; ?>
      <tr>
        <td class="text-muted small"><?php echo (int)$r['sn']; ?></td>
        <td class="small"><?php echo h($r['admission_no']); ?></td>
        <td class="small text-muted"><?php echo h($r['cand_no']); ?></td>
        <td class="small"><?php echo h($r['name']); ?></td>
        <td class="small text-center"><?php echo h($r['sex']); ?></td>
        <td><input type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-sm text-center"
                   name="scores[<?php echo $sid; ?>]" value="<?php echo h($val); ?>" placeholder="-"></td>
        <td class="small">
          <?php if ($state === 'ok'): ?>
            <span class="badge bg-success">Detected</span>
          <?php elseif ($state === 'low'): ?>
            <span class="badge bg-warning text-dark">Check</span>
          <?php else: ?>
            <span class="badge bg-secondary">Not found</span>
          <?php endif; ?>
          <?php if ($state !== 'none'): ?>
            <span class="text-muted d-block" style="font-size:.7rem">Matched by: <?php echo $r['source'] === 'sn' ? 'S/N' : 'Admission No'; ?><?php echo $r['conf'] > 0 ? ' &middot; ' . (int)$r['conf'] . '%' : ''; ?></span>
          <?php endif; ?>
          <?php if ($ex && $ex['status'] === 'ABSENT'): ?>
            <span class="text-muted d-block" style="font-size:.7rem">Existing: ABS</span>
          <?php elseif ($ex && $ex['score'] !== null): ?>
            <span class="text-muted d-block" style="font-size:.7rem">Existing: <?php echo h((string)(0 + $ex['score'])); ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted mb-0">Verify all scores before saving. Leave a field blank if the student has no score on the image.</p>
<?php
    return (string)ob_get_clean();
}

$subs = $class ? allowed_subjects($exam, $class_id) : [];
$sub = ($subject_id && isset($subs[$subject_id])) ? $subs[$subject_id] : null;
/* Orodha ya wanafunzi wa somo hili (usajili wa somo), inayotumika kwenye template na uingizaji. */
$sheetCands = ($class && $sub) ? score_sheet_candidates($exam_id, $school_id, $class_id, $subject_id) : [];
$regIds = ($class && $sub) ? subject_registered_ids($school_id, $class_id, $subject_id) : null;

/* ---- Template Rasmi ya Somo Husika (format ya OASIS) ----
   Template hii inatumia faili la msingi lililopo kwenye storage/templates/iepas_score_template.xlsx.
   Maeneo yenye "JINA LA SHULE", "JINA LA MTIHANI", "JINA LA SOMO" na "KIDATO CHA MTIHANI"
   hujazwa kiotomatiki kwa taarifa za shule, mtihani, somo na kidato husika. */
if ($class && $sub && isset($_GET['download'])) {
    if (!load_vendor()) {
        flash('danger', 'Downloading the Excel template requires PhpSpreadsheet. Run "composer install" in the system folder.');
        redirect('score_upload.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id);
    }

    $template_file = __DIR__ . '/storage/templates/iepas_score_template.xlsx';
    if (!is_file($template_file)) {
        flash('danger', 'The score template was not found (storage/templates/iepas_score_template.xlsx).');
        redirect('score_upload.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id);
    }

    // Wanafunzi waliosajiliwa kwenye somo (au wote wa darasa kama hakuna usajili)
    $cands = $sheetCands;

    $school_name = strtoupper(q_val('SELECT name FROM schools WHERE id = ?', [$school_id]) ?: 'SHULE YA SEKONDARI');
    $exam_name = strtoupper(trim((string)$exam['name']));
    $subject_name = strtoupper(trim((string)$sub['name']));
    $class_name = strtoupper(trim((string)$class['name']));

    $school_full = $school_name . (stripos($school_name, 'SECONDARY') === false ? ' SECONDARY SCHOOL' : '');
    $exam_full = preg_match('/\bSCORES?\b/i', $exam_name) ? $exam_name : $exam_name . ' SCORES';
    $tokens = [
        'JINA LA SHULE SECONDARY SCHOOL' => $school_full,
        'JINA LA SHULE' => $school_name,
        'JINA LA MTIHANI SCORES' => $exam_full,
        'JINA LA MTIHANI' => $exam_name,
        'JINA LA SOMO' => $subject_name,
        'KIDATO CHA MTIHANI' => $class_name,
    ];

    $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($template_file);
    $ws = $wb->getSheet(0);

    // Badilisha maeneo yote yenye maneno ya template, si kwenye vichwa pekee.
    $max_row = $ws->getHighestRow();
    foreach (range('A', 'E') as $col) {
        for ($r = 1; $r <= $max_row; $r++) {
            $cell = $ws->getCell($col . $r);
            $val = $cell->getValue();
            if (!is_string($val) || $val === '') continue;
            $new = str_replace(array_keys($tokens), array_values($tokens), $val);
            if ($new !== $val) $cell->setValueExplicit($new, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    // Safisha data ya zamani kisha jaza watahiniwa; format ya template inabaki kama ilivyo.
    $first = 7;
    $clear_to = max($first, (int)$ws->getHighestDataRow());
    for ($r = $first; $r <= $clear_to; $r++) {
        foreach (range('A', 'E') as $col) $ws->setCellValue($col . $r, null);
    }

    $row = $first;
    foreach ($cands as $index => $c) {
        $index_no = trim((string)($c['cand_no'] ?? ''));
        if ($index_no === '') $index_no = trim((string)($c['admission_no'] ?? ''));
        $ws->setCellValue('A' . $row, $index + 1);
        $ws->setCellValueExplicit('B' . $row, strtoupper(full_name($c)), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('C' . $row, strtoupper((string)($c['sex'] ?? '')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('D' . $row, $index_no, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('E' . $row, '');
        $row++;
    }

    $last_student = $row - 1;

    // Futa safu zote za ziada ili jedwali liishie kwenye mwanafunzi wa mwisho,
    // badala ya kuendelea chini kama template tupu.
    $extra_from = $last_student + 1;
    $highest_row = (int)$ws->getHighestRow();
    if ($highest_row >= $extra_from) {
        $ws->removeRow($extra_from, $highest_row - $last_student);
    }

    // Validation ya alama (0-100) iwe kwenye safu za wanafunzi pekee.
    foreach (array_keys($ws->getDataValidationCollection()) as $coord) $ws->setDataValidation($coord, null);
    if ($last_student >= $first) {
        $dv = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
        $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL);
        $dv->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
        $dv->setFormula1('0');
        $dv->setFormula2('100');
        $dv->setShowErrorMessage(true);
        $dv->setErrorTitle('Invalid score');
        $dv->setError('Enter a score between 0 and 100.');
        $ws->setDataValidation('E' . $first . ':E' . $last_student, $dv);
    }

    if ($last_student >= $first) {
        $ws->getStyle('A' . $first . ':E' . $last_student)->getFont()->setName('Arial')->setSize(10);
        $ws->getStyle('E' . $first . ':E' . $last_student)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    // Jina la faili linatokana na shule, kidato, somo na mtihani.
    $slug = function ($s, $max = 40) {
        $s = trim(preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper((string)$s)), '_');
        return $s === '' ? 'ITEM' : substr($s, 0, $max);
    };
    $fname = 'OASIS_' . $slug($school_name, 30) . '_' . $slug($class_name, 20) . '_' . $slug($subject_name, 30) . '_' . $slug($exam_name, 40);

    send_xlsx($wb, $fname);
}

/* ---- Upload, Scan na OCR Handling ---- */
$report = null;
$ocr_review_html = '';
if ($class && $sub && is_post()) {
    csrf_check();
    $action = (string)($_POST['action_type'] ?? 'upload');
    try {
        if ($action === 'ocr_parse' || $action === 'ocr_confirm') {
            $words = [];
            if (!empty($_POST['ocr_words'])) {
                $dec = json_decode((string)$_POST['ocr_words'], true);
                if (is_array($dec)) $words = $dec;
            }
            $lines = [];
            if (!empty($_POST['ocr_lines'])) {
                $dec = json_decode((string)$_POST['ocr_lines'], true);
                if (is_array($dec)) $lines = $dec;
            }
            $ocr_text = (string)($_POST['ocr_text'] ?? '');
            if (trim($ocr_text) === '' && !$words && !$lines) throw new Exception('No text was read from the image. Try again with a clear, flat image.');

            $cands = score_sheet_candidates($exam_id, $school_id, $class_id, $subject_id);
            $ocr_rows = ocr_extract_scores($cands, $ocr_text, $words, $lines);
            $ocr_review_html = score_review_html($ocr_rows, score_existing_map($exam_id, $school_id, $class_id, $subject_id));

            if ($action === 'ocr_parse') {
                $found = 0; foreach ($ocr_rows as $r) if ($r['score'] !== '') $found++;
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'html' => $ocr_review_html, 'found' => $found, 'total' => count($ocr_rows)]);
                exit();
            }

            // Hifadhi alama zilizothibitishwa na mtumiaji
            if (!can_enter_scores($exam, $school_id, $class_id, $subject_id)) throw new Exception('You are not allowed to enter scores for this subject (or the exam is closed).');
            $scores = $_POST['scores'] ?? [];
            if (!is_array($scores)) $scores = [];
            $allowed = []; $nameById = [];
            foreach ($cands as $c) { $allowed[(int)$c['student_id']] = true; $nameById[(int)$c['student_id']] = strtoupper(full_name($c)); }

            $saved = 0; $errs = []; $skipped = 0;
            db()->beginTransaction();
            foreach ($scores as $sid => $raw) {
                $sid = (int)$sid;
                if (!isset($allowed[$sid])) continue;
                $raw = trim((string)$raw);
                if ($raw === '') { $skipped++; continue; }
                $abs = in_array(strtoupper($raw), ['ABS', 'ABSENT', 'X'], true);
                $res = save_score($exam_id, $school_id, $class_id, $sid, $subject_id, $raw, $abs, 'IMPORT');
                if ($res === 'ok') $saved++;
                elseif ($res !== 'skip') $errs[] = ($nameById[$sid] ?? ('#' . $sid)) . ': ' . $res;
            }
            if ($errs) { db()->rollBack(); $report = ['errors' => $errs]; }
            else {
                db()->commit();
                flash('success', "Scores <b>$saved</b> for " . h($sub['name']) . " were saved from the image." . ($skipped ? " ($skipped left blank)" : ''));
                redirect('score_upload.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id);
            }

        } elseif ($action === 'scan') {
            $admission_no = trim($_POST['scan_admission'] ?? '');
            $score_val = trim($_POST['scan_score'] ?? '');
            
            if ($admission_no === '') throw new Exception('Please enter the admission number.');
            
            $student = q_one('SELECT s.id FROM exam_candidates ec JOIN students s ON s.id = ec.student_id WHERE ec.exam_id = ? AND ec.school_id = ? AND ec.class_id = ? AND s.admission_no = ?', [$exam_id, $school_id, $class_id, $admission_no]);
            if (!$student) throw new Exception("Student with admission number '$admission_no' was not found in this class.");
            if ($regIds !== null && !in_array((int)$student['id'], $regIds, true)) throw new Exception("Student '$admission_no' is not enrolled in " . $sub['name'] . '.');
            
            if (!can_enter_scores($exam, $school_id, $class_id, $subject_id)) throw new Exception("You are not allowed to enter scores for this subject (or the exam is closed).");
            
            $abs = in_array(strtoupper($score_val), ['ABS', 'ABSENT', 'X'], true);
            $res = save_score($exam_id, $school_id, $class_id, (int)$student['id'], $subject_id, $score_val, $abs, 'MANUAL');
            
            if ($res === 'ok') {
                flash('success', "Score for " . h($sub['name']) . " was saved successfully!");
                redirect('score_upload.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id);
            } else {
                throw new Exception("Could not save: $res");
            }
            
        } else {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('The file was not uploaded correctly.');
            if ($_FILES['file']['size'] > 5 * 1024 * 1024) throw new Exception('The file is larger than 5MB.');

            $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') throw new Exception('Use an Excel (.xlsx) file only. CSV is not accepted.');

            $rows = read_table($_FILES['file']['tmp_name'], 'xlsx');
            $norm = function ($v) { return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$v)); };
            $hi = -1;
            foreach (array_slice($rows, 0, 10, true) as $i => $r) {
                foreach ($r as $cell) {
                    if (in_array($norm($cell), ['ADMISSIONNO', 'CANDIDATENO', 'INDEXNUMBER', 'INDEXNO'], true)) { $hi = $i; break 2; }
                }
            }
            if ($hi < 0) throw new Exception('The table header (Index Number / Admission No / Candidate No) was not found. Use the template provided by the system.');

            // Tafuta safu ya Admission/Candidate No na safu ya Score
            $adm_col = -1; $score_col = -1; $last_col = -1;
            foreach ($rows[$hi] as $ci => $t) {
                $tc = $norm($t);
                $tu = strtoupper(trim((string)$t));
                $last_col = $ci;
                if ($adm_col < 0 && in_array($tc, ['ADMISSIONNO', 'CANDIDATENO', 'INDEXNUMBER', 'INDEXNO'], true)) $adm_col = $ci;
                if ($tc === 'SCORE' || strpos($tu, '[#' . $subject_id . ']') !== false) $score_col = $ci;
            }
            if ($adm_col < 0) throw new Exception('The Index Number / Admission No / Candidate No column was not found in the table.');
            if ($score_col < 0) $score_col = $last_col;

            // Ramani ya wanafunzi wa somo hili: admission_no na cand_no zote zinakubalika
            $byKey = [];
            foreach (score_sheet_candidates($exam_id, $school_id, $class_id, $subject_id) as $r) {
                foreach ([$r['admission_no'], $r['cand_no']] as $k) {
                    $k = strtoupper(trim((string)$k));
                    if ($k !== '') $byKey[$k] = (int)$r['student_id'];
                }
            }

            $saved = 0; $errs = []; $skipped = 0;
            db()->beginTransaction();
            for ($i = $hi + 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $adm = strtoupper(trim(cs($r[$adm_col] ?? ''))); if ($adm === '') continue;
                if (!isset($byKey[$adm])) { $errs[] = 'Row ' . ($i + 1) . ": '$adm' is not enrolled in this subject."; continue; }

                $cell = cs($r[$score_col] ?? ''); if ($cell === '' || $cell === '-') { $skipped++; continue; }
                $abs = in_array(strtoupper($cell), ['ABS', 'ABSENT', 'X'], true);
                $res = save_score($exam_id, $school_id, $class_id, $byKey[$adm], $subject_id, $cell, $abs, 'IMPORT');
                if ($res === 'ok') $saved++; elseif ($res !== 'skip') $errs[] = 'Row ' . ($i + 1) . " ($adm): $res";
            }
            if ($errs) { db()->rollBack(); $report = ['errors' => $errs]; }
            else { db()->commit(); flash('success', "Scores <b>$saved</b> for " . h($sub['name']) . " were saved."); redirect('score_upload.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id); }
        }
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($action === 'ocr_parse') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit();
        }
        $report = ['errors' => [$e->getMessage()]];
    }
}

layout_start('Score entry: ' . ($sub ? $sub['name'] : 'Subject'), $u['role'] !== 'teacher' ? 'exams' : 'dashboard'); ?>

<style>
.su-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.su-hero::before,.su-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.su-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.su-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.su-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.su-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.su-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.su-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .3rem;line-height:1.2}
.su-hero h1 span{color:#ffd764}
.su-sub{font-size:.8rem;color:rgba(255,255,255,.88);margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:7px}
.su-chip{display:inline-flex;align-items:center;gap:5px;padding:.16rem .6rem;border-radius:999px;font-size:.69rem;font-weight:700;
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3)}
.su-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.su-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.su-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.su-btn:hover{transform:translateY(-1px)}
.su-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.su-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.su-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.su-tabs{border-bottom:1px solid #edf1fa;gap:2px;padding:0 10px}
.su-tabs .nav-link{border:0;border-bottom:3px solid transparent;color:#6b7a90;font-weight:700;font-size:.82rem;
  padding:.72rem .85rem;border-radius:0;display:inline-flex;align-items:center;gap:7px}
.su-tabs .nav-link:hover{color:#0b3d91;background:#f7faff}
.su-tabs .nav-link.active{color:#0b3d91;border-bottom-color:#2c6ed5;background:transparent}
.su-step{display:inline-flex;align-items:center;justify-content:center;width:21px;height:21px;border-radius:50%;
  background:#eef4ff;color:#0b3d91;font-size:.7rem;font-weight:800;flex:none}
.su-tabs .nav-link.active .su-step{background:#0b3d91;color:#fff}
.su-label{font-size:.8rem;font-weight:800;color:#16203a}
.su-hint{font-size:.76rem;color:#7a879c}
.su-pane{animation:suFade .2s ease}
@keyframes suFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
@media (max-width:575.98px){
  .su-hero{padding:18px;border-radius:18px}
  .su-hero h1{font-size:1.2rem}
  .su-date{margin-left:0}
  .su-tabs .nav-link{font-size:.76rem;padding:.6rem .5rem}
}
</style>

<div class="su-hero">
    <div class="su-head">
        <span class="su-mark"><i class="bi bi-upload"></i></span>
        <div>
            <div class="su-kicker">Exams &amp; Results</div>
            <h1>Score <span>entry</span></h1>
            <p class="su-sub">
                <span><?php echo h($exam['name']); ?></span>
                <?php if ($class): ?><span class="su-chip"><i class="bi bi-building"></i> <?php echo h($class['name']); ?></span><?php endif; ?>
                <?php if ($sub): ?><span class="su-chip"><i class="bi bi-book"></i> <?php echo h($sub['name']); ?></span><?php endif; ?>
            </p>
        </div>
        <span class="su-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="su-actions">
        <a class="su-btn ghost" href="<?php echo h(url($u['role'] === 'teacher' ? 'teacher/dashboard.php' : 'admin/exam_view.php?id=' . $exam_id)); ?>"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($u['role'] !== 'teacher'): ?>
            <a class="su-btn ghost" href="<?php echo h(url('admin/exam_progress.php?exam_id=' . $exam_id)); ?>"><i class="bi bi-bar-chart-line"></i> Score progress</a>
        <?php endif; ?>
    </div>
</div>

<div class="su-card">
    <div class="card-body p-3 p-md-4">
        <?php if ($class && $sub): ?>

        <?php if ($report): ?><div class="alert alert-danger small"><b>Errors occurred:</b><ul class="mb-0"><?php foreach (array_slice($report['errors'], 0, 30) as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div><?php endif; ?>

        <?php $photoActive = ($ocr_review_html !== ''); ?>
        <ul class="nav nav-tabs su-tabs mb-3" id="uploadTab" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $photoActive ? '' : 'active'; ?>" id="template-tab" data-bs-toggle="tab" data-bs-target="#template-pane" type="button" role="tab"><span class="su-step">1</span> Template &amp; Upload</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $photoActive ? 'active' : ''; ?>" id="photo-tab" data-bs-toggle="tab" data-bs-target="#photo-pane" type="button" role="tab"><span class="su-step">2</span> Scan image (OCR)</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="scan-tab" data-bs-toggle="tab" data-bs-target="#scan-pane" type="button" role="tab"><span class="su-step">3</span> Direct entry</button>
          </li>
        </ul>

        <div class="tab-content" id="uploadTabContent">
          <!-- Template Pane -->
          <div class="tab-pane fade su-pane <?php echo $photoActive ? '' : 'show active'; ?>" id="template-pane" role="tabpanel">
            <div class="row g-4">
              <div class="col-lg-7">
                <div class="su-label mb-1"><i class="bi bi-download me-1 text-success"></i>Download the official template</div>
                <p class="su-hint mb-2">Download the template, fill in the scores, then upload the file below.</p>
                <a class="btn btn-outline-success btn-sm mb-3" href="?exam_id=<?php echo $exam_id; ?>&class_id=<?php echo $class_id; ?>&subject_id=<?php echo $subject_id; ?>&download=1"><i class="bi bi-file-earmark-excel me-1"></i>Download template (.xlsx)</a>
                <div class="alert alert-light border small py-2 mb-0">
                  <i class="bi bi-people me-1 text-primary"></i> The template, scan and score entry use <b><?php echo count($sheetCands); ?></b> students
                  <?php echo $regIds === null ? 'from the whole class (no subject enrolment yet).' : 'enrolled in this subject.'; ?>
                  <?php if (in_array($u['role'], ['admin', 'super'], true)): ?>
                    <a class="ms-1" href="<?php echo h(url('admin/subject_students.php?id=' . $subject_id . '&class_id=' . $class_id . '&exam_id=' . $exam_id)); ?>">Change enrolment</a>
                  <?php endif; ?>
                </div>
              </div>
              <div class="col-lg-5">
                <div class="su-label mb-1"><i class="bi bi-upload me-1 text-primary"></i>Upload the filled Excel file</div>
                <p class="su-hint mb-2">Only .xlsx files (max 5MB).</p>
                <form method="post" enctype="multipart/form-data">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                  <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                  <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                  <input type="hidden" name="action_type" value="upload">
                  <input type="file" name="file" accept=".xlsx" class="form-control form-control-sm" required>
                  <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-cloud-arrow-up me-1"></i>Upload scores</button>
                </form>
              </div>
            </div>
          </div>

          <!-- Scan Image (OCR) Pane -->
          <div class="tab-pane fade su-pane <?php echo $photoActive ? 'show active' : ''; ?>" id="photo-pane" role="tabpanel">
            <div class="alert alert-info small py-2">
              <b>How it works:</b> take a photo of the filled template (or choose an existing image), then click <b>Read image</b>. The system will read the scores and show a review table. <b>Verify all scores before saving</b> &mdash; OCR can make mistakes, especially with handwriting. <span class="d-block mt-1">An internet connection is needed the first time to download the OCR engine.</span>
            </div>
            <form method="post" id="ocrForm" enctype="multipart/form-data">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
              <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
              <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
              <input type="hidden" name="action_type" value="ocr_confirm">
              <input type="hidden" name="ocr_text" id="ocrText" value="<?php echo h($_POST['ocr_text'] ?? ''); ?>">
              <input type="hidden" name="ocr_words" id="ocrWords" value="<?php echo h($_POST['ocr_words'] ?? ''); ?>">
              <input type="hidden" name="ocr_lines" id="ocrLines" value="<?php echo h($_POST['ocr_lines'] ?? ''); ?>">

              <div class="row g-3">
                <div class="col-md-7">
                  <label class="form-label su-label" for="ocrFile">Template image (.jpg, .png)</label>
                  <input type="file" id="ocrFile" accept="image/*" class="form-control form-control-sm">
                  <div class="form-text small">On mobile you can take a photo directly. Make sure the image is clear, flat, and all scores are visible.</div>

                  <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="button" id="ocrRun" class="btn btn-success btn-sm"><i class="bi bi-camera me-1"></i>Read image</button>
                    <button type="submit" id="ocrSave" class="btn btn-primary btn-sm" <?php echo $ocr_review_html !== '' ? '' : 'disabled'; ?>><i class="bi bi-save me-1"></i>Save verified scores</button>
                  </div>

                  <div class="progress mt-3" style="height:6px"><div id="ocrBar" class="progress-bar" style="width:0%"></div></div>
                  <div id="ocrStatus" class="small text-muted mt-1"></div>
                </div>
                <div class="col-md-5">
                  <img id="ocrPreview" class="img-fluid rounded border w-100 d-none" style="max-height:320px;object-fit:contain" alt="Selected image">
                </div>
              </div>

              <div id="ocrReview" class="mt-3"><?php echo $ocr_review_html; ?></div>
            </form>
            <noscript><div class="alert alert-warning small mt-2">Image reading requires JavaScript. Use Excel upload or direct entry.</div></noscript>
            <p class="small text-muted mt-2 mb-0">Reading happens in your browser; the image is not stored on the server.</p>
          </div>

          <!-- Scan / Direct Input Pane -->
          <div class="tab-pane fade su-pane" id="scan-pane" role="tabpanel">
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
              <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
              <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
              <input type="hidden" name="action_type" value="scan">

              <div class="row g-3">
                <div class="col-md-5">
                  <label class="form-label su-label">Admission No / Barcode</label>
                  <input type="text" name="scan_admission" class="form-control form-control-sm" placeholder="Scan or type the admission number..." required autofocus autocomplete="off">
                </div>
                <div class="col-md-4">
                  <label class="form-label su-label">Score (0-100 or ABS)</label>
                  <input type="text" name="scan_score" class="form-control form-control-sm" placeholder="Enter score..." required autocomplete="off">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                  <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-save me-1"></i>Save score</button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>
        <script>
        (function () {
          'use strict';
          var form = document.getElementById('ocrForm');
          if (!form) return;

          var fileInput = document.getElementById('ocrFile');
          var runBtn = document.getElementById('ocrRun');
          var saveBtn = document.getElementById('ocrSave');
          var preview = document.getElementById('ocrPreview');
          var review = document.getElementById('ocrReview');
          var statusEl = document.getElementById('ocrStatus');
          var bar = document.getElementById('ocrBar');
          var ocrText = document.getElementById('ocrText');
          var ocrWords = document.getElementById('ocrWords');
          var ocrLines = document.getElementById('ocrLines');
          var workerPromise = null;

          function setStatus(msg) { statusEl.textContent = msg || ''; }
          function setProgress(p) { bar.style.width = Math.max(0, Math.min(100, p)) + '%'; }

          function loadImage(file) {
            return new Promise(function (resolve, reject) {
              var url = URL.createObjectURL(file);
              var img = new Image();
              img.onload = function () { resolve({ img: img, url: url }); };
              img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('The image cannot be read. Try another image.')); };
              img.src = url;
            });
          }

          // Nyoroa picha, ongeza ukolezi na uipunguze/kuipanua kwa OCR.
          function prepare(img) {
            var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
            var maxW = 2200, maxH = 3200, s;
            if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
            if (w < 1400) { s = 1400 / w; w = Math.round(w * s); h = Math.round(h * s); }
            if (h > maxH) { s = maxH / h; h = maxH; w = Math.round(w * s); }
            var c = document.createElement('canvas');
            c.width = w; c.height = h;
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            try {
              var id = ctx.getImageData(0, 0, w, h), d = id.data, i, g;
              for (i = 0; i < d.length; i += 4) {
                g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
                g = (g - 128) * 1.3 + 128;
                g = g < 0 ? 0 : (g > 255 ? 255 : g);
                d[i] = d[i + 1] = d[i + 2] = g;
              }
              ctx.putImageData(id, 0, 0);
            } catch (e) { /* canvas imezuiwa; tumia picha kama ilivyo */ }
            return c.toDataURL('image/png');
          }

          function getWorker() {
            if (workerPromise) return workerPromise;
            if (typeof Tesseract === 'undefined') {
              return Promise.reject(new Error('The OCR engine was not found. Make sure you are online, then refresh the page.'));
            }
            setStatus('Loading OCR engine...');
            workerPromise = Tesseract.createWorker('eng', 1, {
              workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js',
              corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1',
              langPath: 'https://tessdata.projectnaptha.com/4.0.0',
              logger: function (m) {
                if (!m || !m.status) return;
                var pct = m.progress ? Math.round(m.progress * 100) : 0;
                if (m.status === 'recognizing text') { setProgress(pct); setStatus('Reading image... ' + pct + '%'); }
                else if (m.status.indexOf('loading') === 0 || m.status.indexOf('initializ') === 0) setStatus('Preparing OCR... ' + pct + '%');
              }
            }).then(function (w) {
              return w.setParameters({ tessedit_pageseg_mode: Tesseract.PSM.SINGLE_BLOCK, preserve_interword_spaces: '1' })
                .then(function () { return w; });
            }).catch(function (e) { workerPromise = null; throw e; });
            return workerPromise;
          }

          function flattenWords(data) {
            if (Array.isArray(data.words) && data.words.length) return data.words;
            var out = [];
            (data.blocks || []).forEach(function (b) {
              (b.paragraphs || []).forEach(function (p) {
                (p.lines || []).forEach(function (l) {
                  (l.words || []).forEach(function (w) { if (w && w.text) out.push(w); });
                });
              });
            });
            if (!out.length) {
              (data.lines || []).forEach(function (l) {
                (l.words || []).forEach(function (w) { if (w && w.text) out.push(w); });
              });
            }
            return out;
          }

          // Mistari halisi ya Tesseract: bora kwa picha zilizopinda/kuinama.
          function flattenLines(data) {
            var out = [];
            function addLines(lines) {
              (lines || []).forEach(function (l) {
                if (!l) return;
                var ws = (l.words || []).map(function (w) {
                  return { text: w.text, confidence: w.confidence, bbox: w.bbox };
                }).filter(function (w) { return w.text; });
                if (!ws.length && l.text) {
                  out.push({ text: String(l.text), confidence: l.confidence || 0, words: [] });
                  return;
                }
                out.push({ text: l.text || '', confidence: l.confidence || 0, words: ws });
              });
            }
            (data.blocks || []).forEach(function (b) {
              (b.paragraphs || []).forEach(function (p) { addLines(p.lines); });
            });
            if (!out.length) addLines(data.lines);
            return out;
          }

          fileInput.addEventListener('change', function () {
            saveBtn.disabled = true;
            review.innerHTML = '';
            ocrText.value = ''; ocrWords.value = ''; ocrLines.value = '';
            var f = fileInput.files && fileInput.files[0];
            if (!f) { preview.classList.add('d-none'); preview.removeAttribute('src'); return; }
            preview.src = URL.createObjectURL(f);
            preview.classList.remove('d-none');
            setProgress(0);
            setStatus('Image selected. Click "Read image".');
          });

          runBtn.addEventListener('click', function () {
            var f = fileInput.files && fileInput.files[0];
            if (!f) { setStatus('Please select an image first.'); return; }
            runBtn.disabled = true;
            saveBtn.disabled = true;
            setProgress(0);
            setStatus('Preparing image...');

            loadImage(f).then(function (res) {
              var dataUrl = prepare(res.img);
              URL.revokeObjectURL(res.url);
              preview.src = dataUrl;
              preview.classList.remove('d-none');
              return getWorker().then(function (w) {
                setStatus('Reading image...');
                return w.recognize(dataUrl);
              });
            }).then(function (out) {
              var data = (out && out.data) || {};
              var words = flattenWords(data).map(function (w) {
                return { text: w.text, confidence: w.confidence, bbox: w.bbox };
              });
              var lines = flattenLines(data);
              ocrText.value = data.text || '';
              ocrWords.value = JSON.stringify(words);
              ocrLines.value = JSON.stringify(lines);

              var fd = new FormData(form);
              fd.delete('ocrFile');
              fd.set('action_type', 'ocr_parse');
              fd.set('ocr_text', ocrText.value);
              fd.set('ocr_words', ocrWords.value);
              fd.set('ocr_lines', ocrLines.value);
              return fetch(window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
              }).then(function (r) {
                return r.json().catch(function () { throw new Error('The server response is invalid.'); });
              });
            }).then(function (json) {
              if (!json || !json.ok) throw new Error((json && json.error) || 'Could not read the image.');
              review.innerHTML = json.html || '';
              saveBtn.disabled = false;
              setProgress(100);
              setStatus('Read ' + json.found + ' of ' + json.total + ' scores. Verify the table, then save.');
            }).catch(function (err) {
              setStatus((err && err.message) ? err.message : 'Error while reading the image.');
              setProgress(0);
            }).then(function () {
              runBtn.disabled = false;
            });
          });

          // Usitume picha kwenye POST ya kuhifadhi; maandishi/word/line pekee ndiyo yanahitajika.
          form.addEventListener('submit', function () {
            if (fileInput) fileInput.disabled = true;
          });
        })();
        </script>

        <?php else: ?>
        <div class="alert alert-warning small mb-0">Please select a valid class and subject first.</div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>