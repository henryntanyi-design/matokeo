<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
$id = (int)($_GET['exam_id'] ?? $_GET['id'] ?? 0);
$exam = get_exam($id);
if (!$exam || !exam_has_school($id, $school_id)) {
    flash('danger', 'This exam is not part of this school.');
    redirect('admin/exams.php');
}

$prog = exam_progress($id, $school_id);
$total = count($prog);
$done = 0;
foreach ($prog as $p) {
    if ($p['expected'] > 0 && $p['entered'] >= $p['expected']) $done++;
}
$pending = $total - $done;
$shortClass = function ($name) { return preg_replace('/^Form\s+/i', '', (string)$name); };

layout_start('Score Progress: ' . $exam['name'], 'exams'); ?>

<style>
.ep-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.ep-hero::before,.ep-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.ep-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.ep-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.ep-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.ep-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.ep-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.ep-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.ep-hero h1 span{color:#ffd764}
.ep-sub{font-size:.8rem;color:rgba(255,255,255,.85);margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.ep-pill{display:inline-flex;align-items:center;gap:5px;padding:.16rem .6rem;border-radius:999px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.ep-pill.open{background:rgba(75,222,158,.2);color:#b9ffe0;border:1px solid rgba(75,222,158,.45)}
.ep-pill.closed{background:rgba(255,255,255,.16);color:#e7ecf8;border:1px solid rgba(255,255,255,.3)}
.ep-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.ep-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.ep-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.ep-btn:hover{transform:translateY(-1px)}
.ep-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.ep-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.ep-stat{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e6ecf8;border-radius:16px;
  padding:16px 18px;height:100%;box-shadow:0 4px 18px rgba(11,61,145,.06)}
.ep-stat .ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;font-size:1.2rem;flex:none}
.ep-stat .lb{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;font-weight:800;color:#6b7a90}
.ep-stat .vl{font-size:1.6rem;font-weight:800;line-height:1.1;color:#16203a}
.ep-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.ep-card-h{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;
  padding:15px 18px;border-bottom:1px solid #edf1fa}
.ep-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.ep-card-h .hint{font-size:.75rem;color:#7a879c}
.ep-table th{font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:#5b6b85;
  background:#f6f8fd;font-weight:800;padding:.7rem .65rem;border-bottom:1px solid #e7ecf7;white-space:nowrap}
.ep-table td{font-size:.85rem;padding:.6rem .65rem;vertical-align:middle;border-color:#eff2f9}
.ep-table tbody tr:hover{background:#f8fbff}
.ep-cls{display:inline-flex;min-width:40px;justify-content:center;padding:.15rem .55rem;border-radius:8px;
  background:#eef4ff;color:#0b3d91;font-weight:800;font-size:.78rem}
.ep-bar{height:7px;border-radius:999px;background:#eef2fa;overflow:hidden;min-width:110px}
.ep-bar > span{display:block;height:100%;border-radius:999px}
.ep-empty{text-align:center;padding:42px 16px;color:#7a879c}
.ep-empty i{font-size:2rem;color:#c3cee2;display:block;margin-bottom:8px}
@media (max-width:575.98px){
  .ep-hero{padding:18px;border-radius:18px}
  .ep-hero h1{font-size:1.2rem}
  .ep-date{margin-left:0}
}
</style>

<div class="ep-hero">
    <div class="ep-head">
        <span class="ep-mark"><i class="bi bi-journal-check"></i></span>
        <div>
            <div class="ep-kicker">Exams &amp; Results</div>
            <h1>Score <span>Progress</span></h1>
            <p class="ep-sub">
                <?php echo h($exam['name']); ?>
                <span class="ep-pill <?php echo $exam['status'] === 'open' ? 'open' : 'closed'; ?>">
                    <i class="bi bi-<?php echo $exam['status'] === 'open' ? 'unlock' : 'lock'; ?>"></i> <?php echo h(ucfirst($exam['status'])); ?>
                </span>
            </p>
        </div>
        <span class="ep-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="ep-actions">
        <a class="ep-btn ghost" href="<?php echo h(url('admin/exam_view.php?id=' . $id)); ?>"><i class="bi bi-arrow-left"></i> Back to exam</a>
        <a class="ep-btn ghost" href="<?php echo h(url('admin/exams.php')); ?>"><i class="bi bi-journal-text"></i> All exams</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="ep-stat">
            <span class="ic" style="background:#eef4ff;color:#1b5bc4"><i class="bi bi-journal-text"></i></span>
            <div><div class="lb">Subjects</div><div class="vl"><?php echo $total; ?></div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ep-stat">
            <span class="ic" style="background:#e8f8f0;color:#1b7f4b"><i class="bi bi-check2-circle"></i></span>
            <div><div class="lb">Complete</div><div class="vl text-success"><?php echo $done; ?></div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ep-stat">
            <span class="ic" style="background:#fff6e5;color:#b06a00"><i class="bi bi-hourglass-split"></i></span>
            <div><div class="lb">Pending</div><div class="vl" style="color:#b06a00"><?php echo $pending; ?></div></div>
        </div>
    </div>
</div>

<div class="ep-card">
    <div class="ep-card-h">
        <span class="ep-card-t"><i class="bi bi-table text-primary"></i> Subjects and score entry</span>
        <span class="hint">Scores entered / expected candidates</span>
    </div>
    <div class="table-responsive">
        <table class="table ep-table table-hover align-middle mb-0">
            <thead><tr><th class="ps-3">Class</th><th>Subject</th><th>Progress</th><th class="text-end pe-3">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($prog as $p):
                $isDone = $p['expected'] > 0 && $p['entered'] >= $p['expected'];
                $pct = $p['expected'] > 0 ? min(100, (int)round(($p['entered'] / $p['expected']) * 100)) : 0;
                $barColor = $isDone ? '#1b7f4b' : ($pct > 0 ? '#d99a00' : '#c3cee2');
            ?>
                <tr>
                    <td class="ps-3"><span class="ep-cls"><?php echo h($shortClass($p['class_name'])); ?></span></td>
                    <td class="fw-semibold"><?php echo h($p['subject']); ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="ep-bar"><span style="width:<?php echo $pct; ?>%;background:<?php echo $barColor; ?>"></span></div>
                            <span class="badge bg-<?php echo $isDone ? 'success' : ($p['entered'] ? 'warning text-dark' : 'secondary'); ?>"><?php echo $p['entered'] . ' / ' . $p['expected']; ?></span>
                        </div>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-outline-primary btn-sm py-0" href="<?php echo h(url('scores.php?exam_id=' . $id . '&class_id=' . (int)$p['class_id'] . '&subject_id=' . (int)$p['subject_id'])); ?>"><i class="bi bi-pencil-square me-1"></i>Enter</a>
                            <a class="btn btn-outline-success btn-sm py-0" href="<?php echo h(url('score_upload.php?exam_id=' . $id . '&class_id=' . (int)$p['class_id'] . '&subject_id=' . (int)$p['subject_id'])); ?>" title="Upload or scan this subject template"><i class="bi bi-upload me-1"></i>Upload</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$prog): ?>
                <tr><td colspan="4">
                    <div class="ep-empty"><i class="bi bi-inbox"></i>This exam has no class subjects yet.</div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layout_end(); ?>