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
        /* ---- Masomo ya msingi na lazima ---- */
        'maths'      => ['name' => 'Mathematics', 'lower' => 5, 'upper' => 5, 'codes' => ['BMATH', 'B/MATH', 'MATH'], 'names' => ['mathematics', 'basic mathematics', 'hisabati']],
        'kiswahili'  => ['name' => 'Kiswahili', 'lower' => 4, 'upper' => 4, 'codes' => ['KISW'], 'names' => ['kiswahili']],
        'english'    => ['name' => 'English Language', 'lower' => 5, 'upper' => 5, 'codes' => ['ENGL'], 'names' => ['english language', 'english', 'kiingereza']],
        'business'   => ['name' => 'Business Studies', 'lower' => 3, 'upper' => 3, 'codes' => ['BUS', 'COMM'], 'names' => ['business studies', 'business', 'commerce', 'biashara']],
        'geography'  => ['name' => 'Geography', 'lower' => 2, 'upper' => 3, 'codes' => ['GEO'], 'names' => ['geography', 'jiografia']],
        'maadili'    => ['name' => 'Historia ya Tanzania na Maadili', 'lower' => 3, 'upper' => 3, 'codes' => ['CIV'], 'names' => ['historia ya tanzania na maadili', 'historia ya tanzania', 'civics', 'uraia', 'maadili']],

        /* ---- Masomo ya kuchagua ---- */
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
 * Vipindi vya wiki kwa somo kulingana na mtaala, au null kama halipo.
 */
function tt_curriculum_periods(array $subject, $band) {
    if ($band !== 'lower' && $band !== 'upper') return null;
    $code = strtoupper(trim((string)($subject['code'] ?? '')));
    $abbr = strtoupper(trim((string)($subject['abbr'] ?? '')));
    $name = strtolower(trim((string)($subject['name'] ?? '')));
    if ($name === '' && $code === '' && $abbr === '') return null;
    // A-Level haitumii mtaala wa O-Level.
    if (strpos($name, 'advanced') !== false || strpos($name, 'general studies') !== false) return null;

    $rows = tt_curriculum();
    foreach ($rows as $r) {
        if ($code !== '' && in_array($code, $r['codes'], true)) return (int)$r[$band];
        if ($abbr !== '' && in_array($abbr, $r['codes'], true)) return (int)$r[$band];
    }
    foreach ($rows as $r) {
        if (in_array($name, $r['names'], true)) return (int)$r[$band];
    }
    // Mwisho: jina lililo ndani ya jina (alias ndefu inashinda).
    $hit = null;
    $len = 0;
    foreach ($rows as $r) {
        foreach ($r['names'] as $alias) {
            if ($alias === '' || strpos($name, $alias) === false) continue;
            if (strlen($alias) > $len) { $hit = (int)$r[$band]; $len = strlen($alias); }
        }
    }
    return $hit;
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
        $fixed = 0;
        foreach ($subs as $s) {
            $p = tt_curriculum_periods($s, $band);
            if ($p === null) continue;
            $targets[(int)$s['id']] = $p;
            $fixed += $p;
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

    $sessions = (int)($in['sessions_per_day'] ?? 11);
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

    $capacity = tt_capacity([
        'days' => $days,
        'slots' => $slots,
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

    // Nafasi za vipindi kwa kila siku (bila saa za matukio)
    $sessionItems = [];
    foreach (($data['slots']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'session') $sessionItems[] = $it;
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

    $totalRequired = 0;
    foreach ($needByEntity as $ent) foreach ($ent['subjects'] as $n) $totalRequired += $n;

    $best = null;
    $attempts = 40;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        mt_srand(100003 + $attempt * 7919);

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
                    'cap' => $dayCountTarget,
                    'day' => [],
                ];
            }
        }

        $busy = [];       // [day][period][teacher_id]
        $lastSid = [];    // [entity][day] = somo la kipindi kilichopita
        $assignments = [];
        $assigned = 0;

        foreach ($data['days'] as $day) {
            foreach ($daySlots[$day] as $slot) {
                foreach ($entityOrder as $ekey) {
                    $ent = $needByEntity[$ekey];
                    $cid = (int)$ent['class_id'];
                    $stream = (string)$ent['stream'];
                    $pick = null;
                    $pickScore = null;
                    $last = $lastSid[$ekey][$day] ?? 0;

                    foreach ($tasks[$ekey] as $sid => $t) {
                        if ($t['left'] <= 0) continue;
                        $tid = (int)$t['teacher_id'];
                        if ($tid && !empty($busy[$day][$slot['no']][$tid])) continue;
                        $dc = $t['day'][$day] ?? 0;
                        if ($dc >= $t['cap']) continue;

                        $score = [
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
                            if ($tid && !empty($busy[$day][$slot['no']][$tid])) continue;
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
                    }
                    if ($pick === null) continue;

                    $t = $tasks[$ekey][$pick];
                    $assignments[] = [
                        'class_id' => $cid,
                        'subject_id' => $pick,
                        'stream' => $stream,
                        'teacher_id' => (int)$t['teacher_id'],
                        'day' => $day,
                        'period_no' => (int)$slot['no'],
                        'start' => $slot['start_text'],
                        'end' => $slot['end_text'],
                    ];
                    $tasks[$ekey][$pick]['left']--;
                    $tasks[$ekey][$pick]['day'][$day] = ($tasks[$ekey][$pick]['day'][$day] ?? 0) + 1;
                    if ($t['teacher_id']) $busy[$day][$slot['no']][(int)$t['teacher_id']] = true;
                    $lastSid[$ekey][$day] = $pick;
                    $assigned++;
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

    return [
        'ok' => true,
        'entries' => $best['entries'],
        'assigned' => $best['assigned'],
        'required' => $totalRequired,
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
            ];
        }
    }

    // Matukio: safu moja kwa kila saa; jina hubadilika kwa siku
    if ($config['events']) {
        $evCols = [];
        foreach ($config['events'] as $ev) {
            $k = $ev['start_min'] . '-' . $ev['end_min'];
            if (isset($breakKeys[$k])) continue; // linaingana na mapumziko
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
    ];
}

/* ---------------- CSS na uchoraji ---------------- */

function tt_css() {
    return '
.tt-wrap{overflow-x:auto}
.tt-table{border-collapse:collapse;width:100%;background:#fff;table-layout:fixed;margin:0}
.tt-table th,.tt-table td{border:1px solid #495057;text-align:center;vertical-align:middle;padding:3px 2px}
.tt-table thead th{background:#e9ecef;font-size:9px;font-weight:700;letter-spacing:.2px}
.tt-table .tt-day{width:82px;background:#f1f3f5;font-weight:700;font-size:9px}
.tt-table .tt-form{width:82px;background:#f8f9fa;font-weight:700;font-size:9px}
.tt-table .tt-sess{width:74px}
.tt-table .tt-break-col{width:28px}
.tt-table td.tt-day{background:#f1f3f5}
.tt-table td.tt-form{background:#f8f9fa}
.tt-table td.tt-cell{height:44px;font-size:8.5px;white-space:normal;line-height:1.15}
.tt-table td.tt-empty{background:#fff}
.tt-table .tt-subject{font-weight:700;text-transform:uppercase;font-size:8.5px}
.tt-table .tt-teacher{color:#6c757d;font-size:7.5px;line-height:1.1;margin-top:2px;text-transform:uppercase}
.tt-table .tt-time{font-size:7px;font-weight:400;color:#495057;line-height:1.15}
.tt-table th.tt-break-head{background:#fff3cd;font-size:8px;padding:2px 1px}
.tt-table th.tt-break-head small{display:block;font-size:6.5px;font-weight:400;line-height:1.15}
.tt-table td.tt-break,.tt-table td.tt-event{background:#fff3cd;font-weight:700;font-size:8px}
.tt-table td.tt-event-empty{background:#fdfdfd}
.tt-vert{writing-mode:vertical-rl;text-orientation:mixed;transform:rotate(180deg);white-space:nowrap;letter-spacing:.6px;text-transform:uppercase}
.tt-doc-head{text-align:center;margin:0 0 6px}
.tt-doc-head .tt-school{font-weight:700;font-size:12px;text-transform:uppercase}
.tt-doc-head .tt-title{font-weight:700;font-size:11px;margin-top:1px}
.tt-doc-head .tt-meta{font-size:8px;color:#495057;margin-top:2px}
';
}

function tt_styles() {
    return '<style>' . tt_css() . '</style>';
}

/** Chora jedwali la ratiba kwa HTML (hutumika kwenye ukurasa na PDF). */
function tt_render_table(array $matrix) {
    if (empty($matrix['rows'])) {
        return '<div class="alert alert-info mb-0">No periods match the filter you selected.</div>';
    }
    $cols = $matrix['columns'];
    $html = '<div class="tt-wrap"><table class="tt-table"><colgroup><col class="tt-day"><col class="tt-form">';
    foreach ($cols as $col) {
        $html .= '<col class="' . (($col['type'] ?? '') === 'session' ? 'tt-sess' : 'tt-break-col') . '">';
    }
    $html .= '</colgroup><thead><tr><th rowspan="2">DAY</th><th rowspan="2">FORM</th>';
    foreach ($cols as $col) {
        if (($col['type'] ?? '') !== 'session') {
            $html .= '<th rowspan="2" class="tt-break-head" title="' . h(($col['name'] ?? 'BREAK') . ' ' . $col['start_text'] . '-' . $col['end_text']) . '">B'
                   . '<small>' . h($col['start_text']) . '<br>' . h($col['end_text']) . '</small></th>';
        } else {
            $html .= '<th>' . h((string)$col['label']) . '</th>';
        }
    }
    $html .= '</tr><tr>';
    foreach ($cols as $col) {
        if (($col['type'] ?? '') === 'session') {
            $html .= '<th class="tt-time">' . h($col['start_text']) . '<br>' . h($col['end_text']) . '</th>';
        }
    }
    $html .= '</tr></thead><tbody>';

    foreach ($matrix['rows'] as $dayRow) {
        $span = max(1, (int)$dayRow['rowspan']);
        $day = (string)$dayRow['day'];
        $ri = 0;
        foreach ($dayRow['classes'] as $classRow) {
            $html .= '<tr>';
            if ($ri === 0) $html .= '<td rowspan="' . $span . '" class="tt-day">' . h($dayRow['label']) . '</td>';
            $html .= '<td class="tt-form">' . h($classRow['label'] ?? $classRow['class']['name']) . '</td>';
            foreach ($cols as $ci => $col) {
                $type = $col['type'] ?? 'session';
                if ($type === 'break') {
                    if ($ri === 0) {
                        $html .= '<td rowspan="' . $span . '" class="tt-break"><div class="tt-vert">' . h((string)$col['name']) . '</div></td>';
                    }
                } elseif ($type === 'event') {
                    $evName = $col['by_day'][$day] ?? '';
                    if ($evName !== '') {
                        if ($ri === 0) {
                            $html .= '<td rowspan="' . $span . '" class="tt-event"><div class="tt-vert">' . h($evName) . '</div></td>';
                        }
                    } else {
                        $html .= '<td class="tt-event-empty"></td>';
                    }
                } else {
                    $cell = $classRow['cells'][$ci] ?? null;
                    $html .= '<td class="tt-cell' . ($cell ? '' : ' tt-empty') . '">';
                    if ($cell) {
                        $html .= '<div class="tt-subject">' . h($cell['name']) . '</div>';
                        if (!empty($cell['teacher_short'])) $html .= '<div class="tt-teacher">(' . h($cell['teacher_short']) . ')</div>';
                    }
                    $html .= '</td>';
                }
            }
            $html .= '</tr>';
            $ri++;
        }
    }
    $html .= '</tbody></table></div>';
    return $html;
}

/* ---------------- Export ---------------- */

/** Tuma ratiba kama .xlsx yenye merge za siku, BREAK na matukio. */
function tt_send_xlsx($filename, $title, $meta, array $matrix, $paper = 'A3') {
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
    if ($meta !== '') {
        $ws->setCellValue('A2', $meta);
        $ws->mergeCells('A2:' . $last . '2');
        $ws->getStyle('A2')->getAlignment()->setHorizontal('center')->setWrapText(true);
    }
    $ws->getRowDimension(1)->setRowHeight(24);
    $ws->getRowDimension(2)->setRowHeight(30);

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
            $ws->setCellValue($letter . $subRow, $col['start_text'] . '-' . $col['end_text']);
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
    $ws->getRowDimension($headRow)->setRowHeight(20);
    $ws->getRowDimension($subRow)->setRowHeight(20);

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
                if ($type === 'session') {
                    $cell = $classRow['cells'][$i] ?? null;
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
                } elseif ($type === 'break') {
                    if ($ri === 0) {
                        $ws->setCellValue($letter . $r, strtoupper((string)$col['name']) . "\n" . $col['start_text'] . "\n" . $col['end_text']);
                        if ($span > 1) $ws->mergeCells($letter . $r . ':' . $letter . ($r + $span - 1));
                        $ws->getStyle($letter . $r . ':' . $letter . ($r + $span - 1))->applyFromArray([
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
        }
    }

    if ($bodyEnd >= $bodyStart) {
        $ws->getStyle('A' . $bodyStart . ':' . $last . $bodyEnd)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '495057']]],
        ]);
    }

    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ws->getPageSetup()->setPaperSize($paper === 'A4'
        ? \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
        : \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3);
    $ws->getPageSetup()->setFitToWidth(1);
    $ws->getPageSetup()->setFitToHeight(0);
    $ws->getPageSetup()->setHorizontalCentered(true);
    $ws->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3);
    $ws->getPageSetup()->setPrintArea('A1:' . $last . max($bodyStart, $bodyEnd));
    send_xlsx($wb, $filename);
}

/** Tuma ratiba kama PDF (A4/A3 landscape) kwa Dompdf. */
function tt_send_pdf($filename, $title, $meta, array $matrix, $paper = 'A4', $schoolName = '') {
    load_vendor();
    $inner = '<div class="tt-doc-head">';
    if ($schoolName !== '') $inner .= '<div class="tt-school">' . h(strtoupper($schoolName)) . '</div>';
    $inner .= '<div class="tt-title">' . h($title) . '</div>';
    if ($meta !== '') $inner .= '<div class="tt-meta">' . h($meta) . '</div>';
    $inner .= '</div>' . tt_render_table($matrix);

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
    while (ob_get_level()) ob_end_clean();
    $dom->stream(preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '.pdf', ['Attachment' => true]);
    exit();
}

/* ---------------- Metadata ---------------- */

function tt_meta_bits(array $tt) {
    $bits = [];
    $bits[] = 'GROUP: ' . strtoupper(tt_group_label($tt['group_level'] ?? 'ordinary'));
    $bits[] = 'SESSIONS PER DAY: ' . (int)$tt['sessions_per_day'];
    $bits[] = 'SESSION DURATION: ' . (int)$tt['session_duration'] . ' MINUTES';
    $bits[] = 'TIME: ' . substr((string)$tt['start_time'], 0, 5) . ' - ' . substr((string)$tt['end_time'], 0, 5);
    $bits[] = 'SATURDAY: ' . (!empty($tt['include_saturday']) ? 'YES' : 'NO');
    $bits[] = 'SUNDAY: ' . (!empty($tt['include_sunday']) ? 'YES' : 'NO');
    return $bits;
}

function tt_meta_text(array $tt) {
    return implode('   |   ', tt_meta_bits($tt));
}
