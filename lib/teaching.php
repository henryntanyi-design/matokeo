<?php
/**
 * Moduli ya Kufundisha: ratiba ya kufundisha, scheme of work,
 * logbook ya somo na mitala (syllabus) ya masomo.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/export.php';

/* ---------------- Misingi ---------------- */

function teaching_terms() {
    return ['I' => 'Term I', 'II' => 'Term II'];
}

function teaching_term($v) {
    $t = strtoupper(trim((string)$v));
    return in_array($t, ['I', 'II'], true) ? $t : 'I';
}

/** Majina ya siku kwa ratiba (muundo wa kawaida wa shule). */
function teaching_day_labels() {
    return ['MON' => 'MONDAY', 'TUE' => 'TUESDAY', 'WED' => 'WEDNESDAY', 'THU' => 'THURSDAY', 'FRI' => 'FRIDAY', 'SAT' => 'SATURDAY'];
}

/** Badilisha "HH:MM" kuwa dakika tangu saa 12 usiku; null kama si sahihi. */
function teaching_time_minutes($v) {
    $v = trim((string)$v);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return null;
    return $h * 60 + $i;
}

function teaching_minutes_time($m) {
    $m = ((int)$m) % 1440;
    if ($m < 0) $m += 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/**
 * Tengeneza saa za vipindi pamoja na mapumziko.
 * Vipindi huanza `start` na kudumu `duration` dakika; mapumziko hurukwa.
 *
 * @return array{items: array, start_text: string, end_text: string, duration: int, sessions: int}
 */
function teaching_timetable_timeslots($sessions, $duration, $start, array $breaks = []) {
    $sessions = max(1, min(14, (int)$sessions));
    $duration = max(15, min(120, (int)$duration));
    $startMin = teaching_time_minutes($start);
    if ($startMin === null) $startMin = 8 * 60;

    $clean = [];
    foreach ($breaks as $b) {
        $name = trim((string)($b['name'] ?? 'BREAK'));
        $s = teaching_time_minutes($b['start'] ?? '');
        $e = teaching_time_minutes($b['end'] ?? '');
        if ($s === null || $e === null || $e <= $s) continue;
        $k = $s . '-' . $e;
        if (isset($clean[$k])) continue;
        $clean[$k] = ['name' => $name !== '' ? $name : 'BREAK', 'start' => $s, 'end' => $e];
    }
    $list = array_values($clean);
    usort($list, function ($a, $b) { return $a['start'] <=> $b['start']; });

    $items = [];
    $cursor = $startMin;
    $firstStart = null;
    $lastEnd = $startMin;

    for ($i = 1; $i <= $sessions; $i++) {
        $guard = 0;
        while ($guard++ < 30) {
            $moved = false;
            foreach ($list as $b) {
                if ($cursor >= $b['start'] && $cursor < $b['end']) { $cursor = $b['end']; $moved = true; }
            }
            if ($moved) continue;
            $next = null;
            foreach ($list as $b) {
                if ($b['start'] >= $cursor && $b['start'] < $cursor + $duration) { $next = $b; break; }
            }
            if ($next) { $cursor = $next['end']; continue; }
            break;
        }
        $s = $cursor; $e = $cursor + $duration;
        if ($firstStart === null) $firstStart = $s;
        $items[] = ['type' => 'session', 'no' => $i, 'start' => $s, 'end' => $e,
                    'label' => str_pad((string)$i, 2, '0', STR_PAD_LEFT)];
        $cursor = $e;
        $lastEnd = $e;
    }

    foreach ($list as $b) {
        if ($firstStart !== null && $b['start'] >= $firstStart && $b['start'] < $lastEnd) {
            $items[] = ['type' => 'break', 'name' => $b['name'], 'start' => $b['start'], 'end' => $b['end'], 'label' => 'B'];
        }
    }
    usort($items, function ($a, $b) {
        if ($a['start'] === $b['start']) {
            $ta = $a['type'] === 'break' ? 0 : 1;
            $tb = $b['type'] === 'break' ? 0 : 1;
            if ($ta !== $tb) return $ta <=> $tb;
            return ($a['no'] ?? 0) <=> ($b['no'] ?? 0);
        }
        return $a['start'] <=> $b['start'];
    });
    foreach ($items as &$it) {
        $it['start_text'] = teaching_minutes_time($it['start']);
        $it['end_text'] = teaching_minutes_time($it['end']);
    }
    unset($it);

    return [
        'items' => $items,
        'start_text' => teaching_minutes_time($firstStart === null ? $startMin : $firstStart),
        'end_text' => teaching_minutes_time($lastEnd),
        'duration' => $duration,
        'sessions' => $sessions,
    ];
}

function teaching_years() {
    return q_all('SELECT * FROM academic_years ORDER BY name DESC, id DESC');
}

/** Mwaka uliochaguliwa; kama haupo tumia mwaka wa sasa. */
function teaching_year($id = 0) {
    $id = (int)$id;
    if ($id) {
        $y = q_one('SELECT * FROM academic_years WHERE id = ?', [$id]);
        if ($y) return $y;
    }
    return current_year();
}

/** Je, jedwali za moduli hii zimeshasakinishwa? */
function teaching_tables_ready() {
    static $ok = null;
    if ($ok === null) {
        try {
            q_val('SELECT 1 FROM syllabus_topics LIMIT 1');
            q_val('SELECT 1 FROM subject_logs LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Mwalimu aliyeingia (kama ni teacher); 0 kwa admin/super. */
function teaching_active_teacher_id() {
    $u = user();
    return ($u && $u['role'] === 'teacher') ? (int)$u['id'] : 0;
}

/** Madarasa anayoweza kuona mtumiaji: teacher = aliyopangiwa; admin = yote. */
function teaching_classes_for($school_id, $teacher_id = 0) {
    if ($teacher_id) {
        return q_all('SELECT DISTINCT c.* FROM teacher_assignments ta JOIN classes c ON c.id = ta.class_id
                      WHERE ta.teacher_id = ? ORDER BY c.sort_order, c.id', [(int)$teacher_id]);
    }
    return q_all('SELECT * FROM classes ORDER BY sort_order, id');
}

/** Masomo ya darasa; teacher = yale aliyopangiwa tu. */
function teaching_subjects_for($class_id, $teacher_id = 0) {
    $sql = 'SELECT s.* FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.class_id = ?';
    $p = [(int)$class_id];
    if ($teacher_id) {
        $sql .= ' AND EXISTS (SELECT 1 FROM teacher_assignments ta
                              WHERE ta.teacher_id = ? AND ta.class_id = cs.class_id AND ta.subject_id = cs.subject_id)';
        $p[] = (int)$teacher_id;
    }
    return q_all($sql . ' ORDER BY s.name', $p);
}

/** Je, safu ya `stream` kwenye teacher_assignments imesakinishwa? */
function teaching_streams_ready() {
    static $ok = null;
    if ($ok === null) {
        try {
            q_val('SELECT stream FROM teacher_assignments LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

function teaching_valid_pair($class_id, $subject_id) {
    return (bool)q_val('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?', [(int)$class_id, (int)$subject_id]);
}

/** Mwalimu wa darasa/somo; stream maalum kwanza, kisha ule wa darasa zima (''). */
function teaching_teacher_for($class_id, $subject_id, $stream = '') {
    $stream = trim((string)$stream);
    if (teaching_streams_ready()) {
        $row = q_one('SELECT u.id, u.full_name FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                      WHERE ta.class_id = ? AND ta.subject_id = ? AND ta.stream = ? AND u.active = 1
                      ORDER BY u.full_name LIMIT 1', [(int)$class_id, (int)$subject_id, $stream]);
        if ($row) return $row;
        if ($stream !== '') {
            $row = q_one('SELECT u.id, u.full_name FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                          WHERE ta.class_id = ? AND ta.subject_id = ? AND ta.stream = \'\' AND u.active = 1
                          ORDER BY u.full_name LIMIT 1', [(int)$class_id, (int)$subject_id]);
            if ($row) return $row;
        }
        return null;
    }
    return q_one('SELECT u.id, u.full_name FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                  WHERE ta.class_id = ? AND ta.subject_id = ? AND u.active = 1
                  ORDER BY u.full_name LIMIT 1', [(int)$class_id, (int)$subject_id]);
}

/**
 * Ramani ya ugawaji wote wa shule.
 * Funguo: "class_id:subject_id:stream" (stream maalum) na "class_id:subject_id" (darasa zima).
 */
function teaching_assignments_map($school_id) {
    $hasStream = teaching_streams_ready();
    $rows = q_all('SELECT ta.class_id, ta.subject_id, ' . ($hasStream ? 'ta.stream' : "'' AS stream") . ',
                          u.id AS teacher_id, u.full_name
                   FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                   WHERE u.school_id = ? AND u.active = 1 ORDER BY u.full_name', [(int)$school_id]);
    $map = [];
    foreach ($rows as $r) {
        $cid = (int)$r['class_id'];
        $sid = (int)$r['subject_id'];
        $stream = trim((string)($r['stream'] ?? ''));
        $k = $cid . ':' . $sid . ':' . $stream;
        if (!isset($map[$k])) $map[$k] = $r;
        if ($stream === '') {
            $plain = $cid . ':' . $sid;
            if (!isset($map[$plain])) $map[$plain] = $r;
        }
    }
    return $map;
}

/** Admin anaweza yote; mwalimu anaruhusiwa somo alilopangiwa tu. */
function teaching_can_manage($class_id, $subject_id) {
    $u = user();
    if (!$u) return false;
    if (is_admin()) return true;
    if ($u['role'] === 'teacher') {
        return (bool)q_val('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                           [(int)$u['id'], (int)$class_id, (int)$subject_id]);
    }
    return false;
}

function teaching_short_name($full) {
    $p = preg_split('/\s+/', trim((string)$full));
    return $p ? (string)end($p) : '';
}

/**
 * Orodha ya streams zilizopo (kutoka wanafunzi na ugawaji) kwa mapendekezo.
 * Hurudisha orodha ya majina yaliyotenganishwa bila marudio.
 */
function teaching_stream_options($school_id) {
    $out = [];
    try {
        foreach (q_all("SELECT DISTINCT stream FROM students WHERE school_id = ? AND stream IS NOT NULL AND stream <> ''", [(int)$school_id]) as $r) {
            $out[] = trim((string)$r['stream']);
        }
    } catch (Exception $e) { /* walau wanafunzi hawapo */ }
    if (teaching_streams_ready()) {
        try {
            foreach (q_all("SELECT DISTINCT stream FROM teacher_assignments WHERE stream <> ''") as $r) {
                $out[] = trim((string)$r['stream']);
            }
        } catch (Exception $e) { /* safu haijasakinishwa */ }
    }
    $out = array_values(array_unique(array_filter($out, function ($s) { return $s !== ''; })));
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/* ---------------- UI ---------------- */

function teaching_nav($active) {
    $items = [
        'index'    => ['Home', 'house-door', 'teaching/index.php'],
        'timetable'=> ['Timetable', 'calendar3-week', 'teaching/timetable.php'],
        'scheme'   => ['Scheme of Work', 'journal-text', 'teaching/scheme.php'],
        'logbook'  => ['Logbook', 'journal-check', 'teaching/logbook.php'],
        'syllabus' => ['Syllabus', 'book-half', 'teaching/syllabus.php'],
    ];
    echo '<div class="d-flex flex-wrap gap-2 mb-3 d-print-none">';
    foreach ($items as $k => $it) {
        echo '<a class="btn btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-outline-primary') . '" href="' . h(url($it[2])) . '">'
           . '<i class="bi bi-' . $it[1] . '"></i> ' . h($it[0]) . '</a>';
    }
    echo '</div>';
}

/** Kichwa cha shule kwa nyaraka za kuchapisha. */
function teaching_doc_header($title, $metaHtml = '') {
    $sc = active_school();
    $line = [];
    if ($sc) {
        if (!empty($sc['district'])) $line[] = strtoupper($sc['district']) . ' DISTRICT';
        if (!empty($sc['region'])) $line[] = strtoupper($sc['region']);
        if (!empty($sc['centre_no'])) $line[] = 'CENTRE: ' . strtoupper($sc['centre_no']);
    }
    echo '<div class="text-center mb-3">';
    echo '<div class="fw-bold text-uppercase" style="font-size:1.05rem">' . h($sc['name'] ?? system_name()) . '</div>';
    if ($line) echo '<div class="small text-muted">' . implode(' &middot; ', array_map('h', $line)) . '</div>';
    echo '<div class="fw-semibold mt-2">' . h($title) . '</div>';
    if ($metaHtml !== '') echo '<div class="small">' . $metaHtml . '</div>';
    echo '</div>';
}

function teaching_meta_html($class, $subject, $term, $year) {
    $bits = [];
    if ($class) $bits[] = 'CLASS: ' . strtoupper($class['name']);
    if ($subject) $bits[] = 'SUBJECT: ' . strtoupper($subject['name']);
    if ($term !== '' && $term !== null) $bits[] = 'TERM: ' . $term;
    if ($year) $bits[] = 'YEAR: ' . $year['name'];
    return implode(' &middot; ', array_map('h', $bits));
}

function teaching_meta_text($class, $subject, $term, $year) {
    $bits = [];
    if ($class) $bits[] = 'CLASS: ' . strtoupper($class['name']);
    if ($subject) $bits[] = 'SUBJECT: ' . strtoupper($subject['name']);
    if ($term !== '' && $term !== null) $bits[] = 'TERM: ' . $term;
    if ($year) $bits[] = 'YEAR: ' . $year['name'];
    return implode('   |   ', $bits);
}

/* ---------------- Ratiba ya kufundisha ---------------- */

/**
 * Tengeneza ratiba ya wiki kwa madarasa yote yaliyotolewa.
 * Vipindi vya kila somo hugawiwa kwa usawa; mgongano wa mwalimu huepukwa.
 *
 * @return array days, periods, grid[class_id][period][dayIndex] = cell|null, classes
 */
function teaching_timetable_build(array $classes, array $assignMap, $periods_per_day = 8, array $days = null) {
    if ($days === null) $days = ['MON', 'TUE', 'WED', 'THU', 'FRI'];
    $days = array_values(array_filter(array_map(function ($d) { return strtoupper(trim((string)$d)); }, $days)));
    if (!$days) $days = ['MON', 'TUE', 'WED', 'THU', 'FRI'];
    $periods_per_day = max(1, min(14, (int)$periods_per_day));
    $slots = count($days) * $periods_per_day;

    $data = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $subs = q_all('SELECT s.id, s.name, s.abbr FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id
                       WHERE cs.class_id = ? ORDER BY s.name', [$cid]);
        if (!$subs) continue;

        $items = [];
        foreach ($subs as $s) {
            $a = $assignMap[$cid . ':' . (int)$s['id']] ?? null;
            $items[] = [
                'sid'     => (int)$s['id'],
                'name'    => (string)$s['name'],
                'abbr'    => trim((string)$s['abbr']) !== '' ? (string)$s['abbr'] : (string)$s['name'],
                'tid'     => $a ? (int)$a['teacher_id'] : 0,
                'teacher' => $a ? (string)$a['full_name'] : '',
                'left'    => 0,
            ];
        }
        $n = count($items);
        $base = max(1, (int)floor($slots / max(1, $n)));
        $base = min($base, 6);
        $rem = max(0, $slots - $base * $n);
        for ($i = 0; $i < $n; $i++) $items[$i]['left'] = $base + ($i < $rem ? 1 : 0);

        $total = 0; foreach ($items as $it) $total += $it['left'];
        for ($i = $n - 1; $i >= 0 && $total > $slots; $i--) {
            $cut = min($items[$i]['left'], $total - $slots);
            $items[$i]['left'] -= $cut;
            $total -= $cut;
        }
        $i = 0;
        while ($total < $slots && $n > 0) {
            $items[$i % $n]['left']++;
            $total++;
            $i++;
        }
        $data[$cid] = ['class' => $c, 'items' => $items];
    }

    if (!$data) {
        return ['days' => $days, 'periods' => $periods_per_day, 'grid' => [], 'classes' => []];
    }

    $grid = [];
    foreach ($data as $cid => $d) {
        for ($p = 1; $p <= $periods_per_day; $p++) {
            foreach ($days as $di => $day) $grid[$cid][$p][$di] = null;
        }
    }

    $busy = [];      // [period][day][teacher_id]
    $dayCount = [];  // [class][day][subject_id]
    $lastSlot = [];  // [class][subject_id] = slot index ya mwisho

    for ($p = 1; $p <= $periods_per_day; $p++) {
        foreach ($days as $di => $day) {
            $global = ($p - 1) * count($days) + $di;
            foreach ($data as $cid => &$d) {
                $best = -1; $bestToday = 0; $bestLeft = 0; $bestLast = 0;
                foreach ($d['items'] as $i => $it) {
                    if ($it['left'] <= 0) continue;
                    $tid = (int)$it['tid'];
                    if ($tid && !empty($busy[$p][$di][$tid])) continue;

                    $today = isset($dayCount[$cid][$di][$it['sid']]) ? (int)$dayCount[$cid][$di][$it['sid']] : 0;
                    $last  = isset($lastSlot[$cid][$it['sid']]) ? (int)$lastSlot[$cid][$it['sid']] : -1;

                    $better = false;
                    if ($best < 0) $better = true;
                    elseif ($today < $bestToday) $better = true;
                    elseif ($today === $bestToday && $it['left'] > $bestLeft) $better = true;
                    elseif ($today === $bestToday && $it['left'] === $bestLeft && $last < $bestLast) $better = true;

                    if ($better) {
                        $best = $i; $bestToday = $today; $bestLeft = (int)$it['left']; $bestLast = $last;
                    }
                }
                if ($best < 0) continue;

                $it = $d['items'][$best];
                $grid[$cid][$p][$di] = [
                    'sid'        => $it['sid'],
                    'name'       => $it['name'],
                    'abbr'       => $it['abbr'],
                    'teacher_id' => $it['tid'],
                    'teacher'    => $it['teacher'],
                    'class_id'   => $cid,
                    'class_name' => $d['class']['name'],
                ];
                $d['items'][$best]['left']--;
                if ($it['tid']) $busy[$p][$di][$it['tid']] = true;
                $dayCount[$cid][$di][$it['sid']] = (isset($dayCount[$cid][$di][$it['sid']]) ? $dayCount[$cid][$di][$it['sid']] : 0) + 1;
                $lastSlot[$cid][$it['sid']] = $global;
            }
            unset($d);
        }
    }

    return ['days' => $days, 'periods' => $periods_per_day, 'grid' => $grid, 'classes' => $data];
}

/**
 * Panga ratiba katika muundo wa MSSIS: kwa kila siku kuna safu ya darasa,
 * safu za vipindi zenye saa, na safu za BREAK zinazochukua urefu wote.
 *
 * @param array $tt    Matokeo ya teaching_timetable_build()
 * @param array $slots Matokeo ya teaching_timetable_timeslots()
 * @param array $classes Madarasa yatakayoonyeshwa
 * @param array $opts  ['subject_id' => int, 'teacher_id' => int]
 */
function teaching_timetable_matrix(array $tt, array $slots, array $classes, array $opts = []) {
    $subjectId = (int)($opts['subject_id'] ?? 0);
    $teacherId = (int)($opts['teacher_id'] ?? 0);
    $labels = teaching_day_labels();
    $cols = $slots['items'] ?? [];
    $filtered = $subjectId > 0 || $teacherId > 0;

    // Chagua madarasa yenye kipindi kinacholingana na kichujio.
    $keep = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        if (empty($tt['grid'][$cid])) continue;
        if (!$filtered) { $keep[$cid] = true; continue; }
        $match = false;
        foreach ($tt['grid'][$cid] as $period) {
            foreach ($period as $cell) {
                if (!$cell) continue;
                if ($subjectId && (int)$cell['sid'] !== $subjectId) continue;
                if ($teacherId && (int)$cell['teacher_id'] !== $teacherId) continue;
                $match = true;
                break 2;
            }
        }
        if ($match) $keep[$cid] = true;
    }

    $rows = [];
    $totalRows = 0;
    $stats = [];
    foreach ($tt['days'] as $di => $day) {
        $dayRows = [];
        foreach ($classes as $c) {
            $cid = (int)$c['id'];
            if (empty($keep[$cid])) continue;
            $cells = [];
            foreach ($cols as $ci => $col) {
                if (($col['type'] ?? '') !== 'session') { $cells[$ci] = null; continue; }
                $cell = $tt['grid'][$cid][(int)$col['no']][$di] ?? null;
                if ($cell) {
                    if ($subjectId && (int)$cell['sid'] !== $subjectId) $cell = null;
                    elseif ($teacherId && (int)$cell['teacher_id'] !== $teacherId) $cell = null;
                }
                if ($cell) {
                    $cell['teacher_short'] = (string)($cell['teacher'] ?? '') !== '' ? teaching_short_name($cell['teacher']) : '';
                    $name = (string)$cell['name'];
                    $stats[$name] = ($stats[$name] ?? 0) + 1;
                }
                $cells[$ci] = $cell;
            }
            $dayRows[] = ['class' => $c, 'cells' => $cells];
            $totalRows++;
        }
        if ($dayRows) {
            $rows[] = [
                'day'      => $day,
                'label'    => $labels[strtoupper((string)$day)] ?? strtoupper((string)$day),
                'classes'  => $dayRows,
                'rowspan'  => count($dayRows),
            ];
        }
    }

    ksort($stats);
    return [
        'columns'    => $cols,
        'rows'       => $rows,
        'total_rows' => $totalRows,
        'stats'      => $stats,
        'filtered'   => $filtered,
    ];
}

/** Tuma ratiba ya mtindo wa MSSIS kama .xlsx yenye merge za siku na BREAK. */
function teaching_send_timetable_xlsx($filename, $title, $meta, array $matrix, $paper = 'A3') {
    if (!load_vendor()) { http_response_code(500); die('The .xlsx export requires PhpSpreadsheet. Run "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Timetable');

    $cols = $matrix['columns'];
    $nCols = 2 + count($cols);
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nCols);
    $headRow = 4;
    $subRow = 5;
    $bodyStart = 6;
    $bodyEnd = $bodyStart + max(0, (int)$matrix['total_rows']) - 1;

    $ws->setCellValue('A1', $title);
    $ws->mergeCells('A1:' . $last . '1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $ws->getStyle('A1')->getAlignment()->setHorizontal('center');
    $ws->setCellValue('A2', $meta);
    $ws->mergeCells('A2:' . $last . '2');
    $ws->getStyle('A2')->getAlignment()->setHorizontal('center')->setWrapText(true);
    $ws->getRowDimension(1)->setRowHeight(24);
    $ws->getRowDimension(2)->setRowHeight(30);

    $ws->setCellValue('A' . $headRow, 'DAY');
    $ws->setCellValue('B' . $headRow, 'FORM');
    $ws->mergeCells('A' . $headRow . ':A' . $subRow);
    $ws->mergeCells('B' . $headRow . ':B' . $subRow);
    $ws->getStyle('A' . $headRow . ':B' . $subRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 9],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);

    foreach ($cols as $i => $col) {
        $ci = 3 + $i;
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
        if (($col['type'] ?? '') === 'break') {
            $ws->setCellValue($letter . $headRow, 'B');
            $ws->mergeCells($letter . $headRow . ':' . $letter . $subRow);
            $ws->getStyle($letter . $headRow . ':' . $letter . $subRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E9ECEF']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
            $ws->getColumnDimension($letter)->setWidth(7);
        } else {
            $ws->setCellValue($letter . $headRow, (string)$col['label']);
            $ws->setCellValue($letter . $subRow, $col['start_text'] . '-' . $col['end_text']);
            $ws->getStyle($letter . $headRow . ':' . $letter . $subRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 8],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
            $ws->getColumnDimension($letter)->setWidth(16);
        }
    }
    $ws->getColumnDimension('A')->setWidth(16);
    $ws->getColumnDimension('B')->setWidth(20);
    $ws->getRowDimension($headRow)->setRowHeight(20);
    $ws->getRowDimension($subRow)->setRowHeight(20);

    $r = $bodyStart;
    foreach ($matrix['rows'] as $dayRow) {
        $span = max(1, (int)$dayRow['rowspan']);
        $ws->setCellValue('A' . $r, $dayRow['label']);
        if ($span > 1) $ws->mergeCells('A' . $r . ':A' . ($r + $span - 1));
        $ws->getStyle('A' . $r . ':A' . ($r + $span - 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F1F3F5']],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        ]);

        foreach ($dayRow['classes'] as $classRow) {
            if ($r > $bodyEnd) break;
            $ws->setCellValue('B' . $r, (string)$classRow['class']['name']);
            $ws->getStyle('B' . $r)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ]);
            $ws->getRowDimension($r)->setRowHeight(46);

            foreach ($cols as $i => $col) {
                if (($col['type'] ?? '') !== 'session') continue;
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i);
                $cell = $classRow['cells'][$i] ?? null;
                $val = '';
                if ($cell) {
                    $val = strtoupper((string)$cell['name']);
                    if (!empty($cell['teacher_short'])) $val .= "\n(" . strtoupper($cell['teacher_short']) . ')';
                }
                $ws->setCellValue($letter . $r, $val);
                $ws->getStyle($letter . $r)->applyFromArray([
                    'font' => ['size' => 8],
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);
            }
            $r++;
        }
    }

    // Safu za BREAK zinachukua urefu wote wa jedwali.
    if ($bodyEnd >= $bodyStart) {
        foreach ($cols as $i => $col) {
            if (($col['type'] ?? '') !== 'break') continue;
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i);
            $ws->setCellValue($letter . $bodyStart, strtoupper((string)($col['name'] ?? 'BREAK')) . "\n" . $col['start_text'] . "\n" . $col['end_text']);
            if ($bodyEnd > $bodyStart) $ws->mergeCells($letter . $bodyStart . ':' . $letter . $bodyEnd);
            $ws->getStyle($letter . $bodyStart . ':' . $letter . $bodyEnd)->applyFromArray([
                'font' => ['bold' => true, 'size' => 8],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E9ECEF']],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true, 'textRotation' => 90],
            ]);
        }
    }

    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ws->getPageSetup()->setPaperSize($paper === 'A4' ? \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4 : \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3);
    $ws->getPageSetup()->setFitToWidth(1);
    $ws->getPageSetup()->setFitToHeight(0);
    $ws->getPageSetup()->setHorizontalCentered(true);
    $ws->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3);
    $ws->getPageSetup()->setPrintArea('A1:' . $last . max($bodyStart, $bodyEnd));
    send_xlsx($wb, $filename);
}

/* ---------------- Export ya .xlsx ---------------- */

/** Tuma jedwali la data kama .xlsx yenye kichwa, meta na vichwa vya safu. */
function teaching_send_table_xlsx($filename, $title, $meta, array $headers, array $rows, $sheetTitle = 'Sheet1', array $widths = []) {
    if (!load_vendor()) { http_response_code(500); die('The .xlsx export requires PhpSpreadsheet. Run "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle(mb_substr((string)$sheetTitle, 0, 31));
    $n = max(1, count($headers));
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);

    $ws->setCellValue('A1', $title);
    $ws->mergeCells('A1:' . $last . '1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $ws->getStyle('A1')->getAlignment()->setHorizontal('center');
    if ($meta !== '') {
        $ws->setCellValue('A2', $meta);
        $ws->mergeCells('A2:' . $last . '2');
        $ws->getStyle('A2')->getAlignment()->setHorizontal('center');
    }
    $hr = 4;
    $ws->fromArray($headers, null, 'A' . $hr);
    $ws->getStyle('A' . $hr . ':' . $last . $hr)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
        'borders' => ['allBorders' => ['borderStyle' => 'thin']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);
    $r = $hr + 1;
    foreach ($rows as $row) {
        $vals = array_values($row);
        $isBreak = strtoupper(trim((string)($vals[0] ?? ''))) === 'BREAK'
            && count(array_filter($vals, function ($v) { return $v !== null && $v !== ''; })) === 1;
        if ($isBreak) {
            $ws->setCellValue('A' . $r, 'BREAK');
            $ws->mergeCells('A' . $r . ':' . $last . $r);
            $ws->getStyle('A' . $r . ':' . $last . $r)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E9ECEF']],
                'borders' => ['allBorders' => ['borderStyle' => 'thin']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
        } else {
            $ws->fromArray($vals, null, 'A' . $r);
            $ws->getStyle('A' . $r . ':' . $last . $r)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => 'thin']],
                'alignment' => ['vertical' => 'top', 'wrapText' => true],
            ]);
        }
        $r++;
    }
    for ($i = 1; $i <= $n; $i++) {
        $ws->getColumnDimensionByColumn($i)->setWidth($widths[$i - 1] ?? 18);
    }
    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ws->getPageSetup()->setFitToWidth(1);
    $ws->getPageSetup()->setFitToHeight(0);
    send_xlsx($wb, $filename);
}
