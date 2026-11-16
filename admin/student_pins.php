<?php
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();
$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$w = "s.status = 'active' AND s.school_id = ?"; $p = [$school_id];
if ($class_id) { $w .= ' AND s.class_id = ?'; $p[] = $class_id; }
$students = q_all("SELECT s.*, c.name AS class_name, (s.pin_hash IS NOT NULL) AS has_pin FROM students s JOIN classes c ON c.id = s.class_id WHERE $w ORDER BY c.sort_order, s.last_name, s.first_name", $p);
if (is_post()) {
    csrf_check();
    $mode = ($_POST['mode'] ?? 'missing') === 'all' ? 'all' : 'missing';
    $rows = [['PREMS_NO', 'NAME', 'CLASS', 'PIN']];
    db()->beginTransaction();
    foreach ($students as $s) {
        if ($mode !== 'all' && $s['has_pin']) continue;
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        q('UPDATE students SET pin_hash = ?, pin_failed = 0, pin_locked_until = NULL WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $s['id']]);
        $rows[] = [$s['prems_no'] ?: $s['admission_no'], strtoupper(full_name($s)), $s['class_name'], $pin];
    }
    db()->commit();
    if (count($rows) === 1) { flash('info', 'No students needed a PIN.'); redirect('admin/student_pins.php?class_id=' . $class_id); }
    send_rows_xlsx('student_pins', $rows, 'PINs');
}
$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
$with = 0; foreach ($students as $s) if ($s['has_pin']) $with++;
$missing = count($students) - $with;
layout_start('Student PINs', 'students'); ?>

<style>
.pin-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95)}
.pin-hero::before,.pin-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.pin-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.pin-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.pin-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.pin-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.pin-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.pin-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.pin-hero h1 span{color:#ffd764}
.pin-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.pin-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.pin-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.pin-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.pin-btn:hover{transform:translateY(-1px)}
.pin-btn.solid{background:#fff;color:#0b3d91}
.pin-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.pin-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.pin-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.pin-stat{display:flex;align-items:center;gap:13px;height:100%;padding:14px 16px;border-radius:16px;
  background:#fff;border:1px solid #e6ecf8;text-decoration:none;color:inherit;
  box-shadow:0 2px 12px rgba(11,61,145,.05);
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.pin-stat:hover{transform:translateY(-3px);border-color:#cfddf5;color:inherit;
  box-shadow:0 18px 30px -22px rgba(11,61,145,.85)}
.pin-stat-ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;
  font-size:1.15rem;flex:none;box-shadow:0 10px 18px -12px rgba(11,61,145,.9)}
.pin-stat-l{font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8b9ab1}
.pin-stat-v{font-size:1.5rem;font-weight:800;line-height:1.15;color:#16203a}
.pin-stat-s{font-size:.7rem;color:#a3b0c4}

.pin-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.pin-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.pin-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.pin-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.pin-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.pin-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}

.pin-note{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:11px 14px;border-radius:14px;
  background:linear-gradient(90deg,#fff8e6,#fffdf5);border:1px solid #f6e3b4;color:#7a5a00;font-size:.82rem}
.pin-note .pin-note-ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
  background:#ffd764;color:#7a5a00;flex:none}

.pin-select{border:1px solid #d8e2f5;border-radius:10px;padding:.45rem .7rem;font-size:.85rem;background:#fff}
.pin-select:focus{border-color:#2c6ed5;box-shadow:0 0 0 3px rgba(44,110,213,.12);outline:none}

@media (max-width:575.98px){
  .pin-hero{padding:18px;border-radius:18px}
  .pin-hero h1{font-size:1.2rem}
  .pin-date{margin-left:0}
}
</style>

<div class="pin-hero mb-4">
    <div class="pin-head">
        <span class="pin-mark"><i class="bi bi-key-fill"></i></span>
        <div>
            <div class="pin-kicker">Student portal</div>
            <h1>Student <span>PINs</span></h1>
            <p class="pin-sub">
                <?php echo h($sc['name'] ?? school_label()); ?>
                <?php if ($class_id && $classes): ?>
                    &middot; filtered by class
                <?php endif; ?>
            </p>
        </div>
        <span class="pin-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="pin-actions">
        <a class="pin-btn solid" href="<?php echo h(url('admin/student_import.php')); ?>"><i class="bi bi-upload"></i> Import students</a>
        <a class="pin-btn ghost" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-people"></i> Student list</a>
        <a class="pin-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="pin-stat">
            <span class="pin-stat-ic" style="background:linear-gradient(135deg,#2c6ed5,#0b3d91)"><i class="bi bi-people-fill"></i></span>
            <span class="d-block">
                <span class="d-block pin-stat-l">Active students</span>
                <span class="d-block pin-stat-v"><?php echo count($students); ?></span>
                <span class="d-block pin-stat-s"><?php echo $class_id ? 'In selected class' : 'All classes'; ?></span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="pin-stat">
            <span class="pin-stat-ic" style="background:linear-gradient(135deg,#12b886,#0b7a5b)"><i class="bi bi-shield-check"></i></span>
            <span class="d-block">
                <span class="d-block pin-stat-l">With PIN</span>
                <span class="d-block pin-stat-v"><?php echo $with; ?></span>
                <span class="d-block pin-stat-s">Ready to sign in</span>
            </span>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="pin-stat">
            <span class="pin-stat-ic" style="background:linear-gradient(135deg,#f59f00,#b06a00)"><i class="bi bi-key"></i></span>
            <span class="d-block">
                <span class="d-block pin-stat-l">Without PIN</span>
                <span class="d-block pin-stat-v"><?php echo $missing; ?></span>
                <span class="d-block pin-stat-s">Need a PIN</span>
            </span>
        </div>
    </div>
</div>

<?php if ($missing > 0 && count($students) > 0): ?>
<div class="pin-note mb-4">
    <span class="pin-note-ic"><i class="bi bi-exclamation-triangle-fill"></i></span>
    <span><b><?php echo $missing; ?></b> student<?php echo $missing === 1 ? '' : 's'; ?> still have no portal PIN. Generate PINs so they can access their results.</span>
    <a class="btn btn-sm btn-warning ms-auto fw-semibold" href="#generate"><i class="bi bi-shield-lock me-1"></i> Generate</a>
</div>
<?php endif; ?>

<div class="pin-card mb-4" id="generate">
    <div class="pin-card-h">
        <span class="pin-card-t"><i class="bi bi-shield-lock text-primary"></i> Generate and reset PINs</span>
        <span class="pin-pill">One-time download</span>
    </div>
    <div class="card-body p-4">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-muted mb-1">Class filter</label>
                <select name="class_id" class="form-select form-select-sm pin-select w-100" onchange="this.form.submit()">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo h($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <span class="small text-muted">Showing <b><?php echo count($students); ?></b> active student<?php echo count($students) === 1 ? '' : 's'; ?></span>
            </div>
        </form>

        <p class="small text-muted mb-3">
            Students sign in with their candidate number / index number plus their PIN. PINs are stored as hashes;
            the actual list is downloaded <b>once</b> as an Excel file. Distribute it confidentially.
        </p>

        <?php if (count($students) === 0): ?>
            <div class="pin-empty">
                <i class="bi bi-people"></i>
                <div class="fw-semibold text-dark">No active students found</div>
                <div class="small">Import students first, then generate PINs.</div>
                <a class="btn btn-primary btn-sm mt-3" href="<?php echo h(url('admin/student_import.php')); ?>"><i class="bi bi-upload me-1"></i> Import students</a>
            </div>
        <?php else: ?>
            <form method="post" class="d-flex flex-wrap gap-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <button name="mode" value="missing" class="btn btn-primary">
                    <i class="bi bi-shield-plus me-1"></i> Generate for those without (<?php echo $missing; ?>)
                </button>
                <button name="mode" value="all" class="btn btn-outline-danger"
                        onclick="return confirm('This will reset PINs for all listed students. Continue?')">
                    <i class="bi bi-arrow-repeat me-1"></i> Reset all
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php layout_end();