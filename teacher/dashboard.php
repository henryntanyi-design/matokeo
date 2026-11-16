<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$u = require_school_staff(['teacher']); $school_id = active_school_id();
$rows = q_all("SELECT e.id AS eid, e.name AS ename, e.status, c.id AS cid, c.name AS cname, s.id AS sid, s.name AS sname
               FROM teacher_assignments ta JOIN exam_classes ec ON ec.class_id = ta.class_id JOIN exams e ON e.id = ec.exam_id
               JOIN exam_schools xs ON xs.exam_id = e.id AND xs.school_id = ?
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
               WHERE ta.teacher_id = ? AND e.owner_school_id IS NOT NULL ORDER BY (e.status = 'open') DESC, e.id DESC, c.sort_order, s.name", [$school_id, $u['id']]);
$nAssign = (int)q_val('SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id = ?', [$u['id']]);
$openExams = count(array_unique(array_column(array_filter($rows, function ($r) { return $r['status'] === 'open'; }), 'eid')));
$completed = 0;
foreach ($rows as $r) {
    $exp = count(subject_candidates($r['eid'], $school_id, $r['cid'], $r['sid']));
    $got = (int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?', [$r['eid'], $school_id, $r['cid'], $r['sid']]);
    if ($exp > 0 && $got >= $exp) $completed++;
}
layout_start('Teacher Dashboard', 'dashboard'); ?>
<style>
.td-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.td-hero::before,.td-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.td-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.td-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.td-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.td-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.td-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.td-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .3rem;line-height:1.2}
.td-hero h1 span{color:#ffd764}
.td-sub{font-size:.8rem;color:rgba(255,255,255,.88);margin:0}
.td-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.td-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.td-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.td-btn:hover{transform:translateY(-1px)}
.td-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.td-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.td-stat{background:#fff;border:1px solid #e6ecf8;border-radius:16px;padding:16px 18px;
  box-shadow:0 4px 18px rgba(11,61,145,.06);height:100%}
.td-stat .lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#7a879c;font-weight:800}
.td-stat .val{font-size:1.9rem;font-weight:800;color:#0b3d91;line-height:1.1;margin-top:4px}
.td-stat .ico{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:1.2rem;
  background:#eef4ff;color:#0b3d91;flex:none}
.td-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.td-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.td-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.td-bar{height:6px;border-radius:999px;background:#eef2fa;overflow:hidden}
.td-bar > span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#0b3d91,#2c6ed5)}
@media (max-width:575.98px){.td-hero{padding:18px;border-radius:18px}.td-hero h1{font-size:1.2rem}.td-date{margin-left:0}}
</style>

<div class="td-hero">
    <div class="td-head">
        <span class="td-mark"><i class="bi bi-person-workspace"></i></span>
        <div>
            <div class="td-kicker">Teacher</div>
            <h1>Welcome, <span><?php echo h($u['full_name'] ?? 'Teacher'); ?></span></h1>
            <p class="td-sub">Your assigned subjects and exams</p>
        </div>
        <span class="td-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="td-stat d-flex align-items-center gap-3">
        <span class="td-ico"><i class="bi bi-journal-bookmark"></i></span>
        <div><div class="lbl">Assigned subjects</div><div class="val"><?php echo $nAssign; ?></div></div>
    </div></div>
    <div class="col-md-4"><div class="td-stat d-flex align-items-center gap-3">
        <span class="td-ico"><i class="bi bi-unlock"></i></span>
        <div><div class="lbl">Open exams</div><div class="val"><?php echo $openExams; ?></div></div>
    </div></div>
    <div class="col-md-4"><div class="td-stat d-flex align-items-center gap-3">
        <span class="td-ico"><i class="bi bi-check2-circle"></i></span>
        <div><div class="lbl">Completed entries</div><div class="val"><?php echo $completed; ?></div></div>
    </div></div>
</div>

<?php if (!$nAssign): ?><div class="alert alert-warning">No subject has been assigned to you yet. Contact the administrator.</div><?php endif; ?>

<div class="td-card">
    <div class="td-card-h">
        <span class="td-card-t"><i class="bi bi-clipboard2-data"></i> Score entry</span>
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-auto"><?php echo count($rows); ?> assignment<?php echo count($rows) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th class="ps-3">Exam</th><th>Class</th><th>Subject</th><th>Progress</th><th>Status</th><th class="text-end pe-3">Action</th></tr></thead><tbody>
    <?php foreach ($rows as $r):
        $exp = count(subject_candidates($r['eid'], $school_id, $r['cid'], $r['sid']));
        $got = (int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?', [$r['eid'], $school_id, $r['cid'], $r['sid']]);
        $pct = $exp > 0 ? min(100, (int)round($got / $exp * 100)) : 0;
        $done = $exp > 0 && $got >= $exp; ?>
    <tr>
        <td class="ps-3 fw-semibold"><?php echo h($r['ename']); ?></td>
        <td><?php echo h(preg_replace('/^Form\s+/i', '', $r['cname'])); ?></td>
        <td><?php echo h($r['sname']); ?></td>
        <td style="min-width:120px">
            <div class="d-flex align-items-center gap-2">
                <div class="td-bar flex-grow-1"><span style="width:<?php echo $pct; ?>%"></span></div>
                <span class="badge bg-<?php echo $done ? 'success' : ($got ? 'warning text-dark' : 'secondary'); ?>"><?php echo $got . ' / ' . $exp; ?></span>
            </div>
        </td>
        <td><span class="badge bg-<?php echo $r['status'] === 'open' ? 'success' : 'secondary'; ?>"><?php echo h(ucfirst($r['status'])); ?></span></td>
        <td class="text-end pe-3">
            <a class="btn btn-primary btn-sm" href="../scores.php?exam_id=<?php echo (int)$r['eid']; ?>&class_id=<?php echo (int)$r['cid']; ?>&subject_id=<?php echo (int)$r['sid']; ?>"><?php echo $r['status'] === 'open' ? 'Enter scores' : 'View'; ?></a>
            <?php if ($r['status'] === 'open'): ?><a class="btn btn-outline-success btn-sm" href="../score_upload.php?exam_id=<?php echo (int)$r['eid']; ?>&class_id=<?php echo (int)$r['cid']; ?>&subject_id=<?php echo (int)$r['sid']; ?>">Upload</a><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="6" class="text-center text-muted py-4">No exam currently involves your subjects.</td></tr>'; ?></tbody></table></div></div>
<?php layout_end(); ?>