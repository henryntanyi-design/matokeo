<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$u = require_school_staff(['teacher']); $school_id = active_school_id();
$rows = q_all('SELECT DISTINCT e.id AS eid, e.name AS ename, xs.published, c.id AS cid, c.name AS cname, c.sort_order FROM teacher_assignments ta
               JOIN exam_classes ec ON ec.class_id = ta.class_id JOIN exams e ON e.id = ec.exam_id JOIN exam_schools xs ON xs.exam_id = e.id AND xs.school_id = ? JOIN classes c ON c.id = ta.class_id
               WHERE ta.teacher_id = ? ORDER BY e.id DESC, c.sort_order', [$school_id, $u['id']]);
layout_start('Results', 'results'); ?>
<style>
.rl-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.rl-hero::before,.rl-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.rl-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.rl-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.rl-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.rl-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.rl-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.rl-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .3rem;line-height:1.2}
.rl-hero h1 span{color:#ffd764}
.rl-sub{font-size:.8rem;color:rgba(255,255,255,.88);margin:0}
.rl-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.rl-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
@media (max-width:575.98px){.rl-hero{padding:18px;border-radius:18px}.rl-hero h1{font-size:1.2rem}.rl-date{margin-left:0}}
</style>
<div class="rl-hero">
    <div class="rl-head">
        <span class="rl-mark"><i class="bi bi-graph-up-arrow"></i></span>
        <div>
            <div class="rl-kicker">Teacher</div>
            <h1>My <span>results</span></h1>
            <p class="rl-sub">View results for your assigned classes</p>
        </div>
        <span class="rl-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
</div>
<div class="rl-card">
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th class="ps-3">Exam</th><th>Class</th><th>Published</th><th class="text-end pe-3">Action</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td class="ps-3 fw-semibold"><?php echo h($r['ename']); ?></td>
        <td><?php echo h(preg_replace('/^Form\s+/i', '', $r['cname'])); ?></td>
        <td><?php echo $r['published'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
        <td class="text-end pe-3"><a class="btn btn-primary btn-sm" href="../results.php?exam_id=<?php echo (int)$r['eid']; ?>&class_id=<?php echo (int)$r['cid']; ?>">View results</a></td>
    </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="text-center text-muted py-4">No results to display.</td></tr>'; ?></tbody></table></div>
</div>
<?php layout_end(); ?>