<?php
/** Public results portal - MSSIS-style published exams and exam detail. */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/grading.php';
require_once __DIR__ . '/lib/render.php';

$sysName = system_name();
$logo    = url('assets/img/oasis-logo.png');

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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--brand:#0b3d91;--brand2:#2c6ed5;--green:#0f9d6b;--line:#e3e9f5;--mut:#6b7a90;--ink:#16203a;--purple:#800080;--necta-width:1100px}
body{background:#f6f8fd;font-family:"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink)}
a{text-decoration:none}
/* Topbar: sticky, glass, upana sawa na content ya main wrapper. */
.pr-nav{position:sticky;top:0;z-index:1030;background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(12px);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:9px 0;box-shadow:0 12px 30px -26px rgba(11,61,145,.7)}
.pr-nav:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;
  background:linear-gradient(90deg,var(--brand),var(--brand2) 55%,var(--green))}
.pr-nav>.container,main.container,.pr-foot-bar>.container{width:100%;max-width:var(--necta-width);
  margin-left:auto;margin-right:auto;padding-left:18px;padding-right:18px}
main.container .container{max-width:none;padding-left:0;padding-right:0}
.pr-nav .navbar-brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--brand);
  font-size:.9rem;line-height:1.14;letter-spacing:-.01em;margin-right:6px}
.pr-nav .navbar-brand img{width:38px;height:38px;object-fit:contain;background:#fff;border-radius:11px;padding:3px;
  box-shadow:0 8px 18px -12px rgba(11,61,145,.85);flex:0 0 auto}
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
.pr-head{padding:32px 0 10px}
.pr-head h1{font-size:1.7rem;font-weight:800;letter-spacing:-.02em;margin:0;color:var(--ink)}
.pr-head p{color:var(--mut);font-size:.9rem;margin:6px 0 0}
.pr-stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 22px -18px rgba(11,61,145,.6)}
.pr-stat .ic{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--brand);background:#edf3ff}
.pr-stat.green .ic{color:#0f7d50;background:#e9f8f1}
.pr-stat b{font-size:1.32rem;line-height:1;display:block}
.pr-stat span{font-size:.75rem;color:var(--mut)}
.pr-card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 10px 30px -24px rgba(11,61,145,.7);overflow:hidden}
.pr-filter{padding:16px 18px}
.pr-filter .form-label{font-size:.74rem;font-weight:700;color:#4a5872;margin-bottom:5px}
.pr-filter .form-select,.pr-filter .form-control{border-color:var(--line);border-radius:9px;font-size:.87rem;padding:.55rem .75rem;background:#fdfefe}
.pr-toolbar{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;background:#fcfdff}
.pr-toolbar .total{font-size:.86rem;color:var(--mut)}
.pr-toolbar .total b{color:var(--ink)}
.table thead th{background:#f6f8fd;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;color:#5b6b85;border-bottom:1px solid var(--line)}
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
.pr-title-card{padding:20px 22px;border-bottom:1px solid var(--line);background:linear-gradient(120deg,#fff 55%,#f4f8ff)}
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
.necta-filter{margin:14px auto 18px;text-align:center;font-family:Arial,Helvetica,sans-serif}
.necta-caption{font-size:13px;font-weight:700;color:#800080;margin-bottom:7px}
.necta-alpha{width:100%;border-collapse:collapse;margin:0 auto;table-layout:fixed}
.necta-alpha td{border:2px solid #fff!important;padding:3px 1px;height:25px;vertical-align:middle;font-weight:700;font-size:11px}
.necta-alpha td.all{width:11%}
.necta-alpha td.ltr{width:3.34%}
.necta-alpha td.all{background:#90ee90}
.necta-alpha td.ltr{background:#add8e6}
.necta-alpha td.empty{opacity:.42}
.necta-alpha td.active{background:#ffe87a;outline:2px solid #800080;outline-offset:-2px}
.necta-alpha a{color:#0000ee!important;text-decoration:underline!important;display:block;overflow:hidden;text-overflow:clip}
.necta-alpha a:hover{color:#800080!important}
/* NECTA-style school result sheet */
.necta-sheet{padding:16px 18px;background:#add8e6;color:#000080;font-family:Arial,Helvetica,sans-serif}
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
.necta-table.cands th.subj,.necta-table.cands td.subj{text-align:left;white-space:normal}
/* Centre summary below the candidate results */
.necta-summary{margin-top:18px}
.necta-summary .necta-table{width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;border-collapse:collapse}
.necta-summary .info th,.necta-summary .info td{text-align:left;width:50%;padding:3px 6px}
.necta-summary .info th{font-weight:400}
.necta-summary .perf th,.necta-summary .perf td{padding:2px 5px}
.necta-summary .perf .subjname{text-align:left}
.necta-summary .lvl{display:inline-block;padding:1px 5px;font-size:10px;white-space:nowrap}
/* MSSIS detail styling */
.ms-title{color:var(--purple);font-weight:700}
.ms-authority{font-family:"Times New Roman",Times,serif;color:var(--purple);text-align:center}
.ms-authority h4{font-weight:700;margin:0 0 2px}
.ms-link{color:#0000EE!important;text-decoration:underline!important}
.ms-centre td{background:#f8fafc;border:2px solid #fff!important;padding:4px 6px;font-size:12px;font-family:Arial,Helvetica,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;height:16px;vertical-align:middle}
.ms-centre td.c0{background:#c7e6f5}.ms-centre td.c1{background:#f7c4d1}.ms-centre td.c2{background:#bdf0b8}
.ms-centre a{display:block;text-decoration:underline!important;color:#0000ee!important;overflow:hidden;text-overflow:ellipsis}
.ms-section-title{font-weight:700;color:#6c757d}
.ms-report h4{font-size:1.05rem}
@media (max-width:767px){
  .pr-head{padding:22px 0 6px}
  .pr-head h1{font-size:1.4rem}
  .pr-nav{padding:7px 0}
  .pr-nav>.container,main.container,.pr-foot-bar>.container{padding-left:14px;padding-right:14px}
  .pr-foot-bar{margin-top:26px}
  .pr-foot-bar .foot-links{width:100%}
  .pr-nav .navbar-brand{font-size:.76rem;gap:8px;max-width:calc(100% - 62px)}
  .pr-nav .navbar-brand img{width:34px;height:34px;border-radius:9px}
  .pr-nav .navbar-brand span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .table td,.table th{padding:9px 10px}
}
@media print{.pr-nav,.no-print{display:none!important}body{background:#fff}.pr-card{border:0;box-shadow:none}.pr-head{padding-top:0}.ms-report{font-size:.85rem}}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg pr-nav no-print">
  <div class="container">
    <a class="navbar-brand" href="<?php echo h(url('index.php')); ?>">
      <img src="<?php echo h($logo); ?>" alt="Logo"><span><?php echo h($sysName); ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#prNav" aria-controls="prNav" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="prNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="<?php echo h(url('index.php')); ?>"><i class="bi bi-house-door me-1"></i>Home</a></li>
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>Public Results</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo h(url('index.php#about')); ?>"><i class="bi bi-info-circle me-1"></i>About</a></li>
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
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 mb-3 no-print">
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo h(url('public_results.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Published Results</a>
    <div class="d-flex gap-2">
      <?php if ($view !== ''): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo h($du()); ?>"><i class="bi bi-grid me-1"></i>Centre Results</a><?php endif; ?>
      <button class="btn btn-outline-dark btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>
    </div>
  </div>

  <div class="container mb-5">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 ms-wide">
      <h1 class="ms-title h4 mb-3"><?php
        echo h($exam['name']);
        if (!empty($exam['start_date']) && $exam['start_date'] !== '0000-00-00') echo '&nbsp;&nbsp;' . h(date('d M Y', strtotime($exam['start_date'])));
      ?></h1>
      <button class="btn btn-primary btn-sm fw-bold" type="button" id="msCopyLink"><span class="me-2">Copy Link</span><i class="bi bi-share"></i></button>
    </div>

    <?php
      /* Centre initials that actually have published results, for the letter filter */
      $letterCounts = [];
      foreach ($allPub as $sc) {
          $ini = strtoupper(substr(ltrim((string)($sc['name'] ?? '')), 0, 1));
          if ($ini >= 'A' && $ini <= 'Z') $letterCounts[$ini] = (isset($letterCounts[$ini]) ? $letterCounts[$ini] : 0) + 1;
      }
    ?>
    <div class="necta-filter ms-wide no-print">
      <div class="necta-caption">CLICK ANY LETTER BELOW TO FILTER CENTRES BY ALPHABET</div>
      <table class="necta-alpha"><tr>
        <td class="all<?php echo $letter === '' ? ' active' : ''; ?>"><a href="<?php echo h($du(['letter' => '', 'view' => ''])); ?>">ALL CENTRES</a></td>
        <?php for ($i = 0; $i < 26; $i++): $ch = chr(65 + $i); ?>
          <td class="ltr<?php echo $letter === $ch ? ' active' : ''; ?><?php echo isset($letterCounts[$ch]) ? '' : ' empty'; ?>">
            <a href="<?php echo h($du(['letter' => $ch, 'view' => ''])); ?>"><?php echo $ch; ?></a>
          </td>
        <?php endfor; ?>
      </tr></table>
    </div>

    <?php if (!$classId): ?>
      <div class="alert alert-info">Please choose a class first.</div>
    <?php else: ?>
      <?php if ($view !== ''): ?>
        <div class="mb-3 ms-wide">
          <p class="ms-section-title">Overall Results</p>
          <div class="row mx-0 mb-3 justify-content-center">
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['view' => 'rank'])); ?>">Schools Ranking</a></div>
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['view' => 'general'])); ?>">General Schools' Results</a></div>
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['view' => 'subjects'])); ?>">Subjects Ranking</a></div>
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['view' => 'grades'])); ?>">Grades Analysis Subjectwise</a></div>
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['view' => 'best'])); ?>">Overall Ten Students</a></div>
            <div class="col-auto mb-2"><a class="btn btn-sm text-primary fw-bold" href="<?php echo h($du(['export' => 'csv'])); ?>">Download CSV</a></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($view === 'school' && $schoolViewId && isset($schoolResults[$schoolViewId])): ?>
        <?php
          $d = $schoolResults[$schoolViewId];
          $sheetLines = pr_sheet_header_lines($d);
        ?>
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <p class="ms-section-title mb-0">Centre Results (<?php echo count($pubSchools); ?>)</p>
          <a class="btn btn-sm text-danger fw-bold no-print" href="<?php echo h($du(['export' => 'pdf'])); ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
        </div>
        <input type="text" class="form-control mb-2 no-print" id="msCentreSearch" placeholder="Search centres by name or number">
        <?php if (!$pubSchools): ?>
          <p class="text-muted fst-italic">No published centres for this class.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered ms-centre"><tbody>
            <?php
              $chunks = array_chunk($pubSchools, 3);
              foreach ($chunks as $chunk) {
                  echo '<tr>';
                  for ($i = 0; $i < 3; $i++) {
                      $sc = isset($chunk[$i]) ? $chunk[$i] : null;
                      echo '<td class="c' . $i . '">';
                      if ($sc) {
                          $label = pr_school_label($sc['centre_no'] ?? '', $sc['name'] ?? '');
                          echo '<a class="ms-link" data-centre="' . h($label) . '" href="' . h($du(['view' => 'school', 'school_id' => (int)$sc['id']])) . '">' . h($label) . '</a>';
                      }
                      echo '</td>';
                  }
                  echo '</tr>';
              }
            ?>
            </tbody></table>
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
      <p>Browse exams and results published by schools and the district office.</p>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="pr-stat"><span class="ic"><i class="bi bi-journal-text"></i></span><div><b><?php echo (int)$total; ?></b><span>Published exams</span></div></div></div>
    <div class="col-md-4"><div class="pr-stat green"><span class="ic"><i class="bi bi-buildings"></i></span><div><b><?php echo (int)$statSchools; ?></b><span>Schools with results</span></div></div></div>
    <div class="col-md-4"><div class="pr-stat"><span class="ic"><i class="bi bi-people"></i></span><div><b><?php echo (int)$statCandidates; ?></b><span>Candidates with results</span></div></div></div>
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
    <div class="pr-toolbar no-print">
      <div class="total">Total: <b><?php echo (int)$total; ?></b> exams</div>
      <a class="btn btn-outline-success btn-sm" href="<?php echo h(pr_page_url(['year' => $year, 'type' => $type, 'search' => $search, 'export' => 'csv'])); ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>S/N</th><th>Name</th><th>Type</th><th>Date</th><th class="text-end">Action</th></tr></thead>
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
            <td><span class="pr-scope <?php echo $scope[1]; ?>"><?php echo h($scope[0]); ?></span>
              <?php if (!empty($e['exam_type'])): ?><div class="pr-meta"><?php echo h($e['exam_type']); ?></div><?php endif; ?>
            </td>
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
  <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span>&copy; <?php echo h(date('Y')); ?> <?php echo h($sysName); ?> <span class="note d-none d-sm-inline">&middot; All exam results are processed automatically.</span></span>
    <span class="foot-links d-flex flex-wrap align-items-center">
      <a href="<?php echo h(url('index.php')); ?>"><i class="bi bi-house-door me-1"></i>Home</a>
      <span class="sep">&middot;</span>
      <a href="<?php echo h(url('login.php?t=student')); ?>"><i class="bi bi-person-badge me-1"></i>Student Portal</a>
      <span class="sep">&middot;</span>
      <a href="<?php echo h(url('login.php')); ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a>
      <span class="sep">&middot;</span>
      <a href="#top" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;">Back to top <i class="bi bi-arrow-up"></i></a>
    </span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var copy = document.getElementById('msCopyLink');
  if (copy) copy.addEventListener('click', function () {
    var done = function () { this.innerHTML = '<span class="me-2">Copied!</span><i class="bi bi-check2"></i>'; };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(window.location.href).then(done.bind(this)).catch(function () {});
    } else {
      var t = document.createElement('textarea'); t.value = window.location.href;
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done.call(copy); } catch (e) {}
      document.body.removeChild(t);
    }
  });

  var centreSearch = document.getElementById('msCentreSearch');
  if (centreSearch) centreSearch.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    var links = document.querySelectorAll('.ms-centre a[data-centre]');
    for (var i = 0; i < links.length; i++) {
      var show = links[i].getAttribute('data-centre').toLowerCase().indexOf(q) !== -1;
      links[i].style.visibility = show ? 'visible' : 'hidden';
    }
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
    $file = $exam ? ($isSheet ? 'results-centre-' . (int)$schoolViewId : 'results-exam-' . (int)$examId) : 'published-results';

    send_pdf($pdfCss . $style . $inner, $title, $file, true);
    exit();
}
?>
