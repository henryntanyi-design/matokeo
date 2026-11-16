<?php
/** Results Analysis - Grades Analysis Subjectwise (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Grades Analysis Subjectwise', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'grades');
extract($d);
ra_page_head('Grades Analysis Subjectwise', 'grid-3x3-gap', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'grades');
?>

<div class="card ra-sec" id="grades">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-grid-3x3-gap"></i>Grades Analysis Subjectwise</span><span class="ra-pill">counts</span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">Subject</th><?php foreach ($gradeCols as $g): ?><th class="text-center"><?php echo h($g); ?></th><?php endforeach; ?><th class="text-center pe-3">Total</th></tr></thead>
    <tbody>
    <?php if (!$subj): ?><tr><td colspan="<?php echo count($gradeCols) + 2; ?>" class="text-center text-muted py-4">No grade counts yet.</td></tr><?php endif; ?>
    <?php foreach ($subj as $s): $tot = 0; foreach ($gradeCols as $g) $tot += (int)($s['grades'][$g] ?? 0); ?>
      <tr>
        <td class="ps-3"><div class="fw-semibold"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['code']); ?></div></td>
        <?php foreach ($gradeCols as $g): $n = (int)($s['grades'][$g] ?? 0); ?>
          <td class="text-center<?php echo $n ? '' : ' text-muted'; ?>"><?php echo $n; ?></td>
        <?php endforeach; ?>
        <td class="text-center pe-3 fw-semibold"><?php echo $tot; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
