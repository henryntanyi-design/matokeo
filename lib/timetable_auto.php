<?php
/**
 * Automated timetable generator: stream planning and morning-first scheduling.
 *
 * This module sits on top of the existing timetable storage (timetables,
 * timetable_requirements, timetable_entries). It answers three questions the
 * generic builder does not:
 *   1. How many streams does a class need, based on the number of students?
 *   2. How do we keep the difficult subjects in the morning?
 *   3. How do we guarantee that no teacher is ever double-booked?
 */
require_once __DIR__ . '/timetable.php';

const TTA_MAX_STREAMS = 12;
const TTA_MAX_PERIODS_PER_SUBJECT_PER_DAY = 2;

/** Letters used for stream names: A, B, C ... */
function tta_stream_labels($count) {
    $count = max(1, min(TTA_MAX_STREAMS, (int)$count));
    $out = [];
    for ($i = 0; $i < $count; $i++) $out[] = chr(65 + $i);
    return $out;
}

/** Is this a subject that should be taught in the morning? */
function tta_is_core_subject($name, $abbr = '') {
    $n = strtolower(trim((string)$name));
    $a = strtoupper(trim((string)$abbr));
    $words = ['mathematic', 'physics', 'chemistry', 'biology', 'english', 'kiswahili', 'general studies'];
    foreach ($words as $w) {
        if ($n !== '' && strpos($n, $w) !== false) return true;
    }
    return in_array($a, ['B/MATH', 'ADV/MATHS', 'PHY', 'CHEM', 'BIO', 'ENGL', 'KISW', 'GS'], true);
}

/**
 * Students per stream in one class (active students only), including the ones
 * that have no stream yet so that the split covers the whole class.
 */
function tta_enrolment($school_id, $class_id) {
    $rows = q_all(
        "SELECT COALESCE(NULLIF(TRIM(stream), ''), '') AS stream, COUNT(*) AS n
         FROM students
         WHERE school_id = ? AND class_id = ? AND status = 'active'
         GROUP BY COALESCE(NULLIF(TRIM(stream), ''), '')
         ORDER BY stream",
        [(int)$school_id, (int)$class_id]
    );
    $total = 0;
    $existing = [];
    $unassigned = 0;
    foreach ($rows as $r) {
        $n = (int)$r['n'];
        $total += $n;
        $label = trim((string)$r['stream']);
        if ($label === '') $unassigned = $n;
        else $existing[$label] = $n;
    }
    return ['total' => $total, 'existing' => $existing, 'unassigned' => $unassigned];
}

/**
 * Work out how the class should be split into streams.
 *
 * Hakuna kikomo cha idadi ya wanafunzi kwa mkondo. Idadi ya mikondo hutokana
 * na uamuzi wa mtumiaji; bila hiyo, hufuata streams zilizoandikishwa kwa
 * wanafunzi (au darasa zima kama hakuna stream).
 *
 * @param int $override  Idadi ya mikondo iliyowekwa kwa mkono (0 = kutoka wanafunzi).
 */
function tta_plan_streams($school_id, $class_id, $override = 0) {
    // Bila safu ya `stream` kwenye wanafunzi, idadi iliyowekwa kwa mkono
    // (override) bado inatumika: tunarudi kwenye darasa lenye wanafunzi 0.
    try {
        $enrol = tta_enrolment($school_id, $class_id);
    } catch (Exception $e) {
        $enrol = ['total' => 0, 'existing' => [], 'unassigned' => 0];
    }
    $total = $enrol['total'];

    // Bila idadi iliyowekwa, tumia idadi ya streams zilizopo kwa wanafunzi.
    $auto = count($enrol['existing']) > 0 ? count($enrol['existing']) : 1;
    $count = (int)$override > 0 ? (int)$override : $auto;
    $count = max(1, min(TTA_MAX_STREAMS, $count));

    // When the students already carry exactly the right number of stream labels,
    // keep their names instead of inventing A/B/C.
    $labels = count($enrol['existing']) === $count ? array_keys($enrol['existing']) : tta_stream_labels($count);
    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'total' => $total,
        'existing' => $enrol['existing'],
        'unassigned' => $enrol['unassigned'],
        'auto_count' => min(TTA_MAX_STREAMS, max(1, $auto)),
        'count' => $count,
        'labels' => $labels,
        'per_stream' => $total > 0 ? (int)ceil($total / $count) : 0,
        'overridden' => (int)$override > 0,
    ];
}

/**
 * Majina ya mikondo kwa kila darasa.
 *
 * Kila darasa hupata idadi yake ya mikondo (kutoka auto au iliyowekwa kwa
 * mkono); darasa lenye mkondo mmoja linabaki darasa zima (jina tupu).
 * Majina hutoka kwa mtumiaji au A, B, C...
 *
 * @param array $plans          cid => matokeo ya tta_plan_streams()
 * @param array $providedLabels Majina yaliyowekwa kwa mkono (hiari)
 * @return array{labels:array, classes:array} labels = dimbwi la majina, classes = cid => majina
 */
function tta_stream_map(array $plans, array $providedLabels = []) {
    $max = 1;
    foreach ($plans as $p) $max = max($max, (int)($p['count'] ?? 1));

    $pool = [];
    foreach ($providedLabels as $label) {
        $label = trim((string)$label);
        if ($label !== '' && !in_array($label, $pool, true)) $pool[] = $label;
    }
    foreach (tta_stream_labels($max) as $candidate) {
        if (count($pool) >= $max) break;
        if (in_array($candidate, $pool, true)) continue;
        $pool[] = $candidate;
    }

    $classes = [];
    foreach ($plans as $cid => $p) {
        $n = max(1, (int)($p['count'] ?? 1));
        $classes[(int)$cid] = $n > 1 ? array_slice($pool, 0, $n) : [''];
    }
    return ['labels' => $pool, 'classes' => $classes];
}

/**
 * Weekly periods per subject for every stream of one class.
 *
 * The total per stream cannot exceed the number of teaching slots, and no
 * subject may ask for more than twice its daily allowance, otherwise the
 * "at most twice a day" rule could never be satisfied.
 *
 * @return array{requirements:array, required:array, dropped:int}
 */
function tta_week_requirements(array $class, $capacity, array $labels, array $days) {
    $capacity = max(0, (int)$capacity);
    $maxWeek = TTA_MAX_PERIODS_PER_SUBJECT_PER_DAY * max(1, count($days));
    $rows = tt_default_requirements([$class], $capacity);

    $dropped = 0;
    $excess = 0;
    foreach ($rows as &$r) {
        $n = (int)$r['periods_per_week'];
        if ($n > $maxWeek) {
            $excess += $n - $maxWeek;
            $r['periods_per_week'] = $maxWeek;
        }
    }
    unset($r);

    // Spread the removed periods over the subjects that still have room.
    $guard = 0;
    while ($excess > 0 && $guard++ < 500) {
        $moved = false;
        foreach ($rows as &$r) {
            if ($r['periods_per_week'] >= $maxWeek) continue;
            $r['periods_per_week']++;
            $excess--;
            $moved = true;
            if ($excess <= 0) break;
        }
        unset($r);
        if (!$moved) { $dropped = $excess; break; }
    }

    $requirements = [];
    $required = [];
    foreach ($labels as $si => $label) {
        foreach ($rows as $r) {
            $n = (int)$r['periods_per_week'];
            if ($n <= 0) continue;
            $cid = (int)$r['class_id'];
            $sid = (int)$r['subject_id'];
            $requirements[] = ['class_id' => $cid, 'subject_id' => $sid, 'stream' => (string)$label, 'periods_per_week' => $n];
            $required[$cid][$sid][(int)$si] = $n;
        }
    }
    return ['requirements' => $requirements, 'required' => $required, 'dropped' => $dropped];
}

/**
 * One generation attempt.
 *
 * Rules enforced here:
 *   - a teacher is never in two places at the same day and period;
 *   - one subject appears at most twice a day in the same stream;
 *   - core subjects take the morning slots first, and only fill the afternoon
 *     when the remaining morning slots cannot hold them anymore;
 *   - breaks were already removed from the slot list, so no lesson lands on a break.
 */
function tta_attempt(array $data, array $assignMap, array $need, array $subjectInfo, $maxPerDay, $morningCutoff) {
    $days = $data['days'];
    // Vipindi vyote vya siku, pamoja na vya baada ya lunch: nafasi zote
    // zinatumika kwa masomo ya lazima, remedial hujaza kilichobaki.
    $sessionItems = [];
    foreach (($data['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') !== 'session') continue;
        $sessionItems[] = $it;
    }

    $daySlots = [];
    foreach ($days as $day) {
        $list = [];
        foreach ($sessionItems as $s) {
            $blocked = false;
            foreach (($data['events'] ?? []) as $ev) {
                if ($ev['day'] === $day && $ev['start_min'] < $s['end'] && $ev['end_min'] > $s['start']) { $blocked = true; break; }
            }
            if (!$blocked) $list[] = $s;
        }
        $daySlots[$day] = $list;
    }

    // Quota za vipindi vya lazima kwa siku, ili siku zote (pamoja na Ijumaa)
    // zishiriki sawasawa badala ya siku za mwanzo kuchukua vipindi vyote.
    $entityQuota = [];
    $entityDayCount = [];
    foreach ($need as $ekey => $ent) {
        $entityQuota[$ekey] = tt_day_quotas($days, $daySlots, (int)array_sum($ent['subjects']));
        foreach ($days as $d) $entityDayCount[$ekey][$d] = 0;
    }

    // Every slot of the week in time order, plus how many morning slots are left.
    $week = [];
    foreach ($days as $day) {
        foreach ($daySlots[$day] as $slot) {
            $week[] = ['day' => $day, 'slot' => $slot, 'morning' => (int)$slot['start'] < $morningCutoff];
        }
    }
    $morningAhead = [];
    $running = 0;
    for ($i = count($week) - 1; $i >= 0; $i--) {
        if ($week[$i]['morning']) $running++;
        $morningAhead[$week[$i]['day']][$week[$i]['slot']['no']] = $running;
    }

    // Task list per class + stream.
    $tasks = [];
    foreach ($need as $ekey => $ent) {
        $cid = (int)$ent['class_id'];
        $stream = (string)$ent['stream'];
        $tasks[$ekey] = [];
        foreach ($ent['subjects'] as $sid => $n) {
            $a = tt_assignment_for($assignMap, $cid, (int)$sid, $stream);
            $info = $subjectInfo[(int)$sid] ?? ['name' => 'SUBJECT', 'abbr' => ''];
            $tasks[$ekey][(int)$sid] = [
                'sid' => (int)$sid,
                'name' => (string)$info['name'],
                'abbr' => (string)($info['abbr'] ?? ''),
                'teacher_id' => $a ? (int)$a['teacher_id'] : 0,
                'teacher' => $a ? (string)$a['full_name'] : '',
                'hard' => tta_is_core_subject((string)$info['name'], (string)($info['abbr'] ?? '')),
                'left' => (int)$n,
                // Vipindi double: jozi = intdiv(n,2), kilichobaki ni single.
                // Mf. vipindi 3 => double 1 + single 1; vipindi 5 => double 2 + single 1.
                'dbl' => intdiv((int)$n, 2),
                'day' => [],
            ];
        }
    }

    $busy = [];    // [day][period][teacher_id]
    $used = [];    // [entity][day][period]
    $last = [];    // [entity][day] last subject
    $entries = [];
    $assigned = 0;

    $place = function ($ekey, $sid, $day, $slot) use (&$tasks, &$busy, &$used, &$last, &$entries, &$assigned, $need) {
        $ent = $need[$ekey];
        $t = $tasks[$ekey][$sid];
        $entries[] = [
            'class_id' => (int)$ent['class_id'],
            'subject_id' => (int)$sid,
            'stream' => (string)$ent['stream'],
            'teacher_id' => (int)$t['teacher_id'],
            'day' => $day,
            'period_no' => (int)$slot['no'],
            'start' => $slot['start_text'],
            'end' => $slot['end_text'],
        ];
        $tasks[$ekey][$sid]['left']--;
        $tasks[$ekey][$sid]['day'][$day] = ($tasks[$ekey][$sid]['day'][$day] ?? 0) + 1;
        if ($t['teacher_id']) $busy[$day][$slot['no']][(int)$t['teacher_id']] = true;
        $used[$ekey][$day][$slot['no']] = true;
        $last[$ekey][$day] = $sid;
        $assigned++;
    };

    $entityOrder = array_keys($need);
    shuffle($entityOrder);

    /* ---- Pass 0: vipindi double (masomo mawili mfululizo bila mapumziko) ---- */
    foreach ($days as $day) {
        $slotsOfDay = $daySlots[$day];
        for ($i = 0; $i + 1 < count($slotsOfDay); $i++) {
            if ((int)$slotsOfDay[$i]['end'] !== (int)$slotsOfDay[$i + 1]['start']) continue;
            $s1 = $slotsOfDay[$i];
            $s2 = $slotsOfDay[$i + 1];
            foreach ($entityOrder as $ekey) {
                if (($entityDayCount[$ekey][$day] ?? 0) + 2 > ($entityQuota[$ekey][$day] ?? 0)) continue;
                if (!empty($used[$ekey][$day][(int)$s1['no']]) || !empty($used[$ekey][$day][(int)$s2['no']])) continue;

                $pick = null;
                $bestScore = null;
                $previous = $last[$ekey][$day] ?? 0;
                foreach ($tasks[$ekey] as $sid => $t) {
                    if ($t['dbl'] <= 0 || $t['left'] < 2) continue;
                    $tid = (int)$t['teacher_id'];
                    if ($tid && (!empty($busy[$day][(int)$s1['no']][$tid]) || !empty($busy[$day][(int)$s2['no']][$tid]))) continue;
                    $perDay = $t['day'][$day] ?? 0;
                    if ($perDay + 2 > $maxPerDay) continue;

                    $score = [$perDay, $sid === $previous ? 1 : 0, -$t['left'], mt_rand()];
                    if ($bestScore === null || $score < $bestScore) { $pick = $sid; $bestScore = $score; }
                }
                if ($pick === null) continue;

                $place($ekey, $pick, $day, $s1);
                $place($ekey, $pick, $day, $s2);
                $tasks[$ekey][$pick]['dbl']--;
                $entityDayCount[$ekey][$day] += 2;
            }
        }
    }

    /* ---- Pass 1: fill the week slot by slot ---- */
    foreach ($week as $row) {
        $day = $row['day'];
        $slot = $row['slot'];
        $ahead = (int)($morningAhead[$day][$slot['no']] ?? 0);

        foreach ($entityOrder as $ekey) {
            if (!empty($used[$ekey][$day][$slot['no']])) continue;
            // Ruka kama siku hii imeshajaza fungu lake la vipindi.
            if (($entityDayCount[$ekey][$day] ?? 0) >= ($entityQuota[$ekey][$day] ?? 0)) continue;

            $hardLeft = 0;
            foreach ($tasks[$ekey] as $t) if ($t['hard'] && $t['left'] > 0) $hardLeft += $t['left'];
            // Core subjects win the morning; in the afternoon they only win when
            // the mornings left in the week cannot hold what is still unscheduled.
            $coreFirst = $row['morning'] || ($hardLeft > $ahead);
            $previous = $last[$ekey][$day] ?? 0;

            $pick = null;
            $bestScore = null;
            foreach ($tasks[$ekey] as $sid => $t) {
                if ($t['left'] <= 0) continue;
                if ($t['teacher_id'] && !empty($busy[$day][$slot['no']][(int)$t['teacher_id']])) continue;
                $perDay = $t['day'][$day] ?? 0;
                if ($perDay >= $maxPerDay) continue;

                $score = [
                    $coreFirst ? ($t['hard'] ? 0 : 1) : ($t['hard'] ? 1 : 0), // subject class for this time of day
                    ($t['left'] - 2 * $t['dbl']) > 0 ? 0 : 1,                   // single iliyotengwa kwanza
                    $perDay,                                                  // spread over the week
                    $sid === $previous ? 1 : 0,                                // no back-to-back repeat
                    -$t['left'],                                               // biggest need first
                    mt_rand(),
                ];
                if ($bestScore === null || $score < $bestScore) { $pick = $sid; $bestScore = $score; }
            }
            if ($pick === null) continue; // keep the period free instead of breaking a rule
            $singles = $tasks[$ekey][$pick]['left'] - 2 * $tasks[$ekey][$pick]['dbl'];
            $place($ekey, $pick, $day, $slot);
            if ($singles <= 0) $tasks[$ekey][$pick]['dbl']--; // double imevunjwa kuwa single
            $entityDayCount[$ekey][$day]++;
        }
    }

    /* ---- Pass 2: repair, place anything that is still unscheduled ---- */
    foreach (array_keys($tasks) as $ekey) {
        $order = array_keys($tasks[$ekey]);
        usort($order, function ($x, $y) use ($tasks, $ekey) {
            $hx = $tasks[$ekey][$x]['hard'] ? 0 : 1;
            $hy = $tasks[$ekey][$y]['hard'] ? 0 : 1;
            if ($hx !== $hy) return $hx <=> $hy;
            return $tasks[$ekey][$y]['left'] <=> $tasks[$ekey][$x]['left'];
        });

        foreach ($order as $sid) {
            $guard = 0;
            while ($tasks[$ekey][$sid]['left'] > 0 && $guard++ < 200) {
                $placed = false;
                $hard = $tasks[$ekey][$sid]['hard'];
                // Morning slots first for core subjects, then anywhere.
                for ($pass = 0; $pass < 2 && !$placed; $pass++) {
                    foreach ($week as $row) {
                        $day = $row['day'];
                        $slot = $row['slot'];
                        if ($pass === 0 && $hard && !$row['morning']) continue;
                        if (!empty($used[$ekey][$day][$slot['no']])) continue;
                        if (($tasks[$ekey][$sid]['day'][$day] ?? 0) >= $maxPerDay) continue;
                        $tid = (int)$tasks[$ekey][$sid]['teacher_id'];
                        if ($tid && !empty($busy[$day][$slot['no']][$tid])) continue;
                        $place($ekey, $sid, $day, $slot);
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) break;
            }
        }
    }

    $required = 0;
    foreach ($need as $ent) foreach ($ent['subjects'] as $n) $required += (int)$n;

    // Vipindi vya remedial: hujaza nafasi zilizobaki tu, mchanganyiko wa science na art.
    $occupied = [];
    foreach ($entries as $e) {
        $occupied[$e['day']][(int)$e['period_no']][(int)$e['class_id'] . '|' . (string)$e['stream']] = true;
    }
    $entries = array_merge($entries, tt_plan_remedial([
        'days' => $days,
        'slots' => $data['slots'],
        'breaks' => $data['breaks'] ?? [],
        'events' => $data['events'] ?? [],
    ], $need, $assignMap, $subjectInfo, $busy, $occupied));

    return [
        'entries' => $entries,
        'assigned' => $assigned,
        'required' => $required,
        'unfilled' => max(0, $required - $assigned),
    ];
}

/**
 * Generate a timetable, keeping the best of several randomised attempts.
 *
 * @param array $opts ['max_per_day' => 2, 'morning_cutoff' => minutes, 'attempts' => 60]
 * @return array{ok:bool, entries:array, assigned:int, required:int, unfilled:int, errors:array}
 */
function tta_generate(array $data, array $assignMap = [], array $opts = []) {
    $maxPerDay = max(1, (int)($opts['max_per_day'] ?? TTA_MAX_PERIODS_PER_SUBJECT_PER_DAY));
    $morningCutoff = (int)($opts['morning_cutoff'] ?? (12 * 60 + 30));
    $attempts = max(1, min(200, (int)($opts['attempts'] ?? 60)));

    $classes = $data['classes'] ?? [];
    $need = [];
    foreach (($data['requirements'] ?? []) as $r) {
        $cid = (int)$r['class_id'];
        $sid = (int)$r['subject_id'];
        $n = (int)$r['periods_per_week'];
        $stream = trim((string)($r['stream'] ?? ''));
        if ($n <= 0 || !isset($classes[$cid])) continue;
        $ekey = $cid . '|' . $stream;
        if (!isset($need[$ekey])) $need[$ekey] = ['class_id' => $cid, 'stream' => $stream, 'subjects' => []];
        $need[$ekey]['subjects'][$sid] = ($need[$ekey]['subjects'][$sid] ?? 0) + $n;
    }
    if (!$need) {
        return ['ok' => false, 'entries' => [], 'assigned' => 0, 'required' => 0, 'unfilled' => 0,
                'errors' => ['There are no periods to schedule.']];
    }

    $sids = [];
    foreach ($need as $ent) foreach (array_keys($ent['subjects']) as $sid) $sids[(int)$sid] = true;
    $subjectInfo = [];
    $ids = array_keys($sids);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (q_all("SELECT id, name, abbr FROM subjects WHERE id IN ($in)", $ids) as $s) {
            $subjectInfo[(int)$s['id']] = $s;
        }
    }

    if (!$assignMap) $assignMap = teaching_assignments_map(active_school_id());

    $best = null;
    // Mbegu ya random kwa kila ubunifu ili ratiba itofautiane kila mara.
    $seedBase = random_int(1000, 999999);
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        mt_srand($seedBase + $attempt * 7919);
        $result = tta_attempt($data, $assignMap, $need, $subjectInfo, $maxPerDay, $morningCutoff);
        if ($best === null || $result['unfilled'] < $best['unfilled']) $best = $result;
        if ($result['unfilled'] <= 0) { $best = $result; break; }
    }

    if (!$best || $best['unfilled'] > 0) {
        return [
            'ok' => false,
            'entries' => $best['entries'] ?? [],
            'assigned' => (int)($best['assigned'] ?? 0),
            'required' => (int)($best['required'] ?? 0),
            'unfilled' => (int)($best['unfilled'] ?? 0),
            'errors' => [
                'Only ' . (int)($best['assigned'] ?? 0) . ' of ' . (int)($best['required'] ?? 0) . ' periods could be scheduled.',
                'Reduce the required periods, add sessions per day, or include Saturday, then generate again.',
            ],
        ];
    }

    return [
        'ok' => true,
        'entries' => $best['entries'],
        'assigned' => (int)$best['assigned'],
        'required' => (int)$best['required'],
        'unfilled' => 0,
        'errors' => [],
    ];
}

/* ---------------- Rendering ---------------- */

/** Minute at which the afternoon starts (the start of the lunch break). */
function tta_afternoon_cutoff(array $breaks) {
    foreach ($breaks as $b) {
        if (stripos((string)$b['name'], 'lunch') !== false) return (int)$b['start_min'];
    }
    return 12 * 60 + 30;
}

function tta_subject_map(array $entries) {
    $ids = [];
    foreach ($entries as $e) $ids[(int)$e['subject_id']] = true;
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT id, name, abbr FROM subjects WHERE id IN ($in)", array_keys($ids)) as $s) {
        $out[(int)$s['id']] = ['name' => (string)$s['name'], 'abbr' => (string)($s['abbr'] ?? '')];
    }
    return $out;
}

/** Full names of the teachers used by a set of entries (id => name). */
function tta_teacher_map(array $entries) {
    $ids = [];
    foreach ($entries as $e) if (!empty($e['teacher_id'])) $ids[(int)$e['teacher_id']] = true;
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (q_all("SELECT id, full_name FROM users WHERE id IN ($in)", array_keys($ids)) as $u) {
        $out[(int)$u['id']] = (string)$u['full_name'];
    }
    return $out;
}

/**
 * Render one weekly table for a class + stream.
 *
 * @return array{html:string, filled:int, free:int, core_morning:int, core_total:int}
 */
function tta_render_week(array $tt, $class_id, $stream, array $subjectMap, array $teacherMap = []) {
    $config = tt_config_from_row($tt);
    $days = $config['days'];
    $items = $config['slots']['items'] ?? [];
    $cutoff = tta_afternoon_cutoff($config['breaks']);

    $map = [];
    foreach (($tt['entries'] ?? []) as $e) {
        if ((int)$e['class_id'] !== (int)$class_id) continue;
        if (trim((string)($e['stream'] ?? '')) !== trim((string)$stream)) continue;
        $map[$e['day']][(int)$e['period_no']] = $e;
    }

    $filled = 0;
    $free = 0;
    $coreMorning = 0;
    $coreTotal = 0;

    $html = '<div class="tta-wrap"><table class="tta-table table table-bordered align-middle mb-0">';
    $html .= '<thead><tr><th class="tta-slot">Period</th>';
    foreach ($days as $day) $html .= '<th class="tta-day">' . h(tt_day_label($day)) . '</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($items as $it) {
        if (($it['type'] ?? '') === 'break') {
            $html .= '<tr class="tta-break"><td class="tta-slot">' . h($it['start_text'] . ' - ' . $it['end_text']) . '</td>'
                   . '<td colspan="' . count($days) . '"><i class="bi bi-cup-hot"></i> ' . h(strtoupper($it['name'])) . '</td></tr>';
            continue;
        }

        $html .= '<tr><td class="tta-slot"><b>' . h($it['start_text']) . '</b><span>' . h($it['end_text']) . '</span></td>';
        foreach ($days as $day) {
            $e = $map[$day][(int)$it['no']] ?? null;
            $html .= '<td class="tta-cell">';
            if (!$e) {
                $free++;
                $html .= '<span class="tta-empty">Free</span>';
            } else {
                $filled++;
                $info = $subjectMap[(int)$e['subject_id']] ?? ['name' => 'SUBJECT', 'abbr' => ''];
                $core = tta_is_core_subject($info['name'], $info['abbr']);
                $morning = (int)$it['start'] < $cutoff;
                if ($core) $coreTotal++;
                if ($core && $morning) $coreMorning++;

                $cls = 'tta-lesson';
                if ($core) $cls .= ' tta-core';
                if ($core && $morning) $cls .= ' tta-am';
                $html .= '<div class="' . $cls . '">';
                $html .= '<div class="tta-sub">' . h($info['name']) . '</div>';
                if (!empty($e['teacher_id'])) {
                    $short = teaching_short_name($teacherMap[(int)$e['teacher_id']] ?? '');
                    $html .= '<div class="tta-teacher">' . h($short !== '' ? $short : 'Teacher') . '</div>';
                }
                if ($core && $morning) $html .= '<span class="tta-badge">AM</span>';
                elseif ($core) $html .= '<span class="tta-badge pm">Core</span>';
                $html .= '</div>';
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return ['html' => $html, 'filled' => $filled, 'free' => $free, 'core_morning' => $coreMorning, 'core_total' => $coreTotal];
}

function tta_css() {
    return <<<'CSS'
.tta-wrap{overflow-x:auto;border:1px solid #e2e9f6;border-radius:14px;background:#fff}
.tta-table{margin:0;min-width:720px;font-size:.78rem}
.tta-table thead th{background:#f4f8ff;color:#3f5a80;font-size:.66rem;letter-spacing:.05em;text-transform:uppercase;font-weight:800;border-color:#e2e9f6!important;padding:.5rem .4rem;text-align:center}
.tta-table td{border-color:#eef2fa!important;padding:.32rem .3rem;vertical-align:middle}
.tta-slot{background:#fbfdff;text-align:center;width:86px;line-height:1.15}
.tta-slot b{display:block;color:#16203a;font-size:.76rem}
.tta-slot span{display:block;color:#8a99b0;font-size:.62rem}
.tta-day{min-width:112px}
.tta-cell{text-align:center}
.tta-lesson{position:relative;border-radius:8px;padding:.28rem .3rem;background:#f7faff;border:1px solid #e6edfa}
.tta-lesson.tta-core{background:#eef4fe;border-color:#cfdefb}
.tta-lesson.tta-am{background:#eaf3ff;border-color:#b9d3fa;box-shadow:inset 3px 0 0 #2c6ed5}
.tta-sub{font-weight:700;color:#16203a;line-height:1.15;font-size:.74rem}
.tta-teacher{color:#6b7a93;font-size:.64rem;margin-top:1px}
.tta-badge{position:absolute;top:2px;right:3px;font-size:.52rem;font-weight:900;letter-spacing:.04em;background:#2c6ed5;color:#fff;border-radius:999px;padding:0 4px;line-height:1.35}
.tta-badge.pm{background:#8a99b0}
.tta-empty{color:#c3cfe4;font-size:.66rem}
.tta-break td{background:#fff8e6;color:#8a5a00;font-weight:800;font-size:.68rem;letter-spacing:.05em;text-align:center;padding:.3rem}
.tta-break .tta-slot{background:#fff3d6;color:#8a5a00}
@media print{
  @page{size:A4 landscape;margin:8mm}
  .tta-table{min-width:0;font-size:.62rem}
  .tta-table thead th,.tta-lesson{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .tta-break td{background:#fff3d6!important}
}
CSS;
}

function tta_page_css() {
    return <<<'CSS'
.tta-hero{position:relative;overflow:hidden;border-radius:20px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);margin-bottom:18px;
  box-shadow:0 16px 34px -24px rgba(11,61,145,.95)}
.tta-hero:before,.tta-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.tta-hero:before{width:250px;height:250px;right:-70px;top:-130px;background:rgba(255,255,255,.10)}
.tta-hero:after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.tta-hero-in{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.tta-ic{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.26);flex:none}
.tta-kicker{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:#ffd764;display:flex;align-items:center;gap:6px}
.tta-hero h1{font-size:1.4rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2;color:#fff}
.tta-hero p{margin:0;font-size:.81rem;color:rgba(255,255,255,.84)}
.tta-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.tta-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.tta-btn:hover{transform:translateY(-1px)}
.tta-btn.solid{background:#fff;color:#0b3d91}
.tta-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.tta-card{background:#fff;border:1px solid #e6ecf8;border-radius:16px;overflow:hidden;box-shadow:0 2px 14px rgba(11,61,145,.06);margin-bottom:16px}
.tta-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid #eef2fa;background:linear-gradient(180deg,#fbfdff,#fff)}
.tta-card-t{font-weight:800;color:#16203a;font-size:.92rem;display:flex;align-items:center;gap:8px}
.tta-card-t i{color:#2c6ed5}
.tta-card-b{padding:16px}
.tta-stat{background:#fff;border:1px solid #e6ecf8;border-radius:13px;padding:11px 13px;display:flex;align-items:center;gap:10px;height:100%}
.tta-stat .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eef3fd;color:#0b3d91;flex:none}
.tta-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.tta-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.tta-stat.o .ico{background:#fdf1dc;color:#b06a00}
.tta-stat .v{font-size:1.15rem;font-weight:800;line-height:1.05;color:#16203a}
.tta-stat .l{font-size:.62rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.tta-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid #dbe6fb;background:#f4f8ff;color:#0b3d91;border-radius:999px;padding:2px 9px;font-size:.68rem;font-weight:800}
.tta-stream-head{display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between;margin:2px 0 8px}
.tta-stream-name{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.tta-stream-name .dot{width:9px;height:9px;border-radius:50%;background:#2c6ed5;display:inline-block}
.tta-note{font-size:.72rem;color:#8a99b0}
.tta-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:.72rem;color:#5b6b85;align-items:center}
.tta-legend .sw{width:14px;height:14px;border-radius:4px;display:inline-block;border:1px solid #cfdefb;margin-right:5px;vertical-align:-2px}
@media (max-width:575.98px){.tta-hero{padding:18px}.tta-hero h1{font-size:1.15rem}}
@media print{.tta-hero,.tta-actions,.tta-card-h,.tta-note,.tta-legend,.d-print-none{display:none!important}
  .tta-card{border:0;box-shadow:none;margin:0 0 6mm}.tta-card-b{padding:0}
  .tta-stream-name{font-size:.9rem}}
CSS;
}
