<?php
/**
 * Data layer na UI helpers za Results Analysis (ngazi ya wilaya).
 * Inahitaji lib/grading.php au lib/bootstrap.php kabla ya kuitumia.
 */

/** Orodha ya mitihani pamoja na idadi ya shule zilizochapisha. */
function ra_exam_options() {
    return q_all("SELECT e.id, e.name, y.name AS year_name,
            (SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = e.id AND x.published = 1) AS pub
        FROM exams e LEFT JOIN academic_years y ON y.id = e.academic_year_id
        ORDER BY e.id DESC");
}

/** Chagua mtihani halali. Hurudisha [exam_id, exam, hasData, exams]. */
function ra_pick_exam($requested) {
    $exams = ra_exam_options();
    $valid = array_map(function ($e) { return (int)$e['id']; }, $exams);
    $exam_id = (int)$requested;
    if (!in_array($exam_id, $valid, true)) $exam_id = $exams ? (int)$exams[0]['id'] : 0;
    $exam = $exam_id ? get_exam($exam_id) : null;
    $hasData = $exam_id > 0 && (int)q_val("SELECT COUNT(*) FROM exam_schools WHERE exam_id = ? AND published = 1", [$exam_id]) > 0;
    return [$exam_id, $exam, $hasData, $exams];
}

/** Sehemu za uchambuzi: kila moja ina ukurasa wake. */
function ra_sections() {
    return [
        'schools' => ['title' => 'Schools Ranking', 'file' => 'results_schools.php', 'icon' => 'trophy',
            'desc' => 'Shule zote zilizochapisha, GPA, wastani na asilimia ya ufaulu.'],
        'wards' => ['title' => 'Wards Ranking', 'file' => 'results_wards.php', 'icon' => 'geo-fill',
            'desc' => 'Ulinganisho wa kata (wards) kwa GPA na ufaulu.'],
        'districts' => ['title' => 'Districts Ranking', 'file' => 'results_districts.php', 'icon' => 'trophy',
            'desc' => 'Ulinganisho wa wilaya zilizoshiriki mtihani.'],
        'regions' => ['title' => 'Regions Ranking', 'file' => 'results_regions.php', 'icon' => 'globe-africa',
            'desc' => 'Ulinganisho wa mikoa kwa matokeo ya pamoja.'],
        'subjects' => ['title' => 'Subjects Ranking', 'file' => 'results_subjects.php', 'icon' => 'bar-chart',
            'desc' => 'Masomo yaliyopangwa kwa wastani wa alama.'],
        'subj-analysis' => ['title' => 'Overall Subjects Analysis', 'file' => 'results_subject_analysis.php', 'icon' => 'clipboard-data',
            'desc' => 'Uchambuzi wa kina wa ufaulu wa kila somo.'],
        'grades' => ['title' => 'Grades Analysis Subjectwise', 'file' => 'results_grades.php', 'icon' => 'grid-3x3-gap',
            'desc' => 'Mgawanyo wa gredi (A–F/ABS) kwa kila somo.'],
        'teachers' => ['title' => 'Overall Teachers Ranking', 'file' => 'results_teachers.php', 'icon' => 'person-badge',
            'desc' => 'Walimu waliopangwa kwa wastani wa alama.'],
        'top10' => ['title' => 'Overall Ten Students', 'file' => 'results_top_students.php', 'icon' => 'star',
            'desc' => 'Wanafunzi 10 bora kwa pointi na division.'],
        'top10-subj' => ['title' => 'Overall Ten Students Subjectwise', 'file' => 'results_top_students_subjectwise.php', 'icon' => 'list-stars',
            'desc' => 'Wanafunzi 10 bora kwa kila somo.'],
        'teachers-subj' => ['title' => "Teachers' Ranking Subjectwise", 'file' => 'results_teachers_subjectwise.php', 'icon' => 'award',
            'desc' => 'Walimu bora kwa kila somo.'],
        'general' => ['title' => "General schools' Results", 'file' => 'results_general.php', 'icon' => 'buildings',
            'desc' => 'Muhtasari kamili wa matokeo ya kila shule.'],
    ];
}

/** Kundi la shule kwa ward/district/region, likiwa limepangwa. */
function ra_group(array $sAgg, $field) {
    $g = [];
    foreach ($sAgg as $s) {
        $k = trim((string)$s[$field]);
        if ($k === '') $k = 'Not set';
        if (!isset($g[$k])) $g[$k] = ['name' => $k, 'schools' => 0, 'cands' => 0, 'ok' => 0, 'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0,
            'INC' => 0, 'ABS' => 0, 'ptsSum' => 0, 'avgSum' => 0.0, 'avgN' => 0,
            'divPtsSum' => 0, 'divPtsN' => 0, 'subjPtsSum' => 0.0, 'subjPtsN' => 0];
        $g[$k]['schools']++;
        foreach (['cands', 'ok', 'I', 'II', 'III', 'IV', '0', 'INC', 'ABS', 'ptsSum'] as $f) $g[$k][$f] += $s[$f];
        $g[$k]['avgSum'] += $s['avgSum']; $g[$k]['avgN'] += $s['avgN'];
        $g[$k]['divPtsSum'] += $s['divPtsSum']; $g[$k]['divPtsN'] += $s['divPtsN'];
        $g[$k]['subjPtsSum'] += $s['subjPtsSum']; $g[$k]['subjPtsN'] += $s['subjPtsN'];
    }
    foreach ($g as $k => $v) {
        $divGpa = $v['divPtsN'] ? ($v['divPtsSum'] / $v['divPtsN']) : null;
        $subjGpa = $v['subjPtsN'] ? ($v['subjPtsSum'] / $v['subjPtsN']) : null;
        if ($divGpa === null && $subjGpa === null) $gpa = null;
        elseif ($divGpa === null) $gpa = $subjGpa;
        elseif ($subjGpa === null) $gpa = $divGpa;
        else $gpa = ($divGpa + $subjGpa) / 2;
        $g[$k]['gpa'] = $gpa !== null ? round($gpa, 2) : null;
        $g[$k]['avg'] = $v['avgN'] ? round($v['avgSum'] / $v['avgN'], 1) : null;
        $g[$k]['pass_pct'] = $v['ok'] ? round(($v['I'] + $v['II'] + $v['III'] + $v['IV']) * 100 / $v['ok'], 1) : null;
    }
    uasort($g, function ($a, $b) {
        if ($a['gpa'] === null && $b['gpa'] === null) return strcmp($a['name'], $b['name']);
        if ($a['gpa'] === null) return 1;
        if ($b['gpa'] === null) return -1;
        if ($a['gpa'] != $b['gpa']) return $a['gpa'] <=> $b['gpa'];
        return $b['I'] <=> $a['I'];
    });
    $rank = 0;
    foreach ($g as $k => $v) { $g[$k]['rank'] = $v['gpa'] !== null ? ++$rank : null; }
    return $g;
}

/** Kokotoa takwimu zote za mtihani mmoja. */
function ra_build($exam_id) {
    $raCache = [];

    $classes = exam_class_list($exam_id);
    $schools = array_values(array_filter(exam_school_list($exam_id), function ($s) { return (int)$s['published'] === 1; }));

    $students = [];   // watahiniwa wote
    $sAgg = [];       // school_id => takwimu
    $subj = [];       // subject_id => takwimu
    $scoreMap = [];   // school_id => class_id => subject_id => [rows]
    $gradeOrder = ['A', 'B', 'C', 'D', 'E', 'S', 'F', 'ABS'];

    foreach ($schools as $sc) {
        $sid = (int)$sc['id'];
        $sAgg[$sid] = [
            'id' => $sid, 'name' => $sc['name'], 'centre' => school_centre($sc),
            'ward' => trim((string)($sc['ward'] ?? '')), 'district' => trim((string)($sc['district'] ?? '')), 'region' => trim((string)($sc['region'] ?? '')),
            'classes' => 0, 'cands' => 0, 'ok' => 0, 'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0, 'INC' => 0, 'ABS' => 0, 'PENDING' => 0,
            'ptsSum' => 0, 'avgSum' => 0.0, 'avgN' => 0,
            'divPtsSum' => 0, 'divPtsN' => 0, 'subjPtsSum' => 0.0, 'subjPtsN' => 0,
        ];
        foreach ($classes as $cl) {
            $cid = (int)$cl['id'];
            $key = $exam_id . ':' . $cid . ':' . $sid;
            if (isset($raCache[$key])) { $d = $raCache[$key]; }
            else { $d = compute_results($exam_id, $cid, $sid); $raCache[$key] = $d; }
            if (!$d) continue;
            $sAgg[$sid]['classes']++;
            foreach ($d['candidates'] as $cand) {
                $sAgg[$sid]['cands']++;
                $state = $cand['state'];
                if ($state === 'OK') {
                    $sAgg[$sid]['ok']++; $sAgg[$sid][$cand['division']]++; $sAgg[$sid]['ptsSum'] += (int)$cand['points'];
                    $divMap = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, '0' => 5];
                    if (isset($divMap[$cand['division']])) { $sAgg[$sid]['divPtsSum'] += $divMap[$cand['division']]; $sAgg[$sid]['divPtsN']++; }
                }
                elseif ($state === 'INC') $sAgg[$sid]['INC']++;
                elseif ($state === 'ABS') $sAgg[$sid]['ABS']++;
                else $sAgg[$sid]['PENDING']++;
                if ($cand['average'] !== null) { $sAgg[$sid]['avgSum'] += (float)$cand['average']; $sAgg[$sid]['avgN']++; }
                $row = $cand;
                $row['school_id'] = $sid; $row['school'] = $sc['name']; $row['school_centre'] = school_centre($sc);
                $row['ward'] = $sAgg[$sid]['ward']; $row['district'] = $sAgg[$sid]['district']; $row['region'] = $sAgg[$sid]['region'];
                $row['class_id'] = $cid; $row['class_name'] = $cl['name'];
                $students[] = $row;
                foreach ($cand['subjects'] as $r) {
                    $scoreMap[$sid][$cid][(int)$r['subject_id']][] = $r + ['student' => $cand['name'], 'cand_no' => $cand['cand_no'],
                        'class_name' => $cl['name'], 'school' => $sc['name'], 'school_id' => $sid, 'sex' => $cand['sex']];
                }
            }
            foreach ($d['subjStats'] as $st) {
                $kid = (int)$st['subject_id'];
                if (!isset($subj[$kid])) {
                    $subj[$kid] = ['id' => $kid, 'name' => $st['name'], 'code' => $st['code'], 'abbr' => $st['abbr'], 'subsidiary' => $st['subsidiary'],
                        'sat' => 0, 'absent' => 0, 'pass' => 0, 'ptsSum' => 0, 'ptsN' => 0, 'scoreSum' => 0, 'grades' => []];
                }
                $subj[$kid]['sat'] += (int)$st['sat'];
                $subj[$kid]['absent'] += (int)$st['absent'];
                $subj[$kid]['pass'] += (int)$st['pass'];
                $subj[$kid]['ptsSum'] += (float)$st['ptsSum'];
                $subj[$kid]['ptsN'] += (int)$st['ptsN'];
                $subj[$kid]['scoreSum'] += (float)$st['scoreSum'];
                $sAgg[$sid]['subjPtsSum'] += (float)$st['ptsSum'];
                $sAgg[$sid]['subjPtsN'] += (int)$st['ptsN'];
                foreach ($st['grades'] as $g => $n) $subj[$kid]['grades'][$g] = ($subj[$kid]['grades'][$g] ?? 0) + (int)$n;
            }
        }
    }

    foreach ($sAgg as $sid => $s) {
        $divGpa = $s['divPtsN'] ? ($s['divPtsSum'] / $s['divPtsN']) : null;
        $subjGpa = $s['subjPtsN'] ? ($s['subjPtsSum'] / $s['subjPtsN']) : null;
        if ($divGpa === null && $subjGpa === null) $gpa = null;
        elseif ($divGpa === null) $gpa = $subjGpa;
        elseif ($subjGpa === null) $gpa = $divGpa;
        else $gpa = ($divGpa + $subjGpa) / 2;
        $sAgg[$sid]['gpa'] = $gpa !== null ? round($gpa, 2) : null;
        $sAgg[$sid]['avg'] = $s['avgN'] ? round($s['avgSum'] / $s['avgN'], 1) : null;
        $sAgg[$sid]['pass_pct'] = $s['ok'] ? round(($s['I'] + $s['II'] + $s['III'] + $s['IV']) * 100 / $s['ok'], 1) : null;
    }
    foreach ($subj as $kid => $s) {
        $subj[$kid]['avg'] = $s['sat'] ? round($s['scoreSum'] / $s['sat'], 1) : null;
        $subj[$kid]['gpa'] = $s['ptsN'] ? round($s['ptsSum'] / $s['ptsN'], 2) : null;
        $subj[$kid]['pass_pct'] = $s['sat'] ? round($s['pass'] * 100 / $s['sat'], 1) : null;
    }

    $rankSchools = $sAgg;
    uasort($rankSchools, function ($a, $b) {
        if ($a['gpa'] === null && $b['gpa'] === null) return strcmp($a['name'], $b['name']);
        if ($a['gpa'] === null) return 1;
        if ($b['gpa'] === null) return -1;
        if ($a['gpa'] != $b['gpa']) return $a['gpa'] <=> $b['gpa'];
        return $b['I'] <=> $a['I'];
    });
    $rk = 0; foreach ($rankSchools as $sid => $s) { if ($s['gpa'] !== null) $rankSchools[$sid]['rank'] = ++$rk; else $rankSchools[$sid]['rank'] = null; }

    $wardRows = ra_group($sAgg, 'ward');
    $districtRows = ra_group($sAgg, 'district');
    $regionRows = ra_group($sAgg, 'region');

    $rankSubjects = $subj;
    uasort($rankSubjects, function ($a, $b) {
        if ($a['avg'] === null && $b['avg'] === null) return strcmp($a['name'], $b['name']);
        if ($a['avg'] === null) return 1;
        if ($b['avg'] === null) return -1;
        if ($a['avg'] != $b['avg']) return $b['avg'] <=> $a['avg'];
        return $a['gpa'] <=> $b['gpa'];
    });
    $srk = 0; foreach ($rankSubjects as $kid => $s) $rankSubjects[$kid]['rank'] = ++$srk;

    /* Walimu: kazi zao na alama za wanafunzi wao */
    $teacherRows = [];
    $teacherSubj = [];
    if ($sAgg) {
        $in = implode(',', array_map('intval', array_keys($sAgg)));
        try {
            $assignments = q_all("SELECT ta.teacher_id, ta.class_id, ta.subject_id, u.full_name, u.school_id, sc.name AS school_name, s.name AS subject_name
                FROM teacher_assignments ta
                JOIN users u ON u.id = ta.teacher_id
                JOIN schools sc ON sc.id = u.school_id
                JOIN subjects s ON s.id = ta.subject_id
                WHERE u.school_id IN ($in)", []);
        } catch (Exception $e) { $assignments = []; }
        $seen = [];
        foreach ($assignments as $a) {
            $tid = (int)$a['teacher_id']; $cid = (int)$a['class_id']; $kid = (int)$a['subject_id']; $sid = (int)$a['school_id'];
            $key = $tid . ':' . $cid . ':' . $kid . ':' . $sid;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $rows = $scoreMap[$sid][$cid][$kid] ?? [];
            $scores = []; $pts = [];
            foreach ($rows as $r) {
                if ($r['score'] === null) continue;
                $scores[] = (float)$r['score'];
                if ($r['points'] !== null) $pts[] = (int)$r['points'];
            }
            if (!$scores) continue;
            if (!isset($teacherRows[$tid])) {
                $teacherRows[$tid] = ['id' => $tid, 'name' => $a['full_name'], 'school' => $a['school_name'], 'school_id' => $sid,
                    'classes' => [], 'subjects' => [], 'entries' => 0, 'scoreSum' => 0.0, 'ptsSum' => 0, 'ptsN' => 0];
            }
            $t = &$teacherRows[$tid];
            $t['classes'][$cid] = true;
            $t['subjects'][$kid] = $a['subject_name'];
            $t['entries'] += count($scores);
            $t['scoreSum'] += array_sum($scores);
            $t['ptsSum'] += array_sum($pts);
            $t['ptsN'] += count($pts);
            unset($t);
            if (!isset($teacherSubj[$kid][$tid])) {
                $teacherSubj[$kid][$tid] = ['id' => $tid, 'name' => $a['full_name'], 'school' => $a['school_name'],
                    'entries' => 0, 'scoreSum' => 0.0, 'ptsSum' => 0, 'ptsN' => 0];
            }
            $ts = &$teacherSubj[$kid][$tid];
            $ts['entries'] += count($scores);
            $ts['scoreSum'] += array_sum($scores);
            $ts['ptsSum'] += array_sum($pts);
            $ts['ptsN'] += count($pts);
            unset($ts);
        }
    }
    foreach ($teacherRows as $tid => $t) {
        $teacherRows[$tid]['avg'] = $t['entries'] ? round($t['scoreSum'] / $t['entries'], 1) : null;
        $teacherRows[$tid]['gpa'] = $t['ptsN'] ? round($t['ptsSum'] / $t['ptsN'], 2) : null;
        $teacherRows[$tid]['n_classes'] = count($t['classes']);
        $teacherRows[$tid]['n_subjects'] = count($t['subjects']);
    }
    uasort($teacherRows, function ($a, $b) {
        if ($a['avg'] === null && $b['avg'] === null) return strcmp($a['name'], $b['name']);
        if ($a['avg'] === null) return 1;
        if ($b['avg'] === null) return -1;
        if ($a['avg'] != $b['avg']) return $b['avg'] <=> $a['avg'];
        return $a['gpa'] <=> $b['gpa'];
    });
    $trk = 0; foreach ($teacherRows as $tid => $t) $teacherRows[$tid]['rank'] = ++$trk;

    foreach ($teacherSubj as $kid => $list) {
        foreach ($list as $tid => $t) {
            $teacherSubj[$kid][$tid]['avg'] = $t['entries'] ? round($t['scoreSum'] / $t['entries'], 1) : null;
            $teacherSubj[$kid][$tid]['gpa'] = $t['ptsN'] ? round($t['ptsSum'] / $t['ptsN'], 2) : null;
        }
        uasort($teacherSubj[$kid], function ($a, $b) {
            if ($a['avg'] == $b['avg']) return $a['gpa'] <=> $b['gpa'];
            return $b['avg'] <=> $a['avg'];
        });
    }

    $topStudents = array_values(array_filter($students, function ($s) { return $s['state'] === 'OK' && $s['points'] !== null; }));
    usort($topStudents, function ($a, $b) {
        if ($a['points'] !== $b['points']) return $a['points'] <=> $b['points'];
        return $b['average'] <=> $a['average'];
    });
    $top10 = array_slice($topStudents, 0, 10);

    $gradeCols = [];
    foreach ($subj as $s) foreach (array_keys($s['grades']) as $g) $gradeCols[$g] = true;
    $gradeCols = array_keys($gradeCols);
    usort($gradeCols, function ($a, $b) use ($gradeOrder) {
        $ia = array_search($a, $gradeOrder, true); $ib = array_search($b, $gradeOrder, true);
        if ($ia === false) $ia = 99; if ($ib === false) $ib = 99;
        return $ia <=> $ib;
    });

    $totCands = count($students);
    $totOk = 0; foreach ($sAgg as $s) $totOk += $s['ok'];
    $totDivPtsSum = 0; $totDivPtsN = 0; $totSubjPtsSum = 0.0; $totSubjPtsN = 0;
    foreach ($sAgg as $s) {
        $totDivPtsSum += $s['divPtsSum']; $totDivPtsN += $s['divPtsN'];
        $totSubjPtsSum += $s['subjPtsSum']; $totSubjPtsN += $s['subjPtsN'];
    }
    $divGpa = $totDivPtsN ? ($totDivPtsSum / $totDivPtsN) : null;
    $subjGpa = $totSubjPtsN ? ($totSubjPtsSum / $totSubjPtsN) : null;
    if ($divGpa === null && $subjGpa === null) $overallGpa = null;
    elseif ($divGpa === null) $overallGpa = round($subjGpa, 2);
    elseif ($subjGpa === null) $overallGpa = round($divGpa, 2);
    else $overallGpa = round(($divGpa + $subjGpa) / 2, 2);

    return compact('classes', 'schools', 'students', 'sAgg', 'subj', 'scoreMap', 'gradeOrder',
        'rankSchools', 'wardRows', 'districtRows', 'regionRows', 'rankSubjects', 'teacherRows', 'teacherSubj',
        'topStudents', 'top10', 'gradeCols', 'totCands', 'totOk', 'overallGpa');
}

/** Mgawanyo wa divisions na kiwango cha ufaulu. */
function ra_div_totals(array $sAgg) {
    $divCounts = ['I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0];
    $totOk = 0;
    foreach ($sAgg as $s) {
        $totOk += (int)$s['ok'];
        foreach (array_keys($divCounts) as $k) $divCounts[$k] += (int)$s[$k];
    }
    $passTotal = $divCounts['I'] + $divCounts['II'] + $divCounts['III'] + $divCounts['IV'];
    return [$divCounts, $passTotal, $totOk, $totOk ? round($passTotal * 100 / $totOk, 1) : null];
}

/** CSS ya pamoja ya kurasa zote za uchambuzi. */
function ra_styles() { ?>
<style>
.min-w-0{min-width:0}
.ra-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.ra-title{font-size:1.35rem;font-weight:800;color:#0b3d91;letter-spacing:-.01em;margin:0}
.ra-sub{font-size:.82rem;color:#8a99b0;margin:2px 0 0}
.ra-nav{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;position:sticky;top:0;z-index:15;background:rgba(244,247,253,.95);backdrop-filter:blur(3px);padding:8px 0;border-radius:12px}
.ra-nav a{font-size:.76rem;font-weight:700;text-decoration:none;color:#33415c;background:#fff;border:1px solid #e2e9f6;border-radius:999px;padding:5px 11px}
.ra-nav a:hover{background:#0b3d91;border-color:#0b3d91;color:#fff}
.ra-nav a.active{background:#0b3d91;border-color:#0b3d91;color:#fff}
.ra-sec{margin-bottom:18px;scroll-margin-top:70px;break-inside:avoid}
.ra-sec-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:.8rem 1.05rem;border-bottom:1px solid #eef2fa}
.ra-sec-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.ra-sec-h .t i{color:#2c6ed5}
.ra-pill{display:inline-flex;align-items:center;gap:5px;font-size:.74rem;font-weight:700;border-radius:999px;padding:3px 9px;background:#f2f5fc;color:#4a5b78;border:1px solid #e5ebf7}
.ra-table th{font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;background:#f6f8fd;color:#5b6b85}
.ra-table td{font-size:.82rem}
.ra-table thead th{position:sticky;top:0;z-index:2}
.ra-rank{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:7px;background:#eef3fd;color:#0b3d91;font-weight:800;font-size:.74rem}
.ra-rank.top{background:linear-gradient(135deg,#b06a00,#e8a019);color:#fff}
.ra-school{font-weight:700;color:#16203a;line-height:1.25}
.ra-muted{font-size:.72rem;color:#8a99b0}
.ra-badge{font-size:.68rem;font-weight:700;border-radius:6px;padding:2px 6px}
.ra-bar{height:6px;border-radius:99px;background:#eef2fa;overflow:hidden;min-width:60px}
.ra-bar span{display:block;height:100%;background:linear-gradient(90deg,#0b3d91,#2c6ed5)}
.ra-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:.7rem .9rem;border-bottom:1px solid #eef2fa;background:#fbfcff;max-height:150px;overflow:auto}
.ra-tabs button{font-size:.74rem;font-weight:700;border:1px solid #e2e9f6;background:#fff;color:#4a5b78;border-radius:999px;padding:4px 10px}
.ra-tabs button.active{background:#0b3d91;border-color:#0b3d91;color:#fff}
.ra-empty{padding:2rem;text-align:center;color:#8a99b0}
.ra-lock-note{display:flex;align-items:center;gap:10px;background:#fff8e6;border:1px solid #ffe1a3;color:#7a5300;border-radius:12px;padding:11px 14px;font-size:.83rem;margin-bottom:14px}
.ra-lock-note i{font-size:1rem;color:#b06a00}
.ra-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:10px;margin-bottom:12px}
.ra-stat{background:#fff;border:1px solid #e6ecf8;border-radius:12px;padding:12px 13px;display:flex;align-items:center;gap:11px;box-shadow:0 2px 8px rgba(11,61,145,.05)}
.ra-stat .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eef3fd;color:#0b3d91;font-size:1rem;flex:0 0 auto}
.ra-stat .v{font-size:1.14rem;font-weight:800;color:#16203a;line-height:1.1}
.ra-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#8a99b0;font-weight:700;margin-top:2px}
.ra-div{background:#fff;border:1px solid #e6ecf8;border-radius:12px;padding:12px 15px;margin-bottom:16px;box-shadow:0 2px 8px rgba(11,61,145,.05)}
.ra-div-top{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px;align-items:baseline}
.ra-div-top b{font-size:.86rem;color:#16203a}
.ra-div-top .muted{font-size:.72rem;color:#8a99b0}
.ra-div-bar{display:flex;height:14px;border-radius:999px;overflow:hidden;background:#eef2fa;margin:9px 0 10px}
.ra-div-bar span{display:block;height:100%}
.ra-div-legend{display:flex;flex-wrap:wrap;gap:8px 16px}
.ra-div-legend span{font-size:.76rem;color:#4a5b78;display:inline-flex;align-items:center;gap:6px}
.ra-dot{width:9px;height:9px;border-radius:3px;display:inline-block;flex:0 0 auto}
.ra-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-bottom:16px}
.ra-card{display:block;background:#fff;border:1px solid #e6ecf8;border-radius:14px;padding:14px 15px;text-decoration:none;color:inherit;box-shadow:0 2px 8px rgba(11,61,145,.05);transition:transform .15s,box-shadow .15s,border-color .15s;height:100%}
.ra-card:hover{transform:translateY(-2px);border-color:#c9d9f4;box-shadow:0 8px 20px rgba(11,61,145,.12);color:inherit}
.ra-card .ct{display:flex;align-items:center;gap:9px;font-weight:800;color:#16203a;font-size:.9rem}
.ra-card .ct i{width:30px;height:30px;border-radius:9px;background:#eef3fd;color:#0b3d91;display:grid;place-items:center;flex:0 0 auto}
.ra-card .cd{font-size:.75rem;color:#8a99b0;margin-top:6px;line-height:1.4}
.ra-card .go{font-size:.72rem;font-weight:700;color:#2c6ed5;margin-top:8px;display:inline-flex;align-items:center;gap:4px}
@media print{
  .side,.topbar,.ra-nav,.ra-grid,.no-print{display:none!important}
  .main{margin-left:0!important}
  .container-fluid{padding:0!important}
  .ra-head{margin-bottom:6px}
  .ra-stat,.ra-div,.ra-sec{box-shadow:none!important}
  .ra-sec{page-break-inside:avoid}
  .ra-table thead th{position:static!important}
  @page{size:A4 landscape;margin:8mm}
}
@media(max-width:575px){.ra-title{font-size:1.15rem}}
</style>
<?php }

/** Kadi ya "hakuna matokeo bado". */
function ra_no_exam() { ?>
<div class="card"><div class="ra-empty">
  <i class="bi bi-bar-chart-line d-block fs-2 mb-2"></i>
  <div class="fw-bold text-dark mb-1">No published results yet</div>
  <div class="small">Results of all schools appear here once schools (or a district exam) publish them.</div>
  <a class="btn btn-primary btn-sm mt-3" href="exams.php"><i class="bi bi-journal-text me-1"></i>Go to District Exams</a>
</div></div>
<?php }

/** Kichwa cha ukurasa + kichaguzi cha mtihani + PDF/Print. */
function ra_page_head($pageTitle, $icon, $exam, $exam_id, $hasData, $exams, $showBack = true) { ?>
<div class="ra-head">
  <div>
    <?php if ($showBack): ?>
      <a class="btn btn-outline-secondary btn-sm mb-2 no-print" href="results_analysis.php?exam_id=<?php echo (int)$exam_id; ?>"><i class="bi bi-arrow-left me-1"></i>Back to Results Analysis</a>
    <?php endif; ?>
    <h1 class="ra-title"><i class="bi bi-<?php echo h($icon); ?> me-1"></i><?php echo h($pageTitle); ?></h1>
    <p class="ra-sub"><?php echo h($exam['name']); ?><?php echo $exam['year_name'] ? ' (' . h($exam['year_name']) . ')' : ''; ?> &middot; published results of all schools</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <form method="get" class="d-flex align-items-center gap-2">
      <select name="exam_id" class="form-select form-select-sm" style="min-width:230px" onchange="this.form.submit()">
        <?php foreach ($exams as $e): ?>
          <option value="<?php echo (int)$e['id']; ?>"<?php echo (int)$e['id'] === (int)$exam_id ? ' selected' : ''; ?>><?php echo h($e['name'] . ($e['year_name'] ? ' - ' . $e['year_name'] : '')); ?> (<?php echo (int)$e['pub']; ?>)</option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-outline-primary btn-sm">Show</button></noscript>
    </form>
    <a class="btn btn-danger btn-sm no-print" href="?exam_id=<?php echo (int)$exam_id; ?>&export=pdf" download title="Download this section as PDF"<?php echo $hasData ? '' : ' style="pointer-events:none;opacity:.55" aria-disabled="true" tabindex="-1"'; ?>><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
    <button type="button" class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"<?php echo $hasData ? '' : ' disabled title="Available after the results are published"'; ?>><i class="bi bi-printer me-1"></i>Print</button>
  </div>
</div>
<?php if (!$hasData): ?>
  <div class="ra-lock-note"><i class="bi bi-lock-fill"></i><span><b>Results are not published yet.</b> This section becomes active as soon as this exam's results are published.</span></div>
<?php endif; ?>
<?php }

/** Menyu ya kurasa za sehemu. */
function ra_nav($exam_id, $current) { ?>
<div class="ra-nav">
  <?php foreach (ra_sections() as $key => $s): ?>
    <a href="<?php echo h($s['file'] . '?exam_id=' . (int)$exam_id); ?>" class="<?php echo $key === $current ? 'active' : ''; ?>"><?php echo h($s['title']); ?></a>
  <?php endforeach; ?>
</div>
<?php }

/** JS ya tabo za somo (top 10 subjectwise / teachers subjectwise). */
function ra_scripts() { ?>
<script>
document.querySelectorAll('.ra-tabwrap').forEach(function (wrap) {
  var tabs = wrap.querySelectorAll('.ra-tabs button');
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      tabs.forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      wrap.querySelectorAll('.ra-pane').forEach(function (p) { p.classList.add('d-none'); });
      var t = wrap.querySelector(b.getAttribute('data-target'));
      if (t) t.classList.remove('d-none');
    });
  });
});
</script>
<?php }

/** Jedwali la HTML linalotumika kwenye PDF. */
function ra_pdf_table(array $head, array $rows, $empty = 'No data.') {
    if (!$rows) return '<div class="empty">' . h($empty) . '</div>';
    $t = '<table><thead><tr>';
    foreach ($head as $th) $t .= '<th>' . h($th) . '</th>';
    $t .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $t .= '<tr>';
        foreach ($r as $cell) $t .= '<td>' . h((string)$cell) . '</td>';
        $t .= '</tr>';
    }
    return $t . '</tbody></table>';
}

/**
 * Tengeneza na tuma PDF. $only = key ya sehemu (au null kwa nyaraka yote).
 */
function ra_export_pdf($exam, array $d, $only = null) {
    require_once __DIR__ . '/export.php';
    extract($d);

    $n2 = function ($v) { return $v === null ? '-' : number_format((float)$v, 2); };
    $n1 = function ($v) { return $v === null ? '-' : number_format((float)$v, 1); };
    $pc = function ($v) { return $v === null ? '-' : number_format((float)$v, 1) . '%'; };
    $secs = ra_sections();
    $want = function ($key) use ($only) { return $only === null || $only === $key; };
    $num = function ($n, $title) use ($only) { return $only === null ? $n . '. ' . $title : $title; };

    $html = '<style>
      body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:8.5px;color:#16203a}
      h1{font-size:16px;color:#0b3d91;margin:0 0 2px}
      .sub{font-size:9px;color:#5b6b85;margin:0 0 8px}
      .sum{font-size:8.5px;color:#33415c;margin:0 0 10px}
      h2{font-size:10.5px;color:#0b3d91;margin:16px 0 4px;padding-bottom:2px;border-bottom:1px solid #cddaf0}
      h3{font-size:9px;color:#33415c;margin:10px 0 3px}
      table{width:100%;border-collapse:collapse;margin-bottom:4px}
      th{background:#eef3fd;color:#0b3d91;font-size:7.5px;text-transform:uppercase;text-align:left;padding:3px 4px;border:1px solid #d5e0f2}
      td{border:1px solid #e4ebf7;padding:2.5px 4px;vertical-align:top}
      .empty{text-align:center;color:#8a99b0}
      .foot{margin-top:14px;font-size:7.5px;color:#8a99b0}
    </style>';
    $html .= '<h1>' . h($only !== null ? $secs[$only]['title'] : 'Results Analysis') . '</h1>';
    $html .= '<div class="sub">' . h(strtoupper(system_name())) . ' &middot; ' . h($exam['name'])
           . ($exam['year_name'] ? ' (' . h($exam['year_name']) . ')' : '') . '</div>';
    $html .= '<div class="sum">Published schools: <b>' . count($sAgg) . '</b> &nbsp;|&nbsp; Candidates: <b>' . $totCands
           . '</b> &nbsp;|&nbsp; Classes: <b>' . count($classes) . '</b> &nbsp;|&nbsp; Subjects: <b>' . count($subj)
           . '</b> &nbsp;|&nbsp; Overall GPA: <b>' . $n2($overallGpa) . '</b></div>';

    /* 1. Schools Ranking */
    if ($want('schools')) {
        $rows = [];
        foreach ($rankSchools as $x) {
            $rows[] = [$x['rank'] === null ? '-' : (int)$x['rank'], strtoupper($x['name']), $x['centre'], $x['ward'] !== '' ? $x['ward'] : '-',
                (int)$x['cands'], (int)$x['I'], (int)$x['II'], (int)$x['III'], (int)$x['IV'], (int)$x['0'], $n2($x['gpa']), $n1($x['avg']), $pc($x['pass_pct'])];
        }
        $html .= '<h2>' . h($num(1, 'Schools Ranking')) . '</h2>' . ra_pdf_table(['#', 'School', 'Centre', 'Ward', 'Cands', 'I', 'II', 'III', 'IV', '0', 'GPA', 'Avg', 'Pass %'], $rows);
    }

    /* 2-4. Wards / Districts / Regions */
    $gn = 2;
    foreach ([['wards', 'Wards Ranking', $wardRows, 'Ward'], ['districts', 'Districts Ranking', $districtRows, 'District'], ['regions', 'Regions Ranking', $regionRows, 'Region']] as $g) {
        if ($want($g[0])) {
            $rows = [];
            foreach ($g[2] as $x) $rows[] = [$x['rank'] === null ? '-' : (int)$x['rank'], $x['name'], (int)$x['schools'], (int)$x['cands'], $n2($x['gpa']), $n1($x['avg']), $pc($x['pass_pct'])];
            $html .= '<h2>' . h($num($gn, $g[1])) . '</h2>' . ra_pdf_table(['#', $g[3], 'Schools', 'Cands', 'GPA', 'Avg', 'Pass %'], $rows);
        }
        $gn++;
    }

    /* 5. Subjects Ranking */
    if ($want('subjects')) {
        $rows = [];
        foreach ($rankSubjects as $x) $rows[] = [(int)$x['rank'], $x['name'], $x['code'], (int)$x['sat'], (int)$x['absent'], (int)$x['pass'], $n1($x['avg']), $n2($x['gpa']), $pc($x['pass_pct'])];
        $html .= '<h2>' . h($num(5, 'Subjects Ranking')) . '</h2>' . ra_pdf_table(['#', 'Subject', 'Code', 'Sat', 'Absent', 'Pass', 'Avg', 'GPA', 'Pass %'], $rows);
    }

    /* 6. Overall Subjects Analysis */
    if ($want('subj-analysis')) {
        $rows = [];
        foreach ($subj as $x) $rows[] = [$x['name'], $x['code'], (int)$x['sat'], (int)$x['absent'], (int)$x['pass'], $pc($x['pass_pct']), $n1($x['avg']), $n2($x['gpa'])];
        $html .= '<h2>' . h($num(6, 'Overall Subjects Analysis')) . '</h2>' . ra_pdf_table(['Subject', 'Code', 'Sat', 'Absent', 'Pass', 'Pass %', 'Avg', 'GPA'], $rows);
    }

    /* 7. Grades Analysis Subjectwise */
    if ($want('grades')) {
        $head = array_merge(['Subject'], $gradeCols, ['Total']);
        $rows = [];
        foreach ($subj as $x) {
            $r = [$x['name']]; $tot = 0;
            foreach ($gradeCols as $g) { $n = (int)($x['grades'][$g] ?? 0); $tot += $n; $r[] = $n; }
            $r[] = $tot;
            $rows[] = $r;
        }
        $html .= '<h2>' . h($num(7, 'Grades Analysis Subjectwise')) . '</h2>' . ra_pdf_table($head, $rows);
    }

    /* 8. Overall Teachers Ranking */
    if ($want('teachers')) {
        $rows = [];
        foreach ($teacherRows as $x) $rows[] = [(int)$x['rank'], $x['name'], $x['school'], implode(', ', $x['subjects']), (int)$x['n_classes'], (int)$x['entries'], $n1($x['avg']), $n2($x['gpa'])];
        $html .= '<h2>' . h($num(8, 'Overall Teachers Ranking')) . '</h2>' . ra_pdf_table(['#', 'Teacher', 'School', 'Subjects', 'Classes', 'Entries', 'Avg', 'GPA'], $rows, 'No teacher assignments with scores.');
    }

    /* 9. Overall Ten Students */
    if ($want('top10')) {
        $rows = [];
        foreach ($top10 as $i => $x) $rows[] = [$i + 1, $x['name'], $x['cand_no'], $x['school'], $x['class_name'], $x['division'], (int)$x['points'], number_format((float)$x['average'], 2)];
        $html .= '<h2>' . h($num(9, 'Overall Ten Students')) . '</h2>' . ra_pdf_table(['#', 'Student', 'Cand No', 'School', 'Class', 'Division', 'Points', 'Average'], $rows, 'No ranked candidates.');
    }

    /* 10. Overall Ten Students Subjectwise */
    if ($want('top10-subj')) {
        $html .= '<h2>' . h($num(10, 'Overall Ten Students Subjectwise')) . '</h2>';
        if (!$subj) $html .= '<div class="sub">No subject scores yet.</div>';
        foreach ($subj as $kid => $si) {
            $list = [];
            foreach (array_keys($scoreMap) as $sid) foreach (($scoreMap[$sid] ?? []) as $cidRows) foreach (($cidRows[$kid] ?? []) as $r) if ($r['score'] !== null) $list[] = $r;
            usort($list, function ($a, $b) { return (float)$b['score'] <=> (float)$a['score']; });
            $rows = [];
            foreach (array_slice($list, 0, 10) as $i => $r) $rows[] = [$i + 1, $r['student'], $r['cand_no'], $r['school'], $r['class_name'], number_format((float)$r['score'], 1), $r['grade']];
            $html .= '<h3>' . h($si['name']) . ' (' . h($si['code']) . ')</h3>' . ra_pdf_table(['#', 'Student', 'Cand No', 'School', 'Class', 'Score', 'Grade'], $rows);
        }
    }

    /* 11. Teachers' Ranking Subjectwise */
    if ($want('teachers-subj')) {
        $html .= '<h2>' . h($num(11, "Teachers' Ranking Subjectwise")) . '</h2>';
        if (!$teacherSubj) $html .= '<div class="sub">No teacher assignments with scores yet.</div>';
        foreach ($teacherSubj as $kid => $list) {
            $rows = []; $i = 0;
            foreach ($list as $x) { $i++; $rows[] = [$i, $x['name'], $x['school'], (int)$x['entries'], $n1($x['avg']), $n2($x['gpa'])]; }
            $html .= '<h3>' . h($subj[$kid]['name'] ?? ('Subject ' . $kid)) . '</h3>' . ra_pdf_table(['#', 'Teacher', 'School', 'Entries', 'Avg', 'GPA'], $rows);
        }
    }

    /* 12. General schools' Results */
    if ($want('general')) {
        $rows = [];
        foreach ($sAgg as $x) $rows[] = [strtoupper($x['name']), $x['centre'], (int)$x['classes'], (int)$x['cands'], (int)$x['ok'], (int)$x['INC'], (int)$x['ABS'],
            (int)$x['I'], (int)$x['II'], (int)$x['III'], (int)$x['IV'], (int)$x['0'], $n2($x['gpa']), $pc($x['pass_pct'])];
        $html .= '<h2>' . h($num(12, "General schools' Results")) . '</h2>' . ra_pdf_table(['School', 'Centre', 'Classes', 'Cands', 'Ranked', 'INC', 'ABS', 'I', 'II', 'III', 'IV', '0', 'GPA', 'Pass %'], $rows);
    }

    $html .= '<div class="foot">Generated ' . h(date('d M Y H:i')) . ' &middot; ' . h(system_name()) . '</div>';

    $label = $only !== null ? $secs[$only]['title'] : 'Results Analysis';
    $fname = preg_replace('/[^A-Za-z0-9]+/', '_', $label . '_' . $exam['name'] . '_' . (string)$exam['year_name']);
    send_pdf($html, $label . ' - ' . $exam['name'], $fname, true);
}
