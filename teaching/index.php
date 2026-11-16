<?php
require_once __DIR__ . '/../lib/teaching.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_staff(); $school_id = active_school_id();
$tid = teaching_active_teacher_id();
$classes = teaching_classes_for($school_id, $tid);
$ready = teaching_tables_ready();
$nTopics = $ready ? (int)q_val('SELECT COUNT(*) FROM syllabus_topics WHERE school_id = ?', [$school_id]) : 0;
$nLogs = $ready ? (int)q_val('SELECT COUNT(*) FROM subject_logs WHERE school_id = ?', [$school_id]) : 0;
$nSubjects = 0;
foreach ($classes as $c) $nSubjects += count(teaching_subjects_for((int)$c['id'], $tid));
layout_start('Teaching', 'teach');
teaching_nav('index');
?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h5 class="fw-bold mb-1"><i class="bi bi-calendar3"></i> Teaching Module</h5>
  <p class="text-muted mb-0">Create the teaching timetable, scheme of work, subject logbook and subject syllabuses for the classes and subjects assigned to you.</p>
</div></div>

<?php if (!$ready): ?>
<div class="alert alert-warning"><b>Note:</b> the tables for this module are not installed. The administrator (admin) should import <code>database/migration_teaching.sql</code> in phpMyAdmin. Scheme of work, logbook, syllabus and saved timetables require these tables.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Classes</div><div class="fs-3 fw-bold"><?php echo count($classes); ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Subjects</div><div class="fs-3 fw-bold"><?php echo $nSubjects; ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Syllabus topics</div><div class="fs-3 fw-bold"><?php echo $nTopics; ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Logbook entries</div><div class="fs-3 fw-bold"><?php echo $nLogs; ?></div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
    <div class="fs-3 text-primary mb-2"><i class="bi bi-calendar3-week"></i></div>
    <h6 class="fw-bold">Teaching Timetable</h6>
    <p class="text-muted small flex-grow-1">Saved timetables in the MSSIS layout: create a new timetable (breaks, events, Saturday/Sunday, periods per subject), then publish it. Filters, Excel and PDF.</p>
    <a class="btn btn-sm btn-primary" href="<?php echo h(url('teaching/timetable.php')); ?>">Open timetable</a>
  </div></div></div>
  <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
    <div class="fs-3 text-success mb-2"><i class="bi bi-journal-text"></i></div>
    <h6 class="fw-bold">Scheme of Work</h6>
    <p class="text-muted small flex-grow-1">Termly work plan by week: topic, objectives, activities, materials and assessment.</p>
    <a class="btn btn-sm btn-success" href="<?php echo h(url('teaching/scheme.php')); ?>">Open scheme</a>
  </div></div></div>
  <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
    <div class="fs-3 text-warning mb-2"><i class="bi bi-journal-check"></i></div>
    <h6 class="fw-bold">Subject Logbook</h6>
    <p class="text-muted small flex-grow-1">Records for every lesson: date, period, topic, activities and remarks.</p>
    <a class="btn btn-sm btn-warning" href="<?php echo h(url('teaching/logbook.php')); ?>">Open logbook</a>
  </div></div></div>
  <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
    <div class="fs-3 text-info mb-2"><i class="bi bi-book-half"></i></div>
    <h6 class="fw-bold">Syllabus</h6>
    <p class="text-muted small flex-grow-1">Enter term topics; the scheme of work uses these topics directly.</p>
    <a class="btn btn-sm btn-info text-white" href="<?php echo h(url('teaching/syllabus.php')); ?>">Open syllabus</a>
  </div></div></div>
</div>

<?php if (!$classes): ?>
<div class="alert alert-info mt-3">No class/subject has been assigned to you yet.<?php echo is_admin() ? ' Go to <a href="' . h(url('admin/assignments.php')) . '">Assignments</a> to assign teachers to subjects.' : ' Contact the head of school (admin) so that you are assigned subjects.'; ?></div>
<?php endif; ?>
<?php layout_end();
