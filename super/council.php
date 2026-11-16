<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0);
$exam = get_exam($exam_id);
if (!$exam) redirect('super/exams.php');
$classes = exam_class_list($exam_id);
if (!$class_id && $classes) $class_id = (int)$classes[0]['id'];
$c = $class_id ? compute_council($exam_id, $class_id) : null;
$q = 'type=council&exam_id=' . $exam_id . '&class_id=' . $class_id;
layout_start('Matokeo ya Wilaya', 'exams'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
<form method="get" class="d-flex gap-2"><input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>"><select name="class_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><?php foreach ($classes as $cl) echo '<option value="' . (int)$cl['id'] . '"' . ($class_id === (int)$cl['id'] ? ' selected' : '') . '>' . h($cl['name']) . '</option>'; ?></select></form>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="exam_view.php?id=<?php echo $exam_id; ?>">Rudi</a>
<a class="btn btn-danger btn-sm" href="<?php echo h(url('export.php?' . $q . '&format=pdf')); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
<a class="btn btn-success btn-sm" href="<?php echo h(url('export.php?' . $q . '&format=xlsx')); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
<button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div></div>
<div class="card shadow-sm"><div class="card-body bg-white" style="overflow-x:auto"><?php echo $c ? render_council($c) : '<div class="alert alert-warning mb-0">Mtihani huu hauna darasa.</div>'; ?></div></div>
<?php layout_end();
