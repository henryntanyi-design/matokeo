<?php
/** Matokeo ya darasa (admin na walimu wa darasa hilo): muundo wa template/Excel au muundo wa NECTA. */
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/layout.php';
$u = require_school_staff(); $school_id = active_school_id();
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0);
$view = (($_GET['view'] ?? '') === 'necta') ? 'necta' : 'template';
$exam = get_exam($exam_id);
if (!$exam || !exam_has_school($exam_id, $school_id)) { flash('danger', 'Exam not found or does not include this school.'); redirect(home_url()); }
$classes = exam_class_list($exam_id);
if ($u['role'] === 'teacher') $classes = array_values(array_filter($classes, function ($c) use ($u) { return (bool)q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ?', [$u['id'], $c['id']]); }));
$ids = array_map('intval', array_column($classes, 'id'));
if (!$class_id && count($ids) === 1) $class_id = $ids[0];
if (!$class_id || !in_array($class_id, $ids, true)) {
    if ($class_id) deny('Huna ruhusa ya kuona matokeo ya darasa hili.');
    layout_start('Matokeo - ' . $exam['name'], $u['role'] !== 'teacher' ? 'exams' : 'results');
    echo '<div class="mb-2"><a class="btn btn-outline-secondary btn-sm" href="' . h(url('exam_view.php?id=' . $exam_id)) . '"><i class="bi bi-arrow-left me-1"></i> Back to exam</a></div>';
    echo '<div class="card shadow-sm"><div class="card-body"><p class="fw-semibold">Chagua darasa:</p>';
    foreach ($classes as $c) echo '<a class="btn btn-outline-primary me-2 mb-2" href="?exam_id=' . $exam_id . '&class_id=' . (int)$c['id'] . '">' . h($c['name']) . '</a>';
    if (!$classes) echo '<div class="text-muted">Hakuna darasa linalopatikana.</div>';
    echo '</div></div>'; layout_end(); exit();
}
$d = compute_results($exam_id, $class_id, $school_id);
$q = 'exam_id=' . $exam_id . '&class_id=' . $class_id . ($view === 'necta' ? '&view=necta' : '');
layout_start('Matokeo - ' . $exam['name'], $u['role'] !== 'teacher' ? 'exams' : 'results'); ?>
<style>
.necta-format {
    background-color: #fff;
    border-collapse: initial;
    border-color: #71848a #292926 #292926 #71848a;
    border-style: solid;
    border-width: 1px;
    font-family: "Times New Roman", Times, serif;
    color: #12314f;
    text-align: center;
    font-size: 0.7rem;
}</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
<a class="btn btn-outline-secondary btn-sm" href="<?php echo h(url('admin/exam_view.php?id=' . $exam_id)); ?>"><i class="bi bi-arrow-left me-1"></i> Back to exam</a>
<div class="d-flex flex-wrap gap-2 align-items-center">
<form method="get" class="d-flex flex-wrap gap-2 align-items-center"><input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
<select name="class_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><?php foreach ($classes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($class_id === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; ?></select>
<div class="form-check form-switch d-flex align-items-center gap-2 border rounded-pill bg-light shadow-sm px-3 py-2 mb-0" style="min-height:38px"><input class="form-check-input m-0" type="checkbox" role="switch" id="nv" name="view" value="necta" <?php echo $view === 'necta' ? 'checked' : ''; ?> onchange="this.form.submit()"><label class="form-check-label small fw-semibold text-nowrap mb-0" for="nv"><i class="bi bi-table me-1"></i>NECTA Format</label></div><a class="btn btn-outline-primary btn-sm text-nowrap" href="<?php echo h(url('exam_reports.php?exam_id=' . $exam_id . '&class_id=' . $class_id)); ?>"><i class="bi bi-file-earmark-text me-1"></i>Exam report Forms</a></form>
<div class="d-flex gap-2"><a class="btn btn-danger btn-sm" href="<?php echo h(url('export.php?' . $q . '&format=pdf')); ?>" download title="Pakua PDF ya matokeo (muundo wa NECTA - CSEE/ACSEE)"><i class="bi bi-file-earmark-pdf"></i> Download Result</a></div>
</div></div>
<?php if (!$d['candidates']): ?><div class="alert alert-warning no-print">Hakuna watahiniwa kwenye darasa hili. Admin asajili watahiniwa kwenye ukurasa wa mtihani.</div><?php endif; ?>
<div class="card shadow-sm"><div class="card-body necta-format" style="overflow-x:auto;background:#fff"><?php echo $view === 'necta' ? render_necta_results($d, ['show_names' => true]) : render_necta_results($d, ['show_names' => false]); ?></div></div>
<?php layout_end();
