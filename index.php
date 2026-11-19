<?php
/** Public homepage: sign-in link + system overview. */
require_once __DIR__ . '/lib/bootstrap.php';

/* Keep the installer guard: if there are no users, run install.php. */
try {
    if ((int)q_val('SELECT COUNT(*) FROM users') === 0) redirect('install.php');
} catch (Exception $e) {
    /* Database unavailable: still show the page instead of failing. */
}

/* Signed-in users go to their dashboard, not to the public homepage. */
if (user() || student()) redirect(home_url());

$sysName = system_name();
/* Brand ya navbar: logo ya mfumo + jina la mfumo kwa kifupi (mf. "OASIS"). */
$appShort = $sysName;
if (preg_match('/\(([^)]{2,14})\)\s*$/', $sysName, $mm)) $appShort = strtoupper(trim($mm[1]));
$brandLogo = url('assets/img/oasis-logo.png');

/* Queries that cannot break the whole page if a table is missing (migrations). */
$safeAll = function ($sql, $params = []) {
    try { return q_all($sql, $params); } catch (Exception $e) { return []; }
};
$safeVal = function ($sql, $params = []) {
    try { return (int)q_val($sql, $params); } catch (Exception $e) { return 0; }
};

/* Published-result statistics (counts only - no student details). */
$statExams = $safeVal('SELECT COUNT(DISTINCT es.exam_id) FROM exam_schools es
                       JOIN exams e ON e.id = es.exam_id WHERE es.published = 1');
$statSchools = $safeVal('SELECT COUNT(DISTINCT es.school_id) FROM exam_schools es
                         JOIN schools sc ON sc.id = es.school_id AND sc.active = 1 WHERE es.published = 1');
$statCandidates = $safeVal('SELECT COUNT(DISTINCT ec.id) FROM exam_schools es
                            JOIN exam_candidates ec ON ec.exam_id = es.exam_id AND ec.school_id = es.school_id
                            WHERE es.published = 1');

/* System-wide registration totals (cards on the homepage). */
$statStaff     = $safeVal('SELECT COUNT(*) FROM users');
$statSchoolsAll = $safeVal('SELECT COUNT(*) FROM schools');
$statStudents  = $safeVal('SELECT COUNT(*) FROM students');
$statPublished = $safeVal('SELECT COUNT(*) FROM exam_schools WHERE published = 1');

/* Published exams za hivi karibuni kwa strip ndogo, + filter ya mwaka. */
$latestYear = trim((string)($_GET['year'] ?? ''));
if (!preg_match('/^\d{4}$/', $latestYear)) $latestYear = '';

$yearRows = $safeAll("SELECT DISTINCT y.name FROM academic_years y WHERE y.name IS NOT NULL AND y.name <> '' ORDER BY y.name DESC");
$latestYears = array_values(array_filter(array_column($yearRows, 'name'), function ($v) { return $v !== null && $v !== ''; }));
if (!$latestYears) {
    for ($y = (int)date('Y'); $y >= 2024; $y--) $latestYears[] = (string)$y;
}

$latestWhere = 'es.published = 1';
$latestParams = [];
if ($latestYear !== '') { $latestWhere .= ' AND y.name = ?'; $latestParams[] = $latestYear; }
$latest = $safeAll("SELECT e.id AS exam_id, e.name AS exam_name, e.exam_type, e.owner_school_id, y.name AS year_name,
       sc.id AS school_id, sc.name AS school_name, sc.centre_no,
       (SELECT COUNT(*) FROM exam_candidates ec WHERE ec.exam_id = es.exam_id AND ec.school_id = es.school_id) AS candidates
  FROM exam_schools es
  JOIN exams e ON e.id = es.exam_id
  JOIN schools sc ON sc.id = es.school_id AND sc.active = 1
  LEFT JOIN academic_years y ON y.id = e.academic_year_id
 WHERE " . $latestWhere . "
 ORDER BY e.id DESC, sc.name ASC
 LIMIT 6", $latestParams);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?php echo h($sysName); ?> &ndash; exam results, progress reports and school information.">
<title><?php echo h($sysName); ?> &middot; Home</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#0b3d91;--brand2:#2c6ed5;--brand3:#0f9d6b;
  --ink:#0f1b33;--ink2:#1f2d4a;--mut:#6b7a90;--mut2:#9aa6bc;
  --line:#e2e9f6;--line2:#edf2fa;
  --bg:#f7f9fe;--bg2:#eef3fb;
  --card:#ffffff;
  --radius:18px;--radius-sm:12px;
  --shadow-sm:0 6px 18px -12px rgba(11,61,145,.25);
  --shadow-md:0 18px 40px -22px rgba(11,61,145,.35);
  --shadow-lg:0 30px 70px -30px rgba(6,28,72,.45);
  --necta-width:1180px;
  --ease:cubic-bezier(.22,.61,.36,1);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--bg);margin:0;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}
.container{width:100%;max-width:var(--necta-width);margin-left:auto;margin-right:auto;padding-left:20px;padding-right:20px}

/* ---------- Navbar ---------- */
.hm-nav{position:sticky;top:0;z-index:1030;background:rgba(255,255,255,.78);-webkit-backdrop-filter:blur(16px) saturate(160%);backdrop-filter:blur(16px) saturate(160%);
  border-bottom:1px solid rgba(226,233,246,.7);padding:10px 0;transition:padding .25s var(--ease),box-shadow .25s var(--ease)}
.hm-nav.scrolled{padding:6px 0;box-shadow:0 12px 30px -20px rgba(11,61,145,.35)}
.hm-nav:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--brand3))}
.hm-nav .navbar-brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--brand);line-height:1.1;letter-spacing:.01em}
.hm-nav .navbar-brand>span:not(.brand-icon){white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-nav .navbar-brand .brand-logo{flex:0 0 auto;width:36px;height:36px;object-fit:contain;border-radius:10px;background:#fff;padding:2px;box-shadow:0 6px 14px -10px rgba(11,61,145,.9)}
.hm-nav .navbar-brand .brand-name{font-size:.95rem;font-weight:800;letter-spacing:.06em;color:#0b3d91}
.hm-nav .navbar-nav{gap:2px}
.hm-nav .nav-link{font-size:.88rem;font-weight:600;color:#4a5872!important;border-radius:999px;padding:8px 14px!important;transition:color .18s,background .18s,box-shadow .18s}
.hm-nav .nav-link:hover{color:var(--brand)!important;background:#f1f6ff}
.hm-nav .nav-link.active{color:var(--brand)!important;background:linear-gradient(135deg,#eaf1ff,#f6faff);box-shadow:inset 0 0 0 1px #d9e6ff}
.hm-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));color:#fff!important;font-weight:700;font-size:.85rem;border:0;border-radius:999px;padding:9px 20px;box-shadow:0 12px 22px -14px rgba(11,61,145,.95);transition:transform .18s,filter .18s,box-shadow .18s;display:inline-flex;align-items:center;gap:6px}
.hm-btn:hover{filter:brightness(1.06);transform:translateY(-1px);color:#fff!important;box-shadow:0 16px 26px -14px rgba(11,61,145,.95)}
.hm-nav .navbar-toggler{border:1px solid var(--line);border-radius:10px;padding:6px 10px}
.hm-nav .navbar-toggler:focus{box-shadow:0 0 0 3px rgba(44,110,213,.15)}

/* ---------- Hero ---------- */
.hm-hero{position:relative;overflow:hidden;color:#fff;padding:96px 0 110px;isolation:isolate}
.hm-hero-bg{position:absolute;inset:0;z-index:-2;background:linear-gradient(155deg,#062a6e 0%,#0b3d91 40%,#2c6ed5 85%,#3a7be0 100%)}
.hm-hero-blob{position:absolute;border-radius:50%;filter:blur(60px);opacity:.55;animation:blob 14s ease-in-out infinite;pointer-events:none}
.hm-hero-blob.b1{width:520px;height:520px;background:#0f9d6b;top:-120px;right:-120px;animation-delay:0s}
.hm-hero-blob.b2{width:420px;height:420px;background:#6aa6ff;bottom:-140px;left:-100px;animation-delay:-5s}
.hm-hero-blob.b3{width:300px;height:300px;background:#a8d0ff;top:40%;left:45%;animation-delay:-9s;opacity:.35}
@keyframes blob{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-20px) scale(1.08)}66%{transform:translate(-20px,30px) scale(.95)}}
.hm-hero-grid{position:absolute;inset:0;opacity:.35;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.22) 1px,transparent 1px);background-size:26px 26px;mask-image:radial-gradient(ellipse 80% 70% at 50% 50%,#000 40%,transparent 100%)}
.hm-hero .container{position:relative;z-index:1}
.hm-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#dce9ff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:7px 14px;backdrop-filter:blur(6px)}
.hm-hero h1{font-weight:800;font-size:clamp(2rem,4.6vw,3.5rem);line-height:1.08;letter-spacing:-.03em;margin:20px 0 16px}
.hm-hero p.hm-lead{font-size:1.05rem;line-height:1.7;color:rgba(255,255,255,.88);max-width:52ch}
.hm-hero .btn-hero{border-radius:12px;padding:.8rem 1.4rem;font-weight:700;font-size:.92rem;transition:transform .18s,filter .18s,box-shadow .18s;display:inline-flex;align-items:center;gap:6px}
.hm-hero .btn-hero:hover{transform:translateY(-2px);filter:brightness(1.04)}
.hm-hero-card{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.24);border-radius:24px;padding:24px;box-shadow:0 26px 60px -24px rgba(0,0,0,.5);backdrop-filter:blur(8px);position:relative;overflow:hidden}
.hm-hero-card:before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.08),transparent 60%);pointer-events:none}
.hm-hero-card .mini{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 0;border-bottom:1px solid rgba(255,255,255,.15);position:relative}
.hm-hero-card .mini:last-child{border-bottom:0}
.hm-hero-card .mini b{font-size:1.5rem;line-height:1;font-weight:800}
.hm-hero-card .mini span{font-size:.8rem;color:rgba(255,255,255,.78)}
.hm-hero-card .mini i{font-size:1.2rem;opacity:.9}
.hm-hero-card .live-dot{width:8px;height:8px;border-radius:50%;background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.6);animation:pulse 2s infinite;display:inline-block;margin-right:6px}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.6)}70%{box-shadow:0 0 0 10px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}

/* ---------- Stats strip ---------- */
.hm-strip{padding:0 0 4px}
.hm-stats-shell{max-width:1040px;margin:-72px auto 0;position:relative;z-index:2;padding:18px 16px 14px;background:linear-gradient(180deg,#ffffff 0%,#f7faff 100%);border:1px solid rgba(255,255,255,.85);border-radius:24px;box-shadow:inset 0 1px 0 rgba(255,255,255,.9),0 34px 70px -30px rgba(6,28,72,.45),0 4px 16px -10px rgba(11,61,145,.18);-webkit-backdrop-filter:blur(18px) saturate(150%);backdrop-filter:blur(18px) saturate(150%)}
.hm-stats-shell:before{content:"";position:absolute;left:40px;right:40px;top:-1px;height:3px;border-radius:0 0 4px 4px;background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--brand3))}
.hm-stats-head{display:flex;align-items:center;justify-content:center;gap:7px;padding:2px 0 12px;font-size:.66rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--mut)}
.hm-stats-head i{color:var(--brand2);font-size:.8rem}
.hm-stat-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}
.hm-stat-card{position:relative;display:flex;align-items:center;gap:14px;border-radius:14px;padding:14px 16px;transition:background .18s,transform .18s}
.hm-stat-card+.hm-stat-card:before{content:"";position:absolute;left:0;top:22%;bottom:22%;width:1px;background:var(--line)}
.hm-stat-card:hover{background:rgba(255,255,255,.85);transform:translateY(-2px)}
.hm-stat-card .ic{width:46px;height:46px;border-radius:14px;flex:0 0 auto;color:var(--brand);background:linear-gradient(135deg,#e9f1ff,#f6faff);display:inline-flex;align-items:center;justify-content:center;font-size:1.15rem;transition:transform .3s var(--ease)}
.hm-stat-card:hover .ic{transform:scale(1.08) rotate(-4deg)}
.hm-stat-card .ic.green{color:#0f7d50;background:linear-gradient(135deg,#e3f8ee,#f3fbf7)}
.hm-stat-card .ic.amber{color:#9a5b00;background:linear-gradient(135deg,#fff3e2,#fff9f1)}
.hm-stat-card .ic.violet{color:#5b2bb8;background:linear-gradient(135deg,#f0eaff,#f9f6ff)}
.hm-stat-card b{display:block;font-size:1.7rem;line-height:1.05;font-weight:800;letter-spacing:-.02em;background:linear-gradient(135deg,#0b3d91,#2c6ed5);-webkit-background-clip:text;background-clip:text;color:transparent}
.hm-stat-card span{display:block;font-size:.68rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mut)}

/* ---------- Latest published ---------- */
.hm-latest{padding:40px 0 8px}
.hm-latest-title{font-size:1.05rem;font-weight:800;color:var(--ink);margin:0;display:flex;align-items:center;gap:8px}
.hm-latest-all{font-size:.82rem;font-weight:700;color:var(--brand2);display:inline-flex;align-items:center;gap:4px;transition:gap .2s var(--ease)}
.hm-latest-all:hover{color:var(--brand);gap:7px}
.hm-latest-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.hm-latest-card{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;transition:transform .2s var(--ease),box-shadow .2s var(--ease),border-color .2s var(--ease);position:relative;overflow:hidden}
.hm-latest-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,var(--brand2),var(--brand3));opacity:0;transition:opacity .2s}
.hm-latest-card:hover{transform:translateY(-3px);border-color:#b9cdf0;box-shadow:0 16px 30px -20px rgba(11,61,145,.6)}
.hm-latest-card:hover:before{opacity:1}
.hm-latest-card .top{display:flex;align-items:center;gap:8px}
.hm-latest-card .exam{flex:1 1 auto;min-width:0;font-size:.86rem;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-type{flex:0 0 auto;font-size:.62rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;border-radius:999px;padding:3px 9px;border:1px solid transparent;white-space:nowrap}
.hm-scope-district{color:#0b3d91;background:#eaf1ff;border-color:#cfe0ff}
.hm-scope-regional{color:#5b2bb8;background:#f1ebff;border-color:#ddd0fb}
.hm-scope-school{color:#12734a;background:#eaf7f0;border-color:#cfeee0}
.hm-latest-card .meta{display:block;font-size:.74rem;color:var(--mut);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-latest-card .cand{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.72rem;font-weight:700;color:var(--brand3);margin-top:10px;text-transform:uppercase;letter-spacing:.04em}
.hm-latest-empty{font-size:.88rem;color:var(--mut)}
.hm-latest-filter{border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);font-size:.8rem;font-weight:600;padding:7px 11px;cursor:pointer;transition:border-color .18s,box-shadow .18s}
.hm-latest-filter:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12);outline:0}
.hm-section-soft{background:linear-gradient(180deg,#f2f6ff 0%,#eef3fb 100%)}
.hm-section-tight{padding-top:0}

/* ---------- Access section ---------- */
.hm-access-shell{display:flex;flex-wrap:wrap;gap:24px;align-items:stretch;border-radius:26px;padding:34px;color:#fff;background:radial-gradient(600px 320px at 92% 10%,rgba(15,157,107,.35),transparent 60%),linear-gradient(135deg,#0a3a89,#2c6ed5);box-shadow:0 26px 50px -30px rgba(11,61,145,.85);overflow:hidden;position:relative;isolation:isolate}
.hm-access-shell:before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.12) 1px,transparent 1px);background-size:22px 22px;opacity:.4;pointer-events:none}
.hm-access-body{flex:1 1 340px;min-width:0;position:relative}
.hm-access-body .hm-title{color:#fff;font-size:clamp(1.5rem,2.6vw,2rem)}
.hm-access-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:20px}
.hm-access-price{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:.9rem;color:#fff;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:9px 16px}
.hm-access-card{flex:0 0 300px;max-width:100%;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.24);border-radius:20px;padding:20px;backdrop-filter:blur(6px);display:flex;flex-direction:column;gap:14px;position:relative}
.hm-access-row{display:flex;align-items:center;gap:12px}
.hm-access-row i{width:42px;height:42px;flex:0 0 42px;border-radius:13px;background:rgba(255,255,255,.18);display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem;transition:transform .3s var(--ease)}
.hm-access-row:hover i{transform:scale(1.08)}
.hm-access-row div{display:flex;flex-direction:column;line-height:1.25}
.hm-access-row strong{font-size:.88rem;color:#fff}
.hm-access-row span{font-size:.78rem;color:rgba(255,255,255,.84)}

/* ---------- CTA ---------- */
.hm-cta{padding:0 0 56px}
.hm-cta-inner{position:relative;overflow:hidden;border-radius:24px;padding:36px;color:#fff;background:radial-gradient(600px 320px at 92% 10%,rgba(15,157,107,.35),transparent 60%),linear-gradient(135deg,#0a3a89,#2c6ed5);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;box-shadow:0 26px 50px -30px rgba(11,61,145,.85)}
.hm-cta-inner h2{font-size:1.3rem;font-weight:800;margin:0 0 6px;letter-spacing:-.01em;color:#fff}
.hm-cta-inner p{margin:0;font-size:.9rem;color:rgba(255,255,255,.86)}

/* ---------- Section base ---------- */
.hm-section{padding:76px 0}
.hm-kicker{font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--brand3)}
.hm-title{font-weight:800;font-size:clamp(1.65rem,3vw,2.25rem);letter-spacing:-.02em;margin:10px 0 10px;line-height:1.15}
.hm-desc{color:var(--mut);font-size:.96rem;line-height:1.7;max-width:64ch;margin-bottom:0}
.hm-foot{background:#0a2f70;color:rgba(255,255,255,.72);font-size:.82rem;padding:28px 0;position:relative}
.hm-foot:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--brand3))}
.hm-foot a{color:rgba(255,255,255,.9);font-weight:600;transition:color .18s}
.hm-foot a:hover{color:#fff}
.hm-foot .foot-grid{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:center}

/* ---------- Reveal animation ---------- */
.reveal{opacity:0;transform:translateY(22px);transition:opacity .7s var(--ease),transform .7s var(--ease)}
.reveal.in{opacity:1;transform:translateY(0)}
.reveal-stagger>*{opacity:0;transform:translateY(18px);transition:opacity .6s var(--ease),transform .6s var(--ease)}
.reveal-stagger.in>*{opacity:1;transform:translateY(0)}
.reveal-stagger.in>*:nth-child(1){transition-delay:.05s}
.reveal-stagger.in>*:nth-child(2){transition-delay:.12s}
.reveal-stagger.in>*:nth-child(3){transition-delay:.19s}
.reveal-stagger.in>*:nth-child(4){transition-delay:.26s}

/* ---------- Responsive ---------- */
@media (max-width:991px){
  .hm-stats-shell{max-width:720px;margin-top:-52px;padding:14px 12px 12px}
  .hm-stat-cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
  .hm-stat-card{border:1px solid var(--line);background:#fff}
  .hm-stat-card+.hm-stat-card:before{display:none}
  .hm-hero{padding:72px 0 84px}
  .hm-section{padding:60px 0}
}
@media (max-width:767px){
  .hm-hero{padding:56px 0 68px}
  .hm-section{padding:52px 0}
  .hm-hero-card{margin-top:12px}
  .hm-nav{padding:7px 0}
  .container{padding-left:16px;padding-right:16px}
  .hm-nav .navbar-brand{gap:8px;max-width:calc(100% - 62px)}
  .hm-nav .navbar-brand .brand-logo{width:32px;height:32px;border-radius:9px}
  .hm-nav .navbar-brand .brand-name{font-size:.88rem}
  .hm-access-card{flex:1 1 100%}
  .hm-access-shell{padding:26px 22px}
  .hm-cta-inner{padding:28px 22px}
  .hm-stats-shell{margin-top:-40px}
}
@media (max-width:479px){
  .hm-stats-shell{max-width:100%;margin-top:-32px;border-radius:20px}
  .hm-stat-cards{grid-template-columns:1fr}
  .hm-stat-card b{font-size:1.5rem}
  .hm-hero h1{font-size:1.9rem}
}
@media (prefers-reduced-motion:reduce){
  html{scroll-behavior:auto}
  *,*::before,*::after{animation:none!important;transition:none!important}
  .reveal,.reveal-stagger>*{opacity:1!important;transform:none!important}
}
</style>
</head>
<body id="top">

<nav class="navbar navbar-expand-lg hm-nav" id="hmNav">
  <div class="container">
    <a class="navbar-brand" href="<?php echo h(url('index.php')); ?>">
      <img class="brand-logo" src="<?php echo h($brandLogo); ?>" alt="OASIS Logo">
      <span class="brand-name"><?php echo h($appShort); ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hmMenu" aria-controls="hmMenu" aria-label="Open menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="hmMenu">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="#top"><i class="bi bi-house-door me-1"></i>Home</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>Public Results</a></li>
        <li class="nav-item ms-lg-3 mt-2 mt-lg-0"><a class="btn hm-btn" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a></li>
      </ul>
    </div>
  </div>
</nav>

<header class="hm-hero">
  <div class="hm-hero-bg"></div>
  <div class="hm-hero-blob b1"></div>
  <div class="hm-hero-blob b2"></div>
  <div class="hm-hero-blob b3"></div>
  <div class="hm-hero-grid"></div>
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7 reveal">
        <span class="hm-eyebrow"><i class="bi bi-mortarboard-fill"></i> Results management system</span>
        <h1>Manage school results with clarity and confidence.</h1>
        <p class="hm-lead">District and school exams, teachers' scores, student reports and results analysis &ndash; all in one secure system built for Tanzanian schools.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-light btn-hero" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a>
          <a class="btn btn-outline-light btn-hero" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>View published results</a>
        </div>
      </div>
      <div class="col-lg-5 reveal">
        <div class="hm-hero-card">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="hm-eyebrow" style="background:rgba(255,255,255,.14)"><span class="live-dot"></span><i class="bi bi-broadcast"></i> Live overview</span>
          </div>
          <div class="mini"><div><b><?php echo (int)$statExams; ?></b><span> published exams</span></div><i class="bi bi-journal-text"></i></div>
          <div class="mini"><div><b><?php echo (int)$statSchools; ?></b><span> schools with results</span></div><i class="bi bi-buildings"></i></div>
          <div class="mini"><div><b><?php echo (int)$statCandidates; ?></b><span> candidates with results</span></div><i class="bi bi-people"></i></div>
        </div>
      </div>
    </div>
  </div>
</header>

<section class="hm-strip" aria-label="System statistics">
  <div class="container">
    <div class="hm-stats-shell reveal">
      <div class="hm-stats-head"><i class="bi bi-activity"></i>System at a glance</div>
      <div class="hm-stat-cards reveal-stagger">
        <div class="hm-stat-card"><span class="ic"><i class="bi bi-person-badge"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statStaff; ?>"><?php echo (int)$statStaff; ?></b><span>Registered staff</span></div></div>
        <div class="hm-stat-card"><span class="ic amber"><i class="bi bi-buildings"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statSchoolsAll; ?>"><?php echo (int)$statSchoolsAll; ?></b><span>Registered schools</span></div></div>
        <div class="hm-stat-card"><span class="ic green"><i class="bi bi-people-fill"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statStudents; ?>"><?php echo (int)$statStudents; ?></b><span>Registered students</span></div></div>
        <div class="hm-stat-card"><span class="ic violet"><i class="bi bi-journal-check"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statPublished; ?>"><?php echo (int)$statPublished; ?></b><span>Published results</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="hm-latest" aria-label="Latest published exams">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 reveal">
      <h2 class="hm-latest-title"><i class="bi bi-clock-history"></i>Latest published exams</h2>
      <div class="d-flex align-items-center gap-2">
        <label class="visually-hidden" for="hmLatestYear">Filter by year</label>
        <form method="get" action="<?php echo h(url('index.php')); ?>" class="d-flex">
          <select class="hm-latest-filter" id="hmLatestYear" name="year" onchange="this.form.submit()">
            <option value="">All years</option>
            <?php foreach ($latestYears as $yv): ?>
              <option value="<?php echo h($yv); ?>"<?php echo $latestYear === (string)$yv ? ' selected' : ''; ?>><?php echo h($yv); ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm btn-outline-primary ms-1" type="submit">Filter</button></noscript>
        </form>
        <a class="hm-latest-all" href="<?php echo h(url('public_results.php' . ($latestYear !== '' ? '?year=' . rawurlencode($latestYear) : ''))); ?>">View all <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
    <?php if (!$latest): ?>
      <p class="hm-latest-empty mb-0 reveal"><i class="bi bi-hourglass-split me-1"></i><?php echo $latestYear !== '' ? 'No results published for ' . h($latestYear) . '.' : 'No results have been published yet.'; ?></p>
    <?php else: ?>
      <div class="hm-latest-grid reveal-stagger">
        <?php foreach ($latest as $r): ?>
          <?php
            $t = strtolower((string)($r['exam_type'] ?? ''));
            if (strpos($t, 'regional') !== false) {
                $scope = 'Regional'; $scopeClass = 'hm-scope-regional';
            } elseif (strpos($t, 'district') !== false || empty($r['owner_school_id'])) {
                $scope = 'District'; $scopeClass = 'hm-scope-district';
            } else {
                $scope = 'School'; $scopeClass = 'hm-scope-school';
            }
          ?>
          <a class="hm-latest-card" href="<?php echo h(url('public_results.php?exam_id=' . (int)$r['exam_id'] . '&school_id=' . (int)$r['school_id'])); ?>">
            <span class="top">
              <span class="exam"><?php echo h($r['exam_name']); ?></span>
              <span class="hm-type <?php echo $scopeClass; ?>" title="<?php echo h($r['exam_type'] ?: $scope); ?>"><?php echo h($scope); ?></span>
            </span>
            <span class="meta"><?php echo h($r['school_name']); ?><?php echo $r['centre_no'] ? ' &middot; ' . h($r['centre_no']) : ''; ?><?php echo $r['year_name'] ? ' &middot; ' . h($r['year_name']) : ''; ?><?php echo $r['exam_type'] ? ' &middot; ' . h($r['exam_type']) : ''; ?></span>
            <span class="cand"><span><?php echo (int)$r['candidates']; ?> candidates</span><i class="bi bi-arrow-right"></i></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="hm-section" id="access" style="padding-bottom:24px">
  <div class="container">
    <div class="hm-access-shell reveal">
      <div class="hm-access-body">
        <span class="hm-kicker" style="color:var(--brand2)">Registration &amp; access</span>
        <h2 class="hm-title">Need to use this system?</h2>
        <p class="hm-desc" style="color:rgba(255,255,255,.86);max-width:58ch">If you would like to register your school or organisation on the system, please call us to request access. Registration is approved on request and is billed at Tsh. 20,000 per year. Payments can be made through the phone number above or to NMB bank account 35910007554 (Christopher Henry Ntanyi).</p>
        <div class="hm-access-actions">
          <a class="btn btn-light btn-hero fw-bold" href="tel:+255743685170"><i class="bi bi-telephone-fill me-1"></i>Call +255 743 685 170</a>
          <span class="hm-access-price"><i class="bi bi-cash-coin me-1"></i>Tsh. 20,000 / year</span>
        </div>
      </div>
      <div class="hm-access-card">
        <div class="hm-access-row"><i class="bi bi-telephone-fill"></i><div><strong>Phone</strong><span>+255 743 685 170</span></div></div>
        <div class="hm-access-row"><i class="bi bi-person-check-fill"></i><div><strong>How to register</strong><span>Call and request access to be registered on the system</span></div></div>
        <div class="hm-access-row"><i class="bi bi-calendar-check"></i><div><strong>Cost</strong><span>Tsh. 20,000 per year</span></div></div>
        <div class="hm-access-row"><i class="bi bi-bank"></i><div><strong>Payment (NMB bank)</strong><span>35910007554 &middot; Christopher Henry Ntanyi</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="hm-cta">
  <div class="container">
    <div class="hm-cta-inner reveal">
      <div>
        <h2>Ready to publish this term&rsquo;s results?</h2>
        <p>Sign in to manage scores, analysis and reports &ndash; or view results already published.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-light btn-hero fw-bold" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a>
        <a class="btn btn-outline-light btn-hero" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>Browse results</a>
      </div>
    </div>
  </div>
</section>

<footer class="hm-foot">
  <div class="container foot-grid">
    <span>&copy; <?php echo h(date('Y')); ?> <?php echo h($sysName); ?></span>
    <span><a href="tel:+255743685170"><i class="bi bi-telephone me-1"></i>+255 743 685 170</a> &middot; <a href="<?php echo h(url('login.php')); ?>">Staff sign in</a> &middot; <a href="#top">Back to top <i class="bi bi-arrow-up"></i></a></span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Takwimu za strip: namba zinapanda kutoka 0 hadi thamani halisi. */
(function () {
  var nums = document.querySelectorAll('.stat-num');
  if (!nums.length || !window.requestAnimationFrame) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  Array.prototype.forEach.call(nums, function (el) {
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    if (target <= 0) return;
    var start = null;
    var duration = 900;
    el.textContent = '0';
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min(1, (ts - start) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });
})();

/* Navbar shadow on scroll */
(function () {
  var nav = document.getElementById('hmNav');
  if (!nav) return;
  var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 12); };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();

/* Reveal on scroll */
(function () {
  if (!('IntersectionObserver' in window)) {
    document.querySelectorAll('.reveal,.reveal-stagger').forEach(function (el) { el.classList.add('in'); });
    return;
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.reveal,.reveal-stagger').forEach(function (el) { el.classList.add('in'); });
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal,.reveal-stagger').forEach(function (el) { io.observe(el); });
})();
</script>
</body>
</html>
