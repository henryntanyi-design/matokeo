<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

/** Je, safu ya `stream` kwenye teacher_assignments imesakinishwa? */
function assignments_streams_ready() {
    static $ok = null;
    if ($ok === null) {
        try { q_val('SELECT stream FROM teacher_assignments LIMIT 1'); $ok = true; }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

$hasStream = assignments_streams_ready();

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    if ($a === 'add') {
        $tid = (int)($_POST['teacher_id'] ?? 0); $pair = explode(':', (string)($_POST['pair'] ?? ''));
        $cid = (int)($pair[0] ?? 0); $sid = (int)($pair[1] ?? 0);
        $stream = $hasStream ? trim((string)($_POST['stream'] ?? '')) : '';
        $stream = trim(preg_replace('/\s+/', ' ', $stream));
        $stream = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', $stream));
        if (mb_strlen($stream) > 20) $stream = mb_substr($stream, 0, 20);
        if ($tid && $cid && $sid && q_val('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?', [$cid, $sid]) && q_val("SELECT 1 FROM users WHERE id = ? AND active = 1 AND school_id = ?", [$tid, $school_id])) {
            if (!teacher_has($tid, $cid, $sid)) {
                flash('danger', 'This subject is not registered for this teacher. Assign subjects first under Teachers & Users.');
            } else {
                if ($hasStream) {
                    q('INSERT IGNORE INTO teacher_assignments (teacher_id, class_id, subject_id, stream) VALUES (?, ?, ?, ?)', [$tid, $cid, $sid, $stream]);
                } else {
                    q('INSERT IGNORE INTO teacher_assignments (teacher_id, class_id, subject_id) VALUES (?, ?, ?)', [$tid, $cid, $sid]);
                }
                flash('success', 'Assignment added.');
            }
        } else flash('danger', 'Choose a valid teacher and subject/class.');
    } elseif ($a === 'delete') { q('DELETE FROM teacher_assignments WHERE id = ? AND teacher_id IN (SELECT id FROM users WHERE school_id = ?)', [(int)($_POST['id'] ?? 0), $school_id]); flash('success', 'Assignment removed.'); }
    redirect('admin/assignments.php' . (!empty($_POST['teacher_id']) ? '?teacher_id=' . (int)$_POST['teacher_id'] : ''));
}
$teachers = q_all("SELECT id, full_name, username FROM users WHERE active = 1 AND school_id = ? ORDER BY full_name", [$school_id]);
$tid = (int)($_GET['teacher_id'] ?? $_POST['teacher_id'] ?? 0);

/* Somo la kuchagua = yale tu aliyosajiliwa mwalimu kwenye Teachers & Users (class_subjects). */
$pairs = [];
if ($tid) {
    $pairs = q_all('SELECT DISTINCT c.id AS cid, c.name AS cname, c.sort_order AS csort, s.id AS sid, s.name AS sname
                    FROM teacher_assignments ta
                    JOIN class_subjects cs ON cs.class_id = ta.class_id AND cs.subject_id = ta.subject_id
                    JOIN classes c ON c.id = ta.class_id
                    JOIN subjects s ON s.id = ta.subject_id
                    WHERE ta.teacher_id = ?
                    ORDER BY c.sort_order, s.name', [$tid]);
}
$list = q_all('SELECT ta.id, ta.teacher_id, u.full_name, c.name AS cname, s.name AS sname' . ($hasStream ? ', ta.stream' : ", '' AS stream") . ' FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id WHERE u.school_id = ?'
    . ($hasStream ? " AND ta.stream <> ''" : '')
    . ($tid ? ' AND ta.teacher_id = ' . $tid : '') . ' ORDER BY u.full_name, c.sort_order, s.name', [$school_id]);

/* Streams zilizopo: kutoka kwa wanafunzi na ugawaji uliopo. */
$streamOptions = [];
if ($hasStream) {
    foreach (q_all("SELECT DISTINCT stream FROM students WHERE school_id = ? AND stream IS NOT NULL AND stream <> '' ORDER BY stream", [$school_id]) as $r) $streamOptions[] = (string)$r['stream'];
    foreach (q_all("SELECT DISTINCT stream FROM teacher_assignments WHERE stream <> '' ORDER BY stream") as $r) $streamOptions[] = (string)$r['stream'];
    $streamOptions = array_values(array_unique($streamOptions));
    sort($streamOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

$nAssign = count($list);
$nTeachers = count($teachers);

layout_start('Teacher Assignments', 'assign'); ?>

<style>
.asg-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95)}
.asg-hero::before,.asg-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.asg-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.asg-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.asg-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.asg-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.asg-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.asg-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.asg-hero h1 span{color:#ffd764}
.asg-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.asg-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.asg-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.asg-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.asg-btn:hover{transform:translateY(-1px)}
.asg-btn.solid{background:#fff;color:#0b3d91}
.asg-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.asg-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.asg-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.asg-stat{display:flex;align-items:center;gap:13px;height:100%;padding:14px 16px;border-radius:16px;
  background:#fff;border:1px solid #e6ecf8;text-decoration:none;color:inherit;
  box-shadow:0 2px 12px rgba(11,61,145,.05);
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.asg-stat:hover{transform:translateY(-3px);border-color:#cfddf5;color:inherit;
  box-shadow:0 18px 30px -22px rgba(11,61,145,.85)}
.asg-stat-ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;
  font-size:1.15rem;flex:none;box-shadow:0 10px 18px -12px rgba(11,61,145,.9)}
.asg-stat-l{font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8b9ab1}
.asg-stat-v{font-size:1.5rem;font-weight:800;line-height:1.15;color:#16203a}
.asg-stat-s{font-size:.7rem;color:#a3b0c4}

.asg-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.asg-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.asg-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.asg-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.asg-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.asg-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}

.asg-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.asg-table tbody td{border-color:#f2f5fb;vertical-align:middle}
.asg-table tbody tr:hover{background:#fafcff}

.asg-warn{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:11px 14px;border-radius:14px;
  background:linear-gradient(90deg,#fff8e6,#fffdf5);border:1px solid #f6e3b4;color:#7a5a00;font-size:.82rem}
.asg-warn .asg-warn-ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
  background:#ffd764;color:#7a5a00;flex:none}

.asg-filter select{border:1px solid #d8e2f5;border-radius:999px;padding:.35rem .8rem;font-size:.82rem;background:#fff}

@media (max-width:575.98px){
  .asg-hero{padding:18px;border-radius:18px}
  .asg-hero h1{font-size:1.2rem}
  .asg-date{margin-left:0}
}
</style>

<?php if (!$hasStream): ?>
<div class="asg-warn mb-4">
    <span class="asg-warn-ic"><i class="bi bi-exclamation-triangle-fill"></i></span>
    <span><b>Stream not set up:</b> import <code>database/migration_streams.sql</code> to enable streams in assignments and timetables.</span>
</div>
<?php endif; ?>

<div class="asg-hero mb-4">
    <div class="asg-head">
        <span class="asg-mark"><i class="bi bi-diagram-3"></i></span>
        <div>
            <div class="asg-kicker">Assignments</div>
            <h1>Teacher <span>Assignments</span></h1>
            <p class="asg-sub">
                <?php echo $nAssign; ?> assignment<?php echo $nAssign === 1 ? '' : 's'; ?>
                across <?php echo $nTeachers; ?> active teacher<?php echo $nTeachers === 1 ? '' : 's'; ?>
                <?php if ($tid): ?> &middot; filtered by selected teacher<?php endif; ?>
            </p>
        </div>
        <span class="asg-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="asg-actions">
        <a class="asg-btn solid" href="<?php echo h(url('admin/teachers.php')); ?>"><i class="bi bi-people"></i> Teachers &amp; Users</a>
        <a class="asg-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="asg-stat">
            <span class="asg-stat-ic" style="background:linear-gradient(135deg,#2c6ed5,#0b3d91)"><i class="bi bi-diagram-3"></i></span>
            <span class="d-block">
                <span class="d-block asg-stat-l">Assignments</span>
                <span class="d-block asg-stat-v"><?php echo $nAssign; ?></span>
                <span class="d-block asg-stat-s">Total recorded</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="asg-stat">
            <span class="asg-stat-ic" style="background:linear-gradient(135deg,#12b886,#0b7a5b)"><i class="bi bi-person-badge"></i></span>
            <span class="d-block">
                <span class="d-block asg-stat-l">Active teachers</span>
                <span class="d-block asg-stat-v"><?php echo $nTeachers; ?></span>
                <span class="d-block asg-stat-s">Available to assign</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="asg-stat">
            <span class="asg-stat-ic" style="background:linear-gradient(135deg,#7048e8,#4c2fa8)"><i class="bi bi-book"></i></span>
            <span class="d-block">
                <span class="d-block asg-stat-l">Streams</span>
                <span class="d-block asg-stat-v"><?php echo $hasStream ? count($streamOptions) : 0; ?></span>
                <span class="d-block asg-stat-s"><?php echo $hasStream ? 'Available' : 'Not set up'; ?></span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="asg-stat">
            <span class="asg-stat-ic" style="background:linear-gradient(135deg,#f59f00,#b06a00)"><i class="bi bi-funnel"></i></span>
            <span class="d-block">
                <span class="d-block asg-stat-l">Filter</span>
                <span class="d-block asg-stat-v" style="font-size:1.1rem"><?php echo $tid ? 'One teacher' : 'All'; ?></span>
                <span class="d-block asg-stat-s">Current view</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="asg-card h-100">
            <div class="asg-card-h">
                <span class="asg-card-t"><i class="bi bi-plus-circle text-primary"></i> Assign a teacher to a subject</span>
                <span class="asg-pill">New</span>
            </div>
            <div class="card-body p-4">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Teacher</label>
                        <select name="teacher_id" class="form-select" required onchange="window.location = this.value ? 'assignments.php?teacher_id=' + encodeURIComponent(this.value) : 'assignments.php';">
                            <option value="">-- choose --</option>
                            <?php foreach ($teachers as $t) echo '<option value="' . (int)$t['id'] . '"' . ($tid === (int)$t['id'] ? ' selected' : '') . '>' . h($t['full_name']) . ' (' . h($t['username']) . ')</option>'; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Class - Subject</label>
                        <select name="pair" class="form-select" required<?php echo $pairs ? '' : ' disabled'; ?>>
                            <option value=""><?php echo $tid ? ($pairs ? '-- choose --' : 'no registered subjects') : 'choose a teacher first'; ?></option>
                            <?php foreach ($pairs as $p) echo '<option value="' . (int)$p['cid'] . ':' . (int)$p['sid'] . '">' . h($p['cname'] . ' - ' . $p['sname']) . '</option>'; ?>
                        </select>
                        <div class="form-text">Only subjects registered for this teacher under <b>Teachers &amp; Users</b> appear here.</div>
                    </div>
                    <?php if ($hasStream): ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Stream (optional)</label>
                        <input type="text" name="stream" class="form-control" list="streamOptions" maxlength="20" placeholder="e.g. A (leave empty for whole class)">
                        <datalist id="streamOptions"><?php foreach ($streamOptions as $st) echo '<option value="' . h($st) . '">'; ?></datalist>
                        <div class="form-text">Set a stream if this subject is taught to a specific stream; leave empty for the whole class.</div>
                    </div>
                    <?php endif; ?>
                    <button class="btn btn-primary w-100" <?php echo $pairs ? '' : ' disabled'; ?>><i class="bi bi-plus-lg me-1"></i> Add</button>
                </form>
                <?php if (!$tid): ?>
                    <div class="small text-muted mt-3">Choose a teacher first to see their registered subjects.</div>
                <?php elseif (!$pairs): ?>
                    <div class="small text-danger mt-3">This teacher has no registered subjects. Assign subjects under <a href="teacher_form.php?id=<?php echo $tid; ?>">Teachers &amp; Users</a> first.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="asg-card">
            <div class="asg-card-h">
                <span class="asg-card-t"><i class="bi bi-list-ul text-primary"></i> Existing assignments</span>
                <span class="asg-pill"><?php echo $nAssign; ?> shown</span>
                <form method="get" class="ms-auto">
                    <select name="teacher_id" class="form-select form-select-sm asg-filter" onchange="this.form.submit()">
                        <option value="">All teachers</option>
                        <?php foreach ($teachers as $t) echo '<option value="' . (int)$t['id'] . '"' . ($tid === (int)$t['id'] ? ' selected' : '') . '>' . h($t['full_name']) . '</option>'; ?>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 asg-table">
                    <thead><tr>
                        <th class="ps-3">Teacher</th><th>Class</th><th>Subject</th><th>Stream</th><th class="text-end pe-3">Action</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($list as $r): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?php echo h($r['full_name']); ?></td>
                            <td><?php echo h($r['cname']); ?></td>
                            <td><?php echo h($r['sname']); ?></td>
                            <td><?php echo $r['stream'] !== '' && $r['stream'] !== null ? '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">' . h($r['stream']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                            <td class="text-end pe-3">
                                <form method="post" class="m-0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="teacher_id" value="<?php echo $tid; ?>">
                                    <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Remove this assignment?')"><i class="bi bi-trash me-1"></i>Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?>
                        <tr><td colspan="5">
                            <div class="asg-empty">
                                <i class="bi bi-inbox"></i>
                                <div class="fw-semibold text-dark">No assignments yet.</div>
                                <div class="small">Use the form on the left to assign a teacher to a subject.</div>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php layout_end();