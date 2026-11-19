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
        $u = q_one('SELECT * FROM users WHERE username = ? AND active = 1', [$id]);
        if (!$u && $id !== '') { $u = q_one('SELECT * FROM users WHERE email = ? AND active = 1 ORDER BY id LIMIT 1', [$id]); }
        if (!$u) {
            if ($id !== '') { $off = q_one('SELECT active FROM users WHERE username = ? OR email = ? ORDER BY active DESC LIMIT 1', [$id, $id]); if ($off && !(int)$off['active']) $err = 'Your account has been disabled. Contact your school administrator.'; }
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
        $s = q_one("SELECT st.* FROM students st JOIN schools sc ON sc.id = st.school_id AND sc.active = 1 JOIN exam_candidates ec ON ec.student_id = st.id AND ec.school_id = st.school_id WHERE st.school_id = ? AND (ec.cand_no = ? OR st.admission_no = ?) AND st.status <> 'inactive' ORDER BY ec.id DESC LIMIT 1", [$sch, $id, $id]);
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
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in - <?php echo h(APP_NAME); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#141826;--navy:#0b3d91;--navy-2:#2c6ed5;--gold:#0f9d6b;--paper:#fbfaf7;--paper-2:#f4f1ea;--line:#e6e0d4;--mut:#6f7787}
*{box-sizing:border-box}html,body{margin:0;height:100%}
body{font-family:"Inter",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--paper-2);background-image:radial-gradient(60% 50% at 10% 0%,rgba(11,61,145,.09),transparent 70%),radial-gradient(50% 55% at 100% 100%,rgba(15,157,107,.10),transparent 70%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;-webkit-font-smoothing:antialiased}
.lg-shell{width:100%;max-width:1040px;animation:lgIn .6s cubic-bezier(.2,.9,.3,1) both}
@keyframes lgIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.lg-card{display:grid;grid-template-columns:1.05fr 1fr;background:#fff;border:1px solid var(--line);border-radius:22px;overflow:hidden;box-shadow:0 40px 80px -40px rgba(11,42,74,.45),0 2px 6px rgba(11,42,74,.05)}
.lg-aside{position:relative;padding:46px 42px;background:linear-gradient(165deg,#062a6e 0%,#0b3d91 50%,#2c6ed5 100%);color:#fff;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;gap:26px}
.lg-aside::before{content:"";position:absolute;inset:-120px -120px auto auto;width:420px;height:420px;border-radius:50%;border:1px solid rgba(194,161,92,.22)}
.lg-aside::after{content:"";position:absolute;right:-110px;bottom:-140px;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(194,161,92,.16),transparent 62%)}
.lg-aside>*{position:relative;z-index:1}
.lg-logo{height:54px;width:auto;max-width:170px;object-fit:contain;background:#fff;border-radius:14px;padding:7px;box-shadow:0 10px 26px rgba(0,0,0,.25)}
.lg-logo-tile{height:54px;width:54px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);font-size:1.5rem;color:var(--gold)}
.lg-brand-name{margin-top:4px;font-family:"Cormorant Garamond",Georgia,serif;font-size:1.7rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#ffffff;line-height:1.2;word-break:break-word;text-shadow:0 2px 12px rgba(0,0,0,.35)}
.lg-brand-sub{margin-top:2px;font-size:.78rem;color:rgba(255,255,255,.62);letter-spacing:.04em}
.lg-quote{margin:26px 0 10px;font-family:"Cormorant Garamond",Georgia,serif;font-size:1.85rem;font-weight:500;line-height:1.2;letter-spacing:-.01em;color:#fff}
.lg-about{margin:0;max-width:34ch;font-size:.86rem;line-height:1.65;color:rgba(255,255,255,.72)}
.lg-gold-rule{width:44px;height:1px;background:var(--gold);margin:22px 0 18px;border:0}
.lg-points{list-style:none;padding:0;margin:0;display:grid;gap:12px}
.lg-points li{display:flex;align-items:center;gap:12px;font-size:.82rem;color:rgba(255,255,255,.82)}
.lg-points li::before{content:"";flex:0 0 6px;height:6px;border-radius:50%;background:var(--gold);box-shadow:0 0 0 3px rgba(194,161,92,.18)}
.lg-aside-foot{font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.45)}
.lg-main{padding:46px 44px 34px;position:relative}
.lg-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:26px}
.lg-eyebrow{display:inline-flex;align-items:center;gap:7px;font-size:.68rem;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--navy)}
.lg-eyebrow i{color:var(--gold)}
.lg-home{font-size:.8rem;font-weight:500;color:var(--mut);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:color .2s}
.lg-home:hover{color:var(--navy)}
.lg-h{margin:0 0 6px;font-family:"Cormorant Garamond",Georgia,serif;font-size:2.05rem;font-weight:600;line-height:1.1;letter-spacing:-.01em;color:var(--navy)}
.lg-p{margin:0 0 24px;font-size:.88rem;color:var(--mut);line-height:1.5}
.lg-tabs{display:flex;gap:4px;background:var(--paper-2);border:1px solid var(--line);border-radius:14px;padding:4px;margin-bottom:22px}
.lg-tabs a{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;border-radius:10px;font-size:.8rem;font-weight:600;text-decoration:none;color:var(--mut);transition:background .18s,color .18s,box-shadow .18s}
.lg-tabs a:hover{color:var(--navy)}
.lg-tabs a.on{background:#fff;color:var(--navy);box-shadow:0 3px 10px rgba(11,42,74,.12)}
.lg-alert{border-radius:12px;font-size:.84rem;border:1px solid #e7c9c9;background:#fdf2f2;color:#7a2b2b;padding:10px 13px;margin-bottom:18px;display:flex;align-items:flex-start;gap:8px}
.lg-field{margin-bottom:16px}
.lg-field .form-label{font-size:.74rem;font-weight:600;letter-spacing:.04em;color:var(--ink);margin-bottom:6px}
.lg-input-group{display:flex;align-items:stretch}
.lg-input-group .lg-lead{display:inline-flex;align-items:center;justify-content:center;width:44px;flex:0 0 44px;background:var(--paper);border:1px solid var(--line);border-right:0;border-radius:12px 0 0 12px;color:var(--navy);font-size:.95rem}
.lg-input-group .form-control,.lg-input-group .form-select{border:1px solid var(--line);border-radius:0 12px 12px 0;padding:.68rem .9rem;font-size:.9rem;background:var(--paper);color:var(--ink);height:44px}
.lg-input-group .form-control.ig-in{border-radius:0}
.lg-input-group .lg-tail{display:flex;align-items:center;background:var(--paper);border:1px solid var(--line);border-left:0;border-radius:0 12px 12px 0;padding:0 4px}
.lg-input-group:focus-within .lg-lead,.lg-input-group:focus-within .lg-tail,.lg-input-group:focus-within .form-control,.lg-input-group:focus-within .form-select{border-color:var(--navy-2);box-shadow:0 0 0 4px rgba(11,42,74,.08)}
.lg-eye{border:0;background:transparent;color:var(--mut);width:36px;height:36px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.95rem;transition:color .15s,background .15s}
.lg-eye:hover{color:var(--navy);background:rgba(11,42,74,.06)}
.lg-btn{background:linear-gradient(135deg,var(--navy-2),var(--navy));border:0;border-radius:12px;padding:.78rem 1rem;font-weight:600;font-size:.9rem;color:#fff;letter-spacing:.02em;box-shadow:0 12px 26px -10px rgba(11,42,74,.55);transition:transform .16s,box-shadow .16s,filter .16s;height:46px}
.lg-btn:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(11,42,74,.6)}
.lg-btn:active{transform:none}
.lg-links{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:18px;flex-wrap:wrap;font-size:.8rem}
.lg-links a{text-decoration:none;font-weight:500;transition:color .15s}
.lg-links a.text-muted:hover{color:var(--navy)!important}
.lg-link-accent{color:var(--navy);font-weight:600;text-decoration:none;transition:color .15s}
.lg-link-accent:hover{color:var(--gold)}
.lg-foot{text-align:center;font-size:.72rem;color:var(--mut);margin-top:22px;padding-top:16px;border-top:1px dashed var(--line);letter-spacing:.04em}
@media (max-width:860px){.lg-card{grid-template-columns:1fr}.lg-aside{padding:26px 24px;gap:14px}.lg-quote{font-size:1.35rem;margin:14px 0 6px}.lg-about,.lg-gold-rule,.lg-points{display:none}.lg-main{padding:26px 22px 22px}.lg-h{font-size:1.7rem}}
@media (prefers-reduced-motion:reduce){.lg-shell,.lg-shell *{animation:none!important}.lg-btn,.lg-links a{transition:none}}
</style>
</head>
<body>
<?php
$logo_url = function_exists('setting') ? setting('school_logo') : '';
$nat = function_exists('national_logo_src') ? national_logo_src() : '';
$showLogo = $logo_url !== '' ? $logo_url : $nat;
?>
<div class="lg-shell">
  <div class="lg-card">
    <aside class="lg-aside">
      <div>
        <div class="lg-brand-name"><?php echo h(strtoupper(system_name())); ?></div>
        <div class="lg-brand-sub">Results management system</div>
        <h2 class="lg-quote">Clarity for every classroom.</h2>
        <p class="lg-about">Progress reports, subject analysis and secure school records, all in one place.</p>
        <hr class="lg-gold-rule">
        <ul class="lg-points">
          <li>Results and subject analysis</li>
          <li>Progress reports (PDF)</li>
          <li>Secure school records</li>
        </ul>
      </div>
      <div class="lg-aside-foot"><?php echo h(strtoupper(date('Y'))); ?> &middot; <?php echo h(APP_NAME); ?></div>
    </aside>

    <main class="lg-main">
      <div class="lg-top">
        <span class="lg-eyebrow"><i class="bi bi-shield-check"></i> Secure sign-in</span>
        <a class="lg-home" href="<?php echo h(url('index.php')); ?>"><i class="bi bi-house-door"></i> Home</a>
      </div>
      <h1 class="lg-h">Welcome back</h1>
      <p class="lg-p"><?php echo $tab === 'staff' ? 'Sign in to your staff dashboard.' : 'Sign in to view your results.'; ?></p>

      <div class="lg-tabs">
        <a href="?t=staff" class="<?php echo $tab === 'staff' ? 'on' : ''; ?>"><i class="bi bi-person-workspace"></i> Staff</a>
        <a href="?t=student" class="<?php echo $tab === 'student' ? 'on' : ''; ?>"><i class="bi bi-mortarboard"></i> Student</a>
      </div>

      <?php flash_show(); if ($err): ?>
        <div class="lg-alert"><i class="bi bi-exclamation-triangle"></i><span><?php echo h($err); ?></span></div>
      <?php endif; ?>

      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="type" value="<?php echo h($tab); ?>">
        <?php if ($tab === 'student'): ?>
          <div class="row g-3 mb-1">
            <div class="col-md-6">
              <div class="lg-field">
                <label class="form-label" for="school_id">School</label>
                <div class="lg-input-group">
                  <span class="lg-lead"><i class="bi bi-building"></i></span>
                  <select name="school_id" id="school_id" class="form-select" required>
                    <option value="">-- select school --</option>
                    <?php foreach (q_all('SELECT id, name FROM schools WHERE active = 1 ORDER BY name') as $sc) echo '<option value="' . (int)$sc['id'] . '"' . ((int)($_POST['school_id'] ?? 0) === (int)$sc['id'] ? ' selected' : '') . '>' . h($sc['name']) . '</option>'; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="lg-field">
                <label class="form-label" for="identifier">Index No / Candidate No</label>
                <div class="lg-input-group">
                  <span class="lg-lead"><i class="bi bi-person-badge"></i></span>
                  <input name="identifier" id="identifier" class="form-control" required autofocus autocomplete="username">
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="lg-field">
            <label class="form-label" for="identifier">Cheque Number / Email</label>
            <div class="lg-input-group">
              <span class="lg-lead"><i class="bi bi-person-badge"></i></span>
              <input name="identifier" id="identifier" class="form-control" required autofocus autocomplete="username" placeholder="e.g. 1234567">
            </div>
          </div>
        <?php endif; ?>

        <div class="lg-field">
          <label class="form-label" for="secret"><?php echo $tab === 'staff' ? 'Password' : 'PIN'; ?></label>
          <div class="lg-input-group">
            <span class="lg-lead"><i class="bi bi-lock"></i></span>
            <input name="secret" id="secret" type="password" class="form-control ig-in" required autocomplete="current-password">
            <span class="lg-tail">
              <button type="button" class="lg-eye" data-for="secret" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </span>
          </div>
        </div>

        <button class="btn btn-primary w-100 lg-btn"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
      </form>

      <div class="lg-links">
        <?php if ($tab === 'staff'): ?>
          <a href="forgot.php" class="text-muted"><i class="bi bi-key me-1"></i>Forgot password?</a>
          <a href="?t=student" class="lg-link-accent">Sign in as Student <i class="bi bi-arrow-right-short"></i></a>
        <?php else: ?>
          <span class="text-muted"><i class="bi bi-info-circle me-1"></i>Forgot your PIN? See your admin.</span>
          <a href="?t=staff" class="lg-link-accent">Staff <i class="bi bi-arrow-right-short"></i></a>
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
</body>
</html>
