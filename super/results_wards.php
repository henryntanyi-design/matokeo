<?php
/** Results Analysis - Wards Ranking (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Wards Ranking', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'wards');
extract($d);
ra_page_head('Wards Ranking', 'geo-fill', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'wards');
?>

<div class="card ra-sec" id="wards">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-geo-fill"></i>Wards Ranking</span><span class="ra-pill"><i class="bi bi-hash"></i><?php echo count($wardRows); ?></span></div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">#</th><th>Ward</th><th>Schools</th><th>Candidates</th><th>GPA</th><th>Avg score</th><th class="pe-3">Pass %</th></tr></thead>
    <tbody>
    <?php if (!$wardRows): ?><tr><td colspan="7" class="text-center text-muted py-4">No ward data yet.</td></tr><?php endif; ?>
    <?php foreach ($wardRows as $r): ?>
      <tr>
        <td class="ps-3"><span class="ra-rank<?php echo $r['rank'] && $r['rank'] <= 3 ? ' top' : ''; ?>"><?php echo $r['rank'] ?: '-'; ?></span></td>
        <td class="fw-semibold"><?php echo h($r['name']); ?></td>
        <td><?php echo (int)$r['schools']; ?></td>
        <td><?php echo (int)$r['cands']; ?></td>
        <td><b><?php echo $r['gpa'] !== null ? h(number_format($r['gpa'], 2)) : '-'; ?></b></td>
        <td><?php echo $r['avg'] !== null ? h(number_format($r['avg'], 1)) : '-'; ?></td>
        <td class="pe-3"><?php echo $r['pass_pct'] !== null ? h(number_format($r['pass_pct'], 1)) . '%' : '-'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php layout_end();
