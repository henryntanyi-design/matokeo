<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$s = require_student();
$exams = q_all('SELECT e.id, e.name, e.exam_type, y.name AS year_name, ec.class_id FROM exam_candidates ec JOIN exams e ON e.id = ec.exam_id JOIN exam_schools xs ON xs.exam_id = e.id AND xs.school_id = ec.school_id AND xs.published = 1
                LEFT JOIN academic_years y ON y.id = e.academic_year_id WHERE ec.student_id = ? ORDER BY e.id DESC', [$s['id']]);
$list = [];
foreach ($exams as $e) {
    $d = compute_results($e['id'], $e['class_id'], $s['school_id']); $mine = null;
    if ($d) foreach ($d['candidates'] as $c) if ((int)$c['student_id'] === (int)$s['id']) $mine = $c;
    $list[] = [$e, $mine];
}
layout_start('Matokeo Yangu', 'dashboard'); ?>
<div class="card shadow-sm mb-3"><div class="card-body py-3"><div class="fw-bold fs-5"><?php echo h(strtoupper(full_name($s))); ?></div>
<div class="text-muted small">PREMS No: <?php echo h($s['prems_no'] ?: $s['admission_no']); ?> &middot; Darasa: <?php echo h($s['class_name']); ?></div></div></div>
<div class="card shadow-sm"><div class="card-header bg-white fw-semibold">Mitihani iliyochapishwa</div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th class="ps-3">Mtihani</th><th>Mwaka</th><th>Division</th><th>Points</th><th>Wastani</th><th>Nafasi</th><th></th></tr></thead><tbody>
<?php foreach ($list as $row): $e = $row[0]; $m = $row[1]; ?><tr><td class="ps-3 fw-semibold"><?php echo h($e['name']); ?></td><td><?php echo h($e['year_name']); ?></td>
<td><b><?php echo $m ? h($m['division']) : '-'; ?></b></td><td><?php echo ($m && $m['points'] !== null) ? (int)$m['points'] : '-'; ?></td><td><?php echo ($m && $m['average'] !== null) ? h(number_format($m['average'], 2)) : '-'; ?></td>
<td><?php echo ($m && isset($m['position'])) ? (int)$m['position'] . ' / ' . (int)$m['class_size'] : '-'; ?></td>
<td class="text-end pe-3"><a class="btn btn-primary btn-sm" href="report.php?exam_id=<?php echo (int)$e['id']; ?>">Tazama ripoti</a></td></tr><?php endforeach;
if (!$list) echo '<tr><td colspan="7" class="text-center text-muted py-4">Hakuna matokeo yaliyochapishwa bado. Jaribu tena baadaye.</td></tr>'; ?></tbody></table></div></div>
<?php layout_end();
