<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$teacher_id = (int)($_GET['teacher_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
$class_id   = (int)($_GET['class_id'] ?? 0);
$stream     = (string)($_GET['stream'] ?? '');

$t = $teacher_id ? q_one('SELECT u.*, sc.name AS school_name, sc.centre_no FROM users u LEFT JOIN schools sc ON sc.id = u.school_id WHERE u.id = ?', [$teacher_id]) : null;
if (!$t) { flash('danger', 'Mwalimu hajapatikana.'); redirect('super/schools.php'); }

$assignments = teacher_assignment_list($teacher_id);
$focus = null;
foreach ($assignments as $a) {
    if ($subject_id && (int)$a['subject_id'] !== $subject_id) continue;
    if ($class_id && (int)$a['class_id'] !== $class_id) continue;
    if ($stream !== '' && (string)$a['stream'] !== $stream) continue;
    $focus = $a; break;
}
$fmt = function ($v) { $t2 = $v ? strtotime((string)$v) : false; return $t2 ? date('d/m/Y', $t2) : '—'; };
$back = $subject_id ? 'subject_schools.php?subject_id=' . $subject_id . ($class_id ? '&class_id=' . $class_id : '') : 'schools.php';
layout_start('Mwalimu: ' . $t['full_name'], 'schools'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="fw-bold mb-1"><i class="bi bi-person-badge"></i> Taarifa za Mwalimu</h5>
    <div class="text-muted small"><?php echo h($t['full_name']); ?><?php echo $t['school_name'] ? ' &middot; ' . h($t['school_name']) : ''; ?></div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($back); ?>">&larr; Rudi</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold">Taarifa binafsi</div>
      <div class="card-body">
        <div class="row g-2 small">
          <div class="col-5 text-muted">Jina kamili</div><div class="col-7 fw-semibold"><?php echo h($t['full_name']); ?></div>
          <div class="col-5 text-muted">Cheque Number</div><div class="col-7"><?php echo h($t['username']); ?></div>
          <div class="col-5 text-muted">Shule</div><div class="col-7"><?php echo $t['school_name'] ? h($t['school_name']) . ($t['centre_no'] ? ' <span class="text-muted small">' . h($t['centre_no']) . '</span>' : '') : '—'; ?></div>
          <div class="col-5 text-muted">Simu</div><div class="col-7"><?php echo h($t['phone'] ?: '—'); ?></div>
          <div class="col-5 text-muted">Barua pepe</div><div class="col-7"><?php echo h($t['email'] ?: '—'); ?></div>
          <div class="col-5 text-muted">Wadhifa</div><div class="col-7"><?php echo h(user_title($t)); ?></div>
          <div class="col-5 text-muted">Hali</div><div class="col-7"><?php echo (int)$t['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></div>
          <div class="col-5 text-muted">Alisajiliwa</div><div class="col-7"><?php echo h($fmt($t['created_at'])); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm h-100<?php echo $focus ? ' border-primary' : ''; ?>">
      <div class="card-header bg-white fw-semibold">Somo alilopangiwa</div>
      <div class="card-body">
        <?php if ($focus): ?>
          <div class="fw-bold fs-5 mb-1"><?php echo h($focus['subject_name']); ?></div>
          <div class="text-muted small mb-3">Subject code: <b><?php echo h($focus['subject_code']); ?></b><?php if (!empty($focus['subject_abbr'])): ?> &middot; NECTA Abbreviation: <b><?php echo h($focus['subject_abbr']); ?></b><?php endif; ?></div>
          <div class="row g-2 small">
            <div class="col-5 text-muted">Kidato</div><div class="col-7 fw-semibold"><?php echo h($focus['class_name']); ?></div>
            <div class="col-5 text-muted">Stream</div><div class="col-7"><?php echo $focus['stream'] !== '' ? h($focus['stream']) : '<span class="text-muted">Darasa zima</span>'; ?></div>
            <div class="col-5 text-muted">Tarehe ya kusajiliwa</div><div class="col-7"><?php echo h($fmt($focus['assigned_at'])); ?></div>
          </div>
        <?php else: ?>
          <div class="text-muted small">Hakuna somo maalum lililochaguliwa. Orodha ya masomo yote ya mwalimu iko chini.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold">Masomo yaliyosajiliwa kwa mwalimu (<?php echo count($assignments); ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th class="ps-3">S/N</th>
        <th>Kidato</th>
        <th>Somo</th>
        <th>Stream</th>
        <th>Tarehe ya Kusajiliwa</th>
        <th class="text-end pe-3">Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$assignments): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Mwalimu huyu hajasajiliwa somo lolote.</td></tr>
      <?php else: $i = 0; foreach ($assignments as $a): $i++;
            $isFocus = $focus && (int)$a['id'] === (int)$focus['id'];
            $qs = http_build_query(['subject_id' => (int)$a['subject_id'], 'class_id' => (int)$a['class_id']]); ?>
        <tr<?php echo $isFocus ? ' class="table-primary"' : ''; ?>>
          <td class="ps-3 text-muted"><?php echo $i; ?></td>
          <td><?php echo h($a['class_name']); ?></td>
          <td><span class="fw-semibold"><?php echo h($a['subject_name']); ?></span> <span class="text-muted small"><?php echo h($a['subject_code']); ?></span></td>
          <td><?php echo $a['stream'] !== '' ? h($a['stream']) : '<span class="text-muted">Darasa zima</span>'; ?></td>
          <td><?php echo h($fmt($a['assigned_at'])); ?></td>
          <td class="text-end pe-3">
            <a class="btn btn-outline-primary btn-sm py-0" href="subject_schools.php?<?php echo h($qs); ?>" title="Shule zilizosajili somo hili">
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
