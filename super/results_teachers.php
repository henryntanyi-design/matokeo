<?php
/** Results Analysis - Overall Teachers Ranking (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Overall Teachers Ranking', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'teachers');
extract($d);
ra_page_head('Overall Teachers Ranking', 'person-badge', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'teachers');
?>

<div class="card ra-sec" id="teachers">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-person-badge"></i>Overall Teachers Ranking</span><span class="ra-pill"><i class="bi bi-hash"></i><?php echo count($teacherRows); ?></span></div>
  <?php if (!$teacherRows): ?><div class="ra-empty">No teacher assignments with scores yet.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
    <thead><tr><th class="ps-3">#</th><th>Teacher</th><th>School</th><th>Subjects</th><th>Classes</th><th>Entries</th><th>Avg score</th><th class="pe-3">GPA</th></tr></thead>
    <tbody>
    <?php foreach ($teacherRows as $t): ?>
      <tr>
        <td class="ps-3"><span class="ra-rank<?php echo $t['rank'] <= 3 ? ' top' : ''; ?>"><?php echo (int)$t['rank']; ?></span></td>
        <td class="fw-semibold"><?php echo h($t['name']); ?></td>
        <td class="small text-muted"><?php echo h($t['school']); ?></td>
        <td class="small"><?php echo h(implode(', ', $t['subjects'])); ?></td>
        <td><?php echo (int)$t['n_classes']; ?></td>
        <td><?php echo (int)$t['entries']; ?></td>
        <td><b><?php echo $t['avg'] !== null ? h(number_format($t['avg'], 1)) : '-'; ?></b></td>
        <td class="pe-3"><?php echo $t['gpa'] !== null ? h(number_format($t['gpa'], 2)) : '-'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<?php layout_end();
