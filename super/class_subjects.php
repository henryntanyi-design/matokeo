<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$class = q_one('SELECT * FROM classes WHERE id = ?', [$class_id]);
if (!$class) redirect('super/classes.php');
if (is_post()) {
    csrf_check();
    $sel = array_map('intval', $_POST['sub'] ?? []); $comp = $_POST['comp'] ?? [];
    try {
        db()->beginTransaction();
        q('DELETE FROM class_subjects WHERE class_id = ?', [$class_id]);
        foreach ($sel as $sid) q('INSERT INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, ?)', [$class_id, $sid, isset($comp[$sid]) ? 1 : 0]);
        db()->commit();
        flash('success', 'Masomo ya darasa yamehifadhiwa (' . count($sel) . ').');
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    redirect('super/class_subjects.php?class_id=' . $class_id);
}
$cur = [];
foreach (q_all('SELECT subject_id, is_compulsory FROM class_subjects WHERE class_id = ?', [$class_id]) as $r) $cur[(int)$r['subject_id']] = (int)$r['is_compulsory'];
$subs = q_all('SELECT s.*, (SELECT COUNT(DISTINCT u.school_id) FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                      WHERE ta.subject_id = s.id AND u.school_id IS NOT NULL) AS n_schools
               FROM subjects s ORDER BY s.is_subsidiary, s.name');
layout_start('Masomo ya ' . $class['name'], 'classes'); ?>
<p class="text-muted small">Chagua masomo yanayosomwa na <b><?php echo h($class['name']); ?></b>. Masomo yote yaliyochaguliwa hufanywa na wanafunzi wote wa darasa; tiki ya <b>Lazima</b> ni kategoria ya taarifa pekee. Bonyeza <i class="bi bi-eye"></i> kuona shule zilizosajili somo.</p>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr>
  <th class="ps-3">S/N</th>
  <th>Name</th>
  <th>Subject code</th>
  <th>NECTA Abbreviation</th>
  <th>Level</th>
  <th class="text-center">Linasomwa</th>
  <th class="text-center">Lazima</th>
  <th class="text-end pe-3">Action</th>
</tr></thead><tbody>
<?php $n = 0; foreach ($subs as $s): $id = (int)$s['id']; $n++; ?>
<tr>
  <td class="ps-3 text-muted"><?php echo $n; ?></td>
  <td class="fw-semibold"><?php echo h($s['name']); ?><?php echo $s['is_subsidiary'] ? ' <span class="badge bg-warning text-dark">subsidiary</span>' : ''; ?></td>
  <td><?php echo h($s['code']); ?></td>
  <td><?php echo h($s['abbr'] !== '' ? $s['abbr'] : '—'); ?></td>
  <td><?php echo h(subject_level_label($s['level'])); ?></td>
  <td class="text-center"><input type="checkbox" class="form-check-input" name="sub[]" value="<?php echo $id; ?>" <?php echo isset($cur[$id]) ? 'checked' : ''; ?>></td>
  <td class="text-center"><input type="checkbox" class="form-check-input" name="comp[<?php echo $id; ?>]" value="1" <?php echo !empty($cur[$id]) ? 'checked' : ''; ?>></td>
  <td class="text-end pe-3">
    <a class="btn btn-outline-primary btn-sm py-0" href="subject_schools.php?subject_id=<?php echo $id; ?>&class_id=<?php echo $class_id; ?>" title="Shule zilizosajili somo hili">
      <i class="bi bi-eye"></i> <span class="badge text-bg-light border"><?php echo (int)$s['n_schools']; ?></span>
    </a>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card-footer bg-white"><button class="btn btn-primary">Hifadhi</button> <a class="btn btn-link" href="classes.php">Rudi</a></div></div></form>
<?php layout_end(); ?>
