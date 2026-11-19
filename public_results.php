<?php
/** Public results portal - MSSIS-style published exams and exam detail. */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/grading.php';
require_once __DIR__ . '/lib/render.php';

$sysName = system_name();
/* Brand ya navbar: jina la shule + "Secondary School" (muted). */
list($brandName, $brandSub) = school_brand_parts();
if ($brandName === '') { $brandName = $sysName; $brandSub = ''; }
$brandLogo = school_logo_src();

$safeAll = function ($sql, $params = []) {
    try { return q_all($sql, $params); } catch (Exception $e) { return []; }
};
$safeVal = function ($sql, $params = []) {
    try { return q_val($sql, $params); } catch (Exception $e) { return null; }
};

/* -------------------- Request parameters -------------------- */
$year    = trim((string)($_GET['year'] ?? ''));
$type    = (string)($_GET['type'] ?? '');
$search  = trim((string)($_GET['search'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 50;

/* Exam detail parameters (MSSIS-style) */
$examId      = (int)($_GET['exam_id'] ?? 0);
$classId     = (int)($_GET['class_id'] ?? 0);
$view        = (string)($_GET['view'] ?? '');
$schoolViewId = (int)($_GET['school_id'] ?? 0);
/* NECTA-style alphabetical centre filter */
$letter = strtoupper(trim((string)($_GET['letter'] ?? '')));
if ($letter !== '' && !preg_match('/^[A-Z]$/', $letter)) $letter = '';

/* -------------------- Published data (stats & years) -------------------- */
$statSchools = (int)$safeVal('SELECT COUNT(DISTINCT es.school_id) FROM exam_schools es
    JOIN schools sc ON sc.id = es.school_id AND sc.active = 1 WHERE es.published = 1');
$statCandidates = (int)$safeVal('SELECT COUNT(DISTINCT ec.id) FROM exam_schools es
    JOIN exam_candidates ec ON ec.exam_id = es.exam_id AND ec.school_id = es.school_id
    WHERE es.published = 1');

$yearRows = $safeAll('SELECT DISTINCT y.name FROM academic_years y
    JOIN exams e ON e.academic_year_id = y.id ORDER BY y.name DESC');
$yearOptions = array_values(array_filter(array_column($yearRows, 'name'), function ($v) { return $v !== null && $v !== ''; }));
if (!$yearOptions) {
    for ($y = (int)date('Y'); $y >= 2024; $y--) $yearOptions[] = (string)$y;
}

/* -------------------- Exam detail (MSSIS-style) -------------------- */
$exam          = $examId ? get_exam($examId) : null;
$detailClasses = [];
$detailRows    = [];   // flat candidate list (CSV)
$schoolResults = [];   // schoolId => compute_results()
$pubSchools    = [];
$councilRows = []; $subjectAgg = []; $bestRows = [];
$passRate = null;

if ($exam) {
    $detailClasses = exam_class_list($examId);
    if (!$classId && $detailClasses) $classId = (int)$detailClasses[0]['id'];

    $allPub = array_values(array_filter(exam_school_list($examId), function ($s) {
        return (int)$s['published'] === 1 && (int)$s['active'] === 1;
    }));

    // Apply the NECTA-style alphabetical letter filter
    foreach ($allPub as $sc) {
        if ($letter !== '') {
            $first = strtoupper(substr(ltrim((string)($sc['name'] ?? '')), 0, 1));
            if ($first !== $letter) continue;
        }
        $pubSchools[] = $sc;
    }

    foreach ($pubSchools as $sc) {
        $d = compute_results($examId, $classId, (int)$sc['id']);
        if (!$d) continue;
        $schoolResults[(int)$sc['id']] = $d;
        foreach ($d['candidates'] as $c) {
            $detailRows[] = [
                'cand_no'  => (string)($c['cand_no'] ?: $c['prems_no']),
                'name'     => $c['name'],
                'sex'      => $c['sex'],
                'school'   => $d['school']['school_name'],
                'district' => $d['school']['district'],
                'division' => $c['division'],
                'points'   => $c['points'],
                'average'  => $c['average'],
                'position' => isset($c['position']) ? $c['position'] : null,
                'size'     => isset($c['class_size']) ? $c['class_size'] : null,
            ];
        }
    }
    usort($detailRows, function ($a, $b) { return strnatcmp($a['cand_no'], $b['cand_no']); });

    $graded = 0; $passed = 0;
    foreach ($detailRows as $r) {
        if (in_array($r['division'], ['I', 'II', 'III', 'IV', '0'], true)) {
            $graded++;
            if ($r['division'] !== '0') $passed++;
        }
    }
    if ($graded) $passRate = round($passed * 100 / $graded, 1);

    // School ranking (General Schools' Results / Schools Ranking)
    foreach ($schoolResults as $sid => $d) {
        $r = ['school_id' => $sid, 'name' => $d['school']['school_name'], 'centre' => $d['necta_no'],
              'region' => $d['school']['region'], 'district' => $d['school']['district'],
              'registered' => count($d['candidates']),
              'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0, 'INC' => 0, 'ABS' => 0, 'PENDING' => 0,
              'ok' => 0, 'ptsSum' => 0, 'avg_points' => null, 'pass_pct' => null, 'rank' => null];
        foreach ($d['candidates'] as $c) {
            if ($c['state'] === 'OK') { $r[$c['division']]++; $r['ok']++; $r['ptsSum'] += $c['points']; }
            elseif ($c['state'] === 'INC') $r['INC']++;
            elseif ($c['state'] === 'ABS') $r['ABS']++;
            else $r['PENDING']++;
        }
        $r['avg_points'] = $r['ok'] ? round($r['ptsSum'] / $r['ok'], 2) : null;
        $r['pass_pct']   = $r['ok'] ? round(($r['I'] + $r['II'] + $r['III'] + $r['IV']) * 100 / $r['ok'], 1) : null;
        $councilRows[] = $r;
    }
    usort($councilRows, function ($a, $b) {
        if ($a['avg_points'] === null && $b['avg_points'] === null) return strcmp($a['name'], $b['name']);
        if ($a['avg_points'] === null) return 1;
        if ($b['avg_points'] === null) return -1;
        if ($a['avg_points'] != $b['avg_points']) return $a['avg_points'] <=> $b['avg_points'];
        return $b['I'] <=> $a['I'];
    });
    $rk = 0;
    foreach ($councilRows as $i => $r) if ($r['avg_points'] !== null) $councilRows[$i]['rank'] = ++$rk;

    // Subject aggregate + best students
    $subjMap = [];
    foreach ($schoolResults as $d) {
        foreach ($d['subjStats'] as $s) {
            $key = $s['abbr'];
            if (!isset($subjMap[$key])) {
                $subjMap[$key] = ['abbr' => $key, 'name' => $s['name'], 'sat' => 0, 'scoreSum' => 0,
                                  'pass' => 0, 'ptsSum' => 0, 'ptsN' => 0, 'grades' => []];
            }
            $subjMap[$key]['sat']      += $s['sat'];
            $subjMap[$key]['scoreSum'] += $s['scoreSum'];
            $subjMap[$key]['pass']     += $s['pass'];
            $subjMap[$key]['ptsSum']   += $s['ptsSum'];
            $subjMap[$key]['ptsN']     += $s['ptsN'];
            foreach ($s['grades'] as $g => $n) {
                $subjMap[$key]['grades'][$g] = (isset($subjMap[$key]['grades'][$g]) ? $subjMap[$key]['grades'][$g] : 0) + $n;
            }
        }
        foreach ($d['candidates'] as $c) {
            if ($c['state'] !== 'OK') continue;
            $bestRows[] = ['cand_no' => $c['cand_no'], 'name' => $c['name'], 'sex' => $c['sex'],
                           'school' => $d['school']['school_name'], 'division' => $c['division'],
                           'points' => $c['points'], 'average' => $c['average']];
        }
    }
    foreach ($subjMap as $key => $a) {
        $a['avg']      = $a['sat']  ? round($a['scoreSum'] / $a['sat'], 1) : null;
        $a['pass_pct'] = $a['sat']  ? round($a['pass'] * 100 / $a['sat'], 1) : null;
        $a['gpa']      = $a['ptsN'] ? round($a['ptsSum'] / $a['ptsN'], 4) : null;
        $subjectAgg[$key] = $a;
    }
    usort($subjectAgg, function ($a, $b) {
        if ($a['gpa'] === null && $b['gpa'] === null) return 0;
        if ($a['gpa'] === null) return 1;
        if ($b['gpa'] === null) return -1;
        if ($a['gpa'] == $b['gpa']) return 0;
        return ($a['gpa'] < $b['gpa']) ? -1 : 1;
    });
    usort($bestRows, function ($a, $b) {
        if ($a['points'] !== $b['points']) return $a['points'] <=> $b['points'];
        return $b['average'] <=> $a['average'];
    });
    $bestRows = array_slice($bestRows, 0, 10);
    foreach ($bestRows as $i => $b) $bestRows[$i]['pos'] = $i + 1;
}

/* URL helper that keeps the current exam, class and letter selection */
$du = function ($extra = []) use ($examId, $classId, $letter) {
    $base = ['exam_id' => $examId, 'class_id' => $classId, 'letter' => $letter];
    return url('public_results.php?' . http_build_query(array_merge($base, $extra)));
};

/* -------------------- Listing query -------------------- */
$where  = ['es.published = 1', 'sc.active = 1'];
$params = [];
if ($year !== '' && preg_match('/^\d{4}$/', $year)) { $where[] = 'y.name = ?'; $params[] = $year; }
if ($type === 'district') $where[] = 'e.owner_school_id IS NULL';
if ($type === 'school')   $where[] = 'e.owner_school_id IS NOT NULL';
if ($search !== '') {
    $where[] = '(e.name LIKE ? OR sc.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$total = 0;
try {
    $total = (int)q_val("SELECT COUNT(DISTINCT e.id) FROM exams e
        JOIN exam_schools es ON es.exam_id = e.id
        JOIN schools sc ON sc.id = es.school_id
        LEFT JOIN academic_years y ON y.id = e.academic_year_id
        WHERE $whereSql", $params);
} catch (Exception $e) {}

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$exams = $safeAll("SELECT e.id, e.name, e.exam_type, e.start_date, e.end_date, e.owner_school_id,
       y.name AS year_name, own.name AS owner_name, COUNT(DISTINCT es.school_id) AS schools
  FROM exams e
  JOIN exam_schools es ON es.exam_id = e.id
  JOIN schools sc ON sc.id = es.school_id
  LEFT JOIN schools own ON own.id = e.owner_school_id
  LEFT JOIN academic_years y ON y.id = e.academic_year_id
  WHERE $whereSql
  GROUP BY e.id
  ORDER BY e.id DESC
  LIMIT $perPage OFFSET $offset", $params);

/* Buffer the page so a PDF request can reuse the exact rendered result sheet. */
if (($_GET['export'] ?? '') === 'pdf') ob_start();

/* -------------------- CSV export -------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="published-results-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($exam) {
        fputcsv($out, ['S/N', 'Index Number', 'Name', 'Sex', 'School', 'District', 'Division', 'Points', 'Average', 'Position']);
        foreach ($detailRows as $i => $r) {
            fputcsv($out, [$i + 1, $r['cand_no'], $r['name'], $r['sex'], $r['school'], $r['district'],
                           $r['division'], $r['points'], $r['average'], $r['position'] ? $r['position'] . '/' . $r['size'] : '']);
        }
    } else {
        fputcsv($out, ['S/N', 'Exam', 'Type', 'Year', 'Start Date', 'End Date', 'Published Schools']);
        foreach ($exams as $i => $e) {
            fputcsv($out, [$offset + $i + 1, $e['name'], pr_exam_scope($e)[0],
                           $e['year_name'], $e['start_date'], $e['end_date'], $e['schools']]);
        }
    }
    fclose($out);
    exit();
}

/** Wigo wa mtihani: Regional (jina la aina), District (super), au School. */
function pr_exam_scope(array $e) {
    $t = strtolower((string)($e['exam_type'] ?? ''));
    if (strpos($t, 'regional') !== false) return ['Regional', 'pr-scope-regional'];
    if (strpos($t, 'district') !== false || empty($e['owner_school_id'])) return ['District', 'pr-scope-district'];
    return ['School', 'pr-scope-school'];
}

function pr_div_badge($div) {
    $div = (string)$div;
    $map = [
        'I'   => ['bg-success', 'I'], 'II' => ['bg-primary', 'II'], 'III' => ['bg-info text-dark', 'III'],
        'IV'  => ['bg-warning text-dark', 'IV'], '0' => ['bg-danger', '0'],
        'INC' => ['bg-secondary', 'INC'], 'ABS' => ['bg-dark', 'ABS'],
    ];
    if (!isset($map[$div])) return '<span class="badge bg-light text-muted border">' . h($div ?: '-') . '</span>';
    return '<span class="badge ' . $map[$div][0] . '">' . h($map[$div][1]) . '</span>';
}
function pr_page_url($params) {
    return url('public_results.php' . ($params ? '?' . http_build_query($params) : ''));
}
/** NECTA-style centre label: centre number + school name + SECONDARY SCHOOL. */
function pr_school_label($centreNo, $name) {
    $name = trim((string)$name);
    if ($name !== '' && !preg_match('/\b(SCHOOL|SECONDARY|SHULE)\b/i', $name)) $name .= ' SECONDARY SCHOOL';
    return strtoupper(trim((string)$centreNo . ' ' . $name));
}

/** Six centred title lines used on the school result sheet and its PDF. */
function pr_sheet_header_lines(array $d) {
    $region = strtoupper(trim((string)($d['school']['region'] ?? '')));
    if ($region !== '' && strpos($region, 'REGION') === false) $region .= ' REGION';

    $district = strtoupper(trim((string)($d['school']['district'] ?? '')));
    $district = trim(preg_replace('/\b(DISTRICT COUNCIL|DC)\b/', '', $district));
    if ($district !== '') $district .= ' DISTRICT COUNCIL';

    $examLine = strtoupper(trim((string)($d['class']['name'] ?? ''))) . ' '
              . strtoupper(trim((string)($d['exam']['exam_name'] ?? ''))) . ', '
              . strtoupper(trim((string)($d['exam']['exam_month'] ?? ''))) . ' '
              . trim((string)($d['exam']['exam_year'] ?? ''));
    $examLine = trim(preg_replace('/\s+/', ' ', $examLine));

    return [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        $region,
        $district,
        $examLine,
        pr_school_label($d['school']['necta_no'] ?? '', $d['school']['school_name'] ?? ''),
    ];
}

/* Print a small table with a heading, used by the analysis views. */
function pr_table($headers, $rows) {
    if (!$rows) return '<p class="text-muted fst-italic">No data available.</p>';
    $html = '<div class="table-responsive"><table class="table table-sm table-bordered table-hover mb-0"><thead><tr>';
    foreach ($headers as $h) $html .= '<th>' . h($h) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . $cell . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Published exam results - <?php echo h($sysName); ?>">
<title>Published Results | <?php echo h($sysName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--brand:#0b3d91;--brand2:#2c6ed5;--brand3:#0f9d6b;--green:#0f9d6b;--line:#e3e9f5;--line2:#edf2fa;--mut:#6b7a90;--mut2:#9aa6bc;--ink:#0f1b33;--ink2:#1f2d4a;--purple:#800080;--radius:18px;--radius-sm:12px;--shadow-sm:0 6px 18px -12px rgba(11,61,145,.25);--shadow-md:0 18px 40px -22px rgba(11,61,145,.35);--shadow-lg:0 30px 70px -30px rgba(6,28,72,.45);--necta-width:1180px;--ease:cubic-bezier(.22,.61,.36,1)}
body{background:#f6f8fd;font-family:"Plus Jakarta Sans",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;display:flex;flex-direction:column;min-height:100vh}
main.container{flex:1 0 auto}
a{text-decoration:none}
html{scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}*{animation:none!important}*{transition:none!important}}
/* Topbar: sticky, glass, upana sawa na content ya main wrapper. */
.pr-nav{position:sticky;top:0;z-index:1030;background:rgba(255,255,255,.78);-webkit-backdrop-filter:blur(16px) saturate(160%);
  backdrop-filter:blur(16px) saturate(160%);border-bottom:1px solid rgba(226,233,246,.7);padding:10px 0;box-shadow:0 12px 30px -20px rgba(11,61,145,.35);transition:padding .25s var(--ease),box-shadow .25s var(--ease)}
.pr-nav.scrolled{padding:6px 0}
.pr-nav:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--green))}
.pr-nav>.container,main.container,.pr-foot-bar>.container{width:100%;max-width:var(--necta-width);
  margin-left:auto;margin-right:auto;padding-left:18px;padding-right:18px}
main.container .container{max-width:none;padding-left:0;padding-right:0}
.pr-nav .navbar-brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--brand);
  line-height:1.14;letter-spacing:.01em;margin-right:6px}
.pr-nav .navbar-brand>span:not(.brand-icon){white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pr-nav .navbar-brand .brand-icon{flex:0 0 auto;width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand2));box-shadow:0 6px 14px -8px rgba(11,61,145,.8)}
.pr-nav .navbar-brand .brand-logo{flex:0 0 auto;width:34px;height:34px;object-fit:contain;border-radius:9px;background:#fff;padding:2px;box-shadow:0 6px 14px -10px rgba(11,61,145,.9)}
.pr-nav .navbar-brand .brand-name{font-size:.8rem;font-weight:800;color:#0b3d91}
.pr-nav .navbar-brand .brand-sub{font-size:.8rem;font-weight:800;color:#0b3d91;margin-left:6px}
.pr-nav .navbar-nav{gap:4px}
.pr-nav .nav-link{font-weight:600;color:#4a5872!important;border-radius:999px;padding:8px 14px!important;
  transition:color .18s,background .18s,box-shadow .18s}
.pr-nav .nav-link:hover{color:var(--brand)!important;background:#f1f6ff}
.pr-nav .nav-link.active{color:var(--brand)!important;background:linear-gradient(135deg,#eaf1ff,#f6faff);
  box-shadow:inset 0 0 0 1px #d9e6ff}
.pr-nav .btn{border-radius:999px;font-size:.85rem;font-weight:700;padding:8px 18px;transition:transform .16s,filter .16s,background .16s}
.pr-nav .btn-outline-primary{border-color:#c9dbff;color:var(--brand)}
.pr-nav .btn-outline-primary:hover{background:#edf3ff;border-color:#b7cdff;color:var(--brand)}
.pr-nav .btn-primary{border:0;background:linear-gradient(135deg,var(--brand2),var(--brand));
  box-shadow:0 12px 22px -14px rgba(11,61,145,.95)}
.pr-nav .btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px)}
.pr-nav .navbar-toggler{border:1px solid var(--line);border-radius:10px;padding:6px 10px;box-shadow:none}
.pr-nav .navbar-toggler:focus{box-shadow:0 0 0 3px rgba(44,110,213,.15)}
.btn-primary{background:var(--brand);border-color:var(--brand)}
.btn-primary:hover,.btn-primary:focus{background:var(--brand2);border-color:var(--brand2)}
.pr-head{padding:34px 0 12px}
.pr-head h1{font-size:1.85rem;font-weight:800;letter-spacing:-.025em;margin:0;color:var(--ink);line-height:1.15}
.pr-head p{color:var(--mut);font-size:.92rem;margin:8px 0 0;max-width:60ch}
.pr-stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);transition:transform .18s var(--ease),box-shadow .18s var(--ease)}
.pr-stat:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.pr-stat .ic{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--brand);background:#edf3ff}
.pr-stat.green .ic{color:#0f7d50;background:#e9f8f1}
.pr-stat b{font-size:1.32rem;line-height:1;display:block}
.pr-stat span{font-size:.75rem;color:var(--mut)}
.pr-card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-md);overflow:hidden;transition:box-shadow .2s var(--ease)}
.pr-filter{padding:16px 18px}
.pr-filter .form-label{font-size:.74rem;font-weight:700;color:#4a5872;margin-bottom:5px}
.pr-filter .form-select,.pr-filter .form-control{border-color:var(--line);border-radius:10px;font-size:.88rem;padding:.6rem .8rem;background:#fdfefe;transition:border-color .18s,box-shadow .18s}
.pr-filter .form-select:focus,.pr-filter .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12);outline:0}
.pr-toolbar{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;background:linear-gradient(180deg,#fcfdff 0%,#f7faff 100%)}
.pr-toolbar .total{font-size:.86rem;color:var(--mut)}
.pr-toolbar .total b{color:var(--ink)}
.table thead th{background:#f6f8fd;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;color:#5b6b85;border-bottom:1px solid var(--line);font-weight:700;padding:12px 14px}
.table td{padding:12px 14px}
.table td{font-size:.87rem;vertical-align:middle}
.table-hover tbody tr:hover{background:#f8fbff}
.nowrap{white-space:nowrap}
.pr-empty{text-align:center;color:var(--mut);padding:46px 20px!important}
.pr-empty i{font-size:2rem;display:block;margin-bottom:10px;color:#b7c4da}
.pr-meta{font-size:.78rem;color:var(--mut)}
.pr-scope{display:inline-block;font-size:.64rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
  border-radius:999px;padding:3px 9px;border:1px solid transparent;white-space:nowrap}
.pr-scope-district{color:#0b3d91;background:#eaf1ff;border-color:#cfe0ff}
.pr-scope-regional{color:#5b2bb8;background:#f1ebff;border-color:#ddd0fb}
.pr-scope-school{color:#12734a;background:#eaf7f0;border-color:#cfeee0}
.pr-title-card{padding:22px 24px;border-bottom:1px solid var(--line);background:linear-gradient(120deg,#fff 55%,#f4f8ff)}
.pr-title-card h2{font-size:1.25rem;font-weight:800;margin:0 0 6px}
.chip{display:inline-flex;align-items:center;gap:5px;font-size:.71rem;font-weight:700;border:1px solid var(--line);background:#fff;color:#4a5872;border-radius:999px;padding:4px 10px;margin:0 6px 4px 0}
.chip i{color:var(--brand)}
/* Footer mwembamba: mstari wa gradient kama topbar, upana sawa na content. */
.pr-foot-bar{position:relative;margin-top:34px;background:rgba(255,255,255,.92);border-top:1px solid var(--line);
  padding:13px 0;font-size:.78rem;color:var(--mut)}
.pr-foot-bar:before{content:"";position:absolute;left:0;right:0;top:-1px;height:2px;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--green))}
.pr-foot-bar a{color:var(--brand2);font-weight:600;transition:color .16s}
.pr-foot-bar a:hover{color:var(--brand);text-decoration:underline}
.pr-foot-bar .sep{color:#c3cede;margin:0 7px}
.pr-foot-bar .note{color:#93a0b4}
/* NECTA-style alphabetical centre filter */
/* Shared width for the NECTA filter and the result sections below it */
.ms-wide{width:100%;max-width:var(--necta-width);margin-left:auto;margin-right:auto}
.ms-wide .ms-section-title{text-align:center}
.alpha-filter{margin:16px auto 22px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px 18px;box-shadow:var(--shadow-sm)}
.alpha-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px;margin-bottom:12px}
.alpha-title{font-size:.82rem;font-weight:800;color:var(--ink);display:inline-flex;align-items:center;gap:7px}
.alpha-title i{color:var(--brand2)}
.alpha-hint{font-size:.72rem;color:var(--mut)}
.alpha-row{display:flex;flex-wrap:nowrap;gap:6px;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin;-webkit-overflow-scrolling:touch}
.alpha-row::-webkit-scrollbar{height:5px}
.alpha-row::-webkit-scrollbar-thumb{background:#c9d8ef;border-radius:999px}
.alpha-pill{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:34px;padding:0 9px;border-radius:10px;font-size:.8rem;font-weight:700;color:var(--brand);background:#f1f6ff;border:1px solid #dbe7ff;transition:background .16s,color .16s,border-color .16s,transform .16s,box-shadow .16s}
.alpha-pill:hover{background:var(--brand2);color:#fff;border-color:var(--brand2);transform:translateY(-1px)}
.alpha-pill.active{background:linear-gradient(135deg,var(--brand2),var(--brand));color:#fff;border-color:transparent;box-shadow:0 8px 18px -10px rgba(11,61,145,.9)}
.alpha-all{padding:0 16px}
.alpha-pill.empty{opacity:.38;background:#f7f9fd;color:var(--mut);border-color:var(--line2)}
.alpha-pill.empty:hover{opacity:.7;transform:none;background:#eef3fb;color:var(--mut);border-color:var(--line)}
/* Overall results tab bar */
.ovr-bar{position:relative;background:linear-gradient(120deg,#ffffff 0%,#f3f8ff 100%);border:1px solid var(--line);border-radius:20px;padding:16px 18px 16px 24px;box-shadow:var(--shadow-sm);display:flex;flex-wrap:wrap;align-items:center;gap:14px;overflow:hidden}
.ovr-bar:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:linear-gradient(180deg,var(--brand2),var(--brand3))}
.ovr-title{display:inline-flex;align-items:center;gap:9px;font-size:.74rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--brand);background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 14px 7px 8px;white-space:nowrap;box-shadow:0 6px 14px -12px rgba(11,61,145,.7)}
.ovr-title i{width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;font-size:.8rem;color:#fff;background:linear-gradient(135deg,var(--brand2),var(--brand))}
.ovr-tabs{display:flex;flex-wrap:wrap;gap:7px;flex:1 1 auto}
.ovr-tab{display:inline-flex;align-items:center;gap:7px;font-size:.82rem;font-weight:700;color:#46536b;background:#fff;border:1px solid var(--line);border-radius:11px;padding:9px 14px;transition:background .16s,color .16s,border-color .16s,transform .16s,box-shadow .16s}
.ovr-tab:hover{color:var(--brand);background:#f4f9ff;border-color:#cfe0ff;transform:translateY(-2px);box-shadow:0 10px 20px -16px rgba(11,61,145,.7)}
.ovr-tab.active{color:#fff;background:linear-gradient(135deg,var(--brand2),var(--brand));border-color:transparent;box-shadow:0 10px 22px -12px rgba(11,61,145,.95),inset 0 0 0 1px rgba(255,255,255,.14)}
.ovr-tab i{font-size:.92rem;opacity:.9}
.ovr-csv{color:#0f7d50;background:#eaf9f1;border-color:#c8ecd9}
.ovr-csv:hover{color:#fff;background:linear-gradient(135deg,#12b981,#0f9d6b);border-color:transparent}
/* NECTA-style school result sheet */
/* NECTA-style school result sheet */
.sheet-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px}
.sheet-label{display:inline-flex;align-items:center;gap:7px;font-size:.82rem;font-weight:800;color:var(--ink)}
.sheet-label i{color:var(--brand2)}
.sheet-pdf-btn{display:inline-flex;align-items:center;gap:7px;font-size:.82rem;font-weight:700;color:#fff;background:linear-gradient(135deg,#ef5350,#c62828);border-radius:10px;padding:9px 16px;box-shadow:0 10px 20px -12px rgba(198,40,40,.9);transition:transform .16s,filter .16s}
.sheet-pdf-btn:hover{color:#fff;transform:translateY(-1px);filter:brightness(1.06)}
.necta-sheet{padding:16px 18px;background:#add8e6;color:#000080;font-family:Arial,Helvetica,sans-serif;overflow-x:auto;-webkit-overflow-scrolling:touch}
.gov-header{text-align:center;margin:0 0 16px;color:#800080;font-family:Arial,Helvetica,sans-serif;text-transform:uppercase}
.gov-header div{font-size:20px;font-weight:700;line-height:1.4}
.gov-header .gov-exam{font-size:20px;margin-top:7px}
.gov-header .gov-school{font-size:20px;margin-top:3px}
.necta-sheet .necta-summary-title{color:#800080;font-weight:700;margin:0 0 5px}
.necta-table{border-collapse:collapse;background:#fffacd;color:#000080;margin:0 0 14px}
.necta-table th,.necta-table td{border:1px solid #000080;padding:2px 7px;text-align:center;vertical-align:middle}
.necta-table.summary{width:40%;min-width:320px;font-family:"Courier New",Courier,monospace;font-size:12px}
.necta-table.cands{width:100%;border-collapse:separate;border-spacing:2px;font-family:Arial,Helvetica,sans-serif;font-size:11px}
.necta-table.cands th,.necta-table.cands td{border:1px solid #000080;padding:2px 5px}
.necta-table.cands{min-width:640px}
.necta-table.cands th.subj,.necta-table.cands td.subj{text-align:left;white-space:normal;word-break:break-word}
/* Centre summary below the candidate results */
.necta-summary{margin-top:18px}
.necta-summary .necta-table{width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;border-collapse:collapse}
.necta-summary .info th,.necta-summary .info td{text-align:left;width:50%;padding:3px 6px}
.necta-summary .info th{font-weight:400}
.necta-summary .perf th,.necta-summary .perf td{padding:2px 5px}
.necta-summary .perf .subjname{text-align:left}
.necta-summary .lvl{display:inline-block;padding:1px 5px;font-size:10px;white-space:nowrap}
/* Exam detail header banner */
.pr-exam-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:16px 0 22px;padding:18px 22px;border-radius:20px;
  background:radial-gradient(520px 240px at 92% 10%,rgba(15,157,107,.32),transparent 60%),linear-gradient(135deg,#0a3a89,#2c6ed5);
  box-shadow:var(--shadow-md);color:#fff;position:relative;overflow:hidden;isolation:isolate}
.pr-exam-head:before{content:"";position:absolute;inset:0;z-index:-1;opacity:.4;pointer-events:none;
  background-image:radial-gradient(rgba(255,255,255,.18) 1px,transparent 1px);background-size:22px 22px}
.pr-back-btn{display:inline-flex;align-items:center;gap:6px;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);
  border-radius:999px;padding:8px 16px;font-weight:700;font-size:.82rem;transition:background .18s,transform .18s;flex:0 0 auto}
.pr-back-btn:hover{background:rgba(255,255,255,.26);color:#fff;transform:translateY(-1px)}
.pr-exam-titles{flex:1 1 auto;min-width:0;text-align:center;padding:2px 8px}
.pr-exam-kicker{display:inline-flex;align-items:center;gap:6px;font-size:.66rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.72)}
.pr-exam-titles h1{color:#fff!important;font-size:clamp(1.05rem,2.3vw,1.5rem);font-weight:800;margin:5px 0 0;letter-spacing:-.01em;line-height:1.22}
.pr-exam-right{flex:0 0 auto;display:flex;gap:8px}
/* MSSIS detail styling */
.ms-title{color:var(--purple);font-weight:700}
.ms-authority{font-family:"Times New Roman",Times,serif;color:var(--purple);text-align:center}
.ms-authority h4{font-weight:700;margin:0 0 2px}
.ms-link{color:#0000EE!important;text-decoration:underline!important}
.centre-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.centre-card{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px;transition:transform .18s var(--ease),box-shadow .18s var(--ease),border-color .18s var(--ease)}
.centre-card:hover{transform:translateY(-2px);border-color:#b9cdf0;box-shadow:0 14px 28px -20px rgba(11,61,145,.6)}
.centre-ic{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#e9f1ff,#f6faff);color:var(--brand);font-size:.95rem;transition:transform .3s var(--ease)}
.centre-card:hover .centre-ic{transform:scale(1.08) rotate(-4deg)}
.centre-name{flex:1 1 auto;min-width:0;font-size:.76rem;font-weight:700;color:var(--ink);line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.centre-go{color:var(--brand2);font-size:.85rem;flex:0 0 auto;opacity:.55;transition:opacity .18s,transform .18s}
.centre-card:hover .centre-go{opacity:1;transform:translate(1px,-1px)}
@media (max-width:991px){.centre-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:479px){.centre-grid{grid-template-columns:1fr}}
.ms-section-title{font-weight:700;color:#6c757d}
.ms-report h4{font-size:1.05rem}
@media (max-width:767px){
  .necta-sheet{padding:12px 10px}
  .gov-header div{font-size:15px}
  .gov-header .gov-exam,.gov-header .gov-school{font-size:15px}
  .necta-table.summary{min-width:280px}
  .sheet-toolbar{align-items:flex-start}
  .pr-head{padding:22px 0 6px}
  .pr-head h1{font-size:1.4rem}
  .pr-nav{padding:7px 0}
  .pr-nav>.container,main.container,.pr-foot-bar>.container{padding-left:14px;padding-right:14px}
  .pr-foot-bar{margin-top:26px}
  .pr-foot-bar .foot-links{width:100%}
  .pr-nav .navbar-brand{gap:8px;max-width:calc(100% - 62px)}
  .pr-nav .navbar-brand .brand-icon{width:28px;height:28px;border-radius:8px;font-size:.9rem}
  .pr-nav .navbar-brand .brand-logo{width:30px;height:30px;border-radius:8px}
  .table td,.table th{padding:9px 10px}
}
@media print{.pr-nav,.no-print{display:none!important}body{background:#fff}.pr-card{border:0;box-shadow:none}.pr-head{padding-top:0}.ms-report{font-size:.85rem}.pr-exam-head{background:#fff!important;box-shadow:none!important;padding:0;margin:0 0 10px}.pr-exam-titles h1{color:#000!important}.pr-exam-kicker{color:#555}}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg pr-nav no-print" id="prNavBar">
  <div class="container">
    <a class="navbar-brand" href="<?php echo h(url('index.php')); ?>">
      <?php if ($brandLogo !== ''): ?><img class="brand-logo" src="<?php echo h($brandLogo); ?>" alt="School logo"><?php else: ?><span class="brand-icon"><i class="bi bi-mortarboard-fill"></i></span><?php endif; ?>
      <span><span class="brand-name"><?php echo h($brandName); ?></span><?php if ($brandSub !== ''): ?><span class="brand-sub"><?php echo h($brandSub); ?></span><?php endif; ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#prNav" aria-controls="prNav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="prNav">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="<?php echo h(url('index.php')); ?>"><i class="bi bi-house-door me-1"></i>Home</a></li>
      </ul>
      <div class="d-flex flex-wrap gap-2 mt-2 mt-lg-0">
        <a class="btn btn-outline-primary" href="<?php echo h(url('login.php?t=student')); ?>"><i class="bi bi-person-badge me-1"></i>Student Portal</a>
        <a class="btn btn-primary" href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
      </div>
    </div>
  </div>
</nav>

<main class="container pb-3">
<?php if ($exam): ?>
  <div class="pr-exam-head">
    <a class="pr-back-btn no-print" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-arrow-left"></i>Published Results</a>
    <div class="pr-exam-titles">
      <h1 class="ms-title"><?php
        echo h($exam['name']);
        if (!empty($exam['start_date']) && $exam['start_date'] !== '0000-00-00') echo '&nbsp;&nbsp;' . h(date('d M Y', strtotime($exam['start_date'])));
      ?></h1>
    </div>
    <div class="pr-exam-right no-print">
      <?php if ($view !== ''): ?><a class="pr-back-btn" href="<?php echo h($du()); ?>"><i class="bi bi-grid"></i>Centre Results</a><?php endif; ?>
    </div>
  </div>

  <div class="container mb-5">

    <?php
      /* Centre initials that actually have published results, for the letter filter */
      $letterCounts = [];
      foreach ($allPub as $sc) {
          $ini = strtoupper(substr(ltrim((string)($sc['name'] ?? '')), 0, 1));
          if ($ini >= 'A' && $ini <= 'Z') $letterCounts[$ini] = (isset($letterCounts[$ini]) ? $letterCounts[$ini] : 0) + 1;
      }
    ?>
    <?php if ($view !== 'school'): ?>
    <div class="alpha-filter ms-wide no-print">
      <div class="alpha-head">
        <span class="alpha-title"><i class="bi bi-funnel-fill"></i>Filter centres by alphabet</span>
        <span class="alpha-hint">Tap a letter to narrow the list</span>
      </div>
      <div class="alpha-row">
        <a class="alpha-pill alpha-all<?php echo $letter === '' ? ' active' : ''; ?>" href="<?php echo h($du(['letter' => '', 'view' => ''])); ?>"><i class="bi bi-grid-3x3-gap-fill me-1"></i>All centres</a>
        <?php for ($i = 0; $i < 26; $i++): $ch = chr(65 + $i); ?>
          <a class="alpha-pill<?php echo $letter === $ch ? ' active' : ''; ?><?php echo isset($letterCounts[$ch]) ? '' : ' empty'; ?>" href="<?php echo h($du(['letter' => $ch, 'view' => ''])); ?>"><?php echo $ch; ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$classId): ?>
      <div class="alert alert-info">Please choose a class first.</div>
    <?php else: ?>
      <?php if ($view !== ''): ?>
        <div class="ovr-bar ms-wide mb-3">
          <span class="ovr-title"><i class="bi bi-bar-chart-fill"></i>Overall Results</span>
          <div class="ovr-tabs">
            <a class="ovr-tab<?php echo $view === 'rank' ? ' active' : ''; ?>" href="<?php echo h($du(['view' => 'rank'])); ?>"><i class="bi bi-trophy"></i>Schools Ranking</a>
            <a class="ovr-tab<?php echo $view === 'general' ? ' active' : ''; ?>" href="<?php echo h($du(['view' => 'general'])); ?>"><i class="bi bi-table"></i>General Schools' Results</a>
            <a class="ovr-tab<?php echo $view === 'subjects' ? ' active' : ''; ?>" href="<?php echo h($du(['view' => 'subjects'])); ?>"><i class="bi bi-journal-check"></i>Subjects Ranking</a>
            <a class="ovr-tab<?php echo $view === 'grades' ? ' active' : ''; ?>" href="<?php echo h($du(['view' => 'grades'])); ?>"><i class="bi bi-bar-chart-steps"></i>Grades Analysis Subjectwise</a>
            <a class="ovr-tab<?php echo $view === 'best' ? ' active' : ''; ?>" href="<?php echo h($du(['view' => 'best'])); ?>"><i class="bi bi-star"></i>Overall Ten Students</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($view === 'school' && $schoolViewId && isset($schoolResults[$schoolViewId])): ?>
        <?php
          $d = $schoolResults[$schoolViewId];
          $sheetLines = pr_sheet_header_lines($d);
        ?>
        <div class="sheet-toolbar ms-wide no-print">
          <span class="sheet-label"><i class="bi bi-file-earmark-text"></i>Official result sheet</span>
          <a class="sheet-pdf-btn" href="<?php echo h($du(['view' => 'school', 'school_id' => $schoolViewId, 'export' => 'pdf'])); ?>"><i class="bi bi-file-earmark-pdf"></i>Download PDF</a>
        </div>
        <div class="necta-sheet ms-wide">
          <div class="gov-header">
            <div><?php echo h($sheetLines[0]); ?></div>
            <div><?php echo h($sheetLines[1]); ?></div>
            <div><?php echo h($sheetLines[2]); ?></div>
            <div><?php echo h($sheetLines[3]); ?></div>
            <div class="gov-exam"><?php echo h($sheetLines[4]); ?></div>
            <div class="gov-school"><?php echo h($sheetLines[5]); ?></div>
          </div>

          <p class="necta-summary-title">DIVISION PERFORMANCE SUMMARY</p>
          <table class="necta-table summary">
            <thead><tr><th>SEX</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th></tr></thead>
            <tbody>
            <?php foreach (['F' => 'F', 'M' => 'M', 'T' => 'T'] as $key => $label): ?>
              <tr>
                <td><?php echo h($label); ?></td>
                <?php foreach (['I', 'II', 'III', 'IV', '0'] as $dv): ?>
                  <?php
                    $f = (int)($d['divSummary'][$dv]['F'] ?? 0);
                    $m = (int)($d['divSummary'][$dv]['M'] ?? 0);
                    echo '<td>' . ($key === 'T' ? $f + $m : ($key === 'F' ? $f : $m)) . '</td>';
                  ?>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <table class="necta-table cands">
            <thead><tr><th>CNO</th><th>SEX</th><th>AGGT</th><th>DIV</th><th class="subj">DETAILED SUBJECTS</th></tr></thead>
            <tbody>
            <?php foreach ($d['candidates'] as $c): ?>
              <?php
                $parts = [];
                foreach ($c['subjects'] as $s) {
                    $g = (string)($s['grade'] ?? '');
                    if ($g === '' || $g === 'ABS' || $g === 'PENDING') $g = 'X';
                    $parts[] = h($s['abbr']) . " - '" . h($g) . "'";
                }
              ?>
              <tr>
                <td><?php echo h($c['cand_no'] ?: $c['prems_no']); ?></td>
                <td><?php echo h($c['sex']); ?></td>
                <td><?php echo $c['points'] === null ? '-' : (int)$c['points']; ?></td>
                <td><?php echo h($c['division']); ?></td>
                <td class="subj"><?php echo implode('   ', $parts); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <?php
            /* NECTA centre summary shown below the candidate results */
            $nectaGpa = centre_gpa($d);
            $gpaBand  = competence_level($nectaGpa);
            $regN     = count($d['candidates']);
            $absentN  = $satN = $cleanN = $passedN = 0;
            foreach ($d['candidates'] as $c) {
                if ($c['state'] === 'ABS') $absentN++;
                else $satN++;
                if (in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $cleanN++;
                if (in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $passedN++;
            }
            $divCounts = [];
            foreach (['I', 'II', 'III', 'IV', '0'] as $dv) {
                $divCounts[$dv] = (int)($d['divSummary'][$dv]['F'] ?? 0) + (int)($d['divSummary'][$dv]['M'] ?? 0);
            }
          ?>
          <div class="necta-summary">
            <table class="necta-table info">
              <tr><th>EXAMINATION CENTRE REGION</th><td><?php echo h(strtoupper($d['school']['region'])); ?></td></tr>
              <tr><th>TOTAL PASSED CANDIDATES</th><td><?php echo $passedN; ?></td></tr>
              <tr><th>EXAMINATION CENTRE GPA</th><td>
                <?php echo $nectaGpa === null ? '-' : h(number_format((float)$nectaGpa, 4)); ?>
                <?php if ($gpaBand): ?><span class="lvl" style="background:<?php echo h($gpaBand['bg']); ?>;color:<?php echo h($gpaBand['fg']); ?>"><?php echo h($gpaBand['label']); ?></span><?php endif; ?>
              </td></tr>
            </table>

            <p class="necta-summary-title">EXAMINATION CENTRE DIVISION PERFORMANCE</p>
            <table class="necta-table perf">
              <thead><tr><th>REGIST</th><th>ABSENT</th><th>SAT</th><th>WITHHELD</th><th>NO-CA</th><th>CLEAN</th><th>DIV I</th><th>DIV II</th><th>DIV III</th><th>DIV IV</th><th>DIV 0</th></tr></thead>
              <tbody><tr>
                <td><?php echo $regN; ?></td><td><?php echo $absentN; ?></td><td><?php echo $satN; ?></td>
                <td>0</td><td>0</td><td><?php echo $cleanN; ?></td>
                <?php foreach (['I', 'II', 'III', 'IV', '0'] as $dv): ?><td><?php echo $divCounts[$dv]; ?></td><?php endforeach; ?>
              </tr></tbody>
            </table>

            <p class="necta-summary-title">EXAMINATION CENTRE SUBJECTS PERFORMANCE</p>
            <table class="necta-table perf">
              <thead><tr><th>CODE</th><th>SUBJECT NAME</th><th>REG</th><th>SAT</th><th>NO-CA</th><th>W/HD</th><th>CLEAN</th><th>PASS</th><th>GPA</th><th>COMPENTENCY LEVEL</th></tr></thead>
              <tbody>
              <?php foreach ($d['subjStats'] as $s): ?>
                <?php $band = competence_level($s['gpa']); ?>
                <tr>
                  <td><?php echo h($s['code']); ?></td>
                  <td class="subjname"><?php echo h(strtoupper($s['name'])); ?></td>
                  <td><?php echo (int)$s['sat'] + (int)$s['absent']; ?></td>
                  <td><?php echo (int)$s['sat']; ?></td>
                  <td>0</td><td>0</td>
                  <td><?php echo (int)$s['sat']; ?></td>
                  <td><?php echo (int)$s['pass']; ?></td>
                  <td><?php echo $s['gpa'] === null ? '-' : h(number_format((float)$s['gpa'], 4)); ?></td>
                  <td><?php if ($band): ?><span class="lvl" style="background:<?php echo h($band['bg']); ?>;color:<?php echo h($band['fg']); ?>"><?php echo h($band['label']); ?></span><?php else: ?>-<?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php elseif ($view === 'rank' || $view === 'general'): ?>
        <div class="mb-4 ms-wide">
          <p class="ms-section-title"><?php echo $view === 'rank' ? 'Schools Ranking' : 'General Schools\' Results'; ?></p>
          <?php
            $rows = [];
            foreach ($councilRows as $r) {
                $rows[] = [h($r['rank'] ?: '-'), h($r['name']), h($r['centre'] ?: '-'), (int)$r['registered'],
                           (int)$r['I'], (int)$r['II'], (int)$r['III'], (int)$r['IV'], (int)$r['0'],
                           $r['avg_points'] === null ? '-' : h(number_format((float)$r['avg_points'], 2)),
                           $r['pass_pct'] === null ? '-' : h($r['pass_pct'] . '%')];
            }
            echo pr_table(['RANK', 'SCHOOL', 'CENTRE', 'CANDIDATES', 'I', 'II', 'III', 'IV', '0', 'AVG PTS', 'PASS %'], $rows);
          ?>
        </div>

      <?php elseif ($view === 'subjects'): ?>
        <div class="mb-4 ms-wide">
          <p class="ms-section-title">Subjects Ranking</p>
          <?php
            $rows = [];
            foreach ($subjectAgg as $s) {
                $rows[] = [h($s['abbr']), h($s['name']), (int)$s['sat'],
                           $s['avg'] === null ? '-' : h(number_format((float)$s['avg'], 1)),
                           $s['pass_pct'] === null ? '-' : h($s['pass_pct'] . '%'),
                           $s['gpa'] === null ? '-' : h(number_format((float)$s['gpa'], 2))];
            }
            echo pr_table(['SUBJECT', 'NAME', 'SAT', 'AVG', 'PASS %', 'GPA'], $rows);
          ?>
        </div>

      <?php elseif ($view === 'grades'): ?>
        <div class="mb-4 ms-wide">
          <p class="ms-section-title">Grades Analysis Subjectwise</p>
          <?php
            $gradeKeys = [];
            foreach ($subjectAgg as $s) foreach ($s['grades'] as $g => $n) $gradeKeys[$g] = 1;
            $gradeKeys = array_keys($gradeKeys);
            $headers = ['SUBJECT']; foreach ($gradeKeys as $g) $headers[] = h($g);
            $headers[] = 'SAT';
            $rows = [];
            foreach ($subjectAgg as $s) {
                $row = [h($s['abbr'])];
                foreach ($gradeKeys as $g) $row[] = isset($s['grades'][$g]) ? (int)$s['grades'][$g] : '-';
                $row[] = (int)$s['sat'];
                $rows[] = $row;
            }
            echo pr_table($headers, $rows);
          ?>
        </div>

      <?php elseif ($view === 'best'): ?>
        <div class="mb-4 ms-wide">
          <p class="ms-section-title">Overall Ten Students</p>
          <?php
            $rows = [];
            foreach ($bestRows as $b) {
                $rows[] = [(int)$b['pos'], h($b['cand_no']), h($b['name']), h($b['sex']), h($b['school']),
                           pr_div_badge($b['division']), $b['points'] === null ? '-' : (int)$b['points'],
                           $b['average'] === null ? '-' : h(number_format((float)$b['average'], 2))];
            }
            echo pr_table(['POS', 'INDEX NUMBER', 'NAME', 'SEX', 'SCHOOL', 'DIV', 'POINTS', 'AVG'], $rows);
          ?>
        </div>

      <?php else: ?>
        <div class="ms-wide">
        <?php if (!$pubSchools): ?>
          <p class="text-muted fst-italic">No published centres for this class.</p>
        <?php else: ?>
          <div class="centre-grid">
            <?php foreach ($pubSchools as $sc):
              $label = pr_school_label($sc['centre_no'] ?? '', $sc['name'] ?? '');
            ?>
              <a class="centre-card" data-centre="<?php echo h($label); ?>" data-pdf="<?php echo h($du(['view' => 'school', 'school_id' => (int)$sc['id'], 'export' => 'pdf'])); ?>" href="<?php echo h($du(['view' => 'school', 'school_id' => (int)$sc['id']])); ?>">
                <span class="centre-ic"><i class="bi bi-building"></i></span>
                <span class="centre-name"><?php echo h($label); ?></span>
                <i class="bi bi-arrow-up-right centre-go"></i>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="pr-head d-flex flex-wrap justify-content-between align-items-end gap-3">
    <div>
      <h1>Published Results</h1>
    </div>
  </div>

  <div class="pr-card mb-3 no-print">
    <form method="get" class="row g-2 pr-filter align-items-end">
      <div class="col-12 col-lg-2">
        <label class="form-label" for="year">Year</label>
        <select id="year" name="year" class="form-select">
          <option value="">All years</option>
          <?php foreach ($yearOptions as $yv): ?><option value="<?php echo h($yv); ?>"<?php echo $year === (string)$yv ? ' selected' : ''; ?>><?php echo h($yv); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="type">Exam type</label>
        <select id="type" name="type" class="form-select">
          <option value="">Select exam type</option>
          <option value="district"<?php echo $type === 'district' ? ' selected' : ''; ?>>District Exams</option>
          <option value="school"<?php echo $type === 'school' ? ' selected' : ''; ?>>School Exams</option>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="search">Exam name</label>
        <input type="text" id="search" name="search" class="form-control" placeholder="Search exam or school..." value="<?php echo h($search); ?>">
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label" for="perPage">Rows per page</label>
        <select id="perPage" name="per_page" class="form-select">
          <?php foreach ([10, 25, 50, 100] as $n): ?><option value="<?php echo $n; ?>"<?php echo $perPage === $n ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-2 d-flex gap-2">
        <button class="btn btn-primary fw-bold w-100" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a class="btn btn-outline-secondary" href="<?php echo h(url('public_results.php')); ?>" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></a>
      </div>
    </form>
  </div>

  <div class="pr-card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th class="text-center">S/N</th><th class="text-center">Name</th><th class="text-center">Type</th><th class="text-center">Date</th><th class="text-center">Action</th></tr></thead>
        <tbody>
        <?php if (!$exams): ?>
          <tr><td colspan="5" class="pr-empty"><i class="bi bi-inbox"></i>No exams published yet. Results will appear here once schools publish them.</td></tr>
        <?php else: foreach ($exams as $i => $e): ?>
          <?php
            if (!empty($e['start_date']) && !empty($e['end_date'])) {
                $dateText = date('d M Y', strtotime($e['start_date'])) . ' - ' . date('d M Y', strtotime($e['end_date']));
            } elseif (!empty($e['start_date'])) {
                $dateText = date('d M Y', strtotime($e['start_date']));
            } else {
                $dateText = $e['year_name'] ?: '-';
            }
          ?>
          <tr>
            <td><?php echo $offset + $i + 1; ?></td>
            <td>
              <span class="fw-semibold"><?php echo h($e['name']); ?></span>
              <?php if (!empty($e['owner_name'])): ?><div class="pr-meta"><?php echo h($e['owner_name']); ?></div><?php endif; ?>
              <span class="chip" style="margin-top:5px"><i class="bi bi-building"></i><?php echo (int)$e['schools']; ?> school<?php echo (int)$e['schools'] === 1 ? '' : 's'; ?></span>
            </td>
            <?php $scope = pr_exam_scope($e); ?>
            <td><span class="pr-scope <?php echo $scope[1]; ?>"><?php echo h(strtoupper(trim((!empty($e['exam_type']) ? $e['exam_type'] . ' - ' : '') . $scope[0]))); ?></span></td>
            <td class="nowrap"><?php echo h($dateText); ?></td>
            <td class="text-end"><a class="btn btn-sm btn-primary fw-bold" href="<?php echo h(url('public_results.php?exam_id=' . (int)$e['id'])); ?>"><i class="bi bi-eye me-1"></i>View Results</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="mt-3 no-print" aria-label="Pagination">
      <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
          <a class="page-link" href="<?php echo h(pr_page_url(['year' => $year, 'type' => $type, 'search' => $search, 'per_page' => $perPage, 'page' => $page - 1])); ?>&laquo;</a>
        </li>
        <?php
          $start = max(1, $page - 3); $end = min($pages, $page + 3);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
            <a class="page-link" href="<?php echo h(pr_page_url(['year' => $year, 'type' => $type, 'search' => $search, 'per_page' => $perPage, 'page' => $p])); ?>"><?php echo $p; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item<?php echo $page >= $pages ? ' disabled' : ''; ?>">
          <a class="page-link" href="<?php echo h(pr_page_url(['year' => $year, 'type' => $type, 'search' => $search, 'per_page' => $perPage, 'page' => $page + 1])); ?>&raquo;</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>

</main>

<footer class="pr-foot-bar no-print">
  <div class="container text-center">
    <span>&copy; <?php echo h(date('Y')); ?> <?php echo h($sysName); ?></span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var nav = document.getElementById('prNavBar');
  if (nav) {
    var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 12); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
})();

/* On phones, tapping a centre card downloads that school's result PDF.
   On larger screens the card keeps its normal navigation. */
(function () {
  var cards = document.querySelectorAll('.centre-card[data-pdf]');
  if (!cards.length) return;
  var isPhone = function () {
    return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  };
  Array.prototype.forEach.call(cards, function (card) {
    card.addEventListener('click', function (e) {
      if (isPhone()) {
        e.preventDefault();
        var pdf = card.getAttribute('data-pdf');
        if (pdf) window.location.href = pdf;
      }
    });
  });
})();
</script>
</body>
</html>
<?php
/* -------------------- PDF export -------------------- */
if (($_GET['export'] ?? '') === 'pdf') {
    $fullHtml = ob_get_clean();
    require_once __DIR__ . '/lib/export.php';

    $inner = $fullHtml;
    $style = '';
    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"?>' . $fullHtml, LIBXML_NONET);
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " no-print ")]') as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
        foreach ($xp->query('//script') as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
        $styleNode = $xp->query('//style')->item(0);
        if ($styleNode) $style = $doc->saveHTML($styleNode);
        $sheet = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " necta-sheet ")]')->item(0);
        if (!$sheet) $sheet = $xp->query('//main')->item(0);
        if ($sheet) $inner = $doc->saveHTML($sheet);
        libxml_clear_errors();
    }

    $pdfCss = '<style>
      body{color:#000080}
      .necta-sheet,.ms-wide{width:100%!important;max-width:none!important;margin:0!important;padding:8px!important}
      .gov-header div,.gov-header .gov-exam,.gov-header .gov-school{font-size:16px!important}
      .necta-table,.necta-summary .necta-table{width:100%!important}
      .ms-centre td{white-space:normal!important;overflow:visible!important}
      .table{width:100%;border-collapse:collapse}
      .table th,.table td{border:1px solid #000080;padding:3px;font-size:9px}
      .badge{border:1px solid #000080;padding:1px 3px}
    </style>';

    $isSheet = $view === 'school' && $schoolViewId && isset($schoolResults[$schoolViewId]);
    $title = $exam
        ? ($isSheet ? pr_school_label($schoolResults[$schoolViewId]['school']['necta_no'], $schoolResults[$schoolViewId]['school']['school_name']) : $exam['name'])
        : 'Published Results';
    $file = $exam ? ($isSheet ? pr_school_label($schoolResults[$schoolViewId]['school']['necta_no'], $schoolResults[$schoolViewId]['school']['school_name']) : 'results-exam-' . (int)$examId) : 'published-results';

    send_pdf($pdfCss . $style . $inner, $title, $file, true);
    exit();
}
?>
