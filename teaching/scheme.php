<?php
require_once __DIR__ . '/../lib/teaching.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_staff(); $school_id = active_school_id();
$tid = teaching_active_teacher_id();
$classes = teaching_classes_for($school_id, $tid);
$year = teaching_year((int)($_GET['year_id'] ?? 0));
$year_id = $year ? (int)$year['id'] : 0;
$terms = teaching_terms();
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
if ($class_id && $subject_id && teaching_tables_ready()) {
    $topics = q_all('SELECT * FROM syllabus_topics
                     WHERE school_id = ? AND academic_year_id = ? AND class_id = ? AND subject_id = ? AND term = ?
                     ORDER BY week_no, sort_order, id', [$school_id, $year_id, $class_id, $subject_id, $term]);
}
$blank = !$topics && $class_id && $subject_id;
if ($blank) {
    for ($w = 1; $w <= 13; $w++) {
        $topics[] = ['week_no' => $w, 'periods' => 3, 'topic' => '', 'subtopic' => '', 'objectives' => '',
                     'activities' => '', 'materials' => '', 'assessment' => '', 'refs' => ''];
    }
}

$head = ['WEEK', 'PERIODS', 'TOPIC', 'SUBTOPIC', 'OBJECTIVES', 'ACTIVITIES', 'MATERIALS', 'ASSESSMENT', 'REFERENCES'];
$rows = [];
foreach ($topics as $t) {
    $rows[] = [$t['week_no'], $t['periods'], $t['topic'], $t['subtopic'], $t['objectives'],
               $t['activities'], $t['materials'], $t['assessment'], $t['refs']];
}

if (($_GET['action'] ?? '') === 'export') {
    teaching_send_table_xlsx('scheme_' . $class_id . '_' . $subject_id . '_' . $term . '_' . date('Ymd'),
        'SCHEME OF WORK', teaching_meta_text($cur, $sub, $term, $year),
        $head, $rows, 'Scheme', [6, 8, 24, 20, 28, 28, 20, 20, 16]);
}

layout_start('Scheme of Work', 'teach');
teaching_nav('scheme');
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
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(url('teaching/scheme.php?action=export&year_id=' . $year_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id . '&term=' . urlencode($term))); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</form>
</div></div>

<?php if (!$classes): ?>
<div class="alert alert-info">No class has been assigned to you yet.<?php echo is_admin() ? ' Go to <a href="' . h(url('admin/assignments.php')) . '">Assignments</a> to assign teachers to subjects.' : ' Contact the head of school so that you are assigned subjects.'; ?></div>
<?php elseif (!$sub): ?>
<div class="alert alert-info">This class has no subject assigned to you. Choose another class.</div>
<?php else: ?>

<?php if ($blank): ?>
<div class="alert alert-warning d-print-none"><?php if (!teaching_tables_ready()): ?><b>Tables not installed:</b> the administrator should import <code>database/migration_teaching.sql</code>. <?php endif; ?><b>No topics in the syllabus:</b> this is an empty 13-week template. Add topics in the <a href="<?php echo h(url('teaching/syllabus.php?year_id=' . $year_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id . '&term=' . urlencode($term))); ?>">Syllabus</a> so that the scheme fills in automatically.</div>
<?php endif; ?>

<?php teaching_doc_header('SCHEME OF WORK', teaching_meta_html($cur, $sub, $term, $year)); ?>

<div class="table-responsive"><table class="table table-bordered table-sm align-middle">
<thead class="table-light"><tr><?php foreach ($head as $hh) echo '<th class="text-center" style="white-space:nowrap">' . h($hh) . '</th>'; ?></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?><tr>
<?php foreach ($r as $i => $cell): ?>
  <td class="<?php echo $i === 0 ? 'text-center fw-bold' : ''; ?>"><?php echo nl2br(h((string)$cell)); ?></td>
<?php endforeach; ?>
</tr><?php endforeach; ?>
</tbody></table></div>

<div class="row mt-4 small d-print-none">
  <div class="col-md-6"><b>Teacher:</b> <?php $t = teaching_teacher_for($class_id, $subject_id); echo $t ? h($t['full_name']) : '________________________'; ?></div>
  <div class="col-md-6 text-md-end"><b>Head of School:</b> ________________________ &nbsp;&nbsp; <b>Date:</b> ____________</div>
</div>
<?php endif; ?>
<?php layout_end(); ?>
