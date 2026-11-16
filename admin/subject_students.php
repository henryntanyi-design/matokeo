<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$subject = $id ? q_one('SELECT * FROM subjects WHERE id = ?', [$id]) : null;
if (!$subject) { flash('danger', 'Subject not found.'); redirect('admin/subjects.php'); }

/* Classes that take this subject */
$classes = q_all('SELECT c.* FROM class_subjects cs JOIN classes c ON c.id = cs.class_id
    WHERE cs.subject_id = ? ORDER BY c.sort_order, c.id', [$id]);

$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$classIds = array_map(function ($c) { return (int)$c['id']; }, $classes);
if (!in_array($class_id, $classIds, true)) $class_id = $classIds[0] ?? 0;
$class = $class_id ? q_one('SELECT * FROM classes WHERE id = ?', [$class_id]) : null;

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
if ($exam_id && !exam_has_school($exam_id, $school_id)) $exam_id = 0;

/* Next subject: only subjects with enrolled students, and (when an exam is
   given) only those registered in that exam. */
$nextPairs = [];
if ($exam_id) {
    foreach (exam_class_list($exam_id) as $c) {
        $cid = (int)$c['id'];
        foreach (exam_subject_list($exam_id, $cid) as $s) {
            $sid = (int)$s['id'];
            $reg = (int)q_val("SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
                WHERE ss.subject_id = ? AND st.school_id = ? AND st.class_id = ? AND st.status = 'active'", [$sid, $school_id, $cid]);
            if ($reg > 0) $nextPairs[] = ['subject_id' => $sid, 'class_id' => $cid];
        }
    }
} else {
    $rows = q_all("SELECT ss.subject_id, st.class_id
        FROM student_subjects ss JOIN students st ON st.id = ss.student_id
        JOIN subjects s ON s.id = ss.subject_id JOIN classes c ON c.id = st.class_id
        WHERE st.school_id = ? AND st.status = 'active'
        GROUP BY ss.subject_id, st.class_id
        ORDER BY s.is_subsidiary, s.name, c.sort_order, c.id", [$school_id]);
    foreach ($rows as $r) $nextPairs[] = ['subject_id' => (int)$r['subject_id'], 'class_id' => (int)$r['class_id']];
}
$nextSubjectId = 0; $nextClassId = 0;
$idx = -1;
foreach ($nextPairs as $i => $pr) {
    if ((int)$pr['subject_id'] === $id && (int)$pr['class_id'] === $class_id) { $idx = $i; break; }
}
if ($idx >= 0 && isset($nextPairs[$idx + 1])) {
    $nextSubjectId = (int)$nextPairs[$idx + 1]['subject_id'];
    $nextClassId = (int)$nextPairs[$idx + 1]['class_id'];
} elseif ($idx < 0 && $nextPairs) {
    $nextSubjectId = (int)$nextPairs[0]['subject_id'];
    $nextClassId = (int)$nextPairs[0]['class_id'];
}

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'save' && $class_id) {
        /* Only valid students of this class */
        $allowed = [];
        foreach (q_all("SELECT id FROM students WHERE school_id = ? AND class_id = ? AND status = 'active'", [$school_id, $class_id]) as $r) {
            $allowed[(int)$r['id']] = true;
        }
        $picked = [];
        foreach ((array)($_POST['students'] ?? []) as $sid) {
            $sid = (int)$sid;
            if (isset($allowed[$sid])) $picked[$sid] = true;
        }
        try {
            $db = db();
            $db->beginTransaction();
            q('DELETE ss FROM student_subjects ss JOIN students st ON st.id = ss.student_id
               WHERE ss.subject_id = ? AND st.school_id = ? AND st.class_id = ?', [$id, $school_id, $class_id]);
            foreach (array_keys($picked) as $sid) {
                q('INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)', [$sid, $id]);
            }
            $db->commit();
            flash('success', 'Enrolment for <b>' . $subject['name'] . '</b> in <b>' . h($class['name']) . '</b> saved: <b>' . count($picked) . '</b> student(s).');
        } catch (Exception $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', 'Failed to save enrolment: ' . h($e->getMessage()));
        }
        redirect('admin/subject_students.php?id=' . $id . '&class_id=' . $class_id);
    }
}

$students = $class_id ? q_all('SELECT st.id, st.first_name, st.middle_name, st.last_name, st.sex, st.prems_no, st.admission_no,
        (ss.student_id IS NOT NULL) AS enrolled
    FROM students st
    LEFT JOIN student_subjects ss ON ss.student_id = st.id AND ss.subject_id = ?
    WHERE st.school_id = ? AND st.class_id = ? AND st.status = \'active\'
    ORDER BY st.last_name, st.first_name, st.middle_name', [$id, $school_id, $class_id]) : [];
$enrolledCount = 0;
foreach ($students as $st) { if ($st['enrolled']) $enrolledCount++; }

$totalSubject = (int)q_val("SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
    WHERE ss.subject_id = ? AND st.school_id = ? AND st.status = 'active'", [$id, $school_id]);
$otherClasses = 0;
if ($class_id) {
    $otherClasses = (int)q_val("SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
        WHERE ss.subject_id = ? AND st.school_id = ? AND st.status = 'active' AND st.class_id <> ?", [$id, $school_id, $class_id]);
}

layout_start('Enrol students', 'subjects'); ?>

<style>
.ss-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.ss-hero::before,.ss-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.ss-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.ss-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.ss-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.ss-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.ss-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.ss-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.ss-hero h1 span{color:#ffd764}
.ss-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.ss-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.ss-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.ss-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.ss-btn:hover{transform:translateY(-1px)}
.ss-btn.solid{background:#fff;color:#0b3d91}
.ss-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.ss-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.ss-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.ss-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.ss-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.ss-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.ss-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.ss-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.ss-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}

.ss-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.ss-table tbody td{border-color:#f2f5fb;vertical-align:middle}
.ss-table tbody tr:hover{background:#fafcff}

.ss-summary .rowline{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:.82rem;border-bottom:1px solid #f0f4fb}
.ss-summary .rowline:last-child{border-bottom:0}
.ss-summary .rowline b{color:#16203a}
.ss-summary-box{background:#f7faff;border:1px solid #e6ecf8;border-radius:12px;padding:12px 14px;text-align:center;height:100%}
.ss-summary-box .lbl{font-size:.66rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8b9ab1;margin-bottom:4px}
.ss-summary-box .val{font-size:1.15rem;font-weight:800;color:#16203a;line-height:1.2}

.ss-note{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:10px 11px;font-size:.73rem;color:#3f5a80;line-height:1.45}
.ss-note i{color:#2c6ed5;font-size:.9rem;flex:0 0 auto;margin-top:1px}

@media (max-width:575.98px){
  .ss-hero{padding:18px;border-radius:18px}
  .ss-hero h1{font-size:1.2rem}
  .ss-date{margin-left:0}
}
</style>

<div class="ss-hero">
    <div class="ss-head">
        <span class="ss-mark"><i class="bi bi-person-check"></i></span>
        <div>
            <div class="ss-kicker">Subject enrolment</div>
            <h1><?php echo h($subject['name']); ?> <span>students</span></h1>
            <p class="ss-sub">
                Level: <?php echo h(strtoupper(subject_level_label($subject['level']))); ?>
                <?php if (!empty($subject['code'])): ?> &middot; <?php echo h($subject['code']); ?><?php endif; ?>
                &middot; School enrolment: <?php echo $totalSubject; ?> student(s)
                <?php if ($otherClasses > 0): ?>(<?php echo $otherClasses; ?> in other classes)<?php endif; ?>
            </p>
        </div>
        <span class="ss-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="ss-actions">
        <?php if ($nextSubjectId): ?>
        <a class="ss-btn ghost" href="<?php echo h(url('admin/subject_students.php?id=' . $nextSubjectId . ($nextClassId ? '&class_id=' . $nextClassId : '') . ($exam_id ? '&exam_id=' . $exam_id : ''))); ?>"><i class="bi bi-arrow-right"></i> Next subject</a>
        <?php else: ?>
        <a class="ss-btn ghost" href="<?php echo h(url('admin/exam_view.php?id=' . ($exam_id ?: 1))); ?>"><i class="bi bi-arrow-left"></i> Back to exam</a>
        <?php endif; ?>
        <a class="ss-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-house"></i> Dashboard</a>
    </div>
</div>

<?php if (!$classes): ?>
  <div class="ss-card">
    <div class="card-body" style="padding:40px 20px">
      <div class="ss-empty">
        <i class="bi bi-info-circle"></i>
        <div class="fw-semibold text-dark">This subject is not assigned to any class yet.</div>
        <div class="small">Assign this subject to a class first to enrol students.</div>
        <a class="btn btn-primary btn-sm mt-3" href="<?php echo h(url('admin/exam_view.php?id=1')); ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
      </div>
    </div>
  </div>
<?php else: ?>

<div class="ss-card mb-3">
  <div class="ss-card-h">
    <span class="ss-card-t"><i class="bi bi-bar-chart text-primary"></i> Summary</span>
    <span class="ss-pill">Enrolled: <span id="selCountTop"><?php echo $enrolledCount; ?></span> / <?php echo count($students); ?></span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-6 col-md-3 col-xl">
        <div class="ss-summary-box">
          <div class="lbl">Class</div>
          <div class="val"><?php echo h($class['name'] ?? '-'); ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-xl">
        <div class="ss-summary-box">
          <div class="lbl">Class students</div>
          <div class="val"><?php echo count($students); ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-xl">
        <div class="ss-summary-box">
          <div class="lbl">Enrolled</div>
          <div class="val text-success"><?php echo $enrolledCount; ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-xl">
        <div class="ss-summary-box">
          <div class="lbl">Not enrolled</div>
          <div class="val text-muted"><?php echo count($students) - $enrolledCount; ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-xl">
        <div class="ss-summary-box">
          <div class="lbl">Total subject enrolment</div>
          <div class="val"><?php echo $totalSubject; ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="ss-card mb-3">
  <div class="ss-card-h">
    <span class="ss-card-t"><i class="bi bi-people text-primary"></i> Class students</span>
    <span class="ss-pill">Enrolled: <span id="selCount"><?php echo $enrolledCount; ?></span> / <?php echo count($students); ?></span>
    <form method="get" class="ms-auto d-flex flex-wrap align-items-center gap-2">
      <input type="hidden" name="id" value="<?php echo $id; ?>">
      <select name="class_id" class="form-select form-select-sm" style="border-radius:999px" onchange="this.form.submit()">
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $class_id ? ' selected' : ''; ?>><?php echo h($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <span class="small text-muted">Change class here.</span>
    </form>
  </div>

  <form method="post" id="regForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

    <?php if (!$students): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-person-x fs-2 d-block mb-2"></i>
        No active students in <?php echo h($class['name']); ?>.
      </div>
    <?php else: ?>
    <div class="table-responsive" style="max-height:60vh;overflow-y:auto">
      <table class="table table-sm table-hover align-middle mb-0 ss-table">
        <thead class="sticky-top bg-white">
          <tr>
            <th style="width:56px">S/N</th>
            <th>Student name</th>
            <th class="d-none d-sm-table-cell">PREMS</th>
            <th class="d-none d-md-table-cell">Sex</th>
            <th class="text-center" style="width:44px" title="Select all"><input type="checkbox" class="form-check-input" id="checkAll" <?php echo ($students && $enrolledCount === count($students)) ? 'checked' : ''; ?>></th>
            <th class="text-end pe-3" style="width:110px">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $i => $st): ?>
          <tr>
            <td class="text-muted"><?php echo $i + 1; ?></td>
            <td class="fw-semibold"><?php echo h(full_name($st)); ?></td>
            <td class="d-none d-sm-table-cell font-monospace small"><?php echo h($st['prems_no'] ?: '-'); ?></td>
            <td class="d-none d-md-table-cell small"><?php echo $st['sex'] === 'F' ? 'Female' : ($st['sex'] === 'M' ? 'Male' : h((string)$st['sex'])); ?></td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input student-check" name="students[]" value="<?php echo (int)$st['id']; ?>" <?php echo $st['enrolled'] ? 'checked' : ''; ?>>
            </td>
            <td class="text-end pe-3">
              <span class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle enrolled-badge<?php echo $st['enrolled'] ? '' : ' d-none'; ?>">Enrolled</span>
              <span class="badge rounded-pill bg-light text-muted border enrolled-badge<?php echo $st['enrolled'] ? ' d-none' : ''; ?>">Not enrolled</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
      <div class="small text-muted">
        <i class="bi bi-shield-check me-1"></i> Only selected students will be enrolled in this subject.
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary px-4" id="saveBtn"><i class="bi bi-person-check me-1"></i> Enroll</button>
      </div>
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="ss-note mb-3">
  <i class="bi bi-lightbulb"></i>
  <span>Form V &amp; VI students choose their subjects (principal); O-Level subjects are compulsory. This is why enrolment matters most for A-Level.</span>
</div>

<script>
(function () {
  var form = document.getElementById('regForm');
  if (!form) return;
  var checks = Array.prototype.slice.call(form.querySelectorAll('.student-check'));
  var all = document.getElementById('checkAll');
  var out = document.getElementById('selCount');
  var outTop = document.getElementById('selCountTop');
  function refresh() {
    var n = 0;
    checks.forEach(function (c) {
      var badge = c.closest('tr').querySelectorAll('.enrolled-badge');
      badge[0].classList.toggle('d-none', !c.checked);
      badge[1].classList.toggle('d-none', c.checked);
      if (c.checked) n++;
    });
    if (out) out.textContent = n;
    if (outTop) outTop.textContent = n;
    if (all) all.checked = n > 0 && n === checks.length;
    if (all) all.indeterminate = n > 0 && n < checks.length;
  }
  checks.forEach(function (c) { c.addEventListener('change', refresh); });
  if (all) all.addEventListener('change', function () {
    checks.forEach(function (c) { c.checked = all.checked; });
    refresh();
  });
  form.addEventListener('submit', function (e) {
    var n = checks.filter(function (c) { return c.checked; }).length;
    if (n === 0 && !confirm('No student selected. Enrol all students of this class in the subject?')) e.preventDefault();
  });
  refresh();
})();
</script>
<?php endif; ?>

<?php layout_end();