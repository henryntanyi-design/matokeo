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
$logo    = url('assets/img/oasis-logo.png');

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
<style>
:root{--brand:#0b3d91;--brand2:#2c6ed5;--brand3:#0f9d6b;--ink:#16203a;--mut:#6b7a90;--line:#e2e9f6;--necta-width:1100px}
html{scroll-behavior:smooth}
body{font-family:"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);background:#f7f9fe}
a{text-decoration:none}
.container{width:100%;max-width:var(--necta-width);margin-left:auto;margin-right:auto;padding-left:18px;padding-right:18px}
/* Topbar: sticky glass, mstari wa gradient juu, upana sawa na content. */
.hm-nav{position:sticky;top:0;z-index:1030;background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(12px);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:9px 0;box-shadow:0 12px 30px -26px rgba(11,61,145,.7)}
.hm-nav:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--brand3))}
.hm-nav .navbar-brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--brand);
  font-size:.9rem;line-height:1.14;letter-spacing:-.01em;margin-right:6px}
.hm-nav .navbar-brand img{width:38px;height:38px;object-fit:contain;background:#fff;border-radius:11px;padding:3px;
  box-shadow:0 8px 18px -12px rgba(11,61,145,.85);flex:0 0 auto}
.hm-nav .navbar-nav{gap:4px}
.hm-nav .nav-link{font-size:.86rem;font-weight:600;color:#4a5872!important;border-radius:999px;padding:8px 14px!important;
  transition:color .18s,background .18s,box-shadow .18s}
.hm-nav .nav-link:hover{color:var(--brand)!important;background:#f1f6ff}
.hm-nav .nav-link.active{color:var(--brand)!important;background:linear-gradient(135deg,#eaf1ff,#f6faff);
  box-shadow:inset 0 0 0 1px #d9e6ff}
.hm-btn{background:linear-gradient(135deg,var(--brand2),var(--brand));color:#fff!important;font-weight:700;font-size:.85rem;
  border:0;border-radius:999px;padding:8px 18px;box-shadow:0 12px 22px -14px rgba(11,61,145,.95);
  transition:transform .16s,filter .16s}
.hm-btn:hover{filter:brightness(1.06);transform:translateY(-1px);color:#fff!important}
.hm-nav .navbar-toggler{border:1px solid var(--line);border-radius:10px;padding:6px 10px;box-shadow:none}
.hm-nav .navbar-toggler:focus{box-shadow:0 0 0 3px rgba(44,110,213,.15)}
.hm-hero{position:relative;overflow:hidden;color:#fff;padding:82px 0 96px;
  background:radial-gradient(780px 480px at 88% 8%,rgba(15,157,107,.32),transparent 60%),
             radial-gradient(700px 420px at 4% 96%,rgba(44,110,213,.35),transparent 60%),
             linear-gradient(155deg,#0a3a89 0%,#14479e 45%,#2c6ed5 100%)}
.hm-hero:before{content:"";position:absolute;inset:0;opacity:.5;pointer-events:none;
  background-image:radial-gradient(rgba(255,255,255,.16) 1px,transparent 1px);background-size:24px 24px}
.hm-hero .container{position:relative;z-index:1}
.hm-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:.69rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
  color:#dce9ff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:6px 13px}
.hm-hero h1{font-weight:800;font-size:clamp(2.1rem,4.4vw,3.35rem);line-height:1.1;letter-spacing:-.025em;margin:20px 0 16px}
.hm-hero p.hm-lead{font-size:1.02rem;line-height:1.7;color:rgba(255,255,255,.86);max-width:54ch}
.hm-hero .btn-hero{border-radius:12px;padding:.78rem 1.35rem;font-weight:700;font-size:.9rem;transition:transform .16s,filter .16s}
.hm-hero .btn-hero:hover{transform:translateY(-2px);filter:brightness(1.04)}
.hm-hero-card{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.24);border-radius:22px;padding:22px;
  box-shadow:0 26px 60px -24px rgba(0,0,0,.5);backdrop-filter:blur(5px)}
.hm-hero-card .mini{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.15)}
.hm-hero-card .mini:last-child{border-bottom:0}
.hm-hero-card .mini b{font-size:1.45rem;line-height:1}
.hm-strip{background:#fff;border-bottom:1px solid var(--line);padding:20px 0}
.hm-strip .row{row-gap:14px}
.hm-strip .stat{display:flex;align-items:center;gap:12px}
.hm-strip .stat .ic{width:42px;height:42px;border-radius:12px;background:#edf3ff;color:var(--brand);
  display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem;flex:0 0 auto}
.hm-strip .stat b{display:block;font-size:1.4rem;line-height:1.05;color:var(--brand);font-weight:800}
.hm-strip .stat span{display:block;font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}
.hm-latest{padding:26px 0 6px}
.hm-latest-title{font-size:.95rem;font-weight:800;color:var(--ink);margin:0}
.hm-latest-all{font-size:.8rem;font-weight:700;color:var(--brand2)}
.hm-latest-all:hover{color:var(--brand)}
.hm-latest-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.hm-latest-card{display:block;background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;
  transition:transform .16s,box-shadow .16s,border-color .16s}
.hm-latest-card:hover{transform:translateY(-2px);border-color:#b9cdf0;box-shadow:0 14px 28px -20px rgba(11,61,145,.6)}
.hm-latest-card .top{display:flex;align-items:center;gap:8px}
.hm-latest-card .exam{flex:1 1 auto;min-width:0;font-size:.82rem;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-type{flex:0 0 auto;font-size:.6rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
  border-radius:999px;padding:3px 8px;border:1px solid transparent;white-space:nowrap}
.hm-scope-district{color:#0b3d91;background:#eaf1ff;border-color:#cfe0ff}
.hm-scope-regional{color:#5b2bb8;background:#f1ebff;border-color:#ddd0fb}
.hm-scope-school{color:#12734a;background:#eaf7f0;border-color:#cfeee0}
.hm-latest-card .meta{display:block;font-size:.72rem;color:var(--mut);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-latest-card .cand{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.7rem;font-weight:700;
  color:var(--brand3);margin-top:8px;text-transform:uppercase;letter-spacing:.04em}
.hm-latest-empty{font-size:.85rem;color:var(--mut)}
.hm-latest-filter{border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);
  font-size:.78rem;font-weight:600;padding:6px 10px;cursor:pointer}
.hm-latest-filter:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12);outline:0}
.hm-section-soft{background:#f2f6ff}
.hm-section-tight{padding-top:0}
/* Hatua za matumizi */
.hm-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}
.hm-step{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;
  box-shadow:0 16px 34px -32px rgba(11,61,145,.6);transition:transform .18s,box-shadow .18s}
.hm-step:hover{transform:translateY(-3px);box-shadow:0 22px 40px -30px rgba(11,61,145,.7)}
.hm-step .num{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--brand2),var(--brand));color:#fff;font-weight:800;font-size:.85rem;margin-bottom:14px}
.hm-step h3{font-size:.98rem;font-weight:800;margin:0 0 6px;color:var(--ink)}
.hm-step p{font-size:.84rem;color:var(--mut);line-height:1.65;margin:0}
/* Viungo vya haraka */
.hm-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
.hm-quick a{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;
  transition:transform .18s,border-color .18s,box-shadow .18s}
.hm-quick a:hover{transform:translateY(-2px);border-color:#b9cdf0;box-shadow:0 16px 30px -24px rgba(11,61,145,.6)}
.hm-quick .ic{width:44px;height:44px;flex:0 0 44px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;
  font-size:1.15rem;color:var(--brand);background:#edf3ff}
.hm-quick .ic.green{color:#0f7d50;background:#e9f8f1}
.hm-quick .ic.amber{color:#9a5b00;background:#fff5e6}
.hm-quick strong{display:block;font-size:.9rem;color:var(--ink)}
.hm-quick span{display:block;font-size:.76rem;color:var(--mut)}
.hm-quick .go{margin-left:auto;color:var(--brand2)}
/* CTA ya mwisho */
.hm-cta{padding:0 0 52px}
.hm-cta-inner{position:relative;overflow:hidden;border-radius:22px;padding:32px;color:#fff;
  background:radial-gradient(600px 320px at 92% 10%,rgba(15,157,107,.35),transparent 60%),linear-gradient(135deg,#0a3a89,#2c6ed5);
  display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;box-shadow:0 26px 50px -30px rgba(11,61,145,.85)}
.hm-cta-inner h2{font-size:1.25rem;font-weight:800;margin:0 0 6px;letter-spacing:-.01em;color:#fff}
.hm-cta-inner p{margin:0;font-size:.88rem;color:rgba(255,255,255,.86)}
.hm-hero-card .mini span{font-size:.78rem;color:rgba(255,255,255,.78)}
.hm-hero-card .mini i{font-size:1.15rem;opacity:.9}
.hm-section{padding:72px 0}
.hm-kicker{font-size:.7rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--brand3)}
.hm-title{font-weight:800;font-size:clamp(1.65rem,3vw,2.2rem);letter-spacing:-.02em;margin:10px 0 10px}
.hm-desc{color:var(--mut);font-size:.95rem;line-height:1.7;max-width:64ch;margin-bottom:0}
.hm-feature{height:100%;border:1px solid var(--line);border-radius:18px;padding:24px;background:#fff;transition:transform .18s,box-shadow .18s}
.hm-feature:hover{transform:translateY(-4px);box-shadow:0 22px 44px -26px rgba(11,61,145,.45)}
.hm-feature .ic{width:46px;height:46px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:1.2rem;
  color:var(--brand);background:#edf3ff;margin-bottom:16px}
.hm-feature h3{font-size:1.02rem;font-weight:800;margin-bottom:8px}
.hm-feature p{font-size:.85rem;color:var(--mut);line-height:1.65;margin:0}
.hm-foot{background:#0a2f70;color:rgba(255,255,255,.72);font-size:.8rem;padding:26px 0}
.hm-foot a{color:rgba(255,255,255,.9);font-weight:600}
.hm-foot a:hover{color:#fff}
@media (max-width:767px){
  .hm-hero{padding:56px 0 64px}
  .hm-section{padding:52px 0}
  .hm-hero-card{margin-top:12px}
  .hm-nav{padding:7px 0}
  .container{padding-left:14px;padding-right:14px}
  .hm-nav .navbar-brand{font-size:.76rem;gap:8px;max-width:calc(100% - 62px)}
  .hm-nav .navbar-brand img{width:34px;height:34px;border-radius:9px}
  .hm-nav .navbar-brand span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}*{animation:none!important}*{transition:none!important}}
</style>
</head>
<body id="top">

<nav class="navbar navbar-expand-lg hm-nav">
  <div class="container">
    <a class="navbar-brand" href="<?php echo h(url('index.php')); ?>">
      <img src="<?php echo h($logo); ?>" alt="Logo">
      <span>OASIS</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hmMenu" aria-controls="hmMenu" aria-label="Open menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="hmMenu">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="#top"><i class="bi bi-house-door me-1"></i>Home</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>Public Results</a></li>
        <li class="nav-item"><a class="nav-link" href="#about"><i class="bi bi-info-circle me-1"></i>About</a></li>
        <li class="nav-item"><a class="nav-link" href="#how"><i class="bi bi-question-circle me-1"></i>How it works</a></li>
        <li class="nav-item ms-lg-3 mt-2 mt-lg-0"><a class="btn hm-btn" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a></li>
      </ul>
    </div>
  </div>
</nav>

<header class="hm-hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <span class="hm-eyebrow"><i class="bi bi-mortarboard-fill"></i> Results management system</span>
        <h1>Manage school results with ease.</h1>
        <p class="hm-lead">District and school exams, teachers' scores, student reports and results analysis &ndash; all in one secure system.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-light btn-hero" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a>
          <a class="btn btn-outline-light btn-hero" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>View published results</a>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="hm-hero-card">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="hm-eyebrow" style="background:rgba(255,255,255,.14)"><i class="bi bi-broadcast"></i> Live overview</span>
          </div>
          <div class="mini"><div><b><?php echo (int)$statExams; ?></b><span> published exams</span></div><i class="bi bi-journal-text"></i></div>
          <div class="mini"><div><b><?php echo (int)$statSchools; ?></b><span> schools with results</span></div><i class="bi bi-buildings"></i></div>
          <div class="mini"><div><b><?php echo (int)$statCandidates; ?></b><span> candidates with results</span></div><i class="bi bi-people"></i></div>
        </div>
      </div>
    </div>
  </div>
</header>

<section class="hm-strip" aria-label="Published exam statistics">
  <div class="container">
    <div class="row g-3">
      <div class="col-md-4"><div class="stat"><span class="ic"><i class="bi bi-journal-text"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statExams; ?>"><?php echo (int)$statExams; ?></b><span>Published exams</span></div></div></div>
      <div class="col-md-4"><div class="stat"><span class="ic"><i class="bi bi-buildings"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statSchools; ?>"><?php echo (int)$statSchools; ?></b><span>Schools with results</span></div></div></div>
      <div class="col-md-4"><div class="stat"><span class="ic"><i class="bi bi-people"></i></span><div><b class="stat-num" data-count="<?php echo (int)$statCandidates; ?>"><?php echo (int)$statCandidates; ?></b><span>Candidates with results</span></div></div></div>
    </div>
  </div>
</section>

<section class="hm-latest" aria-label="Latest published exams">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="hm-latest-title"><i class="bi bi-clock-history me-2"></i>Latest published exams</h2>
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
      <p class="hm-latest-empty mb-0"><i class="bi bi-hourglass-split me-1"></i><?php echo $latestYear !== '' ? 'No results published for ' . h($latestYear) . '.' : 'No results have been published yet.'; ?></p>
    <?php else: ?>
      <div class="hm-latest-grid">
        <?php foreach ($latest as $r): ?>
          <?php
            /* Wigo wa mtihani: Regional (jina la aina), District (super), au School. */
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

<section class="hm-section" id="about">
  <div class="container">
    <div class="text-center mb-5">
      <span class="hm-kicker">OASIS</span>
      <h2 class="hm-title">Everything a school needs</h2>
      <p class="hm-desc mx-auto">From student registration to publishing district results, the system brings together administrators, teachers and students.</p>
    </div>
    <div class="row g-3">
      <div class="col-md-4"><div class="hm-feature"><span class="ic"><i class="bi bi-bar-chart-line"></i></span><h3>Results &amp; analysis</h3><p>NECTA-style results, school rankings, candidate lists and subject-wise statistics.</p></div></div>
      <div class="col-md-4"><div class="hm-feature"><span class="ic"><i class="bi bi-file-earmark-text"></i></span><h3>Student reports</h3><p>Progress reports downloadable as PDF or Excel, with accurate information for every student.</p></div></div>
      <div class="col-md-4"><div class="hm-feature"><span class="ic"><i class="bi bi-shield-check"></i></span><h3>Secure records</h3><p>Role-based access for district officers, head teachers, teachers and students &ndash; each seeing only what they are allowed to.</p></div></div>
    </div>
  </div>
</section>

<section class="hm-section hm-section-soft" id="how">
  <div class="container">
    <div class="text-center mb-5">
      <span class="hm-kicker">Simple process</span>
      <h2 class="hm-title">How it works</h2>
      <p class="hm-desc mx-auto">Three steps from student records to published results the whole district can see.</p>
    </div>
    <div class="hm-steps">
      <div class="hm-step"><span class="num">1</span><h3>Register students &amp; teachers</h3><p>Import or add students, classes and teachers, then assign subjects to the right classes and streams.</p></div>
      <div class="hm-step"><span class="num">2</span><h3>Enter and verify scores</h3><p>Teachers enter marks subject by subject while the academic office checks totals, grades and divisions.</p></div>
      <div class="hm-step"><span class="num">3</span><h3>Analyse and publish</h3><p>Generate school, ward and district comparisons, then publish results and print reports as PDF or Excel.</p></div>
    </div>
  </div>
</section>

<section class="hm-section hm-section-tight">
  <div class="container">
    <div class="hm-quick">
      <a href="<?php echo h(url('login.php?t=student')); ?>">
        <span class="ic green"><i class="bi bi-person-badge"></i></span>
        <span><strong>Student portal</strong><span>Check your results with exam number and PIN</span></span>
        <i class="bi bi-arrow-right go"></i>
      </a>
      <a href="<?php echo h(url('login.php')); ?>">
        <span class="ic"><i class="bi bi-shield-lock"></i></span>
        <span><strong>Staff sign in</strong><span>Teachers, head teachers and district officers</span></span>
        <i class="bi bi-arrow-right go"></i>
      </a>
      <a href="<?php echo h(url('public_results.php')); ?>">
        <span class="ic amber"><i class="bi bi-bar-chart-line"></i></span>
        <span><strong>Published results</strong><span>Browse exams, centres and performance lists</span></span>
        <i class="bi bi-arrow-right go"></i>
      </a>
    </div>
  </div>
</section>

<section class="hm-cta">
  <div class="container">
    <div class="hm-cta-inner">
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
  <div class="container d-flex flex-wrap justify-content-between gap-2">
    <span>&copy; <?php echo h(date('Y')); ?> <?php echo h($sysName); ?></span>
    <span><a href="<?php echo h(url('login.php')); ?>">Staff sign in</a> &middot; <a href="#top">Back to top <i class="bi bi-arrow-up"></i></a></span>
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
</script>
</body>
</html>
