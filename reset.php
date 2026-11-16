<?php
/** Kuweka nenosiri jipya kupitia kiungo cha email (token ya mara moja, dakika 60). */
require_once __DIR__ . '/lib/bootstrap.php';
header('Referrer-Policy: no-referrer');
if (user() || student()) redirect(home_url());
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = q_one('SELECT pr.id, pr.user_id, u.full_name FROM password_resets pr JOIN users u ON u.id = pr.user_id
                  WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.active = 1', [hash('sha256', $token)]);
}
$err = '';
if ($row && is_post()) {
    csrf_check();
    $p1 = (string)($_POST['password'] ?? ''); $p2 = (string)($_POST['password2'] ?? '');
    if (strlen($p1) < 8) $err = 'Nenosiri liwe na angalau herufi 8.';
    elseif ($p1 !== $p2) $err = 'Nenosiri mbili hazifanani.';
    else {
        db()->beginTransaction();
        q('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL, must_change = 0 WHERE id = ?', [password_hash($p1, PASSWORD_DEFAULT), $row['user_id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);   // batilisha viungo vingine vyote
        db()->commit();
        flash('success', 'Nenosiri limebadilishwa. Ingia kwa nenosiri jipya.');
        redirect('login.php');
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">
<title>New password - <?php echo h(system_name()); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--brand:#0b3d91;--brand2:#2c6ed5;--brand3:#0f9d6b;--ink:#16203a;--mut:#6b7a90;--line:#e2e9f6}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 16px;
  font-family:"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);
  background:
    radial-gradient(900px 520px at 6% -10%,rgba(44,110,213,.22),transparent 60%),
    radial-gradient(760px 480px at 104% 108%,rgba(15,157,107,.20),transparent 58%),
    linear-gradient(160deg,#f5f8ff 0%,#eef3fb 55%,#e8f6f0 100%);}
body:before{content:"";position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:radial-gradient(rgba(11,61,145,.07) 1px,transparent 1px);background-size:22px 22px;
  -webkit-mask-image:linear-gradient(to bottom,rgba(0,0,0,.6),transparent 72%);mask-image:linear-gradient(to bottom,rgba(0,0,0,.6),transparent 72%)}
.lg-wrap{position:relative;width:100%;max-width:940px;animation:lgIn .55s cubic-bezier(.22,.9,.3,1) both}
@keyframes lgIn{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
.lg-card{position:relative;display:flex;flex-wrap:wrap;overflow:hidden;background:#fff;border-radius:24px;
  box-shadow:0 30px 70px -22px rgba(11,61,145,.35),0 2px 8px rgba(11,61,145,.06);border:1px solid rgba(11,61,145,.07)}
.lg-card:before{content:"";position:absolute;inset:0 0 auto;height:4px;z-index:3;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 45%,var(--brand3))}
.lg-brand{flex:1 1 320px;min-width:280px;padding:40px 34px;color:#fff;position:relative;overflow:hidden;
  background:linear-gradient(155deg,#0a3a89 0%,#14479e 42%,#2c6ed5 100%);display:flex;flex-direction:column;justify-content:space-between;gap:22px}
.lg-brand:before{content:"";position:absolute;right:-30%;top:-40%;width:420px;height:420px;border-radius:50%;
  background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.20),transparent 62%);animation:lgFloat 14s ease-in-out infinite}
.lg-brand:after{content:"";position:absolute;left:-90px;bottom:-110px;width:300px;height:300px;border-radius:50%;
  background:rgba(15,157,107,.35);filter:blur(8px);animation:lgFloat 18s ease-in-out infinite reverse}
@keyframes lgFloat{0%,100%{transform:translate3d(0,0,0)}50%{transform:translate3d(-14px,16px,0)}}
.lg-brand .z{position:relative;z-index:1;display:flex;flex-direction:column;gap:14px}
.lg-logo{height:62px;width:auto;max-width:180px;object-fit:contain;background:#fff;border-radius:16px;padding:7px;box-shadow:0 10px 24px rgba(0,0,0,.22)}
.lg-title{font-size:1.5rem;font-weight:800;line-height:1.22;letter-spacing:-.01em}
.lg-sub{font-size:.85rem;opacity:.9;line-height:1.6;max-width:34ch}
.lg-steps{list-style:none;padding:0;margin:4px 0 0;font-size:.83rem;display:grid;gap:12px}
.lg-steps li{display:flex;align-items:flex-start;gap:10px}
.lg-steps i{flex:0 0 27px;height:27px;border-radius:9px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.16);display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-style:normal;font-weight:800}
.lg-note{font-size:.72rem;color:rgba(255,255,255,.72);letter-spacing:.02em}
.lg-form{flex:1 1 400px;padding:40px 38px 28px;position:relative;z-index:1}
.lg-badge{display:inline-flex;align-items:center;gap:6px;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:#1b7f4b;background:#eaf7f0;border:1px solid #cfeee0;border-radius:999px;padding:4px 10px;margin-bottom:14px}
.lg-h{font-size:1.35rem;font-weight:800;color:var(--brand);letter-spacing:-.01em;margin:0 0 6px}
.lg-p{font-size:.85rem;color:var(--mut);line-height:1.6;margin:0 0 20px}
.form-label{font-size:.78rem;font-weight:700;color:#33415c;margin-bottom:5px}
.input-group-text{background:#f5f8fe;color:var(--brand);border-color:var(--line);padding:.5rem .7rem}
.ig-lead{border-right:0;border-radius:11px 0 0 11px}
.ig-tail{border-left:0;border-radius:0 11px 11px 0;padding:0}
.lg-form .form-control{border-color:var(--line);border-radius:11px;padding:.64rem .85rem;font-size:.9rem;background:#fdfefe}
.lg-form .ig-lead+.form-control{border-radius:0 11px 11px 0}
.lg-form .form-control.ig-in{border-radius:0}
.lg-form .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 4px rgba(44,110,213,.13)}
.lg-form .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:var(--brand2)}
.lg-eye{border:0;background:transparent;color:#8a99b0;width:42px;height:100%;border-radius:0 11px 11px 0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.95rem}
.lg-eye:hover{color:var(--brand)}
.lg-strength{display:flex;gap:5px;margin-top:8px}
.lg-strength span{height:5px;flex:1;border-radius:99px;background:#e8eef9;transition:background .2s}
.lg-strength.s1 span:nth-child(1){background:#e06666}
.lg-strength.s2 span:nth-child(-n+2){background:#e0a13a}
.lg-strength.s3 span:nth-child(-n+3){background:#2c9bd5}
.lg-strength.s4 span{background:#0f9d6b}
.lg-sl{font-size:.72rem;color:var(--mut);margin-top:5px;min-height:1em}
.lg-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));border:0;border-radius:12px;padding:.74rem;font-weight:700;color:#fff;
  letter-spacing:.01em;box-shadow:0 12px 26px -8px rgba(11,61,145,.55);transition:transform .16s,box-shadow .16s,filter .16s}
.lg-btn:hover{filter:brightness(1.07);transform:translateY(-1px);box-shadow:0 16px 30px -8px rgba(11,61,145,.6);color:#fff}
.lg-btn:active{transform:none}
.lg-btn:disabled{opacity:.75;transform:none}
.lg-hint{display:flex;gap:9px;font-size:.78rem;color:#5b6b85;background:#f6f9fe;border:1px solid var(--line);border-radius:12px;padding:11px 13px;margin-top:18px;line-height:1.55}
.lg-hint i{color:var(--brand2);font-size:.95rem;margin-top:1px}
.lg-rules{list-style:none;padding:0;margin:10px 0 0;display:grid;gap:6px;font-size:.76rem;color:#5b6b85}
.lg-rules li{display:flex;align-items:center;gap:7px}
.lg-rules i{color:#b9c6da;font-size:.8rem;transition:color .2s}
.lg-rules li.ok{color:#1b7f4b}
.lg-rules li.ok i{color:#0f9d6b}
.lg-links{display:flex;justify-content:center;margin-top:18px;font-size:.8rem}
.lg-links a{text-decoration:none;font-weight:600;transition:color .15s}
.lg-note2{text-align:center;padding:6px 0}
.lg-note2 .ic{width:62px;height:62px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;color:#b06a00;
  background:#fff8e6;border:1px solid #f7e2ae;box-shadow:0 10px 22px -12px rgba(176,106,0,.7);margin-bottom:14px}
.lg-foot{text-align:center;font-size:.72rem;color:#8a99b0;margin-top:20px;padding-top:14px;border-top:1px dashed var(--line)}
.alert{border-radius:12px;font-size:.82rem;border-color:#f3c9cf;background:#fdf2f4;color:#8b1e2d;padding:.6rem .75rem;margin-bottom:16px;
  display:flex;gap:8px;align-items:flex-start}
@media (max-width:767px){
  body{padding:16px 12px;align-items:flex-start}
  .lg-card{border-radius:20px}
  .lg-brand{padding:26px 22px;gap:14px}
  .lg-steps{display:none}
  .lg-form{padding:26px 22px 20px}
  .lg-h{font-size:1.2rem}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important}.lg-btn,.lg-links a,.lg-strength span{transition:none}}
</style></head>
<body>
<?php
$logo_url = function_exists('setting') ? setting('school_logo') : '';
$nat = function_exists('national_logo_src') ? national_logo_src() : '';
$showLogo = $logo_url !== '' ? $logo_url : $nat;
?>
<div class="lg-wrap">
  <div class="lg-card">
    <aside class="lg-brand">
      <div class="z">
        <?php if ($showLogo !== ''): ?><img src="<?php echo h($showLogo); ?>" alt="Logo" class="lg-logo"><?php endif; ?>
        <div>
          <div class="lg-title"><?php echo h(system_name()); ?></div>
          <div class="lg-sub mt-2">Choose a new password to get back into your account.</div>
        </div>
        <ul class="lg-steps">
          <li><i>1</i> Choose a password you have not used before</li>
          <li><i>2</i> Use at least 8 characters</li>
          <li><i>3</i> Sign in with your new password</li>
        </ul>
      </div>
      <div class="z lg-note"><?php echo h(strtoupper(date('Y'))); ?> &middot; <?php echo h(APP_NAME); ?> &middot; iEPAS</div>
    </aside>

    <main class="lg-form">
<?php if (!$row): ?>
      <div class="lg-note2">
        <div class="ic"><i class="bi bi-link-45deg"></i></div>
        <div class="lg-h">This link is no longer valid</div>
        <div class="lg-p">Kiungo hiki si sahihi, kimeshatumika, au muda wake (dakika 60) umeisha. Omba kiungo kipya ili kuendelea.</div>
      </div>
      <a href="forgot.php" class="btn btn-primary w-100 lg-btn"><i class="bi bi-envelope-arrow-up me-1"></i>Request a new link</a>
      <div class="lg-links"><a href="login.php" class="text-primary"><i class="bi bi-arrow-left-short"></i>Back to sign in</a></div>
<?php else: ?>
      <span class="lg-badge"><i class="bi bi-shield-lock"></i> Secure reset</span>
      <div class="lg-h">Set a new password</div>
      <div class="lg-p">Account: <b><?php echo h($row['full_name']); ?></b></div>

      <?php if ($err): ?><div class="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($err); ?></span></div><?php endif; ?>

      <form method="post" id="resetForm"><?php echo csrf_field(); ?><input type="hidden" name="token" value="<?php echo h($token); ?>">
        <div class="mb-3">
          <label class="form-label" for="password">Nenosiri jipya</label>
          <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" id="password" class="form-control ig-in" required minlength="8" autocomplete="new-password" autofocus placeholder="Angalau herufi 8">
            <span class="input-group-text ig-tail"><button type="button" class="lg-eye" data-for="password" aria-label="Show password"><i class="bi bi-eye"></i></button></span>
          </div>
          <div class="lg-strength" id="strength" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
          <div class="lg-sl" id="strengthLabel">Weka nenosiri lenye angalau herufi 8.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password2">Rudia nenosiri</label>
          <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-shield-check"></i></span>
            <input type="password" name="password2" id="password2" class="form-control ig-in" required minlength="8" autocomplete="new-password" placeholder="Andika nenosiri tena">
            <span class="input-group-text ig-tail"><button type="button" class="lg-eye" data-for="password2" aria-label="Show password"><i class="bi bi-eye"></i></button></span>
          </div>
        </div>

        <ul class="lg-rules" id="rules">
          <li data-rule="len"><i class="bi bi-circle"></i> Angalau herufi 8</li>
          <li data-rule="mix"><i class="bi bi-circle"></i> Herufi na namba</li>
          <li data-rule="match"><i class="bi bi-circle"></i> Nenosiri mbili zinafanana</li>
        </ul>

        <button class="btn btn-primary w-100 lg-btn mt-4"><i class="bi bi-check2-circle me-1"></i>Save new password</button>
      </form>
      <div class="lg-hint"><i class="bi bi-info-circle"></i><span>Kiungo hiki kinatumika <b>mara moja</b> pekee. Ukikihitaji tena, omba kipya kwenye ukurasa wa kusahau nenosiri.</span></div>
      <div class="lg-links"><a href="login.php" class="text-primary"><i class="bi bi-arrow-left-short"></i>Back to sign in</a></div>
<?php endif; ?>
      <div class="lg-foot">&copy; <?php echo h(date('Y')); ?> <?php echo h(APP_NAME); ?> &middot; iEPAS</div>
    </main>
  </div>
</div>
<?php if ($row): ?>
<script>
document.querySelectorAll('.lg-eye').forEach(function (b) {
  b.addEventListener('click', function () {
    var f = document.getElementById(b.getAttribute('data-for'));
    if (!f) return;
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    b.innerHTML = '<i class="bi bi-' + (show ? 'eye-slash' : 'eye') + '"></i>';
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});
(function () {
  var p = document.getElementById('password'), p2 = document.getElementById('password2');
  var st = document.getElementById('strength'), sl = document.getElementById('strengthLabel');
  var rules = {
    len: function () { return p.value.length >= 8; },
    mix: function () { return /[A-Za-z]/.test(p.value) && /\d/.test(p.value); },
    match: function () { return p.value !== '' && p.value === p2.value; }
  };
  var labels = ['Weka nenosiri lenye angalau herufi 8.', 'Nenosiri hafifu.', 'Nenosiri la kati.', 'Nenosiri zuri.', 'Nenosiri imara.'];
  function score() {
    var s = 0;
    if (p.value.length >= 8) s++;
    if (/[A-Za-z]/.test(p.value) && /\d/.test(p.value)) s++;
    if (/[^A-Za-z0-9]/.test(p.value) || p.value.length >= 12) s++;
    if (rules.match()) s++;
    return Math.min(s, 4);
  }
  function update() {
    var s = score();
    st.className = 'lg-strength' + (p.value ? ' s' + s : '');
    sl.textContent = p.value ? labels[s] : labels[0];
    Object.keys(rules).forEach(function (k) {
      var li = document.querySelector('#rules [data-rule="' + k + '"]');
      if (!li) return;
      var ok = rules[k]();
      li.classList.toggle('ok', ok);
      li.querySelector('i').className = 'bi bi-' + (ok ? 'check-circle-fill' : 'circle');
    });
  }
  if (p && p2) { p.addEventListener('input', update); p2.addEventListener('input', update); }
  var form = document.getElementById('resetForm');
  if (form) form.addEventListener('submit', function () {
    var btn = form.querySelector('button[type="submit"], .lg-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...'; }
  });
})();
</script>
<?php endif; ?>
</body></html>
