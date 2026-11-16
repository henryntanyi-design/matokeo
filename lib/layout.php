<?php
/** Muundo wa pamoja wa kurasa (navbar + sidebar kulingana na role na shule iliyo hai). */
function nav_items() {
    $u = user();
    if ($u && $u['role'] === 'super' && !active_school_id()) return [
        ['dashboard', 'District (Dashboard)', 'super/dashboard.php', 'speedometer2'], ['schools', 'Schools', 'super/schools.php', 'buildings'],
        ['exams', 'District Exams', 'super/exams.php', 'journal-text'], ['analysis', 'Results Analysis', 'super/results_analysis.php', 'bar-chart-line'],
        ['classes', 'Classes', 'super/classes.php', 'building'],
        ['subjects', 'Subjects', 'super/subjects.php', 'book'], ['grading', 'Grading', 'super/grading.php', 'sliders'],
        ['settings', 'Settings & Years', 'super/settings.php', 'gear'], ['profile', 'My Account', 'profile.php', 'person-circle']];
    if ($u && is_admin()) {
        $n = [['dashboard', 'Dashboard', 'admin/dashboard.php', 'speedometer2'], ['students', 'Students', 'admin/students.php', 'people-fill'],
              ['subjects', 'Subjects', 'admin/subjects.php', 'book'],
              ['teachers', 'Teachers & Users', 'admin/teachers.php', 'person-badge'], ['assign', 'Assignments', 'admin/assignments.php', 'diagram-3'],
              ['teach', 'Teaching', 'teaching/index.php', 'calendar3'],
              ['exams', 'Exams & Results', 'admin/exams.php', 'journal-text'],
              ['reportinfo', 'Report Info', 'admin/report_info.php', 'clipboard2-check'],
              ['settings', 'School Profile', 'admin/settings.php', 'gear']];
        if ($u['role'] === 'super') $n[] = ['schools', '« Back to District', 'super/dashboard.php?exit=1', 'arrow-left-circle'];
        $n[] = ['profile', 'My Account', 'profile.php', 'person-circle'];
        return $n;
    }
    if ($u) return [['dashboard', 'Dashboard', 'teacher/dashboard.php', 'speedometer2'], ['teach', 'Teaching', 'teaching/index.php', 'calendar3'],
                    ['results', 'Results', 'teacher/results_list.php', 'bar-chart'], ['profile', 'My Account', 'profile.php', 'person-circle']];
    if (student()) return [['dashboard', 'My Results', 'student/dashboard.php', 'journal-check'], ['profile', 'Change PIN', 'profile.php', 'key']];
    return [];
}
function layout_start($title, $active = '', $pageLabel = null) {
    if ($pageLabel === null) $pageLabel = $title;
    $u = user(); $s = student();
    $who = $u ? $u['full_name'] . ' (' . user_title($u) . ')' : ($s ? full_name($s) : '');
    $whoName = $u ? (string)$u['full_name'] : ($s ? full_name($s) : '');
    $whoRole = $u ? user_title($u) : ($s ? 'Student' : '');
    $words = preg_split('/\s+/', trim($whoName));
    $ch = function ($str) { return function_exists('mb_substr') ? mb_substr($str, 0, 1) : substr($str, 0, 1); };
    $whoIni = $words && $words[0] !== '' ? strtoupper($ch($words[0]) . (count($words) > 1 ? $ch(end($words)) : '')) : '';
    $items = nav_items();
    $sc = active_school();
    /* Kifupi cha jina la mfumo kwa topbar ya simu: huichukua herufi zilizo kwenye mabano, mf. "... (OASIS)". */
    $appName = system_name();
    $appShort = $appName;
    if (preg_match('/\(([^)]{2,14})\)\s*$/', $appName, $mm)) $appShort = strtoupper(trim($mm[1]));
    ?><!DOCTYPE html>
<html lang="sw"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboards | OASIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f3f5fb}
.side{width:250px;min-height:100vh;background:linear-gradient(180deg,#0b3d91 0%,#07285f 100%);position:fixed;top:0;left:0;z-index:100;box-shadow:2px 0 12px rgba(7,40,95,.18)}
.side .brand{padding:18px 20px;color:#fff;font-weight:700;font-size:0.8rem;background:rgba(0,0,0,.16);line-height:1.3;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:10px}
.side .brand img{width:46px;height:46px;object-fit:contain;background:transparent;border-radius:0;padding:0;flex:0 0 auto}
.side .brand .brand-text{min-width:0;font-size:.76rem}
.side .nav-link{color:rgba(255,255,255,.85);padding:10px 20px;display:flex;gap:10px;align-items:center;border-left:3px solid transparent;transition:background .15s ease,border-color .15s ease}
.side .nav-link:hover{background:rgba(255,255,255,.1);color:#fff}
.side .nav-link.active{background:rgba(255,255,255,.16);color:#fff;border-left-color:#ffc107;font-weight:600}
.main{margin-left:250px}
.topbar{background:#fff;border-bottom:1px solid #e6e9f2;padding:10px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.topbar-brand{display:flex;align-items:center;gap:12px;min-width:0;flex:1 1 auto;overflow:hidden}
.topbar-text{min-width:0;flex:1 1 auto;overflow:hidden}
.topbar-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#0b3d91,#2c6ed5);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex:0 0 auto;box-shadow:0 4px 10px rgba(11,61,145,.25)}
.topbar-logo{width:46px;height:46px;object-fit:contain;border-radius:12px;background:transparent;padding:0;flex:0 0 auto;box-shadow:0 4px 10px rgba(11,61,145,.18)}
.topbar-title{font-weight:800;color:#0b3d91;font-size:1.42rem;line-height:1.05;letter-spacing:.055em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar-sub{font-size:.76rem;color:#6c757d;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700}
.topbar-page{font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:#6c757d;font-weight:600}
.topbar-menu{display:none;align-items:center;justify-content:center;width:40px;height:40px;flex:0 0 auto;padding:0;border:1px solid #dbe4f4;border-radius:11px;background:#f5f8ff;color:#0b3d91;font-size:1.25rem;line-height:1;transition:background .15s ease,border-color .15s ease}
.topbar-menu:hover{background:#e9f0ff;border-color:#b9d0f0}
.topbar-menu:focus-visible{outline:0;box-shadow:0 0 0 4px rgba(44,110,213,.16)}
.offcanvas.main-nav{width:min(272px,84vw);border:0;background:linear-gradient(180deg,#0b3d91 0%,#07285f 100%);color:#fff}
.main-nav .offcanvas-header{padding:14px 16px;gap:10px;background:rgba(0,0,0,.16);border-bottom:1px solid rgba(255,255,255,.12)}
.main-nav .offcanvas-title{color:#fff;font-weight:700;font-size:.8rem;line-height:1.3;white-space:normal}
.main-nav .topbar-logo{width:40px;height:40px;padding:0;background:transparent;box-shadow:none}
.main-nav .nav-link{color:rgba(255,255,255,.85);padding:11px 18px;display:flex;gap:10px;align-items:center;border-left:3px solid transparent;font-size:.92rem}
.main-nav .nav-link:hover,.main-nav .nav-link:focus{background:rgba(255,255,255,.1);color:#fff}
.main-nav .nav-link.active{background:rgba(255,255,255,.16);color:#fff;border-left-color:#ffc107;font-weight:600}
.main-nav .nav-logout{color:#ffc9c9;margin-top:4px;border-top:1px solid rgba(255,255,255,.12)}
.main-nav .nav-logout:hover{background:rgba(255,80,80,.18);color:#fff}
.main-nav .btn-close{filter:invert(1) grayscale(1) brightness(2)}
.user-chip{display:inline-flex;align-items:center;gap:9px;background:linear-gradient(180deg,#ffffff,#f7faff);border:1px solid #e2e8f6;border-radius:999px;padding:4px 14px 4px 4px;text-decoration:none;color:#16203a;max-width:300px;
  transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}
.user-chip:hover{border-color:#b9d0f0;box-shadow:0 8px 20px -10px rgba(11,61,145,.5);transform:translateY(-1px);color:#0b3d91}
.user-chip:focus-visible{outline:0;box-shadow:0 0 0 4px rgba(44,110,213,.16);border-color:#8fb4ea}
.u-avatar{flex:0 0 34px;width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  font-size:.74rem;font-weight:800;letter-spacing:.02em;color:#fff;background:linear-gradient(140deg,#2c6ed5,#0b3d91);
  box-shadow:inset 0 0 0 2px rgba(255,255,255,.28),0 3px 9px rgba(11,61,145,.35)}
.u-meta{display:flex;flex-direction:column;min-width:0;line-height:1.15;text-align:left}
.u-name{font-size:.82rem;font-weight:700;color:inherit;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-role{display:inline-flex;align-items:center;gap:4px;font-size:.66rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#6b7a90;margin-top:1px}
.u-role i{font-size:.66rem;color:#0f9d6b}
@media(max-width:991.98px){.u-meta{display:none}.user-chip{padding:3px}}
.card{border:1px solid #e7ecf7;border-radius:15px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.card-header{background:#fff;border-bottom:1px solid #eef2fa;padding:.8rem 1.05rem;font-weight:600}
.table th{font-size:.72rem;background:#f6f8fd;color:#5b6b85;white-space:nowrap;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #e7ecf7}
.table td{font-size:.86rem;border-color:#eff2f9}
.table-hover tbody tr:hover{background:#f8fbff}
.badge{font-weight:600;letter-spacing:.01em}
.min-w-0{min-width:0}
.flash-top{position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:1080;width:min(560px,calc(100% - 24px));display:flex;flex-direction:column;gap:8px;pointer-events:none}
.flash-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #e2e9f6;border-radius:12px;padding:10px 12px;font-size:.85rem;color:#16203a;
  box-shadow:0 18px 38px -20px rgba(11,61,145,.6);animation:flashIn .28s ease-out both}
.flash-toast.hide{animation:flashOut .25s ease-in both}
.flash-ic{flex:0 0 26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem}
.flash-success{border-left:3px solid #1b7f4b}.flash-success .flash-ic{background:#1b7f4b}
.flash-danger{border-left:3px solid #c92a2a}.flash-danger .flash-ic{background:#c92a2a}
.flash-warning{border-left:3px solid #b06a00}.flash-warning .flash-ic{background:#b06a00}
.flash-info{border-left:3px solid #2c6ed5}.flash-info .flash-ic{background:#2c6ed5}
.flash-msg{flex:1 1 auto;line-height:1.35}
.flash-x{background:none;border:0;font-size:1.15rem;line-height:1;color:#8a99b0;padding:0 2px}
.flash-x:hover{color:#16203a}
@keyframes flashIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
@keyframes flashOut{to{opacity:0;transform:translateY(-8px)}}
.side .nav{overflow-y:auto;max-height:calc(100vh - 96px)}
@media(max-width:767px){.side{display:none}.main{margin-left:0}
  .topbar-menu{display:inline-flex}
  .topbar{padding:8px 14px;flex-wrap:nowrap;gap:10px}
  .topbar-brand{gap:9px}
  .topbar-logo{width:38px;height:38px;border-radius:10px;padding:0}
  .topbar-title{font-size:1.06rem;white-space:nowrap}
  .topbar-sub{font-size:.68rem;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
  .topbar-sub .mx-2{margin-left:.2rem!important;margin-right:.2rem!important}
  .topbar>.d-flex{gap:6px!important;flex:0 0 auto}
  .user-chip{max-width:52vw}}
@media print{.side,.topbar,.no-print{display:none!important}.main{margin-left:0!important}}
</style></head><body>
<div class="side"><div class="brand"><img src="<?php echo h(url('assets/img/oasis-logo.png')); ?>" alt="OASIS Logo"><div class="brand-text"><?php echo h(system_name()); ?></div></div>
  <ul class="nav flex-column mt-2">
  <?php foreach ($items as $it): ?><li><a class="nav-link <?php echo $active === $it[0] ? 'active' : ''; ?>" href="<?php echo h(url($it[2])); ?>"><i class="bi bi-<?php echo $it[3]; ?>"></i> <?php echo h($it[1]); ?></a></li><?php endforeach; ?>
    <li><a class="nav-link" href="<?php echo h(url('logout.php')); ?>"><i class="bi bi-box-arrow-left"></i> Logout</a></li>
  </ul></div>
<div class="main">
  <div class="topbar">
    <div class="topbar-brand">
      <button class="topbar-menu" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Fungua menyu"><i class="bi bi-list"></i></button>
      <img src="<?php echo h(url('assets/img/oasis-logo.png')); ?>" alt="OASIS Logo" class="topbar-logo">
      <div class="topbar-text">
        <div class="topbar-title" title="<?php echo h($appName); ?>"><?php echo h($appShort); ?></div>
        <div class="topbar-sub"><i class="bi bi-geo-alt-fill me-1"></i><?php echo h($sc['district'] ?? ''); ?><?php echo !empty($sc['region']) ? ', ' . h($sc['region']) : ''; ?><?php if (!empty($sc['centre_no'])): ?><span class="mx-2">|</span><?php echo h($sc['centre_no']); ?><span class="mx-2">-</span><?php echo h($sc ? $sc['name'] . ' SECONDARY SCHOOL' : ($u && $u['role'] === 'super' ? 'Ngazi ya Wilaya' : $title)); ?>
                      <?php endif; ?>
                </div>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="user-chip" href="<?php echo h(url('profile.php')); ?>" title="My Account">
        <span class="u-avatar"><?php echo h($whoIni); ?></span>
        <span class="u-meta">
          <span class="u-name"><?php echo h($whoName); ?></span>
          <span class="u-role"><i class="bi bi-patch-check-fill"></i> <?php echo h($whoRole); ?></span>
        </span>
      </a>
    </div>
  </div>
  <?php if ($u && $u['role'] === 'super' && $sc): ?><div class="bg-warning-subtle border-bottom px-4 py-1 small no-print fw-bold">Manage School: <b><?php echo h($sc['name']); ?></b> &middot; <a href="<?php echo h(url('super/dashboard.php?exit=1')); ?>">Back to DSEO Dashboard</a></div><?php endif; ?>
  <div class="offcanvas offcanvas-start main-nav no-print" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
    <div class="offcanvas-header">
      <img src="<?php echo h(url('assets/img/oasis-logo.png')); ?>" alt="OASIS Logo" class="topbar-logo">
      <div class="offcanvas-title" id="mobileNavLabel"><?php echo h(system_name()); ?></div>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" aria-label="Funga"></button>
    </div>
    <div class="offcanvas-body p-0">
      <ul class="nav flex-column">
      <?php foreach ($items as $it): ?><li><a class="nav-link <?php echo $active === $it[0] ? 'active' : ''; ?>" href="<?php echo h(url($it[2])); ?>"><i class="bi bi-<?php echo $it[3]; ?>"></i> <?php echo h($it[1]); ?></a></li><?php endforeach; ?>
        <li><a class="nav-link nav-logout" href="<?php echo h(url('logout.php')); ?>"><i class="bi bi-box-arrow-left"></i> Logout</a></li>
      </ul>
    </div>
  </div>
  <div class="container-fluid p-4">
<?php flash_toasts(); }
function layout_end() { ?>
  </div>
  <div class="text-center text-muted small py-3">&copy; <?php echo date('Y'); ?> <?php echo h(system_name()); ?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.flash-toast').forEach(function (t) {
  function kill() {
    if (t.classList.contains('hide')) return;
    t.classList.add('hide');
    setTimeout(function () { t.remove(); }, 260);
  }
  var x = t.querySelector('.flash-x');
  if (x) x.addEventListener('click', kill);
  setTimeout(kill, 4500);
});
</script>
</body></html>
<?php }
