<?php
/**
 * Usakinishaji wa mara ya kwanza: tengeneza super admin (wilaya).
 * - Jina: first name, middle name (hiari), surname
 * - Username = email
 * - Nenosiri hutengenezwa kiotomatiki kutoka kwenye surname + sehemu ya nasibu, na huonyeshwa MARA MOJA tu.
 * Hujifunga baada ya mtumiaji wa kwanza kuundwa.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$installed = (int)q_val('SELECT COUNT(*) FROM users') > 0;
$err = ''; $created = null;
if (!$installed && is_post()) {
    csrf_check();
    $sys   = trim($_POST['system_name'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $mid   = trim($_POST['middle_name'] ?? '');
    $sur   = trim($_POST['surname'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $year  = trim($_POST['year'] ?? date('Y'));
    $nameOk = function ($n, $required) { return $n === '' ? !$required : (bool)preg_match('/^[\\p{L}\' \\-]{2,50}$/u', $n); };

    if ($sys === '' || $first === '' || $sur === '' || $email === '' || $year === '') $err = 'Jaza sehemu zote zenye nyota (*).';
    elseif (!$nameOk($first, true) || !$nameOk($mid, false) || !$nameOk($sur, true)) $err = 'Majina yaandikwe kwa herufi tu (angalau herufi 2).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Weka email halali (itatumika kama username).';
    elseif (strlen($email) > 60) $err = 'Email ni ndefu mno (upeo herufi 60).';
    else {
        $fullName = trim(preg_replace('/\s+/', ' ', $first . ' ' . $mid . ' ' . $sur));
        $password = generate_password($sur);
        try {
            db()->beginTransaction();
            set_setting('system_name', $sys);
            q("INSERT INTO users (username, password_hash, full_name, role, email) VALUES (?, ?, ?, 'super', ?)", [$email, password_hash($password, PASSWORD_DEFAULT), $fullName, $email]);
            q('INSERT INTO academic_years (name, is_current) VALUES (?, 1)', [$year]);
            db()->commit();
            $created = ['name' => $fullName, 'email' => $email, 'password' => $password];
        } catch (Exception $e) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'Imeshindikana kusakinisha: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html><html lang="sw"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install - <?php echo h(system_name()); ?></title>
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
.inst-wrap{position:relative;width:100%;max-width:1020px;animation:instIn .55s cubic-bezier(.22,.9,.3,1) both}
@keyframes instIn{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
.inst-card{position:relative;display:flex;flex-wrap:wrap;overflow:hidden;background:#fff;border-radius:24px;
  box-shadow:0 30px 70px -22px rgba(11,61,145,.35),0 2px 8px rgba(11,61,145,.06);border:1px solid rgba(11,61,145,.07)}
.inst-card:before{content:"";position:absolute;inset:0 0 auto;height:4px;z-index:3;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 45%,var(--brand3))}
.inst-brand{flex:1 1 330px;min-width:290px;padding:40px 34px;color:#fff;position:relative;overflow:hidden;
  background:linear-gradient(155deg,#0a3a89 0%,#14479e 42%,#2c6ed5 100%);display:flex;flex-direction:column;justify-content:space-between;gap:22px}
.inst-brand:before{content:"";position:absolute;right:-30%;top:-40%;width:420px;height:420px;border-radius:50%;
  background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.20),transparent 62%);animation:instFloat 14s ease-in-out infinite}
.inst-brand:after{content:"";position:absolute;left:-90px;bottom:-110px;width:300px;height:300px;border-radius:50%;
  background:rgba(15,157,107,.35);filter:blur(8px);animation:instFloat 18s ease-in-out infinite reverse}
@keyframes instFloat{0%,100%{transform:translate3d(0,0,0)}50%{transform:translate3d(-14px,16px,0)}}
.inst-brand .z{position:relative;z-index:1;display:flex;flex-direction:column;gap:14px}
.inst-logo{height:62px;width:62px;display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;
  color:var(--brand);background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.22)}
.inst-title{font-size:1.5rem;font-weight:800;line-height:1.22;letter-spacing:-.01em}
.inst-sub{font-size:.85rem;opacity:.9;line-height:1.6;max-width:34ch}
.inst-feat{list-style:none;padding:0;margin:4px 0 0;font-size:.83rem;display:grid;gap:11px}
.inst-feat li{display:flex;align-items:center;gap:10px}
.inst-feat i{flex:0 0 27px;height:27px;border-radius:9px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.16);display:inline-flex;align-items:center;justify-content:center;font-size:.82rem}
.inst-note{font-size:.72rem;color:rgba(255,255,255,.72);letter-spacing:.02em}
.inst-form{flex:1 1 430px;padding:38px 38px 28px;position:relative;z-index:1}
.inst-badge{display:inline-flex;align-items:center;gap:6px;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:#1b7f4b;background:#eaf7f0;border:1px solid #cfeee0;border-radius:999px;padding:4px 10px;margin-bottom:14px}
.inst-h{font-size:1.35rem;font-weight:800;color:var(--brand);letter-spacing:-.01em;margin:0 0 4px}
.inst-p{font-size:.85rem;color:var(--mut);margin:0 0 20px}
.inst-sec{display:flex;align-items:center;gap:9px;font-size:.72rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;
  color:#5b6b85;margin:0 0 12px}
.inst-sec:after{content:"";flex:1;height:1px;background:var(--line)}
.inst-sec i{font-size:.9rem;color:var(--brand2)}
.form-label{font-size:.78rem;font-weight:700;color:#33415c;margin-bottom:5px}
.input-group-text{background:#f5f8fe;color:var(--brand);border-color:var(--line);padding:.5rem .7rem}
.ig-lead{border-right:0;border-radius:11px 0 0 11px}
.inst-form .form-control{border-color:var(--line);border-radius:11px;padding:.64rem .85rem;font-size:.9rem;background:#fdfefe}
.inst-form .ig-lead+.form-control{border-radius:0 11px 11px 0}
.inst-form .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 4px rgba(44,110,213,.13)}
.inst-form .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:var(--brand2)}
.inst-hint{font-size:.72rem;color:var(--mut);margin:6px 0 0}
.inst-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));border:0;border-radius:12px;padding:.74rem;font-weight:700;color:#fff;
  letter-spacing:.01em;box-shadow:0 12px 26px -8px rgba(11,61,145,.55);transition:transform .16s,box-shadow .16s,filter .16s;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;gap:6px}
.inst-btn:hover{filter:brightness(1.07);transform:translateY(-1px);box-shadow:0 16px 30px -8px rgba(11,61,145,.6);color:#fff}
.inst-btn:active{transform:none}
.inst-foot{text-align:center;font-size:.72rem;color:#8a99b0;margin-top:20px;padding-top:14px;border-top:1px dashed var(--line)}
.alert{border-radius:12px;font-size:.82rem;border-color:#f3c9cf;background:#fdf2f4;color:#8b1e2d;
  display:flex;gap:8px;align-items:flex-start;padding:.65rem .8rem;margin-bottom:16px}
.inst-done{text-align:center}
.inst-tick{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:1.7rem;color:#fff;background:linear-gradient(140deg,#17b877,var(--brand3));box-shadow:0 14px 30px -10px rgba(15,157,107,.7)}
.inst-cred{border:1px solid var(--line);border-radius:16px;overflow:hidden;margin:16px 0 14px;text-align:left;background:#fbfdff}
.inst-cred .row-line{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:.85rem}
.inst-cred .row-line+.row-line{border-top:1px solid var(--line)}
.inst-cred .k{flex:0 0 92px;font-size:.7rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#6b7a90}
.inst-cred .v{flex:1;min-width:0;word-break:break-all;font-weight:600}
.inst-cred code{background:#eef3fb;border:1px solid var(--line);border-radius:8px;padding:2px 8px;font-size:.9rem;color:var(--brand)}
.inst-copy{border:1px solid var(--line);background:#fff;color:#5b6b85;border-radius:9px;font-size:.72rem;font-weight:700;
  padding:4px 9px;display:inline-flex;align-items:center;gap:4px;transition:color .15s,border-color .15s}
.inst-copy:hover{color:var(--brand);border-color:#b9d0f0}
.inst-warn{border-radius:12px;background:#fff8e6;border:1px solid #f7e2ae;color:#7a5a10;font-size:.78rem;
  padding:.6rem .75rem;text-align:left;display:flex;gap:8px;align-items:flex-start;margin-bottom:16px}
@media (max-width:767px){
  body{padding:16px 12px;align-items:flex-start}
  .inst-card{border-radius:20px}
  .inst-brand{padding:26px 22px;gap:14px}
  .inst-feat{display:none}
  .inst-form{padding:26px 22px 20px}
  .inst-h{font-size:1.2rem}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important}.inst-btn,.inst-copy{transition:none}}
</style></head>
<body>
<div class="inst-wrap">
  <div class="inst-card">
    <aside class="inst-brand">
      <div class="z">
        <span class="inst-logo"><i class="bi bi-shield-lock"></i></span>
        <div>
          <div class="inst-title"><?php echo h(system_name()); ?></div>
          <div class="inst-sub mt-2">Results management, progress reports and student records for your district.</div>
        </div>
        <ul class="inst-feat">
          <li><i class="bi bi-1-circle"></i> One-time setup &mdash; runs only once</li>
          <li><i class="bi bi-person-check"></i> Creates the first district super admin</li>
          <li><i class="bi bi-key"></i> Password is generated and shown once</li>
          <li><i class="bi bi-calendar-check"></i> Sets the current academic year</li>
        </ul>
      </div>
      <div class="z inst-note"><?php echo h(strtoupper(date('Y'))); ?> &middot; <?php echo h(APP_NAME); ?></div>
    </aside>

    <main class="inst-form">
<?php if ($installed): ?>
      <div class="inst-done">
        <div class="inst-tick"><i class="bi bi-lock-fill"></i></div>
        <div class="inst-h">The system is already installed</div>
        <p class="inst-p">Mfumo umeshasakinishwa. Ingia kwa akaunti yako ya super admin.</p>
        <a class="inst-btn w-100" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Go to sign in</a>
      </div>
<?php elseif ($created): ?>
      <div class="inst-done">
        <div class="inst-tick"><i class="bi bi-check-lg"></i></div>
        <div class="inst-h">Installation complete</div>
        <p class="inst-p">Hifadhi taarifa hizi sasa hivi. <b>Nenosiri halitaonyeshwa tena</b> (halihifadhiwi kwa maandishi wazi).</p>
      </div>
      <div class="inst-cred">
        <div class="row-line"><span class="k">Full name</span><span class="v"><?php echo h($created['name']); ?></span></div>
        <div class="row-line"><span class="k">Username</span><span class="v"><code id="v-user"><?php echo h($created['email']); ?></code></span>
          <button type="button" class="inst-copy" data-copy="v-user"><i class="bi bi-clipboard"></i> Copy</button></div>
        <div class="row-line"><span class="k">Password</span><span class="v"><code id="v-pass"><?php echo h($created['password']); ?></code></span>
          <button type="button" class="inst-copy" data-copy="v-pass"><i class="bi bi-clipboard"></i> Copy</button></div>
      </div>
      <div class="inst-warn"><i class="bi bi-exclamation-triangle-fill"></i>
        <span>Baada ya kuingia, badilisha nenosiri kupitia <b>My Account</b>. Nenosiri hili lina sehemu ya nasibu, lakini ni vizuri liwe lako mwenyewe.</span></div>
      <a class="inst-btn w-100" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Proceed to sign in</a>
<?php else: ?>
      <span class="inst-badge"><i class="bi bi-stars"></i> First installation</span>
      <div class="inst-h">Set up your system</div>
      <p class="inst-p">Create the <b>super admin</b> account (District &ndash; DSEO). This runs only once.</p>

      <?php if ($err): ?><div class="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($err); ?></span></div><?php endif; ?>

      <form method="post" autocomplete="off"><?php echo csrf_field(); ?>
        <div class="inst-sec"><i class="bi bi-gear"></i> System</div>
        <div class="mb-2">
          <label class="form-label" for="system_name">System name *</label>
          <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-building"></i></span>
            <input name="system_name" id="system_name" class="form-control" required value="<?php echo h($_POST['system_name'] ?? 'Operations & Academic School Information (OASIS)'); ?>"></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="year">Academic year *</label>
          <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-calendar3"></i></span>
            <input name="year" id="year" class="form-control" required value="<?php echo h($_POST['year'] ?? date('Y')); ?>"></div>
        </div>

        <div class="inst-sec mt-4"><i class="bi bi-person-badge"></i> Super admin</div>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label" for="first_name">First name *</label>
            <input name="first_name" id="first_name" class="form-control" required value="<?php echo h($_POST['first_name'] ?? 'Christopher'); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="middle_name">Middle name</label>
            <input name="middle_name" id="middle_name" class="form-control" value="<?php echo h($_POST['middle_name'] ?? 'Henry'); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="surname">Surname *</label>
            <input name="surname" id="surname" class="form-control" required value="<?php echo h($_POST['surname'] ?? 'Ntanyi'); ?>">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="email">Username (email) *</label>
          <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-envelope-at"></i></span>
            <input name="email" id="email" type="email" class="form-control" required maxlength="60" value="<?php echo h($_POST['email'] ?? 'henryntanyi@gmail.com'); ?>"></div>
          <p class="inst-hint">Email hii itatumika kama jina la kuingia (username).</p>
        </div>

        <button class="inst-btn w-100"><i class="bi bi-magic"></i> Install system</button>
      </form>
      <div class="inst-foot">&copy; <?php echo h(date('Y')); ?> <?php echo h(APP_NAME); ?></div>
<?php endif; ?>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('.inst-copy').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.getElementById(b.getAttribute('data-copy'));
    if (!el) return;
    var text = el.textContent;
    var done = function () {
      var old = b.innerHTML;
      b.innerHTML = '<i class="bi bi-check2"></i> Copied';
      setTimeout(function () { b.innerHTML = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      var t = document.createElement('textarea');
      t.value = text; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(t); done();
    }
  });
});
</script>
</body></html>
