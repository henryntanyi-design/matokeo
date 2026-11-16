<?php
/**
 * Export ya matokeo kwenda Excel (PhpSpreadsheet) na PDF (Dompdf)
 * Inahitaji: composer install  (phpoffice/phpspreadsheet, dompdf/dompdf)
 */
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/zip_fallback.php';

function load_vendor() {
    $a = __DIR__ . '/../vendor/autoload.php';
    if (is_file($a)) { require_once $a; return true; }
    return false;
}

/** Je, kusoma/kuandika .xlsx kunawezekana? (ZipArchive au fallback ya pure-PHP) */
function xlsx_zip_available() { return class_exists('ZipArchive'); }

/** Ujumbe mmoja wa kuelekeza mtumiaji kuwasha extension ya zip. */
function xlsx_zip_error() {
    return 'The PHP "zip" extension is not enabled on this server, so some Excel (.xlsx) operations are unavailable. '
        . 'In Laragon: open Menu > PHP > php.ini, remove the ";" before "extension=zip", then restart Laragon (Menu > PHP > Restart).';
}

/** Tupa kosa lililoeleweka kama zip haipo (badala ya "Class ZipArchive not found"). */
function xlsx_require_zip() {
    if (!xlsx_zip_available()) throw new Exception(xlsx_zip_error());
}

function xlsx_letter($index) {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex((int)$index);
}

/**
 * "Overall Results": muundo wa template ya OASIS (division summary, subject performance
 * summary, centre ranking na jedwali la watahiniwa lenye MARKS/GRADE kwa kila somo).
 */
function xlsx_school(array $d, $names = true) {
    if (!load_vendor()) { http_response_code(500); die('Export ya .xlsx inahitaji PhpSpreadsheet. Endesha "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $bold = ['font' => ['bold' => true]];
    $head = ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E9E9E9']],
             'borders' => ['allBorders' => ['borderStyle' => 'thin']], 'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]];
    $thin = ['borders' => ['allBorders' => ['borderStyle' => 'thin']]];

    $subs = array_values($d['subjects']);
    $n = max(1, count($subs));
    $grades = array_column($d['grading'], 'grade');
    $gn = count($grades);

    /* --- Nafasi za safu --------------------------------------------------- */
    $firstSubCol = 6;                          // F: somo la kwanza (MARKS, GRADE)
    $subLastCol  = $firstSubCol + 2 * $n - 1;
    $totalCol    = $subLastCol + 1;            // TOTAL MARKS
    $avgCol      = $totalCol + 1;              // AVERAGE
    $gradeCol    = $avgCol + 1;                // GRADE
    $ptsCol      = $gradeCol + 1;              // POINTS
    $classCol    = $ptsCol + 1;                // CLASS (division)
    $gpaCol      = $classCol + 1;              // GPA
    $posCol      = $gpaCol + 1;                // POSITION
    $lastCol     = $posCol;
    $analysisCol = 3 + 3 * $gn;                // R kwa O-Level
    $width       = max($lastCol, $analysisCol + 7);
    $WL          = xlsx_letter($width);

    $ws = $wb->getActiveSheet(); $ws->setTitle('Overall Results');

    /* --- Kichwa cha hati ------------------------------------------------- */
    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        strtoupper(trim((string)($d['school']['region'] ?? ''))),
        strtoupper(trim((string)($d['school']['district'] ?? ''))) . (trim((string)($d['school']['district'] ?? '')) !== '' ? ' DISTRICT COUNCIL' : ''),
        strtoupper((string)$d['exam']['exam_name']) . (!empty($d['exam']['exam_month']) ? ', ' . $d['exam']['exam_month'] : '') . ' ' . strtoupper((string)$d['exam']['exam_year']),
        $d['necta_no'] . ' - ' . strtoupper((string)($d['school']['school_name'] ?? '')) . ' SECONDARY SCHOOL',
    ];
    foreach ($titles as $i => $t) {
        $row = $i + 1;
        $ws->mergeCells('A' . $row . ':' . $WL . $row);
        $ws->setCellValue('A' . $row, $t);
        $ws->getStyle('A' . $row)->getAlignment()->setHorizontal('center')->setVertical('center');
        $ws->getStyle('A' . $row)->getFont()->setBold(true)->setSize($i < 2 ? 11 : 13);
    }

    /* --- DIVISION PERFORMANCE SUMMARY ----------------------------------- */
    $ws->mergeCells('A8:' . $WL . '8');
    $ws->setCellValue('A8', 'DIVISION PERFORMANCE SUMMARY');
    $ws->getStyle('A8')->applyFromArray($bold);
    $ws->getStyle('A8')->getAlignment()->setHorizontal('center');
    $divCols = ['SEX', 'I', 'II', 'III', 'IV', '0', 'INCOMPLETE', 'ABSENT', 'TOTAL'];
    foreach ($divCols as $i => $t) $ws->setCellValue(xlsx_letter($i + 1) . '9', $t);
    $ws->getStyle('A9:I9')->applyFromArray($head);
    foreach ([['F', 10], ['M', 11], ['T', 12]] as $dr) {
        list($lab, $row) = $dr;
        $vals = [];
        foreach (['I', 'II', 'III', 'IV', '0', 'INC', 'ABS'] as $dv) {
            $f = (int)($d['divSummary'][$dv]['F'] ?? 0);
            $m = (int)($d['divSummary'][$dv]['M'] ?? 0);
            $vals[] = ($lab === 'T') ? $f + $m : ($lab === 'F' ? $f : $m);
        }
        $vals[] = array_sum($vals);
        $ws->setCellValue('A' . $row, $lab);
        foreach ($vals as $i => $v) $ws->setCellValue(xlsx_letter($i + 2) . $row, $v);
    }
    $ws->getStyle('A10:I12')->applyFromArray($thin);
    $ws->getStyle('A10:A12')->applyFromArray($bold);
    $ws->getStyle('A10:I12')->getAlignment()->setHorizontal('center');

    /* --- SUBJECT PERFORMANCE SUMMARY ------------------------------------ */
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

    $sr = 14;
    $ws->mergeCells('A' . $sr . ':' . $WL . $sr);
    $ws->setCellValue('A' . $sr, 'SUBJECT PERFORMANCE SUMMARY');
    $ws->getStyle('A' . $sr)->applyFromArray($bold);
    $ws->getStyle('A' . $sr)->getAlignment()->setHorizontal('center');
    $h1 = $sr + 1; $h2 = $sr + 2; $h3 = $sr + 3;
    $ws->mergeCells('A' . $h1 . ':A' . $h3);
    $ws->setCellValue('A' . $h1, 'SN');
    $ws->mergeCells('B' . $h1 . ':B' . $h3);
    $ws->setCellValue('B' . $h1, 'SUBJECTS');
    $ws->mergeCells('C' . $h1 . ':' . xlsx_letter($analysisCol - 1) . $h1);
    $ws->setCellValue('C' . $h1, 'SUBJECT GRADE');
    $ws->mergeCells(xlsx_letter($analysisCol) . $h1 . ':' . xlsx_letter($analysisCol + 7) . $h1);
    $ws->setCellValue(xlsx_letter($analysisCol) . $h1, 'SUBJECT PERFORMANCE ANALYSIS');
    foreach ($grades as $i => $g) {
        $col = 3 + 3 * $i;
        $ws->mergeCells(xlsx_letter($col) . $h2 . ':' . xlsx_letter($col + 2) . $h2);
        $ws->setCellValue(xlsx_letter($col) . $h2, $g);
    }
    $an = ['TOTAL', 'AVERAGE', 'GRADE', 'GPA', 'PASS', 'FAIL', 'RANK', 'COMPETENCE LEVEL'];
    foreach ($an as $i => $t) $ws->setCellValue(xlsx_letter($analysisCol + $i) . $h2, $t);
    foreach ($grades as $i => $g) {
        $col = 3 + 3 * $i;
        foreach (['F', 'M', 'T'] as $j => $sx) $ws->setCellValue(xlsx_letter($col + $j) . $h3, $sx);
    }
    $ws->getStyle('A' . $h1 . ':' . $WL . $h3)->applyFromArray($head);

    $row = $h3 + 1;
    foreach ($subs as $i => $s) {
        $sid = (int)$s['id'];
        $ws->setCellValue('A' . $row, $i + 1);
        $ws->setCellValue('B' . $row, strtoupper((string)$s['name']));
        foreach ($grades as $gi => $g) {
            $col = 3 + 3 * $gi;
            $f = (int)($sexStat[$sid]['F'][$g] ?? 0);
            $m = (int)($sexStat[$sid]['M'][$g] ?? 0);
            $ws->setCellValue(xlsx_letter($col) . $row, $f);
            $ws->setCellValue(xlsx_letter($col + 1) . $row, $m);
            $ws->setCellValue(xlsx_letter($col + 2) . $row, $f + $m);
        }
        $st = $statsBySub[$sid] ?? null;
        if ($st) {
            $ws->setCellValue(xlsx_letter($analysisCol) . $row, $st['sat']);
            $ws->setCellValue(xlsx_letter($analysisCol + 1) . $row, $st['avg']);
            $ws->setCellValue(xlsx_letter($analysisCol + 2) . $row, $st['avg'] !== null ? grade_for($st['avg'], $d['grading'])['grade'] : '');
            $ws->setCellValue(xlsx_letter($analysisCol + 3) . $row, $st['gpa']);
            $ws->setCellValue(xlsx_letter($analysisCol + 4) . $row, $st['pass']);
            $ws->setCellValue(xlsx_letter($analysisCol + 5) . $row, max(0, $st['sat'] - $st['pass']));
            $ws->setCellValue(xlsx_letter($analysisCol + 6) . $row, $rankMap[$sid] ?? '');
            $cl = competence_level($st['gpa']);
            $cc = xlsx_letter($analysisCol + 7) . $row;
            $ws->setCellValue($cc, $cl ? $cl['label'] : '');
            if ($cl) {
                $ws->getStyle($cc)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim($cl['bg'], '#'));
                $ws->getStyle($cc)->getFont()->getColor()->setRGB(ltrim($cl['fg'], '#'));
                $ws->getStyle($cc)->getFont()->setBold(true);
            }
        }
        $row++;
    }
    $ws->getStyle('A' . ($h3 + 1) . ':' . xlsx_letter($analysisCol + 7) . ($row - 1))->applyFromArray($thin);
    $ws->getStyle('A' . ($h3 + 1) . ':' . xlsx_letter($analysisCol + 7) . ($row - 1))->getAlignment()->setHorizontal('center');
    $ws->getStyle('B' . ($h3 + 1) . ':B' . ($row - 1))->getAlignment()->setHorizontal('left');
    $ws->getStyle(xlsx_letter($analysisCol + 7) . ($h3 + 1) . ':' . xlsx_letter($analysisCol + 7) . ($row - 1))->getAlignment()->setWrapText(true);

    /* --- EXAMINATION CENTRE RANKING ------------------------------------- */
    $row++;
    $ws->mergeCells('A' . $row . ':' . $WL . $row);
    $ws->setCellValue('A' . $row, 'EXAMINATION CENTRE RANKING');
    $ws->getStyle('A' . $row)->applyFromArray($bold);
    $ws->getStyle('A' . $row)->getAlignment()->setHorizontal('center');

    $okCands = array_values(array_filter($d['candidates'], function ($c) { return $c['state'] === 'OK'; }));
    $passed = 0; $ptsSum = 0; $avgSum = 0; $avgN = 0;
    foreach ($d['candidates'] as $c) {
        if ($c['average'] !== null) { $avgSum += $c['average']; $avgN++; }
    }
    foreach ($okCands as $c) {
        $ptsSum += (int)$c['points'];
        if (in_array($c['division'], ['I', 'II', 'III', 'IV'], true)) $passed++;
    }
    $centreGpa = centre_gpa($d);
    $schoolAvg = $avgN ? round($avgSum / $avgN, 2) : null;
    $cat = count($d['candidates']) < 40 ? 'CENTER WITH LESS THAN 40 CANDIDATES' : 'CENTER WITH 40 OR MORE CANDIDATES';

    $row++;
    $ws->setCellValue('A' . $row, 'EXAMINATION CENTRE REGION:');
    $ws->setCellValue('D' . $row, strtoupper(trim((string)($d['school']['region'] ?? ''))));
    $ws->setCellValue('G' . $row, 'TOTAL PASSED CANDIDATES:');
    $ws->setCellValue('H' . $row, $passed);
    $ws->setCellValue('M' . $row, 'CENTRE CATEGORY:');
    $ws->setCellValue('N' . $row, $cat);
    $row++;
    $ws->setCellValue('A' . $row, 'EXAMINATION CENTRE DISTRICT:');
    $ws->setCellValue('D' . $row, strtoupper(trim((string)($d['school']['district'] ?? ''))));
    $ws->setCellValue('G' . $row, 'EXAMINATION CENTRE GPA:');
    $ws->setCellValue('H' . $row, $centreGpa);
    $ws->setCellValue('M' . $row, 'SCHOOL AVERAGE:');
    $ws->setCellValue('N' . $row, $schoolAvg);
    $ws->getStyle('A' . ($row - 1) . ':N' . $row)->getFont()->setSize(9);
    $ws->getStyle('A' . ($row - 1) . ':A' . $row)->applyFromArray($bold);

    /* --- JEDWALI LA WATAHINIWA (Overall Results) ------------------------ */
    $regMap = [];
    foreach (q_all('SELECT ss.student_id, ss.subject_id FROM student_subjects ss JOIN students st ON st.id = ss.student_id
                    WHERE st.school_id = ? AND st.class_id = ?',
                    [(int)$d['school']['id'], (int)$d['class']['id']]) as $x) {
        $regMap[(int)$x['student_id']][(int)$x['subject_id']] = true;
    }
    $hasReg = (bool)$regMap;
    $subIds = array_map('intval', array_column($subs, 'id'));

    $row += 3;
    $ch1 = $row; $ch2 = $row + 1; $ch3 = $row + 2;
    $ws->setCellValue('A' . $ch1, 'S/N');
    $ws->setCellValue('B' . $ch1, 'NAME OF CANDIDATE');
    $ws->setCellValue('C' . $ch1, 'SEX');
    $ws->setCellValue('D' . $ch1, 'INDEX NO');
    $ws->setCellValue('E' . $ch1, 'REGISTERED SUBJECTS');
    $ws->mergeCells('F' . $ch1 . ':' . xlsx_letter($subLastCol) . $ch1);
    $ws->setCellValue('F' . $ch1, 'GENERAL SUBJECTS');
    $ws->setCellValue(xlsx_letter($totalCol) . $ch1, 'TOTAL MARKS');
    $ws->setCellValue(xlsx_letter($avgCol) . $ch1, 'AVERAGE');
    $ws->setCellValue(xlsx_letter($gradeCol) . $ch1, 'GRADE');
    $ws->mergeCells(xlsx_letter($ptsCol) . $ch1 . ':' . xlsx_letter($classCol) . $ch1);
    $ws->setCellValue(xlsx_letter($ptsCol) . $ch1, 'DIVISION');
    $ws->setCellValue(xlsx_letter($gpaCol) . $ch1, 'GPA');
    $ws->setCellValue(xlsx_letter($posCol) . $ch1, 'POSITION');
    foreach (['A' . $ch1, 'B' . $ch1, 'C' . $ch1, 'D' . $ch1, 'E' . $ch1] as $a) $ws->mergeCells($a . ':' . $a[0] . $ch3);
    foreach ($subs as $i => $s) {
        $col = $firstSubCol + 2 * $i;
        $ws->mergeCells(xlsx_letter($col) . $ch2 . ':' . xlsx_letter($col + 1) . $ch2);
        $ws->setCellValue(xlsx_letter($col) . $ch2, strtoupper((string)$s['name']));
        $ws->setCellValue(xlsx_letter($col) . $ch3, 'MARKS');
        $ws->setCellValue(xlsx_letter($col + 1) . $ch3, 'GRADE');
    }
    $ws->setCellValue(xlsx_letter($ptsCol) . $ch3, 'POINTS');
    $ws->setCellValue(xlsx_letter($classCol) . $ch3, 'CLASS');
    $ws->getStyle('A' . $ch1 . ':' . $WL . $ch3)->applyFromArray($head);
    $ws->getStyle('A' . $ch2 . ':' . $WL . $ch3)->getFont()->setSize(9);
    $ws->getRowDimension($ch2)->setRowHeight(45);

    $row = $ch3 + 1;
    $sn = 1;
    foreach ($d['candidates'] as $c) {
        $sid = (int)$c['student_id'];
        $regN = $hasReg ? count(array_intersect($subIds, array_keys($regMap[$sid] ?? []))) : $n;
        $bySub = [];
        foreach ($c['subjects'] as $x) $bySub[(int)$x['subject_id']] = $x;
        $ws->setCellValue('A' . $row, $sn);
        $ws->setCellValue('B' . $row, $names ? strtoupper((string)$c['name']) : '');
        $ws->setCellValue('C' . $row, $c['sex']);
        $ws->setCellValueExplicit('D' . $row, (string)$c['cand_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('E' . $row, $regN);
        foreach ($subs as $i => $s) {
            $col = $firstSubCol + 2 * $i;
            $x = $bySub[(int)$s['id']] ?? null;
            if (!$x) continue;
            $ws->setCellValue(xlsx_letter($col) . $row, $x['score'] === null ? 'ABS' : (0 + $x['score']));
            $ws->setCellValue(xlsx_letter($col + 1) . $row, (string)$x['grade']);
        }
        $ptsN = 0; $ptsTotal = 0;
        foreach ($c['subjects'] as $x) if ($x['points'] !== null) { $ptsTotal += (int)$x['points']; $ptsN++; }
        $ws->setCellValue(xlsx_letter($totalCol) . $row, $c['total'] > 0 ? (0 + $c['total']) : '');
        $ws->setCellValue(xlsx_letter($avgCol) . $row, $c['average'] === null ? '' : (0 + $c['average']));
        $ws->setCellValue(xlsx_letter($gradeCol) . $row, $c['average'] === null ? '' : grade_for($c['average'], $d['grading'])['grade']);
        $ws->setCellValue(xlsx_letter($ptsCol) . $row, $c['points'] === null ? '' : (int)$c['points']);
        $ws->setCellValue(xlsx_letter($classCol) . $row, (string)$c['division']);
        $ws->setCellValue(xlsx_letter($gpaCol) . $row, $ptsN ? round($ptsTotal / $ptsN, 4) : '');
        $ws->setCellValue(xlsx_letter($posCol) . $row, isset($c['position']) ? (int)$c['position'] : '');
        $row++; $sn++;
    }
    $dataEnd = $row - 1;
    if ($dataEnd >= $ch3 + 1) {
        $ws->getStyle('A' . ($ch3 + 1) . ':' . $WL . $dataEnd)->applyFromArray($thin);
        $ws->getStyle('A' . ($ch3 + 1) . ':' . $WL . $dataEnd)->getFont()->setSize(9);
        $ws->getStyle('A' . ($ch3 + 1) . ':' . $WL . $dataEnd)->getAlignment()->setHorizontal('center');
        $ws->getStyle('B' . ($ch3 + 1) . ':B' . $dataEnd)->getAlignment()->setHorizontal('left');
    }

    /* --- Upana wa safu na uchapishaji ----------------------------------- */
    $ws->getColumnDimension('A')->setWidth(4);
    $ws->getColumnDimension('B')->setWidth(30);
    $ws->getColumnDimension('C')->setWidth(5);
    $ws->getColumnDimension('D')->setWidth(14);
    $ws->getColumnDimension('E')->setWidth(12);
    for ($i = 0; $i < 2 * $n; $i++) $ws->getColumnDimension(xlsx_letter($firstSubCol + $i))->setWidth($i % 2 ? 6 : 7);
    for ($i = $totalCol; $i <= $lastCol; $i++) $ws->getColumnDimension(xlsx_letter($i))->setWidth(9);
    for ($i = $analysisCol; $i <= $analysisCol + 7; $i++) $ws->getColumnDimension(xlsx_letter($i))->setWidth($i === $analysisCol + 7 ? 34 : 9);
    $ws->setShowGridlines(false);
    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ws->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $ws->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ws->getPageMargins()->setLeft(0.25);
    $ws->getPageMargins()->setRight(0.25);
    $ws->getPageMargins()->setTop(0.4);
    $ws->getPageMargins()->setBottom(0.4);

    // --- Karatasi 2: Watahiniwa
    $ws2 = $wb->createSheet(); $ws2->setTitle('Results');
    $hdr = ['CAND. NO', 'PREM NO'];
    if ($names) $hdr[] = 'NAME';
    $hdr = array_merge($hdr, ['SEX', 'CLASS', 'DIVISION', 'POINTS', 'DETAILED SUBJECTS']);
    $subs = [];
    foreach ($d['subjects'] as $s) $subs[] = $s;
    foreach ($subs as $s) $hdr[] = $s['abbr'];
    $ws2->fromArray($hdr, null, 'A1');
    $ws2->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($hdr)) . '1')->applyFromArray($head);
    $r = 2;
    foreach ($d['candidates'] as $c) {
        $row = [$c['cand_no'], (string)$c['prems_no']];
        if ($names) $row[] = strtoupper($c['name']);
        $row = array_merge($row, [$c['sex'], $c['class_name'], $c['division'], $c['points'], $c['detail']]);
        $bySub = [];
        foreach ($c['subjects'] as $x) $bySub[$x['subject_id']] = $x;
        foreach ($subs as $s) {
            $x = $bySub[$s['id']] ?? null;
            $row[] = $x ? ($x['score'] === null ? $x['grade'] : $x['score'] . ' (' . $x['grade'] . ')') : '';
        }
        $ws2->fromArray($row, null, 'A' . $r);
        $ws2->setCellValueExplicit('B' . $r, (string)$c['prems_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $r++;
    }
    foreach (range(1, count($hdr)) as $i) $ws2->getColumnDimensionByColumn($i)->setAutoSize(true);
    $ws2->freezePane('A2');

    // --- Karatasi 3: Masomo
    $ws3 = $wb->createSheet(); $ws3->setTitle('Subjects');
    $gl = array_column($d['grading'], 'grade');
    $ws3->fromArray(array_merge(['CODE', 'SUBJECT', 'SAT', 'ABS'], $gl, ['PASS', '% PASS', 'GPA']), null, 'A1');
    $ws3->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(7 + count($gl)) . '1')->applyFromArray($head);
    $r = 2;
    foreach ($d['subjStats'] as $s) {
        $row = [$s['code'], strtoupper($s['name']), $s['sat'], $s['absent']];
        foreach ($gl as $g) $row[] = array_key_exists($g, $s['grades']) ? $s['grades'][$g] : '';
        $row[] = $s['pass']; $row[] = $s['pass_pct']; $row[] = $s['gpa'];
        $ws3->fromArray($row, null, 'A' . $r++);
    }
    foreach (range(1, 7 + count($gl)) as $i) $ws3->getColumnDimensionByColumn($i)->setAutoSize(true);
    $wb->setActiveSheetIndex(0);
    return $wb;
}

function xlsx_student(array $d, array $c) {
    if (!load_vendor()) { http_response_code(500); die('Export ya .xlsx inahitaji PhpSpreadsheet. Endesha "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet(); $ws->setTitle('Statement of Results');
    $ws->setCellValue('A1', 'STATEMENT OF RESULTS - ' . strtoupper($d['exam']['exam_name']) . (!empty($d['exam']['exam_month']) ? ', ' . $d['exam']['exam_month'] : '') . ' ' . $d['exam']['exam_year']);
    $ws->setCellValue('A2', $d['necta_no'] . ' - ' . strtoupper($d['school']['school_name'] ?? ''));
    $ws->setCellValue('A4', 'CANDIDATE NO'); $ws->setCellValue('B4', $c['cand_no']);
    $ws->setCellValue('A5', 'PREM NO');      $ws->setCellValueExplicit('B5', (string)$c['prems_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $ws->setCellValue('A6', 'NAME');         $ws->setCellValue('B6', strtoupper($c['name']));
    $ws->setCellValue('A7', 'CLASS');        $ws->setCellValue('B7', strtoupper(preg_replace('/^Form\s+/i', '', (string)$c['class_name'])));
    $ws->fromArray(['CODE', 'SUBJECT', 'SCORE', 'GRADE', 'POINTS', 'REMARK'], null, 'A9');
    $r = 10;
    foreach ($c['subjects'] as $x) $ws->fromArray([$x['code'], strtoupper($x['name']), $x['score'], $x['grade'], $x['points'], $x['remark']], null, 'A' . $r++);
    $r++;
    $ws->setCellValue('A' . $r, 'DIVISION'); $ws->setCellValue('B' . $r, $c['division']); $r++;
    $ws->setCellValue('A' . $r, 'POINTS');   $ws->setCellValue('B' . $r, $c['points']); $r++;
    $ws->setCellValue('A' . $r, 'AVERAGE');  $ws->setCellValue('B' . $r, $c['average']); $r++;
    $ws->setCellValue('A' . $r, 'POSITION'); $ws->setCellValue('B' . $r, isset($c['position']) ? $c['position'] . ' / ' . $c['class_size'] : '-');
    $ws->getStyle('A1:A2')->getFont()->setBold(true);
    $ws->getStyle('A9:F9')->getFont()->setBold(true);
    foreach (range(1, 6) as $i) $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
    return $wb;
}

function send_xlsx($wb, $filename) {
    if (!xlsx_zip_available()) { http_response_code(500); die(h(xlsx_zip_error())); }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $fn = preg_replace('/["\\\\\/:*?<>|\x00-\x1F\x7F]/', '_', (string)$filename);
    header('Content-Disposition: attachment; filename="' . $fn . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb))->save('php://output');
    exit();
}

/** Tuma safu rahisi za data kama faili la .xlsx (mstari wa kwanza = vichwa). */
function send_rows_xlsx($filename, array $rows, $sheetTitle = 'Sheet1') {
    if (!load_vendor()) { http_response_code(500); die('Kuhifadhi .xlsx kunahitaji PhpSpreadsheet. Endesha "composer install".'); }
    if (!xlsx_zip_available()) { http_response_code(500); die(h(xlsx_zip_error())); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle(mb_substr((string)$sheetTitle, 0, 31));
    $maxCol = 0;
    foreach ($rows as $i => $r) {
        foreach (array_values($r) as $j => $v) {
            $ws->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1) . ($i + 1), (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if ($j + 1 > $maxCol) $maxCol = $j + 1;
        }
    }
    if ($rows && $maxCol) {
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxCol);
        $ws->getStyle('A1:' . $last . '1')->getFont()->setBold(true);
    }
    for ($i = 1; $i <= $maxCol; $i++) $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
    send_xlsx($wb, $filename);
}

/** Tuma PDF kupitia Dompdf; kama haijasakinishwa, fungua ukurasa wa print (Save as PDF). */
/** Hurejesha PDF kama string (au null kama Dompdf haipo). */
function pdf_bytes($innerHtml, $title, $landscape = true) {
    if (!class_exists('Dompdf\\Dompdf')) load_vendor();
    if (!class_exists('Dompdf\\Dompdf')) return null;
    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', false);
    $opts->set('defaultFont', 'DejaVu Sans');
    $dom = new \Dompdf\Dompdf($opts);
    $dom->loadHtml(page_shell($title, $innerHtml, false, $landscape), 'UTF-8');
    $dom->setPaper('A4', $landscape ? 'landscape' : 'portrait');
    $dom->render();
    $canvas = $dom->getCanvas();
    $fontMetrics = $dom->getFontMetrics();
    $font = $fontMetrics->getFont('DejaVu Sans', 'bold');
    $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
    $size = 10;
    $x = 20;
    $y = $canvas->get_height() - 25;
    $canvas->page_text($x, $y, $text, $font, $size, [0.07, 0.19, 0.31]);
    return $dom->output();
}
function send_pdf($innerHtml, $title, $filename, $landscape = true, $inline = false) {
    if (!class_exists('Dompdf\\Dompdf')) load_vendor();
    if (!class_exists('Dompdf\\Dompdf')) {
        echo page_shell($title, $innerHtml, true, $landscape);   // fallback: print dialog -> Save as PDF
        exit();
    }
    $pdf = pdf_bytes($innerHtml, $title, $landscape);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    $disp = $inline ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disp . '; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}
/** Pakua PDF nyingi kama ZIP; kama zip haipo, tengeneza PDF moja yenye kurasa zote. */
function send_pdf_zip(array $files, $zipName) {
    while (ob_get_level()) ob_end_clean();
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'ngz');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) === true) {
            $ok = 0;
            foreach ($files as $fn => $html) {
                $pdf = pdf_bytes($html, $fn, false);
                if ($pdf !== null) { $zip->addFromString(preg_replace('/[^A-Za-z0-9_.-]/', '_', $fn) . '.pdf', $pdf); $ok++; }
            }
            $zip->close();
            if ($ok) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $zipName) . '.zip"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp); @unlink($tmp);
                exit();
            }
        }
        @unlink($tmp);
    }
    $html = '';
    foreach ($files as $fn => $h) $html .= '<div style="page-break-after:always">' . $h . '</div>';
    send_pdf($html, 'Statements of Results', $zipName, false);
}

function results_rows(array $d, $names = true) {
    $rows = [[strtoupper($d['exam']['exam_name']) . (!empty($d['exam']['exam_month']) ? ', ' . $d['exam']['exam_month'] : '') . ' ' . $d['exam']['exam_year']], [$d['necta_no'] . ' - ' . strtoupper($d['school']['school_name']) . ' - ' . strtoupper($d['class']['name'])], []];
    $hdr = ['CAND. NO', 'ADMISSION/PREM NO'];
    if ($names) $hdr[] = 'NAME';
    $rows[] = array_merge($hdr, ['SEX', 'DIVISION', 'POINTS', 'POSITION', 'AVERAGE', 'DETAILED SUBJECTS']);
    foreach ($d['candidates'] as $c) {
        $r = [$c['cand_no'], $c['prems_no']];
        if ($names) $r[] = strtoupper($c['name']);
        $rows[] = array_merge($r, [$c['sex'], $c['division'], $c['points'], $c['position'] ?? '', $c['average'], $c['detail']]);
    }
    return $rows;
}
function student_rows(array $d, array $c) {
    $rows = [['STATEMENT OF RESULTS - ' . strtoupper($d['exam']['exam_name']) . (!empty($d['exam']['exam_month']) ? ', ' . $d['exam']['exam_month'] : '') . ' ' . $d['exam']['exam_year']], [$d['necta_no'] . ' - ' . strtoupper($d['school']['school_name'])],
             ['CAND. NO', $c['cand_no']], ['NAME', strtoupper($c['name'])], ['CLASS', strtoupper(preg_replace('/^Form\s+/i', '', (string)$c['class_name']))], [], ['CODE', 'SUBJECT', 'SCORE', 'GRADE', 'POINTS', 'REMARK']];
    foreach ($c['subjects'] as $x) $rows[] = [$x['code'], strtoupper($x['name']), $x['score'], $x['grade'], $x['points'], $x['remark']];
    $rows[] = [];
    $rows[] = ['DIVISION', $c['division']]; $rows[] = ['POINTS', $c['points']]; $rows[] = ['AVERAGE', $c['average']];
    $rows[] = ['POSITION', isset($c['position']) ? $c['position'] . ' / ' . $c['class_size'] : '-'];
    return $rows;
}

/** Soma jedwali kutoka faili la .xlsx hadi array ya mistari. */
function read_table($path, $ext) {
    $ext = strtolower($ext);
    if ($ext !== 'xlsx') throw new Exception('Aina ya faili haikubaliki. Tumia .xlsx pekee.');
    if (!load_vendor()) throw new Exception('Kusoma .xlsx kunahitaji PhpSpreadsheet. Endesha "composer install".');
    xlsx_require_zip();
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $wb = $reader->load($path);
    return $wb->getSheet(0)->toArray(null, true, false, false);
}

/* ---------------- Matokeo ya wilaya ---------------- */
function council_rows(array $c) {
    $rows = [[strtoupper($c['exam']['exam_name']) . (!empty($c['exam']['exam_month']) ? ', ' . $c['exam']['exam_month'] : '') . ' ' . $c['exam']['exam_year']], ['DISTRICT SCHOOLS PERFORMANCE - ' . strtoupper($c['class']['name'])], [],
             ['RANK', 'CENTRE', 'SCHOOL', 'REGISTERED', 'I', 'II', 'III', 'IV', '0', 'INC', 'ABS', 'PENDING', 'AVG POINTS', '% PASS (I-IV)']];
    foreach ($c['rows'] as $r) $rows[] = [$r['rank'], $r['centre'], strtoupper($r['name']), $r['registered'], $r['I'], $r['II'], $r['III'], $r['IV'], $r['0'], $r['INC'], $r['ABS'], $r['PENDING'], $r['avg_points'], $r['pass_pct']];
    return $rows;
}
function xlsx_council(array $c) {
    if (!load_vendor()) { http_response_code(500); die('Export ya .xlsx inahitaji PhpSpreadsheet. Endesha "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet(); $ws->setTitle('Council');
    $rows = council_rows($c);
    foreach ($rows as $i => $r) foreach ($r as $j => $v) $ws->setCellValueByColumnAndRow($j + 1, $i + 1, $v);
    $ws->getStyle('A1:A2')->getFont()->setBold(true);
    $ws->getStyle('A4:N4')->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E9E9E9']]]);
    foreach (range(1, 14) as $i) $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
    return $wb;
}
