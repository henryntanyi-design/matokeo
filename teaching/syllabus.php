<?php
require_once __DIR__ . '/../lib/teaching.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_staff(); $school_id = active_school_id();
$tid = teaching_active_teacher_id();
$ready = teaching_tables_ready();

$terms = teaching_terms();
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $year = teaching_year((int)($_POST['year_id'] ?? 0));
    $year_id = $year ? (int)$year['id'] : 0;
    $cid = (int)($_POST['class_id'] ?? 0);
    $sid = (int)($_POST['subject_id'] ?? 0);
    $term = teaching_term($_POST['term'] ?? 'I');
    $back = 'teaching/syllabus.php?year_id=' . $year_id . '&class_id=' . $cid . '&subject_id=' . $sid . '&term=' . urlencode($term);

    if (!$ready) {
        flash('danger', 'The syllabus table is not installed. The administrator should import database/migration_teaching.sql.');
    } elseif (!teaching_valid_pair($cid, $sid) || !teaching_can_manage($cid, $sid)) {
        flash('danger', 'You do not have permission, or the class/subject is invalid.');
    } elseif (!$year_id) {
        flash('danger', 'Choose the academic year first.');
    } elseif ($a === 'add') {
        $topic = trim((string)($_POST['topic'] ?? ''));
        if ($topic === '') {
            flash('danger', 'Enter the topic first.');
        } else {
            $week = max(1, min(20, (int)($_POST['week_no'] ?? 1)));
            $periods = max(1, min(12, (int)($_POST['periods'] ?? 1)));
            $sort = (int)q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_topics
                                WHERE school_id = ? AND academic_year_id = ? AND class_id = ? AND subject_id = ? AND term = ? AND week_no = ?',
                               [$school_id, $year_id, $cid, $sid, $term, $week]);
            q('INSERT INTO syllabus_topics (school_id, academic_year_id, class_id, subject_id, term, week_no, periods,
                 topic, subtopic, objectives, activities, materials, assessment, refs, sort_order)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [$school_id, $year_id, $cid, $sid, $term, $week, $periods,
               mb_substr($topic, 0, 200), mb_substr(trim((string)($_POST['subtopic'] ?? '')), 0, 200),
               trim((string)($_POST['objectives'] ?? '')), trim((string)($_POST['activities'] ?? '')),
               trim((string)($_POST['materials'] ?? '')), trim((string)($_POST['assessment'] ?? '')),
               trim((string)($_POST['refs'] ?? '')), $sort]);
            flash('success', 'The topic was added to the syllabus.');
        }
    } elseif ($a === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = q_one('SELECT * FROM syllabus_topics WHERE id = ? AND school_id = ?', [$id, $school_id]);
        if (!$row) flash('danger', 'The topic could not be found.');
        elseif (!teaching_can_manage((int)$row['class_id'], (int)$row['subject_id'])) flash('danger', 'You do not have permission to remove this topic.');
        else { q('DELETE FROM syllabus_topics WHERE id = ? AND school_id = ?', [$id, $school_id]); flash('success', 'The topic was removed.'); }
    }
    redirect($back);
}

$classes = teaching_classes_for($school_id, $tid);
$year = teaching_year((int)($_GET['year_id'] ?? 0));
$year_id = $year ? (int)$year['id'] : 0;
$term = teaching_term($_GET['term'] ?? 'I');

$class_id = (int)($_GET['class_id'] ?? 0);
$cur = null;
foreach ($classes as $c) if ((int)$c['id'] === $class_id) { $cur = $c; break; }
if (!$cur) { $cur = $classes ? $classes[0] : null; $class_id = $cur ? (int)$cur['id'] : 0; }

$subjects = $class_id ? teaching_subjects_for($class_id, $tid) : [];
$subject_id = (int)($_GET['subject_id'] ?? 0);
$sub = null;
foreach ($subjects as $s) if ((int)$s['id'] === $subject_id) { $sub = $s; break; }
if (!$sub) { $sub = $subjects ? $subjects[0] : null; $subject_id = $sub ? (int)$sub['id'] : 0; }

$topics = [];
if ($ready && $class_id && $subject_id) {
    $topics = q_all('SELECT * FROM syllabus_topics
                     WHERE school_id = ? AND academic_year_id = ? AND class_id = ? AND subject_id = ? AND term = ?
                     ORDER BY week_no, sort_order, id', [$school_id, $year_id, $class_id, $subject_id, $term]);
}

$head = ['WEEK', 'PERIODS', 'TOPIC', 'SUBTOPIC', 'OBJECTIVES', 'ACTIVITIES', 'MATERIALS', 'ASSESSMENT', 'REFERENCES'];
$rows = [];
foreach ($topics as $t) $rows[] = [$t['week_no'], $t['periods'], $t['topic'], $t['subtopic'], $t['objectives'],
                                   $t['activities'], $t['materials'], $t['assessment'], $t['refs']];

if (($_GET['action'] ?? '') === 'export') {
    teaching_send_table_xlsx('syllabus_' . $class_id . '_' . $subject_id . '_' . $term . '_' . date('Ymd'),
        'SUBJECT SYLLABUS', teaching_meta_text($cur, $sub, $term, $year),
        $head, $rows, 'Syllabus', [6, 8, 24, 20, 28, 28, 20, 20, 16]);
}

$canManage = $class_id && $subject_id && teaching_can_manage($class_id, $subject_id);
layout_start('Syllabus', 'teach');
teaching_nav('syllabus');
?>
<div class="card shadow-sm mb-3 d-print-none"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small fw-bold">Year</label>
    <select name="year_id" class="form-select form-select-sm"><?php foreach (teaching_years() as $y) echo '<option value="' . (int)$y['id'] . '"' . ($year_id === (int)$y['id'] ? ' selected' : '') . '>' . h($y['name']) . '</option>'; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small fw-bold">Class</label>
    <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($classes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($class_id === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label small fw-bold">Subject</label>
    <select name="subject_id" class="form-select form-select-sm"><?php foreach ($subjects as $s) echo '<option value="' . (int)$s['id'] . '"' . ($subject_id === (int)$s['id'] ? ' selected' : '') . '>' . h($s['name']) . '</option>'; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small fw-bold">Term</label>
    <select name="term" class="form-select form-select-sm"><?php foreach ($terms as $k => $label) echo '<option value="' . h($k) . '"' . ($term === $k ? ' selected' : '') . '>' . h($label) . '</option>'; ?></select></div>
  <div class="col-md-3 d-flex gap-2">
    <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(url('teaching/syllabus.php?action=export&year_id=' . $year_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id . '&term=' . urlencode($term))); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</form>
</div></div>

<?php if (!$ready): ?>
<div class="alert alert-warning"><b>Tables not installed:</b> the administrator should import <code>database/migration_teaching.sql</code> in phpMyAdmin so that the syllabus can be saved.</div>
<?php elseif (!$classes): ?>
<div class="alert alert-info">No class has been assigned to you yet.<?php echo is_admin() ? ' Go to <a href="' . h(url('admin/assignments.php')) . '">Assignments</a> to assign teachers to subjects.' : ' Contact the head of school so that you are assigned subjects.'; ?></div>
<?php elseif (!$sub): ?>
<div class="alert alert-info">This class has no subject assigned to you. Choose another class.</div>
<?php else: ?>

<?php if ($canManage): ?>
<div class="card shadow-sm mb-3 d-print-none"><div class="card-header bg-white fw-bold"><i class="bi bi-plus-circle"></i> Add a topic to the syllabus</div>
<div class="card-body">
<form method="post"><?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="year_id" value="<?php echo $year_id; ?>">
  <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
  <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
  <div class="row g-2 mb-2">
    <div class="col-4 col-md-2"><label class="form-label small fw-bold">Term</label>
      <select name="term" class="form-select form-select-sm"><?php foreach ($terms as $k => $label) echo '<option value="' . h($k) . '"' . ($term === $k ? ' selected' : '') . '>' . h($label) . '</option>'; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small fw-bold">Week</label>
      <input type="number" name="week_no" class="form-control form-control-sm" min="1" max="20" value="1" required></div>
    <div class="col-4 col-md-2"><label class="form-label small fw-bold">Periods</label>
      <input type="number" name="periods" class="form-control form-control-sm" min="1" max="12" value="3" required></div>
    <div class="col-md-6"><label class="form-label small fw-bold">Topic</label>
      <input type="text" name="topic" class="form-control form-control-sm" maxlength="200" required></div>
  </div>
  <div class="mb-2"><label class="form-label small fw-bold">Subtopic</label>
    <input type="text" name="subtopic" class="form-control form-control-sm" maxlength="200"></div>
  <div class="row g-2">
    <div class="col-md-6"><label class="form-label small fw-bold">Objectives</label><textarea name="objectives" class="form-control form-control-sm" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label small fw-bold">Activities</label><textarea name="activities" class="form-control form-control-sm" rows="2"></textarea></div>
    <div class="col-md-4"><label class="form-label small fw-bold">Materials</label><textarea name="materials" class="form-control form-control-sm" rows="2"></textarea></div>
    <div class="col-md-4"><label class="form-label small fw-bold">Assessment</label><textarea name="assessment" class="form-control form-control-sm" rows="2"></textarea></div>
    <div class="col-md-4"><label class="form-label small fw-bold">References</label><textarea name="refs" class="form-control form-control-sm" rows="2"></textarea></div>
  </div>
  <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save"></i> Save topic</button>
</form>
</div></div>
<?php endif; ?>

<?php teaching_doc_header('SUBJECT SYLLABUS', teaching_meta_html($cur, $sub, $term, $year)); ?>

<div class="table-responsive"><table class="table table-bordered table-sm align-middle">
<thead class="table-light"><tr><?php foreach ($head as $hh) echo '<th class="text-center" style="white-space:nowrap">' . h($hh) . '</th>'; ?><th class="d-print-none"></th></tr></thead>
<tbody>
<?php foreach ($topics as $t): ?><tr>
  <td class="text-center fw-bold"><?php echo (int)$t['week_no']; ?></td>
  <td class="text-center"><?php echo (int)$t['periods']; ?></td>
  <td><?php echo nl2br(h((string)$t['topic'])); ?></td>
  <td><?php echo nl2br(h((string)$t['subtopic'])); ?></td>
  <td><?php echo nl2br(h((string)$t['objectives'])); ?></td>
  <td><?php echo nl2br(h((string)$t['activities'])); ?></td>
  <td><?php echo nl2br(h((string)$t['materials'])); ?></td>
  <td><?php echo nl2br(h((string)$t['assessment'])); ?></td>
  <td><?php echo nl2br(h((string)$t['refs'])); ?></td>
  <td class="text-end d-print-none">
    <?php if ($canManage): ?><form method="post" class="m-0" onsubmit="return confirm('Remove this topic?');"><?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
      <input type="hidden" name="year_id" value="<?php echo $year_id; ?>"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
      <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>"><input type="hidden" name="term" value="<?php echo h($term); ?>">
      <button class="btn btn-outline-danger btn-sm py-0">Remove</button></form><?php endif; ?>
  </td>
</tr><?php endforeach; ?>
<?php if (!$topics): ?><tr><td colspan="10" class="text-center text-muted py-4">No topics yet. Use the form above to add topics for <?php echo h($sub['name']); ?> - <?php echo h($cur['name']); ?> (<?php echo h($terms[$term]); ?>).</td></tr><?php endif; ?>
</tbody></table></div>
<p class="small text-muted">Total topics: <b><?php echo count($topics); ?></b>. These topics are used directly in the <a href="<?php echo h(url('teaching/scheme.php?year_id=' . $year_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id . '&term=' . urlencode($term))); ?>">Scheme of Work</a>.</p>
<?php endif; ?>
<?php layout_end(); ?>
