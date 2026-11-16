<?php
/** Injini ya matokeo: gredi, division (NECTA), watahiniwa, alama na takwimu. */
require_once __DIR__ . '/bootstrap.php';

/* ------------------------- Gredi ------------------------- */
function default_grading($lvl) {
    if ($lvl === 'A') {
        return [['grade' => 'A', 'min_score' => 80, 'points' => 1, 'remark' => 'Excellent'], ['grade' => 'B', 'min_score' => 70, 'points' => 2, 'remark' => 'Very Good'],
                ['grade' => 'C', 'min_score' => 60, 'points' => 3, 'remark' => 'Good'], ['grade' => 'D', 'min_score' => 50, 'points' => 4, 'remark' => 'Average'],
                ['grade' => 'E', 'min_score' => 40, 'points' => 5, 'remark' => 'Satisfactory'], ['grade' => 'S', 'min_score' => 35, 'points' => 6, 'remark' => 'Subsidiary'],
                ['grade' => 'F', 'min_score' => 0, 'points' => 7, 'remark' => 'Fail']];
    }
    return [['grade' => 'A', 'min_score' => 75, 'points' => 1, 'remark' => 'Excellent'], ['grade' => 'B', 'min_score' => 65, 'points' => 2, 'remark' => 'Very Good'],
            ['grade' => 'C', 'min_score' => 45, 'points' => 3, 'remark' => 'Good'], ['grade' => 'D', 'min_score' => 30, 'points' => 4, 'remark' => 'Satisfactory'],
            ['grade' => 'F', 'min_score' => 0, 'points' => 5, 'remark' => 'Fail']];
}
function get_grading($lvl) {
    static $cache = [];
    $lvl = ($lvl === 'A') ? 'A' : 'O';
    if (!isset($cache[$lvl])) {
        $rows = q_all('SELECT grade, min_score, points, remark FROM grading_scales WHERE level = ?', [$lvl]);
        if (!$rows) $rows = default_grading($lvl);
        foreach ($rows as $i => $r) {
            $rows[$i] = ['grade' => strtoupper($r['grade']), 'min_score' => (float)$r['min_score'], 'points' => (int)$r['points'], 'remark' => (string)$r['remark']];
        }
        usort($rows, function ($a, $b) { return $b['min_score'] <=> $a['min_score']; });
        $cache[$lvl] = $rows;
    }
    return $cache[$lvl];
}
function grade_for($score, array $grading) {
    foreach ($grading as $g) if ((float)$score >= $g['min_score']) return $g;
    return $grading[count($grading) - 1];
}
/** Division ya NECTA. O-Level: masomo 7 bora (7-35). A-Level: masomo 3 bora ya principal (3-21). */
function division($lvl, array $points) {
    sort($points);
    $need = ($lvl === 'A') ? 3 : 7;
    if (count($points) < $need) return ['INC', null];
    $p = array_sum(array_slice($points, 0, $need));
    if ($lvl === 'A') $d = $p <= 9 ? 'I' : ($p <= 12 ? 'II' : ($p <= 17 ? 'III' : ($p <= 19 ? 'IV' : '0')));
    else              $d = $p <= 17 ? 'I' : ($p <= 21 ? 'II' : ($p <= 25 ? 'III' : ($p <= 33 ? 'IV' : '0')));
    return [$d, $p];
}
function subject_abbr(array $s) {
    $a = trim($s['abbr'] ?? '');
    return strtoupper($a !== '' ? $a : substr(trim($s['name'] ?? ''), 0, 8));
}

/* ------------------------- Mitihani, shule na madarasa ------------------------- */
function get_exam($id) {
    return q_one('SELECT e.*, y.name AS year_name FROM exams e LEFT JOIN academic_years y ON y.id = e.academic_year_id WHERE e.id = ?', [(int)$id]);
}
/** Jina la mwezi wa mtihani (mf. MAY) kutoka tarehe ya kuanza, au tarehe ya kuundwa. */
function exam_month_name(array $exam) {
    $src = trim((string)($exam['start_date'] ?? ''));
    if ($src === '' || $src === '0000-00-00') $src = trim((string)($exam['created_at'] ?? ''));
    $t = $src !== '' ? strtotime($src) : false;
    return $t ? strtoupper(date('F', $t)) : '';
}
function exam_has_school($exam_id, $school_id) {
    return (bool)q_val('SELECT 1 FROM exam_schools WHERE exam_id = ? AND school_id = ?', [(int)$exam_id, (int)$school_id]);
}
function is_published($exam_id, $school_id) {
    return (bool)q_val('SELECT 1 FROM exam_schools WHERE exam_id = ? AND school_id = ? AND published = 1', [(int)$exam_id, (int)$school_id]);
}
function exam_school_list($exam_id) {
    return q_all('SELECT sc.*, es.published FROM exam_schools es JOIN schools sc ON sc.id = es.school_id WHERE es.exam_id = ? ORDER BY sc.name', [(int)$exam_id]);
}
/** Mitihani ambayo shule hii inashiriki (ya wilaya na ya shule yenyewe). */
function school_exams($school_id) {
    return q_all('SELECT e.*, y.name AS year_name, es.published FROM exam_schools es JOIN exams e ON e.id = es.exam_id LEFT JOIN academic_years y ON y.id = e.academic_year_id WHERE es.school_id = ? ORDER BY e.id DESC', [(int)$school_id]);
}
function exam_class_list($exam_id) {
    return q_all('SELECT c.* FROM exam_classes ec JOIN classes c ON c.id = ec.class_id WHERE ec.exam_id = ? ORDER BY c.sort_order, c.id', [(int)$exam_id]);
}
function class_subject_list($class_id) {
    return q_all('SELECT s.*, cs.is_compulsory FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.class_id = ? ORDER BY s.is_subsidiary, s.name', [(int)$class_id]);
}

/** Human label for a subject level: O = Ordinary, A = Advance, B = both. */
/**
 * Kokotoa Examination Centre GPA kwa njia ya NECTA:
 * Division GPA = wastani wa pointi za division (I=1..0=5)
 * Subjects GPA = wastani wa pointi za masomo (A=1..F=5)
 * Centre GPA = (Division GPA + Subjects GPA) / 2
 */
function centre_gpa(array $d) {
    $divPoints = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, '0' => 5];
    $divSum = 0; $divN = 0;
    $subjSum = 0; $subjN = 0;
    foreach (($d['candidates'] ?? []) as $c) {
        if (($c['state'] ?? '') === 'OK' && isset($divPoints[$c['division'] ?? ''])) {
            $divSum += $divPoints[$c['division']];
            $divN++;
        }
        foreach (($c['subjects'] ?? []) as $r) {
            if (isset($r['points']) && $r['points'] !== null) {
                $subjSum += (int)$r['points'];
                $subjN++;
            }
        }
    }
    $divGpa = $divN ? ($divSum / $divN) : null;
    $subjGpa = $subjN ? ($subjSum / $subjN) : null;
    if ($divGpa === null && $subjGpa === null) return null;
    if ($divGpa === null) return round($subjGpa, 4);
    if ($subjGpa === null) return round($divGpa, 4);
    return round(($divGpa + $subjGpa) / 2, 4);
}

function subject_level_label($level) {
    if ($level === 'O') return 'Ordinary';
    if ($level === 'A') return 'Advance';
    return 'Ordinary & Advance';
}

/** Is the `created_at` column on teacher_assignments installed? */
function assignment_dates_ready() {
    static $ok = null;
    if ($ok === null) {
        try { q_val('SELECT created_at FROM teacher_assignments LIMIT 1'); $ok = true; }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

/** SQL fragment for the date a teaching assignment was registered (falls back to the teacher account date). */
function assignment_date_sql($alias = 'ta', $userAlias = 'u') {
    if (!assignment_dates_ready()) return $userAlias . '.created_at';
    return "COALESCE(NULLIF($alias.created_at, '0000-00-00 00:00:00'), $userAlias.created_at)";
}

/**
 * Schools and teachers that registered a subject (teacher_assignments), with class, stream and date.
 * Newest first per school/class.
 */
function subject_school_assignments($subject_id) {
    $sid = (int)$subject_id;
    if (!$sid) return [];
    $date = assignment_date_sql('ta', 'u');
    $sql = 'SELECT ta.id, ta.class_id, ta.stream, ta.teacher_id,
                   u.full_name, u.username, u.email, u.phone, u.role, u.active, u.created_at AS user_created_at,
                   sc.id AS school_id, sc.name AS school_name, sc.centre_no,
                   c.name AS class_name, c.sort_order,
                   s.name AS subject_name, s.code AS subject_code,
                   ' . $date . ' AS assigned_at
            FROM teacher_assignments ta
            JOIN users u ON u.id = ta.teacher_id
            JOIN schools sc ON sc.id = u.school_id
            JOIN classes c ON c.id = ta.class_id
            JOIN subjects s ON s.id = ta.subject_id
            WHERE ta.subject_id = ?
            ORDER BY sc.name, c.sort_order, c.id, u.full_name';
    return q_all($sql, [$sid]);
}

/** All subjects registered to one teacher, with class, stream and date. */
function teacher_assignment_list($teacher_id) {
    $tid = (int)$teacher_id;
    if (!$tid) return [];
    $date = assignment_date_sql('ta', 'u');
    $sql = 'SELECT ta.id, ta.class_id, ta.subject_id, ta.stream,
                   c.name AS class_name, c.sort_order,
                   s.name AS subject_name, s.code AS subject_code, s.abbr AS subject_abbr,
                   ' . $date . ' AS assigned_at
            FROM teacher_assignments ta
            JOIN users u ON u.id = ta.teacher_id
            JOIN classes c ON c.id = ta.class_id
            JOIN subjects s ON s.id = ta.subject_id
            WHERE ta.teacher_id = ?
            ORDER BY c.sort_order, c.id, s.name';
    return q_all($sql, [$tid]);
}


/** Is the `exam_class_subjects` table installed? */
function exam_subjects_ready() {
    static $ok = null;
    if ($ok === null) {
        try { q_val('SELECT 1 FROM exam_class_subjects LIMIT 1'); $ok = true; }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

/** Subject ids chosen for an exam class, or null when that class has no saved choice (legacy exam). */
function exam_subject_ids($exam_id, $class_id) {
    $exam_id = (int)$exam_id; $class_id = (int)$class_id;
    if (!$exam_id || !$class_id || !exam_subjects_ready()) return null;
    static $cache = [];
    $k = $exam_id . ':' . $class_id;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $rows = q_all('SELECT subject_id FROM exam_class_subjects WHERE exam_id = ? AND class_id = ?', [$exam_id, $class_id]);
    $cache[$k] = $rows ? array_map('intval', array_column($rows, 'subject_id')) : null;
    return $cache[$k];
}

/**
 * Subjects a class sits for in one exam: the ones chosen on the exam form,
 * or all class subjects when the exam has no saved choice for that class.
 */
function exam_subject_list($exam_id, $class_id) {
    $all = class_subject_list($class_id);
    $ids = exam_subject_ids($exam_id, $class_id);
    if (!$ids) return $all;
    $out = [];
    foreach ($all as $s) if (in_array((int)$s['id'], $ids, true)) $out[] = $s;
    return $out;
}

/**
 * Save the subjects chosen for each exam class. $map = [class_id => [subject_id, ...]].
 * Subjects that already have scores cannot be removed.
 */
function exam_sync_subjects($exam_id, array $map) {
    $exam_id = (int)$exam_id;
    if (!$exam_id || !exam_subjects_ready()) return;
    $valid = [];
    foreach ($map as $cid => $subs) {
        $cid = (int)$cid;
        if (!$cid) continue;
        foreach ((array)$subs as $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            if (q_val('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?', [$cid, $sid])) $valid[$cid . ':' . $sid] = [$cid, $sid];
        }
    }
    $have = [];
    foreach (q_all('SELECT class_id, subject_id FROM exam_class_subjects WHERE exam_id = ?', [$exam_id]) as $r) {
        $cid = (int)$r['class_id']; $sid = (int)$r['subject_id']; $k = $cid . ':' . $sid;
        $have[$k] = true;
        if (isset($valid[$k])) continue;
        if ((int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND class_id = ? AND subject_id = ?', [$exam_id, $cid, $sid]) > 0) {
            $cn = (string)q_val('SELECT name FROM classes WHERE id = ?', [$cid]);
            $sn = (string)q_val('SELECT name FROM subjects WHERE id = ?', [$sid]);
            throw new Exception('Somo ' . $sn . ' (' . $cn . ') lina alama zilizohifadhiwa; haliwezi kuondolewa kwenye mtihani.');
        }
        q('DELETE FROM exam_class_subjects WHERE exam_id = ? AND class_id = ? AND subject_id = ?', [$exam_id, $cid, $sid]);
    }
    foreach ($valid as $k => $p) {
        if (isset($have[$k])) continue;
        q('INSERT IGNORE INTO exam_class_subjects (exam_id, class_id, subject_id) VALUES (?, ?, ?)', [$exam_id, $p[0], $p[1]]);
    }
}

/** Sajili wanafunzi wote hai wa shule hii (kwenye madarasa ya mtihani) kama watahiniwa, kisha toa namba. */
function register_candidates($exam_id, $school_id) {
    $exam_id = (int)$exam_id; $school_id = (int)$school_id; $added = 0;
    if (!exam_has_school($exam_id, $school_id)) throw new Exception('Shule hii haishiriki mtihani huu.');
    db()->beginTransaction();
    try {
        foreach (exam_class_list($exam_id) as $c) {
            $st = q("INSERT IGNORE INTO exam_candidates (exam_id, school_id, student_id, class_id)
                     SELECT ?, ?, s.id, s.class_id FROM students s WHERE s.school_id = ? AND s.class_id = ? AND s.status = 'active'", [$exam_id, $school_id, $school_id, $c['id']]);
            $added += $st->rowCount();
        }
        assign_cand_numbers($exam_id, $school_id);
        db()->commit();
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return $added;
}
function assign_cand_numbers($exam_id, $school_id) {
    $sc = school_by_id($school_id);
    if (!$sc) return;
    $prefix = school_centre($sc);
    $todo = q_all("SELECT ec.id FROM exam_candidates ec JOIN students s ON s.id = ec.student_id
                   WHERE ec.exam_id = ? AND ec.school_id = ? AND (ec.cand_no IS NULL OR ec.cand_no = '') ORDER BY ec.class_id, s.last_name, s.first_name", [(int)$exam_id, (int)$school_id]);
    $n = (int)q_val("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(cand_no, '/', -1) AS UNSIGNED)), 0) FROM exam_candidates WHERE exam_id = ? AND school_id = ? AND cand_no <> ''", [(int)$exam_id, (int)$school_id]);
    foreach ($todo as $r) { $n++; q('UPDATE exam_candidates SET cand_no = ? WHERE id = ?', [$prefix . '/' . str_pad((string)$n, 4, '0', STR_PAD_LEFT), $r['id']]); }
}

/**
 * IDs za wanafunzi wa darasa waliosajiliwa kwenye somo (student_subjects).
 * Hurudisha null ikiwa hakuna usajili wowote — maana yake ni kutumia wanafunzi wote wa darasa.
 */
function subject_registered_ids($school_id, $class_id, $subject_id) {
    $ids = [];
    foreach (q_all('SELECT ss.student_id FROM student_subjects ss JOIN students s ON s.id = ss.student_id
                    WHERE ss.subject_id = ? AND s.school_id = ? AND s.class_id = ?',
                    [(int)$subject_id, (int)$school_id, (int)$class_id]) as $r) {
        $ids[] = (int)$r['student_id'];
    }
    return $ids ?: null;
}

/**
 * Watahiniwa wa shule hii wanaotakiwa kufanya somo. Orodha hutokana na usajili wa wanafunzi
 * kwenye somo (student_subjects); ikiwa somo halina usajili wowote, wanafunzi wote wa darasa hutumika.
 */
function subject_candidates($exam_id, $school_id, $class_id, $subject_id) {
    $cs = q_one('SELECT subject_id FROM class_subjects WHERE class_id = ? AND subject_id = ?', [(int)$class_id, (int)$subject_id]);
    if (!$cs) return [];
    $rows = q_all('SELECT ec.cand_no, s.id AS student_id, s.admission_no, s.prems_no, s.first_name, s.middle_name, s.last_name, s.sex
                  FROM exam_candidates ec JOIN students s ON s.id = ec.student_id
                  WHERE ec.exam_id = ? AND ec.school_id = ? AND ec.class_id = ?
                  ORDER BY s.last_name, s.first_name', [(int)$exam_id, (int)$school_id, (int)$class_id]);
    $ids = subject_registered_ids($school_id, $class_id, $subject_id);
    if ($ids === null) return $rows;
    $set = array_flip($ids);
    return array_values(array_filter($rows, function ($r) use ($set) { return isset($set[(int)$r['student_id']]); }));
}

/** Je, mtumiaji huyu anaweza kuingiza alama za shule/darasa/somo hili? */
function can_enter_scores(array $exam, $school_id, $class_id, $subject_id) {
    $u = user();
    if (!$u || !exam_has_school($exam['id'], $school_id)) return false;
    if ($u['role'] === 'teacher') {
        // Teachers may only enter scores for school exams (not district exams),
        // and only for subjects assigned to them, while the exam is open.
        if (empty($exam['owner_school_id'])) return false;
        return $exam['status'] === 'open' && (int)$u['school_id'] === (int)$school_id && teacher_has((int)$u['id'], (int)$class_id, (int)$subject_id);
    }
    if ($u['role'] === 'admin') return (int)$u['school_id'] === (int)$school_id;
    return true;   // super (aliyeingia shuleni)
}

/** Hifadhi alama moja. Hurudisha 'ok', 'skip' au ujumbe wa kosa. */
function save_score($exam_id, $school_id, $class_id, $student_id, $subject_id, $raw, $absent, $source = 'MANUAL') {
    if ($absent) { $score = null; $status = 'ABSENT'; }
    else {
        $raw = str_replace(',', '.', trim((string)$raw));
        if ($raw === '') return 'skip';
        if (!is_numeric($raw)) return 'Alama si namba sahihi';
        $score = round((float)$raw, 2);
        if ($score < 0 || $score > 100) return 'Alama lazima iwe kati ya 0 na 100';
        $status = 'PRESENT';
    }
    $u = user();
    q('INSERT INTO scores (exam_id, school_id, student_id, subject_id, class_id, score, status, source, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE score = VALUES(score), status = VALUES(status), source = VALUES(source), entered_by = VALUES(entered_by), class_id = VALUES(class_id)',
      [(int)$exam_id, (int)$school_id, (int)$student_id, (int)$subject_id, (int)$class_id, $score, $status, $source, $u ? (int)$u['id'] : null]);
    return 'ok';
}

/** Maendeleo ya uingizaji alama kwa shule moja: waliotarajiwa dhidi ya walioingizwa. */
function exam_progress($exam_id, $school_id) {
    $out = [];
    foreach (exam_class_list($exam_id) as $c) {
        foreach (exam_subject_list($exam_id, $c['id']) as $s) {
            $exp = count(subject_candidates($exam_id, $school_id, $c['id'], $s['id']));
            $got = (int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?', [(int)$exam_id, (int)$school_id, (int)$c['id'], (int)$s['id']]);
            $out[] = ['class_id' => (int)$c['id'], 'class_name' => $c['name'], 'subject_id' => (int)$s['id'], 'subject' => $s['name'], 'expected' => $exp, 'entered' => $got];
        }
    }
    return $out;
}

/* ------------------------- Kukokotoa matokeo ya shule + darasa ------------------------- */
function compute_results($exam_id, $class_id, $school_id) {
    $exam   = get_exam($exam_id);
    $class  = q_one('SELECT * FROM classes WHERE id = ?', [(int)$class_id]);
    $school = school_by_id($school_id);
    if (!$exam || !$class || !$school) return null;
    $lvl     = ($class['level'] === 'A') ? 'A' : 'O';
    $grading = get_grading($lvl);
    $failGrade = $grading[count($grading) - 1]['grade'];

    $subjects = [];
    foreach (exam_subject_list($exam_id, $class_id) as $s) {
        $subjects[(int)$s['id']] = ['id' => (int)$s['id'], 'name' => $s['name'], 'code' => $s['code'], 'subject_name' => $s['name'], 'subject_code' => $s['code'],
            'abbr' => subject_abbr($s), 'subsidiary' => ($lvl === 'A' && (int)$s['is_subsidiary'] === 1)];
    }

    $cands = q_all('SELECT ec.cand_no, ec.class_id, s.id AS student_id, s.admission_no, s.prems_no, s.first_name, s.middle_name, s.last_name, s.sex
                    FROM exam_candidates ec JOIN students s ON s.id = ec.student_id WHERE ec.exam_id = ? AND ec.school_id = ? AND ec.class_id = ? ORDER BY ec.cand_no',
                   [(int)$exam_id, (int)$school_id, (int)$class_id]);
    $scoreMap = [];
    foreach (q_all('SELECT student_id, subject_id, score, status FROM scores WHERE exam_id = ? AND school_id = ? AND class_id = ?', [(int)$exam_id, (int)$school_id, (int)$class_id]) as $r) {
        $scoreMap[(int)$r['student_id']][(int)$r['subject_id']] = $r;
    }

    $out = [];
    foreach ($cands as $c) {
        $sid = (int)$c['student_id'];
        $rows = []; $countPts = []; $sum = 0; $n = 0; $present = 0;
        foreach (($scoreMap[$sid] ?? []) as $subId => $r) {
            if (!isset($subjects[$subId])) continue;
            $sub = $subjects[$subId];
            $row = ['subject_id' => $subId, 'abbr' => $sub['abbr'], 'name' => $sub['name'], 'code' => $sub['code'], 'subsidiary' => $sub['subsidiary'],
                    'status' => $r['status'], 'score' => null, 'grade' => '', 'points' => null, 'remark' => ''];
            if ($r['status'] === 'PRESENT' && $r['score'] !== null) {
                $score = (float)$r['score'];
                if ($sub['subsidiary']) { $pass = $score >= 35; $row['grade'] = $pass ? 'S' : 'F'; $row['remark'] = $pass ? 'Pass' : 'Fail'; }
                else { $g = grade_for($score, $grading); $row['grade'] = $g['grade']; $row['points'] = $g['points']; $row['remark'] = $g['remark']; $countPts[] = $g['points']; }
                $row['score'] = $score; $sum += $score; $n++; $present++;
            } else { $row['grade'] = 'ABS'; }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) { return strcmp($a['abbr'], $b['abbr']); });

        if (!$rows)             { $div = '-';   $pts = null; $state = 'PENDING'; }
        elseif ($present === 0) { $div = 'ABS'; $pts = null; $state = 'ABS'; }
        else { list($div, $pts) = division($lvl, $countPts); $state = ($div === 'INC') ? 'INC' : 'OK'; }

        $detail = [];
        if ($state !== 'ABS') {
            foreach ($rows as $r) $detail[] = $r['abbr'] . " - '" . $r['grade'] . "'";
        }
        $out[] = ['student_id' => $sid, 'cand_no' => $c['cand_no'], 'admission_no' => $c['admission_no'], 'prems_no' => ($c['prems_no'] !== null && $c['prems_no'] !== '') ? $c['prems_no'] : $c['admission_no'],
                  'name' => full_name($c), 'sex' => strtoupper(substr($c['sex'], 0, 1)), 'class_id' => (int)$class_id, 'class_name' => $class['name'],
                  'subjects' => $rows, 'division' => $div, 'points' => $pts, 'state' => $state, 'total' => $sum, 'average' => $n ? round($sum / $n, 2) : null, 'detail' => implode(', ', $detail)];
    }

    // Nafasi darasani: points ndogo = bora; ikilingana, wastani mkubwa
    $idx = [];
    foreach ($out as $i => $o) if ($o['state'] === 'OK') $idx[] = $i;
    usort($idx, function ($a, $b) use ($out) {
        if ($out[$a]['points'] !== $out[$b]['points']) return $out[$a]['points'] <=> $out[$b]['points'];
        return $out[$b]['average'] <=> $out[$a]['average'];
    });
    foreach ($idx as $pos => $i) { $out[$i]['position'] = $pos + 1; $out[$i]['class_size'] = count($idx); }

    // Panga watahiniwa kulingana na namba ya mtahiniwa (index/candidate number) kuanzia ndogo kwenda kubwa.
    usort($out, function ($a, $b) {
        return strnatcmp((string)($a['cand_no'] ?? ''), (string)($b['cand_no'] ?? ''));
    });

    $divs = ['I', 'II', 'III', 'IV', '0', 'INC', 'ABS'];
    $divSummary = []; foreach ($divs as $d) $divSummary[$d] = ['F' => 0, 'M' => 0];
    $sexCount = ['F' => 0, 'M' => 0];
    foreach ($out as $o) { $sx = ($o['sex'] === 'F') ? 'F' : 'M'; $sexCount[$sx]++; if (isset($divSummary[$o['division']])) $divSummary[$o['division']][$sx]++; }

    $stats = [];
    foreach ($subjects as $subId => $s) {
        $row = ['subject_id' => $subId, 'abbr' => $s['abbr'], 'name' => $s['name'], 'code' => $s['code'], 'subsidiary' => $s['subsidiary'],
                'sat' => 0, 'absent' => 0, 'grades' => [], 'pass' => 0, 'ptsSum' => 0, 'ptsN' => 0, 'scoreSum' => 0];
        foreach ($grading as $g) $row['grades'][$g['grade']] = 0;
        if ($s['subsidiary']) $row['grades'] = ['S' => 0, 'F' => 0];
        foreach ($out as $o) foreach ($o['subjects'] as $r) {
            if ($r['subject_id'] !== $subId) continue;
            if ($r['score'] === null) { $row['absent']++; continue; }
            $row['sat']++; $row['scoreSum'] += $r['score'];
            if (isset($row['grades'][$r['grade']])) $row['grades'][$r['grade']]++;
            $failed = $s['subsidiary'] ? ($r['grade'] === 'F') : ($r['grade'] === $failGrade);
            if (!$failed) $row['pass']++;
            if ($r['points'] !== null) { $row['ptsSum'] += $r['points']; $row['ptsN']++; }
        }
        $row['pass_pct'] = $row['sat'] ? round($row['pass'] * 100 / $row['sat'], 1) : null;
        $row['gpa'] = $row['ptsN'] ? round($row['ptsSum'] / $row['ptsN'], 4) : null;
        $row['avg'] = $row['sat'] ? round($row['scoreSum'] / $row['sat'], 1) : null;
        if ($row['sat'] || $row['absent']) $stats[] = $row;
    }
    // Panga masomo kulingana na GPA (ndogo kwanza) kwenye jedwali la utendaji wa masomo.
    usort($stats, function ($a, $b) {
        $ga = $a['gpa']; $gb = $b['gpa'];
        if ($ga === null && $gb === null) return 0;
        if ($ga === null) return 1;
        if ($gb === null) return -1;
        if ($ga == $gb) return 0;
        return ($ga < $gb) ? -1 : 1;
    });

    $necta = school_centre($school);        return ['exam' => ['id' => (int)$exam['id'], 'exam_name' => $exam['name'], 'exam_type' => (string)($exam['exam_type'] ?? ''), 'exam_year' => $exam['year_name'], 'exam_month' => exam_month_name($exam), 'created_at' => (string)($exam['created_at'] ?? ''), 'exam_level' => $lvl, 'status' => $exam['status'], 'published' => is_published($exam_id, $school_id) ? 1 : 0],
            'school' => ['id' => (int)$school['id'], 'school_name' => $school['name'], 'necta_no' => $necta,
                         'region' => (string)($school['region'] ?? ''), 'district' => (string)($school['district'] ?? ''),
                         'phone' => (string)($school['phone'] ?? ''), 'motto' => (string)($school['motto'] ?? '')],
            'necta_no' => $necta, 'level' => $lvl, 'class' => $class,
            'grading' => $grading, 'candidates' => $out, 'subjects' => $subjects, 'divSummary' => $divSummary, 'sexCount' => $sexCount, 'subjStats' => $stats, 'generated' => date('d/m/Y H:i')];
}

/* ------------------------- Taarifa za awali za ripoti (report preliminary info) ------------------------- */
/** Hutengeneza jedwali la taarifa za ripoti mara ya kwanza linapotumika. */
function report_info_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `report_info` (
            `id` int NOT NULL AUTO_INCREMENT,
            `school_id` int NOT NULL,
            `class_id` int NOT NULL,
            `term` enum('I','II') NOT NULL DEFAULT 'I',
            `closing_date` date DEFAULT NULL,
            `opening_date` date DEFAULT NULL,
            `class_teacher_id` int DEFAULT NULL,
            `subject_teacher_id` int DEFAULT NULL,
            `headmaster_id` int DEFAULT NULL,
            `instructions` text,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_report_info` (`school_id`,`class_id`,`term`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Taarifa zilizohifadhiwa za ripoti kwa shule, darasa na muhula (pamoja na majina ya walimu). */
function report_info_get($school_id, $class_id, $term = '') {
    report_info_table();
    $base = 'SELECT ri.*, ct.full_name AS class_teacher, ct.phone AS class_phone,
                    st.full_name AS subject_teacher, st.phone AS subject_phone,
                    hm.full_name AS headmaster, hm.phone AS head_phone
             FROM report_info ri
             LEFT JOIN users ct ON ct.id = ri.class_teacher_id
             LEFT JOIN users st ON st.id = ri.subject_teacher_id
             LEFT JOIN users hm ON hm.id = ri.headmaster_id
             WHERE ri.school_id = ? AND ri.class_id = ?';
    $row = null;
    try {
        if ($term !== '') $row = q_one($base . ' AND ri.term = ? ORDER BY ri.id DESC LIMIT 1', [(int)$school_id, (int)$class_id, (string)$term]);
        if (!$row) $row = q_one($base . ' ORDER BY ri.updated_at DESC, ri.id DESC LIMIT 1', [(int)$school_id, (int)$class_id]);
    } catch (Exception $e) { $row = null; }
    return $row ?: [];
}

/** Muhula (I/II) kutokana na maandishi ya mtihani (aina au mwezi). */
function report_term_code($text) {
    $t = ' ' . strtoupper(trim((string)$text)) . ' ';
    foreach (['PILI', 'SECOND', 'TERM II', 'MUHULA WA II', 'TERM 2', 'MUHULA 2', ' II '] as $needle) {
        if (strpos($t, $needle) !== false) return 'II';
    }
    return 'I';
}

/** Hutengeneza jedwali la maoni ya ripoti yaliyohaririwa na shule mara ya kwanza linapotumika. */
function report_remarks_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `report_remarks` (
            `school_id` int NOT NULL,
            `grade` char(1) NOT NULL,
            `class_teacher` varchar(500) DEFAULT NULL,
            `subject_teacher` varchar(500) DEFAULT NULL,
            `headmaster` varchar(500) DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`school_id`,`grade`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Maoni ya kiotomatiki (kwa gredi ya wastani wa mwanafunzi) yanayotumika kwenye ripoti.
 *  Kama shule imehariri maoni yake, yale ya shule hutumika badala ya chaguo-msingi. */
function report_remarks($grade, $school_id = null) {
    $g = strtoupper(trim((string)$grade));
    if ($g === '') return ['class_teacher' => '', 'subject_teacher' => '', 'headmaster' => ''];
    $class = [
        'A' => 'Hongera, amefanya vizuri aendelee kujituma katika masomo yake',
        'B' => 'Amefanya Vizuri, aongezee bidii zaidi katika masomo',
        'C' => 'Wastani wake ni mzuri. Aendelee kufanya juhudi katika masomo',
        'D' => 'Ana wastani mbaya aongeze juhudi zaidi katika masomo. Mzazi mtafutie mwanao masomo ya ziada',
        'F' => 'Matokeo yake ni mabaya sana, mtafutie mwanao masomo ya ziada',
    ];
    $staff = [
        'A' => 'Hongera, amefanya Vizuri Sana.',
        'B' => 'Amefanya Vizuri, aendelee kujituma katika masomo.',
        'C' => 'Ana wastani mzuri. Aendelea kujituma zaidi katika masomo.',
        'D' => 'Wastani wake ni dhaifu. Mzazi unaombwa kufuatilia taaluma ya mwanao.',
        'F' => 'Matokeo yake hayaridhishi, mtafutie mwanao masomo ya ziada na kumuhimiza zaidi katika Masomo',
    ];
    if (!isset($staff[$g])) $g = ($g === 'E' || $g === 'S') ? 'D' : 'F';
    $out = ['class_teacher' => $class[$g], 'subject_teacher' => $staff[$g], 'headmaster' => $staff[$g]];
    $sid = (int)($school_id ?? active_school_id());
    if ($sid) {
        static $cache = [];
        if (!isset($cache[$sid])) {
            $cache[$sid] = [];
            report_remarks_table();
            try {
                foreach (q_all('SELECT grade, class_teacher, subject_teacher, headmaster FROM report_remarks WHERE school_id = ?', [$sid]) as $r) {
                    $cache[$sid][strtoupper((string)$r['grade'])] = $r;
                }
            } catch (Exception $e) {}
        }
        $ov = $cache[$sid][$g] ?? null;
        if ($ov) {
            foreach (['class_teacher', 'subject_teacher', 'headmaster'] as $k) {
                if (trim((string)($ov[$k] ?? '')) !== '') $out[$k] = (string)$ov[$k];
            }
        }
    }
    return $out;
}

/* ---------------- Nembo, muhuri na sahihi za ripoti (report branding) ---------------- */
/** Hutengeneza jedwali la nembo/muhuri/sahihi za shule mara ya kwanza linapotumika. */
function report_branding_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `report_branding` (
            `school_id` int NOT NULL,
            `national_logo` varchar(191) DEFAULT NULL,
            `school_logo` varchar(191) DEFAULT NULL,
            `stamp` varchar(191) DEFAULT NULL,
            `signature` varchar(191) DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Picha zilizohifadhiwa za ripoti kwa shule (nembo ya taifa, nembo ya shule, muhuri, sahihi). */
function report_branding_get($school_id) {
    report_branding_table();
    $empty = ['national_logo' => '', 'school_logo' => '', 'stamp' => '', 'signature' => ''];
    try {
        $row = q_one('SELECT national_logo, school_logo, stamp, signature FROM report_branding WHERE school_id = ?', [(int)$school_id]);
        if ($row) return array_merge($empty, array_map(function ($v) { return (string)$v; }, $row));
    } catch (Exception $e) {}
    return $empty;
}

/** Aina ya faili (png/jpg/gif/webp) kutokana na maudhui ya picha; null kama si picha. */
function report_branding_ext($tmp) {
    $info = @getimagesize($tmp);
    $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    return ($info && isset($map[$info[2]])) ? $map[$info[2]] : null;
}

/**
 * Huondoa background nyeupe ya muhuri/sahihi: pikseli zilizo karibu na nyeupe huwa transparent,
 * picha hukatwa pembezoni, na kuhifadhiwa kama PNG. Hurejesha extension mpya.
 */
function report_branding_clean($path, $ext) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return $ext;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $ext;
    $img = @imagecreatefromstring($raw);
    if (!$img) return $ext;
    $w = imagesx($img); $h = imagesy($img);
    if ($w < 1 || $h < 1) { @imagedestroy($img); return $ext; }

    // Picha kubwa zipunguzwe kwanza (kasi na ukubwa wa faili)
    $maxSide = 1400;
    if ($w > $maxSide) {
        $nh = max(1, (int)round($h * $maxSide / $w));
        $tmp = imagecreatetruecolor($maxSide, $nh);
        imagealphablending($tmp, false); imagesavealpha($tmp, true);
        imagefilledrectangle($tmp, 0, 0, $maxSide, $nh, imagecolorallocatealpha($tmp, 255, 255, 255, 127));
        imagecopyresampled($tmp, $img, 0, 0, 0, 0, $maxSide, $nh, $w, $h);
        @imagedestroy($img);
        $img = $tmp; $w = $maxSide; $h = $nh;
    }

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocatealpha($out, 255, 255, 255, 127));

    $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            $lum = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
            if ($lum >= 246) continue; // nyeupe tupu
            // Pembe laini (anti-alias) ziwe transparent kiasi
            $alpha = $lum > 214 ? (int)round((($lum - 214) / 32) * 127) : 0;
            if ($alpha > 127) $alpha = 127;
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
            if ($alpha < 100) {
                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }
    }
    @imagedestroy($img);

    // Kata pembezoni tupu
    if ($maxX >= 0) {
        $pad = 4;
        $cx = max(0, $minX - $pad); $cy = max(0, $minY - $pad);
        $cw = min($w - 1, $maxX + $pad) - $cx + 1;
        $ch = min($h - 1, $maxY + $pad) - $cy + 1;
        $crop = imagecreatetruecolor($cw, $ch);
        imagealphablending($crop, false); imagesavealpha($crop, true);
        imagefilledrectangle($crop, 0, 0, $cw, $ch, imagecolorallocatealpha($crop, 255, 255, 255, 127));
        imagecopy($crop, $out, 0, 0, $cx, $cy, $cw, $ch);
        @imagedestroy($out);
        $out = $crop;
    }

    $newPath = preg_replace('/\.[A-Za-z0-9]+$/', '.png', $path);
    $ok = @imagepng($out, $newPath, 9);
    @imagedestroy($out);
    if (!$ok) return $ext;
    if ($newPath !== $path && is_file($path)) @unlink($path);
    return 'png';
}

/** Hifadhi picha iliyopakiwa (PNG/JPG/GIF/WEBP hadi 3MB) kwa shule. Hurejesha jina la faili au '' kama hakuna faili. */
function report_branding_upload($school_id, $type, array $file) {
    $types = ['school_logo', 'stamp', 'signature'];
    if (!in_array($type, $types, true)) throw new Exception('Aina ya picha si sahihi.');
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Picha haikupakiwa vizuri. Jaribu tena.');
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) throw new Exception('Picha ni kubwa mno (kiwango ni 3MB).');
    $ext = report_branding_ext($file['tmp_name']);
    if (!$ext) throw new Exception('Tumia picha ya PNG, JPG, GIF au WEBP.');
    $dir = dirname(__DIR__) . '/assets/uploads/branding';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception('Imeshindikana kutengeneza folda ya picha.');
    foreach (glob($dir . '/' . (int)$school_id . '-' . $type . '.*') ?: [] as $old) @unlink($old);
    $name = (int)$school_id . '-' . $type . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new Exception('Imeshindikana kuhifadhi picha.');
    // Muhuri na sahihi: ondoa background nyeupe ili zionekane safi kwenye ripoti
    if ($type === 'stamp' || $type === 'signature') {
        $cleanExt = report_branding_clean($dir . '/' . $name, $ext);
        if ($cleanExt !== $ext) $name = (int)$school_id . '-' . $type . '.' . $cleanExt;
    }
    report_branding_table();
    q('INSERT INTO report_branding (school_id, `' . $type . '`) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE `' . $type . '` = VALUES(`' . $type . '`)', [(int)$school_id, $name]);
    return $name;
}

/** Ondoa picha ya aina fulani kwa shule. */
function report_branding_clear($school_id, $type) {
    $types = ['school_logo', 'stamp', 'signature'];
    if (!in_array($type, $types, true)) return;
    report_branding_table();
    $dir = dirname(__DIR__) . '/assets/uploads/branding';
    foreach (glob($dir . '/' . (int)$school_id . '-' . $type . '.*') ?: [] as $old) @unlink($old);
    q('UPDATE report_branding SET `' . $type . '` = NULL WHERE school_id = ?', [(int)$school_id]);
}

/** Data URI ya picha iliyohifadhiwa (hutumika kwenye skrini na PDF). */
function report_branding_src($file) {
    static $cache = [];
    $file = basename((string)$file);
    if ($file === '') return '';
    if (array_key_exists($file, $cache)) return $cache[$file];
    $path = dirname(__DIR__) . '/assets/uploads/branding/' . $file;
    $src = '';
    if (is_file($path)) {
        $info = @getimagesize($path);
        if ($info && !empty($info['mime'])) {
            $src = 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($path));
        }
    }
    return $cache[$file] = $src;
}

/** Nembo ya taifa iliyowekwa ndani ya mfumo (storage/templates) - hutumika kwenye ripoti zote. */
function national_logo_src() {
    static $src = null;
    if ($src !== null) return $src;
    $src = '';
    foreach (glob(dirname(__DIR__) . '/storage/templates/Coat_of_arms_of_Tanzania.*') ?: [] as $path) {
        $info = @getimagesize($path);
        if (!$info || empty($info['mime'])) continue;
        // WEBP haitumiki vizuri kwenye PDF; ibadilishe kuwa PNG kama GD inaweza
        if ($info['mime'] === 'image/webp' && function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
            $img = @imagecreatefromwebp($path);
            if ($img) {
                ob_start(); @imagepng($img); $png = ob_get_clean(); @imagedestroy($img);
                if (is_string($png) && $png !== '') { $src = 'data:image/png;base64,' . base64_encode($png); break; }
            }
        }
        $src = 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($path));
        break;
    }
    return $src;
}

/* ------------------------- Alama za nidhamu (discipline scores) ------------------------- */
/** Vipimo vya nidhamu vinavyotumika kwenye ripoti na ukurasa wa discipline. */
function discipline_criteria() {
    return [
        'kazi'      => 'Kufanya kazi kwa bidii, juhudi na maarifa',
        'utiifu'    => 'Utiifu na Uaminifu',
        'kuthamini' => 'Kupenda, kuthamini na kuheshimu kazi',
        'uangalifu' => 'Uangalifu wa mali ya umma, watu wengine na yake binafsi',
        'michezo'   => 'Kushiriki katika michezo na utamaduni',
        'heshima'   => 'Heshima kwa wenzake, walimu, wafanyakazi wengine na wananchi kwa ujumla',
        'uongozi'   => 'Ana sifa za kufanya wenzake kufuata ushauri wake na kuvutiwa uongozi wake',
        'mahudhurio'=> 'Mahudhurio darasani na kazi za nje',
        'usafi'     => 'Usafi binafsi',
        'nidhamu'   => 'Hana nidhamu katika kazi na masomo',
    ];
}

/** Gredi ya nidhamu ya kiotomatiki kwa kila kipimo, kutokana na gredi ya matokeo. */
function discipline_auto_grades($grade) {
    $grade = strtoupper(trim((string)$grade));
    $out = [];
    foreach (array_keys(discipline_criteria()) as $k) $out[$k] = $grade;
    return $out;
}

/** Gredi zinazokubalika za nidhamu (bora hadi hafifu). */
function discipline_grades() { return ['A', 'B', 'C', 'D', 'F']; }

/** Pointi za gredi ya nidhamu (A=5 ... F=1). */
function discipline_points($g) {
    $p = ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'F' => 1];
    $g = strtoupper(trim((string)$g));
    return $p[$g] ?? 0;
}

/** Gredi ya nidhamu kutokana na wastani wa pointi (0-5). */
function discipline_grade($avg) {
    if ($avg === null || $avg === '') return '';
    $v = (float)$avg;
    if ($v >= 4.5) return 'A';
    if ($v >= 3.5) return 'B';
    if ($v >= 2.5) return 'C';
    if ($v >= 1.5) return 'D';
    return 'F';
}

/** Hutengeneza jedwali la alama za nidhamu mara ya kwanza linapotumika. */
function discipline_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `discipline_scores` (
            `exam_id` int NOT NULL,
            `class_id` int NOT NULL,
            `student_id` int NOT NULL,
            `data` text,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`exam_id`, `student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Alama za nidhamu za darasa: student_id => [criterion => grade]. */
function discipline_get($exam_id, $class_id) {
    static $cache = [];
    $ck = (int)$exam_id . ':' . (int)$class_id;
    if (isset($cache[$ck])) return $cache[$ck];
    discipline_table();
    $out = [];
    try {
        $rows = q('SELECT student_id, data FROM discipline_scores WHERE exam_id = ? AND class_id = ?', [(int)$exam_id, (int)$class_id]);
        foreach ($rows as $r) {
            $d = json_decode((string)$r['data'], true);
            $out[(int)$r['student_id']] = is_array($d) ? $d : [];
        }
    } catch (Exception $e) {}
    return $cache[$ck] = $out;
}

/** Hifadhi alama za nidhamu (gredi A-F pekee zilizochaguliwa) kwa darasa. */
function discipline_save($exam_id, $class_id, array $rows) {
    discipline_table();
    $keys = array_keys(discipline_criteria());
    $ok = discipline_grades();
    foreach ($rows as $sid => $vals) {
        $sid = (int)$sid;
        if ($sid <= 0 || !is_array($vals)) continue;
        $clean = [];
        foreach ($keys as $k) {
            $g = strtoupper(trim((string)($vals[$k] ?? '')));
            if (in_array($g, $ok, true)) $clean[$k] = $g;
        }
        if (!$clean) {
            q('DELETE FROM discipline_scores WHERE exam_id = ? AND student_id = ?', [(int)$exam_id, $sid]);
            continue;
        }
        q('INSERT INTO discipline_scores (exam_id, class_id, student_id, data) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), data = VALUES(data)',
          [(int)$exam_id, (int)$class_id, $sid, json_encode($clean)]);
    }
}

/** Wastani wa nidhamu ya mwanafunzi (pointi 0-5) na gredi yake; null kama hakuna alama. */
function discipline_student_avg(array $grades) {
    $sum = 0; $n = 0;
    foreach ($grades as $g) { $p = discipline_points($g); if ($p > 0) { $sum += $p; $n++; } }
    return $n ? round($sum / $n, 2) : null;
}

/* ------------------------- Matokeo ya wilaya (kulinganisha shule) ------------------------- */
/** Kwa mtihani na darasa: kila shule inayoshiriki, mgawanyo wa division, wastani wa points na cheo. */
function compute_council($exam_id, $class_id) {
    $exam  = get_exam($exam_id);
    $class = q_one('SELECT * FROM classes WHERE id = ?', [(int)$class_id]);
    if (!$exam || !$class) return null;
    $lvl = ($class['level'] === 'A') ? 'A' : 'O';
    $rows = [];
    foreach (exam_school_list($exam_id) as $sc) {
        $d = compute_results($exam_id, $class_id, $sc['id']);
        $r = ['school_id' => (int)$sc['id'], 'name' => $sc['name'], 'centre' => school_centre($sc), 'published' => (int)$sc['published'],
              'registered' => count($d['candidates']), 'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, '0' => 0, 'INC' => 0, 'ABS' => 0, 'PENDING' => 0, 'ok' => 0, 'ptsSum' => 0, 'rank' => null];
        foreach ($d['candidates'] as $c) {
            if ($c['state'] === 'OK') { $r[$c['division']]++; $r['ok']++; $r['ptsSum'] += $c['points']; }
            elseif ($c['state'] === 'INC') $r['INC']++; elseif ($c['state'] === 'ABS') $r['ABS']++; else $r['PENDING']++;
        }
        $r['avg_points'] = $r['ok'] ? round($r['ptsSum'] / $r['ok'], 2) : null;
        $r['pass_pct'] = $r['ok'] ? round(($r['I'] + $r['II'] + $r['III'] + $r['IV']) * 100 / $r['ok'], 1) : null;
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) {
        if ($a['avg_points'] === null && $b['avg_points'] === null) return strcmp($a['name'], $b['name']);
        if ($a['avg_points'] === null) return 1;
        if ($b['avg_points'] === null) return -1;
        if ($a['avg_points'] != $b['avg_points']) return $a['avg_points'] <=> $b['avg_points'];
        return $b['I'] <=> $a['I'];
    });
    $rk = 0; foreach ($rows as $i => $r) if ($r['avg_points'] !== null) $rows[$i]['rank'] = ++$rk;
    return ['exam' => ['id' => (int)$exam['id'], 'exam_name' => $exam['name'], 'exam_year' => $exam['year_name'], 'exam_month' => exam_month_name($exam)], 'class' => $class, 'level' => $lvl, 'rows' => $rows, 'generated' => date('d/m/Y H:i')];
}
