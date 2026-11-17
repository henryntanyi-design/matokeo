<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'save') {
            $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $lvl = (($_POST['level'] ?? 'O') === 'A') ? 'A' : 'O'; $ord = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') flash('danger', 'Class name is required.');
            else {
                if ($id) q('UPDATE classes SET name = ?, level = ?, sort_order = ? WHERE id = ?', [$name, $lvl, $ord, $id]);
                else q('INSERT INTO classes (name, level, sort_order) VALUES (?, ?, ?)', [$name, $lvl, $ord]);
                flash('success', 'Class saved.');
            }
        } elseif ($a === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ((int)q_val('SELECT COUNT(*) FROM students WHERE class_id = ?', [$id]) > 0) flash('danger', 'This class has students; it cannot be deleted. Move them first.');
            else { q('DELETE FROM classes WHERE id = ?', [$id]); flash('success', 'Class deleted.'); }
        }
    } catch (PDOException $e) { flash('danger', $e->getCode() == 23000 ? 'A class with that name already exists.' : h($e->getMessage())); }
    redirect('super/classes.php');
}
$edit = isset($_GET['edit']) ? q_one('SELECT * FROM classes WHERE id = ?', [(int)$_GET['edit']]) : null;
$rows = q_all("SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') AS n_students, (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS n_sub FROM classes c ORDER BY c.sort_order, c.id");
$nTotal = count($rows); $nO = 0; $nA = 0; $nStudents = 0; $nSubjects = 0;
foreach ($rows as $c) { if (($c['level'] ?? 'O') === 'A') $nA++; else $nO++; $nStudents += (int)$c['n_students']; $nSubjects += (int)$c['n_sub']; }
$nextOrder = (int)q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM classes');
layout_start('Classes', 'classes'); ?>

<style>
.cls-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.cls-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.cls-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.cls-hero .inner{position:relative;z-index:2;min-width:0}
.cls-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.cls-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.cls-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:640px;line-height:1.45}
.cls-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.cls-hero .actions .btn{font-weight:700;border-radius:10px;padding:.5rem .95rem}
.cls-hero .btn-light{color:#0b3d91;border:0;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.cls-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.cls-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.cls-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.cls-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.cls-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.cls-stat.o .ico{background:#fdf1dc;color:#b06a00}
.cls-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.cls-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.cls-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.cls-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;border-bottom:1px solid #eef2fa}
.cls-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.cls-card-h .t i{color:#2c6ed5}
.cls-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.cls-table tbody td{padding-top:.68rem;padding-bottom:.68rem;border-color:#f0f4fb;vertical-align:middle}
.cls-table tbody tr:hover{background:#fafcff}
.cls-table tr.cls-editing{background:#fffdf4;box-shadow:inset 3px 0 0 #d39b1b}
.cls-table tr.cls-editing:hover{background:#fff9e8}
.cls-mark{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:1rem;color:#fff;flex:0 0 auto;background:linear-gradient(135deg,#0b8a3e,#20b15b);box-shadow:0 3px 8px rgba(11,138,62,.22)}
.cls-mark.a{background:linear-gradient(135deg,#5b3fb5,#8a6fe0);box-shadow:0 3px 8px rgba(91,63,181,.22)}
@media (max-width:767.98px){.cls-table .cls-mark{display:none!important}}
.cls-name{font-weight:700;color:#16203a;line-height:1.25}
.cls-meta{font-size:.71rem;color:#8a99b0;display:flex;align-items:center;gap:4px;margin-top:2px}
.cls-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:3px 9px;border:1px solid;white-space:nowrap}
.cls-pill.o{background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.cls-pill.a{background:#f0ecfd;color:#5b3fb5;border-color:#ddd3f8}
.cls-count{display:inline-flex;align-items:center;gap:5px;font-size:.8rem;color:#33415c;font-weight:600}
.cls-count i{color:#8a99b0}
.cls-actions{display:inline-flex;gap:5px;align-items:center}
.cls-actions .btn{--bs-btn-padding-y:.26rem;--bs-btn-padding-x:.46rem;--bs-btn-font-size:.75rem;border-radius:8px}
.cls-empty{text-align:center;padding:2.6rem 1rem;color:#8a99b0}
.cls-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.cls-form-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden;scroll-margin-top:16px}
.cls-form-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;background:linear-gradient(120deg,#0f4aa8,#2c6ed5);color:#fff}
.cls-form-h .t{font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.cls-form-h .t i{color:#cfe0ff}
.cls-form-h .badge{background:rgba(255,255,255,.18)!important;color:#fff!important;border:1px solid rgba(255,255,255,.45)!important}
.cls-form-body{padding:16px}
.cls-form-card .form-label{font-size:.76rem;font-weight:800;color:#33415c;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.28rem}
.cls-form-card .form-control,.cls-form-card .form-select{border-color:#dfe7f5;font-size:.86rem;padding:.48rem .7rem;border-radius:9px}
.cls-form-card .form-control:focus,.cls-form-card .form-select:focus{border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.cls-form-card .form-text{font-size:.71rem;color:#8a99b0}
.cls-form-card .input-group-text{background:#f4f7fe;border-color:#dfe7f5;color:#5b7bb5;font-size:.88rem;border-radius:9px 0 0 9px}
.cls-form-card .input-group .form-control{border-radius:0 9px 9px 0}
.cls-level-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:.15rem}
.cls-level-opt{position:relative}
.cls-level-opt input{position:absolute;opacity:0;width:1px;height:1px}
.cls-level-opt label{display:block;height:100%;border:1.5px solid #dfe7f5;border-radius:11px;padding:9px 10px;cursor:pointer;background:#fff;transition:border-color .15s,background .15s,box-shadow .15s}
.cls-level-opt label .lv-t{font-size:.81rem;font-weight:800;color:#33415c;display:flex;align-items:center;gap:6px}
.cls-level-opt label .lv-t i{color:#8a99b0;font-size:.95rem}
.cls-level-opt label .lv-s{display:block;font-size:.65rem;color:#8a99b0;margin-top:2px;line-height:1.25}
.cls-level-opt label:hover{border-color:#a9c4f2;background:#fafcff}
.cls-level-opt input:checked + label{border-color:#2c6ed5;background:#eef4ff;box-shadow:0 0 0 3px rgba(44,110,213,.12)}
.cls-level-opt input:checked + label .lv-t{color:#0b3d91}
.cls-level-opt input:checked + label .lv-t i{color:#2c6ed5}
.cls-level-opt input:focus-visible + label{outline:2px solid #7aa5ea;outline-offset:2px}
.cls-preview{border:1px dashed #cfdcf0;background:linear-gradient(180deg,#fbfdff,#f3f8ff);border-radius:12px;padding:10px 12px;margin:0 0 14px}
.cls-preview .pv-l{font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;font-weight:800;color:#8a99b0;margin-bottom:7px;display:flex;align-items:center;gap:5px}
.cls-preview .pv-l i{color:#2c6ed5}
.cls-preview .pv-row{display:flex;align-items:center;gap:9px;min-height:38px}
.cls-preview .pv-name{font-weight:700;color:#16203a;font-size:.9rem;line-height:1.2;word-break:break-word}
.cls-preview .cls-mark{width:34px;height:34px;font-size:.9rem}
.cls-preview .pv-hint{font-size:.64rem;color:#8a99b0;margin-top:6px}
.cls-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:9px 10px;font-size:.72rem;color:#3f5a80;line-height:1.4;margin-top:14px}
.cls-tip i{color:#2c6ed5;font-size:.9rem;flex:0 0 auto;margin-top:1px}
.cls-form-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cls-form-actions .btn{font-weight:700;border-radius:9px;padding:.5rem .9rem}
.cls-form-actions .btn-primary{flex:1 1 auto}
.cls-edit-note{display:flex;align-items:center;gap:8px;background:#fff8e6;border:1px solid #ffe1a3;color:#7a5300;border-radius:10px;padding:8px 10px;font-size:.78rem;margin-bottom:12px}
@media(min-width:992px){.cls-form-card{position:sticky;top:16px}}
@media(max-width:575px){.cls-hero{padding:18px}.cls-hero h1{font-size:1.32rem}}
@media print{.cls-hero{background:#fff!important;color:#000!important;box-shadow:none}.cls-hero .actions,.cls-actions,.cls-form-card{display:none!important}}
</style>

<div class="cls-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-diagram-3"></i> Academic structure</div>
    <h1>Classes</h1>
    <p>Manage O-Level and A-Level classes, see how many students each class has, and link subjects to every class.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light btn-sm" href="classes.php#class-form"><i class="bi bi-plus-lg me-1"></i> New class</a>
  </div>
</div>

<div class="cls-stats">
  <div class="cls-stat"><span class="ico"><i class="bi bi-collection"></i></span><div><div class="v"><?php echo $nTotal; ?></div><div class="l">Total classes</div></div></div>
  <div class="cls-stat g"><span class="ico"><i class="bi bi-book"></i></span><div><div class="v"><?php echo $nO; ?></div><div class="l">O-Level</div></div></div>
  <div class="cls-stat a"><span class="ico"><i class="bi bi-mortarboard"></i></span><div><div class="v"><?php echo $nA; ?></div><div class="l">A-Level</div></div></div>
  <div class="cls-stat o"><span class="ico"><i class="bi bi-people"></i></span><div><div class="v"><?php echo $nStudents; ?></div><div class="l">Active students</div></div></div>
  <div class="cls-stat"><span class="ico"><i class="bi bi-link-45deg"></i></span><div><div class="v"><?php echo $nSubjects; ?></div><div class="l">Subject links</div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="cls-card">
      <div class="cls-card-h">
        <span class="t"><i class="bi bi-list-ul"></i> All classes</span>
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo $nTotal; ?> class<?php echo $nTotal === 1 ? '' : 'es'; ?></span>
      </div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0 cls-table">
        <thead><tr><th class="ps-3">Class</th><th class="d-none d-md-table-cell">Level</th><th><i class="bi bi-people me-1"></i>Students</th><th class="d-none d-md-table-cell"><i class="bi bi-book me-1"></i>Subjects</th><th class="text-end pe-3">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
          <tr<?php echo $edit && (int)$edit['id'] === (int)$c['id'] ? ' class="cls-editing"' : ''; ?>>
            <td class="ps-3">
              <div class="d-flex align-items-center gap-2">
                <span class="cls-mark<?php echo $c['level'] === 'A' ? ' a' : ''; ?>"><i class="bi bi-<?php echo $c['level'] === 'A' ? 'mortarboard' : 'book'; ?>"></i></span>
                <div style="min-width:0">
                  <div class="cls-name"><?php echo h($c['name']); ?></div>
                  <div class="cls-meta"><i class="bi bi-sort-numeric-down"></i>Sort order: <?php echo (int)$c['sort_order']; ?></div>
                </div>
              </div>
            </td>
            <td class="d-none d-md-table-cell"><span class="cls-pill <?php echo $c['level'] === 'A' ? 'a' : 'o'; ?>"><?php echo $c['level'] === 'A' ? 'A-Level (ACSEE)' : 'O-Level (CSEE)'; ?></span></td>
            <td><span class="cls-count"><i class="bi bi-people"></i><?php echo (int)$c['n_students']; ?></span></td>
            <td class="d-none d-md-table-cell"><span class="cls-count"><i class="bi bi-book"></i><?php echo (int)$c['n_sub']; ?></span></td>
            <td class="text-end pe-3 text-nowrap">
              <div class="cls-actions justify-content-end">
                <a class="btn btn-outline-primary" href="<?php echo h(url('super/class_subjects.php?class_id=' . (int)$c['id'])); ?>" title="Manage subjects for this class"><i class="bi bi-book"></i><span class="d-none d-xl-inline ms-1">Subjects</span></a>
                <a class="btn btn-outline-secondary" href="?edit=<?php echo (int)$c['id']; ?>#class-form" title="Edit class"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this class?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-outline-danger" title="Delete class"><i class="bi bi-trash3"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5"><div class="cls-empty"><i class="bi bi-collection"></i><div class="fw-bold text-dark mb-1">No classes yet.</div><div class="small">Create your first class using the form.</div></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="cls-form-card" id="class-form">
      <div class="cls-form-h">
        <span class="t"><i class="bi bi-<?php echo $edit ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $edit ? 'Edit class' : 'New class'; ?></span>
        <?php if ($edit): ?><span class="badge">Editing</span><?php endif; ?>
      </div>
      <div class="card-body cls-form-body">
        <?php if ($edit): ?><div class="cls-edit-note"><i class="bi bi-info-circle"></i><span>You are editing <b><?php echo h($edit['name']); ?></b>. Changes apply everywhere this class is used.</span></div><?php endif; ?>

        <div class="cls-preview" aria-hidden="true">
          <div class="pv-l"><i class="bi bi-eye"></i> Live preview</div>
          <div class="pv-row">
            <span class="cls-mark<?php echo ($edit['level'] ?? 'O') === 'A' ? ' a' : ''; ?>" id="pv-mark"><i class="bi bi-<?php echo ($edit['level'] ?? 'O') === 'A' ? 'mortarboard' : 'book'; ?>"></i></span>
            <div style="min-width:0">
              <div class="pv-name" id="pv-name"><?php echo h($edit['name'] ?? 'Class name'); ?></div>
              <span class="cls-pill <?php echo ($edit['level'] ?? 'O') === 'A' ? 'a' : 'o'; ?>" id="pv-pill"><?php echo ($edit['level'] ?? 'O') === 'A' ? 'A-Level (ACSEE)' : 'O-Level (CSEE)'; ?></span>
            </div>
          </div>
          <div class="pv-hint">This is how the class will look in the list.</div>
        </div>

        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
          <div class="mb-3">
            <label class="form-label" for="cls-name">Class name <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-diagram-3"></i></span>
              <input id="cls-name" name="name" class="form-control" required value="<?php echo h($edit['name'] ?? ''); ?>" placeholder="e.g. Form IV" autocomplete="off">
            </div>
            <div class="form-text">Use the name students and teachers know, e.g. Form I, Form II.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Level</label>
            <div class="cls-level-grid">
              <div class="cls-level-opt">
                <input type="radio" id="cls-level-o" name="level" value="O" <?php echo ($edit['level'] ?? 'O') === 'O' ? 'checked' : ''; ?>>
                <label for="cls-level-o"><span class="lv-t"><i class="bi bi-book"></i> O-Level</span><span class="lv-s">CSEE &middot; Form I&ndash;IV</span></label>
              </div>
              <div class="cls-level-opt">
                <input type="radio" id="cls-level-a" name="level" value="A" <?php echo ($edit['level'] ?? '') === 'A' ? 'checked' : ''; ?>>
                <label for="cls-level-a"><span class="lv-t"><i class="bi bi-mortarboard"></i> A-Level</span><span class="lv-s">ACSEE &middot; Form V&ndash;VI</span></label>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="cls-order">Sort order</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-sort-numeric-down"></i></span>
              <input id="cls-order" type="number" name="sort_order" class="form-control" min="0" max="999" value="<?php echo $edit ? (int)$edit['sort_order'] : $nextOrder; ?>">
            </div>
            <div class="form-text"><?php echo $edit ? 'Lower numbers appear first in lists.' : 'Suggested automatically &mdash; lower numbers appear first.'; ?></div>
          </div>
          <div class="cls-form-actions">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo $edit ? 'Save changes' : 'Save class'; ?></button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="classes.php"><i class="bi bi-x-lg me-1"></i>Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="cls-tip"><i class="bi bi-lightbulb"></i><span>Classes are shared by all schools. After saving, use <b>Subjects</b> in the list to link subjects to this class.</span></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var nameEl = document.getElementById('cls-name');
  if (!nameEl) return;
  var markEl = document.getElementById('pv-mark');
  var nameOut = document.getElementById('pv-name');
  var pillEl = document.getElementById('pv-pill');
  function sync() {
    var picked = document.querySelector('input[name="level"]:checked');
    var isA = picked && picked.value === 'A';
    nameOut.textContent = nameEl.value.trim() || 'Class name';
    markEl.className = 'cls-mark' + (isA ? ' a' : '');
    markEl.innerHTML = '<i class="bi bi-' + (isA ? 'mortarboard' : 'book') + '"></i>';
    pillEl.className = 'cls-pill ' + (isA ? 'a' : 'o');
    pillEl.textContent = isA ? 'A-Level (ACSEE)' : 'O-Level (CSEE)';
  }
  nameEl.addEventListener('input', sync);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="level"]'), function (r) {
    r.addEventListener('change', sync);
  });
  sync();
})();
</script>

<?php layout_end();
