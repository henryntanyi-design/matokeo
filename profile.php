<?php
/** Change password (teachers/admin/super) or PIN (students). Forced after a temporary credential is issued. */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';
$u = user(); $s = student();
if (!$u && !$s) redirect('login.php');
$forced = $u ? !empty($u['must_change']) : !empty($s['must_change']);
$minLen = $u ? 8 : 4;
if (is_post()) {
    csrf_check();
    $old = (string)($_POST['old'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
    $hash = $u ? $u['password_hash'] : $s['pin_hash'];
    $min = $minLen;
    if (!$hash || !password_verify($old, $hash)) flash('danger', $u ? 'Your current password is incorrect.' : 'Your current PIN is incorrect.');
    elseif (strlen($new) < $min) flash('danger', ($u ? 'The new password' : 'The new PIN') . " must be at least $min characters.");
    elseif ($new !== $new2) flash('danger', 'The new entries do not match.');
    elseif ($new === $old) flash('danger', 'The new one must be different from the current one.');
    else {
        if ($u) q('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        else q('UPDATE students SET pin_hash = ?, must_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $s['id']]);
        flash('success', $u ? 'Your password has been changed successfully.' : 'Your PIN has been changed successfully.');
        redirect($forced ? home_url() : 'profile.php');
    }
    redirect('profile.php' . ($forced ? '?force=1' : ''));
}
if ($u) {
    $displayName = (string)$u['full_name'];
    $handle = (string)$u['username'];
    $roleText = user_title($u);
    $schoolRow = active_school();
    $schoolText = $schoolRow ? (string)$schoolRow['name'] : ($u['role'] === 'super' ? 'District-wide (all schools)' : 'Not assigned');
    $meta = [
        ['bi-person', 'Username', $handle],
        ['bi-award', 'Role', $roleText],
    ];
    if (trim((string)($u['phone'] ?? '')) !== '') $meta[] = ['bi-telephone', 'Phone', (string)$u['phone']];
    if (trim((string)($u['email'] ?? '')) !== '') $meta[] = ['bi-envelope', 'Email', (string)$u['email']];
    $meta[] = ['bi-building', 'School', $schoolText];
} else {
    $displayName = trim($s['first_name'] . ' ' . (string)($s['middle_name'] ?? '') . ' ' . $s['last_name']);
    $handle = (string)$s['prems_no'];
    $roleText = 'Student';
    $schoolRow = school_by_id($s['school_id']);
    $schoolText = $schoolRow ? (string)$schoolRow['name'] : 'Not assigned';
    $meta = [
        ['bi-upc-scan', 'PREMS No', $handle],
        ['bi-journal-bookmark', 'Class', (string)$s['class_name']],
        ['bi-gender-ambiguous', 'Sex', (string)$s['sex']],
        ['bi-building', 'School', $schoolText],
    ];
}
$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($displayName)), 0, 2) as $w) $initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
if ($initials === '') $initials = '?';
$shown = function ($v) { $v = trim((string)$v); return $v !== '' ? $v : '—'; };
$credLabel = $u ? 'password' : 'PIN';
layout_start('My Account', 'profile'); ?>

<style>
.acc-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.acc-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.acc-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.acc-hero .inner{position:relative;z-index:2;min-width:0}
.acc-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.acc-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.acc-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:620px;line-height:1.45}
.acc-hero-id{position:relative;z-index:2;display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:12px;padding:8px 13px}
.acc-hero-id .av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:.72rem;font-weight:800;background:#fff;color:#0b3d91;flex:0 0 auto}
.acc-hero-id span{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.06em;font-weight:800;opacity:.75}
.acc-hero-id b{font-size:.84rem;line-height:1.15;display:block;max-width:230px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.acc-forced{display:flex;gap:12px;background:#fff8e6;border:1px solid #ffd98a;border-left:4px solid #d39b1b;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.acc-forced .ic{width:38px;height:38px;border-radius:10px;background:#fdf1dc;color:#b06a00;display:grid;place-items:center;font-size:1.15rem;flex:0 0 auto}
.acc-forced .t{font-weight:800;color:#7a5300;margin-bottom:2px}
.acc-forced p{margin:0;font-size:.8rem;color:#7a5300;line-height:1.5}
.acc-forced ol{margin:8px 0 0;padding-left:1.1rem;font-size:.78rem;color:#7a5300;line-height:1.55}
.acc-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.acc-card-h{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #eef2fa}
.acc-card-h .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:1.02rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.acc-card-h .t{font-weight:800;color:#16203a;line-height:1.2}
.acc-card-h .s{font-size:.7rem;color:#8a99b0;margin-top:1px}
.acc-card-h .badge{margin-left:auto;font-size:.64rem;font-weight:800;background:#fff8e6!important;color:#8a5a00!important;border:1px solid #ffe1a3;border-radius:999px}
.acc-card-b{padding:16px}
.acc-profile{text-align:center;padding:20px 16px 4px}
.acc-av{width:74px;height:74px;border-radius:50%;margin:0 auto 10px;display:grid;place-items:center;font-size:1.3rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 6px 16px rgba(11,61,145,.26);letter-spacing:.02em}
.acc-name{font-weight:800;color:#16203a;font-size:1.02rem;line-height:1.25;word-break:break-word}
.acc-role{display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:.68rem;font-weight:800;border-radius:999px;padding:2px 10px;background:#eef3fd;color:#0b3d91;border:1px solid #d9e6fb}
.acc-meta{list-style:none;margin:14px 0 0;padding:0;border-top:1px solid #f0f4fb}
.acc-meta li{display:flex;align-items:flex-start;gap:9px;padding:9px 2px;border-bottom:1px solid #f4f7fc;font-size:.79rem}
.acc-meta li:last-child{border-bottom:0}
.acc-meta i{width:16px;text-align:center;color:#8a99b0;margin-top:2px;flex:0 0 auto}
.acc-meta .k{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#8a99b0;font-weight:800}
.acc-meta .v{color:#33415c;font-weight:600;word-break:break-word}
.acc-active{display:inline-flex;align-items:center;gap:4px;font-size:.63rem;font-weight:800;color:#0b8a3e;background:#e8f7ee;border:1px solid #c6ecd6;border-radius:999px;padding:1px 8px;margin-top:10px}
.acc-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:10px 11px;font-size:.73rem;color:#3f5a80;line-height:1.45;margin-top:14px}
.acc-tip i{color:#2c6ed5;font-size:.9rem;flex:0 0 auto;margin-top:1px}
.acc-field{margin-bottom:14px}
.acc-field .form-label{font-size:.74rem;font-weight:800;color:#33415c;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.3rem}
.acc-field .form-control{border-color:#dfe7f5;font-size:.9rem;padding:.52rem .72rem;border-radius:9px}
.acc-field .form-control:focus{border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.acc-field .input-group-text{background:#f4f7fe;border-color:#dfe7f5;color:#5b7bb5;border-radius:9px 0 0 9px}
.acc-field .input-group .form-control{border-radius:0}
.acc-field .acc-eye{border-color:#dfe7f5;color:#5b7bb5;border-radius:0 9px 9px 0;background:#f8faff}
.acc-field .acc-eye:hover{background:#eef4ff;color:#0b3d91}
.acc-hint{font-size:.7rem;color:#8a99b0;margin-top:.3rem}
.acc-req{list-style:none;margin:0 0 14px;padding:0;display:flex;flex-wrap:wrap;gap:6px 14px}
.acc-req li{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#8a99b0}
.acc-req li i{font-size:.7rem;color:#c3cfe4}
.acc-req li.ok{color:#0b8a3e}
.acc-req li.ok i{color:#20b15b}
.acc-meter{display:flex;align-items:center;gap:8px;margin:-4px 0 14px}
.acc-meter .bar{flex:1 1 auto;height:7px;border-radius:999px;background:#eef2fa;overflow:hidden}
.acc-meter .bar span{display:block;height:100%;width:0;border-radius:999px;background:#e05a5a;transition:width .2s,background .2s}
.acc-meter .lbl{font-size:.66rem;font-weight:800;color:#8a99b0;min-width:74px;text-align:right}
.acc-meter.w .bar span{background:#e05a5a}
.acc-meter.m .bar span{background:#e0a020}
.acc-meter.s .bar span{background:#20b15b}
.acc-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:4px}
.acc-actions .btn{border-radius:9px;font-weight:700;padding:.5rem .95rem}
.acc-actions .btn-primary{flex:1 1 auto}
.acc-note{font-size:.7rem;color:#8a99b0;margin-top:10px;display:flex;gap:5px;align-items:flex-start;line-height:1.4}
.acc-note i{color:#a8b5c9;margin-top:1px}
@media(max-width:575px){.acc-hero{padding:18px}.acc-hero h1{font-size:1.32rem}.acc-hero-id{width:100%}.acc-hero-id b{max-width:none}}
@media print{.acc-hero,.acc-forced,.acc-actions,.acc-tip,.acc-note{display:none!important}.acc-card{box-shadow:none}}
</style>

<div class="acc-hero">
  <div class="inner">
    <div class="kicker"><i class="bi bi-shield-lock"></i> Account security</div>
    <h1>My Account</h1>
    <p><?php echo $u
        ? 'Review your account details and change the password used to sign in to the system.'
        : 'Review your account details and change the PIN used to check your results.'; ?></p>
  </div>
  <div class="acc-hero-id">
    <span class="av"><?php echo h($initials); ?></span>
    <div><span>Signed in as</span><b><?php echo h($displayName); ?></b></div>
  </div>
</div>

<?php if ($forced): ?>
<div class="acc-forced">
  <div class="ic"><i class="bi bi-shield-exclamation"></i></div>
  <div>
    <div class="t">Action required: temporary <?php echo $credLabel; ?></div>
    <p>You were given a temporary <?php echo $credLabel; ?>. Change it now to secure your account.</p>
    <ol>
      <li>Enter the temporary <?php echo $credLabel; ?> in the <b>Current <?php echo $credLabel; ?></b> field.</li>
      <li>Choose a new <?php echo $credLabel; ?> (at least <?php echo $minLen; ?> characters) and repeat it.</li>
      <li>After saving you will go straight to your dashboard.</li>
    </ol>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="acc-card h-100">
      <div class="acc-card-h">
        <span class="ico"><i class="bi bi-person-badge"></i></span>
        <div><div class="t">Account details</div><div class="s">Your profile information</div></div>
      </div>
      <div class="acc-card-b">
        <div class="acc-profile">
          <div class="acc-av"><?php echo h($initials); ?></div>
          <div class="acc-name"><?php echo h($displayName); ?></div>
          <div class="acc-role"><i class="bi bi-patch-check-fill"></i><?php echo h($roleText); ?></div>
          <div><span class="acc-active"><i class="bi bi-check-circle-fill"></i>Active account</span></div>
        </div>
        <ul class="acc-meta">
          <?php foreach ($meta as $m): ?>
          <li><i class="bi <?php echo $m[0]; ?>"></i><span><span class="k"><?php echo h($m[1]); ?></span><span class="v"><?php echo h($shown($m[2])); ?></span></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="acc-tip"><i class="bi bi-lightbulb"></i><span>Never share your <?php echo $credLabel; ?> with anyone. The school or district will never ask for it by phone or message.</span></div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="acc-card h-100">
      <div class="acc-card-h">
        <span class="ico"><i class="bi bi-key"></i></span>
        <div>
          <div class="t"><?php echo $forced ? 'Set a new ' . $credLabel : 'Change your ' . $credLabel; ?></div>
          <div class="s"><?php echo $u ? 'Use at least ' . $minLen . ' characters' : 'Use at least ' . $minLen . ' characters (PIN)'; ?></div>
        </div>
        <?php if ($forced): ?><span class="badge">Required now</span><?php endif; ?>
      </div>
      <div class="acc-card-b">
        <form method="post" autocomplete="off">
          <?php echo csrf_field(); ?>
          <div class="acc-field">
            <label class="form-label" for="accOld">Current <?php echo $credLabel; ?><?php echo $forced ? ' (temporary)' : ''; ?></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input id="accOld" type="password" name="old" class="form-control" required autocomplete="current-password" <?php echo $forced ? 'autofocus' : ''; ?>>
              <button class="btn acc-eye" type="button" data-for="accOld" aria-label="Show <?php echo $credLabel; ?>"><i class="bi bi-eye"></i></button>
            </div>
          </div>
          <div class="acc-field">
            <label class="form-label" for="accNew">New <?php echo $credLabel; ?></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
              <input id="accNew" type="password" name="new" class="form-control" required minlength="<?php echo $minLen; ?>" autocomplete="new-password">
              <button class="btn acc-eye" type="button" data-for="accNew" aria-label="Show new <?php echo $credLabel; ?>"><i class="bi bi-eye"></i></button>
            </div>
            <div class="acc-hint">At least <?php echo $minLen; ?> characters. Avoid names, dates or simple patterns.</div>
          </div>
          <div class="acc-meter" id="accMeter">
            <div class="bar"><span id="accBar"></span></div>
            <div class="lbl" id="accBarLbl">&mdash;</div>
          </div>
          <div class="acc-field">
            <label class="form-label" for="accNew2">Repeat new <?php echo $credLabel; ?></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-check2-square"></i></span>
              <input id="accNew2" type="password" name="new2" class="form-control" required autocomplete="new-password">
              <button class="btn acc-eye" type="button" data-for="accNew2" aria-label="Show repeated <?php echo $credLabel; ?>"><i class="bi bi-eye"></i></button>
            </div>
          </div>
          <ul class="acc-req" id="accReq">
            <li data-req="len"><i class="bi bi-circle"></i><span>At least <?php echo $minLen; ?> characters</span></li>
            <li data-req="diff"><i class="bi bi-circle"></i><span>Different from the current one</span></li>
            <li data-req="match"><i class="bi bi-circle"></i><span>Both new entries match</span></li>
          </ul>
          <div class="acc-actions">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo $forced ? 'Save and continue' : 'Save changes'; ?></button>
            <?php if (!$forced): ?><a class="btn btn-outline-secondary" href="<?php echo h(home_url()); ?>"><i class="bi bi-arrow-left me-1"></i>Back to dashboard</a><?php endif; ?>
          </div>
          <div class="acc-note"><i class="bi bi-info-circle"></i><span><?php echo $forced ? 'You must change the temporary ' . $credLabel . ' before you can continue using the system.' : 'After changing your ' . $credLabel . ' you will stay signed in on this device.'; ?></span></div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var minLen = <?php echo (int)$minLen; ?>;
  var oldEl = document.getElementById('accOld');
  var newEl = document.getElementById('accNew');
  var new2El = document.getElementById('accNew2');
  var bar = document.getElementById('accBar');
  var lbl = document.getElementById('accBarLbl');
  var meter = document.getElementById('accMeter');
  var req = {
    len: document.querySelector('#accReq [data-req="len"]'),
    diff: document.querySelector('#accReq [data-req="diff"]'),
    match: document.querySelector('#accReq [data-req="match"]')
  };

  Array.prototype.forEach.call(document.querySelectorAll('.acc-eye'), function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-for'));
      if (!target) return;
      var show = target.type === 'password';
      target.type = show ? 'text' : 'password';
      btn.innerHTML = '<i class="bi bi-' + (show ? 'eye-slash' : 'eye') + '"></i>';
      btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + '<?php echo $credLabel; ?>');
    });
  });

  function mark(el, ok, empty) {
    if (!el) return;
    el.classList.toggle('ok', !!ok && !empty);
    el.querySelector('i').className = 'bi bi-' + (ok && !empty ? 'check-circle-fill' : 'circle');
  }
  function refresh() {
    var v = newEl.value;
    var v2 = new2El.value;
    var old = oldEl.value;
    var empty = v === '';
    mark(req.len, v.length >= minLen, empty);
    mark(req.diff, v !== '' && v !== old, empty);
    mark(req.match, v !== '' && v === v2, v === '');

    var score = 0;
    if (v.length >= minLen) score += 30;
    if (v.length >= minLen + 4) score += 20;
    if (/\d/.test(v)) score += 15;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score += 15;
    if (/[^A-Za-z0-9]/.test(v)) score += 20;
    if (v.length >= minLen + 8) score += 10;
    score = Math.min(100, score);
    if (bar) bar.style.width = (empty ? 0 : Math.max(8, score)) + '%';
    if (lbl) {
      lbl.textContent = empty ? '\u2014' : (score < 40 ? 'Weak' : score < 70 ? 'Medium' : score < 90 ? 'Strong' : 'Very strong');
    }
    if (meter) {
      meter.classList.remove('w', 'm', 's');
      if (!empty) meter.classList.add(score < 40 ? 'w' : score < 70 ? 'm' : 's');
    }
  }
  [oldEl, newEl, new2El].forEach(function (el) { if (el) el.addEventListener('input', refresh); });
  refresh();
})();
</script>
<?php layout_end(); ?>
