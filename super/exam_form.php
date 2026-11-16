<?php
/** District exam: classes + subjects per class + participating schools. */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ex = $id ? q_one('SELECT * FROM exams WHERE id = ?', [$id]) : null;
if ($id && !$ex) redirect('super/exams.php');
$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
$schools = q_all('SELECT id, name, centre_no FROM schools WHERE active = 1 ORDER BY name');
$years = q_all('SELECT * FROM academic_years ORDER BY name DESC');
$curC = $id ? array_map('intval', array_column(q_all('SELECT class_id FROM exam_classes WHERE exam_id = ?', [$id]), 'class_id')) : [];
$curS = $id ? array_map('intval', array_column(q_all('SELECT school_id FROM exam_schools WHERE exam_id = ?', [$id]), 'school_id')) : [];

/* Subjects of each class in this exam. */
$csReady = exam_subjects_ready();
$curSub = [];
if ($id && $csReady) {
    foreach (q_all('SELECT class_id, subject_id FROM exam_class_subjects WHERE exam_id = ?', [$id]) as $r) {
        $curSub[(int)$r['class_id']][] = (int)$r['subject_id'];
    }
}
$classSubjects = [];
foreach ($classes as $c) $classSubjects[(int)$c['id']] = class_subject_list($c['id']);

if (is_post()) {
    csrf_check();
    $name = trim($_POST['name'] ?? ''); $type = trim($_POST['exam_type'] ?? 'Terminal'); $year = (int)($_POST['academic_year_id'] ?? 0) ?: null;
    $sd = trim($_POST['start_date'] ?? '') ?: null; $ed = trim($_POST['end_date'] ?? '') ?: null;
    $selC = array_values(array_unique(array_map('intval', $_POST['classes'] ?? [])));
    $selS = isset($_POST['all_schools']) ? array_map('intval', array_column($schools, 'id')) : array_values(array_unique(array_map('intval', $_POST['schools'] ?? [])));
    $selSub = [];
    foreach ($selC as $cid) $selSub[$cid] = array_values(array_unique(array_map('intval', (array)($_POST['subjects'][$cid] ?? []))));

    $missing = [];
    if ($csReady) {
        foreach ($selC as $cid) {
            $cn = (string)q_val('SELECT name FROM classes WHERE id = ?', [$cid]);
            if (!$selSub[$cid]) $missing[] = $cn !== '' ? $cn : ('Darasa #' . $cid);
        }
    }

    if ($name === '' || !$selC || !$selS) flash('danger', 'Name, at least one class and at least one school are required.');
    elseif ($missing) flash('danger', 'Select at least one subject for each selected class: <b>' . h(implode(', ', $missing)) . '</b>.');
    else {
        try {
            db()->beginTransaction();
            if ($id) {
                foreach (array_diff($curC, $selC) as $rc) if ((int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND class_id = ?', [$id, $rc]) > 0) throw new Exception('A class you removed already has saved scores; it cannot be removed.');
                foreach (array_diff($curS, $selS) as $rs) if ((int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ?', [$id, $rs]) > 0) throw new Exception('A school you removed already has saved scores; it cannot be removed.');
                q('UPDATE exams SET name=?, exam_type=?, academic_year_id=?, start_date=?, end_date=? WHERE id=?', [$name, $type, $year, $sd, $ed, $id]);
                foreach (array_diff($curC, $selC) as $rc) { q('DELETE FROM exam_classes WHERE exam_id = ? AND class_id = ?', [$id, $rc]); q('DELETE FROM exam_candidates WHERE exam_id = ? AND class_id = ?', [$id, $rc]); }
                foreach (array_diff($curS, $selS) as $rs) { q('DELETE FROM exam_schools WHERE exam_id = ? AND school_id = ?', [$id, $rs]); q('DELETE FROM exam_candidates WHERE exam_id = ? AND school_id = ?', [$id, $rs]); }
            } else {
                q('INSERT INTO exams (owner_school_id, name, exam_type, academic_year_id, start_date, end_date) VALUES (NULL,?,?,?,?,?)', [$name, $type, $year, $sd, $ed]);
                $id = (int)db()->lastInsertId();
            }
            foreach ($selC as $cid) q('INSERT IGNORE INTO exam_classes (exam_id, class_id) VALUES (?, ?)', [$id, $cid]);
            foreach ($selS as $sid) q('INSERT IGNORE INTO exam_schools (exam_id, school_id) VALUES (?, ?)', [$id, $sid]);
            exam_sync_subjects($id, $selSub);
            db()->commit();
            flash('success', 'Exam saved together with the subjects of each class. Each school should register its candidates (or click "Register candidates for all schools").');
            redirect('super/exam_view.php?id=' . $id);
        } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    }
    $curC = $selC; $curS = $selS; $curSub = $selSub;
}
$cy = current_year();
$formName  = $_POST['name'] ?? ($ex['name'] ?? '');
$formType  = $_POST['exam_type'] ?? ($ex['exam_type'] ?? 'Joint');
$formYear  = (int)($_POST['academic_year_id'] ?? ($ex['academic_year_id'] ?? ($cy['id'] ?? 0)));
$formStart = $_POST['start_date'] ?? ($ex['start_date'] ?? '');
$formEnd   = $_POST['end_date'] ?? ($ex['end_date'] ?? '');
$allSchools = ($curS && count($curS) === count($schools));
layout_start($id ? 'Edit district exam' : 'New district exam', 'exams'); ?>
<style>
.exf-hero{position:relative;overflow:hidden;border-radius:20px;padding:22px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 58%,#2c6ed5 100%);color:#fff;margin-bottom:18px;box-shadow:0 14px 30px -18px rgba(11,61,145,.9)}
.exf-hero:after{content:"";position:absolute;right:-60px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.07)}
.exf-hero:before{content:"";position:absolute;right:70px;bottom:-90px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.06)}
.exf-hero-ic{position:relative;z-index:1;flex:0 0 auto;width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);box-shadow:inset 0 0 0 1px rgba(255,255,255,.25)}
.exf-hero-txt{position:relative;z-index:1;min-width:0;flex:1 1 260px}
.exf-kicker{font-size:.68rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#ffd764;display:flex;align-items:center;gap:6px}
.exf-hero h1{font-size:1.34rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.25}
.exf-hero p{margin:0;font-size:.82rem;color:rgba(255,255,255,.82)}
.exf-hero-link{position:relative;z-index:1;display:inline-flex;align-items:center;gap:7px;padding:.45rem .9rem;border-radius:10px;font-size:.78rem;font-weight:700;
  color:#fff;text-decoration:none;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);transition:background .15s}
.exf-hero-link:hover{background:rgba(255,255,255,.24);color:#fff}
.exf-card{background:#fff;border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden;margin-bottom:16px}
.exf-sec-h{display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid #eef2fa;background:linear-gradient(180deg,#fbfdff,#fff);flex-wrap:wrap}
.exf-sec-ic{flex:0 0 auto;width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#eef3fd;color:#0b3d91;font-size:1.05rem}
.exf-sec-t{font-weight:800;color:#16203a;line-height:1.2;font-size:.94rem}
.exf-sec-s{font-size:.73rem;color:#8a99b0;margin-top:2px}
.exf-pill{margin-left:auto;font-size:.67rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#0b3d91;background:#eef3fd;border:1px solid #dbe6fb;border-radius:999px;padding:4px 10px;white-space:nowrap}
.exf-body{padding:18px}
.exf-body .form-label{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.045em;color:#5b6b85;margin-bottom:.35rem}
.exf-body .form-control,.exf-body .form-select{border-color:#dfe7f5;border-radius:10px;padding:.52rem .75rem;font-size:.9rem;color:#16203a}
.exf-body .form-control:focus,.exf-body .form-select:focus{border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.exf-class{border:1.5px solid #e7ecf7;border-radius:14px;background:#fff;height:100%;display:flex;flex-direction:column;overflow:hidden;transition:border-color .16s,box-shadow .16s}
.exf-class:hover{border-color:#c9dcf8}
.exf-class.is-on{border-color:#2c6ed5;box-shadow:0 0 0 3px rgba(44,110,213,.1)}
.exf-class-h{display:flex;align-items:center;gap:10px;padding:12px 14px;margin:0;cursor:pointer;background:#fbfdff;border-bottom:1px solid #eef2fa;transition:background .16s}
.exf-class.is-on .exf-class-h{background:#eef4fe}
.exf-class-h .form-check-input{flex:0 0 auto;width:1.15em;height:1.15em;margin:0;cursor:pointer;border-color:#c3d3ec;border-radius:.3em}
.exf-class-h .form-check-input:checked{background-color:#2c6ed5;border-color:#2c6ed5}
.exf-class-name{font-weight:800;color:#16203a;font-size:.92rem}
.exf-class-count{margin-left:auto;font-size:.67rem;font-weight:800;color:#0b3d91;background:#eef3fd;border:1px solid #dbe6fb;border-radius:999px;padding:3px 9px;white-space:nowrap}
.exf-class-count.none{color:#8a99b0;background:#f4f6fa;border-color:#e9edf6}
.exf-class-b{flex:1 1 auto;display:flex;flex-direction:column;gap:9px;padding:12px 14px}
.exf-tools{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.exf-tools .lbl{font-size:.68rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#8a99b0}
.exf-link{background:none;border:0;padding:0;font-size:.75rem;font-weight:700;color:#2c6ed5}
.exf-link:hover{text-decoration:underline}
.exf-link.off{color:#8a99b0}
.exf-subs{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:6px;max-height:228px;overflow:auto;padding:2px}
.exf-subs::-webkit-scrollbar{width:8px}
.exf-subs::-webkit-scrollbar-thumb{background:#d7e2f5;border-radius:99px}
.exf-sub{display:flex;align-items:center;gap:7px;margin:0;padding:6px 9px;border:1px solid #e9eef8;border-radius:9px;background:#fff;
  font-size:.79rem;color:#33415c;cursor:pointer;min-width:0;transition:background .14s,border-color .14s,color .14s}
.exf-sub:hover{border-color:#c9dcf8;background:#f8fbff}
.exf-sub input{flex:0 0 auto;margin:0;accent-color:#2c6ed5}
.exf-sub .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.exf-sub .ct{margin-left:auto;font-size:.66rem;font-weight:800;color:#8a99b0;white-space:nowrap}
.exf-sub:has(input:checked){background:#eef4fe;border-color:#bcd4f6;color:#0b3d91;font-weight:700}
.exf-sub:has(input:checked) .ct{color:#2c6ed5}
.exf-empty{font-size:.79rem;color:#8a99b0;padding:6px 2px}
.exf-switch{display:flex;align-items:center;gap:10px;margin:0 0 10px;padding:11px 14px;border:1px solid #e2eaf8;border-radius:12px;background:#f7faff;font-size:.84rem;color:#16203a;font-weight:700}
.exf-switch .form-check-input{flex:0 0 auto;margin:0;width:1.15em;height:1.15em;cursor:pointer;border-color:#c3d3ec;accent-color:#2c6ed5}
.exf-switch .form-check-input:checked{background-color:#2c6ed5;border-color:#2c6ed5}
.exf-switch:has(.form-check-input:checked){border-color:#bcd4f6;background:#eef4fe}
.exf-switch small{font-weight:600;color:#8a99b0}
.exf-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;background:#fff;border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06)}
.exf-actions .btn-primary{background:linear-gradient(135deg,#2c6ed5,#0b3d91);border:0;border-radius:10px;font-weight:700;padding:.55rem 1.15rem;box-shadow:0 10px 20px -14px rgba(11,61,145,.95)}
.exf-actions .btn-primary:hover{filter:brightness(1.07)}
.exf-actions .btn-cancel{border-radius:10px;font-weight:700;border:1px solid #e2e8f6;color:#5b6b85;background:#fff}
.exf-actions .btn-cancel:hover{background:#f5f8fd;color:#16203a}
.exf-hint{margin-left:auto;font-size:.74rem;color:#8a99b0}
@media(max-width:575.98px){.exf-hero{padding:18px}.exf-hero h1{font-size:1.12rem}.exf-body{padding:14px}.exf-hint{display:none}}
</style>

<div class="exf-hero">
  <span class="exf-hero-ic"><i class="bi bi-bank"></i></span>
  <div class="exf-hero-txt">
    <div class="exf-kicker"><i class="bi bi-<?php echo $id ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $id ? 'Edit district exam' : 'New district exam'; ?></div>
    <h1><?php echo $id ? h($ex['name']) : 'Create a district exam'; ?></h1>
    <p>Enter the exam details, then choose the classes, subjects and participating schools.</p>
  </div>
  <a class="exf-hero-link" href="exams.php"><i class="bi bi-arrow-left"></i> All exams</a>
</div>

<form method="post">
<?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">

<div class="exf-card">
  <div class="exf-sec-h">
    <span class="exf-sec-ic"><i class="bi bi-info-circle"></i></span>
    <div>
      <div class="exf-sec-t">Exam details</div>
      <div class="exf-sec-s">Name, type, academic year and dates</div>
    </div>
    <span class="exf-pill"><i class="bi bi-asterisk"></i> Required</span>
  </div>
  <div class="exf-body">
    <div class="row g-3">
      <div class="col-lg-8">
        <label class="form-label" for="exname">Exam name *</label>
        <input id="exname" name="name" class="form-control" required value="<?php echo h($formName); ?>" placeholder="e.g. District Mock Examination - Form IV">
      </div>
      <div class="col-lg-4">
        <label class="form-label" for="extype">Type</label>
        <select id="extype" name="exam_type" class="form-select"><?php foreach (['Mid-Term', 'Terminal', 'Annual', 'Joint', 'Mock', 'Pre-National'] as $t) echo '<option' . ($formType === $t ? ' selected' : '') . '>' . $t . '</option>'; ?></select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exyear">Academic year</label>
        <select id="exyear" name="academic_year_id" class="form-select"><?php foreach ($years as $y) echo '<option value="' . (int)$y['id'] . '"' . ($formYear === (int)$y['id'] ? ' selected' : '') . '>' . h($y['name']) . '</option>'; ?></select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exstart"><i class="bi bi-calendar-event me-1"></i> Start date</label>
        <input id="exstart" type="date" name="start_date" class="form-control" value="<?php echo h($formStart); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exend"><i class="bi bi-calendar-check me-1"></i> End date</label>
        <input id="exend" type="date" name="end_date" class="form-control" value="<?php echo h($formEnd); ?>">
      </div>
    </div>
  </div>
</div>

<div class="exf-card">
  <div class="exf-sec-h">
    <span class="exf-sec-ic"><i class="bi bi-building"></i></span>
    <div>
      <div class="exf-sec-t">Classes and subjects *</div>
      <div class="exf-sec-s">Pick a class, then choose the subjects to be sat for that class.</div>
    </div>
    <span class="exf-pill">Subjects are chosen per class</span>
  </div>
  <div class="exf-body">
    <?php if (!$csReady): ?>
    <div class="alert alert-warning small d-flex gap-2 py-2 mb-3"><i class="bi bi-exclamation-triangle-fill"></i><span>The exam subjects table is not installed. Import <code>database/migration_exam_subjects.sql</code> in phpMyAdmin to choose subjects per class; for now the system uses every subject of the class.</span></div>
    <?php endif; ?>
    <div class="row g-3">
    <?php foreach ($classes as $c): $cid = (int)$c['id']; $on = in_array($cid, $curC, true);
          $subsAll = $classSubjects[$cid] ?? [];
          $checked = array_key_exists($cid, $curSub)
              ? array_map('intval', $curSub[$cid])
              : array_map('intval', array_column($subsAll, 'id')); ?>
      <div class="col-12 col-xl-6">
        <div class="exf-class<?php echo $on ? ' is-on' : ''; ?>">
          <label class="exf-class-h" for="c<?php echo $cid; ?>">
            <input class="form-check-input class-toggle" type="checkbox" name="classes[]" id="c<?php echo $cid; ?>" value="<?php echo $cid; ?>" data-subject-panel="subj<?php echo $cid; ?>" <?php echo $on ? 'checked' : ''; ?>>
            <span class="exf-class-name"><?php echo h($c['name']); ?></span>
            <span class="exf-class-count<?php echo $subsAll ? '' : ' none'; ?>"><?php echo count($subsAll); ?> subjects</span>
          </label>
          <div class="exf-class-b<?php echo $on ? '' : ' d-none'; ?>" id="subj<?php echo $cid; ?>">
            <?php if (!$subsAll): ?>
              <div class="exf-empty"><i class="bi bi-inbox me-1"></i> This class has no subjects. Add subjects under <b>Classes &rarr; Subjects</b>.</div>
            <?php else: ?>
              <div class="exf-tools">
                <span class="lbl">Exam subjects</span>
                <span class="text-nowrap">
                  <button type="button" class="exf-link subj-all" data-panel="subj<?php echo $cid; ?>">Select all</button>
                  <span class="text-muted mx-1">|</span>
                  <button type="button" class="exf-link off subj-none" data-panel="subj<?php echo $cid; ?>">Clear all</button>
                </span>
              </div>
              <div class="exf-subs">
              <?php foreach ($subsAll as $s): $sid = (int)$s['id']; ?>
                <label class="exf-sub" for="s<?php echo $cid; ?>_<?php echo $sid; ?>">
                  <input class="form-check-input" type="checkbox" name="subjects[<?php echo $cid; ?>][]" id="s<?php echo $cid; ?>_<?php echo $sid; ?>" value="<?php echo $sid; ?>" <?php echo in_array($sid, $checked, true) ? 'checked' : ''; ?>>
                  <span class="nm" title="<?php echo h($s['name']); ?>"><?php echo h($s['name']); ?></span>
                </label>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="exf-card">
  <div class="exf-sec-h">
    <span class="exf-sec-ic"><i class="bi bi-bank2"></i></span>
    <div>
      <div class="exf-sec-t">Participating schools *</div>
      <div class="exf-sec-s">Choose the schools that will sit this exam.</div>
    </div>
    <span class="exf-pill"><i class="bi bi-buildings me-1"></i><?php echo count($schools); ?> active schools</span>
  </div>
  <div class="exf-body">
    <label class="exf-switch" for="allS">
      <input class="form-check-input" type="checkbox" name="all_schools" id="allS" <?php echo $allSchools ? 'checked' : ''; ?> onchange="document.querySelectorAll('.sch').forEach(function(c){c.disabled=this.checked}.bind(this))">
      <span>All schools (active) <small class="d-block">Tick this to include every school at once.</small></span>
    </label>
    <div class="exf-subs" style="max-height:260px">
    <?php foreach ($schools as $s): ?>
      <label class="exf-sub" for="s<?php echo (int)$s['id']; ?>">
        <input class="form-check-input sch" type="checkbox" name="schools[]" id="s<?php echo (int)$s['id']; ?>" value="<?php echo (int)$s['id']; ?>" <?php echo in_array((int)$s['id'], $curS, true) ? 'checked' : ''; ?>>
        <span class="nm" title="<?php echo h($s['name']); ?>"><?php echo h($s['name']); ?></span>
        <span class="ct"><?php echo h($s['centre_no']); ?></span>
      </label>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="exf-actions">
  <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i> Save exam</button>
  <a class="btn btn-cancel" href="exams.php">Cancel</a>
  <span class="exf-hint"><i class="bi bi-shield-check me-1"></i> Each school registers its own candidates after saving.</span>
</div>
</form>

<script>
(function () {
  function panelOf(id) { return document.getElementById(id); }

  Array.prototype.forEach.call(document.querySelectorAll('.class-toggle'), function (cb) {
    var panel = panelOf(cb.getAttribute('data-subject-panel'));
    var card = cb.closest('.exf-class');
    function apply() {
      if (panel) {
        panel.classList.toggle('d-none', !cb.checked);
        Array.prototype.forEach.call(panel.querySelectorAll('input[type=checkbox]'), function (i) { i.disabled = !cb.checked; });
      }
      if (card) card.classList.toggle('is-on', cb.checked);
    }
    cb.addEventListener('change', apply);
    apply();
  });

  Array.prototype.forEach.call(document.querySelectorAll('.subj-all, .subj-none'), function (btn) {
    btn.addEventListener('click', function () {
      var panel = panelOf(btn.getAttribute('data-panel'));
      if (!panel) return;
      var on = btn.classList.contains('subj-all');
      Array.prototype.forEach.call(panel.querySelectorAll('input[type=checkbox]'), function (i) { i.checked = on; });
    });
  });
})();
</script>
<?php layout_end(); ?>
