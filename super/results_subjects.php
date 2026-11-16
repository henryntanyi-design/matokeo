<?php
/** Results Analysis - Subjects Ranking (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Subjects Ranking', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'subjects');
extract($d);
ra_page_head('Subjects Ranking', 'bar-chart', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'subjects');
?>

<div class="card ra-sec" id="subjects">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-bar-chart"></i>Subjects Ranking</span><span class="ra-pill"><i class="bi bi-hash"></i><?php echo count($rankSubjects); ?></span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">#</th><th>Subject</th><th>Sat</th><th>Absent</th><th>Pass</th><th>Avg score</th><th>GPA</th><th class="pe-3">Pass %</th></tr></thead>
    <tbody>
    <?php if (!$rankSubjects): ?><tr><td colspan="8" class="text-center text-muted py-4">No subject scores yet.</td></tr><?php endif; ?>
    <?php foreach ($rankSubjects as $s): ?>
      <tr>
        <td class="ps-3"><span class="ra-rank<?php echo $s['rank'] <= 3 ? ' top' : ''; ?>"><?php echo (int)$s['rank']; ?></span></td>
        <td><div class="fw-semibold"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['code']); ?></div></td>
        <td><?php echo (int)$s['sat']; ?></td><td><?php echo (int)$s['absent']; ?></td><td><?php echo (int)$s['pass']; ?></td>
        <td><b><?php echo $s['avg'] !== null ? h(number_format($s['avg'], 1)) : '-'; ?></b></td>
        <td><?php echo $s['gpa'] !== null ? h(number_format($s['gpa'], 2)) : '-'; ?></td>
        <td class="pe-3"><?php echo $s['pass_pct'] !== null ? h(number_format($s['pass_pct'], 1)) . '%' : '-'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
