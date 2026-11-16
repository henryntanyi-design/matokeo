<?php
/** Results Analysis - General schools' Results (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start("General schools' Results", 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'general');
extract($d);
ra_page_head("General schools' Results", 'buildings', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'general');
?>

<div class="card ra-sec" id="general">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-buildings"></i>General schools' Results</span><span class="ra-pill"><i class="bi bi-hash"></i><?php echo count($sAgg); ?></span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">School</th><th>Classes</th><th>Candidates</th><th>Sat / ranked</th><th>INC</th><th>ABS</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th><th>GPA</th><th class="pe-3">Pass %</th></tr></thead>
    <tbody>
    <?php if (!$sAgg): ?><tr><td colspan="13" class="text-center text-muted py-4">No published results yet.</td></tr><?php endif; ?>
    <?php foreach ($sAgg as $s): ?>
      <tr>
        <td class="ps-3"><div class="ra-school"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['centre']); ?><?php echo $s['district'] !== '' ? ' &middot; ' . h($s['district']) : ''; ?><?php echo $s['region'] !== '' ? ', ' . h($s['region']) : ''; ?></div></td>
        <td><?php echo (int)$s['classes']; ?></td>
        <td><?php echo (int)$s['cands']; ?></td>
        <td><?php echo (int)$s['ok']; ?></td>
        <td><?php echo (int)$s['INC']; ?></td>
        <td><?php echo (int)$s['ABS']; ?></td>
        <td><?php echo (int)$s['I']; ?></td><td><?php echo (int)$s['II']; ?></td><td><?php echo (int)$s['III']; ?></td><td><?php echo (int)$s['IV']; ?></td><td><?php echo (int)$s['0']; ?></td>
        <td><b><?php echo $s['gpa'] !== null ? h(number_format($s['gpa'], 2)) : '-'; ?></b></td>
        <td class="pe-3"><?php echo $s['pass_pct'] !== null ? h(number_format($s['pass_pct'], 1)) . '%' : '-'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
