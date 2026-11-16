<?php
/**
 * NGASIS - Uonyeshaji wa matokeo kwa muundo unaofanana na matokeo ya NECTA
 * (HTML safi inayotumika kwenye skrini, print, na PDF/Dompdf).
 */
require_once __DIR__ . '/grading.php';

function level_title($lvl) {
    return $lvl === 'A' ? 'ADVANCED CERTIFICATE OF SECONDARY EDUCATION' : 'CERTIFICATE OF SECONDARY EDUCATION';
}

function results_css() {
    return '<style>
    .ng-doc{font-family:"Times New Roman",Times,serif;color:#000;font-size:11px;background:#F5F5DC;border:2px solid #12314f;padding:10px 12px;font-weight:bold}
    .ng-doc table{border-collapse:collapse;width:100%;margin-bottom:12px;background:#F5F5DC}
    .ng-doc th,.ng-doc td{border:1px solid #12314f;padding:3px 5px;vertical-align:top;text-align:left}
    .ng-doc th{background:#e6f0fa;text-align:center;font-size:10px;color:#12314f;font-weight:bold}
    .ng-doc td.c{text-align:center}
    .ng-doc tbody tr:nth-child(even) td{background:#f4f7fb}
    .ng-head{text-align:center;margin:0 0 10px;padding:8px 6px;background:#e8eef7;border:1px solid #12314f;border-radius:2px}
    .ng-title{font-size:15px;font-weight:bold;line-height:1.35;text-transform:uppercase;letter-spacing:.3px;color:#12314f}
    .ng-head .t1{font-size:11px;font-weight:bold;letter-spacing:.5px}
    .ng-head .t2{font-size:15px;font-weight:bold;margin:3px 0}
    .ng-head .t3{font-size:11px;font-weight:bold}
    .ng-centre{background:#12314f;color:#fff;border:2px solid #12314f;padding:6px;text-align:center;font-weight:bold;font-size:13px;letter-spacing:.4px;margin:6px 0 10px}
    .ng-sec{background:#12314f;color:#fff;font-weight:bold;font-size:11px;letter-spacing:.5px;text-transform:uppercase;padding:4px 8px;margin:12px 0 0;border:1px solid #12314f;border-bottom:none}
    .ng-doc td.c{text-align:center}.ng-doc td.l{text-align:left}.ng-doc td.r{text-align:right}
    .ng-doc .detail{font-size:10px;text-align:left}
    .ng-doc table.ng-cand th{font-size:8px;padding:2px 3px}
    .ng-doc table.ng-cand td{font-size:9px;padding:2px 3px}
    .ng-foot{font-size:9px;color:#333;margin-top:10px;border-top:1px solid #12314f;padding-top:5px}
    .ng-pend{color:#777}
    </style>';
}

function fmt_name($n) { return h(strtoupper($n)); }

/**
 * Kiwango cha umahiri (Competence Level) kwa GPA ya somo:
 * 1.0000-1.9999 = A (Excellent), 2.0000-2.9999 = B (Very Good),
 * 3.0000-3.9999 = C (Good), 4.0000-4.7999 = D (Satisfactory), 4.8000-5.0000 = F (Fail).
 */
function competence_level($gpa) {
    if ($gpa === null || $gpa === '') return null;
    $g = (float)$gpa;
    if ($g < 2.0)  return ['label' => 'Grade A (Excellent)', 'bg' => '#146c2e', 'fg' => '#ffffff'];
    if ($g < 3.0)  return ['label' => 'Grade B (Very Good)', 'bg' => '#8fd694', 'fg' => '#10243a'];
    if ($g < 4.0)  return ['label' => 'Grade C (Good)', 'bg' => '#ffd966', 'fg' => '#10243a'];
    if ($g < 4.8)  return ['label' => 'Grade D (Satisfactory)', 'bg' => '#f17a1e', 'fg' => '#ffffff'];
    return ['label' => 'Grade F (Fail)', 'bg' => '#c62828', 'fg' => '#ffffff'];
}

/**
 * Muundo rasmi wa NECTA (CSEE / ACSEE): muhtasari wa centre, matokeo ya watahiniwa
 * (CAND. NO, PREM NO, JINA, SEX, DIVISION, POINTS, masomo kwa gredi) na utendaji wa masomo.
 * Unatumika kwenye PDF ya matokeo ya darasa (batani la PDF juu ya ukurasa wa matokeo).
 */
function render_necta_results(array $d, array $opt = []) {
    $showNames = !empty($opt['show_names']);
    $exam = $d['exam']; $school = $d['school']; $lvl = $d['level'];
    $multiClass = count(array_unique(array_column($d['candidates'], 'class_id'))) > 1;

    // Kichwa cha hati: mistari yote ina font size moja (kama template ya Excel).
    $region = strtoupper(trim((string)($school['region'] ?? '')));
    $district = strtoupper(trim((string)($school['district'] ?? '')));
    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        $region . ($region !== '' ? ' REGION' : ''),
        $district . ($district !== '' ? ' DISTRICT COUNCIL' : ''),
        strtoupper((string)$exam['exam_name']) . (!empty($exam['exam_month']) ? ', ' . $exam['exam_month'] : '') . ' ' . strtoupper((string)$exam['exam_year']),
    ];
    $html  = results_css() . '<div class="ng-doc">';
    $html .= '<div class="ng-head">';
    foreach ($titles as $t) if (trim($t) !== '') $html .= '<div class="ng-title">' . h($t) . '</div>';
    $html .= '</div>';
    $html .= '<div class="ng-centre">' . h($d['necta_no']) . ' - ' . h(strtoupper($school['school_name'] ?? '')) . ' SECONDARY SCHOOL</div>';

    // 1. Muhtasari wa Division kwa jinsia
    $sc = $d['sexCount'];
    $html .= '<div class="ng-sec" style="width:50%;box-sizing:border-box;background:transparent;color:#000;border:none">Centre Performance Summary</div>';
    $html .= '<table style="width:50%;table-layout:fixed;box-sizing:border-box"><tr><th>DIVISION</th>';
    $divs = array_keys($d['divSummary']);
    foreach ($divs as $dv) $html .= '<th>' . h($dv) . '</th>';
    $html .= '<th>TOTAL</th></tr>';
    foreach (['F' => 'FEMALE', 'M' => 'MALE'] as $k => $lab) {
        $html .= '<tr><td><b>' . $lab . '</b></td>';
        foreach ($divs as $dv) $html .= '<td class="c">' . (int)$d['divSummary'][$dv][$k] . '</td>';
        $html .= '<td class="c"><b>' . (int)$sc[$k] . '</b></td></tr>';
    }
    $html .= '<tr><td><b>TOTAL</b></td>';
    foreach ($divs as $dv) $html .= '<td class="c"><b>' . ($d['divSummary'][$dv]['F'] + $d['divSummary'][$dv]['M']) . '</b></td>';
    $html .= '<td class="c"><b>' . ($sc['F'] + $sc['M']) . '</b></td></tr></table>';

    // 2. Matokeo ya watahiniwa
    $nameCols = ($showNames ? '<th>PREM NO</th>' : '') . '<th>CANDIDATE NAME</th>';
    $html .= '<table><thead><tr><th>CAND. NO</th>' . $nameCols .
          '<th>SEX</th>' . ($multiClass ? '<th>CLASS</th>' : '') . '<th>POS</th><th>DIV</th><th>AGGT</th><th style="text-align:left">DETAILED SUBJECTS</th></tr></thead><tbody>';
    $colCount = ($multiClass ? 6 : 5) + 1 + ($showNames ? 1 : 0);
    if (!$d['candidates']) {
        $html .= '<tr><td colspan="' . $colCount . '" class="c ng-pend">Hakuna watahiniwa waliosajiliwa kwenye mtihani huu.</td></tr>';
    }
    foreach ($d['candidates'] as $c) {
        $pend = ($c['state'] === 'PENDING');
        $nameCells = ($showNames ? '<td class="c">' . h($c['prems_no']) . '</td>' : '') . '<td class="l">' . fmt_name($c['name']) . '</td>';
        $html .= '<tr><td class="c">' . h($c['cand_no']) . '</td>' . $nameCells;
        $html .= '<td class="c">' . h($c['sex']) . '</td>' . ($multiClass ? '<td class="c">' . h($c['class_name']) . '</td>' : '');
        $html .= '<td class="c">' . ($c['position'] ?? '-') . '</td>';
        $html .= '<td class="c"><b>' . h($pend ? '-' : $c['division']) . '</b></td><td class="c">' . ($c['points'] === null ? '-' : (int)$c['points']) . '</td>';
        $html .= '<td class="detail' . ($pend ? ' ng-pend' : '') . '">' . ($pend ? 'Alama hazijapakiwa' : h($c['detail'])) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    // 3. Muhtasari wa utendaji wa kituo kwa ujumla
    $passedC = 0;
    foreach ($d['candidates'] as $c) {
        if ($c['state'] === 'OK' && in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $passedC++;
    }
    // GPA inahesabiwa kutoka points zilizopo tayari (hata kama alama bado zinaingizwa),
    // hivyo thamani inaendelea kuji-update kadri data inavyopakiwa.
    $centreGpa = centre_gpa($d);
    $gpaGrade = '';
    if ($centreGpa !== null) {
        $idx = max(0, min(count($d['grading']) - 1, (int)round($centreGpa) - 1));
        $gpaGrade = $d['grading'][$idx]['grade'];
    }
    $avgSumC = 0; $avgNC = 0;
    foreach ($d['candidates'] as $c) if ($c['average'] !== null) { $avgSumC += $c['average']; $avgNC++; }
    $schoolAvgC = $avgNC ? round($avgSumC / $avgNC, 2) : null;
    $avgGrade = $schoolAvgC !== null ? grade_for($schoolAvgC, $d['grading'])['grade'] : '';
    $html .= '<div class="ng-sec" style="width:45%;box-sizing:border-box;background:transparent;color:#000;border:none;text-align:left">Examination Centre Overall Performance</div>';
    $html .= '<table style="width:45%;table-layout:fixed;box-sizing:border-box">'
          .  '<tr><td><b>EXAMINATION CENTRE REGION</b></td><td>' . h($region) . '</td></tr>'
          .  '<tr><td><b>TOTAL PASSED CANDIDATES</b></td><td>' . $passedC . '</td></tr>'
          .  '<tr><td><b>EXAMINATION CENTRE GPA</b></td><td>' . ($centreGpa === null ? '-' : number_format($centreGpa, 4))
          .  ($gpaGrade !== '' ? ' <span style="background:#f17a1e;color:#fff;font-weight:bold;padding:1px 5px">GRADE ' . h($gpaGrade) . '</span>' : '')
          .  '</td></tr></table>';

    // 4. Utendaji kwa somo
    $gl = array_column($d['grading'], 'grade');
    $html .= '<div class="ng-sec" style="width:80%;box-sizing:border-box;background:transparent;color:#000;border:none;text-align:left">Subject Performance</div><table style="width:80%;box-sizing:border-box"><thead><tr><th>CODE</th><th>SUBJECT NAME</th><th>REG</th><th>SAT</th><th>NO-CA</th><th>W/HD</th><th>CLEAN</th><th>PASS</th><th>GPA</th><th>SCHL/RANK</th><th>COMPETENCY LEVEL</th></tr></thead><tbody>';
    $subjRank = 1;
    $subjTotal = count($d['subjStats']);
    foreach ($d['subjStats'] as $s) {
        $cl = competence_level($s['gpa']);
        $reg = (int)$s['sat'] + (int)$s['absent'];
        $html .= '<tr><td class="c">' . h($s['code']) . '</td><td class="l">' . h(strtoupper($s['name'])) . ($s['subsidiary'] ? ' (SUBS.)' : '') . '</td>';
        $html .= '<td class="c">' . $reg . '</td><td class="c">' . $s['sat'] . '</td><td class="c">0</td><td class="c">0</td><td class="c">' . $s['sat'] . '</td>';
        $html .= '<td class="c">' . $s['pass'] . '</td><td class="c">' . ($s['gpa'] === null ? '-' : number_format($s['gpa'], 4)) . '</td>';
        $html .= '<td class="c">' . $subjRank . '/' . $subjTotal . '</td>';
        $html .= '<td class="c" style="' . ($cl ? 'background:' . $cl['bg'] . ';color:' . $cl['fg'] . ';font-weight:bold' : '') . '">' . ($cl ? h($cl['label']) : '-') . '</td></tr>';
        $subjRank++;
    }
    if (!$d['subjStats']) $html .= '<tr><td colspan="' . (7 + count($gl)) . '" class="c ng-pend">Hakuna alama zilizopakiwa bado.</td></tr>';
    $html .= '</tbody></table>';

    // 5. Maelezo ya gredi na division
    $gk = [];
    foreach ($d['grading'] as $i => $g) {
        $max = ($i === 0) ? 100 : ($d['grading'][$i - 1]['min_score'] - 0.01);
        $gk[] = h($g['grade']) . ' = ' . rtrim(rtrim(number_format($g['min_score'], 2), '0'), '.') . '-' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' (' . (int)$g['points'] . ' pt)';
    }
    $divKey = ($lvl === 'A')
        ? 'Division: I = 3-9, II = 10-12, III = 13-17, IV = 18-19, 0 = 20-21 (points za masomo 3 bora ya principal; GS/BAM ni subsidiary).'
        : 'Division: I = 7-17, II = 18-21, III = 22-25, IV = 26-33, 0 = 34-35 (points za masomo 7 bora). INC = Incomplete, ABS = Absent.';
    return $html . '</div>';
}

function render_school_results(array $d, array $opt = []) {
    foreach (['I', 'II', 'III', 'IV', '0'] as $dv) $html .= '<th>' . h($dv) . '</th>';
    $html .= '<th>INCOMPLETE</th><th>ABSENT</th><th>TOTAL</th></tr></thead><tbody>';
    foreach (['F' => 'F', 'M' => 'M', 'T' => 'T'] as $k => $lab) {
        $vals = [];
        foreach (['I', 'II', 'III', 'IV', '0', 'INC', 'ABS'] as $dv) {
            $f = (int)($d['divSummary'][$dv]['F'] ?? 0);
            $m = (int)($d['divSummary'][$dv]['M'] ?? 0);
            $vals[] = ($lab === 'T') ? $f + $m : ($lab === 'F' ? $f : $m);
        }
        $vals[] = array_sum($vals);
        $html .= '<tr><td class="c"><b>' . $lab . '</b></td>';
        foreach ($vals as $v) $html .= '<td class="c">' . $v . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    /* --- 2. SUBJECT PERFORMANCE SUMMARY -------------------------------- */
    $sexStat = [];
    foreach ($d['candidates'] as $c) {
        $sx = ($c['sex'] === 'F') ? 'F' : 'M';
        foreach ($c['subjects'] as $x) {
            $sid = (int)$x['subject_id'];
            if (!isset($sexStat[$sid])) $sexStat[$sid] = ['F' => [], 'M' => []];
            if ($x['score'] === null) continue;
            $g = (string)$x['grade'];
            $sexStat[$sid][$sx][$g] = ($sexStat[$sid][$sx][$g] ?? 0) + 1;
        }
    }
    $statsBySub = [];
    foreach ($d['subjStats'] as $s) $statsBySub[(int)$s['subject_id']] = $s;
    $rankable = array_values(array_filter($d['subjStats'], function ($s) { return $s['avg'] !== null; }));
    usort($rankable, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
    $rankMap = [];
    foreach ($rankable as $i => $s) $rankMap[(int)$s['subject_id']] = $i + 1;

    $html .= '<div class="ng-sec">SUBJECT PERFORMANCE SUMMARY</div><table><thead>';
    $html .= '<tr><th rowspan="3">SN</th><th rowspan="3">SUBJECTS</th><th colspan="' . (3 * $gn) . '">SUBJECT GRADE</th><th colspan="8">SUBJECT PERFORMANCE ANALYSIS</th></tr><tr>';
    foreach ($grades as $g) $html .= '<th colspan="3">' . h($g) . '</th>';
    foreach (['TOTAL', 'AVERAGE', 'GRADE', 'GPA', 'PASS', 'FAIL', 'RANK', 'COMPETENCE LEVEL'] as $t) $html .= '<th rowspan="2">' . h($t) . '</th>';
    $html .= '</tr><tr>';
    foreach ($grades as $g) $html .= '<th>F</th><th>M</th><th>T</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($subs as $i => $s) {
        $sid = (int)$s['id'];
        $html .= '<tr><td class="c">' . ($i + 1) . '</td><td class="l">' . h(strtoupper((string)$s['name'])) . '</td>';
        foreach ($grades as $g) {
            $f = (int)($sexStat[$sid]['F'][$g] ?? 0);
            $m = (int)($sexStat[$sid]['M'][$g] ?? 0);
            $html .= '<td class="c">' . $f . '</td><td class="c">' . $m . '</td><td class="c">' . ($f + $m) . '</td>';
        }
        $st = $statsBySub[$sid] ?? null;
        $cl = competence_level($st ? $st['gpa'] : null);
        $html .= '<td class="c">' . ($st ? $st['sat'] : '-') . '</td>'
              .  '<td class="c">' . ($st && $st['avg'] !== null ? $st['avg'] : '-') . '</td>'
              .  '<td class="c">' . ($st && $st['avg'] !== null ? h(grade_for($st['avg'], $d['grading'])['grade']) : '-') . '</td>'
              .  '<td class="c">' . ($st && $st['gpa'] !== null ? number_format($st['gpa'], 4) : '-') . '</td>'
              .  '<td class="c">' . ($st ? $st['pass'] : '-') . '</td>'
              .  '<td class="c">' . ($st ? max(0, $st['sat'] - $st['pass']) : '-') . '</td>'
              .  '<td class="c">' . (isset($rankMap[$sid]) ? $rankMap[$sid] : '-') . '</td>'
              .  '<td class="c" style="font-size:9px;' . ($cl ? 'background:' . $cl['bg'] . ';color:' . $cl['fg'] . ';font-weight:bold' : '') . '">'
              .  ($cl ? h($cl['label']) : '-') . '</td></tr>';
    }
    if (!$subs) $html .= '<tr><td colspan="' . (2 + 3 * $gn + 8) . '" class="c ng-pend">Hakuna masomo yaliyosajiliwa kwenye mtihani huu.</td></tr>';
    $html .= '</tbody></table>';

    /* --- 3. EXAMINATION CENTRE RANKING --------------------------------- */
    $okCands = array_values(array_filter($d['candidates'], function ($c) { return $c['state'] === 'OK'; }));
    $passed = 0; $ptsSum = 0; $gpaN = 0; $avgSum = 0; $avgN = 0;
    foreach ($d['candidates'] as $c) {
        if ($c['average'] !== null) { $avgSum += $c['average']; $avgN++; }
        if ($c['points'] !== null) { $ptsSum += (int)$c['points']; $gpaN++; }
    }
    foreach ($okCands as $c) {
        if (in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $passed++;
    }
    // GPA inatumia points zilizopo tayari, hivyo inaendelea kuji-update kadri alama zinavyoingizwa.
    $centreGpa = centre_gpa($d);
    $schoolAvg = $avgN ? round($avgSum / $avgN, 2) : null;
    $cat = count($d['candidates']) < 40 ? 'CENTER WITH LESS THAN 40 CANDIDATES' : 'CENTER WITH 40 OR MORE CANDIDATES';

    $html .= '<div class="ng-sec">EXAMINATION CENTRE RANKING</div>';
    $html .= '<table class="ng-rank"><tr>'
          .  '<td style="width:26%"><b>EXAMINATION CENTRE REGION:</b></td><td style="width:24%">' . h(strtoupper(trim((string)($school['region'] ?? '')))) . '</td>'
          .  '<td style="width:26%"><b>TOTAL PASSED CANDIDATES:</b></td><td>' . $passed . '</td></tr><tr>'
          .  '<td><b>EXAMINATION CENTRE DISTRICT:</b></td><td>' . h(strtoupper(trim((string)($school['district'] ?? '')))) . '</td>'
          .  '<td><b>CENTRE CATEGORY:</b></td><td>' . h($cat) . '</td></tr><tr>'
          .  '<td><b>EXAMINATION CENTRE GPA:</b></td><td>' . ($centreGpa === null ? '-' : number_format($centreGpa, 4)) . '</td>'
          .  '<td><b>SCHOOL AVERAGE:</b></td><td>' . ($schoolAvg === null ? '-' : number_format($schoolAvg, 2))
          .  ($schoolAvg !== null ? ' <span style="background:#12314f;color:#fff;font-weight:bold;padding:1px 5px">GRADE ' . h(grade_for($schoolAvg, $d['grading'])['grade']) . '</span>' : '')
          .  '</td></tr></table>';

    /* --- 4. Jedwali la watahiniwa (Overall Results) --------------------- */
    $regMap = [];
    foreach (q_all('SELECT ss.student_id, ss.subject_id FROM student_subjects ss JOIN students st ON st.id = ss.student_id
                    WHERE st.school_id = ? AND st.class_id = ?',
                    [(int)$school['id'], (int)$d['class']['id']]) as $x) {
        $regMap[(int)$x['student_id']][(int)$x['subject_id']] = true;
    }
    $hasReg = (bool)$regMap;
    $subIds = array_map('intval', array_column($subs, 'id'));

    $html .= '<table class="ng-cand"><thead>'
          .  '<tr><th rowspan="2">S/N</th><th rowspan="2">INDEX NO</th><th rowspan="2">PREMS NO</th><th rowspan="2" style="white-space:nowrap;width:auto">NAME OF CANDIDATE</th><th rowspan="2">SEX</th><th rowspan="2">REGISTERED SUBJECTS</th>'
          .  '<th colspan="' . (2 * $n) . '">GENERAL SUBJECTS</th><th rowspan="2">TOTAL MARKS</th><th rowspan="2">AVERAGE</th><th rowspan="2">GRADE</th>'
          .  '<th colspan="2">DIVISION</th><th rowspan="2">GPA</th><th rowspan="2">POSITION</th></tr><tr>';
    foreach ($subs as $s) $html .= '<th colspan="2">' . h(strtoupper((string)$s['name'])) . '</th>';
    $html .= '<th>POINTS</th><th>CLASS</th></tr></thead><tbody>';
    if (!$d['candidates']) {
        $html .= '<tr><td colspan="' . (13 + 2 * $n) . '" class="c ng-pend">Hakuna watahiniwa waliosajiliwa kwenye mtihani huu.</td></tr>';
    }
    $sn = 1;
    foreach ($d['candidates'] as $c) {
        $sid = (int)$c['student_id'];
        $bySub = [];
        foreach ($c['subjects'] as $x) $bySub[(int)$x['subject_id']] = $x;
        $regN = $hasReg ? count(array_intersect($subIds, array_keys($regMap[$sid] ?? []))) : $n;
        $ptsN = 0; $ptsTotal = 0;
        foreach ($c['subjects'] as $x) if ($x['points'] !== null) { $ptsTotal += (int)$x['points']; $ptsN++; }

        $html .= '<tr><td class="c">' . $sn . '</td>'
              .  '<td class="c">' . h($c['cand_no']) . '</td><td class="c">' . h($c['prems_no']) . '</td>'
              .  '<td class="l" style="white-space:nowrap">' . fmt_name($c['name']) . '</td><td class="c">' . h($c['sex']) . '</td><td class="c">' . $regN . '</td>';
        foreach ($subs as $s) {
            $x = $bySub[(int)$s['id']] ?? null;
            if (!$x) { $html .= '<td class="c"></td><td class="c"></td>'; continue; }
            $html .= '<td class="c">' . ($x['score'] === null ? 'ABS' : h((string)(0 + $x['score']))) . '</td><td class="c">' . h((string)$x['grade']) . '</td>';
        }
        $html .= '<td class="c">' . ($c['total'] > 0 ? h((string)(0 + $c['total'])) : '') . '</td>'
              .  '<td class="c">' . ($c['average'] === null ? '' : h((string)$c['average'])) . '</td>'
              .  '<td class="c">' . ($c['average'] === null ? '' : h(grade_for($c['average'], $d['grading'])['grade'])) . '</td>'
              .  '<td class="c">' . ($c['points'] === null ? '' : (int)$c['points']) . '</td>'
              .  '<td class="c"><b>' . h((string)$c['division']) . '</b></td>'
              .  '<td class="c">' . ($ptsN ? number_format($ptsTotal / $ptsN, 4) : '') . '</td>'
              .  '<td class="c">' . (isset($c['position']) ? (int)$c['position'] : '-') . '</td></tr>';
        $sn++;
    }
    $html .= '</tbody></table>';

    // 5. Maelezo ya gredi na division
    $gk = [];
    foreach ($d['grading'] as $i => $g) {
        $max = ($i === 0) ? 100 : ($d['grading'][$i - 1]['min_score'] - 0.01);
        $gk[] = h($g['grade']) . ' = ' . rtrim(rtrim(number_format($g['min_score'], 2), '0'), '.') . '-' . rtrim(rtrim(number_format($max, 2), '0'), '.') . ' (' . (int)$g['points'] . ' pt)';
    }
    $divKey = ($lvl === 'A')
        ? 'Division: I = 3-9, II = 10-12, III = 13-17, IV = 18-19, 0 = 20-21 (points za masomo 3 bora ya principal; GS/BAM ni subsidiary).'
        : 'Division: I = 7-17, II = 18-21, III = 22-25, IV = 26-33, 0 = 34-35 (points za masomo 7 bora). INC = Incomplete, ABS = Absent.';
    return $html . '</div>';
}

/** CSS ya fomu ya ripoti ya maendeleo (kurasa 3, A4 portrait). */
function progress_css() {
    return '<style>
    .pf-doc{font-family:"DejaVu Sans",Arial,Helvetica,sans-serif;color:#111;font-size:9.5px;background:#f4f6fa}
    .pf-page{background:#fff;border:1px solid #c7d2e0;border-radius:8px;padding:9px 12px;margin:0 auto;max-width:820px;box-sizing:border-box;box-shadow:0 4px 14px rgba(11,61,145,.08)}
    .pf-doc table{border-collapse:collapse;width:100%}
    .pf-doc th,.pf-doc td{border:1px solid #333;padding:1.5px 4px;font-size:9.3px;vertical-align:middle;line-height:1.25}
    .pf-doc th{background:#1b7f4b;color:#fff;text-align:center;font-weight:bold}
    .pf-doc td.c{text-align:center}
    .pf-doc table.pf-plain td{border:none;padding:1px 0}
    .pf-head{text-align:center;line-height:1.2;font-weight:bold}
    .pf-head .l1,.pf-head .l2{font-size:10px}
    .pf-title{text-align:center;font-weight:bold;font-size:10px;border-top:2px solid #111;border-bottom:2px solid #111;padding:2px 0;margin:4px 0 5px;letter-spacing:.2px}
    .pf-dot{display:inline-block;min-width:55px;border-bottom:1px dotted #444;padding:0 3px;font-weight:bold;color:#0b3d91}
    .pf-sec{font-weight:bold;margin:6px 0 3px;font-size:9.2px}
    .pf-sec .k{color:#1b7f4b;margin-right:4px}
    .pf-legend{font-weight:bold;font-size:8.2px;margin:1px 0 2px}
    .pf-legend span{margin-right:11px;color:#0b3d91}
    .pf-line{font-weight:bold;margin:2px 0;font-size:8.8px}
    .pf-line b.lbl{color:#1b7f4b}
    .pf-fill{border-bottom:1px dotted #444;display:block;height:10px;margin-top:1px}
    .pf-muted{color:#666}
    .pf-ol{margin:2px 0 0 14px;padding:0;font-size:8.2px;line-height:1.3}
    .pf-headtbl td{border:none!important;padding:0!important;vertical-align:middle}
    .pf-headtbl .pf-logo{width:72px;text-align:center}
    .pf-headtbl .pf-logo img{max-height:58px;max-width:66px}
    .pf-headtbl .pf-head{text-align:center;line-height:1.2;font-weight:bold}
    .pf-seal-line{font-weight:bold;font-size:8.8px;line-height:1.6}
    .pf-seal-lbl{color:#1b7f4b;margin-right:2px}
    .pf-seal-line img{vertical-align:middle;margin:0 2px}
    .pf-foot{width:100%;margin-top:8px;border-top:1.5px solid #1b7f4b;font-size:8px;color:#333}
    .pf-foot td{border:0;padding:4px 0 0}
    .pf-foot b{color:#1b7f4b}
    .pf-sig{max-height:48px;max-width:170px}
    .pf-stamp{max-height:76px;max-width:115px;display:inline-block}
    @media print{@page{size:A4 portrait;margin:0}.pf-doc{background:#fff}.pf-page{border:none;padding:9mm 11mm;max-width:none}}
    </style>';
}

function render_student_report(array $d, array $c, array $opt = []) {
    $exam = $d['exam']; $school = $d['school']; $lvl = $d['level'];
    $hm = q_one("SELECT full_name, phone FROM users WHERE school_id = ? AND active = 1 AND position = 'Headmaster' ORDER BY id LIMIT 1", [(int)$school['id']]);
    if (!$hm) $hm = q_one("SELECT full_name, phone FROM users WHERE school_id = ? AND active = 1 AND role = 'admin' ORDER BY id LIMIT 1", [(int)$school['id']]);

    // Nafasi ya mwanafunzi kwenye kila somo (kati ya walioweka alama kwenye darasa)
    $rank = [];
    foreach ($c['subjects'] as $r) {
        $sub = (int)$r['subject_id'];
        if ($r['score'] === null) continue;
        $scores = [];
        foreach ($d['candidates'] as $o) foreach ($o['subjects'] as $x)
            if ((int)$x['subject_id'] === $sub && $x['score'] !== null) $scores[] = (float)$x['score'];
        if (!$scores) continue;
        $better = 0; foreach ($scores as $v) if ($v > (float)$r['score']) $better++;
        $rank[$sub] = [$better + 1, count($scores)];
    }

    $district = strtoupper(trim((string)($school['district'] ?? '')));
    $schoolName = strtoupper(trim((string)$school['school_name']));
    $clsName = strtoupper(trim(preg_replace('/^Form\s+/i', '', (string)$c['class_name'])));
    $examType = strtoupper(trim((string)($exam['exam_type'] ?? '')));
    $month = strtoupper(trim((string)($exam['exam_month'] ?? '')));

    $schoolLabel = (strpos($schoolName, 'SEKONDARI') !== false || strpos($schoolName, 'SECONDARY') !== false)
        ? $schoolName
        : 'SHULE YA SEKONDARI ' . $schoolName;
    $headLines = [
        'OFISI YA WAZIRI MKUU',
        'TAWALA ZA MIKOA NA SERIKALI ZA MITAA',
        $district !== '' ? 'HALMASHAURI YA WILAYA YA ' . $district : '',
        $schoolLabel,
        trim((string)($school['phone'] ?? '')) !== '' ? 'SIMU: ' . trim((string)$school['phone']) : '',
    ];
    $headText = '';
    foreach ($headLines as $i => $l) if ($l !== '') $headText .= '<div class="' . ($i === 0 ? 'l1' : 'l2') . '">' . h($l) . '</div>';
    // Nembo za ripoti: nembo ya taifa (coat of arms) ya mfumo kushoto, nembo ya shule kulia
    $brand = report_branding_get((int)$school['id']);
    $natSrc = national_logo_src();
    $schSrc = report_branding_src($brand['school_logo'] ?? '');
    if ($natSrc !== '' || $schSrc !== '') {
        $head = '<table class="pf-headtbl"><tr>'
              .  '<td class="pf-logo">' . ($natSrc !== '' ? '<img src="' . $natSrc . '" alt="National logo">' : '') . '</td>'
              .  '<td class="pf-head">' . $headText . '</td>'
              .  '<td class="pf-logo">' . ($schSrc !== '' ? '<img src="' . $schSrc . '" alt="School logo">' : '') . '</td>'
              .  '</tr></table>';
    } else {
        $head = '<div class="pf-head">' . $headText . '</div>';
    }

    $termName = $examType !== '' ? $examType : $month;
    // Taarifa za awali zilizojazwa kwenye admin/report_info.php (muhula + darasa)
    $ri = report_info_get((int)$school['id'], (int)$c['class_id'], report_term_code($termName . ' ' . $month));
    // Muhula hutoka kwenye Report Info (Kwanza = I, Pili = II), si aina ya mtihani (mf. MOCK)
    $termCode = in_array(($ri['term'] ?? ''), ['I', 'II'], true) ? (string)$ri['term'] : report_term_code($termName . ' ' . $month);
    $termLabel = $termCode === 'II' ? 'PILI' : 'KWANZA';
    // Mwezi wa mtihani kuundwa (created_at); kama haupo, tumia mwezi wa mtihani
    $createdAt = trim((string)($exam['created_at'] ?? ''));
    $tCreated = ($createdAt !== '' && $createdAt !== '0000-00-00 00:00:00') ? strtotime($createdAt) : false;
    $monthCreated = $tCreated ? strtoupper(date('F', $tCreated)) : $month;
    $title = 'RIPOTI YA MAENDELEO YA TAALUMA KIDATO CHA ' . $clsName
        . ' MUHULA WA ' . $termLabel
        . ($monthCreated !== '' ? ', ' . $monthCreated : '')
        . ' ' . strtoupper((string)$exam['exam_year']);

    $html = progress_css() . '<div class="pf-doc">';

    /* ---------------- Ukurasa 1: matokeo ya masomo + tathmini ---------------- */
    $html .= '<div class="pf-page">' . $head . '<div class="pf-title">' . h($title) . '</div>';
    $html .= '<table class="pf-plain"><tr>'
          .  '<td style="width:42%"><b style="color:#1b7f4b">KWA MZAZI/MLEZI WA:</b> <span class="pf-dot">' . fmt_name($c['name']) . '</span></td>'
          .  '<td style="width:42%"><b style="color:#1b7f4b">MUHULA:</b> <span class="pf-dot">' . h($termLabel) . '</span></td>'
          .  '<td style="width:16%"><b style="color:#1b7f4b">KIDATO:</b> <span class="pf-dot">' . h($clsName) . '</span></td></tr></table>';

    $html .= '<table style="margin-top:8px"><thead><tr>'
          .  '<th style="width:6%">#</th><th>SOMO</th><th style="width:13%">MTIHANI</th>'
          .  '<th style="width:8%">GREDI</th><th style="width:12%">NAFASI</th><th style="width:22%">MAONI</th>'
          .  '</tr></thead><tbody>';
    $sn = 0;
    foreach ($c['subjects'] as $r) {
        $sn++;
        $sc = ($r['score'] === null) ? '-' : rtrim(rtrim(number_format((float)$r['score'], 1), '0'), '.');
        $pos = isset($rank[(int)$r['subject_id']]) ? $rank[(int)$r['subject_id']][0] . ' / ' . $rank[(int)$r['subject_id']][1] : '-';
        $html .= '<tr><td class="c">' . $sn . '</td><td>' . h(strtoupper($r['name'])) . ($r['subsidiary'] ? ' (SUBS.)' : '') . '</td>'
              .  '<td class="c"><b>' . h($sc) . '</b></td>'
              .  '<td class="c"><b>' . h($r['grade']) . '</b></td><td class="c">' . h($pos) . '</td><td>' . h($r['remark']) . '</td></tr>';
    }
    if (!$c['subjects']) $html .= '<tr><td colspan="6" class="c pf-muted">Alama hazijapakiwa bado.</td></tr>';
    $html .= '</tbody></table>';

    // A. Tathmini ya ufaulu
    $gAvg = $c['average'] !== null ? grade_for($c['average'], $d['grading']) : null;
    $fmt = function ($v, $dec = 1) { return $v === null ? '-' : rtrim(rtrim(number_format((float)$v, $dec), '0'), '.'); };
    $html .= '<div class="pf-sec"><span class="k">A.</span>Tathmini ya Ufaulu</div>';
    $html .= '<table class="pf-plain"><tr>'
          .  '<td style="width:25%"><b class="lbl">Jumla ya alama zake ni:</b> <span class="pf-dot">' . h($fmt($c['total'])) . '</span></td>'
          .  '<td style="width:25%"><b class="lbl">Wastani wa alama zake:</b> <span class="pf-dot">' . h($fmt($c['average'])) . '</span></td>'
          .  '<td style="width:25%"><b class="lbl">Gredi:</b> <span class="pf-dot">' . h($gAvg ? $gAvg['grade'] : '-') . '</span></td>'
          .  '<td><b class="lbl">Maoni:</b> <span class="pf-dot">' . h($gAvg ? $gAvg['remark'] : '-') . '</span></td></tr>'
          .  '<tr><td style="padding-top:6px"><b class="lbl">Amekuwa:</b> <span class="pf-dot">' . (isset($c['position']) ? (int)$c['position'] : '-') . '</span></td>'
          .  '<td><b class="lbl">Kati ya:</b> <span class="pf-dot">' . (isset($c['class_size']) ? (int)$c['class_size'] : '-') . '</span></td>'
          .  '<td><b class="lbl">Daraja:</b> <span class="pf-dot">' . h($c['division']) . '</span></td>'
          .  '<td><b class="lbl">Point:</b> <span class="pf-dot">' . ($c['points'] === null ? '-' : (int)$c['points']) . '</span></td></tr></table>';

    // Maoni ya kiotomatiki kutokana na gredi ya wastani
    $rmk = report_remarks($gAvg ? $gAvg['grade'] : '', (int)$school['id']);

    // B. Mwalimu wa darasa
    $ctName = strtoupper(trim((string)($ri['class_teacher'] ?? '')));
    $html .= '<div class="pf-sec"><span class="k">B.</span>Jina la mwalimu wa darasa: <span class="pf-dot" style="min-width:150px">' . h($ctName !== '' ? $ctName : ' ') . '</span>'
          .  ' &nbsp; Namba ya simu: <span class="pf-dot" style="min-width:110px">' . h(trim((string)($ri['class_phone'] ?? '')) ?: ' ') . '</span></div>';
    $html .= '<table class="pf-plain"><tr><td style="width:22%;vertical-align:top"><b class="lbl">Maoni ya Mwalimu wa darasa:</b></td>'
          .  '<td>' . ($rmk['class_teacher'] !== '' ? '<b>' . h($rmk['class_teacher']) . '</b>' : '<span class="pf-fill"></span>') . '</td></tr></table>';

    /* --------- Tabia, gredi na maagizo (ukurasa huo huo) --------- */
    $html .= '<div class="pf-sec"><span class="k">C.</span>Tabia na maadili ya mwanafunzi.</div>';
    $html .= '<div class="pf-legend"><span>A: Nzuri Sana</span><span>B: Nzuri</span><span>C: Wastani</span><span>D: Mbaya</span><span>F: Mbaya Sana</span></div>';
    $behaviour = discipline_criteria();
    // Daraja la kiotomatiki: alama zilizohifadhiwa za nidhamu, au gredi ya wastani wa mwanafunzi.
    $dsc = discipline_get((int)($exam['id'] ?? 0), (int)$c['class_id']);
    $dscRow = $dsc[(int)$c['student_id']] ?? [];
    $autoG = $gAvg ? (string)$gAvg['grade'] : '';
    $html .= '<table><thead><tr><th style="width:8%">S/N</th><th>KIPIMO</th><th style="width:16%">DARAJA</th></tr></thead><tbody>';
    $i = 0;
    foreach ($behaviour as $key => $b) {
        $i++;
        $gr = (string)($dscRow[$key] ?? $autoG);
        $html .= '<tr><td class="c">' . $i . '</td><td>' . h($b) . '</td><td class="c"><b>' . h($gr !== '' ? $gr : '-') . '</b></td></tr>';
    }
    $html .= '</tbody></table>';

    // D. Gredi za shule na madaraja
    $html .= '<div class="pf-sec"><span class="k">D.</span>Gredi za shule za sekondari na Madaraja</div>';
    $html .= '<table><thead><tr><th style="width:8%">#</th><th>Alama</th><th>Gredi</th><th>Maoni</th><th>Point</th><th>Daraja</th></tr></thead><tbody>';
    $gs = $d['grading'];   // imepangwa kushuka kwa min_score
    foreach ($gs as $i => $g) {
        $max = $i === 0 ? 100 : ((float)$gs[$i - 1]['min_score'] - 1);
        $min = (float)$g['min_score'];
        $divBand = $min >= 75 ? 'I' : ($min >= 65 ? 'II' : ($min >= 45 ? 'III' : ($min >= 30 ? 'IV' : '0')));
        $html .= '<tr><td class="c">' . ($i + 1) . '</td><td class="c">' . h($fmt($min) . ' - ' . $fmt($max)) . '</td>'
              .  '<td class="c"><b>' . h($g['grade']) . '</b></td><td>' . h((string)$g['remark']) . '</td>'
              .  '<td class="c">' . (int)$g['points'] . '</td><td class="c"><b>' . h($divBand) . '</b></td></tr>';
    }
    $html .= '</tbody></table>';

    // E. Maagizo
    $html .= '<div class="pf-sec"><span class="k">E.</span>Maagizo</div>';
    $cd = !empty($ri['closing_date']) ? date('d/m/Y', strtotime($ri['closing_date'])) : '';
    $od = !empty($ri['opening_date']) ? date('d/m/Y', strtotime($ri['opening_date'])) : '';
    $html .= '<table class="pf-plain"><tr>'
          .  '<td style="width:50%"><b class="lbl">Shule imefungwa tarehe:</b> <span class="pf-dot" style="min-width:130px">' . h($cd !== '' ? $cd : ' ') . '</span></td>'
          .  '<td><b class="lbl">Shule itafunguliwa tarehe:</b> <span class="pf-dot" style="min-width:130px">' . h($od !== '' ? $od : ' ') . '</span></td></tr></table>';
    $html .= '<div style="margin-top:4px;font-size:8.6px"><b>Aidha wakati wa kufungua shule mambo yafuatayo hayana budi kuzingatiwa:-</b></div>';
    $ins = array_values(array_filter(array_map('trim', explode("\n", (string)($ri['instructions'] ?? ''))), 'strlen'));
    if (!$ins) $ins = [
        'Kila mwanafunzi anapaswa kupeleka ripoti nyumbani kwa wazazi/walezi wake.',
        'Mzazi/mlezi anapaswa kumfuatilia mwanafunzi na kumwelekeza kujisomea vyema.',
        'Kila mwanafunzi anapaswa kulipia mahitaji yote ya shule ikiwemo madarini wa nyuma.',
    ];
    $html .= '<ol class="pf-ol">';
    foreach ($ins as $line) $html .= '<li>' . h($line) . '</li>';
    $html .= '</ol>';

    /* --------- Maoni ya walimu na sahihi (ukurasa huo huo) --------- */
    $html .= '<div class="pf-sec"><span class="k">F.</span>Maoni ya Mwalimu wa Taaluma na Mkuu wa Shule.</div>';
    $stName = strtoupper(trim((string)($ri['subject_teacher'] ?? '')));
    $hmName = strtoupper(trim((string)($ri['headmaster'] ?? ($hm['full_name'] ?? ''))));
    $hmPhone = trim((string)($ri['head_phone'] ?? ($hm['phone'] ?? '')));
    $html .= '<div class="pf-line"><b class="lbl">Jina la mwalimu wa taaluma:</b> <span class="pf-dot" style="min-width:200px">' . h($stName !== '' ? $stName : ' ') . '</span>'
          .  ' &nbsp; <b class="lbl">Simu:</b> <span class="pf-dot" style="min-width:130px">' . h(trim((string)($ri['subject_phone'] ?? '')) ?: ' ') . '</span></div>';
    $html .= '<div class="pf-line"><b class="lbl">Maoni ya Mwalimu wa Taaluma:</b> ' . ($rmk['subject_teacher'] !== '' ? h($rmk['subject_teacher']) : '<span class="pf-fill"></span>') . '</div>';
    $html .= '<div class="pf-line" style="margin-top:7px"><b class="lbl">Jina la Mwalimu Mkuu wa Shule:</b> <span class="pf-dot" style="min-width:200px">'
          .  h($hmName !== '' ? $hmName : ' ') . '</span>'
          .  ' &nbsp; <b class="lbl">Simu:</b> <span class="pf-dot" style="min-width:130px">' . h($hmPhone !== '' ? $hmPhone : ' ') . '</span></div>';
    $html .= '<div class="pf-line"><b class="lbl">Maoni ya Mwalimu Mkuu wa Shule:</b> ' . ($rmk['headmaster'] !== '' ? h($rmk['headmaster']) : '<span class="pf-fill"></span>') . '</div>';
    // Muhuri wa shule na sahihi ya mkuu wa shule
    $stampSrc = report_branding_src($brand['stamp'] ?? '');
    $sigSrc = report_branding_src($brand['signature'] ?? '');
    if ($stampSrc !== '' || $sigSrc !== '') {
        $html .= '<div class="pf-seal-line" style="margin-top:8px">'
              .  '<span class="pf-seal-lbl">Sahihi ya Mkuu wa Shule:</span>'
              .  ($sigSrc !== '' ? '<img src="' . $sigSrc . '" class="pf-sig" alt="Headmaster signature">' : '<span class="pf-fill" style="display:inline-block;width:150px;height:9px"></span>')
              .  ' &nbsp;&nbsp; <span class="pf-seal-lbl">Muhuri:</span>'
              .  ($stampSrc !== '' ? '<img src="' . $stampSrc . '" class="pf-stamp" alt="School stamp">' : '<span class="pf-fill" style="display:inline-block;width:110px;height:9px"></span>')
              .  '</div>';
    }
    $html .= '</div>';

    return $html . '</div>';
}

function page_shell($title, $inner, $autoprint = false, $landscape = true) {
    return '<!DOCTYPE html><html lang="sw"><head><meta charset="UTF-8"><title>' . h($title) . '</title>'
        . '<style>@page{size:A4 ' . ($landscape ? 'landscape' : 'portrait') . ';margin:10mm;border:2px solid #12314f;background:#F5F5DC;@bottom-left{content:"Page " counter(page) " of " counter(pages);font-size:10px;font-weight:bold;color:#12314f}}body{margin:0;background:#F5F5DC}.ng-doc{border:none!important}@media print{.no-print{display:none!important}body{margin:0;padding:0}}</style></head><body>'
        . $inner . ($autoprint ? '<script>window.onload=function(){window.print()}</script>' : '') . '</body></html>';
}

/** Jedwali la kulinganisha shule kwa mtihani na darasa (matokeo ya wilaya). */
function render_council(array $c) {
    $html = results_css() . '<div class="ng-doc">';
    $html .= '<div class="ng-head"><div class="t1">' . h(strtoupper(system_name())) . '</div><div class="t2">' . h(strtoupper($c['exam']['exam_name'])) . '</div>'
          .  '<div class="t3">DISTRICT SCHOOLS PERFORMANCE - ' . h(strtoupper($c['class']['name'])) . ' (' . ($c['level'] === 'A' ? 'A-LEVEL' : 'O-LEVEL') . ') ' . (!empty($c['exam']['exam_month']) ? h($c['exam']['exam_month']) . ' ' : '') . h($c['exam']['exam_year']) . '</div></div>';
    $html .= '<table><thead><tr><th>RANK</th><th>CENTRE</th><th>SCHOOL</th><th>REG</th><th>I</th><th>II</th><th>III</th><th>IV</th><th>0</th><th>INC</th><th>ABS</th><th>PEND</th><th>AVG POINTS</th><th>% PASS (I-IV)</th></tr></thead><tbody>';
    foreach ($c['rows'] as $r) {
        $html .= '<tr><td class="c"><b>' . ($r['rank'] === null ? '-' : (int)$r['rank']) . '</b></td><td class="c">' . h($r['centre']) . '</td><td class="l">' . h(strtoupper($r['name'])) . '</td><td class="c">' . (int)$r['registered'] . '</td>';
        foreach (['I', 'II', 'III', 'IV', '0', 'INC', 'ABS', 'PENDING'] as $k) $html .= '<td class="c">' . (int)$r[$k] . '</td>';
        $html .= '<td class="c"><b>' . ($r['avg_points'] === null ? '-' : number_format($r['avg_points'], 2)) . '</b></td><td class="c">' . ($r['pass_pct'] === null ? '-' : h($r['pass_pct'])) . '</td></tr>';
    }
    if (!$c['rows']) $html .= '<tr><td colspan="14" class="c ng-pend">Hakuna shule inayoshiriki mtihani huu.</td></tr>';
    $note = ($c['level'] === 'A') ? 'AVG POINTS = wastani wa points za division za watahiniwa (masomo 3 bora ya principal; 3 = bora zaidi, 21 = hafifu).'
                                  : 'AVG POINTS = wastani wa points za division za watahiniwa (masomo 7 bora; 7 = bora zaidi, 35 = hafifu).';
    $html .= '</tbody></table><div class="ng-foot"><b>' . h($note) . '</b> Shule hupangwa kwa AVG POINTS ndogo zaidi; ikilingana, kwa idadi ya Division I. Watahiniwa wenye INC/ABS hawahesabiwi kwenye wastani. PEND = alama hazijaingizwa.<br>Imetolewa na ' . h(system_name()) . ': ' . h($c['generated']) . '</div></div>';
    return $html;
}
