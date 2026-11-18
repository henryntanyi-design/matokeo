<?php
require_once __DIR__ . '/../lib/timetable_auto.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();

$school_id = active_school_id();
$me = user();
$ready = tt_tables_ready();
$years = teaching_years();
$year = teaching_year((int)($_GET['year_id'] ?? $_POST['year_id'] ?? 0));

$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$class = null;
foreach ($classes as $c) if ((int)$c['id'] === $class_id) $class = $c;
if (!$class && $classes) { $class = $classes[0]; $class_id = (int)$class['id']; }

$group = strtoupper((string)($class['level'] ?? 'O')) === 'A' ? 'advance' : 'ordinary';

/* ---------------- Configuration ---------------- */

$isPost = is_post();
$src = $isPost ? $_POST : $_GET;

function tta_int_in($v, $min, $max, $default) {
    $v = (int)$v;
    if ($v < $min || $v > $max) return $default;
    return $v;
}
function tta_time_in($v, $default) {
    $v = trim((string)$v);
    return teaching_time_minutes($v) === null ? $default : $v;
}

$streamsOverride = tta_int_in($src['streams'] ?? 0, 0, TTA_MAX_STREAMS, 0);
$sessions = tta_int_in($src['sessions_per_day'] ?? 11, 1, 14, 11);
$duration = tta_int_in($src['session_duration'] ?? 40, 15, 120, 40);
$startTime = tta_time_in($src['start_time'] ?? '08:00', '08:00');
$endTime = tta_time_in($src['end_time'] ?? '16:30', '16:30');
$includeSaturday = !empty($src['include_saturday']);
$teaStart = tta_time_in($src['tea_start'] ?? '10:40', '10:40');
$teaEnd = tta_time_in($src['tea_end'] ?? '11:10', '11:10');
$lunchStart = tta_time_in($src['lunch_start'] ?? '14:30', '14:30');
$lunchEnd = tta_time_in($src['lunch_end'] ?? '15:10', '15:10');

$days = tt_days($includeSaturday, false);
$breaks = tt_normalize_breaks([
    ['name' => 'TEA BREAK', 'start' => $teaStart, 'end' => $teaEnd],
    ['name' => 'LUNCH BREAK', 'start' => $lunchStart, 'end' => $lunchEnd],
]);
$slots = teaching_timetable_timeslots($sessions, $duration, $startTime, $breaks);
// Vipindi vya lazima pekee: baada ya lunch ni saa za remedial.
$capacity = tt_compulsory_capacity(['days' => $days, 'slots' => $slots, 'breaks' => $breaks, 'events' => []]);
$morningSlots = 0;
$lunchCut = teaching_time_minutes($lunchStart);
foreach ($slots['items'] as $it) {
    if (($it['type'] ?? '') === 'session' && (int)$it['start'] < (int)$lunchCut) $morningSlots++;
}

$plan = $class ? tta_plan_streams($school_id, $class_id, $streamsOverride) : null;
$labelsInput = $isPost ? trim((string)($_POST['labels'] ?? '')) : (($plan && count($plan['labels']) > 1) ? implode(', ', $plan['labels']) : '');
$labelList = tt_normalize_streams($labelsInput);
if (!$labelList && $plan) $labelList = $plan['labels'];

$errors = [];
$warnings = [];

/* ---------------- Generate & save ---------------- */

if ($isPost && ($_POST['action'] ?? '') === 'generate') {
    csrf_check();

    if (!$ready) {
        $errors[] = 'The timetable tables are not installed. Import database/migration_teaching.sql first.';
    } elseif (!$class) {
        $errors[] = 'Choose a class first.';
    } elseif ($plan['total'] === 0) {
        $warnings[] = 'This class has no active students yet, so the stream count was not derived from enrolment.';
    }

    if (!$errors) {
        $week = tta_week_requirements($class, $capacity, $labelList, $days);
        if (!$week['requirements']) {
            $errors[] = 'This class has no subjects assigned. Add subjects to the class first (Classes &rarr; Subjects).';
        } else {
            if ($week['dropped'] > 0) {
                $warnings[] = 'Some periods were left out because no subject may appear more than '
                    . TTA_MAX_PERIODS_PER_SUBJECT_PER_DAY . ' times a day.';
            }

            $in = [
                'group' => $group,
                'sessions_per_day' => $sessions,
                'session_duration' => $duration,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'include_saturday' => $includeSaturday ? 1 : 0,
                'include_sunday' => 0,
                'breaks' => $breaks,
                'events' => [],
                'streams' => $labelList,
                'required' => $week['required'],
            ];
            $check = tt_validate_input($in, $school_id);
            if (!$check['ok']) {
                $errors = array_merge($errors, $check['errors']);
            } else {
                $data = $check['data'];
                $data['group'] = $group;
                $assignMap = teaching_assignments_map($school_id);
                $result = tta_generate($data, $assignMap, [
                    'max_per_day' => TTA_MAX_PERIODS_PER_SUBJECT_PER_DAY,
                    'morning_cutoff' => (int)$lunchCut,
                ]);

                if (!$result['ok']) {
                    $errors = array_merge($errors, $result['errors']);
                } else {
                    try {
                        $newId = tt_save($school_id, (int)$year['id'], $data, $week['requirements'], $result['entries'], (int)($me['id'] ?? 0));
                        flash('success', 'Timetable generated and saved: <b>' . count($labelList) . '</b> stream(s) for '
                            . h(tt_entity_name((string)$class['name'], '')) . '. Click <b>Print / PDF</b> to print it, or publish it from the timetable page.');
                        redirect('teaching/timetable_auto.php?' . http_build_query([
                            'id' => $newId,
                            'class_id' => $class_id,
                            'year_id' => (int)$year['id'],
                            'streams' => $streamsOverride,
                            'labels' => $labelsInput,
                            'sessions_per_day' => $sessions,
                            'session_duration' => $duration,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'include_saturday' => $includeSaturday ? 1 : 0,
                            'tea_start' => $teaStart,
                            'tea_end' => $teaEnd,
                            'lunch_start' => $lunchStart,
                            'lunch_end' => $lunchEnd,
                        ]));
                    } catch (Exception $e) {
                        $errors[] = 'Could not save the timetable: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

/* ---------------- Load a saved timetable ---------------- */

$tt = null;
$id = (int)($_GET['id'] ?? 0);
if ($ready && $id) $tt = tt_load($id, $school_id);
if ($tt && (int)$tt['school_id'] !== (int)$school_id) $tt = null;

$streams = [];
$subjectMap = [];
$teacherMap = [];
if ($tt) {
    foreach ($tt['entries'] as $e) {
        if ((int)$e['class_id'] !== $class_id) continue;
        $s = trim((string)($e['stream'] ?? ''));
        $streams[$s] = true;
    }
    $streams = array_keys($streams);
    sort($streams, SORT_NATURAL | SORT_FLAG_CASE);
    $subjectMap = tta_subject_map($tt['entries']);
    $teacherMap = tta_teacher_map($tt['entries']);
}

/* Missing teacher warning for the current plan */
$missingTeachers = 0;
if ($class && $ready) {
    $cs = q_all('SELECT subject_id FROM class_subjects WHERE class_id = ?', [$class_id]);
    $assignMapPreview = teaching_assignments_map($school_id);
    foreach ($cs as $row) {
        foreach ($labelList as $label) {
            if (!tt_assignment_for($assignMapPreview, $class_id, (int)$row['subject_id'], (string)$label)) { $missingTeachers++; break; }
        }
    }
}

layout_start('Auto Timetable Generator', 'teach');
teaching_nav('timetable_auto');
?>

<style>
<?php echo tta_page_css(); ?>
</style>

<div class="tta-hero d-print-none">
  <div class="tta-hero-in">
    <span class="tta-ic"><i class="bi bi-calendar2-range"></i></span>
    <div>
      <div class="tta-kicker"><i class="bi bi-stars"></i> Automated scheduling</div>
      <h1>Auto Timetable Generator</h1>
      <p>Split a class into streams from its enrolment, then schedule it morning-first with no teacher clashes and a maximum of two periods per subject a day.</p>
    </div>
  </div>
  <div class="tta-actions">
    <?php if ($tt): ?>
      <button type="button" class="tta-btn solid" onclick="window.print()"><i class="bi bi-printer"></i> Print / PDF</button>
    <?php endif; ?>
    <a class="tta-btn ghost" href="<?php echo h(url('teaching/timetable.php')); ?>"><i class="bi bi-calendar3-week"></i> Saved timetables</a>
    <a class="tta-btn ghost" href="<?php echo h(url('teaching/timetable_new.php')); ?>"><i class="bi bi-sliders"></i> Manual builder</a>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning d-print-none">
    The timetable tables are not installed. The administrator should import <code>database/migration_teaching.sql</code> first.
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger d-print-none">
    <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle"></i> The timetable was not generated:</div>
    <ul class="mb-0 small"><?php foreach ($errors as $err): ?><li><?php echo $err; ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($warnings): ?>
  <div class="alert alert-warning d-print-none">
    <ul class="mb-0 small"><?php foreach ($warnings as $w): ?><li><?php echo $w; ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($missingTeachers && $class): ?>
  <div class="alert alert-info d-print-none small">
    <i class="bi bi-info-circle"></i> <b><?php echo (int)$missingTeachers; ?></b> subject(s) in this class have no teacher for every stream.
    They will still be scheduled, but without a teacher name. Fix that in
    <a href="<?php echo h(url('admin/assignments.php')); ?>">Assignments</a>.
  </div>
<?php endif; ?>

<?php if (!$classes): ?>
  <div class="alert alert-info">There are no classes yet. Create classes first.</div>
<?php else: ?>

<div class="tta-card d-print-none">
  <div class="tta-card-h">
    <span class="tta-card-t"><i class="bi bi-gear"></i> Configuration</span>
    <form method="get" class="d-flex align-items-center gap-2 ms-auto">
      <input type="hidden" name="year_id" value="<?php echo (int)$year['id']; ?>">
      <label class="small fw-bold mb-0" for="autoClass">Class</label>
      <select name="class_id" id="autoClass" class="form-select form-select-sm" style="width:auto;min-width:170px" onchange="this.form.submit()">
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $class_id ? ' selected' : ''; ?>><?php echo h($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm btn-outline-primary">Load</button></noscript>
    </form>
    <span class="tta-chip"><?php echo count($days); ?> days &times; <?php echo (int)$sessions; ?> sessions = <?php echo (int)$capacity; ?> slots</span>
  </div>
  <div class="tta-card-b">
    <form method="post" id="autoForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="generate">
      <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">

      <div class="row g-3">
        <div class="col-6 col-lg-4">
          <label class="form-label small fw-bold mb-1">Academic year</label>
          <select name="year_id" class="form-select form-select-sm">
            <?php foreach ($years as $y): ?>
              <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$year['id'] === (int)$y['id'] ? ' selected' : ''; ?>><?php echo h($y['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><hr class="my-1"></div>

        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Streams for this class</label>
          <input type="number" name="streams" class="form-control form-control-sm" min="0" max="<?php echo TTA_MAX_STREAMS; ?>" value="<?php echo (int)$streamsOverride; ?>">
          <div class="form-text">0 = from the students' streams. No limit on students per stream.</div>
        </div>
        <div class="col-12 col-lg-5">
          <label class="form-label small fw-bold mb-1">Stream names (optional)</label>
          <input type="text" name="labels" class="form-control form-control-sm" maxlength="240" value="<?php echo h($labelsInput); ?>" placeholder="Example: A, B, C">
          <div class="form-text">Leave empty to use the generated names.</div>
        </div>
        <div class="col-6 col-lg-2">
          <label class="form-label small fw-bold mb-1">Sessions per day</label>
          <input type="number" name="sessions_per_day" class="form-control form-control-sm" min="1" max="14" value="<?php echo (int)$sessions; ?>">
        </div>
        <div class="col-6 col-lg-2">
          <label class="form-label small fw-bold mb-1">Lesson minutes</label>
          <input type="number" name="session_duration" class="form-control form-control-sm" min="15" max="120" value="<?php echo (int)$duration; ?>">
        </div>

        <div class="col-12"><hr class="my-1"></div>

        <div class="col-6 col-lg-2">
          <label class="form-label small fw-bold mb-1">Day starts</label>
          <input type="time" name="start_time" class="form-control form-control-sm" value="<?php echo h($startTime); ?>">
        </div>
        <div class="col-6 col-lg-2">
          <label class="form-label small fw-bold mb-1">Day ends</label>
          <input type="time" name="end_time" class="form-control form-control-sm" value="<?php echo h($endTime); ?>">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Tea break</label>
          <div class="d-flex align-items-center gap-1">
            <input type="time" name="tea_start" class="form-control form-control-sm" value="<?php echo h($teaStart); ?>">
            <span class="small text-muted">&ndash;</span>
            <input type="time" name="tea_end" class="form-control form-control-sm" value="<?php echo h($teaEnd); ?>">
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small fw-bold mb-1">Lunch break</label>
          <div class="d-flex align-items-center gap-1">
            <input type="time" name="lunch_start" class="form-control form-control-sm" value="<?php echo h($lunchStart); ?>">
            <span class="small text-muted">&ndash;</span>
            <input type="time" name="lunch_end" class="form-control form-control-sm" value="<?php echo h($lunchEnd); ?>">
          </div>
        </div>
        <div class="col-12 col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="include_saturday" id="incSat" value="1"<?php echo $includeSaturday ? ' checked' : ''; ?>>
            <label class="form-check-label small fw-bold" for="incSat">Include Saturday</label>
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between mt-3 pt-3" style="border-top:1px dashed #dbe6fb">
        <div class="small text-muted">
          Morning = before lunch (<?php echo (int)$morningSlots; ?> of <?php echo (int)$sessions; ?> daily sessions).
          Core subjects are scheduled there first.
        </div>
        <button class="btn btn-primary fw-bold px-4"<?php echo $ready ? '' : ' disabled'; ?>>
          <i class="bi bi-magic me-1"></i> Generate &amp; Save
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($plan): ?>
<div class="card shadow-sm mb-3 d-print-none" style="border-radius:16px;border-color:#e6ecf8">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="fw-bold" style="color:#16203a"><i class="bi bi-diagram-3 text-primary"></i> Stream plan for <?php echo h($class['name']); ?></div>
        <div class="small text-muted">Based on <?php echo (int)$plan['total']; ?> active student(s). Set the stream count to override what the students' records suggest.</div>
      </div>
      <span class="tta-chip"><i class="bi bi-people"></i> <?php echo count($labelList); ?> stream(s)</span>
    </div>

    <div class="row g-2">
      <div class="col-6 col-md-3"><div class="tta-stat"><span class="ico"><i class="bi bi-people"></i></span><div><div class="v"><?php echo (int)$plan['total']; ?></div><div class="l">Students</div></div></div></div>
      <div class="col-6 col-md-3"><div class="tta-stat g"><span class="ico"><i class="bi bi-columns-gap"></i></span><div><div class="v"><?php echo (int)$plan['auto_count']; ?></div><div class="l">From students</div></div></div></div>
      <div class="col-6 col-md-3"><div class="tta-stat a"><span class="ico"><i class="bi bi-sliders"></i></span><div><div class="v"><?php echo count($labelList); ?></div><div class="l">Streams to build</div></div></div></div>
      <div class="col-6 col-md-3"><div class="tta-stat o"><span class="ico"><i class="bi bi-person-lines-fill"></i></span><div><div class="v"><?php echo (int)$plan['per_stream']; ?></div><div class="l">Students / stream</div></div></div></div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php foreach ($labelList as $label): ?>
        <span class="tta-chip"><i class="bi bi-bookmark"></i> <?php echo h(tt_entity_name((string)$class['name'], (string)$label)); ?></span>
      <?php endforeach; ?>
    </div>

    <?php if ($plan['existing']): ?>
      <div class="small text-muted mt-2">
        Students already recorded under:
        <?php foreach ($plan['existing'] as $label => $n): ?>
          <span class="badge text-bg-light border"><?php echo h($label); ?>: <?php echo (int)$n; ?></span>
        <?php endforeach; ?>
        <?php if ($plan['unassigned'] > 0): ?>
          &middot; <span class="badge text-bg-light border">No stream: <?php echo (int)$plan['unassigned']; ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<?php if ($tt && $streams): ?>
  <div class="tta-card">
    <div class="tta-card-h">
      <span class="tta-card-t"><i class="bi bi-table"></i>
        <?php echo h($class['name']); ?> &middot; <?php echo h($year['name'] ?? ''); ?>
      </span>
      <span class="badge <?php echo $tt['status'] === 'Active' ? 'text-bg-success' : 'text-bg-warning text-dark'; ?>">
        <?php echo h($tt['status'] === 'Active' ? 'Published' : 'Not published'); ?>
      </span>
      <a class="btn btn-sm btn-outline-secondary ms-auto d-print-none" href="<?php echo h(url('teaching/timetable.php?id=' . (int)$tt['id'])); ?>">
        Open in saved timetables
      </a>
    </div>
    <div class="tta-card-b">
      <div class="tta-legend mb-3 d-print-none">
        <span><span class="sw" style="background:#eaf3ff"></span>Core subject in the morning</span>
        <span><span class="sw" style="background:#eef4fe"></span>Core subject, afternoon</span>
        <span><span class="sw" style="background:#f7faff"></span>Other subject</span>
        <span><span class="sw" style="background:#fff3d6"></span>Break</span>
      </div>

      <?php foreach ($streams as $stream): ?>
        <?php
          $week = tta_render_week($tt, $class_id, (string)$stream, $subjectMap, $teacherMap);
        ?>
        <div class="mb-4 tta-stream-block">
          <div class="tta-stream-head">
            <span class="tta-stream-name"><span class="dot"></span> <?php echo h(tt_entity_name((string)$class['name'], (string)$stream)); ?></span>
            <span class="tta-note">
              <i class="bi bi-check2-circle text-success"></i> <?php echo (int)$week['filled']; ?> periods
              &middot; <?php echo (int)$week['free']; ?> free
              &middot; <?php echo (int)$week['core_morning']; ?>/<?php echo (int)$week['core_total']; ?> core in the morning
            </span>
          </div>
          <?php echo $week['html']; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php elseif ($class): ?>
  <div class="alert alert-light border d-print-none">
    <i class="bi bi-lightbulb text-warning"></i>
    Set the options above and click <b>Generate &amp; Save</b>. The timetable is stored in the database, so it survives a page refresh.
  </div>
<?php endif; ?>

<?php endif; ?>

<?php layout_end(); ?>
