<?php
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/layout.php';
$s = require_student();
$exam_id = (int)($_GET['exam_id'] ?? 0);
$exam = get_exam($exam_id);
$cand = $exam ? q_one('SELECT class_id FROM exam_candidates WHERE exam_id = ? AND student_id = ?', [$exam_id, $s['id']]) : null;
if (!$exam || !is_published($exam_id, $s['school_id']) || !$cand) { flash('warning', 'Ripoti hii haipatikani.'); redirect('student/dashboard.php'); }
$d = compute_results($exam_id, (int)$cand['class_id'], (int)$s['school_id']); $mine = null;
foreach ($d['candidates'] as $c) if ((int)$c['student_id'] === (int)$s['id']) $mine = $c;
layout_start('Ripoti ya matokeo', 'dashboard'); ?>
<div class="d-flex flex-wrap gap-2 mb-3 no-print"><a class="btn btn-outline-secondary btn-sm" href="dashboard.php">Rudi</a>
<a class="btn btn-danger btn-sm" href="<?php echo h(url('export.php?exam_id=' . $exam_id . '&format=pdf')); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
<a class="btn btn-success btn-sm" href="<?php echo h(url('export.php?exam_id=' . $exam_id . '&format=xlsx')); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
<button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<div class="card shadow-sm" style="max-width:860px"><div class="card-body bg-white" style="overflow-x:auto"><?php echo $mine ? render_student_report($d, $mine) : '<div class="alert alert-warning mb-0">Matokeo yako hayajapatikana.</div>'; ?></div></div>
<?php layout_end();
