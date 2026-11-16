<?php
require_once __DIR__ . '/lib/bootstrap.php';
if (user() || student()) redirect(home_url());
$tab = (($_GET['t'] ?? $_POST['type'] ?? '') === 'student') ? 'student' : 'staff';
$err = '';
if (is_post()) {
    csrf_check();
    $id = trim($_POST['identifier'] ?? ''); $pw = (string)($_POST['secret'] ?? '');
    $fail = 'Invalid login details.';
    if ($tab === 'staff') {
        /* Cheque Number (users.username) is the normal login; email is accepted too (e.g. super admin). */
        $u = q_one('SELECT * FROM users WHERE username = ? AND active = 1', [$id]);
        if (!$u && $id !== '') {
            $u = q_one('SELECT * FROM users WHERE email = ? AND active = 1 ORDER BY id LIMIT 1', [$id]);
        }
        if (!$u) {
            if ($id !== '') {
                $off = q_one('SELECT active FROM users WHERE username = ? OR email = ? ORDER BY active DESC LIMIT 1', [$id, $id]);
                if ($off && !(int)$off['active']) $err = 'Your account has been disabled. Contact your school administrator.';
            }
            if ($err === '') { usleep(400000); $err = $fail; }
        } elseif ($u['locked_until'] && strtotime($u['locked_until']) > time()) $err = 'Account temporarily locked. Try again after 15 minutes.';
        elseif ($u['role'] !== 'super' && !(int)q_val('SELECT active FROM schools WHERE id = ?', [(int)$u['school_id']])) $err = 'Your school has been disabled. Contact your district office.';
        elseif (password_verify($pw, $u['password_hash'])) {
            q('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
            session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
            redirect($u['role'] === 'super' ? 'super/dashboard.php' : ($u['role'] === 'admin' ? 'admin/dashboard.php' : 'teacher/dashboard.php'));
        } else {
            $n = (int)$u['failed_attempts'] + 1;
            q('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$n >= 5 ? 0 : $n, $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]);
            usleep(400000); $err = $fail;
        }
    } else {
        $sch = (int)($_POST['school_id'] ?? 0);
        /* Mwanafunzi huingia kwa Index No / Candidate No (exam_candidates.cand_no); admission_no inakubaliwa kama akiba. */
        $s = q_one("SELECT st.* FROM students st
                    JOIN schools sc ON sc.id = st.school_id AND sc.active = 1
                    JOIN exam_candidates ec ON ec.student_id = st.id AND ec.school_id = st.school_id
                    WHERE st.school_id = ? AND (ec.cand_no = ? OR st.admission_no = ?) AND st.status <> 'inactive'
                    ORDER BY ec.id DESC LIMIT 1", [$sch, $id, $id]);
        if ($s && $s['pin_locked_until'] && strtotime($s['pin_locked_until']) > time()) $err = 'Account temporarily locked. Try again after 15 minutes.';
        elseif ($s && $s['pin_hash'] && password_verify($pw, $s['pin_hash'])) {
            q('UPDATE students SET pin_failed = 0, pin_locked_until = NULL WHERE id = ?', [$s['id']]);
            session_regenerate_id(true); $_SESSION['sid'] = (int)$s['id'];
            redirect('student/dashboard.php');
        } else {
            if ($s) { $n = (int)$s['pin_failed'] + 1; q('UPDATE students SET pin_failed = ?, pin_locked_until = ? WHERE id = ?', [$n >= 5 ? 0 : $n, $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $s['id']]); }
            usleep(400000); $err = $fail;
        }
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in - <?php echo h(APP_NAME); ?></title>
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
.lg-wrap{position:relative;width:100%;max-width:1000px;animation:lgIn .55s cubic-bezier(.22,.9,.3,1) both}
@keyframes lgIn{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
.lg-card{position:relative;display:flex;flex-wrap:wrap;overflow:hidden;background:#fff;border-radius:24px;
  box-shadow:0 30px 70px -22px rgba(11,61,145,.35),0 2px 8px rgba(11,61,145,.06);border:1px solid rgba(11,61,145,.07)}
.lg-card:before{content:"";position:absolute;inset:0 0 auto;height:4px;z-index:3;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 45%,var(--brand3))}
.lg-brand{flex:1 1 330px;min-width:290px;padding:40px 34px;color:#fff;position:relative;overflow:hidden;
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
.lg-feat{list-style:none;padding:0;margin:4px 0 0;font-size:.83rem;display:grid;gap:11px}
.lg-feat li{display:flex;align-items:center;gap:10px}
.lg-feat i{flex:0 0 27px;height:27px;border-radius:9px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.16);display:inline-flex;align-items:center;justify-content:center;font-size:.82rem}
.lg-note{font-size:.72rem;color:rgba(255,255,255,.72);letter-spacing:.02em}
.lg-form{flex:1 1 400px;padding:40px 38px 28px;position:relative;z-index:1}
.lg-badge{display:inline-flex;align-items:center;gap:6px;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:#1b7f4b;background:#eaf7f0;border:1px solid #cfeee0;border-radius:999px;padding:4px 10px;margin-bottom:14px}
.lg-h{font-size:1.35rem;font-weight:800;color:var(--brand);letter-spacing:-.01em;margin:0 0 4px}
.lg-p{font-size:.85rem;color:var(--mut);margin:0 0 20px}
.lg-tabs{display:flex;gap:4px;background:#eef3fb;border:1px solid var(--line);border-radius:14px;padding:4px;margin-bottom:20px}
.lg-tabs a{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;border-radius:10px;
  font-size:.8rem;font-weight:700;text-decoration:none;color:#5b6b85;transition:background .18s,color .18s,box-shadow .18s}
.lg-tabs a:hover{color:var(--brand);background:rgba(255,255,255,.72)}
.lg-tabs a.on{background:#fff;color:var(--brand);box-shadow:0 3px 10px rgba(11,61,145,.14)}
.form-label{font-size:.78rem;font-weight:700;color:#33415c;margin-bottom:5px}
.input-group-text{background:#f5f8fe;color:var(--brand);border-color:var(--line);padding:.5rem .7rem}
.ig-lead{border-right:0;border-radius:11px 0 0 11px}
.ig-tail{border-left:0;border-radius:0 11px 11px 0;padding:0}
.lg-form .form-control,.lg-form .form-select{border-color:var(--line);border-radius:11px;padding:.64rem .85rem;font-size:.9rem;background:#fdfefe}
.lg-form .ig-lead+.form-control{border-radius:0 11px 11px 0}
.lg-form .form-control.ig-in{border-radius:0}
.lg-form .form-select:focus,.lg-form .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 4px rgba(44,110,213,.13)}
.lg-form .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:var(--brand2)}
.lg-eye{border:0;background:transparent;color:#8a99b0;width:42px;height:100%;border-radius:0 11px 11px 0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.95rem}
.lg-eye:hover{color:var(--brand)}
.lg-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));border:0;border-radius:12px;padding:.74rem;font-weight:700;color:#fff;
  letter-spacing:.01em;box-shadow:0 12px 26px -8px rgba(11,61,145,.55);transition:transform .16s,box-shadow .16s,filter .16s}
.lg-btn:hover{filter:brightness(1.07);transform:translateY(-1px);box-shadow:0 16px 30px -8px rgba(11,61,145,.6)}
.lg-btn:active{transform:none}
.lg-links{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:18px;flex-wrap:wrap;font-size:.8rem}
.lg-links a{text-decoration:none;font-weight:600;transition:color .15s}
.lg-links a.text-muted:hover{color:var(--brand)!important}
.lg-foot{text-align:center;font-size:.72rem;color:#8a99b0;margin-top:20px;padding-top:14px;border-top:1px dashed var(--line)}
.alert{border-radius:12px;font-size:.82rem;border-color:#f3c9cf;background:#fdf2f4}
@media (max-width:767px){
  body{padding:16px 12px;align-items:flex-start}
  .lg-card{border-radius:20px}
  .lg-brand{padding:26px 22px;gap:14px}
  .lg-feat{display:none}
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
          <div class="lg-sub mt-2">Results management system, progress reports and student records.</div>
        </div>
        <ul class="lg-feat">
          <li><i class="bi bi-bar-chart-line"></i> Results and subject analysis</li>
          <li><i class="bi bi-file-earmark-text"></i> Progress reports (PDF)</li>
          <li><i class="bi bi-shield-check"></i> Secure school records</li>
        </ul>
      </div>
      <div class="z lg-note"><?php echo h(strtoupper(date('Y'))); ?> &middot; <?php echo h(APP_NAME); ?></div>
    </aside>

    <main class="lg-form">
      <span class="lg-badge"><i class="bi bi-shield-lock"></i> Secure sign-in</span>
      <div class="lg-h">Welcome back</div>
      <div class="lg-p"><?php echo $tab === 'staff' ? 'Sign in as Staff' : 'Sign in as Student'; ?></div>

      <div class="lg-tabs">
        <a href="?t=staff" class="<?php echo $tab === 'staff' ? 'on' : ''; ?>"><i class="bi bi-person-workspace me-1"></i>Staff</a>
        <a href="?t=student" class="<?php echo $tab === 'student' ? 'on' : ''; ?>"><i class="bi bi-mortarboard me-1"></i>Student</a>
      </div>

      <?php flash_show(); if ($err): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?php echo h($err); ?></div><?php endif; ?>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="type" value="<?php echo h($tab); ?>">
      <?php if ($tab === 'student'): ?><div class="mb-3"><label class="form-label">School</label><select name="school_id" class="form-select" required><option value="">-- select school --</option><?php foreach (q_all('SELECT id, name FROM schools WHERE active = 1 ORDER BY name') as $sc) echo '<option value="' . (int)$sc['id'] . '"' . ((int)($_POST['school_id'] ?? 0) === (int)$sc['id'] ? ' selected' : '') . '>' . h($sc['name']) . '</option>'; ?></select></div><?php endif; ?>
      <div class="mb-3"><label class="form-label" for="identifier"><?php echo $tab === 'staff' ? 'Cheque Number / Email' : 'Index No / Candidate No'; ?></label>
        <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-person-badge"></i></span><input name="identifier" id="identifier" class="form-control" required autofocus autocomplete="username"<?php echo $tab === 'staff' ? ' placeholder="e.g. 1234567"' : ''; ?>></div></div>
      <div class="mb-3"><label class="form-label" for="secret"><?php echo $tab === 'staff' ? 'Password' : 'PIN'; ?></label>
        <div class="input-group"><span class="input-group-text ig-lead"><i class="bi bi-lock"></i></span><input name="secret" id="secret" type="password" class="form-control ig-in" required autocomplete="current-password"><span class="input-group-text ig-tail"><button type="button" class="lg-eye" data-for="secret" aria-label="Show password"><i class="bi bi-eye"></i></button></span></div></div>
      <button class="btn btn-primary w-100 lg-btn"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button></form>

      <div class="lg-links">
        <?php if ($tab === 'staff'): ?>
          <a href="forgot.php" class="text-muted"><i class="bi bi-key me-1"></i>Forgot password?</a>
          <a href="?t=student" class="text-primary">Sign in as Student <i class="bi bi-arrow-right-short"></i></a>
        <?php else: ?>
          <span class="text-muted"><i class="bi bi-info-circle me-1"></i>Forgot your PIN? See your admin.</span>
          <a href="?t=staff" class="text-primary">Staff <i class="bi bi-arrow-right-short"></i></a>
        <?php endif; ?>
      </div>
      <div class="lg-foot">&copy; <?php echo h(date('Y')); ?> <?php echo h(APP_NAME); ?></div>
    </main>
  </div>
</div>
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
</script>
</body></html>