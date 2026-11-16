<?php
/**
 * Nyaraka za mtihani kwa muundo wa OASIS:
 * CAL (Collective Attendance List), ISAL (Individual Subject Attendance List),
 * Registered Candidates Statistics na Sitting Plan.
 */
require_once __DIR__ . '/export.php';

function exam_doc_full_school(array $sc) {
    $name = strtoupper(trim((string)($sc['name'] ?? '')));
    if ($name === '') return '';
    if (stripos($name, 'SECONDARY') === false) $name .= ' SECONDARY SCHOOL';
    return $name;
}

function exam_doc_short_school(array $sc) {
    $name = strtoupper(trim((string)($sc['name'] ?? '')));
    $name = preg_replace('/\s+SECONDARY\s+SCHOOL$/i', '', $name);
    return trim($name);
}

function exam_doc_region(array $sc) {
    $r = strtoupper(trim((string)($sc['region'] ?? '')));
    return $r !== '' ? $r . ' REGION' : 'REGION';
}

function exam_doc_district(array $sc) {
    $d = strtoupper(trim((string)($sc['district'] ?? '')));
    return $d !== '' ? $d . ' DISTRICT COUNCIL' : 'DISTRICT COUNCIL';
}

function exam_doc_exam_title(array $exam) {
    return strtoupper(trim((string)($exam['name'] ?? '')));
}

function exam_doc_month_year(array $exam) {
    $ts = !empty($exam['start_date']) ? strtotime($exam['start_date']) : time();
    if (!$ts) $ts = time();
    return strtoupper(date('F', $ts)) . '-' . date('Y', $ts);
}

function exam_doc_date(array $exam) {
    $ts = !empty($exam['start_date']) ? strtotime($exam['start_date']) : time();
    if (!$ts) $ts = time();
    return date('d/m/Y', $ts);
}

function exam_doc_centre_line(array $sc) {
    return 'CENTER NO: ' . school_centre($sc) . '                                          CENTER NAME: ' . exam_doc_full_school($sc);
}

/** Watahiniwa wa darasa moja kwenye mtihani, kwa mpangilio wa jina. */
function exam_doc_candidates($exam_id, $school_id, $class_id) {
    return q_all('SELECT ec.cand_no, s.id AS student_id, s.admission_no, s.prems_no, s.first_name, s.middle_name, s.last_name, s.sex
                  FROM exam_candidates ec JOIN students s ON s.id = ec.student_id
                  WHERE ec.exam_id = ? AND ec.school_id = ? AND ec.class_id = ?
                  ORDER BY s.last_name, s.first_name, s.middle_name', [(int)$exam_id, (int)$school_id, (int)$class_id]);
}

/**
 * Usajili wa wanafunzi wa darasa kwenye masomo: student_id => [subject_id => true].
 * Hutumika kwenye CAL kuweka alama ya "_" kwenye masomo aliyosajiliwa mwanafunzi pekee.
 */
function exam_doc_subject_registrations($school_id, $class_id) {
    $out = [];
    foreach (q_all("SELECT ss.student_id, ss.subject_id FROM student_subjects ss JOIN students s ON s.id = ss.student_id
                    WHERE s.school_id = ? AND s.class_id = ? AND s.status = 'active'",
                    [(int)$school_id, (int)$class_id]) as $r) {
        $out[(int)$r['student_id']][(int)$r['subject_id']] = true;
    }
    return $out;
}

function exam_doc_col($index) {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
}

function exam_doc_sheet_title($text) {
    $text = preg_replace('/[\\\\\\/?*\[\]:]/', ' ', (string)$text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return mb_substr($text, 0, 31);
}

function exam_doc_styles() {
    return [
        'title' => [
            'font' => ['name' => 'Copperplate Gothic Bold', 'size' => 15, 'bold' => true, 'color' => ['rgb' => '003366']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ],
        'subtitle' => [
            'font' => ['name' => 'Times New Roman', 'size' => 14, 'bold' => true],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ],
        'head' => [
            'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ],
        'data' => [
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['vertical' => 'bottom'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ],
    ];
}

function exam_doc_page_setup($ws, $landscape = true) {
    $ps = $ws->getPageSetup();
    $ps->setOrientation($landscape ? \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE : \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(0);
    $ws->getPageMargins()->setLeft(0.25);
    $ws->getPageMargins()->setRight(0.25);
    $ws->getPageMargins()->setTop(0.5);
    $ws->getPageMargins()->setBottom(0.5);
}

/* ------------------------------------------------------------------ */
/* CAL - Collective Attendance List (darasa moja)                      */
/* ------------------------------------------------------------------ */
function xlsx_cal(array $exam, array $sc, array $class, array $subjects, array $candidates, $headName = '') {
    if (!load_vendor()) throw new Exception('Nyaraka za .xlsx zinahitaji PhpSpreadsheet. Endesha "composer install".');
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle(exam_doc_sheet_title('CAL ' . $class['name']));

    $n = max(1, count($subjects));
    $firstSubCol = 7;                 // G
    $lastSubCol = $firstSubCol + $n - 1;
    $sigCol = $lastSubCol + 1;        // SIGNATURE
    $last = exam_doc_col($sigCol);
    $st = exam_doc_styles();

    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        exam_doc_region($sc),
        exam_doc_district($sc),
        exam_doc_exam_title($exam) . ', ' . exam_doc_month_year($exam),
    ];
    foreach ($titles as $i => $t) {
        $r = $i + 1;
        $ws->mergeCells('A' . $r . ':' . $last . $r);
        $ws->setCellValue('A' . $r, $t);
    }
    $ws->mergeCells('A6:' . $last . '6');
    $ws->setCellValue('A6', exam_doc_centre_line($sc));
    $ws->mergeCells('A7:' . $last . '7');
    $ws->setCellValue('A7', 'COLLECTIVE ATTENDANCE LIST (CAL)');
    $ws->mergeCells('A8:' . $last . '8');

    $ws->getStyle('A1:' . $last . '5')->applyFromArray($st['title']);
    $ws->getStyle('A6:A7')->applyFromArray($st['subtitle']);

    $widths = ['A' => 3.5, 'B' => 30, 'C' => 5, 'D' => 14, 'E' => 15, 'F' => 6];
    foreach ($widths as $col => $w) $ws->getColumnDimension($col)->setWidth($w);
    for ($i = 0; $i < $n; $i++) $ws->getColumnDimension(exam_doc_col($firstSubCol + $i))->setWidth(3.4);
    $ws->getColumnDimension($last)->setWidth(11);

    // Vichwa vya jedwali
    $ws->mergeCells('A9:A10');
    $ws->mergeCells('B9:B10');
    $ws->mergeCells('C9:C10');
    $ws->mergeCells('D9:D10');
    $ws->mergeCells('E9:E10');
    $ws->mergeCells('F9:F10');
    if ($lastSubCol > $firstSubCol) $ws->mergeCells('G9:' . exam_doc_col($lastSubCol) . '9');
    $ws->mergeCells($last . '9:' . $last . '10');

    $ws->setCellValue('A9', 'S/N');
    $ws->setCellValue('B9', 'NAME OF CANDIDATE');
    $ws->setCellValue('C9', 'SEX');
    $ws->setCellValue('D9', 'EXAM NO.');
    $ws->setCellValue('E9', 'SCHOOL NAME');
    $ws->setCellValue('F9', 'TOTAL REGISTERED SUBJECTS');
    $ws->setCellValue('G9', 'GENERAL SUBJECTS');
    $ws->setCellValue($last . '9', 'SIGNATURE');
    foreach ($subjects as $i => $s) {
        $col = exam_doc_col($firstSubCol + $i);
        $ws->setCellValue($col . '10', strtoupper($s['name']));
        $ws->getStyle($col . '10')->getAlignment()->setTextRotation(90)->setVertical('bottom')->setHorizontal('center');
    }
    $ws->getStyle('A9:' . $last . '10')->applyFromArray($st['head']);
    foreach ($subjects as $i => $s) {
        $ws->getStyle(exam_doc_col($firstSubCol + $i) . '10')->getAlignment()->setTextRotation(90)->setVertical('bottom')->setHorizontal('center');
    }
    $ws->getRowDimension(10)->setRowHeight(150);

    $r = 11;
    $count = count($subjects);
    $subIds = [];
    foreach ($subjects as $i => $s) $subIds[$i] = (int)$s['id'];
    // Usajili wa somo: "_" huwekwa kwenye masomo aliyosajiliwa mwanafunzi pekee; mengine hubaki wazi.
    // Darasa lisilo na usajili wowote haliweki alama yoyote (safu zinabaki wazi).
    $regMap = exam_doc_subject_registrations((int)$sc['id'], (int)$class['id']);
    foreach ($candidates as $c) {
        $marked = [];
        $own = $regMap[(int)($c['student_id'] ?? 0)] ?? [];
        foreach ($subIds as $i => $subId) if (isset($own[$subId])) $marked[$i] = true;
        $ws->setCellValue('A' . $r, $r - 10);
        $ws->setCellValue('B' . $r, strtoupper(full_name($c)));
        $ws->setCellValue('C' . $r, strtoupper((string)$c['sex']));
        $ws->setCellValueExplicit('D' . $r, (string)$c['cand_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('E' . $r, exam_doc_short_school($sc));
        $ws->setCellValue('F' . $r, count($marked));
        foreach ($marked as $i => $unused) $ws->setCellValue(exam_doc_col($firstSubCol + $i) . $r, '_');
        $r++;
    }
    $dataEnd = $r - 1;
    if ($dataEnd >= 11) {
        $ws->getStyle('A11:' . $last . $dataEnd)->applyFromArray($st['data']);
        for ($rr = 11; $rr <= $dataEnd; $rr++) {
            foreach (['A', 'C', 'D', 'E', 'F'] as $col) $ws->getStyle($col . $rr)->getAlignment()->setHorizontal('center');
            for ($i = 0; $i < $count; $i++) $ws->getStyle(exam_doc_col($firstSubCol + $i) . $rr)->getAlignment()->setHorizontal('center');
        }
    }

    // Sahihi za mwisho
    $f = max(11, $dataEnd) + 3;
    $ws->setCellValue('A' . $f, "Head of School's name & Signature");
    $ws->setCellValue('G' . $f, "Supervisor's Name: _________________________");
    $ws->setCellValue('A' . ($f + 1), $headName !== '' ? strtoupper($headName) : '_________________________');
    $ws->setCellValue('G' . ($f + 1), 'Signature: ____________________');
    $ws->setCellValue('A' . ($f + 2), 'Official Stamp: ____________________');
    $ws->setCellValue('G' . ($f + 2), 'Date: ' . exam_doc_date($exam));
    $ws->setCellValue('A' . ($f + 3), 'Date: ' . exam_doc_date($exam));
    $ws->getStyle('A' . $f . ':' . $last . ($f + 3))->getFont()->setName('Calibri')->setSize(11);

    exam_doc_page_setup($ws, true);
    return $wb;
}

/* ------------------------------------------------------------------ */
/* ISAL - Individual Subject Attendance List (darasa + somo)           */
/* ------------------------------------------------------------------ */
function xlsx_isal(array $exam, array $sc, array $class, array $subject, array $candidates) {
    if (!load_vendor()) throw new Exception('Nyaraka za .xlsx zinahitaji PhpSpreadsheet. Endesha "composer install".');
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle(exam_doc_sheet_title('ISAL ' . $class['name'] . ' ' . ($subject['abbr'] ?: $subject['name'])));

    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        exam_doc_region($sc),
        exam_doc_district($sc),
        exam_doc_exam_title($exam) . ', ' . exam_doc_month_year($exam),
    ];
    foreach ($titles as $i => $t) {
        $r = $i + 1;
        $ws->mergeCells('B' . $r . ':G' . $r);
        $ws->setCellValue('B' . $r, $t);
    }
    $ws->mergeCells('B6:G6');
    $ws->setCellValue('B6', 'INDIVIDUAL SUBJECT ATTENDANCE LIST (ISAL) - ' . strtoupper($class['name']));
    $ws->mergeCells('B7:G7');
    $ws->setCellValue('B7', exam_doc_centre_line($sc));
    $ws->mergeCells('B8:G8');
    $ws->setCellValue('B8', 'SUBJECT NAME: ' . strtoupper($subject['name']) . '              SUBJECT CODE: ' . strtoupper((string)$subject['code']) . '              DATE: ______________');

    // Mandhari ya juu: mistari ya serikali
    $ws->getStyle('B1:G5')->applyFromArray([
        'font' => ['name' => 'Arial', 'size' => 12, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9D9D9']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['outline' => ['borderStyle' => 'thin']],
    ]);
    $ws->getStyle('B3:B4')->getFont()->setSize(14);
    $ws->getStyle('B6')->applyFromArray(['font' => ['name' => 'Times New Roman', 'size' => 12, 'bold' => true], 'alignment' => ['horizontal' => 'center', 'vertical' => 'center']]);
    $ws->getStyle('B7:B8')->applyFromArray(['font' => ['name' => 'Times New Roman', 'size' => 11, 'bold' => true], 'alignment' => ['horizontal' => 'center', 'vertical' => 'center']]);
    $ws->getStyle('B6:G8')->applyFromArray(['borders' => ['outline' => ['borderStyle' => 'thin']]]);

    $ws->getColumnDimension('A')->setWidth(2);
    $ws->getColumnDimension('B')->setWidth(4.8);
    $ws->getColumnDimension('C')->setWidth(32.7);
    $ws->getColumnDimension('D')->setWidth(10);
    $ws->getColumnDimension('E')->setWidth(17.2);
    $ws->getColumnDimension('F')->setWidth(17.6);
    $ws->getColumnDimension('G')->setWidth(8.5);

    $head = [
        'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBE5F2']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ];
    $ws->mergeCells('B10:B11');
    $ws->mergeCells('C10:C11');
    $ws->mergeCells('D10:D11');
    $ws->mergeCells('E10:E11');
    $ws->mergeCells('F10:G11');
    $ws->setCellValue('B10', 'S/N');
    $ws->setCellValue('C10', 'NAME OF CANDIDATE');
    $ws->setCellValue('D10', 'SEX');
    $ws->setCellValue('E10', 'EXAM NO.');
    $ws->setCellValue('F10', 'SIGNATURE');
    $ws->getStyle('B10:G11')->applyFromArray($head);

    $r = 12;
    foreach ($candidates as $c) {
        $ws->setCellValue('B' . $r, $r - 11);
        $ws->setCellValue('C' . $r, strtoupper(full_name($c)));
        $ws->setCellValue('D' . $r, strtoupper((string)$c['sex']));
        $ws->setCellValueExplicit('E' . $r, (string)$c['cand_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $r++;
    }
    $dataEnd = $r - 1;
    if ($dataEnd >= 12) {
        $ws->getStyle('B12:G' . $dataEnd)->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['vertical' => 'bottom'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ]);
        for ($rr = 12; $rr <= $dataEnd; $rr++) {
            foreach (['B', 'D', 'E', 'F', 'G'] as $col) $ws->getStyle($col . $rr)->getAlignment()->setHorizontal('center');
        }
    }

    $f = max(12, $dataEnd) + 2;
    $ws->setCellValue('B' . $f, "Examination Invigilator's Signature");
    $ws->setCellValue('B' . ($f + 1), 'Name: _____________________________');
    $ws->setCellValue('B' . ($f + 2), 'Phone:_____________________');
    $ws->setCellValue('B' . ($f + 3), 'Signature: ____________________');
    $ws->getStyle('B' . $f . ':B' . ($f + 3))->getFont()->setName('Arial')->setSize(11)->setBold(true);

    exam_doc_page_setup($ws, true);
    return $wb;
}

/* ------------------------------------------------------------------ */
/* Registered Candidates Statistics (madarasa ya shule)                */
/* ------------------------------------------------------------------ */
function xlsx_registered_stats(array $exam, array $sc, array $subjects, array $rows, $singleClass = '') {
    if (!load_vendor()) throw new Exception('Nyaraka za .xlsx zinahitaji PhpSpreadsheet. Endesha "composer install".');
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Registered Stats');

    $n = max(1, count($subjects));
    $firstSubCol = 5;                 // E
    $lastSubCol = $firstSubCol + $n - 1;
    $totalCol = $lastSubCol + 1;
    $last = exam_doc_col($totalCol);

    // Ongeza jina la darasa mbele ya jina la mtihani pale mtihani wenyewe hauna kidato.
    $prefix = '';
    if ($singleClass !== '' && !preg_match('/\bFORM\s+(I|II|III|IV|V|VI|ONE|TWO|THREE|FOUR|FIVE|SIX)\b/i', (string)($exam['name'] ?? ''))) {
        $prefix = strtoupper($singleClass) . ' ';
    }
    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        exam_doc_region($sc),
        exam_doc_district($sc),
        $prefix . exam_doc_exam_title($exam) . ', ' . exam_doc_month_year($exam),
        'CANDIDATES REGISTERED FOR EXAM STATISTICS',
    ];
    foreach ($titles as $i => $t) {
        $r = $i + 1;
        $ws->mergeCells('A' . $r . ':' . $last . $r);
        $ws->setCellValue('A' . $r, $t);
    }
    $ws->mergeCells('A7:' . $last . '7');
    $ws->mergeCells('A8:' . $last . '8');
    $reg = strtoupper(trim((string)($sc['region'] ?? '')));
    $dist = strtoupper(trim((string)($sc['district'] ?? '')));
    $ws->setCellValue('A8', ($reg !== '' ? $reg : 'REGION') . ', ' . ($dist !== '' ? $dist : 'DISTRICT'));

    $ws->getStyle('A1:' . $last . '6')->applyFromArray([
        'font' => ['name' => 'Copperplate Gothic Bold', 'size' => 15, 'bold' => true, 'color' => ['rgb' => '003366']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);
    $ws->getStyle('A8:' . $last . '8')->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);

    $ws->getColumnDimension('A')->setWidth(4.5);
    $ws->getColumnDimension('B')->setWidth(24);
    $ws->getColumnDimension('C')->setWidth(10);
    $ws->getColumnDimension('D')->setWidth(7);
    for ($i = 0; $i < $n; $i++) $ws->getColumnDimension(exam_doc_col($firstSubCol + $i))->setWidth(4.8);
    $ws->getColumnDimension($last)->setWidth(10);

    $head = [
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ];
    $ws->mergeCells('A9:A10');
    $ws->mergeCells('B9:B10');
    $ws->mergeCells('C9:C10');
    $ws->mergeCells('D9:D10');
    if ($lastSubCol > $firstSubCol) $ws->mergeCells('E9:' . exam_doc_col($lastSubCol) . '9');
    $ws->mergeCells($last . '9:' . $last . '10');
    $ws->setCellValue('A9', 'S/N');
    $ws->setCellValue('B9', 'CLASS NAME');
    $ws->setCellValue('C9', 'CENTRE NO');
    $ws->setCellValue('D9', 'REG. SUBJECTS');
    $ws->setCellValue('E9', "SUBJECT'S DETAILS");
    $ws->setCellValue($last . '9', 'TOTAL CANDIDATES');
    $idx = 0;
    foreach ($subjects as $s) {
        $col = exam_doc_col($firstSubCol + $idx);
        $ws->setCellValue($col . '10', strtoupper($s['name']));
        $ws->getStyle($col . '10')->getAlignment()->setTextRotation(90)->setVertical('center')->setHorizontal('center');
        $idx++;
    }
    $ws->getStyle('A9:' . $last . '10')->applyFromArray($head);
    $idx = 0;
    foreach ($subjects as $s) {
        $ws->getStyle(exam_doc_col($firstSubCol + $idx) . '10')->getAlignment()->setTextRotation(90)->setVertical('center')->setHorizontal('center');
        $idx++;
    }
    $ws->getRowDimension(10)->setRowHeight(100);

    $r = 11;
    $totals = array_fill_keys(array_map(function ($s) { return (int)$s['id']; }, $subjects), 0);
    $grand = 0;
    foreach ($rows as $row) {
        $ws->setCellValue('A' . $r, $r - 10);
        $ws->setCellValue('B' . $r, strtoupper($row['class_name']));
        $ws->setCellValue('C' . $r, school_centre($sc));
        $ws->setCellValue('D' . $r, (int)$row['subject_count']);
        $idx = 0;
        foreach ($subjects as $s) {
            $v = (int)($row['counts'][(int)$s['id']] ?? 0);
            $ws->setCellValue(exam_doc_col($firstSubCol + $idx) . $r, $v);
            $totals[(int)$s['id']] += $v;
            $idx++;
        }
        $ws->setCellValue($last . $r, (int)$row['total']);
        $grand += (int)$row['total'];
        $r++;
    }
    $dataEnd = $r - 1;
    if ($dataEnd >= 11) {
        $ws->getStyle('A11:' . $last . $dataEnd)->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ]);
        $ws->getStyle('B11:B' . $dataEnd)->getAlignment()->setHorizontal('left');
    }

    // Mstari wa jumla
    $tr = $dataEnd + 1;
    if ($lastSubCol > 1) $ws->mergeCells('A' . $tr . ':D' . $tr);
    $ws->setCellValue('A' . $tr, 'TOTAL');
    $idx = 0;
    foreach ($subjects as $s) {
        $ws->setCellValue(exam_doc_col($firstSubCol + $idx) . $tr, (int)$totals[(int)$s['id']]);
        $idx++;
    }
    $ws->setCellValue($last . $tr, $grand);
    $ws->getStyle('A' . $tr . ':' . $last . $tr)->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);

    exam_doc_page_setup($ws, true);
    return $wb;
}

/* ------------------------------------------------------------------ */
/* Sitting Plan (darasa moja)                                          */
/* ------------------------------------------------------------------ */
function xlsx_sitting_plan(array $exam, array $sc, array $class, array $subjects, array $candidates, $headName = '') {
    if (!load_vendor()) throw new Exception('Nyaraka za .xlsx zinahitaji PhpSpreadsheet. Endesha "composer install".');
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle(exam_doc_sheet_title('Sitting ' . $class['name']));

    $last = 'H';
    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        exam_doc_region($sc),
        exam_doc_district($sc),
        exam_doc_exam_title($exam) . ', ' . exam_doc_month_year($exam),
    ];
    foreach ($titles as $i => $t) {
        $r = $i + 1;
        $ws->mergeCells('A' . $r . ':' . $last . $r);
        $ws->setCellValue('A' . $r, $t);
    }
    $ws->mergeCells('A6:' . $last . '6');
    $ws->setCellValue('A6', exam_doc_centre_line($sc));
    $ws->mergeCells('A7:' . $last . '7');
    $ws->setCellValue('A7', 'SITTING PLAN');
    $ws->mergeCells('A8:' . $last . '8');
    $ws->setCellValue('A8', 'CLASS: ' . strtoupper($class['name']) . '            CENTER NO: ' . school_centre($sc) . '            CENTER NAME: ' . exam_doc_full_school($sc));

    $ws->getStyle('A1:' . $last . '5')->applyFromArray([
        'font' => ['name' => 'Copperplate Gothic Bold', 'size' => 15, 'bold' => true, 'color' => ['rgb' => '003366']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);
    $ws->getStyle('A6:A7')->applyFromArray([
        'font' => ['name' => 'Times New Roman', 'size' => 14, 'bold' => true],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
    ]);
    $ws->getStyle('A8')->applyFromArray([
        'font' => ['name' => 'Times New Roman', 'size' => 11, 'bold' => true],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
    ]);

    $ws->getColumnDimension('A')->setWidth(5);
    $ws->getColumnDimension('B')->setWidth(32);
    $ws->getColumnDimension('C')->setWidth(6);
    $ws->getColumnDimension('D')->setWidth(15);
    $ws->getColumnDimension('E')->setWidth(43);
    $ws->getColumnDimension('F')->setWidth(10);
    $ws->getColumnDimension('G')->setWidth(9);
    $ws->getColumnDimension('H')->setWidth(14);

    $head = [
        'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ];
    $ws->setCellValue('A10', 'S/N');
    $ws->setCellValue('B10', 'NAME OF CANDIDATE');
    $ws->setCellValue('C10', 'SEX');
    $ws->setCellValue('D10', 'EXAM NO.');
    $ws->setCellValue('E10', 'SUBJECTS REGISTERED');
    $ws->setCellValue('F10', 'ROOM');
    $ws->setCellValue('G10', 'SEAT NO.');
    $ws->setCellValue('H10', 'SIGNATURE');
    $ws->getStyle('A10:H10')->applyFromArray($head);
    $ws->getRowDimension(10)->setRowHeight(30);

    $subjectNames = [];
    foreach ($subjects as $s) $subjectNames[] = strtoupper($s['abbr'] ?: $s['name']);
    $subjectsText = implode(', ', $subjectNames);

    $r = 11;
    foreach ($candidates as $c) {
        $ws->setCellValue('A' . $r, $r - 10);
        $ws->setCellValue('B' . $r, strtoupper(full_name($c)));
        $ws->setCellValue('C' . $r, strtoupper((string)$c['sex']));
        $ws->setCellValueExplicit('D' . $r, (string)$c['cand_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('E' . $r, $subjectsText);
        $r++;
    }
    $dataEnd = $r - 1;
    if ($dataEnd >= 11) {
        $ws->getStyle('A11:H' . $dataEnd)->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ]);
        for ($rr = 11; $rr <= $dataEnd; $rr++) {
            foreach (['A', 'C', 'D', 'F', 'G', 'H'] as $col) $ws->getStyle($col . $rr)->getAlignment()->setHorizontal('center');
        }
    }

    $f = max(11, $dataEnd) + 3;
    $ws->setCellValue('A' . $f, "Head of School's name & Signature");
    $ws->setCellValue('E' . $f, "Invigilator's Name: _________________________");
    $ws->setCellValue('A' . ($f + 1), $headName !== '' ? strtoupper($headName) : '_________________________');
    $ws->setCellValue('E' . ($f + 1), 'Signature: ____________________');
    $ws->setCellValue('A' . ($f + 2), 'Official Stamp: ____________________');
    $ws->setCellValue('E' . ($f + 2), 'Date: ' . exam_doc_date($exam));
    $ws->setCellValue('A' . ($f + 3), 'Date: ' . exam_doc_date($exam));
    $ws->getStyle('A' . $f . ':H' . ($f + 3))->getFont()->setName('Calibri')->setSize(11);

    exam_doc_page_setup($ws, true);
    return $wb;
}

/* ------------------------------------------------------------------ */
/* Registered Candidates Statistics - shule zote za wilaya (combined)  */
/* ------------------------------------------------------------------ */
function xlsx_registered_stats_schools(array $exam, array $schools, array $subjects, array $rows) {
    if (!load_vendor()) throw new Exception('Nyaraka za .xlsx zinahitaji PhpSpreadsheet. Endesha "composer install".');
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Registered Stats');

    $ref = $schools ? $schools[0] : [];
    $reg = strtoupper(trim((string)($ref['region'] ?? '')));
    $dist = strtoupper(trim((string)($ref['district'] ?? '')));

    $n = max(1, count($subjects));
    $firstSubCol = 5;                 // E
    $lastSubCol = $firstSubCol + $n - 1;
    $totalCol = $lastSubCol + 1;
    $last = exam_doc_col($totalCol);

    $titles = [
        "PRIME MINISTER'S OFFICE",
        'REGIONAL ADMINISTRATION AND LOCAL GOVERNMENT',
        $reg !== '' ? $reg . ' REGION' : 'REGION',
        $dist !== '' ? $dist . ' DISTRICT COUNCIL' : 'DISTRICT COUNCIL',
        exam_doc_exam_title($exam) . ', ' . exam_doc_month_year($exam),
        'CANDIDATES REGISTERED FOR EXAM STATISTICS',
    ];
    foreach ($titles as $i => $t) {
        $r = $i + 1;
        $ws->mergeCells('A' . $r . ':' . $last . $r);
        $ws->setCellValue('A' . $r, $t);
    }
    $ws->mergeCells('A7:' . $last . '7');
    $ws->mergeCells('A8:' . $last . '8');
    $ws->setCellValue('A8', ($reg !== '' ? $reg : 'REGION') . ', ' . ($dist !== '' ? $dist : 'DISTRICT'));

    $ws->getStyle('A1:' . $last . '6')->applyFromArray([
        'font' => ['name' => 'Copperplate Gothic Bold', 'size' => 15, 'bold' => true, 'color' => ['rgb' => '003366']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);
    $ws->getStyle('A8:' . $last . '8')->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);

    $ws->getColumnDimension('A')->setWidth(4.5);
    $ws->getColumnDimension('B')->setWidth(32);
    $ws->getColumnDimension('C')->setWidth(10);
    $ws->getColumnDimension('D')->setWidth(7);
    for ($i = 0; $i < $n; $i++) $ws->getColumnDimension(exam_doc_col($firstSubCol + $i))->setWidth(4.8);
    $ws->getColumnDimension($last)->setWidth(10);

    $head = [
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C0C0C0']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ];
    $ws->mergeCells('A9:A10');
    $ws->mergeCells('B9:B10');
    $ws->mergeCells('C9:C10');
    $ws->mergeCells('D9:D10');
    if ($lastSubCol > $firstSubCol) $ws->mergeCells('E9:' . exam_doc_col($lastSubCol) . '9');
    $ws->mergeCells($last . '9:' . $last . '10');
    $ws->setCellValue('A9', 'S/N');
    $ws->setCellValue('B9', 'SCHOOL NAME');
    $ws->setCellValue('C9', 'CENTRE NO');
    $ws->setCellValue('D9', 'REG. SUBJECTS');
    $ws->setCellValue('E9', "SUBJECT'S DETAILS");
    $ws->setCellValue($last . '9', 'TOTAL CANDIDATES');
    $idx = 0;
    foreach ($subjects as $s) {
        $col = exam_doc_col($firstSubCol + $idx);
        $ws->setCellValue($col . '10', strtoupper($s['name']));
        $ws->getStyle($col . '10')->getAlignment()->setTextRotation(90)->setVertical('center')->setHorizontal('center');
        $idx++;
    }
    $ws->getStyle('A9:' . $last . '10')->applyFromArray($head);
    $idx = 0;
    foreach ($subjects as $s) {
        $ws->getStyle(exam_doc_col($firstSubCol + $idx) . '10')->getAlignment()->setTextRotation(90)->setVertical('center')->setHorizontal('center');
        $idx++;
    }
    $ws->getRowDimension(10)->setRowHeight(100);

    $r = 11;
    $totals = array_fill_keys(array_map(function ($s) { return (int)$s['id']; }, $subjects), 0);
    $grand = 0;
    foreach ($rows as $row) {
        $ws->setCellValue('A' . $r, $r - 10);
        $ws->setCellValue('B' . $r, strtoupper($row['school_name']));
        $ws->setCellValue('C' . $r, $row['centre']);
        $ws->setCellValue('D' . $r, (int)$row['subject_count']);
        $idx = 0;
        foreach ($subjects as $s) {
            $v = (int)($row['counts'][(int)$s['id']] ?? 0);
            $ws->setCellValue(exam_doc_col($firstSubCol + $idx) . $r, $v);
            $totals[(int)$s['id']] += $v;
            $idx++;
        }
        $ws->setCellValue($last . $r, (int)$row['total']);
        $grand += (int)$row['total'];
        $r++;
    }
    $dataEnd = $r - 1;
    if ($dataEnd >= 11) {
        $ws->getStyle('A11:' . $last . $dataEnd)->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        ]);
        $ws->getStyle('B11:B' . $dataEnd)->getAlignment()->setHorizontal('left');
    }

    $tr = $dataEnd + 1;
    $ws->mergeCells('A' . $tr . ':D' . $tr);
    $ws->setCellValue('A' . $tr, 'TOTAL');
    $idx = 0;
    foreach ($subjects as $s) {
        $ws->setCellValue(exam_doc_col($firstSubCol + $idx) . $tr, (int)$totals[(int)$s['id']]);
        $idx++;
    }
    $ws->setCellValue($last . $tr, $grand);
    $ws->getStyle('A' . $tr . ':' . $last . $tr)->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
    ]);

    exam_doc_page_setup($ws, true);
    return $wb;
}
