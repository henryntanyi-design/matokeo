<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
$sc = active_school();
$cnt = [
    'students' => (int)q_val("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'", [$school_id]),
    'teachers' => (int)q_val("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher' AND active = 1", [$school_id]),
    'exams' => (int)q_val('SELECT COUNT(*) FROM exam_schools WHERE school_id = ?', [$school_id]),
    'scores' => (int)q_val('SELECT COUNT(*) FROM scores WHERE school_id = ?', [$school_id]),
];
$exams = school_exams($school_id);
$exams = array_slice($exams, 0, 8);
$noPin = (int)q_val("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active' AND pin_hash IS NULL", [$school_id]);
$cards = [
    ['Students', $cnt['students'], 'people-fill', '#2c6ed5,#0b3d91', 'admin/students.php', 'Registered and active'],
    ['Teachers', $cnt['teachers'], 'person-badge', '#12b886,#0b7a5b', 'admin/teachers.php', 'Active staff accounts'],
    ['Exams', $cnt['exams'], 'journal-text', '#f59f00,#b06a00', 'admin/exams.php', 'School and district exams'],
    ['Scores entered', $cnt['scores'], 'clipboard-data', '#7048e8,#4c2fa8', 'admin/exams.php', 'Marks recorded so far'],
];
$u = user();
$firstName = $u && !empty($u['full_name']) ? preg_split('/\s+/', trim($u['full_name']))[0] : 'Administrator';
$place = [];
foreach (['centre_no' => 'Centre', 'district' => 'District', 'region' => 'Region'] as $k => $label) {
    if ($sc && !empty($sc[$k])) $place[] = $label . ' ' . $sc[$k];
}
layout_start('School Dashboard', 'dashboard'); ?>

<style>
.adx-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95)}
.adx-hero::before,.adx-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.adx-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.adx-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.adx-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.adx-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.adx-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.adx-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.adx-hero h1 span{color:#ffd764}
.adx-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.adx-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.adx-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.adx-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.adx-btn:hover{transform:translateY(-1px)}
.adx-btn.solid{background:#fff;color:#0b3d91}
.adx-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.adx-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.adx-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.adx-stat{display:flex;align-items:center;gap:13px;height:100%;padding:14px 16px;border-radius:16px;
  background:#fff;border:1px solid #e6ecf8;text-decoration:none;color:inherit;
  box-shadow:0 2px 12px rgba(11,61,145,.05);
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.adx-stat:hover{transform:translateY(-3px);border-color:#cfddf5;color:inherit;
  box-shadow:0 18px 30px -22px rgba(11,61,145,.85)}
.adx-stat-ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;
  font-size:1.15rem;flex:none;box-shadow:0 10px 18px -12px rgba(11,61,145,.9)}
.adx-stat-l{font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8b9ab1}
.adx-stat-v{font-size:1.5rem;font-weight:800;line-height:1.15;color:#16203a}
.adx-stat-s{font-size:.7rem;color:#a3b0c4}
.adx-stat-go{margin-left:auto;color:#c6d5ec;font-size:1rem}

.adx-note{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:11px 14px;border-radius:14px;
  background:linear-gradient(90deg,#fff8e6,#fffdf5);border:1px solid #f6e3b4;color:#7a5a00;font-size:.82rem}
.adx-note .adx-note-ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
  background:#ffd764;color:#7a5a00;flex:none}

.adx-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.adx-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.adx-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.adx-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.adx-ex-ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:none;
  background:#eef3fd;color:#0b3d91;font-size:.9rem}
.adx-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.adx-table tbody td{border-color:#f2f5fb}
.adx-table tbody tr:hover{background:#fafcff}
.adx-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.adx-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}
@media (max-width:575.98px){
  .adx-hero{padding:18px;border-radius:18px}
  .adx-hero h1{font-size:1.2rem}
  .adx-date{margin-left:0}
}
</style>

<div class="adx-hero mb-4">
    <div class="adx-head">
        <span class="adx-mark"><i class="bi bi-mortarboard-fill"></i></span>
        <div>
            <div class="adx-kicker">School dashboard</div>
            <h1>Welcome, <span><?php echo h($firstName); ?></span></h1>
            <p class="adx-sub">
                <?php echo h($sc['name'] ?? school_label()); ?>
                <?php if ($place): ?> &middot; <?php echo h(implode(' · ', $place)); ?><?php endif; ?>
            </p>
        </div>
        <span class="adx-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="adx-actions">
        <a class="adx-btn solid" href="<?php echo h(url('admin/exam_form.php')); ?>"><i class="bi bi-plus-lg"></i> New exam</a>
        <a class="adx-btn ghost" href="<?php echo h(url('admin/student_import.php')); ?>"><i class="bi bi-upload"></i> Import students</a>
        <a class="adx-btn ghost" href="<?php echo h(url('admin/subjects.php')); ?>"><i class="bi bi-book"></i> Subjects &amp; enrolment</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
        <a class="adx-stat" href="<?php echo h(url($c[4])); ?>">
            <span class="adx-stat-ic" style="background:linear-gradient(135deg,<?php echo h($c[3]); ?>)"><i class="bi bi-<?php echo h($c[2]); ?>"></i></span>
            <span class="d-block">
                <span class="d-block adx-stat-l"><?php echo h($c[0]); ?></span>
                <span class="d-block adx-stat-v"><?php echo (int)$c[1]; ?></span>
                <span class="d-block adx-stat-s"><?php echo h($c[5]); ?></span>
            </span>
            <i class="bi bi-arrow-right-short adx-stat-go"></i>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($noPin > 0 && $cnt['students'] > 0): ?>
<div class="adx-note mb-4">
    <span class="adx-note-ic"><i class="bi bi-key-fill"></i></span>
    <span><b><?php echo $noPin; ?></b> student<?php echo $noPin === 1 ? '' : 's'; ?> still have no portal PIN.</span>
    <a class="btn btn-sm btn-warning ms-auto fw-semibold" href="<?php echo h(url('admin/student_pins.php')); ?>"><i class="bi bi-shield-lock me-1"></i> Generate PINs</a>
</div>
<?php endif; ?>

<div class="adx-card">
    <div class="adx-card-h">
        <span class="adx-card-t"><i class="bi bi-journal-text text-primary"></i> School exams</span>
        <span class="adx-pill"><?php echo count($exams); ?> recent</span>
        <a class="btn btn-primary btn-sm ms-auto" href="<?php echo h(url('admin/exam_form.php')); ?>"><i class="bi bi-plus-lg me-1"></i> School exam</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 adx-table">
            <thead><tr><th class="ps-3">Exam</th><th>Owner</th><th>Year</th><th>Status</th><th class="text-end pe-3">Action</th></tr></thead>
            <tbody>
            <?php foreach ($exams as $e): ?>
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="adx-ex-ic"><i class="bi bi-file-earmark-text"></i></span>
                            <span class="fw-semibold"><?php echo h($e['name']); ?></span>
                        </div>
                    </td>
                    <td><?php echo $e['owner_school_id'] ? 'School' : '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">District</span>'; ?></td>
                    <td class="text-muted"><?php echo h($e['year_name']); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $e['status'] === 'open' ? 'success' : 'secondary'; ?>-subtle text-<?php echo $e['status'] === 'open' ? 'success' : 'secondary'; ?>-emphasis border border-<?php echo $e['status'] === 'open' ? 'success' : 'secondary'; ?>-subtle"><?php echo h($e['status']); ?></span>
                        <?php if ($e['published']): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">published</span><?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo h(url('admin/exam_view.php?id=' . (int)$e['id'])); ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exams): ?>
                <tr><td colspan="5">
                    <div class="adx-empty">
                        <i class="bi bi-journal-x"></i>
                        <div class="fw-semibold text-dark">No exams yet</div>
                        <div class="small">Create your first school exam to start entering scores.</div>
                        <a class="btn btn-primary btn-sm mt-3" href="<?php echo h(url('admin/exam_form.php')); ?>"><i class="bi bi-plus-lg me-1"></i> Create exam</a>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layout_end();
