<?php
/** Results Analysis - Overall Subjects Analysis (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Overall Subjects Analysis', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'subj-analysis');
extract($d);
ra_page_head('Overall Subjects Analysis', 'clipboard-data', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'subj-analysis');
?>

<div class="card ra-sec" id="subj-analysis">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-clipboard-data"></i>Overall Subjects Analysis</span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">Subject</th><th>Sat</th><th>Absent</th><th>Pass %</th><th>Avg score</th><th>GPA</th><th class="pe-3" style="min-width:170px">Performance</th></tr></thead>
    <tbody>
    <?php if (!$subj): ?><tr><td colspan="7" class="text-center text-muted py-4">No subject scores yet.</td></tr><?php endif; ?>
    <?php foreach ($subj as $s): ?>
      <tr>
        <td class="ps-3"><div class="fw-semibold"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['code']); ?></div></td>
        <td><?php echo (int)$s['sat']; ?></td><td><?php echo (int)$s['absent']; ?></td>
        <td><?php echo $s['pass_pct'] !== null ? h(number_format($s['pass_pct'], 1)) . '%' : '-'; ?></td>
        <td><?php echo $s['avg'] !== null ? h(number_format($s['avg'], 1)) : '-'; ?></td>
        <td><?php echo $s['gpa'] !== null ? h(number_format($s['gpa'], 2)) : '-'; ?></td>
        <td class="pe-3"><div class="d-flex align-items-center gap-2"><span class="ra-bar flex-grow-1"><span style="width:<?php echo (int)round($s['avg'] ?? 0); ?>%"></span></span><span class="small text-muted"><?php echo $s['avg'] !== null ? h(number_format($s['avg'], 1)) : '-'; ?></span></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
