<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sc = $id ? school_by_id($id) : null;
if ($id && !$sc) redirect('super/schools.php');
if (is_post()) {
    csrf_check();
    $f = [];
    foreach (['name', 'centre_no', 'region', 'district', 'ward', 'phone', 'motto'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    $f['centre_no'] = strtoupper($f['centre_no']) ?: null;
    $an = trim($_POST['admin_username'] ?? ''); $af = trim($_POST['admin_name'] ?? ''); $ap = (string)($_POST['admin_password'] ?? '');
    $wantAdmin = !$id && ($an !== '' || $af !== '' || $ap !== '');
    if ($f['name'] === '') flash('danger', 'School name is required.');
    elseif ($wantAdmin && (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $an) || $af === '' || strlen($ap) < 8)) flash('danger', 'School admin: name, username (3-40 letters/numbers) and password (min 8) are all required.');
    else {
        try {
            db()->beginTransaction();
            if ($id) q('UPDATE schools SET name=?, centre_no=?, region=?, district=?, ward=?, phone=?, motto=? WHERE id=?', [$f['name'], $f['centre_no'], $f['region'], $f['district'], $f['ward'], $f['phone'], $f['motto'], $id]);
            else {
                q('INSERT INTO schools (name, centre_no, region, district, ward, phone, motto) VALUES (?,?,?,?,?,?,?)', [$f['name'], $f['centre_no'], $f['region'], $f['district'], $f['ward'], $f['phone'], $f['motto']]);
                $id = (int)db()->lastInsertId();
                if ($wantAdmin) q("INSERT INTO users (username, password_hash, full_name, role, school_id) VALUES (?, ?, ?, 'admin', ?)", [$an, password_hash($ap, PASSWORD_DEFAULT), $af, $id]);
            }
            db()->commit();
            flash('success', 'School saved.' . ($wantAdmin ? ' School admin account created.' : ''));
            redirect('super/schools.php');
        } catch (PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $e->getCode() == 23000 ? 'School name, centre number or username is already in use.' : h($e->getMessage()));
            $sc = array_merge($sc ?: [], $f);
        }
    }
    if (!$sc) $sc = $f;
}
$v = function ($k) use ($sc) { return h($sc[$k] ?? ''); };
layout_start($id ? 'Edit school' : 'New school', 'schools'); ?>
<style>
.sf-head{display:flex;align-items:center;gap:14px;margin-bottom:18px}
.sf-face{flex:0 0 52px;width:52px;height:52px;border-radius:15px;color:#fff;display:flex;align-items:center;justify-content:center;
  font-size:1.35rem;background:linear-gradient(135deg,#2c6ed5,#0b3d91);box-shadow:0 12px 24px -12px rgba(11,61,145,.7)}
.sf-title{font-size:1.15rem;font-weight:800;color:#0b3d91;line-height:1.2;margin:0}
.sf-sub{font-size:.8rem;color:#6b7a90;margin:3px 0 0}
.sf-card{position:relative;background:#fff;border:1px solid #e7ecf7;border-radius:18px;overflow:hidden;
  box-shadow:0 18px 40px -30px rgba(11,61,145,.55)}
.sf-card:before{content:"";position:absolute;inset:0 0 auto;height:4px;z-index:2;
  background:linear-gradient(90deg,#0b3d91,#2c6ed5 55%,#0f9d6b)}
.sf-sec{padding:18px 20px 4px}
.sf-sec+.sf-sec{border-top:1px dashed #e7ecf7}
.sf-sec-h{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.sf-sec-ic{flex:0 0 32px;width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:.92rem;color:#0b3d91;background:#eef3fb;border:1px solid #e2e9f6}
.sf-sec-t{font-size:.8rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#33415c;margin:0}
.sf-sec-p{font-size:.75rem;color:#6b7a90;margin:1px 0 0}
.sf-label{font-size:.76rem;font-weight:700;color:#33415c;margin-bottom:5px;display:block}
.sf-label .req{color:#c92a2a;margin-left:2px}
.sf-label .opt{font-weight:600;color:#8a99b0;text-transform:none;letter-spacing:0}
.sf-in .input-group-text{background:#f5f8fe;border-color:#e2e9f6;color:#7b8dad;padding:.5rem .7rem;border-right:0;border-radius:10px 0 0 10px}
.sf-in .form-control{border-color:#e2e9f6;border-radius:0 10px 10px 0;padding:.6rem .8rem;font-size:.88rem;background:#fdfefe}
.sf-in .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 4px rgba(44,110,213,.12)}
.sf-in .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:#2c6ed5}
.sf-in .ig-tail{border-left:0;border-right:1px solid #e2e9f6;border-radius:0 10px 10px 0;padding:0}
.sf-eye{border:0;background:transparent;color:#8a99b0;width:40px;height:100%;display:inline-flex;align-items:center;justify-content:center}
.sf-eye:hover{color:#0b3d91}
.sf-foot{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 20px;background:#fbfcff;border-top:1px solid #eef2fa}
.sf-save{border:0;border-radius:11px;padding:.6rem 1.25rem;font-weight:700;color:#fff;
  background:linear-gradient(135deg,#2c6ed5,#0b3d91);box-shadow:0 10px 22px -10px rgba(11,61,145,.75);
  transition:filter .16s,transform .16s}
.sf-save:hover{filter:brightness(1.07);transform:translateY(-1px);color:#fff}
.sf-cancel{border:1px solid #e2e9f6;background:#fff;color:#5b6b85;border-radius:11px;padding:.6rem 1.1rem;font-weight:600;text-decoration:none;font-size:.88rem}
.sf-cancel:hover{color:#0b3d91;border-color:#b9d0f0;background:#f7faff}
.sf-note{font-size:.74rem;color:#8a99b0;margin-left:auto}
@media(max-width:767px){.sf-head{gap:11px}.sf-face{flex:0 0 44px;width:44px;height:44px;border-radius:13px;font-size:1.1rem}.sf-title{font-size:1rem}.sf-note{display:none}}
</style>
<div class="sf-head">
  <div class="sf-face"><i class="bi bi-<?php echo $id ? 'pencil-square' : 'building-add'; ?>"></i></div>
  <div class="min-w-0">
    <h1 class="sf-title"><?php echo $id ? 'Edit school details' : 'Register a new school'; ?></h1>
    <p class="sf-sub"><?php echo $id ? 'Update the school details, then save your changes.' : 'Fill in the school details so it appears in the system and reports.'; ?></p>
  </div>
</div>
<form method="post">
  <div class="sf-card"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
    <div class="sf-sec">
      <div class="sf-sec-h"><span class="sf-sec-ic"><i class="bi bi-building"></i></span>
        <div><div class="sf-sec-t">School details</div><div class="sf-sec-p">The name and centre number are used on exam reports.</div></div></div>
      <div class="row g-3">
        <div class="col-md-8">
          <label class="sf-label" for="name">School name <span class="req">*</span></label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-building-fill"></i></span>
            <input name="name" id="name" class="form-control" required autofocus placeholder="e.g. MANTARE SECONDARY SCHOOL" value="<?php echo $v('name'); ?>"></div>
        </div>
        <div class="col-md-4">
          <label class="sf-label" for="centre_no">Centre number</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-hash"></i></span>
            <input name="centre_no" id="centre_no" class="form-control" placeholder="S0123" value="<?php echo $v('centre_no'); ?>"></div>
        </div>
      </div>
    </div>

    <div class="sf-sec">
      <div class="sf-sec-h"><span class="sf-sec-ic"><i class="bi bi-geo-alt"></i></span>
        <div><div class="sf-sec-t">Location</div><div class="sf-sec-p">Region, district and ward of the school.</div></div></div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="sf-label" for="region">Region</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-map"></i></span>
            <input name="region" id="region" class="form-control" value="<?php echo $v('region'); ?>"></div>
        </div>
        <div class="col-md-4">
          <label class="sf-label" for="district">District</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-geo"></i></span>
            <input name="district" id="district" class="form-control" value="<?php echo $v('district'); ?>"></div>
        </div>
        <div class="col-md-4">
          <label class="sf-label" for="ward">Ward</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-signpost-split"></i></span>
            <input name="ward" id="ward" class="form-control" value="<?php echo $v('ward'); ?>"></div>
        </div>
      </div>
    </div>

    <div class="sf-sec">
      <div class="sf-sec-h"><span class="sf-sec-ic"><i class="bi bi-chat-square-text"></i></span>
        <div><div class="sf-sec-t">Contact</div><div class="sf-sec-p">Contact details and the school motto.</div></div></div>
      <div class="row g-3 pb-3">
        <div class="col-md-6">
          <label class="sf-label" for="phone">Phone</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-telephone"></i></span>
            <input name="phone" id="phone" class="form-control" placeholder="e.g. 0712 345 678" value="<?php echo $v('phone'); ?>"></div>
        </div>
        <div class="col-md-6">
          <label class="sf-label" for="motto">Motto</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-quote"></i></span>
            <input name="motto" id="motto" class="form-control" value="<?php echo $v('motto'); ?>"></div>
        </div>
      </div>
    </div>

<?php if (!$id): ?>
    <div class="sf-sec">
      <div class="sf-sec-h"><span class="sf-sec-ic"><i class="bi bi-person-badge"></i></span>
        <div><div class="sf-sec-t">School admin <span class="opt">(optional)</span></div>
          <div class="sf-sec-p">You can create this account later under Teachers &amp; Users.</div></div></div>
      <div class="row g-3 pb-3">
        <div class="col-md-4">
          <label class="sf-label" for="admin_name">Full name</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-person"></i></span>
            <input name="admin_name" id="admin_name" class="form-control" value="<?php echo h($_POST['admin_name'] ?? ''); ?>"></div>
        </div>
        <div class="col-md-4">
          <label class="sf-label" for="admin_username">Username</label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-at"></i></span>
            <input name="admin_username" id="admin_username" class="form-control" autocomplete="off" value="<?php echo h($_POST['admin_username'] ?? ''); ?>"></div>
        </div>
        <div class="col-md-4">
          <label class="sf-label" for="admin_password">Password <span class="opt">(min 8)</span></label>
          <div class="input-group sf-in"><span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="admin_password" id="admin_password" class="form-control" autocomplete="new-password">
            <span class="input-group-text ig-tail"><button type="button" class="sf-eye" data-for="admin_password" aria-label="Show password"><i class="bi bi-eye"></i></button></span></div>
        </div>
      </div>
    </div>
<?php endif; ?>

    <div class="sf-foot">
      <button class="sf-save"><i class="bi bi-check2-circle me-1"></i><?php echo $id ? 'Save changes' : 'Save school'; ?></button>
      <a href="schools.php" class="sf-cancel">Cancel</a>
      <span class="sf-note"><span style="color:#c92a2a">*</span> Required fields</span>
    </div>
  </div>
</form>
<script>
document.querySelectorAll('.sf-eye').forEach(function (b) {
  b.addEventListener('click', function () {
    var f = document.getElementById(b.getAttribute('data-for'));
    if (!f) return;
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    b.innerHTML = '<i class="bi bi-' + (show ? 'eye-slash' : 'eye') + '"></i>';
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});
</script>
<?php layout_end();
