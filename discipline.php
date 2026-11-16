<?php
/**
 * Discipline Scores: alama za nidhamu za darasa kwa mtihani mmoja.
 * Jedwali la watahiniwa x vipimo vya nidhamu (A-F), wastani, gredi na gredi ya shule.
 */
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/layout.php';
$u = require_school_staff(); $school_id = active_school_id();
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0);
$exam = get_exam($exam_id);
if (!$exam || !exam_has_school($exam_id, $school_id)) { flash('danger', 'Exam not found or does not include this school.'); redirect(home_url()); }
if (!q_val('SELECT 1 FROM exam_classes WHERE exam_id = ? AND class_id = ?', [$exam_id, $class_id])) deny('Darasa si la mtihani huu.');
if ($u['role'] === 'teacher' && !q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ?', [$u['id'], $class_id])) deny('Huna ruhusa ya darasa hili.');

$crit   = discipline_criteria();
$grades = discipline_grades();
// Majina mafupi ya vichwa vya jedwali (maelezo kamili yanaonekana chini ya jedwali).
$short = [
    'kazi'       => 'Bidii na maarifa',
    'utiifu'     => 'Utiifu na uaminifu',
    'kuthamini'  => 'Kuthamini kazi',
    'uangalifu'  => 'Uangalifu wa mali',
    'michezo'    => 'Michezo na utamaduni',
    'heshima'    => 'Heshima',
    'uongozi'    => 'Uongozi',
    'mahudhurio' => 'Mahudhurio',
    'usafi'      => 'Usafi binafsi',
    'nidhamu'    => 'Nidhamu',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    discipline_save($exam_id, $class_id, (array)($_POST['g'] ?? []));
    flash('success', 'Discipline scores saved.');
    redirect(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id));
}

$d = compute_results($exam_id, $class_id, $school_id);
$cands = $d['candidates'];
$clsName = (string)($d['class']['name'] ?? '');
$saved = discipline_get($exam_id, $class_id);

$rowsOut = []; $sumAvg = 0; $nAvg = 0;
foreach ($cands as $c) {
    // Gredi ya matokeo (wastani) ndiyo inayozalisha gredi za nidhamu kiotomatiki.
    $acad = $c['average'] === null ? '' : (string)grade_for($c['average'], $d['grading'])['grade'];
    $g = array_intersect_key($saved[(int)$c['student_id']] ?? [], $crit);
    if (!$g) $g = discipline_auto_grades($acad);
    $avg = discipline_student_avg($g);
    if ($avg !== null) { $sumAvg += $avg; $nAvg++; }
    $rowsOut[] = ['c' => $c, 'g' => $g, 'avg' => $avg, 'grade' => discipline_grade($avg), 'auto' => $acad];
}
$schoolAvg = $nAvg ? round($sumAvg / $nAvg, 2) : null;
$schoolGrade = discipline_grade($schoolAvg);
$gradeBadge = ['A' => 'success', 'B' => 'success', 'C' => 'warning', 'D' => 'warning', 'F' => 'danger'];
$examName = trim((string)($exam['name'] ?? ''));
$examYear = trim((string)($exam['year_name'] ?? ''));
$termLabel = report_term_code((string)($exam['exam_type'] ?? '')) === 'II' ? 'PILI' : 'KWANZA';

/* ---------------- Export: PDF / Excel ---------------- */
if (isset($_GET['export'])) {
    $kind = strtolower((string)$_GET['export']);
    $head = ['S/N', 'INDEX NUMBER'];
    foreach ($crit as $key => $label) $head[] = $short[$key] ?? $label;
    $head[] = 'AVERAGE'; $head[] = 'GRADE';

    $rows = [];
    $rows[] = [strtoupper($examName) . ' ' . $examYear . ' — ' . strtoupper($clsName) . ' — DISCIPLINE SCORES'];
    $rows[] = $head;
    $sn = 0;
    foreach ($rowsOut as $ro) {
        $sn++;
        $r = [$sn, (string)$ro['c']['cand_no']];
        foreach ($crit as $key => $label) $r[] = (string)($ro['g'][$key] ?? '');
        $r[] = $ro['avg'] === null ? '' : number_format((float)$ro['avg'], 2);
        $r[] = $ro['grade'];
        $rows[] = $r;
    }
    $rows[] = [];
    $rows[] = ['SCHOOL AVERAGE', $schoolAvg === null ? '-' : number_format($schoolAvg, 2), 'SCHOOL GRADE', $schoolGrade];

    $fname = 'Discipline_' . preg_replace('/[^A-Za-z0-9]+/', '_', $clsName !== '' ? $clsName : 'Class') . '_' . $exam_id;

    if ($kind === 'xlsx') {
        send_rows_xlsx($fname, $rows, 'Discipline');
    }
    if ($kind === 'pdf') {
        $th = ''; foreach ($head as $hh) $th .= '<th>' . h($hh) . '</th>';
        $body = '';
        $sn = 0;
        foreach ($rowsOut as $ro) {
            $sn++;
            $body .= '<tr><td class="c">' . $sn . '</td><td class="c">' . h((string)$ro['c']['cand_no']) . '</td>';
            foreach ($crit as $key => $label) $body .= '<td class="c">' . h((string)($ro['g'][$key] ?? '')) . '</td>';
            $body .= '<td class="c b">' . ($ro['avg'] === null ? '-' : h(number_format((float)$ro['avg'], 2))) . '</td>'
                   . '<td class="c b">' . h($ro['grade'] !== '' ? $ro['grade'] : '-') . '</td></tr>';
        }
        if (!$rowsOut) $body = '<tr><td colspan="' . count($head) . '" class="c">No candidates.</td></tr>';
        $legend = '';
        $i = 0;
        foreach ($crit as $key => $label) { $i++; $legend .= '<span><b>' . $i . '.</b> ' . h($label) . '</span>'; }

        $html = '<div class="dhead">' . h(strtoupper((string)($d['school']['school_name'] ?? ''))) . '</div>'
              . '<div class="dsub">' . h(strtoupper($examName) . ' ' . $examYear) . ' &nbsp;|&nbsp; ' . h(strtoupper($clsName))
              . ' &nbsp;|&nbsp; SCHOOL GRADE: <b>' . h($schoolGrade !== '' ? $schoolGrade : '-') . '</b>'
              . ' &nbsp;|&nbsp; AVERAGE: <b>' . h($schoolAvg === null ? '-' : number_format($schoolAvg, 2)) . '</b></div>'
              . '<div class="dtitle">DISCIPLINE SCORES</div>'
              . '<table><thead><tr>' . $th . '</tr></thead><tbody>' . $body . '</tbody></table>'
              . '<div class="legend">' . $legend . '</div>'
              . '<style>.dhead{text-align:center;font-weight:bold;font-size:11pt}.dsub{text-align:center;font-size:8px;margin:2px 0 6px}'
              . '.dtitle{text-align:center;font-weight:bold;font-size:10pt;text-decoration:underline;margin-bottom:6px}'
              . 'table{width:100%;border-collapse:collapse;table-layout:fixed}'
              . 'th,td{border:1px solid #456;padding:3px 2px;font-size:7px;word-wrap:break-word}'
              . 'th{background:#dbe6f7;font-size:6px;text-transform:uppercase;text-align:center}'
              . '.c{text-align:center}.b{font-weight:bold}'
              . '.legend{margin-top:8px;font-size:7px}.legend span{display:block;line-height:1.3}</style>';
        send_pdf($html, 'Discipline Scores', $fname, true);
    }
    redirect(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id));
}

layout_start('Discipline Scores', $u['role'] !== 'teacher' ? 'exams' : 'results'); ?>
<style>
.dsc-hero{background:linear-gradient(135deg,#0b3d91 0%,#123f8f 45%,#2c6ed5 100%);color:#fff;border-radius:18px;padding:22px 24px;box-shadow:0 12px 26px rgba(11,61,145,.22);position:relative;overflow:hidden}
.dsc-hero:after{content:"";position:absolute;right:-60px;top:-70px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08)}
.dsc-kicker{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;opacity:.82;font-weight:700}
.dsc-title{font-size:1.32rem;font-weight:800;line-height:1.25}
.dsc-chip{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:4px 12px;font-size:.76rem;font-weight:600;white-space:nowrap}
.dsc-card{border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 6px 18px rgba(11,61,145,.07);overflow:hidden;background:#fff}
.dsc-card .dsc-head{background:#f7f9fe;border-bottom:1px solid #e7ecf7;padding:12px 16px}
.dsc-tbl{table-layout:fixed;width:100%;min-width:0;font-size:.7rem;border:1px solid #a9bfe0}
.dsc-tbl th{background:#eef3fb;color:#0b3d91;font-size:.58rem;line-height:1.2;text-transform:uppercase;letter-spacing:0;vertical-align:bottom;padding:7px 3px;text-align:center;white-space:normal;overflow-wrap:break-word;word-break:break-word;hyphens:auto}
.dsc-tbl th,.dsc-tbl td{border:1px solid #c7d5ec}
.dsc-tbl thead th{background:#e7effc;border-color:#a9bfe0}
.dsc-tbl tbody tr:hover td{background:#f6f9ff}
.dsc-tbl select{border:1px solid #b9c9e0;border-radius:6px;background:#fff}
.dsc-tbl td{vertical-align:middle;padding:3px 2px;white-space:normal;word-break:break-word;overflow-wrap:break-word}
.dsc-tbl select{width:100%;min-width:0;font-weight:700;font-size:.7rem;padding:.15rem .05rem;text-align:center;text-align-last:center;-webkit-appearance:none;appearance:none;background-image:none;cursor:pointer}
.dsc-tbl select:focus{outline:2px solid #86b7fe;outline-offset:1px}
.dsc-tbl .dsc-nm{display:none}
@media (min-width:1400px){ .dsc-tbl .dsc-nm{display:block} }
.dsc-avg{font-weight:800;color:#0b3d91}
.dsc-badge{display:inline-block;min-width:30px;padding:.2rem .45rem;border-radius:8px;font-weight:800;font-size:.75rem}
.dsc-empty{color:#6c757d;padding:26px 16px;text-align:center}
</style>

<div class="dsc-hero mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 position-relative">
    <div>
      <div class="dsc-kicker">Tabia na Maadili</div>
      <div class="dsc-title">Discipline Scores</div>
      <div class="d-flex flex-wrap gap-2 mt-2">
        <span class="dsc-chip"><i class="bi bi-journal-text me-1"></i><?php echo h($examName); ?></span>
        <span class="dsc-chip"><i class="bi bi-people me-1"></i><?php echo h($clsName); ?></span>
        <span class="dsc-chip"><i class="bi bi-calendar3 me-1"></i>Muhula wa <?php echo h($termLabel); ?> <?php echo h($examYear); ?></span>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-light btn-sm fw-semibold" href="<?php echo h(url('exam_reports.php?exam_id=' . $exam_id . '&class_id=' . $class_id)); ?>"><i class="bi bi-arrow-left me-1"></i>Reports</a>
    </div>
  </div>
</div>

<div class="row g-2 g-md-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card dsc-card h-100"><div class="card-body py-3">
      <div class="small text-uppercase fw-bold text-muted" style="letter-spacing:.06em">School Grade</div>
      <div class="d-flex align-items-center gap-2 mt-1">
        <span class="dsc-badge bg-<?php echo h($gradeBadge[$schoolGrade] ?? 'light'); ?><?php echo $schoolGrade === '' ? ' text-dark border' : ' text-white'; ?>" style="font-size:1.15rem;min-width:44px;text-align:center"><?php echo h($schoolGrade !== '' ? $schoolGrade : '–'); ?></span>
        <div class="small text-muted">Wastani wa shule:<br><b class="dsc-avg"><?php echo $schoolAvg === null ? '–' : h(number_format($schoolAvg, 2)); ?></b> / 5</div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card dsc-card h-100"><div class="card-body py-3">
      <div class="small text-uppercase fw-bold text-muted" style="letter-spacing:.06em">Candidates</div>
      <div class="fw-bold" style="font-size:1.35rem;color:#0b3d91"><?php echo count($cands); ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card dsc-card h-100"><div class="card-body py-3">
      <div class="small text-uppercase fw-bold text-muted" style="letter-spacing:.06em">With scores</div>
      <div class="fw-bold" style="font-size:1.35rem;color:#0b3d91"><?php echo $nAvg; ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card dsc-card h-100"><div class="card-body py-3">
      <div class="small text-uppercase fw-bold text-muted" style="letter-spacing:.06em">Criteria</div>
      <div class="fw-bold" style="font-size:1.35rem;color:#0b3d91"><?php echo count($crit); ?></div>
    </div></div>
  </div>
</div>

<form method="post" action="<?php echo h(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id)); ?>">
<?php echo csrf_field(); ?>
<div class="card dsc-card">
  <div class="dsc-head d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="fw-bold small text-uppercase" style="letter-spacing:.06em;color:#0b3d91"><i class="bi bi-clipboard-check me-1"></i>Discipline Scores — <?php echo h($clsName); ?></div>
      <div class="text-muted" style="font-size:.72rem"><i class="bi bi-stars me-1"></i>Gredi hutengenezwa kiotomatiki kutoka gredi ya matokeo ya mwanafunzi; unaweza kubadilisha kabla ya kuhifadhi.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-danger btn-sm fw-semibold" href="<?php echo h(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&export=pdf')); ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      <a class="btn btn-outline-success btn-sm fw-semibold" href="<?php echo h(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&export=xlsx')); ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
      <button type="button" id="dscAuto" class="btn btn-outline-primary btn-sm fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Auto-generate</button>
      <button type="submit" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-save me-1"></i>Save</button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle dsc-tbl" id="dscTable">
      <colgroup>
        <col style="width:3%"><col style="width:8%">
        <?php foreach ($crit as $label): ?><col style="width:7.85%"><?php endforeach; ?>
        <col style="width:5%"><col style="width:4.5%">
      </colgroup>
      <thead><tr>
        <th>S/N</th>
        <th>Index Number</th>
        <?php foreach ($crit as $key => $label): ?><th title="<?php echo h($label); ?>"><?php echo h($short[$key] ?? $label); ?></th><?php endforeach; ?>
        <th class="text-center">Average</th>
        <th class="text-center">Grade</th>
      </tr></thead>
      <tbody>
      <?php $sn = 0; foreach ($rowsOut as $ro): $sn++; $c = $ro['c']; ?>
      <tr data-auto="<?php echo h($ro['auto']); ?>">
        <td class="text-muted"><?php echo $sn; ?></td>
        <td><div class="fw-bold" style="color:#0b3d91;font-size:.66rem"><?php echo h($c['cand_no']); ?></div>
          <div class="text-muted dsc-nm" style="font-size:.6rem;line-height:1.1" title="<?php echo h($c['name']); ?>"><?php echo h(mb_substr((string)$c['name'], 0, 16)); ?></div></td>
        <?php foreach ($crit as $key => $label): $val = $ro['g'][$key] ?? ''; ?>
        <td class="text-center">
          <select class="form-select form-select-sm dsc-sel" name="g[<?php echo (int)$c['student_id']; ?>][<?php echo h($key); ?>]" aria-label="<?php echo h($label); ?>">
            <option value=""></option>
            <?php foreach ($grades as $gr): ?><option value="<?php echo h($gr); ?>"<?php echo $val === $gr ? ' selected' : ''; ?><?php echo $ro['auto'] === $gr ? ' data-auto' : ''; ?>><?php echo h($gr); ?></option><?php endforeach; ?>
          </select>
        </td>
        <?php endforeach; ?>
        <td class="text-center dsc-avg"><?php echo $ro['avg'] === null ? '–' : h(number_format((float)$ro['avg'], 2)); ?></td>
        <td class="text-center"><span class="dsc-badge bg-<?php echo h($gradeBadge[$ro['grade']] ?? 'light'); ?><?php echo $ro['grade'] === '' ? ' text-dark border' : ' text-white'; ?>" style="min-width:0;padding:.15rem .3rem;font-size:.68rem"><?php echo h($ro['grade'] !== '' ? $ro['grade'] : '–'); ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rowsOut): ?><tr><td colspan="<?php echo 4 + count($crit); ?>" class="dsc-empty"><i class="bi bi-inbox fs-4 d-block mb-1"></i>No candidates in this class.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($rowsOut): ?>
  <div class="dsc-head d-flex justify-content-end align-items-center gap-2 border-top" style="border-bottom:0">
    <div class="small text-muted me-auto">A = 5, B = 4, C = 3, D = 2, F = 1</div>
    <button type="submit" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-save me-1"></i>Save</button>
  </div>
  <?php endif; ?>
</div>
</form>
<script>
(function () {
  var pts = { A: 5, B: 4, C: 3, D: 2, F: 1 };
  function gradeFor(v) {
    if (v === null) return '';
    if (v >= 4.5) return 'A';
    if (v >= 3.5) return 'B';
    if (v >= 2.5) return 'C';
    if (v >= 1.5) return 'D';
    return 'F';
  }
  var colors = { A: 'success', B: 'success', C: 'warning', D: 'warning', F: 'danger' };
  function paint(el, g) {
    el.className = 'dsc-badge bg-' + (colors[g] || 'light') + (g ? ' text-white' : ' text-dark border');
    el.textContent = g || '–';
  }
  var table = document.getElementById('dscTable');
  if (!table) return;
  var autoBtn = document.getElementById('dscAuto');
  if (autoBtn) autoBtn.addEventListener('click', function () {
    table.querySelectorAll('tbody tr[data-auto]').forEach(function (tr) {
      var g = tr.getAttribute('data-auto') || '';
      tr.querySelectorAll('.dsc-sel').forEach(function (s) {
        s.value = g;
        s.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });
  table.querySelectorAll('tbody tr').forEach(function (tr) {
    var sels = tr.querySelectorAll('.dsc-sel');
    if (!sels.length) return;
    var avgCell = tr.querySelector('.dsc-avg');
    var badge = tr.querySelector('.dsc-badge');
    function calc() {
      var sum = 0, n = 0;
      sels.forEach(function (s) { if (pts[s.value]) { sum += pts[s.value]; n++; } });
      var avg = n ? sum / n : null;
      if (avgCell) avgCell.textContent = avg === null ? '–' : avg.toFixed(2);
      if (badge) paint(badge, n ? gradeFor(avg) : '');
    }
    sels.forEach(function (s) { s.addEventListener('change', calc); });
  });
})();
</script>
<?php layout_end();
