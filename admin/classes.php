<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

/* ---- Actions: save class, delete class, save class subjects ---- */
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $back = 'admin/classes.php';
    try {
        if ($a === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $lvl = (($_POST['level'] ?? 'O') === 'A') ? 'A' : 'O';
            $ord = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') {
                flash('danger', 'Class name is required.');
            } else {
                if ($id) {
                    q('UPDATE classes SET name = ?, level = ?, sort_order = ? WHERE id = ?', [$name, $lvl, $ord, $id]);
                    flash('success', 'Class <b>' . h($name) . '</b> updated.');
                } else {
                    q('INSERT INTO classes (name, level, sort_order) VALUES (?, ?, ?)', [$name, $lvl, $ord]);
                    flash('success', 'Class <b>' . h($name) . '</b> added.');
                }
            }
        } elseif ($a === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $cls = q_one('SELECT * FROM classes WHERE id = ?', [$id]);
            $n = $id ? (int)q_val('SELECT COUNT(*) FROM students WHERE class_id = ?', [$id]) : 0;
            if (!$cls) {
                flash('danger', 'Class not found.');
            } elseif ($n > 0) {
                flash('danger', 'Class <b>' . h($cls['name']) . '</b> has ' . $n . ' student(s) and cannot be deleted. Move them to another class first.');
            } else {
                q('DELETE FROM classes WHERE id = ?', [$id]);
                flash('success', 'Class <b>' . h($cls['name']) . '</b> deleted.');
            }
        } elseif ($a === 'subjects') {
            $id = (int)($_POST['class_id'] ?? 0);
            $back = 'admin/classes.php?subjects=' . $id;
            $cls = q_one('SELECT * FROM classes WHERE id = ?', [$id]);
            if (!$cls) {
                flash('danger', 'Class not found.');
            } else {
                $sel = array_map('intval', is_array($_POST['sub'] ?? null) ? $_POST['sub'] : []);
                $comp = is_array($_POST['comp'] ?? null) ? $_POST['comp'] : [];
                db()->beginTransaction();
                q('DELETE FROM class_subjects WHERE class_id = ?', [$id]);
                foreach ($sel as $sid) {
                    q('INSERT INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, ?)', [$id, $sid, isset($comp[$sid]) ? 1 : 0]);
                }
                db()->commit();
                flash('success', 'Subjects for <b>' . h($cls['name']) . '</b> saved (' . count($sel) . ' selected).');
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', $e->getCode() == 23000 ? 'A class with that name already exists.' : h($e->getMessage()));
    }
    redirect($back);
}

$editId  = (int)($_GET['edit'] ?? 0);
$subjId  = (int)($_GET['subjects'] ?? 0);
$edit    = $editId ? q_one('SELECT * FROM classes WHERE id = ?', [$editId]) : null;
$subjCls = $subjId ? q_one('SELECT * FROM classes WHERE id = ?', [$subjId]) : null;

$rows = q_all("SELECT c.*,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.school_id = ? AND s.status = 'active') AS n_students,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS n_all_students,
        (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS n_subjects
    FROM classes c ORDER BY c.sort_order, c.id", [$school_id]);
$total = count($rows); $nO = 0; $nA = 0; $nStudents = 0;
foreach ($rows as $c) { if (($c['level'] ?? 'O') === 'A') $nA++; else $nO++; $nStudents += (int)$c['n_students']; }
$lvlLabel = ['O' => 'O-Level', 'A' => 'A-Level'];
$nextOrder = (int)q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM classes');

/* Subjects panel data */
$subjects = []; $cur = [];
if ($subjCls) {
    $subjects = q_all("SELECT s.*,
            (SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
              WHERE ss.subject_id = s.id AND st.school_id = ? AND st.status = 'active') AS n_enrolled
        FROM subjects s ORDER BY s.is_subsidiary, s.name", [$school_id]);
    foreach (q_all('SELECT subject_id, is_compulsory FROM class_subjects WHERE class_id = ?', [$subjId]) as $r) {
        $cur[(int)$r['subject_id']] = (int)$r['is_compulsory'];
    }
}

layout_start('Classes', 'classes'); ?>

<style>
.acl-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.acl-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.acl-hero .inner{position:relative;z-index:2;min-width:0}
.acl-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.acl-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.acl-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:640px;line-height:1.45}
.acl-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.acl-btn{display:inline-flex;align-items:center;gap:7px;padding:.48rem .95rem;border-radius:11px;font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.acl-btn:hover{transform:translateY(-1px)}
.acl-btn.solid{background:#fff;color:#0b3d91}
.acl-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.acl-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.acl-chip{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e7ecf7;border-radius:14px;padding:12px 14px;height:100%;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.acl-chip .ic{flex:0 0 40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1rem}
.acl-chip .v{font-size:1.25rem;font-weight:800;line-height:1.1;color:#16203a}
.acl-chip .k{font-size:.72rem;font-weight:700;color:#5b6b85;text-transform:uppercase;letter-spacing:.04em}
.acl-panel{position:sticky;top:16px}
.acl-panel-h{display:flex;align-items:center;gap:8px;padding:.85rem 1.05rem;border-bottom:1px solid #eef2fa;font-weight:800;color:#16203a}
.acl-panel-h i{color:#2c6ed5}
.acl-subj-list{max-height:380px;overflow:auto}
.acl-subj{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #f2f5fb;font-size:.85rem}
.acl-subj:last-child{border-bottom:0}
.acl-subj:hover{background:#fafcff}
.acl-subj .nm{flex:1 1 auto;min-width:0;font-weight:600;color:#16203a;line-height:1.25;cursor:pointer;margin:0}
.acl-subj .nm small{display:block;font-weight:600;color:#8a99b0;font-size:.7rem}
.acl-subj .form-check-input{cursor:pointer;margin:0}
.acl-subj .form-check-label{font-size:.72rem;font-weight:700;color:#5b6b85;cursor:pointer}
.acl-subj.is-on{background:#f4f8ff}
@media(max-width:991px){.acl-panel{position:static}}
@media(max-width:575.98px){.acl-hero{padding:18px;border-radius:16px}.acl-hero h1{font-size:1.25rem}}
</style>

<div class="acl-hero">
  <div class="inner">
    <div class="kicker"><i class="bi bi-building"></i> Manage class</div>
    <h1>Classes</h1>
    <p>Add, edit or delete classes, and register the subjects studied in each class. Classes are shared by all schools in the district, so changes apply everywhere the class is used.</p>
  </div>
  <div class="actions">
    <a class="acl-btn solid" href="<?php echo h(url('admin/classes.php')); ?>"><i class="bi bi-plus-lg"></i> New class</a>
    <a class="acl-btn ghost" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-people-fill"></i> Students</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="acl-chip">
      <span class="ic" style="background:linear-gradient(135deg,#0b3d91,#2c6ed5)"><i class="bi bi-building"></i></span>
      <span><span class="d-block v"><?php echo number_format($total); ?></span><span class="d-block k">Classes</span></span>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="acl-chip">
      <span class="ic" style="background:linear-gradient(135deg,#0e7a55,#19b37d)"><i class="bi bi-book"></i></span>
      <span><span class="d-block v"><?php echo number_format($nO); ?></span><span class="d-block k">O-Level</span></span>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="acl-chip">
      <span class="ic" style="background:linear-gradient(135deg,#7048e8,#4c2fa8)"><i class="bi bi-mortarboard"></i></span>
      <span><span class="d-block v"><?php echo number_format($nA); ?></span><span class="d-block k">A-Level</span></span>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="acl-chip">
      <span class="ic" style="background:linear-gradient(135deg,#b06a00,#e8a019)"><i class="bi bi-people-fill"></i></span>
      <span><span class="d-block v"><?php echo number_format($nStudents); ?></span><span class="d-block k">Active students</span></span>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-building text-primary"></i>
        <span class="fw-semibold">Class list</span>
        <span class="badge bg-primary-subtle text-primary-emphasis ms-auto"><?php echo number_format($total); ?> class(es)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th class="ps-3">Class</th><th>Level</th><th>Subjects</th><th>Students</th><th class="text-end pe-3">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $c): ?>
            <tr<?php echo ($editId === (int)$c['id'] || $subjId === (int)$c['id']) ? ' class="table-active"' : ''; ?>>
              <td class="ps-3">
                <div class="fw-semibold"><?php echo h($c['name']); ?></div>
                <div class="small text-muted"><i class="bi bi-sort-numeric-down me-1"></i>Order <?php echo (int)$c['sort_order']; ?></div>
              </td>
              <td><span class="badge bg-<?php echo ($c['level'] ?? 'O') === 'A' ? 'primary' : 'success'; ?>-subtle text-<?php echo ($c['level'] ?? 'O') === 'A' ? 'primary' : 'success'; ?>-emphasis border border-<?php echo ($c['level'] ?? 'O') === 'A' ? 'primary' : 'success'; ?>-subtle"><?php echo h($lvlLabel[$c['level'] ?? 'O'] ?? 'O-Level'); ?></span></td>
              <td><a class="badge bg-light text-secondary-emphasis border text-decoration-none" href="<?php echo h(url('admin/classes.php?subjects=' . (int)$c['id'])); ?>" title="Register subjects"><i class="bi bi-book me-1"></i><?php echo (int)$c['n_subjects']; ?></a></td>
              <td><span class="badge bg-light text-secondary-emphasis border"><i class="bi bi-people-fill me-1"></i><?php echo (int)$c['n_students']; ?></span></td>
              <td class="text-end pe-3 text-nowrap">
                <div class="btn-group">
                  <a class="btn btn-outline-primary btn-sm" href="<?php echo h(url('admin/classes.php?subjects=' . (int)$c['id'])); ?>" title="Subjects studied in this class"><i class="bi bi-book me-1"></i>Subjects</a>
                  <a class="btn btn-outline-secondary btn-sm" href="<?php echo h(url('admin/classes.php?edit=' . (int)$c['id'])); ?>" title="Edit class"><i class="bi bi-pencil me-1"></i>Edit</a>
                  <?php if ((int)$c['n_all_students'] > 0): ?>
                    <button class="btn btn-outline-danger btn-sm" disabled title="This class has students; move them to another class first"><i class="bi bi-trash me-1"></i>Delete</button>
                  <?php else: ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this class? It will be removed for every school using it.');">
                      <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                      <button class="btn btn-outline-danger btn-sm" title="Delete class"><i class="bi bi-trash me-1"></i>Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="5"><div class="text-center py-4 text-muted small"><i class="bi bi-building display-6 d-block mb-2"></i>Hakuna madarasa yaliyopangwa bado.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
  <?php if ($subjCls): ?>
    <?php $curLevel = ($subjCls['level'] ?? 'O') === 'A' ? 'A' : 'O'; ?>
    <div class="card acl-panel">
      <div class="acl-panel-h">
        <i class="bi bi-book"></i> Subjects for <?php echo h($subjCls['name']); ?>
        <span class="badge bg-primary-subtle text-primary-emphasis ms-auto"><?php echo count($cur); ?> / <?php echo count($subjects); ?></span>
      </div>
      <div class="p-2 border-bottom">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input type="search" id="aclQ" class="form-control" placeholder="Search subject or code..." autocomplete="off">
        </div>
      </div>
      <form method="post">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="subjects"><input type="hidden" name="class_id" value="<?php echo (int)$subjCls['id']; ?>">
        <div class="acl-subj-list" id="aclList">
          <?php foreach ($subjects as $s): $sid = (int)$s['id']; $on = isset($cur[$sid]); ?>
            <div class="acl-subj<?php echo $on ? ' is-on' : ''; ?>" data-search="<?php echo h(strtolower($s['name'] . ' ' . $s['code'] . ' ' . ($s['abbr'] ?? ''))); ?>">
              <input type="checkbox" class="form-check-input acl-taught" id="acl-sub-<?php echo $sid; ?>" name="sub[]" value="<?php echo $sid; ?>" <?php echo $on ? 'checked' : ''; ?>>
              <label class="nm" for="acl-sub-<?php echo $sid; ?>"><?php echo h($s['name']); ?><small><?php echo h($s['code']); ?><?php echo !empty($s['is_subsidiary']) ? ' · Subsidiary' : ''; ?> · <?php echo (int)$s['n_enrolled']; ?> student(s)</small></label>
              <span class="form-check">
                <input type="checkbox" class="form-check-input" id="acl-comp-<?php echo $sid; ?>" name="comp[<?php echo $sid; ?>]" value="1" data-taught="<?php echo $sid; ?>" <?php echo !empty($cur[$sid]) ? 'checked' : ''; ?> <?php echo $on ? '' : 'disabled'; ?>>
                <label class="form-check-label" for="acl-comp-<?php echo $sid; ?>">Compulsory</label>
              </span>
            </div>
          <?php endforeach; ?>
          <?php if (!$subjects): ?><div class="text-center py-4 text-muted small"><i class="bi bi-journal-x display-6 d-block mb-2"></i>No subjects available. Add subjects first in the Subjects page.</div><?php endif; ?>
        </div>
        <div class="p-2 border-top d-flex align-items-center gap-2 flex-wrap">
          <span class="small text-muted me-auto"><b id="aclCount"><?php echo count($cur); ?></b> of <?php echo count($subjects); ?> selected &middot; <?php echo h($lvlLabel[$curLevel]); ?></span>
          <a class="btn btn-outline-secondary btn-sm" href="<?php echo h(url('admin/classes.php')); ?>">Cancel</a>
          <button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Save subjects</button>
        </div>
      </form>
    </div>
  <?php else: ?>
    <?php $isEdit = (bool)$edit; $lv = $edit['level'] ?? 'O'; ?>
    <div class="card acl-panel">
      <div class="acl-panel-h">
        <i class="bi bi-<?php echo $isEdit ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $isEdit ? 'Edit class' : 'New class'; ?>
        <?php if ($isEdit): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-auto">Editing</span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($isEdit): ?><div class="alert alert-warning py-2 small"><i class="bi bi-info-circle me-1"></i>You are editing <b><?php echo h($edit['name']); ?></b>. Changes apply to every school using this class.</div><?php endif; ?>
        <form method="post">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
          <div class="mb-3">
            <label class="form-label" for="aclName">Class name <span class="text-danger">*</span></label>
            <input id="aclName" name="name" class="form-control" required maxlength="60" value="<?php echo h($edit['name'] ?? ''); ?>" placeholder="e.g. Form IV" autocomplete="off">
            <div class="form-text">Use the name students and teachers know, e.g. Form I, Form II.</div>
          </div>
          <div class="mb-3">
            <label class="form-label d-block">Level</label>
            <div class="btn-group w-100" role="group" aria-label="Level">
              <input type="radio" class="btn-check" name="level" id="aclLvlO" value="O" <?php echo $lv !== 'A' ? 'checked' : ''; ?>>
              <label class="btn btn-outline-primary btn-sm" for="aclLvlO"><i class="bi bi-book me-1"></i>O-Level</label>
              <input type="radio" class="btn-check" name="level" id="aclLvlA" value="A" <?php echo $lv === 'A' ? 'checked' : ''; ?>>
              <label class="btn btn-outline-primary btn-sm" for="aclLvlA"><i class="bi bi-mortarboard me-1"></i>A-Level</label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="aclOrder">Sort order</label>
            <input id="aclOrder" type="number" name="sort_order" class="form-control" min="0" max="999" value="<?php echo $isEdit ? (int)$edit['sort_order'] : $nextOrder; ?>">
            <div class="form-text">Lower numbers appear first in lists.</div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary flex-grow-1"><i class="bi bi-check2-circle me-1"></i><?php echo $isEdit ? 'Save changes' : 'Save class'; ?></button>
            <?php if ($isEdit): ?><a class="btn btn-outline-secondary" href="<?php echo h(url('admin/classes.php')); ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
          </div>
        </form>
        <div class="alert alert-light border small mt-3 mb-0"><i class="bi bi-lightbulb text-primary me-1"></i>After saving, use <b>Subjects</b> in the list to register the subjects studied in this class.</div>
      </div>
    </div>
  <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var list = document.getElementById('aclList');
  if (!list) return;
  var q = document.getElementById('aclQ');
  var labels = Array.prototype.slice.call(list.querySelectorAll('.acl-subj'));
  var count = document.getElementById('aclCount');

  function refresh(row) {
    var taught = row.querySelector('.acl-taught');
    var comp = row.querySelector('input[data-taught].form-check-input:not(.acl-taught)');
    if (!taught || !comp) return;
    comp.disabled = !taught.checked;
    if (!taught.checked) comp.checked = false;
    row.classList.toggle('is-on', taught.checked);
    var on = list.querySelectorAll('.acl-taught:checked').length;
    if (count) count.textContent = on;
  }

  list.addEventListener('change', function (e) {
    var row = e.target.closest('.acl-subj');
    if (row) refresh(row);
  });

  labels.forEach(function (row) {
    var taught = row.querySelector('.acl-taught');
    var comp = row.querySelector('input[data-taught].form-check-input:not(.acl-taught)');
    if (comp) comp.disabled = !taught.checked;
  });

  if (q) q.addEventListener('input', function () {
    var term = q.value.trim().toLowerCase();
    labels.forEach(function (row) {
      var hit = term === '' || (row.getAttribute('data-search') || '').indexOf(term) > -1;
      row.style.display = hit ? '' : 'none';
    });
  });
})();
</script>

<?php layout_end();
