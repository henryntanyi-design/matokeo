<?php
/** Results Analysis - Schools Ranking (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Schools Ranking', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'schools');
extract($d);
ra_page_head('Schools Ranking', 'trophy', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'schools');
?>

<div class="card ra-sec" id="schools">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-trophy"></i>Schools Ranking</span><span class="ra-pill"><i class="bi bi-hash"></i><?php echo count($rankSchools); ?></span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">#</th><th>School</th><th>Candidates</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th><th>GPA</th><th>Avg score</th><th class="pe-3">Pass %</th></tr></thead>
    <tbody>
    <?php if (!$rankSchools): ?><tr><td colspan="11" class="text-center text-muted py-4">No published school results yet.</td></tr><?php endif; ?>
    <?php foreach ($rankSchools as $s): ?>
      <tr>
        <td class="ps-3"><span class="ra-rank<?php echo $s['rank'] && $s['rank'] <= 3 ? ' top' : ''; ?>"><?php echo $s['rank'] ?: '-'; ?></span></td>
        <td><div class="ra-school"><?php echo h($s['name']); ?></div><div class="ra-muted"><?php echo h($s['centre']); ?><?php echo $s['ward'] !== '' ? ' &middot; ' . h($s['ward']) : ''; ?></div></td>
        <td><?php echo (int)$s['cands']; ?></td>
        <td><?php echo (int)$s['I']; ?></td><td><?php echo (int)$s['II']; ?></td><td><?php echo (int)$s['III']; ?></td><td><?php echo (int)$s['IV']; ?></td><td><?php echo (int)$s['0']; ?></td>
        <td><b><?php echo $s['gpa'] !== null ? h(number_format($s['gpa'], 2)) : '-'; ?></b></td>
        <td><?php echo $s['avg'] !== null ? h(number_format($s['avg'], 1)) : '-'; ?></td>
        <td class="pe-3"><div class="d-flex align-items-center gap-2"><span class="small"><?php echo $s['pass_pct'] !== null ? h(number_format($s['pass_pct'], 1)) . '%' : '-'; ?></span><span class="ra-bar"><span style="width:<?php echo (int)round($s['pass_pct'] ?? 0); ?>%"></span></span></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
