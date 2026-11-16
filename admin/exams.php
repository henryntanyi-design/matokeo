<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
$rows = q_all("SELECT e.*, y.name AS year_name, es.published,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') FROM exam_classes ec JOIN classes c ON c.id = ec.class_id WHERE ec.exam_id = e.id) AS classes,
    (SELECT COUNT(*) FROM exam_candidates x WHERE x.exam_id = e.id AND x.school_id = es.school_id) AS cands, (SELECT COUNT(*) FROM scores s WHERE s.exam_id = e.id AND s.school_id = es.school_id) AS n_scores
    FROM exam_schools es JOIN exams e ON e.id = es.exam_id LEFT JOIN academic_years y ON y.id = e.academic_year_id WHERE es.school_id = ? ORDER BY e.id DESC", [$school_id]);
layout_start('Exams & Results', 'exams'); ?>

<style>
.ex-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.ex-hero::before,.ex-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.ex-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.ex-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.ex-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.ex-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.ex-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.ex-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.ex-hero h1 span{color:#ffd764}
.ex-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.ex-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.ex-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.ex-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.ex-btn:hover{transform:translateY(-1px)}
.ex-btn.solid{background:#fff;color:#0b3d91}
.ex-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.ex-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.ex-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.ex-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.ex-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.ex-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.ex-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.ex-empty{text-align:center;padding:40px 16px;color:#8b9ab1}
.ex-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}
.ex-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.ex-table tbody td{border-color:#f2f5fb;vertical-align:middle}
.ex-table tbody tr:hover{background:#fafcff}

@media (max-width:575.98px){
  .ex-hero{padding:18px;border-radius:18px}
  .ex-hero h1{font-size:1.2rem}
  .ex-date{margin-left:0}
}
</style>

<div class="ex-hero">
    <div class="ex-head">
        <span class="ex-mark"><i class="bi bi-clipboard2-check"></i></span>
        <div>
            <div class="ex-kicker">Exams &amp; results</div>
            <h1>Exams <span>&amp; results</span></h1>
            <p class="ex-sub">District exams (you were selected to participate in) and your school exams.</p>
        </div>
        <span class="ex-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="ex-actions">
        <a class="ex-btn solid" href="<?php echo h(url('admin/exam_form.php')); ?>"><i class="bi bi-plus-lg"></i> School exam</a>
        <a class="ex-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-house"></i> Dashboard</a>
    </div>
</div>

<div class="ex-card">
    <div class="ex-card-h">
        <span class="ex-card-t"><i class="bi bi-list-check text-primary"></i> Exam list</span>
        <span class="ex-pill"><?php echo count($rows); ?> exam(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 ex-table">
            <thead>
                <tr>
                    <th class="ps-3">Exam</th>
                    <th>Owner</th>
                    <th>Year</th>
                    <th>Classes</th>
                    <th>Candidates</th>
                    <th>Scores</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $e): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?php echo h($e['name']); ?></td>
                    <td><?php echo $e['owner_school_id'] ? '<span class="badge bg-light text-dark border">School</span>' : '<span class="badge bg-primary">District</span>'; ?></td>
                    <td><?php echo h($e['year_name']); ?></td>
                    <td class="small"><?php echo h($e['classes']); ?></td>
                    <td><?php echo (int)$e['cands']; ?></td>
                    <td><?php echo (int)$e['n_scores']; ?></td>
                    <td>
                        <span class="badge bg-<?php echo $e['status'] === 'open' ? 'success' : 'secondary'; ?>"><?php echo h($e['status']); ?></span>
                        <?php echo $e['published'] ? '<span class="badge bg-info ms-1">published</span>' : ''; ?>
                    </td>
                    <td class="text-end pe-3">
                        <a class="btn btn-primary btn-sm" href="exam_view.php?id=<?php echo (int)$e['id']; ?>"><i class="bi bi-eye me-1"></i> Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><div class="ex-empty"><i class="bi bi-inbox"></i><div class="fw-semibold text-dark">No exams yet.</div><div class="small">Create a school exam to get started.</div></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layout_end();