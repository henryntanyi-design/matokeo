<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$class = q_one('SELECT * FROM classes WHERE id = ?', [$class_id]);
if (!$class) redirect('super/classes.php');
if (is_post()) {
    csrf_check();
    $sel = array_map('intval', $_POST['sub'] ?? []); $comp = $_POST['comp'] ?? [];
    try {
        db()->beginTransaction();
        q('DELETE FROM class_subjects WHERE class_id = ?', [$class_id]);
        foreach ($sel as $sid) q('INSERT INTO class_subjects (class_id, subject_id, is_compulsory) VALUES (?, ?, ?)', [$class_id, $sid, isset($comp[$sid]) ? 1 : 0]);
        db()->commit();
        flash('success', 'Subjects for ' . h($class['name']) . ' saved (' . count($sel) . ' selected).');
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    redirect('super/class_subjects.php?class_id=' . $class_id);
}
$cur = [];
foreach (q_all('SELECT subject_id, is_compulsory FROM class_subjects WHERE class_id = ?', [$class_id]) as $r) $cur[(int)$r['subject_id']] = (int)$r['is_compulsory'];
$subs = q_all('SELECT s.*, (SELECT COUNT(DISTINCT u.school_id) FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
                      WHERE ta.subject_id = s.id AND u.school_id IS NOT NULL) AS n_schools
               FROM subjects s ORDER BY s.is_subsidiary, s.name');
$nTotal = count($subs); $nSelected = count($cur); $nCompulsory = count(array_filter($cur));
$classLevel = ($class['level'] ?? 'O') === 'A' ? 'A' : 'O';
layout_start('Subjects for ' . $class['name'], 'classes'); ?>

<style>
.cs-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.cs-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.cs-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.cs-hero .inner{position:relative;z-index:2;min-width:0}
.cs-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.cs-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 6px;letter-spacing:-.01em;color:#fff;word-break:break-word}
.cs-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:640px;line-height:1.45}
.cs-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.cs-hero .actions .btn{font-weight:700;border-radius:10px;padding:.5rem .95rem}
.cs-hero .btn-light{color:#0b3d91;border:0;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.cs-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:3px 9px;border:1px solid;white-space:nowrap}
.cs-pill.o{background:rgba(255,255,255,.18);color:#fff;border-color:rgba(255,255,255,.5)}
.cs-pill.a{background:rgba(255,255,255,.18);color:#fff;border-color:rgba(255,255,255,.5)}
.cs-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.cs-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.cs-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.cs-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.cs-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.cs-stat.o .ico{background:#fdf1dc;color:#b06a00}
.cs-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.cs-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.cs-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06)}
.cs-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid #eef2fa;border-radius:16px 16px 0 0;background:#fff}
.cs-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.cs-card-h .t i{color:#2c6ed5}
.cs-tools{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.cs-search{position:relative}
.cs-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#8a99b0;font-size:.85rem}
.cs-search input{border:1px solid #dfe7f5;border-radius:9px;padding:.4rem .7rem .4rem 30px;font-size:.83rem;min-width:210px;background:#fff}
.cs-search input:focus{outline:0;border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.cs-tools .btn{--bs-btn-padding-y:.32rem;--bs-btn-padding-x:.6rem;--bs-btn-font-size:.76rem;border-radius:8px;font-weight:700}
.cs-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.cs-table tbody td{padding-top:.62rem;padding-bottom:.62rem;border-color:#f0f4fb;vertical-align:middle}
.cs-table tbody tr:hover{background:#fafcff}
.cs-table tbody tr.cs-on td:first-child{box-shadow:inset 3px 0 0 #0b8a3e}
.cs-name{font-weight:700;color:#16203a;line-height:1.25}
.cs-sub{display:inline-flex;align-items:center;gap:4px;font-size:.65rem;font-weight:800;border-radius:999px;padding:2px 7px;background:#fff8e6;border:1px solid #ffe1a3;color:#7a5300;margin-left:6px;vertical-align:middle}
.cs-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem;color:#5b7bb5;background:#f4f7fe;border:1px solid #e4ebf9;border-radius:6px;padding:1px 6px}
.cs-lvl{font-size:.76rem;color:#33415c;font-weight:600}
.cs-table .form-check-input{cursor:pointer;width:1.05rem;height:1.05rem;margin:0}
.cs-table .form-check-input:disabled{cursor:not-allowed;opacity:.35}
.cs-eye{display:inline-flex;align-items:center;gap:5px;border:1px solid #dfe7f5;background:#fff;border-radius:8px;padding:.2rem .45rem;font-size:.75rem;color:#2c6ed5;text-decoration:none;font-weight:700}
.cs-eye:hover{background:#eef4ff;border-color:#a9c4f2;color:#0b3d91}
.cs-eye .badge{background:#f4f7fe!important;border:1px solid #e4ebf9!important;color:#5b7bb5!important}
.cs-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-top:1px solid #eef2fa;background:#fff;position:sticky;bottom:0;z-index:3;border-radius:0 0 16px 16px;box-shadow:0 -4px 14px rgba(11,61,145,.06)}
.cs-foot .note{font-size:.75rem;color:#8a99b0}
.cs-foot .note b{color:#33415c}
.cs-foot .btns{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cs-foot .btn{font-weight:700;border-radius:9px;padding:.5rem .95rem}
.cs-empty{text-align:center;padding:2.6rem 1rem;color:#8a99b0}
.cs-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.cs-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:10px 12px;font-size:.78rem;color:#3f5a80;line-height:1.45;margin-bottom:14px}
.cs-tip i{color:#2c6ed5;font-size:.95rem;flex:0 0 auto;margin-top:1px}
@media(max-width:575px){.cs-hero{padding:18px}.cs-hero h1{font-size:1.32rem}.cs-search input{min-width:0;width:100%}.cs-tools{width:100%}.cs-search{flex:1 1 100%}}
@media print{.cs-hero{background:#fff!important;color:#000!important;box-shadow:none}.cs-hero .actions,.cs-foot,.cs-tools{display:none!important}}
</style>

<div class="cs-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-diagram-3"></i> Academic structure</div>
    <h1>Subjects for <?php echo h($class['name']); ?></h1>
    <p>Choose the subjects studied in this class and mark which ones are compulsory.
      <span class="cs-pill <?php echo $classLevel === 'A' ? 'a' : 'o'; ?>"><i class="bi bi-<?php echo $classLevel === 'A' ? 'mortarboard' : 'book'; ?>"></i><?php echo $classLevel === 'A' ? 'A-Level (ACSEE)' : 'O-Level (CSEE)'; ?></span>
    </p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="classes.php"><i class="bi bi-arrow-left me-1"></i>Back to classes</a>
  </div>
</div>

<div class="cs-stats">
  <div class="cs-stat"><span class="ico"><i class="bi bi-journal-bookmark"></i></span><div><div class="v" id="st-selected"><?php echo $nSelected; ?></div><div class="l">Selected subjects</div></div></div>
  <div class="cs-stat g"><span class="ico"><i class="bi bi-check2-circle"></i></span><div><div class="v" id="st-comp"><?php echo $nCompulsory; ?></div><div class="l">Compulsory</div></div></div>
  <div class="cs-stat a"><span class="ico"><i class="bi bi-collection"></i></span><div><div class="v"><?php echo $nTotal; ?></div><div class="l">Subjects available</div></div></div>
  <div class="cs-stat o"><span class="ico"><i class="bi bi-mortarboard"></i></span><div><div class="v"><?php echo $classLevel === 'A' ? 'A' : 'O'; ?></div><div class="l">Class level</div></div></div>
</div>

<div class="cs-tip">
  <i class="bi bi-info-circle"></i>
  <span>Every selected subject is studied by all students in this class. The <b>Compulsory</b> tick is a reporting category only. Use the <i class="bi bi-eye"></i> button to see which schools offer a subject.</span>
</div>

<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
<div class="cs-card">
  <div class="cs-card-h">
    <span class="t"><i class="bi bi-list-check"></i> Subject list</span>
    <div class="cs-tools">
      <div class="cs-search">
        <i class="bi bi-search"></i>
        <input type="search" id="cs-search" placeholder="Search subject or code" aria-label="Search subjects">
      </div>
      <button type="button" class="btn btn-outline-primary" id="cs-all"><i class="bi bi-check-all me-1"></i>Select all</button>
      <button type="button" class="btn btn-outline-secondary" id="cs-none"><i class="bi bi-x-lg me-1"></i>Clear</button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 cs-table">
      <thead><tr>
        <th class="ps-3" style="width:52px">S/N</th>
        <th>Subject</th>
        <th class="d-none d-md-table-cell">Code</th>
        <th class="d-none d-lg-table-cell">NECTA abbr.</th>
        <th class="d-none d-md-table-cell">Level</th>
        <th class="text-center">Taught</th>
        <th class="text-center">Compulsory</th>
        <th class="text-end pe-3">Schools</th>
      </tr></thead>
      <tbody>
      <?php $n = 0; foreach ($subs as $s): $id = (int)$s['id']; $n++; $checked = isset($cur[$id]); $isComp = !empty($cur[$id]); ?>
        <tr class="<?php echo $checked ? 'cs-on' : ''; ?>" data-search="<?php echo h(strtolower($s['name'] . ' ' . $s['code'] . ' ' . ($s['abbr'] ?? ''))); ?>">
          <td class="ps-3 text-muted"><?php echo $n; ?></td>
          <td>
            <span class="cs-name"><?php echo h($s['name']); ?></span>
            <?php if (!empty($s['is_subsidiary'])): ?><span class="cs-sub"><i class="bi bi-star-fill"></i>Subsidiary</span><?php endif; ?>
          </td>
          <td class="d-none d-md-table-cell"><span class="cs-code"><?php echo h($s['code']); ?></span></td>
          <td class="d-none d-lg-table-cell"><?php echo h($s['abbr'] !== '' ? $s['abbr'] : '—'); ?></td>
          <td class="d-none d-md-table-cell"><span class="cs-lvl"><?php echo h(subject_level_label($s['level'])); ?></span></td>
          <td class="text-center">
            <input type="checkbox" class="form-check-input cs-sub-check" name="sub[]" value="<?php echo $id; ?>" id="sub-<?php echo $id; ?>" aria-label="Taught" <?php echo $checked ? 'checked' : ''; ?>>
          </td>
          <td class="text-center">
            <input type="checkbox" class="form-check-input" name="comp[<?php echo $id; ?>]" value="1" data-for="<?php echo $id; ?>" aria-label="Compulsory" <?php echo $isComp ? 'checked' : ''; ?> <?php echo $checked ? '' : 'disabled'; ?>>
          </td>
          <td class="text-end pe-3">
            <a class="cs-eye" href="subject_schools.php?subject_id=<?php echo $id; ?>&class_id=<?php echo $class_id; ?>" title="Schools offering this subject">
              <i class="bi bi-eye"></i><span class="badge"><?php echo (int)$s['n_schools']; ?></span>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$subs): ?><tr><td colspan="8"><div class="cs-empty"><i class="bi bi-journal-x"></i><div class="fw-bold text-dark mb-1">No subjects yet.</div><div class="small">Add subjects in the Subjects page first.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="cs-foot">
    <div class="note"><b id="cs-count"><?php echo $nSelected; ?></b> of <?php echo $nTotal; ?> subjects selected</div>
    <div class="btns">
      <a class="btn btn-link text-decoration-none" href="classes.php">Cancel</a>
      <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save changes</button>
    </div>
  </div>
</div></form>

<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.cs-table tbody tr[data-search]'));
  var checks = Array.prototype.slice.call(document.querySelectorAll('.cs-sub-check'));
  var search = document.getElementById('cs-search');
  var stSel = document.getElementById('st-selected');
  var stComp = document.getElementById('st-comp');
  var cnt = document.getElementById('cs-count');

  function visibleRows() {
    return rows.filter(function (r) { return r.style.display !== 'none'; });
  }

  function refresh() {
    var sel = 0, comp = 0;
    checks.forEach(function (c) {
      var row = c.closest('tr');
      var compBox = row ? row.querySelector('input[data-for]') : null;
      if (c.checked) {
        sel++;
        if (compBox) { compBox.disabled = false; if (compBox.checked) comp++; }
        if (row) row.classList.add('cs-on');
      } else {
        if (compBox) { compBox.disabled = true; compBox.checked = false; }
        if (row) row.classList.remove('cs-on');
      }
    });
    stSel.textContent = sel;
    stComp.textContent = comp;
    cnt.textContent = sel;
  }

  checks.forEach(function (c) { c.addEventListener('change', refresh); });

  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      rows.forEach(function (r) {
        r.style.display = (!q || r.getAttribute('data-search').indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  var all = document.getElementById('cs-all');
  if (all) all.addEventListener('click', function () {
    visibleRows().forEach(function (r) { var c = r.querySelector('.cs-sub-check'); if (c) c.checked = true; });
    refresh();
  });

  var none = document.getElementById('cs-none');
  if (none) none.addEventListener('click', function () {
    visibleRows().forEach(function (r) { var c = r.querySelector('.cs-sub-check'); if (c) c.checked = false; });
    refresh();
  });
})();
</script>
<?php layout_end(); ?>
