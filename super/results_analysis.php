<?php
/** Results Analysis - ukurasa wa muhtasari unaounganisha kurasa za kila sehemu. */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Results Analysis', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d);
extract($d);
ra_page_head('Results Analysis', 'bar-chart-line', $exam, $exam_id, $hasData, $exams, false);

list($divCounts, $passTotal, $totOkDiv, $passRate) = ra_div_totals($sAgg);
$divStyles = ['I' => '#0b8a3e', 'II' => '#2c6ed5', 'III' => '#b06a00', 'IV' => '#d39b1b', '0' => '#b02a37'];
$totDiv = max(1, $passTotal + $divCounts['0']);
?>

<div class="ra-stats">
  <div class="ra-stat"><span class="ico"><i class="bi bi-buildings"></i></span><div><div class="v"><?php echo count($sAgg); ?></div><div class="l">Published schools</div></div></div>
  <div class="ra-stat"><span class="ico"><i class="bi bi-people"></i></span><div><div class="v"><?php echo $totCands; ?></div><div class="l">Candidates</div></div></div>
  <div class="ra-stat"><span class="ico"><i class="bi bi-mortarboard"></i></span><div><div class="v"><?php echo count($classes); ?></div><div class="l">Classes</div></div></div>
  <div class="ra-stat"><span class="ico"><i class="bi bi-book"></i></span><div><div class="v"><?php echo count($subj); ?></div><div class="l">Subjects</div></div></div>
  <div class="ra-stat"><span class="ico"><i class="bi bi-graph-up-arrow"></i></span><div><div class="v"><?php echo $overallGpa !== null ? h(number_format($overallGpa, 2)) : '-'; ?></div><div class="l">Overall GPA</div></div></div>
  <div class="ra-stat"><span class="ico"><i class="bi bi-patch-check"></i></span><div><div class="v"><?php echo $passRate !== null ? h(number_format($passRate, 1)) . '%' : '-'; ?></div><div class="l">Pass rate (I-IV)</div></div></div>
</div>

<div class="ra-div">
  <div class="ra-div-top"><b><i class="bi bi-pie-chart me-1"></i>Division distribution</b><span class="muted"><?php echo (int)$totOkDiv; ?> ranked candidate(s)</span></div>
  <?php if ($totOkDiv > 0): ?>
  <div class="ra-div-bar">
    <?php foreach ($divCounts as $dk => $dn): if (!$dn) continue; ?>
      <span style="width:<?php echo round($dn * 100 / $totDiv, 2); ?>%;background:<?php echo $divStyles[$dk]; ?>" title="Division <?php echo h($dk); ?>: <?php echo (int)$dn; ?>"></span>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="small text-muted mb-2"><i class="bi bi-info-circle me-1"></i>No division has been awarded yet — candidates are still incomplete (INC) or absent (ABS).</div>
  <?php endif; ?>
  <div class="ra-div-legend">
    <?php foreach ($divCounts as $dk => $dn): ?>
      <span><i class="ra-dot" style="background:<?php echo $divStyles[$dk]; ?>"></i>Division <?php echo h($dk); ?>: <b><?php echo (int)$dn; ?></b></span>
    <?php endforeach; ?>
    <span><i class="ra-dot" style="background:#8a99b0"></i>INC: <b><?php echo (int)array_sum(array_column($sAgg, 'INC')); ?></b></span>
    <span><i class="ra-dot" style="background:#c8d1e0"></i>ABS: <b><?php echo (int)array_sum(array_column($sAgg, 'ABS')); ?></b></span>
  </div>
</div>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <h2 class="h6 fw-bold text-dark mb-0"><i class="bi bi-grid me-1"></i>Analysis sections</h2>
  <span class="small text-muted">Chagua sehemu kufungua ukurasa wake</span>
</div>
<div class="ra-grid">
  <?php foreach (ra_sections() as $key => $s): ?>
    <a class="ra-card" href="<?php echo h($s['file'] . '?exam_id=' . (int)$exam_id); ?>">
      <div class="ct"><i class="bi bi-<?php echo h($s['icon']); ?>"></i><span><?php echo h($s['title']); ?></span></div>
      <div class="cd"><?php echo h($s['desc']); ?></div>
      <span class="go">Open <i class="bi bi-arrow-right"></i></span>
    </a>
  <?php endforeach; ?>
</div>

<?php layout_end();
