<?php
/** Enter scores for one subject (admin: any; teacher: assigned subjects, exam must be open). */
require_once __DIR__ . '/lib/grading.php';
require_once __DIR__ . '/lib/layout.php';
$u = require_school_staff(); $school_id = active_school_id();
$exam_id = (int)($_GET['exam_id'] ?? 0); $class_id = (int)($_GET['class_id'] ?? 0); $subject_id = (int)($_GET['subject_id'] ?? 0);
$exam = get_exam($exam_id);
if (!$exam || !exam_has_school($exam_id, $school_id)) { flash('danger', 'The exam was not found or does not include this school.'); redirect(home_url()); }
if ($u['role'] === 'teacher' && empty($exam['owner_school_id'])) { flash('danger', 'District exam scores are entered by the school administrator.'); redirect('teacher/dashboard.php'); }
$myHome = $u['role'] !== 'teacher' ? 'admin/exam_view.php?id=' . $exam_id : 'teacher/dashboard.php';

/* ---- Choose class / subject ---- */
$pairs = [];
if ($u['role'] !== 'teacher') {
    foreach (exam_class_list($exam_id) as $c) foreach (exam_subject_list($exam_id, $c['id']) as $s) $pairs[] = [(int)$c['id'], $c['name'], (int)$s['id'], $s['name']];
} else {
    foreach (q_all('SELECT ta.class_id, c.name cname, ta.subject_id, s.name sname FROM teacher_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
                    JOIN exam_classes ec ON ec.class_id = ta.class_id AND ec.exam_id = ? WHERE ta.teacher_id = ? ORDER BY c.sort_order, s.name', [$exam_id, $u['id']]) as $r) {
        if (!in_array((int)$r['subject_id'], array_map('intval', array_column(exam_subject_list($exam_id, (int)$r['class_id']), 'id')), true)) continue;
        $pairs[] = [(int)$r['class_id'], $r['cname'], (int)$r['subject_id'], $r['sname']];
    }
}
$valid = false; $cname = ''; $sname = '';
foreach ($pairs as $p) if ($p[0] === $class_id && $p[2] === $subject_id) { $valid = true; $cname = $p[1]; $sname = $p[3]; }
if (!$valid) {
    layout_start('Score entry - ' . $exam['name'], $u['role'] !== 'teacher' ? 'exams' : 'dashboard'); ?>
<style>
.sc-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.sc-hero::before,.sc-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.sc-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.sc-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.sc-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.sc-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.sc-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.sc-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .3rem;line-height:1.2}
.sc-hero h1 span{color:#ffd764}
.sc-sub{font-size:.8rem;color:rgba(255,255,255,.88);margin:0}
.sc-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.sc-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.sc-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.sc-btn:hover{transform:translateY(-1px)}
.sc-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.sc-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.sc-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.sc-class-badge{display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:34px;padding:0 .7rem;
  border-radius:10px;background:linear-gradient(135deg,#0b3d91,#2c6ed5);color:#fff;font-weight:800;font-size:.95rem}
.sc-subject-btn{display:inline-flex;align-items:center;gap:7px;padding:.45rem .9rem;border-radius:10px;
  background:#f4f7fd;border:1px solid #d8e2f5;color:#0b3d91;font-weight:700;font-size:.82rem;text-decoration:none;
  transition:transform .15s ease,background .15s ease,box-shadow .15s ease}
.sc-subject-btn:hover{transform:translateY(-2px);background:#eaf1fe;box-shadow:0 6px 16px rgba(11,61,145,.12);color:#0b3d91}
.sc-subject-btn i{font-size:.9rem}
@media (max-width:575.98px){.sc-hero{padding:18px;border-radius:18px}.sc-hero h1{font-size:1.2rem}.sc-date{margin-left:0}}
</style>
<div class="sc-hero">
    <div class="sc-head">
        <span class="sc-mark"><i class="bi bi-pencil-square"></i></span>
        <div>
            <div class="sc-kicker">Exams &amp; Results</div>
            <h1>Score <span>entry</span></h1>
            <p class="sc-sub"><?php echo h($exam['name']); ?></p>
        </div>
        <span class="sc-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="sc-actions">
        <a class="sc-btn ghost" href="<?php echo h(url($myHome)); ?>"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>
<div class="sc-card"><div class="card-body">
    <p class="fw-semibold mb-2">Choose a class and subject:</p>
    <?php
    $cur = '';
    foreach ($pairs as $p) {
        $dispClass = preg_replace('/^Form\s+/i', '', $p[1]);
        if ($cur !== $p[1]) {
            if ($cur !== '') echo '</div></div>';
            echo '<div class="sc-class-block mb-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="sc-class-badge">' . h($dispClass) . '</span><span class="text-muted small">Class</span></div><div class="d-flex flex-wrap gap-2">';
            $cur = $p[1];
        }
        echo '<a class="sc-subject-btn" href="?exam_id=' . $exam_id . '&class_id=' . $p[0] . '&subject_id=' . $p[2] . '"><i class="bi bi-book"></i> ' . h($p[3]) . '</a>';
    }
    if ($cur !== '') echo '</div></div>';
    if (!$pairs) echo '<div class="text-muted">No subject has been assigned to you in this exam.</div>';
    ?>
</div></div>
<?php layout_end(); exit(); }

$editable = can_enter_scores($exam, $school_id, $class_id, $subject_id);
$cands = subject_candidates($exam_id, $school_id, $class_id, $subject_id);
if (is_post()) {
    csrf_check();
    if (!$editable) deny('The exam is closed or you are not allowed to enter scores for this subject.');
    $sc = $_POST['score'] ?? []; $ab = $_POST['absent'] ?? [];
    $saved = 0; $errs = [];
    try {
        db()->beginTransaction();
        foreach ($cands as $c) {
            $sid = (int)$c['student_id'];
            $res = save_score($exam_id, $school_id, $class_id, $sid, $subject_id, $sc[$sid] ?? '', isset($ab[$sid]));
            if ($res === 'ok') $saved++; elseif ($res !== 'skip') $errs[] = strtoupper($c['last_name'] . ' ' . $c['first_name']) . ': ' . $res;
        }
        if ($errs) { db()->rollBack(); flash('danger', 'Nothing was saved. Fix the following:<br>' . implode('<br>', array_map('h', $errs))); }
        else { db()->commit(); flash('success', "Saved $saved score(s)."); }
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    redirect("scores.php?exam_id=$exam_id&class_id=$class_id&subject_id=$subject_id");
}
$ex = [];
foreach (q_all('SELECT student_id, score, status FROM scores WHERE exam_id = ? AND school_id = ? AND subject_id = ? AND class_id = ?', [$exam_id, $school_id, $subject_id, $class_id]) as $r) $ex[(int)$r['student_id']] = $r;
layout_start('Score entry', $u['role'] !== 'teacher' ? 'exams' : 'dashboard'); ?>
<style>
.sc-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.sc-hero::before,.sc-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.sc-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.sc-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.sc-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.sc-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.sc-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.sc-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .3rem;line-height:1.2}
.sc-hero h1 span{color:#ffd764}
.sc-sub{font-size:.8rem;color:rgba(255,255,255,.88);margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:7px}
.sc-chip{display:inline-flex;align-items:center;gap:5px;padding:.16rem .6rem;border-radius:999px;font-size:.69rem;font-weight:700;
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3)}
.sc-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.sc-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.sc-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.sc-btn:hover{transform:translateY(-1px)}
.sc-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.sc-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.sc-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
@media (max-width:575.98px){.sc-hero{padding:18px;border-radius:18px}.sc-hero h1{font-size:1.2rem}.sc-date{margin-left:0}}
</style>
<div class="sc-hero">
    <div class="sc-head">
        <span class="sc-mark"><i class="bi bi-pencil-square"></i></span>
        <div>
            <div class="sc-kicker">Exams &amp; Results</div>
            <h1>Score <span>entry</span></h1>
            <p class="sc-sub">
                <span><?php echo h($exam['name']); ?></span>
                <span class="sc-chip"><i class="bi bi-building"></i> <?php echo h(preg_replace('/^Form\s+/i', '', $cname)); ?></span>
                <span class="sc-chip"><i class="bi bi-book"></i> <?php echo h($sname); ?></span>
            </p>
        </div>
        <span class="sc-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="sc-actions">
        <a class="sc-btn ghost" href="<?php echo h(url('scores.php?exam_id=' . $exam_id)); ?>"><i class="bi bi-arrow-left"></i> Another subject</a>
        <a class="sc-btn ghost" href="<?php echo h(url($myHome)); ?>"><i class="bi bi-house"></i> Back</a>
    </div>
</div>
<?php if (!$editable): ?><div class="alert alert-warning py-2">The exam is closed; you can only view. Contact the admin to change scores.</div><?php endif; ?>
<?php if (!$cands): ?><div class="alert alert-warning">No candidates for this subject. The admin must register candidates on the exam page (and choose student subjects if applicable).</div>
<?php else: ?>
<form method="post"><?php echo csrf_field(); ?><div class="sc-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th class="ps-3">#</th><th>Cand. No</th><th>Name</th><th>Sex</th><th style="width:140px">Score (0-100)</th><th class="text-center">Absent</th></tr></thead><tbody>
<?php $i = 1; foreach ($cands as $c): $sid = (int)$c['student_id']; $e = $ex[$sid] ?? null; $abs = $e && $e['status'] === 'ABSENT'; ?>
<tr><td class="ps-3 text-muted"><?php echo $i++; ?></td><td><?php echo h($c['cand_no']); ?></td><td class="fw-semibold text-uppercase"><?php echo h($c['last_name'] . ', ' . $c['first_name'] . ' ' . $c['middle_name']); ?></td><td><?php echo h($c['sex']); ?></td>
<td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm sc" name="score[<?php echo $sid; ?>]" value="<?php echo ($e && !$abs && $e['score'] !== null) ? h(rtrim(rtrim($e['score'], '0'), '.')) : ''; ?>" <?php echo ($abs || !$editable) ? 'disabled' : ''; ?>></td>
<td class="text-center"><input type="checkbox" class="form-check-input ab" name="absent[<?php echo $sid; ?>]" value="1" <?php echo $abs ? 'checked' : ''; ?> <?php echo $editable ? '' : 'disabled'; ?>></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($editable): ?><div class="card-footer bg-white d-flex justify-content-between align-items-center"><span class="small text-muted">Empty cells are not saved. Scores 0-100.</span><button class="btn btn-primary">Save scores</button></div><?php endif; ?></div></form>
<script>document.querySelectorAll('.ab').forEach(function(cb){cb.addEventListener('change',function(){var i=cb.closest('tr').querySelector('.sc');i.disabled=cb.checked;if(cb.checked)i.value='';});});</script>
<?php endif; layout_end(); ?>