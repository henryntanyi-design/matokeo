<?php
/** Exam report forms: individual student reports (PDF) for an exam and class. */
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/layout.php';
$u = require_school_staff(); $school_id = active_school_id();
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0);
$exam = get_exam($exam_id);
if (!$exam || !exam_has_school($exam_id, $school_id)) { flash('danger', 'Exam not found or does not include this school.'); redirect(home_url()); }
$classes = exam_class_list($exam_id);
if ($u['role'] === 'teacher') $classes = array_values(array_filter($classes, function ($c) use ($u) { return (bool)q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ?', [$u['id'], $c['id']]); }));
$ids = array_map('intval', array_column($classes, 'id'));
if (!$class_id && count($ids) === 1) $class_id = $ids[0];
if (!$class_id || !in_array($class_id, $ids, true)) {
    if ($class_id) deny('Huna ruhusa ya kuona ripoti za darasa hili.');
    layout_start('Exam Report Forms', $u['role'] !== 'teacher' ? 'exams' : 'results');
    echo '<div class="card shadow-sm"><div class="card-body"><p class="fw-semibold">Select a class:</p>';
    foreach ($classes as $c) echo '<a class="btn btn-outline-primary me-2 mb-2" href="?exam_id=' . $exam_id . '&class_id=' . (int)$c['id'] . '">' . h($c['name']) . '</a>';
    if (!$classes) echo '<div class="text-muted">No classes available.</div>';
    echo '</div></div>'; layout_end(); exit();
}
$d = compute_results($exam_id, $class_id, $school_id);
$cands = $d['candidates'];
$clsName = (string)($d['class']['name'] ?? '');
$nCand = count($cands);
$nOK = 0; $nPass = 0; $ptsSum = 0; $avgSum = 0; $avgN = 0;
foreach ($cands as $c) {
    if ($c['state'] === 'OK') $nOK++;
    if (in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $nPass++;
    if ($c['points'] !== null) $ptsSum += (int)$c['points'];
    if ($c['average'] !== null) { $avgSum += $c['average']; $avgN++; }
}
$centreGpa = centre_gpa($d);
$schoolAvg = $avgN ? round($avgSum / $avgN, 1) : null;
$schoolGrade = $schoolAvg === null ? '' : (string)grade_for($schoolAvg, $d['grading'])['grade'];
$divBadge = ['I' => 'success', 'II' => 'success', 'III' => 'warning', 'IV' => 'warning', '0' => 'danger', 'INC' => 'secondary', 'ABS' => 'secondary', 'PENDING' => 'light'];
$gradeBadge = ['A' => 'success', 'B' => 'success', 'C' => 'info', 'D' => 'warning', 'F' => 'danger'];
layout_start('Exam Report Forms', $u['role'] !== 'teacher' ? 'exams' : 'results'); ?>
<style>
.erf-hero{background:linear-gradient(135deg,#0b3d91 0%,#123f8f 45%,#2c6ed5 100%);color:#fff;border-radius:18px;padding:22px 24px;box-shadow:0 12px 26px rgba(11,61,145,.22);position:relative;overflow:hidden}
.erf-hero:after{content:"";position:absolute;right:-60px;top:-70px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08)}
.erf-hero:before{content:"";position:absolute;right:60px;bottom:-90px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.06)}
.erf-kicker{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;opacity:.82;font-weight:700}
.erf-title{font-size:1.32rem;font-weight:800;line-height:1.25}
.erf-chip{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:4px 12px;font-size:.76rem;font-weight:600;white-space:nowrap}
.erf-stat{background:#fff;border:1px solid #e7ecf7;border-radius:14px;padding:12px 14px;box-shadow:0 4px 14px rgba(11,61,145,.06);height:100%}
.erf-stat .v{font-size:1.35rem;font-weight:800;color:#0b3d91;line-height:1.1}
.erf-stat .l{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;font-weight:700}
.erf-stat .ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex:0 0 auto}
.erf-card{border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 6px 18px rgba(11,61,145,.07);overflow:hidden;background:#fff}
.erf-card .erf-head{background:#f7f9fe;border-bottom:1px solid #e7ecf7;padding:12px 16px}
.erf-row td{vertical-align:middle}
.erf-row:hover{background:#f7f9fe}
.erf-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0b3d91,#2c6ed5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;letter-spacing:.02em;flex:0 0 auto}
.erf-cand{font-weight:700;color:#0b3d91;font-size:.82rem}
.erf-name{font-weight:600;font-size:.88rem}
.erf-actions .btn{border-radius:9px;font-weight:600;font-size:.75rem}
.erf-empty{color:#6c757d;padding:26px 16px;text-align:center}
</style>
<div class="erf-hero mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 position-relative">
    
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-light btn-sm fw-semibold" href="<?php echo h(url('results.php?exam_id=' . $exam_id . '&class_id=' . $class_id)); ?>"><i class="bi bi-arrow-left me-1"></i>Results</a>
      <?php if (is_admin()): ?><a class="btn btn-outline-light btn-sm fw-semibold" href="<?php echo h(url('admin/report_info.php?class_id=' . $class_id . '&term=' . report_term_code((string)($exam['exam_type'] ?? '')))); ?>"><i class="bi bi-clipboard2-check me-1"></i>Report Info</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-2 g-md-3 mb-3">
  <div class="col-6 col-md-3"><div class="erf-stat d-flex align-items-center gap-2">
    <div class="ic" style="background:#e8f0ff;color:#0b3d91"><i class="bi bi-people-fill"></i></div>
    <div><div class="v"><?php echo $nCand; ?></div><div class="l">Candidates</div></div></div></div>
  <div class="col-6 col-md-3"><div class="erf-stat d-flex align-items-center gap-2">
    <div class="ic" style="background:#e6f6ec;color:#146c2e"><i class="bi bi-check2-circle"></i></div>
    <div><div class="v"><?php echo $nPass; ?></div><div class="l">Passed (I–IV)</div></div></div></div>
  <div class="col-6 col-md-3"><div class="erf-stat d-flex align-items-center gap-2">
    <div class="ic" style="background:#fff4e5;color:#b35c00"><i class="bi bi-graph-up-arrow"></i></div>
    <div><div class="v"><?php echo $centreGpa === null ? '–' : number_format($centreGpa, 4); ?></div><div class="l">Centre GPA</div></div></div></div>
  <div class="col-6 col-md-3"><div class="erf-stat d-flex align-items-center gap-2">
    <div class="ic" style="background:#f1f4fb;color:#33415c"><i class="bi bi-bar-chart-line"></i></div>
    <div><div class="v"><?php echo $schoolAvg === null ? '–' : number_format($schoolAvg, 1); ?>
      <?php if ($schoolGrade !== ''): ?><span class="badge rounded-pill bg-<?php echo h($gradeBadge[$schoolGrade] ?? 'primary'); ?> align-middle ms-1" style="font-size:.72rem"><?php echo h($schoolGrade); ?></span><?php endif; ?></div>
      <div class="l">School Average / Grade</div></div></div></div>
</div>

<div class="card erf-card">
  <div class="erf-head">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="fw-bold small text-uppercase" style="letter-spacing:.06em;color:#0b3d91"><i class="bi bi-journal-text me-1"></i>Student Reports (individual)</div>
      <div class="d-flex align-items-center gap-2 ms-auto">
        <a class="btn btn-outline-primary btn-sm fw-semibold" href="<?php echo h(url('discipline.php?exam_id=' . $exam_id . '&class_id=' . $class_id)); ?>"><i class="bi bi-clipboard-check me-1"></i>Discipline Scores</a>
        <button type="submit" form="erfBulk" id="erfBulkBtn" class="btn btn-danger btn-sm fw-semibold"><i class="bi bi-download me-1"></i>Download Report</button>
      </div>
    </div>
  </div>
  <form id="erfBulk" method="get" action="<?php echo h(url('export.php')); ?>">
  <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
  <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle" id="erfTable">
      <thead><tr>
        <th class="ps-3" style="width:52px">#</th>
        <th>Student</th>
        <th style="width:120px">Cand. No</th>
        <th style="width:90px">Division</th>
        <th style="width:80px" class="text-center">Points</th>
        <th style="width:80px" class="text-center">Average</th>
        <th style="width:70px" class="text-center">Grade</th>
        <th class="text-end" style="width:110px">Report</th>
        <th class="text-center pe-3" style="width:60px">Select</th>
      </tr></thead>
      <tbody>
      <?php $sn = 0; foreach ($cands as $c): $sn++;
        $ini = ''; foreach (preg_split('/\s+/', trim((string)$c['name'])) as $w) { if ($w !== '' && mb_strlen($ini) < 2) $ini .= mb_strtoupper(mb_substr($w, 0, 1)); }
        $dv = (string)$c['division'];
        $b = $divBadge[$dv] ?? 'secondary';
      ?>
      <tr class="erf-row" data-search="<?php echo h(mb_strtolower($c['name'] . ' ' . $c['cand_no'] . ' ' . $c['prems_no'])); ?>">
        <td class="ps-3 text-muted"><?php echo $sn; ?></td>
        <td><div class="d-flex align-items-center gap-2"><div class="erf-av"><?php echo h($ini !== '' ? $ini : '?'); ?></div>
          <div><div class="erf-name"><?php echo h(strtoupper($c['name'])); ?></div>
          <div class="small text-muted">PREM: <?php echo h($c['prems_no']); ?></div></div></div></td>
        <td><span class="erf-cand"><?php echo h($c['cand_no']); ?></span></td>
        <td><span class="badge rounded-pill bg-<?php echo h($b); ?><?php echo $b === 'light' ? ' text-dark border' : ''; ?> px-3 py-2"><?php echo h($dv !== '' ? $dv : '-'); ?></span></td>
        <td class="text-center fw-semibold"><?php echo $c['points'] === null ? '–' : (int)$c['points']; ?></td>
        <td class="text-center"><?php echo $c['average'] === null ? '–' : h(number_format((float)$c['average'], 1)); ?></td>
        <td class="text-center"><?php $cg = $c['average'] === null ? '' : (string)grade_for($c['average'], $d['grading'])['grade']; ?>
          <span class="badge bg-<?php echo h($gradeBadge[$cg] ?? 'light'); ?><?php echo $cg === '' ? ' text-dark border' : ''; ?> px-2 py-1"><?php echo h($cg !== '' ? $cg : '–'); ?></span></td>
        <td class="text-end erf-actions">
          <a class="btn btn-danger btn-sm" title="Download student PDF" href="<?php echo h(url('export.php?exam_id=' . $exam_id . '&class_id=' . $class_id . '&student_id=' . (int)$c['student_id'] . '&format=pdf')); ?>"><i class="bi bi-download"></i></a>
        </td>
        <td class="text-center pe-3"><input class="form-check-input erf-check" type="checkbox" name="student_ids[]" value="<?php echo (int)$c['student_id']; ?>" aria-label="Select <?php echo h($c['name']); ?>"></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$cands): ?><tr><td colspan="9" class="erf-empty"><i class="bi bi-inbox fs-4 d-block mb-1"></i>No candidates in this class.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  </form>
</div>
<script>
(function () {
  var all = document.getElementById('erfAll');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.erf-check'));
  var countEl = document.getElementById('erfCount');
  function refresh() {
    var n = checks.filter(function (x) { return x.checked; }).length;
    if (countEl) countEl.textContent = n;
    if (all) all.checked = n > 0 && n === checks.length;
  }
  if (all) all.addEventListener('change', function () {
    checks.forEach(function (x) { x.checked = all.checked; });
    refresh();
  });
  checks.forEach(function (x) { x.addEventListener('change', refresh); });
  var bulk = document.getElementById('erfBulk');
  if (bulk) bulk.addEventListener('submit', function (e) {
    if (!checks.some(function (x) { return x.checked; })) {
      e.preventDefault();
      alert('Please select at least one student first.');
    }
  });
  refresh();
})();
</script>
<?php layout_end();
