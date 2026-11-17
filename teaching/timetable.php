<?php
require_once __DIR__ . '/../lib/timetable.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_staff();

$school_id = active_school_id();
$isAdmin = is_admin();
$me = user();
$tid = teaching_active_teacher_id();
$ready = tt_tables_ready();
$years = teaching_years();
$year = teaching_year((int)($_GET['year_id'] ?? 0));

/* ---------------- Select timetable ---------------- */

$group = isset($_GET['group']) ? (string)$_GET['group'] : ($isAdmin ? 'ordinary' : 'all');
if (!in_array($group, ['ordinary', 'advance', 'all'], true)) $group = $isAdmin ? 'ordinary' : 'all';

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'Active';
if (!in_array($status, ['Active', 'Pending', 'Archived', 'all'], true)) $status = 'Active';
if (!$isAdmin) $status = 'Active';

$id = (int)($_GET['id'] ?? 0);
$tt = null;
if ($ready) {
    if ($id) {
        $tt = tt_load($id, $school_id);
        if ($tt && !$isAdmin && !tt_teacher_has($id, (int)$tid)) $tt = null;
    }
    if ($tt) {
        $year = teaching_year((int)$tt['academic_year_id']);
        $group = (string)$tt['group_level'];
        if (!$isAdmin) $status = 'Active';
        $id = (int)$tt['id'];
    } else {
        $tt = tt_find_latest($school_id, (int)$year['id'], $group, $status, $isAdmin ? 0 : (int)$tid);
        if (!$tt && $isAdmin && $status === 'Active') {
            $tt = tt_find_latest($school_id, (int)$year['id'], $group, 'Pending', 0);
            if ($tt) $status = 'Pending';
        }
        if ($tt) $id = (int)$tt['id'];
    }
    // tt_find_latest() returns the timetables row only; load entries/requirements.
    if ($tt && !array_key_exists('entries', $tt)) {
        $tt = tt_load((int)$tt['id'], $school_id);
    }
}

/* ---------------- Publish ---------------- */

if (is_post() && $isAdmin) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'publish') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid && tt_publish($pid, $school_id)) flash('success', 'Timetable published successfully.');
        else flash('danger', 'Could not publish the timetable. Make sure it exists.');
        redirect('teaching/timetable.php?id=' . $pid);
    }
}

/* ---------------- Filters ---------------- */

$classRows = [];
$subjectOptions = [];
$teacherOptions = [];
$streamOptions = [];
if ($tt) {
    $sql = 'SELECT DISTINCT c.* FROM timetable_entries e JOIN classes c ON c.id = e.class_id WHERE e.timetable_id = ?';
    $p = [$id];
    if (!$isAdmin) { $sql .= ' AND e.teacher_id = ?'; $p[] = (int)$tid; }
    $classRows = q_all($sql . ' ORDER BY c.sort_order, c.id', $p);

    $sql = 'SELECT DISTINCT s.id, s.name FROM timetable_entries e JOIN subjects s ON s.id = e.subject_id WHERE e.timetable_id = ?';
    $p = [$id];
    if (!$isAdmin) { $sql .= ' AND e.teacher_id = ?'; $p[] = (int)$tid; }
    $subjectOptions = q_all($sql . ' ORDER BY s.name', $p);

    $sql = 'SELECT DISTINCT u.id, u.full_name FROM timetable_entries e JOIN users u ON u.id = e.teacher_id
            WHERE e.timetable_id = ? AND e.teacher_id IS NOT NULL';
    $p = [$id];
    if (!$isAdmin) { $sql .= ' AND e.teacher_id = ?'; $p[] = (int)$tid; }
    $teacherOptions = q_all($sql . ' ORDER BY u.full_name', $p);

    if (tt_streams_installed()) {
        $sql = "SELECT DISTINCT e.stream FROM timetable_entries e WHERE e.timetable_id = ? AND e.stream <> ''";
        $p = [$id];
        if (!$isAdmin) { $sql .= ' AND e.teacher_id = ?'; $p[] = (int)$tid; }
        $streamOptions = q_all($sql . ' ORDER BY e.stream', $p);
    }
}

$allowedClassIds = array_map('intval', array_column($classRows, 'id'));
$class_id = (int)($_GET['class_id'] ?? 0);
if ($class_id && !in_array($class_id, $allowedClassIds, true)) $class_id = 0;

$subject_id = (int)($_GET['subject_id'] ?? 0);
$allowedSubjectIds = array_map('intval', array_column($subjectOptions, 'id'));
if ($subject_id && !in_array($subject_id, $allowedSubjectIds, true)) $subject_id = 0;

$teacher_id = $isAdmin ? (int)($_GET['teacher_id'] ?? 0) : (int)$tid;
$allowedTeacherIds = array_map('intval', array_column($teacherOptions, 'id'));
if ($teacher_id && !in_array($teacher_id, $allowedTeacherIds, true)) $teacher_id = 0;

$stream = trim((string)($_GET['stream'] ?? ''));
$allowedStreams = array_map('strval', array_column($streamOptions, 'stream'));
if ($stream !== '' && !in_array($stream, $allowedStreams, true)) $stream = '';

$display_classes = $classRows;
if ($class_id) {
    $display_classes = array_values(array_filter($classRows, function ($c) use ($class_id) {
        return (int)$c['id'] === $class_id;
    }));
}

$matrix = $tt
    ? tt_build_matrix($tt, $display_classes, ['subject_id' => $subject_id, 'teacher_id' => $teacher_id, 'stream' => $stream])
    : ['columns' => [], 'rows' => [], 'total_rows' => 0, 'stats' => [], 'filtered' => false];

/* ---------------- Names and metadata ---------------- */

$teacherName = '';
if ($teacher_id) {
    foreach ($teacherOptions as $t) if ((int)$t['id'] === $teacher_id) $teacherName = (string)$t['full_name'];
}
$subjectName = '';
if ($subject_id) {
    foreach ($subjectOptions as $s) if ((int)$s['id'] === $subject_id) $subjectName = (string)$s['name'];
}
$className = '';
if ($class_id) {
    foreach ($classRows as $c) if ((int)$c['id'] === $class_id) $className = (string)$c['name'];
}

$title = 'TEACHING TIMETABLE';
if (!$isAdmin) $title = 'MY TEACHING TIMETABLE';
elseif ($teacher_id && $teacherName !== '') $title = 'TEACHER TIMETABLE: ' . strtoupper($teacherName);
elseif ($className !== '') $title = 'CLASS TIMETABLE: ' . strtoupper(trim($className . ($stream !== '' ? ' ' . $stream : '')));

$metaBits = ['YEAR: ' . ($year['name'] ?? '-')];
if ($tt) $metaBits = array_merge($metaBits, tt_meta_bits($tt));
if ($subject_id && $subjectName !== '') $metaBits[] = 'SUBJECT: ' . strtoupper($subjectName);
if ($teacher_id && $teacherName !== '') $metaBits[] = 'TEACHER: ' . strtoupper($teacherName);
if ($stream !== '') $metaBits[] = 'STREAM: ' . strtoupper($stream);
$meta = implode('   |   ', $metaBits);

$filterParams = [
    'year_id' => (int)$year['id'],
    'group' => $group,
    'status' => $status,
    'class_id' => $class_id,
    'subject_id' => $subject_id,
    'teacher_id' => $teacher_id,
    'stream' => $stream,
];
if ($id) $filterParams['id'] = $id;

/* ---------------- Export ---------------- */

if (($ready && $tt && ($_GET['action'] ?? '') === 'export') && !empty($matrix['rows'])) {
    $format = (string)($_GET['format'] ?? 'xlsx');
    $parts = ['timetable', $group];
    if ($teacher_id) $parts[] = 'teacher_' . $teacher_id;
    elseif ($class_id) $parts[] = 'class_' . $class_id;
    else $parts[] = 'school';
    if ($subject_id) $parts[] = 'subject_' . $subject_id;
    if ($stream !== '') $parts[] = 'stream_' . preg_replace('/[^A-Za-z0-9]+/', '', $stream);
    $parts[] = date('Ymd');
    $fname = implode('_', $parts);

    $sc = active_school();
    $schoolName = (string)($sc['name'] ?? system_name());

    if ($format === 'pdf_a4') tt_send_pdf($fname, $title, $meta, $matrix, 'A4', $schoolName);
    elseif ($format === 'pdf_a3') tt_send_pdf($fname, $title, $meta, $matrix, 'A3', $schoolName);
    else tt_send_xlsx($fname, $title, $meta, $matrix, 'A3');
}

$statusLabels = ['Active' => 'Published', 'Pending' => 'Unpublished', 'Archived' => 'Archived', 'all' => 'All'];
$exportBase = array_merge($filterParams, ['action' => 'export']);

layout_start('Teaching Timetable', 'teach');
teaching_nav('timetable');
?>

<?php if (!$ready): ?>
<div class="alert alert-warning">
  <b>Note:</b> the timetable tables are not installed. The administrator (admin) should import
  <code>database/migration_teaching.sql</code> in phpMyAdmin, then timetables can be saved.
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3 d-print-none">
  <div>
    <h5 class="fw-bold mb-1"><i class="bi bi-calendar3-week"></i> Teaching Timetable</h5>
    <div class="text-muted small">Saved timetables in the MSSIS layout: day/class rows, numbered sessions with times, breaks and events.</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-primary fw-bold" href="<?php echo h(url('teaching/timetable_auto.php?year_id=' . (int)$year['id'])); ?>">
        <i class="bi bi-magic"></i> Auto Generator
      </a>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo h(url('teaching/timetable_new.php?year_id=' . (int)$year['id'] . '&group=' . urlencode($group === 'all' ? 'ordinary' : $group))); ?>">
        <i class="bi bi-calendar-plus"></i> Generate New
      </a>
    <?php endif; ?>
    <?php if ($isAdmin && $tt && $tt['status'] !== 'Active'): ?>
      <form method="post" class="m-0" onsubmit="return confirm('Publish this timetable? Any other timetable for this group will be archived.');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="publish">
        <input type="hidden" name="id" value="<?php echo (int)$tt['id']; ?>">
        <button class="btn btn-sm btn-warning fw-bold"><i class="bi bi-calendar-check"></i> Publish</button>
      </form>
    <?php endif; ?>
    <?php if ($tt && !empty($matrix['rows'])): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-download"></i> Export
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="<?php echo h(url('teaching/timetable.php?' . http_build_query(array_merge($exportBase, ['format' => 'xlsx'])))); ?>"><i class="bi bi-file-earmark-excel"></i> Excel (.xlsx)</a></li>
          <li><a class="dropdown-item" href="<?php echo h(url('teaching/timetable.php?' . http_build_query(array_merge($exportBase, ['format' => 'pdf_a4'])))); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF A4</a></li>
          <li><a class="dropdown-item" href="<?php echo h(url('teaching/timetable.php?' . http_build_query(array_merge($exportBase, ['format' => 'pdf_a3'])))); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF A3</a></li>
        </ul>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3 d-print-none"><div class="card-body">
  <form method="get" class="row g-2 align-items-end">
    <?php if ($id): ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><?php endif; ?>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-bold mb-1">Year</label>
      <select name="year_id" class="form-select form-select-sm">
        <?php foreach ($years as $y): ?>
          <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$year['id'] === (int)$y['id'] ? ' selected' : ''; ?>><?php echo h($y['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($isAdmin): ?>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-bold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <?php foreach (['Active', 'Pending', 'Archived', 'all'] as $st): ?>
            <option value="<?php echo $st; ?>"<?php echo $status === $st ? ' selected' : ''; ?>><?php echo h($statusLabels[$st]); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-bold mb-1">Group</label>
      <select name="group" class="form-select form-select-sm">
        <?php foreach (tt_groups() as $g => $label): ?>
          <option value="<?php echo h($g); ?>"<?php echo $group === $g ? ' selected' : ''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-bold mb-1">Class</label>
      <select name="class_id" class="form-select form-select-sm">
        <option value="0">All classes</option>
        <?php foreach ($classRows as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>"<?php echo $class_id === (int)$c['id'] ? ' selected' : ''; ?>><?php echo h($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($streamOptions): ?>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-bold mb-1">Stream</label>
        <select name="stream" class="form-select form-select-sm">
          <option value="">All streams</option>
          <?php foreach ($streamOptions as $so): $sv = (string)$so['stream']; ?>
            <option value="<?php echo h($sv); ?>"<?php echo $stream === $sv ? ' selected' : ''; ?>><?php echo h($sv); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-bold mb-1">Subject</label>
      <select name="subject_id" class="form-select form-select-sm">
        <option value="0">All subjects</option>
        <?php foreach ($subjectOptions as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>"<?php echo $subject_id === (int)$s['id'] ? ' selected' : ''; ?>><?php echo h($s['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-bold mb-1">Teacher</label>
      <?php if (!$isAdmin): ?>
        <input type="text" class="form-control form-control-sm" value="<?php echo h($me['full_name'] ?? ''); ?>" disabled>
      <?php else: ?>
        <select name="teacher_id" class="form-select form-select-sm">
          <option value="0">All teachers</option>
          <?php foreach ($teacherOptions as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>"<?php echo $teacher_id === (int)$t['id'] ? ' selected' : ''; ?>><?php echo h($t['full_name']); ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="col-12 d-flex flex-wrap gap-2 mt-1">
      <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(url('teaching/timetable.php')); ?>"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </div>
  </form>
</div></div>

<?php if (!$tt): ?>
  <div class="alert alert-info">
    <?php if (!$isAdmin): ?>
      There is no published timetable for you at the moment. Contact the head of school.
    <?php elseif (!$ready): ?>
      Timetables could not be loaded because the tables are not installed.
    <?php else: ?>
      No timetable has been created for year <b><?php echo h($year['name'] ?? ''); ?></b> and group <b><?php echo h(tt_group_label($group)); ?></b>.
      Click <a href="<?php echo h(url('teaching/timetable_new.php?year_id=' . (int)$year['id'] . '&group=' . urlencode($group === 'all' ? 'ordinary' : $group))); ?>">Generate New</a> to create a new timetable.
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="d-flex flex-wrap gap-3 align-items-center small">
        <span><b>Status:</b>
          <?php
            $badge = $tt['status'] === 'Active' ? 'success' : ($tt['status'] === 'Pending' ? 'warning text-dark' : 'secondary');
            echo '<span class="badge text-bg-' . $badge . '">' . h($statusLabels[$tt['status']] ?? $tt['status']) . '</span>';
          ?>
        </span>
        <span><b>Published:</b> <?php echo h($tt['published_at'] ? date('d/m/Y H:i', strtotime($tt['published_at'])) : 'Not yet'); ?></span>
        <span><b>Group:</b> <?php echo h(tt_group_label($tt['group_level'])); ?></span>
        <?php if ($streamOptions): ?><span><b>Streams:</b> <?php echo count($streamOptions); ?></span><?php endif; ?>
        <span><b>Sessions per day:</b> <?php echo (int)$tt['sessions_per_day']; ?></span>
        <span><b>Session duration:</b> <?php echo (int)$tt['session_duration']; ?> mins</span>
        <span><b>Time:</b> <?php echo h(substr((string)$tt['start_time'], 0, 5) . ' - ' . substr((string)$tt['end_time'], 0, 5)); ?></span>
        <span><b>Saturday:</b> <?php echo !empty($tt['include_saturday']) ? 'Yes' : 'No'; ?></span>
        <span><b>Sunday:</b> <?php echo !empty($tt['include_sunday']) ? 'Yes' : 'No'; ?></span>
      </div>
    </div>
  </div>

  <div class="text-center mb-2">
    <div class="fw-bold text-uppercase"><?php $sc = active_school(); echo h($sc ? $sc['name'] : system_name()); ?></div>
    <div class="fw-semibold"><?php echo h($title); ?></div>
    <div class="small text-muted"><?php echo h($meta); ?></div>
  </div>

  <style>
  <?php echo tt_css(); ?>
  @media print{
    @page{size:A3 landscape;margin:6mm}
    .tt-wrap{overflow:visible}
    .tt-table{page-break-inside:auto}
    .tt-table thead{display:table-header-group}
    .tt-table tr{page-break-inside:avoid}
    .tt-table th,.tt-table td{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
  </style>

  <?php echo tt_render_table($matrix); ?>

  <?php if ($matrix['stats']): ?>
    <div class="card shadow-sm mt-3 d-print-none">
      <div class="card-header bg-white fw-bold">Period distribution by subject</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($matrix['stats'] as $name => $n): ?>
            <span class="badge text-bg-light border"><?php echo h($name); ?>: <b><?php echo (int)$n; ?></b></span>
          <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">
          Total visible periods: <b><?php echo (int)array_sum($matrix['stats']); ?></b>.
          <b>B</b> columns are breaks/events and are not used for lessons.
        </p>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php layout_end(); ?>
