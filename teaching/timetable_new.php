<?php
require_once __DIR__ . '/../lib/timetable.php';
require_once __DIR__ . '/../lib/timetable_auto.php'; // tta_plan_streams() kwa mgawanyo wa stream kutoka idadi ya wanafunzi
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();

$school_id = active_school_id();
$me = user();
$ready = tt_tables_ready();
$years = teaching_years();
$year = teaching_year((int)($_GET['year_id'] ?? $_POST['year_id'] ?? 0));

$group = (string)($_GET['group'] ?? $_POST['group'] ?? 'ordinary');
if (!in_array($group, ['ordinary', 'advance', 'all'], true)) $group = 'ordinary';

$isPost = is_post();

$classes = tt_classes_for_group($group);
$classMap = [];
foreach ($classes as $c) $classMap[(int)$c['id']] = $c;

/* ---------------- Mikondo (streams) ---------------- */

// Idadi ya mikondo kwa kila darasa; 0 = kutoka streams za wanafunzi.
$countBag = is_array($_POST['streams_class'] ?? null) ? $_POST['streams_class']
          : (is_array($_GET['streams_class'] ?? null) ? $_GET['streams_class'] : []);
$classStreamCount = [];
foreach ($countBag as $cid => $n) {
    $classStreamCount[(int)$cid] = max(0, min(TTA_MAX_STREAMS, (int)$n));
}

$streamSuggestions = teaching_stream_options($school_id);
$fStreamsRaw = trim((string)($_POST['streams'] ?? $_GET['streams'] ?? ''));
$providedLabels = tt_normalize_streams($fStreamsRaw);

// Mpango wa kila darasa: automatic (0) au idadi iliyowekwa kwa mkono.
$classPlans = [];
if ($classMap) {
    try {
        foreach (array_keys($classMap) as $cid) {
            $classPlans[(int)$cid] = tta_plan_streams($school_id, (int)$cid, (int)($classStreamCount[(int)$cid] ?? 0));
        }
    } catch (Exception $e) {
        // Bila safu ya `stream` kwenye students, tunaendelea bila kugawanya.
        $classPlans = [];
    }
}

// Majina ya mikondo kwa kila darasa (A, B, C... au yaliyowekwa kwa mkono).
$streamMap = tta_stream_map($classPlans, $providedLabels);
$labelPool = $streamMap['labels'];
$classStreams = $streamMap['classes'];
foreach ($classMap as $cid => $c) {
    if (!isset($classStreams[(int)$cid])) $classStreams[(int)$cid] = [''];
}

$defaultBreaks = [
    ['name' => 'Tea Break', 'start' => '10:40', 'end' => '11:10'],
    ['name' => 'Lunch Break', 'start' => '14:30', 'end' => '15:10'],
];
$defaultEvents = [
    ['name' => 'Religion', 'day' => 'FRI', 'start' => '13:20', 'end' => '14:00'],
];

$errors = [];
$savedId = 0;

if (is_post()) {
    csrf_check();
    // Mikondo ya kila darasa inaingizwa kwa mpangilio wa $labelPool (A, B, C...).
    $input = $_POST;
    $input['streams'] = $labelPool;
    // Tumia maadili yaliyoonyeshwa kwenye fomu (defaults za kila stream
    // zimekwisha kujazwa), si maadili ghafi ya POST pekee.
    $input['required'] = $reqValues;
    $check = tt_validate_input($input, $school_id);
    $data = $check['data'];
    $errors = $check['errors'];

    if (!$errors) {
        $assignMap = teaching_assignments_map($school_id);
        $weekSlots = (int)($data['capacity'] ?? 0);

        // Mgongano wa kudumu: mwalimu anahitajika vipindi vingi kuliko nafasi za wiki.
        foreach (tt_teacher_load($data['requirements'], $assignMap, $weekSlots) as $t) {
            $errors[] = 'Teacher ' . $t['name'] . ' is required for ' . $t['periods']
                . ' periods a week but only ' . $weekSlots . ' slots exist. Reduce that teacher\'s periods, add sessions per day, or include Saturday.';
        }

        if (!$errors) {
            $result = tt_generate($data, $assignMap);
            if (!$result['ok']) {
                $errors = array_merge($errors, $result['errors']);
            } else {
                try {
                    $savedId = tt_save($school_id, (int)$year['id'], $data, $data['requirements'], $result['entries'], (int)($me['id'] ?? 0));
                    $clash = tt_clash_report($result['entries']);
                    flash('success', 'The timetable was generated successfully (' . count($result['entries']) . ' periods, '
                        . (int)$clash['teacher'] . ' teacher clashes, ' . (int)$clash['stream'] . ' class clashes).'
                        . ' Click <b>Publish</b> so that teachers and students see it as the official timetable.');
                    redirect('teaching/timetable.php?id=' . $savedId);
                } catch (Exception $e) {
                    $errors[] = 'Could not save the timetable: ' . $e->getMessage();
                }
            }
        }
    }
}

/* ---------------- Form values ---------------- */

$fSessions = $isPost ? (int)($_POST['sessions_per_day'] ?? 11) : 11;
$fSessions = max(1, min(14, $fSessions));
$fDuration = $isPost ? (int)($_POST['session_duration'] ?? 40) : 40;
$fDuration = max(15, min(120, $fDuration));
$fStart = $isPost ? trim((string)($_POST['start_time'] ?? '08:00')) : '08:00';
if (teaching_time_minutes($fStart) === null) $fStart = '08:00';
$fEnd = $isPost ? trim((string)($_POST['end_time'] ?? '16:30')) : '16:30';
if (teaching_time_minutes($fEnd) === null) $fEnd = '16:30';
$fSat = $isPost ? !empty($_POST['include_saturday']) : false;
$fSun = $isPost ? !empty($_POST['include_sunday']) : false;

$rawBreaks = $isPost ? (is_array($_POST['breaks'] ?? null) ? $_POST['breaks'] : []) : $defaultBreaks;
$rawEvents = $isPost ? (is_array($_POST['events'] ?? null) ? $_POST['events'] : []) : $defaultEvents;
if (!$rawBreaks) $rawBreaks = [['name' => '', 'start' => '', 'end' => '']];
if (!$rawEvents) $rawEvents = [['name' => '', 'day' => '', 'start' => '', 'end' => '']];

$days = tt_days($fSat, $fSun);
$cfgBreaks = tt_normalize_breaks($rawBreaks);
$cfgEvents = tt_normalize_events($rawEvents, $days);
$slots = teaching_timetable_timeslots($fSessions, $fDuration, $fStart, $cfgBreaks);
// Vipindi vya lazima pekee (kabla ya lunch); baada ya lunch ni remedial.
$capacity = tt_compulsory_capacity(['days' => $days, 'slots' => $slots, 'breaks' => $cfgBreaks, 'events' => $cfgEvents]);
$remedialSlots = count(tt_remedial_nos(['days' => $days, 'slots' => $slots, 'breaks' => $cfgBreaks]));

/* Requirements: values submitted by POST, otherwise defaults (kwa kila stream). */
// Defaults za mtaala kwa kila stream: hizi hujazwa kwanza ili kubadilisha
// idadi ya mikondo (Apply) kusiondoe vipindi vilivyokwishajazwa.
$reqValues = [];
$defaults = tt_default_requirements($classes, $capacity);
foreach ($defaults as $r) {
    $rcid = (int)$r['class_id'];
    foreach (array_keys($classStreams[$rcid] ?? ['']) as $si) {
        $reqValues[$rcid][(int)$r['subject_id']][$si] = (int)$r['periods_per_week'];
    }
}
// Kisha maadili yaliyowasilishwa (pamoja na 0) yanachukua nafasi ya defaults.
if ($isPost) {
    foreach (($_POST['required'] ?? []) as $cid => $subs) {
        if (!is_array($subs) || !isset($classMap[(int)$cid])) continue;
        foreach ($subs as $sid => $byStream) {
            if (!is_array($byStream)) $byStream = [0 => $byStream];
            foreach ($byStream as $si => $n) {
                $si = (int)$si;
                if (!isset($classStreams[(int)$cid][$si])) continue;
                $reqValues[(int)$cid][(int)$sid][$si] = max(0, (int)$n);
            }
        }
    }
}

/* Subjects for each class */
$classSubjects = [];
if ($classMap) {
    $ids = array_keys($classMap);
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT cs.class_id, s.id, s.name, s.abbr FROM class_subjects cs
                    JOIN subjects s ON s.id = cs.subject_id
                    WHERE cs.class_id IN ($in) ORDER BY s.name", $ids) as $r) {
        $classSubjects[(int)$r['class_id']][] = $r;
    }
}
$assignMap = teaching_assignments_map($school_id);

$totalAssignments = (int)q_val('SELECT COUNT(*) FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                               WHERE u.school_id = ? AND u.active = 1', [$school_id]);
$missingTeachers = 0;
foreach ($classMap as $cid => $c) {
    foreach ($classSubjects[$cid] ?? [] as $s) {
        foreach ($classStreams[(int)$cid] ?? [''] as $si => $stream) {
            if ((int)($reqValues[$cid][(int)$s['id']][$si] ?? 0) > 0
                && !tt_assignment_for($assignMap, $cid, (int)$s['id'], (string)$stream)) $missingTeachers++;
        }
    }
}

layout_start('Create New Timetable', 'teach');
teaching_nav('timetable');
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
  <div>
    <h5 class="fw-bold mb-1"><i class="bi bi-calendar-plus"></i> Create New Timetable</h5>
    <div class="text-muted small">Fill in the settings, breaks, events and the periods required for each class and subject.</div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(url('teaching/timetable.php')); ?>">
    <i class="bi bi-arrow-left"></i> Back to timetable
  </a>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-danger">
    The timetable tables are not installed. The administrator should import <code>database/migration_teaching.sql</code> first.
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-3"><div class="card-body">
  <p class="mb-1">Fill in all the information below to create a new timetable.</p>
  <div class="fw-bold">Important tips <i class="bi bi-lightbulb-fill text-warning"></i></div>
  <ul class="mb-0 small">
    <li>Make sure every subject has an assigned teacher.</li>
    <li>Make sure the session and break times do not overlap.</li>
    <li>You can change the number of periods per week for each class and subject.</li>
    <li>If you enter streams, each stream gets its own row (for example: Form I A, Form I B); leave the stream names empty to split the class from enrolment.</li>
    <li>Periods are placed without clashes: one teacher is never in two classes at the same time, and no stream gets two subjects in the same period.</li>
    <li>If generation fails, add more sessions per day or include Saturday.</li>
  </ul>
</div></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle"></i> There are errors:</div>
    <ul class="mb-0 small">
      <?php foreach ($errors as $err): ?><li><?php echo h($err); ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($missingTeachers > 0): ?>
  <div class="alert alert-warning">
    <b>Note:</b> there are <b><?php echo (int)$missingTeachers; ?></b> subject(s) with required periods but no assigned teacher.
    The timetable will still be generated, but the teacher's name will not appear. Assign teachers under
    <a href="<?php echo h(url('admin/assignments.php')); ?>">Assignments</a> for the best result.
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-header bg-white fw-bold">Timetable settings</div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-5">
        <label class="form-label small fw-bold mb-1">Academic year</label>
        <select name="year_id" class="form-select form-select-sm">
          <?php foreach ($years as $y): ?>
            <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$year['id'] === (int)$y['id'] ? ' selected' : ''; ?>><?php echo h($y['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label small fw-bold mb-1">Group</label>
        <select name="group" class="form-select form-select-sm">
          <?php foreach (tt_groups() as $g => $label): ?>
            <option value="<?php echo h($g); ?>"<?php echo $group === $g ? ' selected' : ''; ?>><?php echo h($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-arrow-repeat"></i> Apply</button>
      </div>
      <?php if ($classMap): ?>
        <div class="col-12">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-1">
              <thead>
                <tr>
                  <th>Class</th>
                  <th class="text-center">Students</th>
                  <th style="width:170px">Streams (0 = from students)</th>
                  <th>Building</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($classMap as $cid => $c): $cid = (int)$cid; $p = $classPlans[$cid] ?? null; ?>
                  <tr>
                    <td class="fw-bold"><?php echo h($c['name']); ?></td>
                    <td class="text-center"><?php echo (int)($p['total'] ?? 0); ?></td>
                    <td>
                      <input type="number" name="streams_class[<?php echo $cid; ?>]" class="form-control form-control-sm" min="0" max="<?php echo TTA_MAX_STREAMS; ?>" value="<?php echo (int)($classStreamCount[$cid] ?? 0); ?>">
                    </td>
                    <td class="small">
                      <?php $lbl = $classStreams[$cid] ?? ['']; ?>
                      <?php echo ($lbl === [''])
                          ? '<span class="text-muted">Whole class</span>'
                          : h(implode(', ', $lbl)); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="form-text">
            0 = follow the streams already recorded for the students (whole class if none).
            Set a number to decide the streams of that class yourself &mdash; each class can have a different number.
            There is no maximum number of students per stream. Click <b>Apply</b> after changing the numbers so the period table below follows.
          </div>
        </div>
      <?php endif; ?>
    </form>
    <div class="small text-muted mt-2">
      The selected group has <b><?php echo count($classMap); ?></b> class(es). Compulsory period slots per week for each class:
      <b><?php echo (int)$capacity; ?></b> (days <?php echo count($days); ?> &times; sessions <?php echo (int)$fSessions; ?>).
      <?php if ($remedialSlots > 0): ?>
        Sessions after lunch are <b>remedial</b> (<?php echo (int)$remedialSlots; ?> per day) and are filled with a science/art mix.
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$classMap): ?>
  <div class="alert alert-info">There are no classes in this group. Choose another group or add classes first.</div>
<?php else: ?>

<form method="post" id="ttForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="year_id" value="<?php echo (int)$year['id']; ?>">
  <input type="hidden" name="group" value="<?php echo h($group); ?>">
  <?php foreach ($classMap as $cid => $c): ?>
    <input type="hidden" name="streams_class[<?php echo (int)$cid; ?>]" value="<?php echo (int)($classStreamCount[(int)$cid] ?? 0); ?>">
  <?php endforeach; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-bold">Basic settings</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Sessions per day</label>
          <input type="number" name="sessions_per_day" class="form-control form-control-sm" min="1" max="14" required value="<?php echo (int)$fSessions; ?>">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Session duration (minutes)</label>
          <input type="number" name="session_duration" class="form-control form-control-sm" min="15" max="120" required value="<?php echo (int)$fDuration; ?>">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Start time</label>
          <input type="time" name="start_time" class="form-control form-control-sm" required value="<?php echo h($fStart); ?>">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">End time</label>
          <input type="time" name="end_time" class="form-control form-control-sm" required value="<?php echo h($fEnd); ?>">
        </div>
        <div class="col-12 col-lg-8">
          <label class="form-label small fw-bold mb-1">Stream names (optional)</label>
          <input type="text" name="streams" class="form-control form-control-sm" list="ttStreamOptions" maxlength="240" value="<?php echo h($fStreamsRaw); ?>" placeholder="Example: A, B (leave empty to split from enrolment)">
          <datalist id="ttStreamOptions">
            <?php foreach ($streamSuggestions as $st): ?><option value="<?php echo h($st); ?>"><?php endforeach; ?>
          </datalist>
          <div class="form-text">
            Leave the names empty to use A, B, C... Each class uses as many names as its own stream count above.
            Every stream gets its own timetable rows (for example: Form I A, Form I B).
          </div>
        </div>
        <?php if ($classPlans): ?>
          <div class="col-12">
            <div class="alert alert-light border py-2 px-3 mb-0 small">
              <i class="bi bi-diagram-3 text-primary"></i> Streams per class:
              <?php foreach ($classMap as $cid => $c): $cid = (int)$cid; $lbl = $classStreams[$cid] ?? ['']; ?>
                <span class="badge text-bg-light border ms-1"><?php echo h($c['name']); ?>:
                  <?php echo ($lbl === ['']) ? 'whole class' : h(implode(', ', $lbl)); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="col-6 col-lg-3">
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="include_saturday" id="includeSaturday" value="1"<?php echo $fSat ? ' checked' : ''; ?>>
            <label class="form-check-label small fw-bold" for="includeSaturday">Include Saturday</label>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="include_sunday" id="includeSunday" value="1"<?php echo $fSun ? ' checked' : ''; ?>>
            <label class="form-check-label small fw-bold" for="includeSunday">Include Sunday</label>
          </div>
        </div>
      </div>
      <div class="alert alert-light border mt-3 mb-0 small">
        Session times: <b><?php echo h($slots['start_text']); ?> - <?php echo h($slots['end_text']); ?></b>.
        Each class has <b><?php echo (int)$capacity; ?></b> period slots per week (excluding event times).
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-bold">Breaks</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ttAddBreak()"><i class="bi bi-plus-lg"></i> Add Break</button>
    </div>
    <div class="card-body">
      <div id="breakRows">
        <?php foreach (array_values($rawBreaks) as $bi => $b): ?>
          <div class="row g-2 align-items-end mb-2 tt-break-row">
            <div class="col-12 col-md-4">
              <label class="form-label small fw-bold mb-1">Name</label>
              <input type="text" name="breaks[<?php echo $bi; ?>][name]" class="form-control form-control-sm" placeholder="Example: Breakfast" value="<?php echo h($b['name'] ?? ''); ?>">
            </div>
            <div class="col-5 col-md-3">
              <label class="form-label small fw-bold mb-1">Start</label>
              <input type="time" name="breaks[<?php echo $bi; ?>][start]" class="form-control form-control-sm" value="<?php echo h($b['start'] ?? ''); ?>">
            </div>
            <div class="col-5 col-md-3">
              <label class="form-label small fw-bold mb-1">End</label>
              <input type="time" name="breaks[<?php echo $bi; ?>][end]" class="form-control form-control-sm" value="<?php echo h($b['end'] ?? ''); ?>">
            </div>
            <div class="col-2 col-md-2">
              <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="ttRemoveRow(this)" title="Remove break"><i class="bi bi-trash"></i></button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-muted small">Breaks apply every day. Session times skip them so that they do not overlap.</div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-bold">Events</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ttAddEvent()"><i class="bi bi-plus-lg"></i> Add Event</button>
    </div>
    <div class="card-body">
      <div id="eventRows">
        <?php foreach (array_values($rawEvents) as $ei => $ev):
            $evDay = tt_day_code($ev['day'] ?? '') ?: 'MON'; ?>
          <div class="row g-2 align-items-end mb-2 tt-event-row">
            <div class="col-12 col-md-3">
              <label class="form-label small fw-bold mb-1">Event name</label>
              <input type="text" name="events[<?php echo $ei; ?>][name]" class="form-control form-control-sm" placeholder="Example: Religion" value="<?php echo h($ev['name'] ?? ''); ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Day</label>
              <select name="events[<?php echo $ei; ?>][day]" class="form-select form-select-sm">
                <?php foreach (tt_day_labels() as $code => $label): ?>
                  <option value="<?php echo h($code); ?>"<?php echo $evDay === $code ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Start</label>
              <input type="time" name="events[<?php echo $ei; ?>][start]" class="form-control form-control-sm" value="<?php echo h($ev['start'] ?? ''); ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">End</label>
              <input type="time" name="events[<?php echo $ei; ?>][end]" class="form-control form-control-sm" value="<?php echo h($ev['end'] ?? ''); ?>">
            </div>
            <div class="col-2 col-md-1">
              <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="ttRemoveRow(this)" title="Remove event"><i class="bi bi-trash"></i></button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-muted small">An event takes the place of a session on the given day (for example: Friday worship).</div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
      <span>Required periods per week</span>
      <span class="badge text-bg-light border">Slots per class: <?php echo (int)$capacity; ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th style="width:50px">S/N</th><th>Class</th><th>Subject</th><th>Teacher</th><th style="width:120px">Periods</th></tr>
          </thead>
          <tbody>
            <?php $sn = 0; foreach ($classMap as $cid => $c): ?>
              <?php $subs = $classSubjects[$cid] ?? []; if (!$subs) continue; ?>
              <?php foreach ($classStreams[(int)$cid] ?? [''] as $si => $stream): $label = tt_entity_name((string)$c['name'], (string)$stream); $tkey = (int)$cid . '|' . (int)$si; ?>
                <tr class="table-light">
                  <td colspan="4" class="fw-bold"><?php echo h($label); ?></td>
                  <td class="fw-bold text-center">
                    <span data-class-total="<?php echo h($tkey); ?>">0</span> / <?php echo (int)$capacity; ?>
                  </td>
                </tr>
                <?php foreach ($subs as $s): $sn++; $sid = (int)$s['id']; $val = (int)($reqValues[$cid][$sid][$si] ?? 0); $assigned = tt_assignment_for($assignMap, $cid, $sid, (string)$stream); ?>
                  <tr>
                    <td class="text-muted"><?php echo $sn; ?></td>
                    <td><?php echo h($label); ?></td>
                    <td><?php echo h($s['name']); ?><?php echo trim((string)($s['abbr'] ?? '')) !== '' ? ' <span class="text-muted small">(' . h($s['abbr']) . ')</span>' : ''; ?></td>
                    <td class="small"><?php echo $assigned ? h($assigned['full_name']) : '<span class="badge text-bg-warning">No teacher</span>'; ?></td>
                    <td>
                      <input type="number" name="required[<?php echo (int)$cid; ?>][<?php echo $sid; ?>][<?php echo (int)$si; ?>]" class="form-control form-control-sm tt-req-input" data-class="<?php echo h($tkey); ?>" min="0" max="100" value="<?php echo $val; ?>">
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="text-muted small mt-2">Defaults follow the official curriculum (Kidato cha I–II and III–IV). The total periods for each class must not exceed the available slots. You can set 0 for a subject you do not want on the timetable.</div>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 justify-content-end mb-4">
    <a class="btn btn-outline-secondary" href="<?php echo h(url('teaching/timetable.php')); ?>">Cancel</a>
    <button type="submit" class="btn btn-primary fw-bold"<?php echo !$ready ? ' disabled' : ''; ?>>
      <i class="bi bi-calendar-plus"></i> Create Timetable
    </button>
  </div>
</form>

<script>
var breakSeq = <?php echo count($rawBreaks); ?>;
var eventSeq = <?php echo count($rawEvents); ?>;
function ttRemoveRow(btn) {
  var row = btn.closest('.tt-break-row, .tt-event-row');
  if (row) row.remove();
}
function ttAddBreak() {
  var i = breakSeq++;
  var html = '<div class="row g-2 align-items-end mb-2 tt-break-row">'
    + '<div class="col-12 col-md-4"><label class="form-label small fw-bold mb-1">Name</label><input type="text" name="breaks[' + i + '][name]" class="form-control form-control-sm" placeholder="Example: Breakfast"></div>'
    + '<div class="col-5 col-md-3"><label class="form-label small fw-bold mb-1">Start</label><input type="time" name="breaks[' + i + '][start]" class="form-control form-control-sm" value="10:40"></div>'
    + '<div class="col-5 col-md-3"><label class="form-label small fw-bold mb-1">End</label><input type="time" name="breaks[' + i + '][end]" class="form-control form-control-sm" value="11:10"></div>'
    + '<div class="col-2 col-md-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="ttRemoveRow(this)"><i class="bi bi-trash"></i></button></div>'
    + '</div>';
  document.getElementById('breakRows').insertAdjacentHTML('beforeend', html);
}
function ttAddEvent() {
  var i = eventSeq++;
  var html = '<div class="row g-2 align-items-end mb-2 tt-event-row">'
    + '<div class="col-12 col-md-3"><label class="form-label small fw-bold mb-1">Event name</label><input type="text" name="events[' + i + '][name]" class="form-control form-control-sm" placeholder="Example: Religion"></div>'
    + '<div class="col-6 col-md-2"><label class="form-label small fw-bold mb-1">Day</label><select name="events[' + i + '][day]" class="form-select form-select-sm">'
    <?php foreach (tt_day_labels() as $code => $label): ?>
    + '<option value="<?php echo h($code); ?>"><?php echo h($label); ?></option>'
    <?php endforeach; ?>
    + '</select></div>'
    + '<div class="col-6 col-md-2"><label class="form-label small fw-bold mb-1">Start</label><input type="time" name="events[' + i + '][start]" class="form-control form-control-sm" value="13:20"></div>'
    + '<div class="col-6 col-md-2"><label class="form-label small fw-bold mb-1">End</label><input type="time" name="events[' + i + '][end]" class="form-control form-control-sm" value="14:00"></div>'
    + '<div class="col-2 col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="ttRemoveRow(this)"><i class="bi bi-trash"></i></button></div>'
    + '</div>';
  document.getElementById('eventRows').insertAdjacentHTML('beforeend', html);
}
function ttRecalc() {
  var cap = <?php echo (int)$capacity; ?>;
  var totals = {};
  document.querySelectorAll('.tt-req-input').forEach(function (inp) {
    var c = inp.dataset.class;
    var n = parseInt(inp.value, 10);
    if (isNaN(n) || n < 0) n = 0;
    totals[c] = (totals[c] || 0) + n;
  });
  document.querySelectorAll('[data-class-total]').forEach(function (el) {
    var t = totals[el.dataset.classTotal] || 0;
    el.textContent = t;
    el.classList.toggle('text-danger', t > cap);
  });
}
document.querySelectorAll('.tt-req-input').forEach(function (inp) { inp.addEventListener('input', ttRecalc); });
ttRecalc();
</script>

<?php endif; ?>

<?php layout_end(); ?>
