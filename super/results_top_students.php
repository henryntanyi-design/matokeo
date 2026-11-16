<?php
/** Results Analysis - Overall Ten Students (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Overall Ten Students', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'top10');
extract($d);
ra_page_head('Overall Ten Students', 'star', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'top10');
?>

<div class="card ra-sec" id="top10">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-star"></i>Overall Ten Students</span><span class="ra-pill">best 10 of <?php echo count($topStudents); ?></span></div>
  <?php if (!$top10): ?><div class="ra-empty">No ranked candidates yet.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">#</th><th>Student</th><th>School</th><th>Class</th><th>Division</th><th>Points</th><th class="pe-3">Average</th></tr></thead>
    <tbody>
    <?php foreach ($top10 as $i => $s): ?>
      <tr>
        <td class="ps-3"><span class="ra-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span></td>
        <td><div class="fw-semibold"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['cand_no']); ?></div></td>
        <td class="small text-muted"><?php echo h($s['school']); ?></td>
        <td class="small"><?php echo h($s['class_name']); ?></td>
        <td><span class="badge bg-primary-subtle text-primary-emphasis"><?php echo h($s['division']); ?></span></td>
        <td><?php echo (int)$s['points']; ?></td>
        <td class="pe-3"><b><?php echo h(number_format((float)$s['average'], 2)); ?></b></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<?php layout_end();
