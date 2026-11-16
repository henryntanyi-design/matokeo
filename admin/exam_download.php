<?php
/**
 * Upakuaji wa nyaraka za mtihani (CAL, ISAL, Registered Candidates Stats, Sitting Plan)
 * kwa muundo wa OASIS.
 */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/exam_docs.php';

$u = require_staff(['super', 'admin']);
$exam_id = (int)($_GET['exam_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? '')));

$exam = get_exam($exam_id);
if (!$exam) { http_response_code(404); die('Exam not found.'); }

/* Takwimu za pamoja za shule zote za wilaya (super pekee). */
if ($type === 'stats_all') {
    if ($u['role'] !== 'super') { http_response_code(403); die('Statistics for all schools are available to the district administrator only.'); }
    $schools = exam_school_list($exam_id);
    if (!$schools) { http_response_code(404); die('No school is registered for this exam.'); }
    try {
        $classes = exam_class_list($exam_id);
        $classSubs = [];
        $allSubs = [];
        foreach ($classes as $c) {
            $subs = exam_subject_list($exam_id, (int)$c['id']);
            $classSubs[(int)$c['id']] = $subs;
            foreach ($subs as $s) $allSubs[(int)$s['id']] = $s;
        }
        $candBySchoolClass = [];
        foreach (q_all('SELECT school_id, class_id, COUNT(*) n FROM exam_candidates WHERE exam_id = ? GROUP BY school_id, class_id', [$exam_id]) as $row) {
            $candBySchoolClass[(int)$row['school_id']][(int)$row['class_id']] = (int)$row['n'];
        }
        $rows = [];
        foreach ($schools as $sch) {
            $sid = (int)$sch['id'];
            $counts = array_fill_keys(array_keys($allSubs), 0);
            $usedSubs = [];
            $total = 0;
            foreach ($classes as $c) {
                $cid = (int)$c['id'];
                $n = $candBySchoolClass[$sid][$cid] ?? 0;
                if ($n <= 0) continue;
                $total += $n;
                foreach ($classSubs[$cid] as $s) {
                    $subId = (int)$s['id'];
                    $counts[$subId] += $n;
                    $usedSubs[$subId] = true;
                }
            }
            $rows[] = [
                'school_name' => exam_doc_short_school($sch),
                'centre' => school_centre($sch),
                'subject_count' => count($usedSubs),
                'counts' => $counts,
                'total' => $total,
            ];
        }
        $wb = xlsx_registered_stats_schools($exam, $schools, array_values($allSubs), $rows);
        $reg = strtoupper(trim((string)($schools[0]['region'] ?? '')));
        $dist = strtoupper(trim((string)($schools[0]['district'] ?? '')));
        send_xlsx($wb, 'OASIS-' . ($reg !== '' ? $reg . '_' : '') . ($dist !== '' ? $dist . '_' : '') . 'REGISTERED_CANDIDATES_STATS');
    } catch (Exception $e) {
        http_response_code(500);
        die(h($e->getMessage()));
    }
}

$school_id = active_school_id();
// Super (wilaya) anaweza kupakua nyaraka za shule yoyote kwa kuipitisha school_id;
// admin wa shule hulazimishwa kutumia shule yake mwenyewe.
if ($u['role'] === 'super' && (int)($_GET['school_id'] ?? 0) > 0) {
    $school_id = (int)$_GET['school_id'];
}
if (!$school_id) { http_response_code(400); die('Please choose a school first.'); }

if (!exam_has_school($exam_id, $school_id)) {
    http_response_code(404);
    die('Exam not found or does not include this school.');
}
$sc = school_by_id($school_id);
if (!$sc) { http_response_code(404); die('School not found.'); }

$classes = exam_class_list($exam_id);
$class = null;
foreach ($classes as $c) {
    if ((int)$c['id'] === $class_id) { $class = $c; break; }
}

$tag = 'OASIS-' . school_centre($sc) . '_' . exam_doc_short_school($sc) . ' SECONDARY SCHOOL';

try {
    if ($type === 'cal' || $type === 'sitting' || $type === 'isal') {
        if (!$class) { http_response_code(400); die('Please choose a valid exam class.'); }
        $subjects = exam_subject_list($exam_id, (int)$class['id']);
        $candidates = exam_doc_candidates($exam_id, $school_id, (int)$class['id']);

        if ($type === 'cal') {
            $wb = xlsx_cal($exam, $sc, $class, $subjects, $candidates, (string)$u['full_name']);
            send_xlsx($wb, $tag . '_' . strtoupper($class['name']) . '_CAL');
        }

        if ($type === 'sitting') {
            $wb = xlsx_sitting_plan($exam, $sc, $class, $subjects, $candidates, (string)$u['full_name']);
            send_xlsx($wb, $tag . '_' . strtoupper($class['name']) . '_SITTING_PLAN');
        }

        // ISAL
        $subject = null;
        foreach ($subjects as $s) {
            if ((int)$s['id'] === $subject_id) { $subject = $s; break; }
        }
        if (!$subject) { http_response_code(400); die('This subject is not registered for this exam class.'); }
        // ISAL ina wanafunzi waliosajiliwa kwenye somo (student_subjects) pekee;
        // tumia usajili wa somo tu, bila fallback ya "wanafunzi wote wa darasa".
        $allCands = q_all('SELECT ec.cand_no, s.id AS student_id, s.admission_no, s.prems_no, s.first_name, s.middle_name, s.last_name, s.sex
                          FROM exam_candidates ec JOIN students s ON s.id = ec.student_id
                          WHERE ec.exam_id = ? AND ec.school_id = ? AND ec.class_id = ?
                          ORDER BY s.last_name, s.first_name, s.middle_name', [(int)$exam_id, (int)$school_id, (int)$class['id']]);
        $enrolledIds = subject_registered_ids((int)$school_id, (int)$class['id'], (int)$subject['id']);
        if ($enrolledIds === null) $enrolledIds = [];
        $enrolledSet = array_flip($enrolledIds);
        $isalCandidates = array_values(array_filter($allCands, function ($r) use ($enrolledSet) {
            return isset($enrolledSet[(int)$r['student_id']]);
        }));
        if (isset($_GET['check'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => !empty($isalCandidates), 'count' => count($isalCandidates),
                'message' => empty($isalCandidates) ? 'No student is enrolled in this subject for this class.' : '']);
            exit;
        }
        if (!$isalCandidates) { http_response_code(400); die('No student is enrolled in this subject for this class.'); }
        $wb = xlsx_isal($exam, $sc, $class, $subject, $isalCandidates);
        send_xlsx($wb, $tag . '_' . strtoupper($class['name']) . '_' . strtoupper($subject['abbr'] ?: $subject['name']) . '_ISAL');
    }

    if ($type === 'stats') {
        $use = $class ? [$class] : $classes;
        $allSubs = [];
        $rows = [];
        foreach ($use as $c) {
            $subs = exam_subject_list($exam_id, (int)$c['id']);
            $cnt = count(exam_doc_candidates($exam_id, $school_id, (int)$c['id']));
            $counts = [];
            $ids = [];
            foreach ($subs as $s) {
                $sid = (int)$s['id'];
                $ids[] = $sid;
                $counts[$sid] = $cnt;
                if (!isset($allSubs[$sid])) $allSubs[$sid] = $s;
            }
            $rows[] = [
                'class_name' => $c['name'],
                'subject_count' => count($ids),
                'counts' => $counts,
                'total' => $cnt,
            ];
        }
        $wb = xlsx_registered_stats($exam, $sc, array_values($allSubs), $rows, $class ? (string)$class['name'] : '');
        send_xlsx($wb, $tag . ($class ? '_' . strtoupper($class['name']) : '') . '_REGISTERED_CANDIDATES_STATS');
    }

    http_response_code(400);
    die('Aina ya nyaraka haijulikani.');
} catch (Exception $e) {
    http_response_code(500);
    die(h($e->getMessage()));
}
