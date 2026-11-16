<?php
/** Umesahau nenosiri: tuma kiungo cha kureset kwa email ya akaunti (walimu/admin/super). */
require_once __DIR__ . '/lib/bootstrap.php';
if (user() || student()) redirect(home_url());
$done = false;
if (is_post()) {
    csrf_check();
    $id = trim($_POST['identifier'] ?? '');
    $u = $id !== '' ? q_one("SELECT * FROM users WHERE active = 1 AND email IS NOT NULL AND email <> '' AND (email = ? OR username = ?) LIMIT 1", [$id, $id]) : null;
    if ($u && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        // Mipaka: maombi 3 kwa saa kwa kila akaunti
        $recent = (int)q_val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$u['id']]);
        if ($recent < 3) {
            $token = bin2hex(random_bytes(32));
            q('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), ?)',
              [$u['id'], hash('sha256', $token), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
            $link = absolute_url('reset.php?token=' . $token);
            send_mail($u['email'], 'Kuweka nenosiri jipya - ' . system_name(),
                "Habari " . $u['full_name'] . ",\n\nTumepokea ombi la kuweka nenosiri jipya la akaunti yako.\n\nBofya kiungo hiki (kinatumika mara moja, ndani ya dakika 60):\n" . $link .
                "\n\nUkikosa kuomba hili, puuza barua hii; nenosiri lako halijabadilika.\n\n" . system_name());
        }
    }
    usleep(300000);
    $done = true;   // jibu ni lilelile hata kama akaunti haipo (kuzuia kubaini akaunti zilizopo)
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password - <?php echo h(system_name()); ?></title>
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
.input-group-text{background:#f5f8fe;color:var(--brand);border-color:var(--line);padding:.5rem .7rem;border-right:0;border-radius:11px 0 0 11px}
.lg-form .form-control{border-color:var(--line);border-radius:0 11px 11px 0;padding:.64rem .85rem;font-size:.9rem;background:#fdfefe}
.lg-form .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 4px rgba(44,110,213,.13)}
.lg-form .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:var(--brand2)}
.lg-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));border:0;border-radius:12px;padding:.74rem;font-weight:700;color:#fff;
  letter-spacing:.01em;box-shadow:0 12px 26px -8px rgba(11,61,145,.55);transition:transform .16s,box-shadow .16s,filter .16s}
.lg-btn:hover{filter:brightness(1.07);transform:translateY(-1px);box-shadow:0 16px 30px -8px rgba(11,61,145,.6)}
.lg-btn:active{transform:none}
.lg-hint{display:flex;gap:9px;font-size:.78rem;color:#5b6b85;background:#f6f9fe;border:1px solid var(--line);border-radius:12px;padding:11px 13px;margin-top:18px;line-height:1.55}
.lg-hint i{color:var(--brand2);font-size:.95rem;margin-top:1px}
.lg-ok{text-align:center;padding:6px 0}
.lg-ok .ic{width:62px;height:62px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.7rem;color:#1b7f4b;
  background:#eaf7f0;border:1px solid #cfeee0;box-shadow:0 10px 22px -12px rgba(27,127,75,.7);margin-bottom:14px}
.lg-links{display:flex;justify-content:center;margin-top:18px;font-size:.8rem}
.lg-links a{text-decoration:none;font-weight:600;transition:color .15s}
.lg-foot{text-align:center;font-size:.72rem;color:#8a99b0;margin-top:20px;padding-top:14px;border-top:1px dashed var(--line)}
.alert{border-radius:12px;font-size:.82rem;border-color:#f3c9cf;background:#fdf2f4}
@media (max-width:767px){
  body{padding:16px 12px;align-items:flex-start}
  .lg-card{border-radius:20px}
  .lg-brand{padding:26px 22px;gap:14px}
  .lg-steps{display:none}
  .lg-form{padding:26px 22px 20px}
  .lg-h{font-size:1.2rem}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important}.lg-btn,.lg-links a{transition:none}}
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
          <div class="lg-sub mt-2">Regain access to your account in three quick steps.</div>
        </div>
        <ul class="lg-steps">
          <li><i>1</i> Enter your email or Cheque Number</li>
          <li><i>2</i> Open the reset link we email you</li>
          <li><i>3</i> Create a new password and sign in</li>
        </ul>
      </div>
      <div class="z lg-note"><?php echo h(strtoupper(date('Y'))); ?> &middot; <?php echo h(APP_NAME); ?></div>
    </aside>

    <main class="lg-form">
      <?php if ($done): ?>
        <div class="lg-ok">
          <div class="ic"><i class="bi bi-envelope-check"></i></div>
          <div class="lg-h">Check your inbox</div>
          <div class="lg-p">If an account with those details exists, we have emailed a reset link. It expires in <b>60 minutes</b> and can be used once.</div>
          <div class="lg-hint"><i class="bi bi-info-circle"></i><span>Can't find the email? Check your <b>Spam</b> folder, then wait a few minutes before trying again.</span></div>
          <a href="login.php" class="btn btn-primary w-100 lg-btn mt-3"><i class="bi bi-box-arrow-in-right me-1"></i>Back to sign in</a>
        </div>
      <?php else: ?>
        <span class="lg-badge"><i class="bi bi-shield-lock"></i> Password recovery</span>
        <div class="lg-h">Forgot your password?</div>
        <div class="lg-p">Enter the email or Cheque Number linked to your account and we'll send you a link to set a new password.</div>

        <?php flash_show(); ?>
        <form method="post"><?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label" for="identifier">Email or Cheque Number</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-person-badge"></i></span><input name="identifier" id="identifier" class="form-control" required autofocus autocomplete="username" placeholder="e.g. 1234567"></div>
          </div>
          <button class="btn btn-primary w-100 lg-btn"><i class="bi bi-send me-1"></i>Send reset link</button>
        </form>

        <div class="lg-hint"><i class="bi bi-clock-history"></i><span>You can request up to <b>3 links per hour</b>. Each link expires after <b>60 minutes</b> and works once.</span></div>
        <div class="lg-hint"><i class="bi bi-info-circle"></i><span>No email on your account? Ask your school or district admin to reset it. <b>Students:</b> ask your teacher or admin for a new PIN.</span></div>
        <div class="lg-links"><a href="login.php" class="text-primary"><i class="bi bi-arrow-left-short"></i>Back to sign in</a></div>
      <?php endif; ?>
      <div class="lg-foot">&copy; <?php echo h(date('Y')); ?> <?php echo h(APP_NAME); ?></div>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () {
    var b = f.querySelector('.lg-btn');
    if (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...'; }
  });
});
</script>
</body></html>
