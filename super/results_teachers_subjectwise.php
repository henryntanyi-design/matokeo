<?php
/** Results Analysis - Teachers' Ranking Subjectwise (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start("Teachers' Ranking Subjectwise", 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'teachers-subj');
extract($d);
ra_page_head("Teachers' Ranking Subjectwise", 'award', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'teachers-subj');
?>

<div class="card ra-sec" id="teachers-subj">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-award"></i>Teachers' Ranking Subjectwise</span><span class="ra-pill">by average score</span></div>
  <?php if (!$teacherSubj): ?><div class="ra-empty">No teacher assignments with scores yet.</div><?php else: ?>
  <?php $tIds = array_keys($teacherSubj); $first = true; ?>
  <div class="ra-tabwrap">
    <div class="ra-tabs">
      <?php foreach ($tIds as $kid): ?><button type="button" data-target="#tt-<?php echo (int)$kid; ?>" class="<?php echo $first ? 'active' : ''; ?>"><?php echo h($subj[$kid]['code'] ?? ''); ?></button><?php $first = false; endforeach; ?>
    </div>
    <?php $first = true; foreach ($tIds as $kid): ?>
      <div class="ra-pane<?php echo $first ? '' : ' d-none'; ?>" id="tt-<?php echo (int)$kid; ?>">
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
          <thead><tr><th class="ps-3">#</th><th>Teacher</th><th>School</th><th>Entries</th><th>Avg score</th><th class="pe-3">GPA</th></tr></thead>
          <tbody>
          <?php $i = 0; foreach ($teacherSubj[$kid] as $t): $i++; ?>
            <tr>
              <td class="ps-3"><span class="ra-rank<?php echo $i <= 3 ? ' top' : ''; ?>"><?php echo $i; ?></span></td>
              <td class="fw-semibold"><?php echo h($t['name']); ?></td>
              <td class="small text-muted"><?php echo h($t['school']); ?></td>
              <td><?php echo (int)$t['entries']; ?></td>
              <td><b><?php echo $t['avg'] !== null ? h(number_format($t['avg'], 1)) : '-'; ?></b></td>
              <td class="pe-3"><?php echo $t['gpa'] !== null ? h(number_format($t['gpa'], 2)) : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      </div>
    <?php $first = false; endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php ra_scripts(); ?>
<?php layout_end();
