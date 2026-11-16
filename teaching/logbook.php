<?php
require_once __DIR__ . '/../lib/teaching.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_staff(); $school_id = active_school_id();
$tid = teaching_active_teacher_id();
$ready = teaching_tables_ready();

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $year = teaching_year((int)($_POST['year_id'] ?? 0));
    $year_id = $year ? (int)$year['id'] : 0;
    $cid = (int)($_POST['class_id'] ?? 0);
    $sid = (int)($_POST['subject_id'] ?? 0);
    $back = 'teaching/logbook.php?year_id=' . $year_id . '&class_id=' . $cid . '&subject_id=' . $sid;

    if (!$ready) {
        flash('danger', 'The logbook table is not installed. The administrator should import database/migration_teaching.sql.');
    } elseif (!teaching_valid_pair($cid, $sid) || !teaching_can_manage($cid, $sid)) {
        flash('danger', 'You do not have permission, or the class/subject is invalid.');
    } elseif (!$year_id) {
        flash('danger', 'Choose the academic year first.');
    } elseif ($a === 'add') {
        $topic = trim((string)($_POST['topic'] ?? ''));
        $dateRaw = trim((string)($_POST['log_date'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $dateRaw);
        $dateOk = $dt && $dt->format('Y-m-d') === $dateRaw;
        if ($topic === '' || !$dateOk) {
            flash('danger', 'Enter a valid date and the lesson topic.');
        } else {
            $teacher = $tid;
            if (!$teacher) {
                $teacher = (int)($_POST['teacher_id'] ?? 0);
                if ($teacher && !q_val('SELECT 1 FROM users WHERE id = ? AND school_id = ? AND role = ? AND active = 1', [$teacher, $school_id, 'teacher'])) $teacher = 0;
            }
            q('INSERT INTO subject_logs (school_id, academic_year_id, class_id, subject_id, teacher_id, log_date, period, topic, activities, remarks)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [$school_id, $year_id, $cid, $sid, $teacher ?: null, $dateRaw,
               mb_substr(trim((string)($_POST['period'] ?? '')), 0, 20), mb_substr($topic, 0, 200),
               trim((string)($_POST['activities'] ?? '')), trim((string)($_POST['remarks'] ?? ''))]);
            flash('success', 'The lesson was added to the logbook.');
        }
    } elseif ($a === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = q_one('SELECT * FROM subject_logs WHERE id = ? AND school_id = ?', [$id, $school_id]);
        if (!$row) flash('danger', 'The entry could not be found.');
        elseif (!teaching_can_manage((int)$row['class_id'], (int)$row['subject_id'])) flash('danger', 'You do not have permission to remove this entry.');
        else { q('DELETE FROM subject_logs WHERE id = ? AND school_id = ?', [$id, $school_id]); flash('success', 'The entry was removed.'); }
    }
    redirect($back);
}

$classes = teaching_classes_for($school_id, $tid);
$year = teaching_year((int)($_GET['year_id'] ?? 0));
$year_id = $year ? (int)$year['id'] : 0;

$class_id = (int)($_GET['class_id'] ?? 0);
$cur = null;
foreach ($classes as $c) if ((int)$c['id'] === $class_id) { $cur = $c; break; }
if (!$cur) { $cur = $classes ? $classes[0] : null; $class_id = $cur ? (int)$cur['id'] : 0; }

$subjects = $class_id ? teaching_subjects_for($class_id, $tid) : [];
$subject_id = (int)($_GET['subject_id'] ?? 0);
$sub = null;
foreach ($subjects as $s) if ((int)$s['id'] === $subject_id) { $sub = $s; break; }
if (!$sub) { $sub = $subjects ? $subjects[0] : null; $subject_id = $sub ? (int)$sub['id'] : 0; }

$logs = [];
if ($ready && $class_id && $subject_id) {
    $logs = q_all('SELECT l.*, u.full_name AS teacher_name FROM subject_logs l LEFT JOIN users u ON u.id = l.teacher_id
                   WHERE l.school_id = ? AND l.academic_year_id = ? AND l.class_id = ? AND l.subject_id = ?
                   ORDER BY l.log_date DESC, l.id DESC', [$school_id, $year_id, $class_id, $subject_id]);
}

$head = ['DATE', 'PERIOD', 'TOPIC', 'ACTIVITIES', 'REMARKS', 'TEACHER'];
$rows = [];
foreach ($logs as $l) $rows[] = [$l['log_date'], $l['period'], $l['topic'], $l['activities'], $l['remarks'], $l['teacher_name']];

if (($_GET['action'] ?? '') === 'export') {
    teaching_send_table_xlsx('logbook_' . $class_id . '_' . $subject_id . '_' . date('Ymd'),
        'SUBJECT LOGBOOK', teaching_meta_text($cur, $sub, '', $year),
        $head, $rows, 'Logbook', [12, 10, 26, 32, 26, 22]);
}

$canManage = $class_id && $subject_id && teaching_can_manage($class_id, $subject_id);
$teachers = (!$tid && is_admin()) ? q_all("SELECT id, full_name FROM users WHERE school_id = ? AND role = 'teacher' AND active = 1 ORDER BY full_name", [$school_id]) : [];
layout_start('Subject Logbook', 'teach');
teaching_nav('logbook');
?>
<div class="card shadow-sm mb-3 d-print-none"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small fw-bold">Year</label>
    <select name="year_id" class="form-select form-select-sm"><?php foreach (teaching_years() as $y) echo '<option value="' . (int)$y['id'] . '"' . ($year_id === (int)$y['id'] ? ' selected' : '') . '>' . h($y['name']) . '</option>'; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small fw-bold">Class</label>
    <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($classes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($class_id === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label small fw-bold">Subject</label>
    <select name="subject_id" class="form-select form-select-sm"><?php foreach ($subjects as $s) echo '<option value="' . (int)$s['id'] . '"' . ($subject_id === (int)$s['id'] ? ' selected' : '') . '>' . h($s['name']) . '</option>'; ?></select></div>
  <div class="col-md-5 d-flex gap-2 align-self-end">
    <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(url('teaching/logbook.php?action=export&year_id=' . $year_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id)); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</form>
</div></div>

<?php if (!$ready): ?>
<div class="alert alert-warning"><b>Tables not installed:</b> the administrator should import <code>database/migration_teaching.sql</code> in phpMyAdmin so that the logbook can be saved.</div>
<?php elseif (!$classes): ?>
<div class="alert alert-info">No class has been assigned to you yet.<?php echo is_admin() ? ' Go to <a href="' . h(url('admin/assignments.php')) . '">Assignments</a> to assign teachers to subjects.' : ' Contact the head of school so that you are assigned subjects.'; ?></div>
<?php elseif (!$sub): ?>
<div class="alert alert-info">This class has no subject assigned to you. Choose another class.</div>
<?php else: ?>

<?php if ($canManage): ?>
<div class="card shadow-sm mb-3 d-print-none"><div class="card-header bg-white fw-bold"><i class="bi bi-plus-circle"></i> Add a lesson that was taught</div>
<div class="card-body">
<form method="post"><?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="year_id" value="<?php echo $year_id; ?>">
  <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
  <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
  <div class="row g-2 mb-2">
    <div class="col-6 col-md-2"><label class="form-label small fw-bold">Date</label>
      <input type="date" name="log_date" class="form-control form-control-sm" value="<?php echo h(date('Y-m-d')); ?>" required></div>
    <div class="col-6 col-md-2"><label class="form-label small fw-bold">Period</label>
      <input type="text" name="period" class="form-control form-control-sm" maxlength="20" placeholder="e.g. P3"></div>
    <?php if ($teachers): ?><div class="col-md-3"><label class="form-label small fw-bold">Teacher</label>
      <select name="teacher_id" class="form-select form-select-sm"><option value="">-- optional --</option><?php $def = teaching_teacher_for($class_id, $subject_id); foreach ($teachers as $t) echo '<option value="' . (int)$t['id'] . '"' . ($def && (int)$def['id'] === (int)$t['id'] ? ' selected' : '') . '>' . h($t['full_name']) . '</option>'; ?></select></div><?php endif; ?>
    <div class="col-md-<?php echo $teachers ? '5' : '8'; ?>"><label class="form-label small fw-bold">Lesson topic</label>
      <input type="text" name="topic" class="form-control form-control-sm" maxlength="200" required></div>
  </div>
  <div class="row g-2">
    <div class="col-md-6"><label class="form-label small fw-bold">Activities carried out</label><textarea name="activities" class="form-control form-control-sm" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label small fw-bold">Remarks / challenges</label><textarea name="remarks" class="form-control form-control-sm" rows="2"></textarea></div>
  </div>
  <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save"></i> Save entry</button>
</form>
</div></div>
<?php endif; ?>

<?php teaching_doc_header('SUBJECT LOGBOOK', teaching_meta_html($cur, $sub, '', $year)); ?>

<div class="table-responsive"><table class="table table-bordered table-sm align-middle">
<thead class="table-light"><tr><?php foreach ($head as $hh) echo '<th style="white-space:nowrap">' . h($hh) . '</th>'; ?><th class="d-print-none"></th></tr></thead>
<tbody>
<?php foreach ($logs as $l): ?><tr>
  <td class="text-nowrap"><?php echo h($l['log_date']); ?></td>
  <td class="text-center"><?php echo h($l['period']); ?></td>
  <td><?php echo nl2br(h((string)$l['topic'])); ?></td>
  <td><?php echo nl2br(h((string)$l['activities'])); ?></td>
  <td><?php echo nl2br(h((string)$l['remarks'])); ?></td>
  <td><?php echo h($l['teacher_name']); ?></td>
  <td class="text-end d-print-none">
    <?php if ($canManage): ?><form method="post" class="m-0" onsubmit="return confirm('Remove this entry?');"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
      <input type="hidden" name="year_id" value="<?php echo $year_id; ?>"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
      <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
      <button class="btn btn-outline-danger btn-sm py-0">Remove</button></form><?php endif; ?>
  </td>
</tr><?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="7" class="text-center text-muted py-4">No entries yet for <?php echo h($sub['name']); ?> - <?php echo h($cur['name']); ?>.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="small text-muted">Total lessons recorded: <b><?php echo count($logs); ?></b>.</p>
<?php endif; ?>
<?php layout_end(); ?>
