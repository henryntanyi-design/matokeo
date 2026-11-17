<?php
/**
 * Ratiba za kufundisha zilizohifadhiwa (muundo wa MSSIS).
 *
 * Huhifadhi ratiba kwenye `timetables`, mahitaji ya vipindi kwenye
 * `timetable_requirements`, na vipindi halisi kwenye `timetable_entries`.
 */
require_once __DIR__ . '/teaching.php';

/* ---------------- Misingi ---------------- */

function tt_tables_ready() {
    static $ok = null;
    if ($ok === null) {
        try {
            q_val('SELECT 1 FROM timetables LIMIT 1');
            q_val('SELECT 1 FROM timetable_requirements LIMIT 1');
            q_val('SELECT 1 FROM timetable_entries LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Je, safu ya `stream` kwenye majedwali ya ratiba imesakinishwa? */
function tt_streams_installed() {
    static $ok = null;
    if ($ok === null) {
        try {
            q_val('SELECT stream FROM timetable_entries LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Safisha orodha ya streams kutoka text ("A, B") au array.
 * Hurudisha orodha ya majina yaliyotenganishwa, bila marudio.
 */
function tt_normalize_streams($raw) {
    if (is_string($raw)) $raw = preg_split('/[,;|]+/', $raw);
    if (!is_array($raw)) return [];
    $out = [];
    $seen = [];
    foreach ($raw as $s) {
        if (!is_scalar($s)) continue;
        $s = trim(preg_replace('/\s+/', ' ', (string)$s));
        $s = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', $s));
        if ($s === '') continue;
        if (mb_strlen($s) > 20) $s = mb_substr($s, 0, 20);
        $k = strtolower($s);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $s;
        if (count($out) >= 12) break;
    }
    return $out;
}

/** Jina la darasa pamoja na stream, mf. "Form I A". */
function tt_entity_name($class_name, $stream) {
    $class_name = trim((string)$class_name);
    $stream = trim((string)$stream);
    return $stream === '' ? $class_name : trim($class_name . ' ' . $stream);
}

/** Mwalimu kutoka ramani ya ugawaji: stream maalum kwanza, kisha darasa zima. */
function tt_assignment_for(array $map, $class_id, $subject_id, $stream = '') {
    $cid = (int)$class_id;
    $sid = (int)$subject_id;
    $stream = trim((string)$stream);
    $k = $cid . ':' . $sid . ':' . $stream;
    if (isset($map[$k])) return $map[$k];
    if ($stream === '' && isset($map[$cid . ':' . $sid])) return $map[$cid . ':' . $sid];
    return null;
}

function tt_groups() {
    return [
        'ordinary' => 'Ordinary (O-Level)',
        'advance'  => 'Advanced (A-Level)',
        'all'      => 'All Levels',
    ];
}

function tt_group_label($group) {
    $g = tt_groups();
    return $g[$group] ?? (string)$group;
}

function tt_group_levels($group) {
    if ($group === 'ordinary') return ['O'];
    if ($group === 'advance') return ['A'];
    return ['O', 'A'];
}

/** Madarasa ya kundi lililochaguliwa. */
function tt_classes_for_group($group) {
    $levels = tt_group_levels($group);
    $in = implode(',', array_fill(0, count($levels), '?'));
    return q_all("SELECT * FROM classes WHERE level IN ($in) ORDER BY sort_order, id", $levels);
}

/** Siku za ratiba kulingana na chaguo za Jumamosi/Jumapili. */
function tt_days($include_saturday, $include_sunday) {
    $days = ['MON', 'TUE', 'WED', 'THU', 'FRI'];
    if ($include_saturday) $days[] = 'SAT';
    if ($include_sunday) $days[] = 'SUN';
    return $days;
}

function tt_day_labels() {
    return [
        'MON' => 'MONDAY', 'TUE' => 'TUESDAY', 'WED' => 'WEDNESDAY',
        'THU' => 'THURSDAY', 'FRI' => 'FRIDAY', 'SAT' => 'SATURDAY', 'SUN' => 'SUNDAY',
    ];
}

function tt_day_code($v) {
    $v = strtoupper(trim((string)$v));
    $map = [
        'MON' => 'MON', 'MONDAY' => 'MON', 'JUMATATU' => 'MON',
        'TUE' => 'TUE', 'TUESDAY' => 'TUE', 'JUMANNE' => 'TUE',
        'WED' => 'WED', 'WEDNESDAY' => 'WED', 'JUMATANO' => 'WED',
        'THU' => 'THU', 'THURSDAY' => 'THU', 'ALHAMISI' => 'THU',
        'FRI' => 'FRI', 'FRIDAY' => 'FRI', 'IJUMAA' => 'FRI',
        'SAT' => 'SAT', 'SATURDAY' => 'SAT', 'JUMAMOSI' => 'SAT',
        'SUN' => 'SUN', 'SUNDAY' => 'SUN', 'JUMAPILI' => 'SUN',
    ];
    return $map[$v] ?? '';
}

function tt_day_label($code) {
    $l = tt_day_labels();
    return $l[strtoupper((string)$code)] ?? strtoupper((string)$code);
}

/* ---------------- Usafishaji wa mapumziko na matukio ---------------- */

/**
 * Safisha mapumziko: rudisha ["name","start","end","start_min","end_min"].
 * Mistari isiyo na saa sahihi hupuuzwa.
 */
function tt_normalize_breaks(array $breaks) {
    $out = [];
    $seen = [];
    foreach ($breaks as $b) {
        if (!is_array($b)) continue;
        $name = trim((string)($b['name'] ?? ''));
        $s = teaching_time_minutes($b['start'] ?? '');
        $e = teaching_time_minutes($b['end'] ?? '');
        if ($s === null || $e === null || $e <= $s) continue;
        $k = $s . '-' . $e;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = [
            'name' => $name !== '' ? $name : 'BREAK',
            'start' => teaching_minutes_time($s),
            'end' => teaching_minutes_time($e),
            'start_min' => $s,
            'end_min' => $e,
        ];
    }
    usort($out, function ($a, $b) { return $a['start_min'] <=> $b['start_min']; });
    return $out;
}

/**
 * Safisha matukio: rudisha ["name","day","start","end","start_min","end_min"].
 * Matukio ya siku zisizojumuishwa hupuuzwa.
 */
function tt_normalize_events(array $events, array $days) {
    $out = [];
    $seen = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $name = trim((string)($ev['name'] ?? ''));
        $day = tt_day_code($ev['day'] ?? '');
        $s = teaching_time_minutes($ev['start'] ?? '');
        $e = teaching_time_minutes($ev['end'] ?? '');
        if ($name === '' || $day === '' || $s === null || $e === null || $e <= $s) continue;
        if (!in_array($day, $days, true)) continue;
        $k = $day . '|' . $s . '-' . $e;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = [
            'name' => $name,
            'day' => $day,
            'start' => teaching_minutes_time($s),
            'end' => teaching_minutes_time($e),
            'start_min' => $s,
            'end_min' => $e,
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['start_min'] !== $b['start_min']) return $a['start_min'] <=> $b['start_min'];
        return strcmp($a['day'], $b['day']);
    });
    return $out;
}

/** Ondoa sehemu za ndani (start_min/end_min) kabla ya kuhifadhi JSON. */
function tt_plain_breaks(array $breaks) {
    $out = [];
    foreach ($breaks as $b) $out[] = ['name' => $b['name'], 'start' => $b['start'], 'end' => $b['end']];
    return $out;
}

function tt_plain_events(array $events) {
    $out = [];
    foreach ($events as $e) $out[] = ['name' => $e['name'], 'day' => $e['day'], 'start' => $e['start'], 'end' => $e['end']];
    return $out;
}

/* ---------------- Mipangilio ya ratiba ---------------- */

function tt_config_from_row(array $tt) {
    $sat = !empty($tt['include_saturday']);
    $sun = !empty($tt['include_sunday']);
    $days = tt_days($sat, $sun);
    $breaks = tt_normalize_breaks(json_decode((string)($tt['breaks_json'] ?? '[]'), true) ?: []);
    $events = tt_normalize_events(json_decode((string)($tt['events_json'] ?? '[]'), true) ?: [], $days);
    $slots = teaching_timetable_timeslots(
        (int)($tt['sessions_per_day'] ?? 11),
        (int)($tt['session_duration'] ?? 40),
        (string)($tt['start_time'] ?? '08:00'),
        $breaks
    );
    return [
        'days' => $days,
        'breaks' => $breaks,
        'events' => $events,
        'slots' => $slots,
        'include_saturday' => $sat,
        'include_sunday' => $sun,
    ];
}

/** Idadi ya nafasi za vipindi kwa wiki (bila kujumuisha saa za matukio). */
function tt_capacity(array $config) {
    $sessions = [];
    foreach (($config['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'session') $sessions[] = $it;
    }
    $n = 0;
    foreach ($config['days'] as $day) {
        foreach ($sessions as $s) {
            $blocked = false;
            foreach ($config['events'] as $ev) {
                if ($ev['day'] === $day && $ev['start_min'] < $s['end'] && $ev['end_min'] > $s['start']) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) $n++;
        }
    }
    return $n;
}

/**
 * Namba za vipindi vya remedial: vipindi vinavyoanza saa ya lunch inapoisha.
 * Vipindi vya lazima huishia kwenye lunch (mf. 14:30); kila kipindi baada
 * yake ni cha remedial.
 *
 * @return array period_no => true (tupu kama hakuna mapumziko ya lunch)
 */
function tt_remedial_nos(array $config) {
    $cut = 0;
    foreach (($config['breaks'] ?? []) as $b) {
        if (stripos((string)($b['name'] ?? ''), 'lunch') !== false) {
            $cut = max($cut, (int)($b['end_min'] ?? 0));
        }
    }
    if ($cut <= 0) return []; // hakuna lunch: hakuna vipindi vya remedial

    $nos = [];
    foreach (($config['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'session' && (int)$it['start'] >= $cut) $nos[(int)$it['no']] = true;
    }
    return $nos;
}

/** Idadi ya nafasi za vipindi vya lazima (bila remedial na bila matukio). */
function tt_compulsory_capacity(array $config) {
    $nos = tt_remedial_nos($config);
    if (!$nos) return tt_capacity($config);

    $filtered = $config;
    $items = [];
    foreach (($config['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'session' && isset($nos[(int)$it['no']])) continue;
        $items[] = $it;
    }
    $filtered['slots']['items'] = $items;
    return tt_capacity($filtered);
}

/**
 * Mgawanyo wa vipindi vya lazima kwa siku za wiki.
 *
 * Kila siku hupata fungu la vipindi kulingana na nafasi zilizopo (mapumziko
 * na matukio yametolewa), ili siku za mwanzo zisichukue vipindi vyote na
 * siku za mwisho (mf. Ijumaa) kubaki bila vipindi.
 *
 * @param array $days     Siku za wiki kwa mpangilio
 * @param array $daySlots day => orodha ya vipindi vinavyopatikana
 * @param int   $required Idadi ya vipindi vinavyohitajika kwa wiki
 * @return array day => fungu (jumla yake ni $required)
 */
function tt_day_quotas(array $days, array $daySlots, $required) {
    $required = max(0, (int)$required);
    $usable = [];
    $total = 0;
    foreach ($days as $day) {
        $usable[$day] = count($daySlots[$day] ?? []);
        $total += $usable[$day];
    }
    $quotas = [];
    foreach ($days as $day) $quotas[$day] = 0;
    if ($required <= 0 || $total <= 0) return $quotas;
    if ($required > $total) $required = $total;

    // Gawanya kwa uwiano wa nafasi, kisha sambaza mabaki kwa siku zenye nafasi.
    $rem = $required;
    $frac = [];
    foreach ($days as $day) {
        $exact = $required * $usable[$day] / $total;
        $quotas[$day] = min($usable[$day], (int)floor($exact));
        $frac[$day] = $exact - floor($exact);
        $rem -= $quotas[$day];
    }
    while ($rem > 0) {
        $best = null;
        $bestFrac = -1.0;
        foreach ($days as $day) {
            if ($quotas[$day] >= $usable[$day]) continue;
            if ($frac[$day] > $bestFrac) {
                $bestFrac = $frac[$day];
                $best = $day;
            }
        }
        if ($best === null) break;
        $quotas[$best]++;
        $frac[$best] = -1.0; // siku moja ipate nyongeza moja pekee kwa mzunguko
        $rem--;
    }
    return $quotas;
}

/** Familia ya somo: "science" au "art" (kwa mchanganyiko wa remedial). */
function tt_subject_family($name, $abbr = '') {
    $s = strtolower(trim((string)$name . ' ' . (string)$abbr));
    $science = ['physics', 'chemistry', 'biology', 'mathematics', 'basic math', 'agriculture',
                'computer', 'science', 'fizikia', 'kemia', 'biolojia', 'hisabati', 'kilimo'];
    foreach ($science as $k) {
        if (strpos($s, $k) !== false) return 'science';
    }
    return 'art';
}

/**
 * Panga vipindi vya remedial baada ya lunch.
 *
 * Kila siku kipindi cha kwanza kinapewa somo la science na cha pili somo la
 * art (vinabadilishana), na somo lenye remedial chache huchaguliwa kwanza,
 * hivyo kila somo linapata zamu ya remedial. Mwalimu aliye na kipindi kingine
 * kwa wakati huo harukwi.
 *
 * @param array $config     ['days','slots','breaks','events']
 * @param array $need       [ekey => ['class_id','stream','subjects'=>[sid=>n]]]
 * @param array $assignMap  Ramani ya ugawaji wa walimu
 * @param array $subjectInfo [sid => ['name','abbr']]
 * @param array $busy       [day][period][teacher_id] => true (vipindi vya lazima)
 * @return array entries
 */
function tt_plan_remedial(array $config, array $need, array $assignMap, array $subjectInfo, array $busy = []) {
    $nos = tt_remedial_nos($config);
    if (!$nos || !$need) return [];

    $slots = [];
    foreach (($config['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'session' && isset($nos[(int)$it['no']])) $slots[] = $it;
    }
    if (!$slots) return [];

    $days = $config['days'] ?? [];
    $events = $config['events'] ?? [];
    $entries = [];

    foreach ($need as $ent) {
        $cid = (int)$ent['class_id'];
        $stream = (string)$ent['stream'];
        $pools = ['science' => [], 'art' => []];
        $given = [];
        foreach (array_keys($ent['subjects']) as $sid) {
            $sid = (int)$sid;
            $info = $subjectInfo[$sid] ?? ['name' => '', 'abbr' => ''];
            $pools[tt_subject_family((string)($info['name'] ?? ''), (string)($info['abbr'] ?? ''))][] = $sid;
            $given[$sid] = 0;
        }
        // Mpangilio wa random ili kila ratiba mpya igawie remedial tofauti.
        shuffle($pools['science']);
        shuffle($pools['art']);

        foreach ($days as $day) {
            // Kila siku inaweza kuanza na science au art (kisha vinabadilishana).
            $k = mt_rand(0, 1);
            $today = [];
            foreach ($slots as $slot) {
                $no = (int)$slot['no'];

                // Ruka kipindi kilichofunikwa na tukio la siku hiyo.
                $covered = false;
                foreach ($events as $ev) {
                    if ($ev['day'] === $day && $ev['start_min'] < $slot['end'] && $ev['end_min'] > $slot['start']) {
                        $covered = true;
                        break;
                    }
                }
                if ($covered) continue;

                $order = ($k % 2 === 0) ? ['science', 'art'] : ['art', 'science'];
                $pick = 0;
                foreach ($order as $family) {
                    $bestScore = null;
                    foreach ($pools[$family] as $sid) {
                        if (!empty($today[$sid])) continue; // somo moja kwa siku
                        $a = tt_assignment_for($assignMap, $cid, $sid, $stream);
                        $tid = $a ? (int)$a['teacher_id'] : 0;
                        if ($tid && !empty($busy[$day][$no][$tid])) continue;
                        $score = [$given[$sid], mt_rand()];
                        if ($bestScore === null || $score < $bestScore) {
                            $pick = $sid;
                            $bestScore = $score;
                        }
                    }
                    if ($pick) break;
                }
                if (!$pick) { $k++; continue; }

                $a = tt_assignment_for($assignMap, $cid, $pick, $stream);
                $tid = $a ? (int)$a['teacher_id'] : 0;
                $entries[] = [
                    'class_id' => $cid,
                    'subject_id' => $pick,
                    'stream' => $stream,
                    'teacher_id' => $tid,
                    'day' => $day,
                    'period_no' => $no,
                    'start' => (string)$slot['start_text'],
                    'end' => (string)$slot['end_text'],
                ];
                $given[$pick] = ($given[$pick] ?? 0) + 1;
                $today[$pick] = true;
                if ($tid) $busy[$day][$no][$tid] = true;
                $k++;
            }
        }
    }
    return $entries;
}

/**
 * Hesabu migongano iliyobaki kwenye entries: mwalimu mmoja vipindi viwili kwa
 * wakati mmoja, au stream moja masomo mawili kwa wakati mmoja.
 *
 * @return array{teacher:int, stream:int}
 */
function tt_clash_report(array $entries) {
    $seenTeacher = [];
    $seenStream = [];
    $teacher = 0;
    $stream = 0;
    foreach ($entries as $e) {
        $day = (string)($e['day'] ?? '');
        $period = (int)($e['period_no'] ?? 0);
        if ($day === '' || $period <= 0) continue;

        $tid = (int)($e['teacher_id'] ?? 0);
        if ($tid > 0) {
            $k = $day . '|' . $period . '|' . $tid;
            if (isset($seenTeacher[$k])) $teacher++; else $seenTeacher[$k] = true;
        }

        $sk = $day . '|' . $period . '|' . (int)($e['class_id'] ?? 0) . '|' . trim((string)($e['stream'] ?? ''));
        if (isset($seenStream[$sk])) $stream++; else $seenStream[$sk] = true;
    }
    return ['teacher' => $teacher, 'stream' => $stream];
}

/**
 * Walimu wanaohitajika vipindi vingi kuliko nafasi zilizopo kwa wiki.
 * Mwalimu hawezi kufundisha zaidi ya nafasi moja kwa wakati mmoja, hivyo
 * mahitaji kuzidi nafasi ya wiki ni mgongano wa kudumu.
 *
 * @return array<int,array{name:string,periods:int}>
 */
function tt_teacher_load(array $requirements, array $assignMap, $capacity) {
    $capacity = (int)$capacity;
    if ($capacity <= 0) return [];

    $load = [];
    foreach ($requirements as $r) {
        $n = (int)($r['periods_per_week'] ?? 0);
        if ($n <= 0) continue;
        $a = tt_assignment_for($assignMap, (int)($r['class_id'] ?? 0), (int)($r['subject_id'] ?? 0), (string)($r['stream'] ?? ''));
        if (!$a) continue;
        $tid = (int)$a['teacher_id'];
        if (!isset($load[$tid])) $load[$tid] = ['name' => (string)($a['full_name'] ?? 'Teacher'), 'periods' => 0];
        $load[$tid]['periods'] += $n;
    }

    $over = [];
    foreach ($load as $tid => $t) {
        if ($t['periods'] > $capacity) $over[$tid] = $t;
    }
    return $over;
}

/* ---------------- Hifadhi ---------------- */

function tt_load($id, $school_id = 0) {
    $sql = 'SELECT * FROM timetables WHERE id = ?';
    $p = [(int)$id];
    if ($school_id) { $sql .= ' AND school_id = ?'; $p[] = (int)$school_id; }
    $row = q_one($sql, $p);
    if (!$row) return null;
    $row['requirements'] = q_all('SELECT * FROM timetable_requirements WHERE timetable_id = ? ORDER BY class_id, subject_id', [(int)$row['id']]);
    $row['entries'] = q_all('SELECT * FROM timetable_entries WHERE timetable_id = ? ORDER BY day, period_no, class_id', [(int)$row['id']]);
    return $row;
}

/**
 * Pata ratiba ya karibuni kwa shule/mwaka/kundi/status.
 * $teacher_id > 0 huchuja ratiba zenye vipindi vya mwalimu huyo.
 */
function tt_find_latest($school_id, $year_id, $group, $status, $teacher_id = 0) {
    $sql = 'SELECT t.* FROM timetables t WHERE t.school_id = ? AND t.academic_year_id = ?';
    $p = [(int)$school_id, (int)$year_id];
    if ($group !== 'all') { $sql .= ' AND t.group_level = ?'; $p[] = $group; }
    if ($status !== 'all') { $sql .= ' AND t.status = ?'; $p[] = $status; }
    if ($teacher_id) {
        $sql .= ' AND EXISTS (SELECT 1 FROM timetable_entries e WHERE e.timetable_id = t.id AND e.teacher_id = ?)';
        $p[] = (int)$teacher_id;
    }
    $sql .= " ORDER BY (t.status = 'Active') DESC, t.published_at DESC, t.id DESC LIMIT 1";
    return q_one($sql, $p);
}

function tt_teacher_has($timetable_id, $teacher_id) {
    return (bool)q_val('SELECT 1 FROM timetable_entries WHERE timetable_id = ? AND teacher_id = ? LIMIT 1',
                       [(int)$timetable_id, (int)$teacher_id]);
}

/**
 * Hifadhi ratiba mpya pamoja na mahitaji na vipindi.
 * @return int id ya ratiba
 */
function tt_save($school_id, $year_id, array $data, array $requirements, array $entries, $created_by = 0) {
    $withStream = tt_streams_installed();
    db()->beginTransaction();
    try {
        q('INSERT INTO timetables
           (school_id, academic_year_id, group_level, status, sessions_per_day, session_duration,
            start_time, end_time, include_saturday, include_sunday, breaks_json, events_json, created_by, published_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
          [
            (int)$school_id, (int)$year_id, $data['group'], 'Pending',
            (int)$data['sessions_per_day'], (int)$data['session_duration'],
            $data['start_time'], $data['end_time'],
            !empty($data['include_saturday']) ? 1 : 0, !empty($data['include_sunday']) ? 1 : 0,
            json_encode(tt_plain_breaks($data['breaks']), JSON_UNESCAPED_UNICODE),
            json_encode(tt_plain_events($data['events']), JSON_UNESCAPED_UNICODE),
            $created_by ? (int)$created_by : null,
          ]);
        $id = (int)db()->lastInsertId();

        foreach ($requirements as $r) {
            if ((int)($r['periods_per_week'] ?? 0) <= 0) continue;
            if ($withStream) {
                q('INSERT INTO timetable_requirements (timetable_id, class_id, subject_id, stream, periods_per_week) VALUES (?, ?, ?, ?, ?)',
                  [$id, (int)$r['class_id'], (int)$r['subject_id'], (string)($r['stream'] ?? ''), (int)$r['periods_per_week']]);
            } else {
                q('INSERT INTO timetable_requirements (timetable_id, class_id, subject_id, periods_per_week) VALUES (?, ?, ?, ?)',
                  [$id, (int)$r['class_id'], (int)$r['subject_id'], (int)$r['periods_per_week']]);
            }
        }
        foreach ($entries as $e) {
            if ($withStream) {
                q('INSERT INTO timetable_entries
                   (timetable_id, class_id, subject_id, stream, teacher_id, day, period_no, start_time, end_time)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                  [$id, (int)$e['class_id'], (int)$e['subject_id'], (string)($e['stream'] ?? ''), ((int)$e['teacher_id']) ?: null,
                   $e['day'], (int)$e['period_no'], $e['start'], $e['end']]);
            } else {
                q('INSERT INTO timetable_entries
                   (timetable_id, class_id, subject_id, teacher_id, day, period_no, start_time, end_time)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                  [$id, (int)$e['class_id'], (int)$e['subject_id'], ((int)$e['teacher_id']) ?: null,
                   $e['day'], (int)$e['period_no'], $e['start'], $e['end']]);
            }
        }
        db()->commit();
        return $id;
    } catch (Exception $e) {
        db()->rollBack();
        throw $e;
    }
}

/** Chapisha ratiba: iwe Active, na ratiba nyingine za kundi hilo ziwe Archived. */
function tt_publish($id, $school_id) {
    $tt = q_one('SELECT * FROM timetables WHERE id = ? AND school_id = ?', [(int)$id, (int)$school_id]);
    if (!$tt) return false;
    db()->beginTransaction();
    try {
        q("UPDATE timetables SET status = 'Archived'
           WHERE school_id = ? AND academic_year_id = ? AND group_level = ? AND status = 'Active' AND id <> ?",
          [(int)$school_id, (int)$tt['academic_year_id'], $tt['group_level'], (int)$id]);
        q("UPDATE timetables SET status = 'Active', published_at = NOW() WHERE id = ? AND school_id = ?",
          [(int)$id, (int)$school_id]);
        db()->commit();
        return true;
    } catch (Exception $e) {
        db()->rollBack();
        throw $e;
    }
}

/* ---------------- Mahitaji ya awali ---------------- */

function tt_subject_weight(array $s) {
    $n = strtolower(trim((string)($s['name'] ?? '')));
    $a = strtoupper(trim((string)($s['abbr'] ?? '')));
    $core = [
        'basic mathematics' => 3.0, 'english language' => 3.0, 'kiswahili' => 3.0,
        'advanced mathematics' => 3.0, 'general studies' => 2.0,
    ];
    if (isset($core[$n])) return $core[$n];
    if (in_array($a, ['BIO', 'CHEM', 'PHY', 'B/MATH', 'ADV/MATHS', 'ENGL', 'KISW', 'GS', 'BAM'], true)) return 2.0;
    return 1.0;
}

/**
 * Mtaala rasmi: masomo na idadi ya vipindi kwa wiki.
 * 'lower' = Kidato cha I–II, 'upper' = Kidato cha III–IV.
 * Kila somo lina codes (code/abbr ya `subjects`) na names (majina mbadala)
 * ili defaults zifanane na majina yaliyowekwa shuleni.
 */
function tt_curriculum() {
    static $rows = null;
    if ($rows !== null) return $rows;
    $rows = [
        /* ---- Masomo ya msingi na lazima (hupata kipaumbele cha nafasi) ---- */
        'maths'      => ['name' => 'Mathematics', 'lower' => 5, 'upper' => 5, 'compulsory' => true, 'codes' => ['BMATH', 'B/MATH', 'MATH'], 'names' => ['mathematics', 'basic mathematics', 'hisabati']],
        'kiswahili'  => ['name' => 'Kiswahili', 'lower' => 4, 'upper' => 4, 'compulsory' => true, 'codes' => ['KISW'], 'names' => ['kiswahili']],
        'english'    => ['name' => 'English Language', 'lower' => 5, 'upper' => 5, 'compulsory' => true, 'codes' => ['ENGL'], 'names' => ['english language', 'english', 'kiingereza']],
        'business'   => ['name' => 'Business Studies', 'lower' => 3, 'upper' => 3, 'compulsory' => true, 'codes' => ['BUS', 'COMM'], 'names' => ['business studies', 'business', 'commerce', 'biashara']],
        'geography'  => ['name' => 'Geography', 'lower' => 2, 'upper' => 3, 'compulsory' => true, 'codes' => ['GEO'], 'names' => ['geography', 'jiografia']],
        'maadili'    => ['name' => 'Historia ya Tanzania na Maadili', 'lower' => 3, 'upper' => 3, 'compulsory' => true, 'codes' => ['CIV'], 'names' => ['historia ya tanzania na maadili', 'historia ya tanzania', 'civics', 'uraia', 'maadili']],

        /* ---- Masomo ya kuchagua (hugawana nafasi iliyobaki) ---- */
        'physics'    => ['name' => 'Physics', 'lower' => 3, 'upper' => 4, 'codes' => ['PHY'], 'names' => ['physics', 'fizikia']],
        'chemistry'  => ['name' => 'Chemistry', 'lower' => 3, 'upper' => 4, 'codes' => ['CHEM'], 'names' => ['chemistry', 'kemia']],
        'biology'    => ['name' => 'Biology', 'lower' => 3, 'upper' => 4, 'codes' => ['BIO'], 'names' => ['biology', 'biolojia']],
        'agriculture' => ['name' => 'Agriculture', 'lower' => 5, 'upper' => 5, 'codes' => ['AGRI', 'AGRIB'], 'names' => ['agriculture', 'kilimo']],
        'computer'   => ['name' => 'Computer Science', 'lower' => 4, 'upper' => 4, 'codes' => ['COMP', 'CS'], 'names' => ['computer science', 'computer studies', 'kompyuta']],
        'food'       => ['name' => 'Food and Human Nutrition', 'lower' => 5, 'upper' => 5, 'codes' => ['FOOD'], 'names' => ['food and human nutrition', 'food and nutrition', 'food science', 'lishe']],
        'sport'      => ['name' => 'Sport Studies', 'lower' => 4, 'upper' => 4, 'codes' => ['SPORT', 'PE'], 'names' => ['sport studies', 'sports studies', 'physical education', 'michezo']],
        'textile'    => ['name' => 'Textile and Garment Construction', 'lower' => 5, 'upper' => 5, 'codes' => ['TEXT', 'TGCL'], 'names' => ['textile and garment construction', 'textile', 'ushonaji']],
        'fineart'    => ['name' => 'Fine Art', 'lower' => 4, 'upper' => 4, 'codes' => ['ART', 'FART'], 'names' => ['fine art', 'sanaa']],
        'theatre'    => ['name' => 'Theatre Arts', 'lower' => 4, 'upper' => 4, 'codes' => ['THEATRE', 'THART'], 'names' => ['theatre arts', 'theater arts', 'theatre', 'sanaa za maonyesho']],
        'addmaths'   => ['name' => 'Additional Mathematics', 'lower' => 0, 'upper' => 3, 'codes' => ['ADD/MATH', 'ADDMATH'], 'names' => ['additional mathematics', 'additional maths']],
        'literature' => ['name' => 'Literature in English', 'lower' => 0, 'upper' => 2, 'codes' => ['LIT'], 'names' => ['literature in english', 'literature', 'fasihi ya kiingereza']],
        'fasihi'     => ['name' => 'Fasihi ya Kiswahili', 'lower' => 0, 'upper' => 2, 'codes' => ['FASIHI'], 'names' => ['fasihi ya kiswahili', 'fasihi']],
        'french'     => ['name' => 'French', 'lower' => 3, 'upper' => 3, 'codes' => ['FREN', 'FRE'], 'names' => ['french', 'kifaransa']],
        'arabic'     => ['name' => 'Arabic', 'lower' => 3, 'upper' => 3, 'codes' => ['ARAB'], 'names' => ['arabic', 'khiarabu']],
        'chinese'    => ['name' => 'Chinese', 'lower' => 3, 'upper' => 3, 'codes' => ['CHIN'], 'names' => ['chinese', 'kichina']],
        'music'      => ['name' => 'Music', 'lower' => 4, 'upper' => 4, 'codes' => ['MUSIC', 'MUS'], 'names' => ['music', 'muziki']],
        'history'    => ['name' => 'History', 'lower' => 3, 'upper' => 4, 'codes' => ['HIST'], 'names' => ['history', 'historia']],
        'bookkeeping' => ['name' => 'Book-keeping', 'lower' => 3, 'upper' => 4, 'codes' => ['BK', 'B/KEEP'], 'names' => ['book-keeping', 'bookkeeping', 'book keeping', 'uhasibu']],
        'bible'      => ['name' => 'Bible Knowledge', 'lower' => 3, 'upper' => 3, 'codes' => ['BIBLE', 'BKNW'], 'names' => ['bible knowledge', 'biblia']],
        'islam'      => ['name' => 'Dini ya Kiislamu', 'lower' => 3, 'upper' => 3, 'codes' => ['ISLAM', 'DK'], 'names' => ['dini ya kiislamu', 'islamic knowledge', 'dini']],
    ];
    return $rows;
}

/**
 * Band ya mtaala kwa darasa: 'lower' (Kidato I–II), 'upper' (Kidato III–IV)
 * au null (A-Level / darasa lisilojulikana) — hapo tutatumia uzito wa zamani.
 */
function tt_class_band(array $class) {
    if (strtoupper((string)($class['level'] ?? 'O')) === 'A') return null;
    $name = strtolower(trim((string)($class['name'] ?? '')));
    if ($name === '') return null;
    if (preg_match('/\b(iii|iv|3|4)\b/', $name)) return 'upper';
    if (preg_match('/\b(i|ii|1|2)\b/', $name)) return 'lower';
    return null;
}

/**
 * Tafuta somo kwenye mtaala: hurudisha safu ya mtaala + ufunguo wake, au null.
 *
 * @return array{key:string, compulsory:bool}&array<string,int>|null
 */
function tt_curriculum_lookup(array $subject, $band) {
    if ($band !== 'lower' && $band !== 'upper') return null;
    $code = strtoupper(trim((string)($subject['code'] ?? '')));
    $abbr = strtoupper(trim((string)($subject['abbr'] ?? '')));
    $name = strtolower(trim((string)($subject['name'] ?? '')));
    if ($name === '' && $code === '' && $abbr === '') return null;
    // A-Level haitumii mtaala wa O-Level.
    if (strpos($name, 'advanced') !== false || strpos($name, 'general studies') !== false) return null;

    $rows = tt_curriculum();
    $wrap = function ($key) use ($rows) {
        $r = $rows[$key];
        $r['key'] = $key;
        $r['compulsory'] = !empty($r['compulsory']);
        return $r;
    };
    foreach ($rows as $key => $r) {
        if ($code !== '' && in_array($code, $r['codes'], true)) return $wrap($key);
        if ($abbr !== '' && in_array($abbr, $r['codes'], true)) return $wrap($key);
    }
    foreach ($rows as $key => $r) {
        if (in_array($name, $r['names'], true)) return $wrap($key);
    }
    // Mwisho: jina lililo ndani ya jina (alias ndefu inashinda).
    $hitKey = null;
    $len = 0;
    foreach ($rows as $key => $r) {
        foreach ($r['names'] as $alias) {
            if ($alias === '' || strpos($name, $alias) === false) continue;
            if (strlen($alias) > $len) { $hitKey = $key; $len = strlen($alias); }
        }
    }
    return $hitKey === null ? null : $wrap($hitKey);
}

/**
 * Vipindi vya wiki kwa somo kulingana na mtaala, au null kama halipo.
 */
function tt_curriculum_periods(array $subject, $band) {
    $m = tt_curriculum_lookup($subject, $band);
    return $m === null ? null : (int)$m[$band];
}

/**
 * Gawanya $total vipindi kwa uwiano wa $weights (largest remainder method).
 * Matokeo ni ints zinazolingana kabisa na $total.
 */
function tt_largest_remainder(array $weights, $total) {
    $out = [];
    $total = max(0, (int)$total);
    if (!$weights) return $out;

    $sum = 0.0;
    foreach ($weights as $k => $w) { $weights[$k] = max(0.0, (float)$w); $sum += $weights[$k]; }
    if ($sum <= 0) {
        foreach ($weights as $k => $_) $weights[$k] = 1.0;
        $sum = (float)count($weights);
    }

    $used = 0;
    $rems = [];
    foreach ($weights as $k => $w) {
        $raw = $total * $w / $sum;
        $out[$k] = (int)floor($raw);
        $rems[$k] = $raw - floor($raw);
        $used += $out[$k];
    }
    $left = $total - $used;
    $keys = array_keys($out);
    usort($keys, function ($a, $b) use ($rems, $out) {
        if ($rems[$a] != $rems[$b]) return $rems[$b] <=> $rems[$a];
        return $out[$a] <=> $out[$b];
    });
    $i = 0;
    while ($left > 0 && $keys) {
        $out[$keys[$i % count($keys)]]++;
        $left--;
        $i++;
    }
    return $out;
}

/**
 * Mahitaji ya awali ya vipindi kwa wiki.
 *
 * Masomo yaliyo kwenye mtaala rasmi (tt_curriculum) hupata idadi yake halisi
 * kwa Kidato I–II au III–IV. Masomo mengine hugawana nafasi iliyobaki kwa
 * uwiano wa uzito. Kama jumla inazidi nafasi za wiki, hupunguzwa kwa uwiano
 * ili jumla isizidi $capacity.
 */
function tt_default_requirements(array $classes, $capacity) {
    $capacity = max(0, (int)$capacity);
    $out = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $subs = q_all('SELECT s.* FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id
                       WHERE cs.class_id = ? ORDER BY s.name', [$cid]);
        if (!$subs) continue;

        $band = tt_class_band($c);
        $targets = [];
        $compulsory = [];
        $fixed = 0;
        foreach ($subs as $s) {
            $match = tt_curriculum_lookup($s, $band);
            if ($match === null) continue;
            $sid = (int)$s['id'];
            $targets[$sid] = (int)$match[$band];
            if ($match['compulsory']) $compulsory[$sid] = true;
            $fixed += $targets[$sid];
        }

        // Masomo yasiyo kwenye mtaala: gawio kwa uzito kwenye nafasi iliyobaki.
        // Somo la ziada halizidi somo la msingi lililo na vipindi vingi zaidi.
        $loose = [];
        foreach ($subs as $s) if (!isset($targets[(int)$s['id']])) $loose[] = $s;
        if ($loose) {
            $rest = max(0, $capacity - $fixed);
            if ($rest > 0) {
                $w = [];
                foreach ($loose as $s) $w[(int)$s['id']] = tt_subject_weight($s);
                $top = $fixed > 0 ? max($targets) : $rest;
                foreach (tt_largest_remainder($w, $rest) as $sid => $n) {
                    $targets[$sid] = min($n, max(1, (int)$top));
                }
            } else {
                foreach ($loose as $s) $targets[(int)$s['id']] = 0;
            }
        }

        // Nafasi haitoshi: masomo ya lazima yanabaki na idadi yao halisi,
        // nafasi iliyobaki inagawanywa kwa masomo ya kuchagua pekee.
        if ($capacity > 0 && array_sum($targets) > $capacity) {
            $keep = [];
            $keepTotal = 0;
            foreach ($compulsory as $sid => $_) {
                $keep[$sid] = $targets[$sid];
                $keepTotal += $targets[$sid];
            }
            if ($keep && $keepTotal <= $capacity) {
                $elective = [];
                foreach ($targets as $sid => $n) if (!isset($keep[$sid])) $elective[$sid] = $n;
                $space = $capacity - $keepTotal;
                $scaled = $space > 0 ? tt_largest_remainder($elective, $space) : [];
                $targets = $keep;
                foreach ($elective as $sid => $n) $targets[$sid] = (int)($scaled[$sid] ?? 0);
            }
        }
        if ($capacity > 0 && array_sum($targets) > $capacity) {
            $targets = tt_largest_remainder($targets, $capacity);
        }

        foreach ($subs as $s) {
            $sid = (int)$s['id'];
            $out[] = [
                'class_id' => $cid,
                'class_name' => (string)$c['name'],
                'subject_id' => $sid,
                'subject_name' => (string)$s['name'],
                'subject_abbr' => (string)($s['abbr'] ?? ''),
                'periods_per_week' => (int)($targets[$sid] ?? 0),
            ];
        }
    }
    return $out;
}

/* ---------------- Uthibitishaji ---------------- */

/**
 * Thibitisha na safisha data ya fomu ya kuunda ratiba.
 * @return array{ok:bool, errors:array, data:array}
 */
function tt_validate_input(array $in, $school_id) {
    $errors = [];

    $group = (string)($in['group'] ?? 'ordinary');
    if (!in_array($group, ['ordinary', 'advance', 'all'], true)) {
        $errors[] = 'The group you selected is invalid.';
        $group = 'ordinary';
    }

    $sessions = (int)($in['sessions_per_day'] ?? 10);
    if ($sessions < 1 || $sessions > 14) {
        $errors[] = 'Sessions per day must be between 1 and 14.';
        $sessions = max(1, min(14, $sessions));
    }
    $duration = (int)($in['session_duration'] ?? 40);
    if ($duration < 15 || $duration > 120) {
        $errors[] = 'Session duration must be between 15 and 120 minutes.';
        $duration = max(15, min(120, $duration));
    }
    $start = trim((string)($in['start_time'] ?? '08:00'));
    if (teaching_time_minutes($start) === null) {
        $errors[] = 'The start time is invalid.';
        $start = '08:00';
    }
    $end = trim((string)($in['end_time'] ?? '16:30'));
    $endMin = teaching_time_minutes($end);
    if ($endMin === null) {
        $errors[] = 'The end time is invalid.';
        $end = '16:30';
        $endMin = teaching_time_minutes($end);
    }
    if (teaching_time_minutes($start) >= $endMin) {
        $errors[] = 'The end time must be after the start time.';
    }

    $sat = !empty($in['include_saturday']);
    $sun = !empty($in['include_sunday']);
    $days = tt_days($sat, $sun);

    // Mapumziko
    $rawBreaks = is_array($in['breaks'] ?? null) ? $in['breaks'] : [];
    $breaks = [];
    $breakRanges = [];
    foreach ($rawBreaks as $i => $b) {
        if (!is_array($b)) continue;
        $name = trim((string)($b['name'] ?? ''));
        $bs = trim((string)($b['start'] ?? ''));
        $be = trim((string)($b['end'] ?? ''));
        if ($name === '' && $bs === '' && $be === '') continue;
        $s = teaching_time_minutes($bs);
        $e = teaching_time_minutes($be);
        if ($s === null || $e === null) { $errors[] = 'Break #' . ($i + 1) . ': enter valid start and end times.'; continue; }
        if ($e <= $s) { $errors[] = 'Break #' . ($i + 1) . ': the end time must be after the start time.'; continue; }
        foreach ($breakRanges as $br) {
            if ($s < $br[1] && $e > $br[0]) { $errors[] = 'Overlapping breaks: ' . ($name !== '' ? $name : 'BREAK') . ' and an earlier one.'; break; }
        }
        $breakRanges[] = [$s, $e];
        $breaks[] = ['name' => $name !== '' ? $name : 'BREAK', 'start' => $bs, 'end' => $be, 'start_min' => $s, 'end_min' => $e];
    }
    usort($breaks, function ($a, $b) { return $a['start_min'] <=> $b['start_min']; });

    // Matukio
    $rawEvents = is_array($in['events'] ?? null) ? $in['events'] : [];
    $events = [];
    $eventSeen = [];
    foreach ($rawEvents as $i => $ev) {
        if (!is_array($ev)) continue;
        $name = trim((string)($ev['name'] ?? ''));
        $day = tt_day_code($ev['day'] ?? '');
        $es = trim((string)($ev['start'] ?? ''));
        $ee = trim((string)($ev['end'] ?? ''));
        if ($name === '' && $day === '' && $es === '' && $ee === '') continue;
        if ($name === '') { $errors[] = 'Event #' . ($i + 1) . ': enter the event name.'; continue; }
        if ($day === '') { $errors[] = 'Event #' . ($i + 1) . ': choose a valid day.'; continue; }
        $s = teaching_time_minutes($es);
        $e = teaching_time_minutes($ee);
        if ($s === null || $e === null) { $errors[] = 'Event "' . $name . '": enter valid times.'; continue; }
        if ($e <= $s) { $errors[] = 'Event "' . $name . '": the end time must be after the start time.'; continue; }
        if (!in_array($day, $days, true)) continue; // day not included
        foreach ($breakRanges as $br) {
            if ($s < $br[1] && $e > $br[0]) { $errors[] = 'Event "' . $name . '" overlaps a break.'; continue 2; }
        }
        $k = $day . '|' . $s . '-' . $e;
        if (isset($eventSeen[$k])) { $errors[] = 'Event "' . $name . '" appears twice on that day.'; continue; }
        $eventSeen[$k] = true;
        $events[] = ['name' => $name, 'day' => $day, 'start' => $es, 'end' => $ee, 'start_min' => $s, 'end_min' => $e];
    }
    usort($events, function ($a, $b) {
        if ($a['start_min'] !== $b['start_min']) return $a['start_min'] <=> $b['start_min'];
        return strcmp($a['day'], $b['day']);
    });

    // Saa za vipindi
    $slots = teaching_timetable_timeslots($sessions, $duration, $start, $breaks);
    $lastEnd = teaching_time_minutes($slots['end_text']);
    if ($lastEnd !== null && $endMin !== null && $lastEnd > $endMin) {
        $errors[] = 'Sessions end at ' . $slots['end_text'] . ', after the end time (' . $end . '). Increase the end time or reduce the number of sessions.';
    }

    // Streams (hiari): kila stream inakuwa safu yake ya ratiba.
    $streams = tt_normalize_streams($in['streams'] ?? []);
    $streamList = $streams ?: [''];

    // Mahitaji ya vipindi
    $allowedClasses = [];
    foreach (tt_classes_for_group($group) as $c) $allowedClasses[(int)$c['id']] = $c;
    $classSubjects = [];
    if ($allowedClasses) {
        $ids = array_keys($allowedClasses);
        $inSql = implode(',', array_fill(0, count($ids), '?'));
        foreach (q_all("SELECT class_id, subject_id FROM class_subjects WHERE class_id IN ($inSql)", $ids) as $r) {
            $classSubjects[(int)$r['class_id']][(int)$r['subject_id']] = true;
        }
    }

    $requirements = [];
    $classTotals = [];
    $rawReq = is_array($in['required'] ?? null) ? $in['required'] : [];
    foreach ($rawReq as $cid => $subs) {
        $cid = (int)$cid;
        if (!isset($allowedClasses[$cid]) || !is_array($subs)) continue;
        foreach ($subs as $sid => $byStream) {
            $sid = (int)$sid;
            if (!isset($classSubjects[$cid][$sid])) continue;
            $perStream = is_array($byStream) ? $byStream : [0 => $byStream];
            foreach ($perStream as $si => $n) {
                $si = (int)$si;
                if (!isset($streamList[$si])) continue;
                $stream = $streamList[$si];
                $n = (int)$n;
                if ($n <= 0) continue;
                if ($n > 100) $n = 100;
                $requirements[] = ['class_id' => $cid, 'subject_id' => $sid, 'stream' => $stream, 'periods_per_week' => $n];
                $tkey = $cid . '|' . $stream;
                $classTotals[$tkey] = ($classTotals[$tkey] ?? 0) + $n;
            }
        }
    }
    if (!$requirements) {
        $errors[] = 'Set at least one period for each class and subject.';
    }

    // Vipindi vya lazima pekee: baada ya lunch ni saa za remedial.
    $capacity = tt_compulsory_capacity([
        'days' => $days,
        'slots' => $slots,
        'breaks' => $breaks,
        'events' => $events,
    ]);
    foreach ($classTotals as $tkey => $total) {
        list($cid, $stream) = explode('|', $tkey, 2) + [1 => ''];
        if ($total > $capacity) {
            $label = tt_entity_name((string)$allowedClasses[(int)$cid]['name'], $stream);
            $errors[] = 'Class ' . $label . ': you set ' . $total
                . ' periods per week but there are only ' . $capacity . ' slots. Reduce the periods, add sessions per day, or include Saturday.';
        }
    }

    return [
        'ok' => !$errors,
        'errors' => $errors,
        'data' => [
            'group' => $group,
            'sessions_per_day' => $sessions,
            'session_duration' => $duration,
            'start_time' => $start,
            'end_time' => $end,
            'include_saturday' => $sat ? 1 : 0,
            'include_sunday' => $sun ? 1 : 0,
            'days' => $days,
            'breaks' => $breaks,
            'events' => $events,
            'slots' => $slots,
            'capacity' => $capacity,
            'streams' => $streams,
            'requirements' => $requirements,
            'classes' => $allowedClasses,
        ],
    ];
}

/* ---------------- Kizalisha ratiba ---------------- */

/**
 * Tengeneza vipindi halisi kwa kuzingatia mahitaji, mapumziko, matukio na
 * migongano ya walimu. Hujaribu mara kadhaa na kuchagua matokeo bora.
 *
 * @return array{ok:bool, entries:array, assigned:int, required:int, errors:array}
 */
function tt_generate(array $data, array $assignMap = []) {
    $classes = $data['classes'] ?? [];
    // Mbegu ya random kwa kila ubunifu: ratiba (na remedial) hubadilika kila mara.
    $seedBase = random_int(1000, 999999);
    $requirements = $data['requirements'] ?? [];
    $config = [
        'days' => $data['days'],
        'slots' => $data['slots'],
        'events' => $data['events'],
    ];

    // Masomo yanayohitajika kwa kila darasa + stream (entity).
    $needByEntity = [];
    $subjectIds = [];
    foreach ($requirements as $r) {
        $cid = (int)$r['class_id'];
        $sid = (int)$r['subject_id'];
        $n = (int)$r['periods_per_week'];
        $stream = trim((string)($r['stream'] ?? ''));
        if ($n <= 0 || !isset($classes[$cid])) continue;
        $ekey = $cid . '|' . $stream;
        if (!isset($needByEntity[$ekey])) {
            $needByEntity[$ekey] = ['class_id' => $cid, 'stream' => $stream, 'subjects' => []];
        }
        $needByEntity[$ekey]['subjects'][$sid] = ($needByEntity[$ekey]['subjects'][$sid] ?? 0) + $n;
        $subjectIds[$sid] = true;
    }
    if (!$needByEntity) {
        return ['ok' => false, 'entries' => [], 'assigned' => 0, 'required' => 0,
                'errors' => ['No required periods have been set.']];
    }

    $subjectInfo = [];
    $sids = array_keys($subjectIds);
    if ($sids) {
        $in = implode(',', array_fill(0, count($sids), '?'));
        foreach (q_all("SELECT id, name, abbr FROM subjects WHERE id IN ($in)", $sids) as $s) {
            $subjectInfo[(int)$s['id']] = $s;
        }
    }

    // Nafasi za vipindi vya lazima kwa kila siku (bila saa za matukio wala
    // vipindi vya remedial, ambavyo hupangwa baadaye).
    $remedialNos = tt_remedial_nos([
        'days' => $data['days'],
        'slots' => $data['slots'],
        'breaks' => $data['breaks'] ?? [],
    ]);
    $sessionItems = [];
    foreach (($data['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') !== 'session') continue;
        if (isset($remedialNos[(int)$it['no']])) continue;
        $sessionItems[] = $it;
    }
    $daySlots = [];
    foreach ($data['days'] as $day) {
        $list = [];
        foreach ($sessionItems as $s) {
            $blocked = false;
            foreach ($data['events'] as $ev) {
                if ($ev['day'] === $day && $ev['start_min'] < $s['end'] && $ev['end_min'] > $s['start']) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) $list[] = $s;
        }
        $daySlots[$day] = $list;
    }

    // Quota za vipindi vya lazima kwa siku, ili siku zote zishiriki sawasawa.
    $entityQuota = [];
    foreach ($needByEntity as $ekey => $ent) {
        $entityQuota[$ekey] = tt_day_quotas($data['days'], $daySlots, (int)array_sum($ent['subjects']));
    }

    // Nafasi za double: vipindi viwili vinavyofuatana bila mapumziko katikati.
    $dayPairs = [];
    foreach ($data['days'] as $day) {
        $pairs = [];
        $slotsOfDay = $daySlots[$day];
        for ($i = 0; $i + 1 < count($slotsOfDay); $i++) {
            if ((int)$slotsOfDay[$i]['end'] === (int)$slotsOfDay[$i + 1]['start']) $pairs[] = $i;
        }
        $dayPairs[$day] = $pairs;
    }

    $totalRequired = 0;
    foreach ($needByEntity as $ent) foreach ($ent['subjects'] as $n) $totalRequired += $n;

    $best = null;
    $attempts = 40;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        mt_srand($seedBase + $attempt * 7919);

        // Mpangilio wa madarasa/streams (hubadilika kwa kila jaribio)
        $entityOrder = array_keys($needByEntity);
        shuffle($entityOrder);

        $tasks = [];
        foreach ($needByEntity as $ekey => $ent) {
            $cid = (int)$ent['class_id'];
            $stream = (string)$ent['stream'];
            $tasks[$ekey] = [];
            foreach ($ent['subjects'] as $sid => $n) {
                $a = tt_assignment_for($assignMap, $cid, $sid, $stream);
                $sub = $subjectInfo[$sid] ?? ['name' => 'SUBJECT', 'abbr' => ''];
                $dayCountTarget = max(1, (int)ceil($n / max(1, count($data['days']))));
                $tasks[$ekey][$sid] = [
                    'sid' => $sid,
                    'name' => (string)$sub['name'],
                    'abbr' => (string)($sub['abbr'] ?? ''),
                    'teacher_id' => $a ? (int)$a['teacher_id'] : 0,
                    'teacher' => $a ? (string)$a['full_name'] : '',
                    'left' => $n,
                    // Vipindi double: jozi = intdiv(n,2), kilichobaki ni single.
                    // Mf. vipindi 3 => double 1 + single 1; vipindi 5 => double 2 + single 1.
                    'dbl' => intdiv($n, 2),
                    'cap' => $dayCountTarget,
                    'day' => [],
                ];
            }
        }

        $busy = [];       // [day][period][teacher_id]
        $lastSid = [];    // [entity][day] = somo la kipindi kilichopita
        $taken = [];      // [entity][day][period] = true (imechukuliwa na double)
        $units = [];      // [entity][subject][day] = idadi ya vipindi vya siku
        $assignments = [];
        $assigned = 0;
        $dayCount = [];
        foreach ($needByEntity as $ekey => $ent) {
            foreach ($data['days'] as $day) $dayCount[$ekey][$day] = 0;
        }

        // Huongeza kipindi kimoja kwenye ratiba na kusasisha hesabu zote.
        $emit = function ($ekey, $sid, $day, $slot) use (&$assignments, &$busy, &$tasks, &$units, &$lastSid, $needByEntity) {
            $ent = $needByEntity[$ekey];
            $t = $tasks[$ekey][$sid];
            $assignments[] = [
                'class_id' => (int)$ent['class_id'],
                'subject_id' => (int)$sid,
                'stream' => (string)$ent['stream'],
                'teacher_id' => (int)$t['teacher_id'],
                'day' => $day,
                'period_no' => (int)$slot['no'],
                'start' => (string)$slot['start_text'],
                'end' => (string)$slot['end_text'],
            ];
            $tasks[$ekey][$sid]['left']--;
            $tasks[$ekey][$sid]['day'][$day] = ($tasks[$ekey][$sid]['day'][$day] ?? 0) + 1;
            $units[$ekey][$sid][$day] = ($units[$ekey][$sid][$day] ?? 0) + 1;
            if ($t['teacher_id']) $busy[$day][(int)$slot['no']][(int)$t['teacher_id']] = true;
            $lastSid[$ekey][$day] = (int)$sid;
        };

        foreach ($data['days'] as $day) {
            /* Pass A: vipindi double. Kipindi cha jozi hupangwa kama somo moja
               linalochukua vipindi viwili mfululizo bila mapumziko katikati. */
            foreach ($dayPairs[$day] as $pi) {
                $s1 = $daySlots[$day][$pi];
                $s2 = $daySlots[$day][$pi + 1];
                foreach ($entityOrder as $ekey) {
                    if (($dayCount[$ekey][$day] ?? 0) + 2 > ($entityQuota[$ekey][$day] ?? 0)) continue;
                    if (!empty($taken[$ekey][$day][(int)$s1['no']]) || !empty($taken[$ekey][$day][(int)$s2['no']])) continue;

                    $pick = null;
                    $pickScore = null;
                    $last = $lastSid[$ekey][$day] ?? 0;
                    foreach ($tasks[$ekey] as $sid => $t) {
                        if ($t['dbl'] <= 0 || $t['left'] < 2) continue;
                        $tid = (int)$t['teacher_id'];
                        if ($tid && (!empty($busy[$day][(int)$s1['no']][$tid]) || !empty($busy[$day][(int)$s2['no']][$tid]))) continue;
                        $u = $units[$ekey][$sid][$day] ?? 0;
                        if ($u >= $t['cap']) continue;

                        $score = [
                            'left' => -$t['left'],
                            'u' => $u,
                            'last' => ($sid === $last) ? 1 : 0,
                            'rand' => mt_rand(),
                        ];
                        if ($pickScore === null || $score < $pickScore) {
                            $pick = $sid;
                            $pickScore = $score;
                        }
                    }
                    if ($pick === null) continue;

                    $emit($ekey, $pick, $day, $s1);
                    $emit($ekey, $pick, $day, $s2);
                    $tasks[$ekey][$pick]['dbl']--;
                    $taken[$ekey][$day][(int)$s1['no']] = true;
                    $taken[$ekey][$day][(int)$s2['no']] = true;
                    $dayCount[$ekey][$day] += 2;
                    $assigned += 2;
                }
            }

            /* Pass B: vipindi vya single (pamoja na mabaki ya double
               yasiyopata nafasi ya jozi). */
            foreach ($daySlots[$day] as $slot) {
                foreach ($entityOrder as $ekey) {
                    // Ruka kama siku hii imeshajaza fungu lake la vipindi.
                    if (($dayCount[$ekey][$day] ?? 0) >= ($entityQuota[$ekey][$day] ?? 0)) continue;
                    if (!empty($taken[$ekey][$day][(int)$slot['no']])) continue;
                    $pick = null;
                    $pickScore = null;
                    $last = $lastSid[$ekey][$day] ?? 0;

                    foreach ($tasks[$ekey] as $sid => $t) {
                        if ($t['left'] <= 0) continue;
                        $tid = (int)$t['teacher_id'];
                        if ($tid && !empty($busy[$day][(int)$slot['no']][$tid])) continue;
                        $dc = $t['day'][$day] ?? 0;
                        if ($dc >= $t['cap']) continue;

                        $singles = $t['left'] - 2 * $t['dbl'];
                        $score = [
                            'single' => $singles > 0 ? 0 : 1, // single iliyotengwa kwanza
                            'left' => -$t['left'],
                            'dc' => $dc,
                            'last' => ($sid === $last) ? 1 : 0,
                            'rand' => mt_rand(),
                        ];
                        if ($pickScore === null || $score < $pickScore) {
                            $pick = $sid;
                            $pickScore = $score;
                        }
                    }

                    // Kama hakuna chaguo ndani ya kikomo cha siku, puuza kikomo.
                    if ($pick === null) {
                        foreach ($tasks[$ekey] as $sid => $t) {
                            if ($t['left'] <= 0) continue;
                            $tid = (int)$t['teacher_id'];
                            if ($tid && !empty($busy[$day][(int)$slot['no']][$tid])) continue;
                            $singles = $t['left'] - 2 * $t['dbl'];
                            $score = [
                                'single' => $singles > 0 ? 0 : 1,
                                'left' => -$t['left'],
                                'dc' => $t['day'][$day] ?? 0,
                                'last' => ($sid === $last) ? 1 : 0,
                                'rand' => mt_rand(),
                            ];
                            if ($pickScore === null || $score < $pickScore) {
                                $pick = $sid;
                                $pickScore = $score;
                            }
                        }
                    }
                    if ($pick === null) continue;

                    // Double iliyotengwa lakini isiyopata jozi inavunjwa kuwa single.
                    $singles = $tasks[$ekey][$pick]['left'] - 2 * $tasks[$ekey][$pick]['dbl'];
                    $emit($ekey, $pick, $day, $slot);
                    if ($singles <= 0) $tasks[$ekey][$pick]['dbl']--;
                    $dayCount[$ekey][$day]++;
                    $assigned++;
                }
            }
        }

        // Mop-up: vipindi vilivyobaki kwa sababu ya migongano ya walimu
        // hujaribiwa kupangwa bila kikomo cha siku, hivyo hakuna kipindi
        // kinachobaki bure isipokuwa hakuna nafasi iliyosalia.
        if ($assigned < $totalRequired) {
            foreach ($data['days'] as $day) {
                foreach ($daySlots[$day] as $slot) {
                    foreach ($entityOrder as $ekey) {
                        if (!empty($taken[$ekey][$day][(int)$slot['no']])) continue;
                        $pick = null;
                        $pickScore = null;
                        $last = $lastSid[$ekey][$day] ?? 0;
                        foreach ($tasks[$ekey] as $sid => $t) {
                            if ($t['left'] <= 0) continue;
                            $tid = (int)$t['teacher_id'];
                            if ($tid && !empty($busy[$day][(int)$slot['no']][$tid])) continue;
                            $score = [
                                'left' => -$t['left'],
                                'dc' => $t['day'][$day] ?? 0,
                                'last' => ($sid === $last) ? 1 : 0,
                                'rand' => mt_rand(),
                            ];
                            if ($pickScore === null || $score < $pickScore) {
                                $pick = $sid;
                                $pickScore = $score;
                            }
                        }
                        if ($pick === null) continue;
                        $singles = $tasks[$ekey][$pick]['left'] - 2 * $tasks[$ekey][$pick]['dbl'];
                        $emit($ekey, $pick, $day, $slot);
                        if ($singles <= 0) $tasks[$ekey][$pick]['dbl']--;
                        $dayCount[$ekey][$day]++;
                        $assigned++;
                    }
                }
            }
        }

        $unfilled = $totalRequired - $assigned;
        $candidate = ['entries' => $assignments, 'assigned' => $assigned, 'unfilled' => $unfilled];
        if ($best === null || $candidate['unfilled'] < $best['unfilled']) $best = $candidate;
        if ($unfilled <= 0) break;
    }

    if ($best === null || $best['unfilled'] > 0) {
        $assigned = $best['assigned'] ?? 0;
        return [
            'ok' => false,
            'entries' => [],
            'assigned' => $assigned,
            'required' => $totalRequired,
            'errors' => [
                'Could not schedule all periods: ' . $assigned . ' of ' . $totalRequired . ' were scheduled.',
                'Try adding sessions per day, including Saturday/Sunday, reducing the required periods, or making sure every subject has a teacher without conflicts.',
            ],
        ];
    }

    // Vipindi vya remedial: hujazwa baada ya lunch kwa mchanganyiko wa science
    // na art, hadi kila somo lipate zamu.
    $busy = [];
    foreach ($best['entries'] as $e) {
        if (!empty($e['teacher_id'])) $busy[$e['day']][(int)$e['period_no']][(int)$e['teacher_id']] = true;
    }
    $remedial = tt_plan_remedial([
        'days' => $data['days'],
        'slots' => $data['slots'],
        'breaks' => $data['breaks'] ?? [],
        'events' => $data['events'] ?? [],
    ], $needByEntity, $assignMap, $subjectInfo, $busy);

    return [
        'ok' => true,
        'entries' => array_merge($best['entries'], $remedial),
        'assigned' => $best['assigned'],
        'required' => $totalRequired,
        'remedial' => count($remedial),
        'errors' => [],
    ];
}

/* ---------------- Matrix ya kuonyesha/export ---------------- */

/**
 * Panga vipindi vilivyohifadhiwa katika muundo wa MSSIS.
 *
 * @param array $tt      Safu ya timetables pamoja na entries
 * @param array $classes Madarasa ya kuonyesha
 * @param array $opts    ['subject_id'=>int, 'teacher_id'=>int, 'stream'=>string]
 */
function tt_build_matrix(array $tt, array $classes, array $opts = []) {
    $config = tt_config_from_row($tt);
    $days = $config['days'];
    $slots = $config['slots'];
    $subjectId = (int)($opts['subject_id'] ?? 0);
    $teacherId = (int)($opts['teacher_id'] ?? 0);
    $streamFilter = trim((string)($opts['stream'] ?? ''));
    $filtered = $subjectId > 0 || $teacherId > 0;

    // Saa za vipindi
    $cols = [];
    $breakKeys = [];
    $remedialNos = tt_remedial_nos($config);
    foreach (($slots['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'break') {
            $k = (int)$it['start'] . '-' . (int)$it['end'];
            $breakKeys[$k] = true;
            $cols[] = [
                'type' => 'break', 'name' => (string)($it['name'] ?? 'BREAK'),
                'start' => (int)$it['start'], 'end' => (int)$it['end'],
                'start_text' => $it['start_text'], 'end_text' => $it['end_text'],
                'label' => 'B', 'by_day' => [],
            ];
        } else {
            $cols[] = [
                'type' => 'session', 'no' => (int)$it['no'],
                'start' => (int)$it['start'], 'end' => (int)$it['end'],
                'start_text' => $it['start_text'], 'end_text' => $it['end_text'],
                'label' => (string)$it['label'], 'by_day' => [],
                'remedial' => isset($remedialNos[(int)$it['no']]),
            ];
        }
    }

    /* Matukio hayaundi safu yao: yanaingia ndani ya safu za vipindi 1..N
       zinazoingiliana na muda wake, kisha zinaunganishwa (merge) kuwa cell
       moja. Tukio lisiloingiliana na kipindi chochote (mf. ndani ya break)
       linabaki kama safu yake ya kawaida. */
    $eventSpans = [];   // day => [['from'=>i,'to'=>j,'name'=>..,'start_text'=>..], ...]
    if ($config['events']) {
        $evCols = [];
        foreach ($config['events'] as $ev) {
            $k = $ev['start_min'] . '-' . $ev['end_min'];
            if (isset($breakKeys[$k])) continue; // linaingana na mapumziko

            $hits = [];
            foreach ($cols as $ci => $col) {
                if (($col['type'] ?? '') !== 'session') continue;
                if ($ev['start_min'] < $col['end'] && $ev['end_min'] > $col['start']) $hits[] = $ci;
            }

            if ($hits) {
                $eventSpans[$ev['day']][] = [
                    'from' => min($hits), 'to' => max($hits),
                    'name' => (string)$ev['name'],
                    'start_text' => (string)$ev['start'], 'end_text' => (string)$ev['end'],
                    'start_min' => (int)$ev['start_min'], 'end_min' => (int)$ev['end_min'],
                ];
                continue;
            }

            // Hifadhi: tukio lisiloingiliana na kipindi chochote.
            if (!isset($evCols[$k])) {
                $evCols[$k] = [
                    'type' => 'event', 'name' => $ev['name'],
                    'start' => $ev['start_min'], 'end' => $ev['end_min'],
                    'start_text' => $ev['start'], 'end_text' => $ev['end'],
                    'label' => 'B', 'by_day' => [],
                ];
            }
            $evCols[$k]['by_day'][$ev['day']] = $ev['name'];
        }
        foreach ($evCols as $c) $cols[] = $c;
    }

    usort($cols, function ($a, $b) {
        if ($a['start'] !== $b['start']) return $a['start'] <=> $b['start'];
        $rank = ['break' => 0, 'event' => 1, 'session' => 2];
        $ra = $rank[$a['type']] ?? 3;
        $rb = $rank[$b['type']] ?? 3;
        if ($ra !== $rb) return $ra <=> $rb;
        return ((int)($a['no'] ?? 0)) <=> ((int)($b['no'] ?? 0));
    });

    /* Matukio yanaingia ndani ya vipindi, hivyo yanahitaji indices za safu
       baada ya kupanga: $evStart = cell inapoanza, $evCover = zilizofunikwa. */
    $evStart = [];   // day => [index => span]
    $evCover = [];   // day => [index => true]
    foreach ($eventSpans as $day => $list) {
        $cursor = -1;
        foreach ($list as $sp) {
            // Ruka tukio linalogongana na lililotangulia kwenye siku hiyo.
            if ($sp['from'] <= $cursor) continue;
            $evStart[$day][$sp['from']] = ['span' => $sp['to'] - $sp['from'] + 1] + $sp;
            for ($ci = $sp['from']; $ci <= $sp['to']; $ci++) $evCover[$day][$ci] = true;
            $cursor = $sp['to'];
        }
    }

    // Fahirisi ya vipindi: [class|stream][day][period]
    $idx = [];
    $allEntries = $tt['entries'] ?? [];
    foreach ($allEntries as $e) {
        $key = (int)$e['class_id'] . '|' . trim((string)($e['stream'] ?? ''));
        $idx[$key][(string)$e['day']][(int)$e['period_no']] = $e;
    }

    // Orodha ya safu za darasa + stream kwa mpangilio wa madarasa.
    $entityRows = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $streamsHere = [];
        foreach ($allEntries as $e) {
            if ((int)$e['class_id'] !== $cid) continue;
            $stream = trim((string)($e['stream'] ?? ''));
            if ($streamFilter !== '' && $stream !== $streamFilter) continue;
            $streamsHere[$stream] = true;
        }
        $keys = array_keys($streamsHere);
        usort($keys, function ($a, $b) {
            if ($a === '') return -1;
            if ($b === '') return 1;
            return strcasecmp($a, $b);
        });
        foreach ($keys as $stream) {
            $entityRows[] = [
                'key' => $cid . '|' . $stream,
                'class' => $c,
                'stream' => $stream,
                'label' => tt_entity_name((string)$c['name'], $stream),
            ];
        }
    }

    // Chagua safu zenye vipindi vinavyolingana na vichujio
    $keep = [];
    foreach ($entityRows as $row) {
        $key = $row['key'];
        if (empty($idx[$key])) continue;
        if (!$filtered) { $keep[$key] = true; continue; }
        $match = false;
        foreach ($days as $day) {
            if (empty($idx[$key][$day])) continue;
            foreach ($idx[$key][$day] as $e) {
                if ($subjectId && (int)$e['subject_id'] !== $subjectId) continue;
                if ($teacherId && (int)(($e['teacher_id'] ?? 0)) !== $teacherId) continue;
                $match = true;
                break 2;
            }
        }
        if ($match) $keep[$key] = true;
    }

    // Majina ya masomo na walimu
    $subjectNames = [];
    $teacherNames = [];
    $subjectIds = [];
    $teacherIds = [];
    foreach ($allEntries as $e) {
        $subjectIds[(int)$e['subject_id']] = true;
        if (!empty($e['teacher_id'])) $teacherIds[(int)$e['teacher_id']] = true;
    }
    if ($subjectIds) {
        $ids = array_keys($subjectIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (q_all("SELECT id, name, abbr FROM subjects WHERE id IN ($in)", $ids) as $s) {
            $subjectNames[(int)$s['id']] = $s;
        }
    }
    if ($teacherIds) {
        $ids = array_keys($teacherIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (q_all("SELECT id, full_name FROM users WHERE id IN ($in)", $ids) as $u) {
            $teacherNames[(int)$u['id']] = (string)$u['full_name'];
        }
    }

    $rows = [];
    $stats = [];
    $totalRows = 0;
    $labels = tt_day_labels();
    foreach ($days as $day) {
        $classRows = [];
        foreach ($entityRows as $row) {
            $key = $row['key'];
            if (empty($keep[$key])) continue;
            $cells = [];
            foreach ($cols as $ci => $col) {
                if (($col['type'] ?? '') !== 'session') { $cells[$ci] = null; continue; }
                $e = $idx[$key][$day][(int)$col['no']] ?? null;
                if ($e) {
                    if ($subjectId && (int)$e['subject_id'] !== $subjectId) $e = null;
                    elseif ($teacherId && (int)($e['teacher_id'] ?? 0) !== $teacherId) $e = null;
                }
                if ($e) {
                    $sub = $subjectNames[(int)$e['subject_id']] ?? ['name' => 'SUBJECT', 'abbr' => ''];
                    $tFull = $teacherNames[(int)($e['teacher_id'] ?? 0)] ?? '';
                    $e['name'] = (string)$sub['name'];
                    $e['abbr'] = (string)($sub['abbr'] ?? '');
                    $e['teacher'] = $tFull;
                    $e['teacher_short'] = $tFull !== '' ? teaching_short_name($tFull) : '';
                    $name = (string)$e['name'];
                    $stats[$name] = ($stats[$name] ?? 0) + 1;
                }
                $cells[$ci] = $e;
            }
            /* Vipindi double: somo lile lile vipindi viwili mfululizo bila
               mapumziko katikati. Cell ya pili haichorwi; ya kwanza inachukua
               nafasi ya zote mbili. */
            foreach ($cols as $ci => $col) {
                if (($col['type'] ?? '') !== 'session') continue;
                $next = $cols[$ci + 1] ?? null;
                if (!$next || ($next['type'] ?? '') !== 'session') continue;
                if ((int)$next['start'] !== (int)$col['end']) continue;
                if (!empty($col['remedial']) || !empty($next['remedial'])) continue; // remedial si double
                $a = $cells[$ci] ?? null;
                $b = $cells[$ci + 1] ?? null;
                if (!$a || !$b) continue;
                if ((int)$a['subject_id'] !== (int)$b['subject_id']) continue;
                if ((int)($a['teacher_id'] ?? 0) !== (int)($b['teacher_id'] ?? 0)) continue;
                $cells[$ci]['double'] = 2;
                $cells[$ci + 1]['double_skip'] = true;
            }

            $classRows[] = [
                'class' => $row['class'],
                'stream' => $row['stream'],
                'label' => $row['label'],
                'cells' => $cells,
            ];
            $totalRows++;
        }
        if ($classRows) {
            $rows[] = [
                'day' => $day,
                'label' => $labels[$day] ?? $day,
                'classes' => $classRows,
                'rowspan' => count($classRows),
                'event_start' => $evStart[$day] ?? [],
                'event_cover' => $evCover[$day] ?? [],
            ];
        }
    }
    ksort($stats);

    return [
        'columns' => $cols,
        'rows' => $rows,
        'total_rows' => $totalRows,
        'stats' => $stats,
        'filtered' => $filtered,
        'days' => $days,
        'breaks' => $config['breaks'],
        'events' => $config['events'],
        'slots' => $slots,
        'config' => $config,
        'remedial_nos' => $remedialNos,
    ];
}

/* ---------------- CSS na uchoraji ---------------- */

function tt_css() {
    return '
.tt-wrap{overflow-x:auto;background:#fff;border:1px solid #d7e3f4;border-radius:14px;box-shadow:0 18px 40px -28px rgba(11,61,145,.55)}
.tt-table{border-collapse:collapse;width:100%;background:#fff;table-layout:fixed;margin:0;font-family:"Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif}
.tt-table th,.tt-table td{border:1px solid #e3eaf5;text-align:center;vertical-align:middle;padding:4px 3px}
.tt-table thead th{background:#1e3a8a;background-color:#1e3a8a;color:#fff;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;border-color:#2b4aa0}
.tt-table thead th.tt-time{background-color:#eef4ff;background:#eef4ff;color:#1e3a8a;font-size:7.5px;font-weight:700;letter-spacing:.2px;border-color:#dbe6fb}
.tt-table .tt-day{width:88px}
.tt-table .tt-form{width:92px}
.tt-table .tt-sess{width:76px}
.tt-table .tt-break-col{width:26px}
.tt-table td.tt-day{background:#f0f6ff;color:#12326e;font-weight:800;font-size:9px;letter-spacing:.6px;text-transform:uppercase;border-color:#dbe6fb}
.tt-table td.tt-form{background:#f9fcff;color:#12233d;font-weight:700;font-size:8.5px}
.tt-table td.tt-cell{height:46px;font-size:8.5px;white-space:normal;line-height:1.18;background:#fff}
.tt-table tbody tr:nth-child(even) td.tt-cell{background:#f8fbff}
.tt-table td.tt-cell:hover{background:#eef5ff}
.tt-table td.tt-empty{background:#fff}
.tt-table td.tt-remedial{background:#eff8f2}
.tt-table tbody tr:nth-child(even) td.tt-remedial{background:#e8f4ec}
.tt-table td.tt-double{background:#e8f1ff}
.tt-table .tt-double-tag{display:block;font-size:5.5px;font-weight:800;color:#1d4ed8;letter-spacing:.4px;margin-top:1px}
.tt-table th.tt-rem-head{background-color:#0f766e;background:#0f766e;color:#fff;border-color:#0d9488}
.tt-table .tt-rem{display:block;font-size:5.5px;font-weight:800;color:#0f766e;letter-spacing:.4px;margin-top:1px}
.tt-table .tt-subject{font-weight:800;text-transform:uppercase;font-size:8.5px;color:#12233d;letter-spacing:.2px}
.tt-table .tt-teacher{color:#6b7c93;font-size:7px;line-height:1.1;margin-top:2px;text-transform:uppercase;letter-spacing:.2px}
.tt-table .tt-time{font-size:7px;font-weight:700;color:#1e3a8a;line-height:1.2}
.tt-table th.tt-break-head{background-color:#fff4d6;background:#fff4d6;color:#8a5a00;font-size:8px;padding:2px 1px;border-color:#f1dfae}
.tt-table th.tt-break-head small{display:block;font-size:6.5px;font-weight:600;line-height:1.15}
.tt-table td.tt-break,.tt-table td.tt-event{background:#fff6de;font-weight:800;font-size:8px;color:#8a5a00;border-color:#f1dfae}
.tt-table td.tt-event-empty{background:#fcfdff}
.tt-table td.tt-event-cell{background:#fdf1e7;border:1px dashed #c9763f!important}
.tt-table .tt-event-name{font-family:Georgia,"Times New Roman",serif;font-style:italic;font-weight:700;font-size:9px;color:#8a2c2c;letter-spacing:.4px;text-transform:uppercase;line-height:1.2}
.tt-table .tt-event-time{font-family:Georgia,"Times New Roman",serif;font-size:7px;color:#a0603c;margin-top:2px}
.tt-vert{writing-mode:vertical-rl;text-orientation:mixed;transform:rotate(180deg);white-space:nowrap;letter-spacing:.6px;text-transform:uppercase}
.tt-doc-head{text-align:center;margin:0 0 10px;padding:12px 14px;border:1px solid #dbe6fb;border-radius:14px;background:#f7faff;background-color:#f7faff}
.tt-doc-head .tt-school{font-weight:800;font-size:16px;letter-spacing:.9px;text-transform:uppercase;color:#0b3d91}
.tt-doc-head .tt-title{font-weight:800;font-size:12.5px;margin-top:3px;color:#12233d;letter-spacing:.4px}
.tt-doc-head .tt-meta{font-size:8.5px;color:#5b6b85;margin-top:4px;letter-spacing:.3px}
.tt-signoff{width:100%;border-collapse:collapse;margin:16px 0 0;page-break-inside:avoid;break-inside:avoid}
.tt-signoff .tt-sign-cell{width:33.33%;vertical-align:bottom;padding:10px 14px 2px;border-top:1px solid #e3eaf5}
.tt-signoff .tt-sign-label{font-size:7.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#5b6b85;margin-bottom:4px}
.tt-signoff .tt-sign-line{font-size:9px;font-weight:700;color:#12233d;border-bottom:1px dotted #9db2cf;padding-bottom:3px;min-height:14px}
.tt-signoff .tt-sign-last{text-align:right}
.tt-table.tt-pdf{table-layout:fixed;width:100%}
.tt-table.tt-pdf thead{display:table-header-group}
.tt-table.tt-pdf tbody.tt-day-group{page-break-inside:avoid;break-inside:avoid}
.tt-table.tt-pdf tr{page-break-inside:avoid;break-inside:avoid}
.tt-page-break{page-break-after:always;break-after:page}
.tt-page{page-break-inside:auto}
.tt-table.tt-pdf td.tt-break{border-top:none;border-bottom:none}
.tt-table.tt-pdf .tt-form{width:86px}
.tt-table.tt-pdf .tt-sess{width:68px}
.tt-table.tt-pdf .tt-break-col{width:30px}
.tt-table.tt-pdf td.tt-cell{height:38px;font-size:8px}
.tt-table.tt-pdf th,.tt-table.tt-pdf td{padding:3px 2px}
';
}

function tt_styles() {
    return '<style>' . tt_css() . '</style>';
}

/**
 * Chora jedwali la ratiba kwa HTML (ukurasa) au PDF.
 *
 * Kwenye PDF kila siku huchorwa kwenye jedwali lake, hivyo tukio la ukurasa
 * wa 2/3 halivunji rowspan za siku na mapumziko (Dompdf haichori vizuri cell
 * inayovuka ukurasa) na kichwa hurudiwa juu ya kila ukurasa.
 *
 * @param array $matrix Matokeo ya tt_build_matrix()
 * @param bool  $forPdf true = muundo wa PDF (kichwa kimoja kwa kila ukurasa)
 * @param string $paper 'A4' au 'A3' — huamua siku ngapi zinatosha ukurasa mmoja
 */
function tt_render_table(array $matrix, $forPdf = false, $paper = 'A4') {
    if (empty($matrix['rows'])) {
        return '<div class="alert alert-info mb-0">No periods match the filter you selected.</div>';
    }
    $cols = $matrix['columns'];
    $forPdf = (bool)$forPdf;

    $colgroup = '<colgroup><col class="tt-day"><col class="tt-form">';
    foreach ($cols as $col) {
        $colgroup .= '<col class="' . (($col['type'] ?? '') === 'session' ? 'tt-sess' : 'tt-break-col') . '">';
    }
    $colgroup .= '</colgroup>';

    // Kichwa cha jedwali. PDF hutumia jina la mapumziko (si 'B') ili
    // kichwa kiwe wazi kwenye ukurasa wa 2.
    $renderHead = function () use ($cols, $forPdf) {
        $out = '<thead><tr><th rowspan="2">DAY</th><th rowspan="2">FORM</th>';
        foreach ($cols as $col) {
            if (($col['type'] ?? '') === 'session') {
                $out .= '<th' . (!empty($col['remedial']) ? ' class="tt-rem-head"' : '') . '>' . h((string)$col['label']) . '</th>';
            } else {
                $label = $forPdf ? (string)($col['name'] ?? 'BREAK') : 'B';
                $out .= '<th rowspan="2" class="tt-break-head" title="' . h(($col['name'] ?? 'BREAK') . ' ' . $col['start_text'] . '-' . $col['end_text']) . '">'
                      . h($label)
                      . '<small>' . h($col['start_text']) . '<br>' . h($col['end_text']) . '</small></th>';
            }
        }
        $out .= '</tr><tr>';
        foreach ($cols as $col) {
            if (($col['type'] ?? '') === 'session') {
                $out .= '<th class="tt-time">' . h($col['start_text']) . '<br>' . h($col['end_text']);
                if (!empty($col['remedial'])) $out .= '<span class="tt-rem">REMEDIAL</span>';
                $out .= '</th>';
            }
        }
        return $out . '</tr></thead>';
    };

    /* Mapumziko: kwenye ukurasa safu moja iliyounganishwa kwa siku zote;
       kwenye PDF cell ya siku husika (kila siku ni jedwali lake). */
    $bodySpan = max(1, (int)$matrix['total_rows']);
    $breakCovered = [];
    foreach ($matrix['rows'] as $r) {
        foreach (array_keys($r['event_cover'] ?? []) as $ci) {
            if (($cols[$ci]['type'] ?? '') === 'break') $breakCovered[(int)$ci] = true;
        }
    }
    $rowNo = 0;

    // Jenga safu za siku moja. $mode = 'pdf' huchora cell za mapumziko
    // zilizounganishwa kwa siku husika (bila maandishi, jina liko kichwa).
    $renderDay = function (array $dayRow, $mode) use ($cols, $breakCovered, $bodySpan, &$rowNo) {
        $span = max(1, (int)$dayRow['rowspan']);
        $day = (string)$dayRow['day'];
        $pdf = $mode === 'pdf';
        $out = '';
        $ri = 0;
        foreach ($dayRow['classes'] as $classRow) {
            $out .= '<tr>';
            if ($ri === 0) {
                $out .= '<td rowspan="' . $span . '" class="tt-day">' . h($dayRow['label']) . '</td>';
            }
            $out .= '<td class="tt-form">' . h($classRow['label'] ?? $classRow['class']['name']) . '</td>';
            foreach ($cols as $ci => $col) {
                $type = $col['type'] ?? 'session';

                // Tukio: cell moja iliyounganishwa juu ya vipindi vinavyoingiliana.
                $evSpan = $dayRow['event_start'][$ci] ?? null;
                if ($evSpan) {
                    // Tukio limeunganishwa kulingana na muda wake: safu zote
                    // zinazoguswa + madarasa yote ya siku hiyo.
                    if ($ri === 0) {
                        $out .= '<td colspan="' . (int)$evSpan['span'] . '" rowspan="' . $span . '" class="tt-event-cell">'
                              . '<div class="tt-event-name">' . h((string)$evSpan['name']) . '</div>'
                              . '<div class="tt-event-time">' . h($evSpan['start_text'] . ' - ' . $evSpan['end_text']) . '</div>'
                              . '</td>';
                    }
                    continue;
                }
                // Vipindi vilivyofunikwa na tukio hilo haviandikwi tena.
                if (!empty($dayRow['event_cover'][$ci])) continue;

                if ($type === 'break') {
                    if ($pdf) {
                        // PDF: cell moja ndefu kwa siku (inaonekana kama safu
                        // moja iliyounganishwa chini ya kichwa cha mapumziko).
                        if ($ri === 0) $out .= '<td rowspan="' . $span . '" class="tt-break"></td>';
                    } elseif (!empty($breakCovered[$ci])) {
                        if ($ri === 0) {
                            $out .= '<td rowspan="' . $span . '" class="tt-break"><div class="tt-vert">' . h((string)$col['name']) . '</div></td>';
                        }
                    } elseif ($rowNo === 0) {
                        // Cell moja kwa siku zote (ukurasa wa HTML).
                        $out .= '<td rowspan="' . $bodySpan . '" class="tt-break tt-break-merged"><div class="tt-vert">' . h((string)$col['name']) . '</div></td>';
                    }
                } elseif ($type === 'event') {
                    $evName = $col['by_day'][$day] ?? '';
                    if ($evName !== '') {
                        if ($ri === 0) {
                            $out .= '<td rowspan="' . $span . '" class="tt-event"><div class="tt-vert">' . h($evName) . '</div></td>';
                        }
                    } else {
                        $out .= '<td class="tt-event-empty"></td>';
                    }
                } else {
                    $cell = $classRow['cells'][$ci] ?? null;
                    if (!empty($cell['double_skip'])) continue; // imechukuliwa na double
                    $dspan = !empty($cell['double']) ? 2 : 0;
                    $out .= '<td' . ($dspan ? ' colspan="' . $dspan . '"' : '')
                          . ' class="tt-cell' . ($cell ? '' : ' tt-empty')
                          . ($dspan ? ' tt-double' : '')
                          . (!empty($col['remedial']) ? ' tt-remedial' : '') . '">';
                    if ($cell) {
                        $out .= '<div class="tt-subject">' . h($cell['name']) . '</div>';
                        if (!empty($cell['teacher_short'])) $out .= '<div class="tt-teacher">(' . h($cell['teacher_short']) . ')</div>';
                        if ($dspan) $out .= '<div class="tt-double-tag">DOUBLE</div>';
                    }
                    $out .= '</td>';
                }
            }
            $out .= '</tr>';
            $ri++;
            $rowNo++;
        }
        return $out;
    };

    if ($forPdf) {
        /* Kichwa kimoja kwa kila ukurasa, siku za wiki zikipangwa chini yake.
           Ili siku isikatike katikati, hesabu safu zinazotosha ukurasa mmoja
           (Dompdf haitekelezi page-break-inside:avoid kwenye tbody) na
           vunja kurasa kati ya siku. */
        $pageHeight = $paper === 'A3' ? 841.89 : 595.28; // pt, landscape
        $usable = $pageHeight - 34.0 /* margins */ - 22.0 /* page number */ - 26.0 /* header */;
        $rowsPerPage = max(4, (int)floor($usable / 38.0)); // ~38pt kwa kila safu (na nafasi ya ziada)

        $chunks = [];
        $current = [];
        $used = 0;
        foreach ($matrix['rows'] as $dayRow) {
            $rows = max(1, count($dayRow['classes']));
            if ($current && $used + $rows > $rowsPerPage) {
                $chunks[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = $dayRow;
            $used += $rows;
        }
        if ($current) $chunks[] = $current;

        $html = '';
        $lastChunk = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $html .= '<div class="tt-page' . ($i < $lastChunk ? ' tt-page-break' : '') . '">'
                   . '<table class="tt-table tt-pdf">' . $colgroup . $renderHead();
            foreach ($chunk as $dayRow) {
                $html .= '<tbody class="tt-day-group">' . $renderDay($dayRow, 'pdf') . '</tbody>';
            }
            $html .= '</table></div>';
        }
        return $html;
    }

    $html = '<div class="tt-wrap"><table class="tt-table">' . $colgroup . $renderHead() . '<tbody>';
    foreach ($matrix['rows'] as $dayRow) $html .= $renderDay($dayRow, 'screen');
    return $html . '</tbody></table></div>';
}

/* ---------------- Export ---------------- */

/** Tuma ratiba kama .xlsx yenye merge za siku, BREAK na matukio. */
function tt_send_xlsx($filename, $title, $meta, array $matrix, $paper = 'A3', $schoolName = '') {
    if (!load_vendor()) { http_response_code(500); die('The .xlsx export requires PhpSpreadsheet. Run "composer install".'); }
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Timetable');

    $cols = $matrix['columns'];
    $nCols = 2 + count($cols);
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nCols);
    // Kichwa: jina la shule, jina la ratiba, kisha mistari ya filter (kama ipo).
    $headRow = 5;
    $subRow = 6;
    $bodyStart = 7;
    $bodyEnd = $bodyStart + max(0, (int)$matrix['total_rows']) - 1;

    if ($schoolName !== '') {
        $ws->setCellValue('A1', strtoupper($schoolName));
        $ws->mergeCells('A1:' . $last . '1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getStyle('A1')->getAlignment()->setHorizontal('center');
        $ws->getRowDimension(1)->setRowHeight(22);
    }
    $ws->setCellValue('A2', $title);
    $ws->mergeCells('A2:' . $last . '2');
    $ws->getStyle('A2')->getFont()->setBold(true)->setSize(12);
    $ws->getStyle('A2')->getAlignment()->setHorizontal('center');
    if ($meta !== '') {
        $ws->setCellValue('A3', $meta);
        $ws->mergeCells('A3:' . $last . '3');
        $ws->getStyle('A3')->getAlignment()->setHorizontal('center')->setWrapText(true);
        $ws->getRowDimension(3)->setRowHeight(22);
    }
    $ws->getRowDimension(2)->setRowHeight(20);

    $ws->setCellValue('A' . $headRow, 'DAY');
    $ws->setCellValue('B' . $headRow, 'FORM');
    $ws->mergeCells('A' . $headRow . ':A' . $subRow);
    $ws->mergeCells('B' . $headRow . ':B' . $subRow);

    foreach ($cols as $i => $col) {
        $ci = 3 + $i;
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
        if (($col['type'] ?? '') !== 'session') {
            $ws->setCellValue($letter . $headRow, 'B');
            $ws->mergeCells($letter . $headRow . ':' . $letter . $subRow);
            $ws->getColumnDimension($letter)->setWidth(7);
        } else {
            $ws->setCellValue($letter . $headRow, (string)$col['label']);
            $ws->setCellValue($letter . $subRow, $col['start_text'] . '-' . $col['end_text']
                . (!empty($col['remedial']) ? "\nREM" : ''));
            $ws->getColumnDimension($letter)->setWidth(16);
        }
    }
    $ws->getColumnDimension('A')->setWidth(16);
    $ws->getColumnDimension('B')->setWidth(20);
    $ws->getStyle('A' . $headRow . ':' . $last . $subRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 8],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);
    $ws->getRowDimension($headRow)->setRowHeight(22);
    $ws->getRowDimension($subRow)->setRowHeight(24);

    // Safu za remedial zina rangi ya kijani ili zitofautishwe na vipindi vya lazima.
    foreach ($cols as $i => $col) {
        if (($col['type'] ?? '') !== 'session' || empty($col['remedial'])) continue;
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i);
        $ws->getStyle($letter . $headRow . ':' . $letter . $subRow)->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E3F1E8']],
        ]);
    }

    // Safu za mapumziko zinaunganishwa kwa siku zote (jina mara moja).
    $breakCovered = [];
    foreach ($matrix['rows'] as $mr) {
        foreach (array_keys($mr['event_cover'] ?? []) as $ci) {
            if (($cols[$ci]['type'] ?? '') === 'break') $breakCovered[(int)$ci] = true;
        }
    }
    $rowNo = 0;

    $r = $bodyStart;
    foreach ($matrix['rows'] as $dayRow) {
        $span = max(1, (int)$dayRow['rowspan']);
        $day = (string)$dayRow['day'];
        $ws->setCellValue('A' . $r, $dayRow['label']);
        if ($span > 1) $ws->mergeCells('A' . $r . ':A' . ($r + $span - 1));
        $ws->getStyle('A' . $r . ':A' . ($r + $span - 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F1F3F5']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        ]);

        $ri = 0;
        foreach ($dayRow['classes'] as $classRow) {
            if ($r > $bodyEnd) break;
            $ws->setCellValue('B' . $r, (string)($classRow['label'] ?? $classRow['class']['name']));
            $ws->getStyle('B' . $r)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ]);
            $ws->getRowDimension($r)->setRowHeight(46);

            foreach ($cols as $i => $col) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i);
                $type = $col['type'] ?? 'session';

                // Tukio limeunganishwa juu ya vipindi vinavyoingiliana.
                $evSpan = $dayRow['event_start'][$i] ?? null;
                if ($evSpan) {
                    if ($ri === 0) {
                        $l2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i + (int)$evSpan['span'] - 1);
                        $ws->setCellValue($letter . $r, strtoupper((string)$evSpan['name']) . "\n" . $evSpan['start_text'] . '-' . $evSpan['end_text']);
                        $range = $letter . $r . ':' . $l2 . ($r + $span - 1);
                        if ($evSpan['span'] > 1 || $span > 1) $ws->mergeCells($range);
                        $ws->getStyle($range)->applyFromArray([
                            'font' => ['italic' => true, 'bold' => true, 'size' => 9, 'color' => ['rgb' => '8A2C2C']],
                            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FDF0E6']],
                            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                        ]);
                    }
                    continue;
                }
                if (!empty($dayRow['event_cover'][$i])) continue;

                if ($type === 'session') {
                    $cell = $classRow['cells'][$i] ?? null;
                    if ($cell && !empty($cell['double_skip'])) continue; // imechukuliwa na double
                    $val = '';
                    if ($cell) {
                        $val = strtoupper((string)$cell['name']);
                        if (!empty($cell['teacher_short'])) $val .= "\n(" . strtoupper($cell['teacher_short']) . ')';
                    }
                    $ws->setCellValue($letter . $r, $val);
                    $ws->getStyle($letter . $r)->applyFromArray([
                        'font' => ['size' => 8],
                        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                    ]);
                    if ($cell && !empty($cell['double'])) {
                        // Vipindi double: cell moja juu ya safu mbili.
                        $l2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i + 1);
                        $range = $letter . $r . ':' . $l2 . $r;
                        $ws->mergeCells($range);
                        $ws->getStyle($range)->applyFromArray([
                            'font' => ['bold' => true, 'size' => 8],
                            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EEF6FF']],
                            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                        ]);
                    }
                } elseif ($type === 'break') {
                    if (!empty($breakCovered[$i])) {
                        // Tukio linavuka safu hii: merge ya siku moja.
                        if ($ri === 0) {
                            $ws->setCellValue($letter . $r, strtoupper((string)$col['name']) . "\n" . $col['start_text'] . "\n" . $col['end_text']);
                            if ($span > 1) $ws->mergeCells($letter . $r . ':' . $letter . ($r + $span - 1));
                            $ws->getStyle($letter . $r . ':' . $letter . ($r + $span - 1))->applyFromArray([
                                'font' => ['bold' => true, 'size' => 8],
                                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF3CD']],
                                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true, 'textRotation' => 90],
                            ]);
                        }
                    } elseif ($rowNo === 0) {
                        // Cell moja kwa siku zote tano.
                        $ws->setCellValue($letter . $r, strtoupper((string)$col['name']) . "\n" . $col['start_text'] . " - " . $col['end_text']);
                        $range = $letter . $bodyStart . ':' . $letter . max($bodyStart, $bodyEnd);
                        if ($bodyEnd > $bodyStart) $ws->mergeCells($range);
                        $ws->getStyle($range)->applyFromArray([
                            'font' => ['bold' => true, 'size' => 8],
                            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF3CD']],
                            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true, 'textRotation' => 90],
                        ]);
                    }
                } else { // event
                    $evName = $col['by_day'][$day] ?? '';
                    if ($evName !== '' && $ri === 0) {
                        $ws->setCellValue($letter . $r, strtoupper($evName) . "\n" . $col['start_text'] . "\n" . $col['end_text']);
                        if ($span > 1) $ws->mergeCells($letter . $r . ':' . $letter . ($r + $span - 1));
                        $ws->getStyle($letter . $r . ':' . $letter . ($r + $span - 1))->applyFromArray([
                            'font' => ['bold' => true, 'size' => 8],
                            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF3CD']],
                            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true, 'textRotation' => 90],
                        ]);
                    }
                }
            }
            $r++;
            $ri++;
            $rowNo++;
        }
    }

    if ($bodyEnd >= $bodyStart) {
        $ws->getStyle('A' . $bodyStart . ':' . $last . $bodyEnd)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
        ]);
    }

    /* Sahihi mwisho wa ratiba: Academic Master, stamp na ofisi ya masomo. */
    $signRow = max($bodyStart, $bodyEnd) + 2;
    $third = max(1, (int)ceil($nCols / 3));
    $letter = function ($n) {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    };
    $masterName = tt_academic_master_name();
    $signBits = [
        [1, min($nCols, $third), 'Senior Academic Master Name: ' . ($masterName !== '' ? strtoupper($masterName) : '........................')],
        [min($nCols, $third + 1), min($nCols, 2 * $third), 'Official Stamp: ........................'],
        [min($nCols, 2 * $third + 1), $nCols, 'Prepared by Academic Office'],
    ];
    foreach ($signBits as $bit) {
        $from = $letter($bit[0]);
        $to = $letter($bit[1]);
        $ws->setCellValue($from . $signRow, $bit[2]);
        if ($from !== $to) $ws->mergeCells($from . $signRow . ':' . $to . $signRow);
        $ws->getStyle($from . $signRow . ':' . $to . $signRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 8],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            'borders' => ['top' => ['borderStyle' => 'thin', 'color' => ['rgb' => '9DB2CF']]],
        ]);
    }
    $ws->getRowDimension($signRow)->setRowHeight(28);

    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ws->getPageSetup()->setPaperSize($paper === 'A4'
        ? \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
        : \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3);
    $ws->getPageSetup()->setFitToWidth(1);
    $ws->getPageSetup()->setFitToHeight(0);
    $ws->getPageSetup()->setHorizontalCentered(true);
    $ws->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3);
    $ws->getPageSetup()->setPrintArea('A1:' . $last . $signRow);
    send_xlsx($wb, $filename);
}

/** Andika namba za kurasa ("Page 1 of 3") chini katikati ya PDF. */
function tt_pdf_page_numbers($dom, $color = [0.36, 0.42, 0.53]) {
    $canvas = $dom->getCanvas();
    $font = $dom->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
    $width = $dom->getFontMetrics()->getTextWidth($label, $font, 7.5);
    $x = max(8, ($canvas->get_width() - $width) / 2);
    $canvas->page_text($x, $canvas->get_height() - 14, $label, $font, 7.5, $color);
}

/** Tuma ratiba kama PDF (A4/A3 landscape) kwa Dompdf. */
function tt_send_pdf($filename, $title, $meta, array $matrix, $paper = 'A4', $schoolName = '') {
    load_vendor();
    $inner = '<div class="tt-doc-head">';
    if ($schoolName !== '') $inner .= '<div class="tt-school">' . h(strtoupper($schoolName)) . '</div>';
    $inner .= '<div class="tt-title">' . h($title) . '</div>';
    if ($meta !== '') $inner .= '<div class="tt-meta">' . h($meta) . '</div>';
    $inner .= '</div>' . tt_render_table($matrix, true, $paper) . tt_render_footer(tt_academic_master_name());

    $size = $paper === 'A3' ? 'A3' : 'A4';
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . h($title) . '</title>'
          . '<style>@page{size:' . $size . ' landscape;margin:6mm}body{margin:0;font-family:DejaVu Sans,Arial,sans-serif;font-size:8px}'
          . tt_css() . '</style></head><body>' . $inner . '</body></html>';

    if (!class_exists('Dompdf\\Dompdf')) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . h($title) . '</title><style>@page{size:' . $size . ' landscape;margin:6mm}'
           . tt_css() . '</style></head><body onload="window.print()">' . $inner . '</body></html>';
        exit();
    }
    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', false);
    $opts->set('defaultFont', 'DejaVu Sans');
    $dom = new \Dompdf\Dompdf($opts);
    $dom->loadHtml($html, 'UTF-8');
    $dom->setPaper($size, 'landscape');
    $dom->render();

    tt_pdf_page_numbers($dom);

    while (ob_get_level()) ob_end_clean();
    $dom->stream(preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '.pdf', ['Attachment' => true]);
    exit();
}

/* ---------------- Metadata ---------------- */

/**
 * Jina la Academic Master aliyepangiwa shule (kama yupo).
 *
 * Hutumika kwenye sehemu ya sahihi mwisho wa ratiba; kama hakuna
 * aliyepangiwa wadhifa huo hurudisha string tupu (mistari ya dots).
 */
function tt_academic_master_name($school_id = 0) {
    $school_id = (int)$school_id;
    if ($school_id <= 0) {
        $sc = active_school();
        $school_id = (int)($sc['id'] ?? 0);
    }
    if ($school_id <= 0) return '';
    try {
        return trim((string)(q_val("SELECT full_name FROM users WHERE school_id = ? AND position = 'academic_master' ORDER BY id LIMIT 1", [$school_id]) ?? ''));
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Sehemu ya sahihi mwisho wa ratiba: Senior Academic Master, stamp na ofisi.
 *
 * @param string $masterName Jina la Academic Master (tupu = mstari wa dots)
 */
function tt_render_footer($masterName = '') {
    $masterName = trim((string)$masterName);
    $dots = '...............................................';
    $html  = '<table class="tt-signoff"><tr>';
    $html .= '<td class="tt-sign-cell"><div class="tt-sign-label">Senior Academic Master Name:</div>'
           . '<div class="tt-sign-line">' . h($masterName !== '' ? strtoupper($masterName) : $dots) . '</div></td>';
    $html .= '<td class="tt-sign-cell"><div class="tt-sign-label">Official Stamp:</div>'
           . '<div class="tt-sign-line">' . h($dots) . '</div></td>';
    $html .= '<td class="tt-sign-cell tt-sign-last"><div class="tt-sign-label">Prepared by</div>'
           . '<div class="tt-sign-line">Academic Office</div></td>';
    $html .= '</tr></table>';
    return $html;
}

/** Jina la shule kwa kichwa cha ratiba: huongeza "SECONDARY SCHOOL". */
function tt_school_title($name) {
    $name = strtoupper(trim((string)$name));
    if ($name === '') $name = strtoupper((string)system_name());
    if ($name !== '' && strpos($name, 'SECONDARY SCHOOL') === false) $name .= ' SECONDARY SCHOOL';
    return $name;
}
