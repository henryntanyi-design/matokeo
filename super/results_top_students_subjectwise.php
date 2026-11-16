<?php
/** Results Analysis - Overall Ten Students Subjectwise (ukurasa wake). */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/results_analysis.php';
require_district();
@set_time_limit(180);
ob_start();

list($exam_id, $exam, $hasData, $exams) = ra_pick_exam((int)($_GET['exam_id'] ?? 0));
layout_start('Overall Ten Students Subjectwise', 'analysis', '');
ra_styles();
if (!$exam) { ra_no_exam(); layout_end(); exit; }
$d = ra_build($exam_id);
if (($_GET['export'] ?? '') === 'pdf' && $hasData) ra_export_pdf($exam, $d, 'top10-subj');
extract($d);
ra_page_head('Overall Ten Students Subjectwise', 'list-stars', $exam, $exam_id, $hasData, $exams);
ra_nav($exam_id, 'top10-subj');
?>

<div class="card ra-sec" id="top10-subj">
  <div class="ra-sec-h"><span class="t"><i class="bi bi-list-stars"></i>Overall Ten Students Subjectwise</span><span class="ra-pill">top 10 per subject</span></div>
  <?php if (!$subj): ?><div class="ra-empty">No subject scores yet.</div><?php else: ?>
  <?php $subjIds = array_keys($subj); $first = true; ?>
  <div class="ra-tabwrap">
    <div class="ra-tabs">
      <?php foreach ($subjIds as $kid): ?><button type="button" data-target="#ts-<?php echo (int)$kid; ?>" class="<?php echo $first ? 'active' : ''; ?>"><?php echo h($subj[$kid]['code']); ?></button><?php $first = false; endforeach; ?>
    </div>
    <?php $first = true; foreach ($subjIds as $kid): $list = [];
        foreach (array_keys($scoreMap) as $sid) {
            foreach (($scoreMap[$sid] ?? []) as $cidRows) {
                foreach (($cidRows[$kid] ?? []) as $r) if ($r['score'] !== null) $list[] = $r;
            }
        }
        usort($list, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $list = array_slice($list, 0, 10); ?>
      <div class="ra-pane<?php echo $first ? '' : ' d-none'; ?>" id="ts-<?php echo (int)$kid; ?>">
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 ra-table">
          <thead><tr><th class="ps-3">#</th><th>Student</th><th>School</th><th>Class</th><th>Score</th><th class="pe-3">Grade</th></tr></thead>
          <tbody>
          <?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">No scores for this subject.</td></tr><?php endif; ?>
          <?php foreach ($list as $i => $r): ?>
            <tr>
              <td class="ps-3"><span class="ra-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span></td>
              <td class="fw-semibold"><?php echo h($r['student']); ?><div class="ra-muted"><?php echo h($r['cand_no']); ?></div></td>
              <td class="small text-muted"><?php echo h($r['school']); ?></td>
              <td class="small"><?php echo h($r['class_name']); ?></td>
              <td><b><?php echo h(number_format((float)$r['score'], 1)); ?></b></td>
              <td class="pe-3"><span class="badge bg-secondary-subtle text-secondary-emphasis"><?php echo h($r['grade']); ?></span></td>
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
