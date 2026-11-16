<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$subject_id = (int)($_GET['subject_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$subject = $subject_id ? q_one('SELECT * FROM subjects WHERE id = ?', [$subject_id]) : null;
if (!$subject) { flash('danger', 'Somo halijapatikana.'); redirect($class_id ? 'super/class_subjects.php?class_id=' . $class_id : 'super/subjects.php'); }
$rows = subject_school_assignments($subject_id);
$schools = [];
foreach ($rows as $r) $schools[(int)$r['school_id']] = true;
$fmt = function ($v) { $t = $v ? strtotime((string)$v) : false; return $t ? date('d/m/Y', $t) : '—'; };
layout_start('Shule zilizosajili: ' . $subject['name'], 'classes'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="fw-bold mb-1"><i class="bi bi-buildings"></i> Shule zilizosajili somo</h5>
    <div class="text-muted small">Orodha ya shule na walimu waliosajiliwa somo hili, pamoja na kidato, stream na tarehe.</div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($class_id ? 'class_subjects.php?class_id=' . $class_id : 'subjects.php'); ?>">&larr; Rudi</a>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <div class="fw-bold fs-5"><?php echo h($subject['name']); ?></div>
      <div class="text-muted small">Subject code: <b><?php echo h($subject['code']); ?></b>
        <?php if (!empty($subject['abbr'])): ?> &middot; NECTA Abbreviation: <b><?php echo h($subject['abbr']); ?></b><?php endif; ?>
        &middot; Level: <b><?php echo h(subject_level_label($subject['level'])); ?></b>
        <?php if (!empty($subject['is_subsidiary'])): ?><span class="badge bg-warning text-dark">subsidiary</span><?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <span class="badge text-bg-light border px-3 py-2">Shule: <?php echo count($schools); ?></span>
      <span class="badge text-bg-light border px-3 py-2">Walimu: <?php echo count($rows); ?></span>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold">Shule, walimu na madarasa</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th class="ps-3">S/N</th>
        <th>Jina la Shule</th>
        <th>Jina la Mwalimu</th>
        <th>Kidato</th>
        <th>Stream</th>
        <th>Tarehe ya Kusajiliwa</th>
        <th class="text-end pe-3">Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Hakuna shule iliyosajili somo hili bado.</td></tr>
      <?php else: $i = 0; foreach ($rows as $r): $i++;
            $qs = http_build_query(['teacher_id' => (int)$r['teacher_id'], 'subject_id' => $subject_id, 'class_id' => (int)$r['class_id'], 'stream' => (string)$r['stream']]); ?>
        <tr>
          <td class="ps-3 text-muted"><?php echo $i; ?></td>
          <td><div class="fw-semibold"><?php echo h($r['school_name']); ?></div><?php if ($r['centre_no']): ?><div class="small text-muted"><?php echo h($r['centre_no']); ?></div><?php endif; ?></td>
          <td><?php echo h($r['full_name']); ?><?php if (!$r['active']): ?> <span class="badge bg-secondary">inactive</span><?php endif; ?><div class="small text-muted"><?php echo h($r['username']); ?></div></td>
          <td><?php echo h($r['class_name']); ?></td>
          <td><?php echo $r['stream'] !== '' ? h($r['stream']) : '<span class="text-muted">Darasa zima</span>'; ?></td>
          <td><?php echo h($fmt($r['assigned_at'])); ?></td>
          <td class="text-end pe-3">
            <a class="btn btn-outline-primary btn-sm py-0" href="teacher_view.php?<?php echo h($qs); ?>" title="View taarifa za mwalimu">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
