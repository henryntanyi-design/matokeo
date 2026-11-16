<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$me = require_district();
$hasRegion = db_column_exists('users', 'region');
$hasDistrict = db_column_exists('users', 'district');
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'system') {
            $n = trim($_POST['system_name'] ?? '');
            if ($n === '') flash('danger', 'System name is required.'); else { set_setting('system_name', $n); flash('success', 'Saved.'); }
        } elseif ($a === 'add_year') {
            $y = trim($_POST['year'] ?? '');
            if ($y === '') flash('danger', 'Enter a year.'); else { q('INSERT INTO academic_years (name, is_current) VALUES (?, 0)', [$y]); flash('success', 'Year added.'); }
        } elseif ($a === 'current_year') {
            db()->beginTransaction(); q('UPDATE academic_years SET is_current = 0'); q('UPDATE academic_years SET is_current = 1 WHERE id = ?', [(int)($_POST['year_id'] ?? 0)]); db()->commit();
            flash('success', 'Current year updated.');
        } elseif ($a === 'reset_super') {
            $t = q_one("SELECT * FROM users WHERE id = ? AND role = 'super'", [(int)($_POST['id'] ?? 0)]);
            if (!$t) flash('danger', 'User not found.');
            elseif ((int)$t['id'] === (int)$me['id']) flash('danger', 'Change your own password through My Account.');
            else {
                $pw = generate_password(surname_of($t['full_name']));
                q('UPDATE users SET password_hash = ?, must_change = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]);
                flash('success', 'Temporary password for <b>' . h($t['full_name']) . '</b>: <code class="fs-6">' . h($pw) . '</code><br><small>It is shown once only; they must change it after signing in.</small>');
            }
        } elseif ($a === 'add_super') {
            $un = trim($_POST['username'] ?? ''); $fn = trim($_POST['full_name'] ?? ''); $pw = (string)($_POST['password'] ?? '');
            $rg = trim($_POST['region'] ?? ''); $ds = trim($_POST['district'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $un) || $fn === '' || strlen($pw) < 8) flash('danger', 'Full name, username (3-40 letters/numbers) and password (min 8) are required.');
            elseif (($hasRegion && $rg === '') || ($hasDistrict && $ds === '')) flash('danger', 'Region and district are required for a district super admin.');
            else {
                $cols = ['username', 'password_hash', 'full_name', 'role'];
                $vals = [$un, password_hash($pw, PASSWORD_DEFAULT), $fn, 'super'];
                if ($hasRegion) { $cols[] = 'region'; $vals[] = $rg; }
                if ($hasDistrict) { $cols[] = 'district'; $vals[] = $ds; }
                q('INSERT INTO users (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
                flash('success', 'New super admin added.');
            }
        }
    } catch (PDOException $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', $e->getCode() == 23000 ? 'Already exists.' : h($e->getMessage())); }
    redirect('super/settings.php');
}
$years = q_all('SELECT * FROM academic_years ORDER BY name DESC');
$superCols = 'id, username, full_name, active' . ($hasRegion ? ', region' : '') . ($hasDistrict ? ', district' : '');
$supers = q_all("SELECT $superCols FROM users WHERE role = 'super' ORDER BY full_name");
$nSchools = (int)q_val('SELECT COUNT(*) FROM schools');
$currentYear = '';
foreach ($years as $y) { if ((int)$y['is_current'] === 1) { $currentYear = $y['name']; break; } }
$initialsOf = function ($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $s = '';
    foreach (array_slice($parts, 0, 2) as $w) $s .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    return $s !== '' ? $s : '?';
};
layout_start('Settings & Years', 'settings'); ?>

<style>
.set-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.set-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.set-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.set-hero .inner{position:relative;z-index:2;min-width:0}
.set-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.set-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.set-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:620px;line-height:1.45}
.set-hero-chip{position:relative;z-index:2;display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:12px;padding:9px 14px;backdrop-filter:blur(2px)}
.set-hero-chip i{font-size:1.15rem;opacity:.9}
.set-hero-chip span{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.06em;font-weight:800;opacity:.75}
.set-hero-chip b{font-size:1.02rem;line-height:1.1}
.set-warn{display:flex;gap:10px;background:#fff8e6;border:1px solid #ffe1a3;color:#7a5300;border-radius:14px;padding:11px 14px;font-size:.8rem;line-height:1.5;margin-bottom:16px}
.set-warn i{font-size:1rem;flex:0 0 auto;margin-top:1px}
.set-warn code{background:#fff;border:1px solid #ffe1a3;border-radius:5px;padding:0 4px;color:#7a5300}
.set-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.set-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.set-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.set-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.set-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.set-stat.s .ico{background:#fdf1dc;color:#b06a00}
.set-stat .v{font-size:1.22rem;font-weight:800;line-height:1.05;color:#16203a;word-break:break-word}
.set-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.set-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.set-card-h{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #eef2fa}
.set-card-h .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:1.02rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.set-card-h .t{font-weight:800;color:#16203a;line-height:1.2}
.set-card-h .s{font-size:.7rem;color:#8a99b0;margin-top:1px}
.set-card-h .badge{margin-left:auto;font-size:.66rem;font-weight:800;background:#eef3fd!important;color:#0b3d91!important;border:1px solid #d9e6fb;border-radius:999px}
.set-card-b{padding:16px}
.set-card-f{padding:12px 16px;border-top:1px solid #eef2fa;background:linear-gradient(180deg,#fbfcff,#fff)}
.set-card .form-label{font-size:.72rem;font-weight:800;color:#33415c;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.28rem}
.set-card .form-control,.set-card .form-select{border-color:#dfe7f5;font-size:.86rem;padding:.46rem .66rem;border-radius:9px}
.set-card .form-control:focus{border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.set-card .form-text{font-size:.71rem;color:#8a99b0}
.set-card .input-group-text{background:#f4f7fe;border-color:#dfe7f5;color:#5b7bb5;font-size:.88rem;border-radius:9px 0 0 9px}
.set-card .input-group .form-control{border-radius:0 9px 9px 0}
.set-card .btn{border-radius:9px;font-weight:700}
.set-pv{border:1px dashed #cfdcf0;background:linear-gradient(180deg,#fbfdff,#f3f8ff);border-radius:12px;padding:11px 13px;margin-bottom:14px}
.set-pv-l{font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;font-weight:800;color:#8a99b0;margin-bottom:8px;display:flex;align-items:center;gap:5px}
.set-pv-l i{color:#2c6ed5}
.set-pv-row{display:flex;align-items:center;gap:10px}
.set-pv-ico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;color:#fff;font-size:1.05rem;background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 3px 8px rgba(11,61,145,.22);flex:0 0 auto}
.set-pv-name{font-weight:800;color:#16203a;font-size:.92rem;line-height:1.25;word-break:break-word}
.set-pv-sub{font-size:.68rem;color:#8a99b0;margin-top:1px}
.set-list{list-style:none;margin:0;padding:0}
.set-year{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid #f0f4fb}
.set-year:last-child{border-bottom:0}
.set-year:hover{background:#fafcff}
.set-year .yico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:.95rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.set-year.cur .yico{background:linear-gradient(135deg,#0b8a3e,#20b15b);color:#fff;box-shadow:0 3px 8px rgba(11,138,62,.22)}
.set-year .yname{font-weight:800;color:#16203a;font-size:.95rem;line-height:1.15}
.set-year .ymeta{font-size:.7rem;color:#8a99b0;margin-top:1px}
.set-year .act{margin-left:auto}
.set-year .act .btn{--bs-btn-padding-y:.24rem;--bs-btn-padding-x:.6rem;--bs-btn-font-size:.73rem;border-radius:8px;font-weight:700}
.set-pill{display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:800;border-radius:999px;padding:2px 9px;border:1px solid;white-space:nowrap}
.set-pill.g{background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.set-pill.m{background:#eef3fd;color:#0b3d91;border-color:#d9e6fb}
.set-admin{display:flex;align-items:flex-start;gap:11px;padding:11px 16px;border-bottom:1px solid #f0f4fb}
.set-admin:last-of-type{border-bottom:0}
.set-admin:hover{background:#fafcff}
.set-av{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:.74rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#0b3d91,#2c6ed5);flex:0 0 auto;letter-spacing:.02em}
.set-admin .nm{font-weight:700;color:#16203a;font-size:.88rem;line-height:1.25;word-break:break-word}
.set-admin .meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:3px;font-size:.7rem;color:#8a99b0}
.set-admin .meta i{color:#a8b5c9}
.set-admin .act{margin-left:auto;flex:0 0 auto}
.set-admin .act .btn{--bs-btn-padding-y:.22rem;--bs-btn-padding-x:.46rem;--bs-btn-font-size:.74rem;border-radius:8px}
.set-sub-h{display:flex;align-items:center;gap:7px;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;font-weight:800;color:#6b7a93;margin:2px 0 10px}
.set-sub-h i{color:#2c6ed5}
.set-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:9px 10px;font-size:.72rem;color:#3f5a80;line-height:1.4;margin-top:12px}
.set-tip i{color:#2c6ed5;font-size:.9rem;flex:0 0 auto;margin-top:1px}
.set-empty{text-align:center;padding:1.6rem 1rem;color:#8a99b0;font-size:.8rem}
.set-empty i{display:block;font-size:1.5rem;color:#c3cfe4;margin-bottom:5px}
@media(max-width:575px){.set-hero{padding:18px}.set-hero h1{font-size:1.32rem}.set-hero-chip{width:100%}.set-card-b{padding:14px}}
@media print{.set-hero,.set-warn,.set-card-f,.set-year .act,.set-admin .act,.set-tip{display:none!important}.set-card{box-shadow:none;break-inside:avoid}}
</style>

<div class="set-hero">
  <div class="inner">
    <div class="kicker"><i class="bi bi-gear"></i> System configuration</div>
    <h1>Settings &amp; Years</h1>
    <p>Manage the system identity, academic years for all schools, and district super admin accounts in one place.</p>
  </div>
  <div class="set-hero-chip">
    <i class="bi bi-calendar-check"></i>
    <div><span>Current academic year</span><b><?php echo $currentYear !== '' ? h($currentYear) : 'Not set'; ?></b></div>
  </div>
</div>

<?php if (!$hasRegion || !$hasDistrict): ?>
<div class="set-warn">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div><b>Region/District columns are not installed.</b> Import <code>database/migration_user_region.sql</code> in phpMyAdmin so that region and district can be saved for super admins.</div>
</div>
<?php endif; ?>

<div class="set-stats">
  <div class="set-stat g"><span class="ico"><i class="bi bi-calendar-check"></i></span><div><div class="v"><?php echo $currentYear !== '' ? h($currentYear) : '&mdash;'; ?></div><div class="l">Current year</div></div></div>
  <div class="set-stat"><span class="ico"><i class="bi bi-calendar3"></i></span><div><div class="v"><?php echo count($years); ?></div><div class="l">Academic years</div></div></div>
  <div class="set-stat a"><span class="ico"><i class="bi bi-shield-lock"></i></span><div><div class="v"><?php echo count($supers); ?></div><div class="l">Super admins</div></div></div>
  <div class="set-stat s"><span class="ico"><i class="bi bi-buildings"></i></span><div><div class="v"><?php echo $nSchools; ?></div><div class="l">Schools</div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="set-card mb-3">
      <div class="set-card-h">
        <span class="ico"><i class="bi bi-badge-tm"></i></span>
        <div><div class="t">System identity</div><div class="s">The name shown on screens and reports</div></div>
      </div>
      <div class="set-card-b">
        <div class="set-pv">
          <div class="set-pv-l"><i class="bi bi-eye"></i> Live preview</div>
          <div class="set-pv-row">
            <span class="set-pv-ico"><i class="bi bi-mortarboard-fill"></i></span>
            <div style="min-width:0">
              <div class="set-pv-name" id="setPvName"><?php echo h(system_name()); ?></div>
              <div class="set-pv-sub">School Results Management</div>
            </div>
          </div>
        </div>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="system">
          <label class="form-label" for="setSystemName">System name</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-type"></i></span>
            <input id="setSystemName" name="system_name" class="form-control" required maxlength="120" value="<?php echo h(system_name()); ?>" placeholder="e.g. Integrated Educational Performance Analysis System">
          </div>
          <div class="form-text">Appears in the sidebar, topbar and printed documents.</div>
          <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="set-card">
      <div class="set-card-h">
        <span class="ico"><i class="bi bi-calendar3"></i></span>
        <div><div class="t">Academic years</div><div class="s">Shared by all schools in the district</div></div>
        <span class="badge"><?php echo count($years); ?> year<?php echo count($years) === 1 ? '' : 's'; ?></span>
      </div>
      <?php if ($years): ?>
      <ul class="set-list">
        <?php foreach ($years as $y): $isCur = (int)$y['is_current'] === 1; ?>
        <li class="set-year<?php echo $isCur ? ' cur' : ''; ?>">
          <span class="yico"><i class="bi bi-<?php echo $isCur ? 'calendar-check' : 'calendar3'; ?>"></i></span>
          <div style="min-width:0">
            <div class="yname"><?php echo h($y['name']); ?></div>
            <div class="ymeta"><?php echo $isCur ? 'Active academic year' : 'Available for selection'; ?></div>
          </div>
          <div class="act">
            <?php if ($isCur): ?>
              <span class="set-pill g"><i class="bi bi-check-circle-fill"></i>Current</span>
            <?php else: ?>
              <form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="action" value="current_year"><input type="hidden" name="year_id" value="<?php echo (int)$y['id']; ?>"><button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Make current</button></form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="set-empty"><i class="bi bi-calendar-x"></i>No academic years yet. Add the first one below.</div>
      <?php endif; ?>
      <div class="set-card-f">
        <form method="post" class="row g-2 align-items-end">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add_year">
          <div class="col-sm-7">
            <label class="form-label" for="setYear">New academic year</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-calendar-plus"></i></span>
              <input id="setYear" name="year" class="form-control" placeholder="e.g. 2027" maxlength="20" required>
            </div>
          </div>
          <div class="col-sm-5">
            <button class="btn btn-outline-success w-100"><i class="bi bi-plus-lg me-1"></i>Add year</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="set-card h-100">
      <div class="set-card-h">
        <span class="ico"><i class="bi bi-shield-lock"></i></span>
        <div><div class="t">Super admins</div><div class="s">District-level access</div></div>
        <span class="badge"><?php echo count($supers); ?></span>
      </div>
      <?php if ($supers): ?>
      <div>
        <?php foreach ($supers as $u): $isMe = (int)$u['id'] === (int)$me['id']; ?>
        <div class="set-admin">
          <span class="set-av"><?php echo h($initialsOf($u['full_name'])); ?></span>
          <div style="min-width:0">
            <div class="nm"><?php echo h($u['full_name']); ?><?php echo $isMe ? ' <span class="text-muted small">(you)</span>' : ''; ?></div>
            <div class="meta">
              <span><i class="bi bi-person"></i><?php echo h($u['username']); ?></span>
              <?php if ($hasRegion && trim((string)($u['region'] ?? '')) !== ''): ?><span><i class="bi bi-geo-alt"></i><?php echo h($u['region']); ?></span><?php endif; ?>
              <?php if ($hasDistrict && trim((string)($u['district'] ?? '')) !== ''): ?><span><i class="bi bi-pin-map"></i><?php echo h($u['district']); ?></span><?php endif; ?>
              <span class="set-pill <?php echo (int)$u['active'] === 1 ? 'g' : 'm'; ?>"><?php echo (int)$u['active'] === 1 ? 'Active' : 'Inactive'; ?></span>
            </div>
          </div>
          <?php if (!$isMe): ?>
          <div class="act">
            <form method="post" class="m-0" onsubmit="return confirm('Set a new temporary password for <?php echo h(addslashes($u['full_name'])); ?>?')">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="reset_super"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
              <button class="btn btn-outline-warning" title="Reset password"><i class="bi bi-key"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="set-empty"><i class="bi bi-person-x"></i>No super admins yet.</div>
      <?php endif; ?>

      <div class="set-card-f">
        <div class="set-sub-h"><i class="bi bi-person-plus"></i>Add super admin</div>
        <form method="post" autocomplete="off">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add_super">
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label" for="setFn">Full name</label>
              <input id="setFn" name="full_name" class="form-control" placeholder="e.g. Asha Mwakyusa" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="setUn">Username</label>
              <input id="setUn" name="username" class="form-control" placeholder="e.g. asha.mwakyusa" pattern="[A-Za-z0-9_.\-]{3,40}" title="3 to 40 letters, numbers, dot, dash or underscore" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="setPw">Password</label>
              <input id="setPw" name="password" type="password" class="form-control" placeholder="Min 8 characters" minlength="8" required autocomplete="new-password">
            </div>
            <?php if ($hasRegion): ?>
            <div class="col-sm-6">
              <label class="form-label" for="setRg">Region</label>
              <input id="setRg" name="region" class="form-control" placeholder="e.g. Kagera" required>
            </div>
            <?php endif; ?>
            <?php if ($hasDistrict): ?>
            <div class="col-sm-6">
              <label class="form-label" for="setDs">District</label>
              <input id="setDs" name="district" class="form-control" placeholder="e.g. Kyerwa" required>
            </div>
            <?php endif; ?>
          </div>
          <button class="btn btn-outline-success w-100 mt-3"><i class="bi bi-person-plus me-1"></i>Add super admin</button>
        </form>
        <div class="set-tip"><i class="bi bi-lightbulb"></i><span>New super admins must change the password after their first sign-in. Only your own password can be changed from <b>My Account</b>.</span></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var input = document.getElementById('setSystemName');
  var pv = document.getElementById('setPvName');
  if (!input || !pv) return;
  input.addEventListener('input', function () {
    pv.textContent = input.value.trim() || 'System name';
  });
})();
</script>
<?php layout_end(); ?>
