<?php
/** School exam (single school). District exams are created by the super admin. */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ex = $id ? q_one('SELECT * FROM exams WHERE id = ? AND owner_school_id = ?', [$id, $school_id]) : null;
if ($id && !$ex) { flash('danger', 'You can only edit exams of your own school. District exams are edited by the super admin.'); redirect('admin/exams.php'); }
$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
$years = q_all('SELECT * FROM academic_years ORDER BY name DESC');
$cur = $id ? array_map('intval', array_column(q_all('SELECT class_id FROM exam_classes WHERE exam_id = ?', [$id]), 'class_id')) : [];

/* Subjects of each class (for this exam). */
$csReady = exam_subjects_ready();
$curSub = [];   // class_id => [subject_id, ...]  (yaliyohifadhiwa / yaliyowekwa alama)
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
    $sel = array_values(array_unique(array_map('intval', $_POST['classes'] ?? [])));

    /* Subjects chosen for each selected class. */
    $selSub = [];
    foreach ($sel as $cid) $selSub[$cid] = array_values(array_unique(array_map('intval', (array)($_POST['subjects'][$cid] ?? []))));

    $missing = [];
    if ($csReady) {
        foreach ($sel as $cid) {
            $sn = (string)q_val('SELECT name FROM classes WHERE id = ?', [$cid]);
            if (!$selSub[$cid]) $missing[] = $sn !== '' ? $sn : ('Darasa #' . $cid);
        }
    }

    if ($name === '' || !$sel) flash('danger', 'Exam name and at least one class are required.');
    elseif ($missing) flash('danger', 'Select at least one subject for each selected class: <b>' . h(implode(', ', $missing)) . '</b>.');
    else {
        try {
            db()->beginTransaction();
            if ($id) {
                $removed = array_diff($cur, $sel);
                foreach ($removed as $rc) if ((int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND class_id = ?', [$id, $rc]) > 0) throw new Exception('A class you removed already has saved scores; it cannot be removed.');
                q('UPDATE exams SET name=?, exam_type=?, academic_year_id=?, start_date=?, end_date=? WHERE id=? AND owner_school_id=?', [$name, $type, $year, $sd, $ed, $id, $school_id]);
                foreach ($removed as $rc) { q('DELETE FROM exam_classes WHERE exam_id = ? AND class_id = ?', [$id, $rc]); q('DELETE FROM exam_candidates WHERE exam_id = ? AND class_id = ?', [$id, $rc]); }
            } else {
                q('INSERT INTO exams (owner_school_id, name, exam_type, academic_year_id, start_date, end_date) VALUES (?,?,?,?,?,?)', [$school_id, $name, $type, $year, $sd, $ed]);
                $id = (int)db()->lastInsertId();
                q('INSERT INTO exam_schools (exam_id, school_id) VALUES (?, ?)', [$id, $school_id]);
            }
            foreach ($sel as $cid) q('INSERT IGNORE INTO exam_classes (exam_id, class_id) VALUES (?, ?)', [$id, $cid]);
            exam_sync_subjects($id, $selSub);
            db()->commit();
            flash('success', 'Exam saved together with the subjects of each class. Click "Register candidates" so students get exam numbers.');
            redirect('admin/exam_view.php?id=' . $id);
        } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    }
    $cur = $sel;
    $curSub = $selSub;
}
$cy = current_year();
$formName  = $_POST['name'] ?? ($ex['name'] ?? '');
$formType  = $_POST['exam_type'] ?? ($ex['exam_type'] ?? 'Terminal');
$formYear  = (int)($_POST['academic_year_id'] ?? ($ex['academic_year_id'] ?? ($cy['id'] ?? 0)));
$formStart = $_POST['start_date'] ?? ($ex['start_date'] ?? '');
$formEnd   = $_POST['end_date'] ?? ($ex['end_date'] ?? '');
layout_start($id ? 'Edit exam' : 'New school exam', 'exams'); ?>
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
.exf-class-h .form-check-input:focus{box-shadow:0 0 0 .2rem rgba(44,110,213,.16)}
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
.exf-sub span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.exf-sub:has(input:checked){background:#eef4fe;border-color:#bcd4f6;color:#0b3d91;font-weight:700}
.exf-empty{font-size:.79rem;color:#8a99b0;padding:6px 2px}
.exf-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;background:#fff;border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06)}
.exf-actions .btn-primary{background:linear-gradient(135deg,#2c6ed5,#0b3d91);border:0;border-radius:10px;font-weight:700;padding:.55rem 1.15rem;box-shadow:0 10px 20px -14px rgba(11,61,145,.95)}
.exf-actions .btn-primary:hover{filter:brightness(1.07)}
.exf-actions .btn-cancel{border-radius:10px;font-weight:700;border:1px solid #e2e8f6;color:#5b6b85;background:#fff}
.exf-actions .btn-cancel:hover{background:#f5f8fd;color:#16203a}
.exf-hint{margin-left:auto;font-size:.74rem;color:#8a99b0}
@media(max-width:575.98px){.exf-hero{padding:18px}.exf-hero h1{font-size:1.12rem}.exf-body{padding:14px}.exf-hint{display:none}}
</style>

<div class="exf-hero">
  <span class="exf-hero-ic"><i class="bi bi-journal-bookmark"></i></span>
  <div class="exf-hero-txt">
    <div class="exf-kicker"><i class="bi bi-<?php echo $id ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $id ? 'Edit exam' : 'New exam'; ?></div>
    <h1><?php echo $id ? h($ex['name']) : 'Create a school exam'; ?></h1>
    <p>Enter the exam details, then choose the classes and the subjects to be sat.</p>
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
        <input id="exname" name="name" class="form-control" required value="<?php echo h($formName); ?>" placeholder="e.g. Mid-Term Examination - Term I">
      </div>
      <div class="col-lg-4">
        <label class="form-label" for="extype">Type</label>
        <select id="extype" name="exam_type" class="form-select"><?php foreach (['Weekly Test', 'Monthly Test', 'Mid-Term', 'Terminal', 'Annual', 'Mock'] as $t) echo '<option' . ($formType === $t ? ' selected' : '') . '>' . $t . '</option>'; ?></select>
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
    <?php foreach ($classes as $c): $cid = (int)$c['id']; $on = in_array($cid, $cur, true);
          $subsAll = $classSubjects[$cid] ?? [];
          /* Selected subjects: use the saved choice if the class has one, otherwise all subjects (default). */
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
                  <span title="<?php echo h($s['name']); ?>"><?php echo h($s['name']); ?></span>
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

<div class="exf-actions">
  <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i> Save exam</button>
  <a class="btn btn-cancel" href="exams.php">Cancel</a>
  <span class="exf-hint"><i class="bi bi-shield-check me-1"></i> After saving you can register candidates.</span>
</div>
</form>

<script>
(function () {
  function panelOf(id) { return document.getElementById(id); }

  /* Show/hide a class's subjects from its checkbox; disable hidden inputs. */
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

  /* Select all / clear all for one class. */
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
