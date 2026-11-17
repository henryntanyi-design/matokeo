<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$subject_id = (int)($_GET['subject_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$subject = $subject_id ? q_one('SELECT * FROM subjects WHERE id = ?', [$subject_id]) : null;
if (!$subject) { flash('danger', 'Subject not found.'); redirect($class_id ? 'super/class_subjects.php?class_id=' . $class_id : 'super/subjects.php'); }
$rows = subject_school_assignments($subject_id);
$schools = [];
foreach ($rows as $r) $schools[(int)$r['school_id']] = true;
$fmt = function ($v) { $t = $v ? strtotime((string)$v) : false; return $t ? date('d/m/Y', $t) : '—'; };
$nSchools = count($schools); $nAssignments = count($rows);
$classIds = []; $lastAt = null;
foreach ($rows as $r) {
    $classIds[(int)$r['class_id']] = true;
    if (!empty($r['assigned_at'])) { $t = strtotime((string)$r['assigned_at']); if ($lastAt === null || $t > $lastAt) $lastAt = $t; }
}
$back = $class_id ? 'class_subjects.php?class_id=' . $class_id : 'subjects.php';
layout_start('Schools offering ' . $subject['name'], 'classes'); ?>

<style>
.ss-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.ss-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.ss-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.ss-hero .inner{position:relative;z-index:2;min-width:0}
.ss-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.ss-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 6px;letter-spacing:-.01em;color:#fff;word-break:break-word}
.ss-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:660px;line-height:1.45}
.ss-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.ss-hero .actions .btn{font-weight:700;border-radius:10px;padding:.5rem .95rem}
.ss-hero .btn-light{color:#0b3d91;border:0;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.ss-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:3px 9px;border:1px solid rgba(255,255,255,.5);background:rgba(255,255,255,.18);color:#fff;white-space:nowrap}
.ss-pill.sub{background:rgba(255,214,102,.22);border-color:rgba(255,214,102,.65)}
.ss-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.ss-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.ss-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.ss-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.ss-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.ss-stat.o .ico{background:#fdf1dc;color:#b06a00}
.ss-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.ss-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.ss-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06)}
.ss-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid #eef2fa;border-radius:16px 16px 0 0;background:#fff}
.ss-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.ss-card-h .t i{color:#2c6ed5}
.ss-tools{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.ss-search{position:relative}
.ss-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#8a99b0;font-size:.85rem}
.ss-search input{border:1px solid #dfe7f5;border-radius:9px;padding:.4rem .7rem .4rem 30px;font-size:.83rem;min-width:230px;background:#fff}
.ss-search input:focus{outline:0;border-color:#7aa5ea;box-shadow:0 0 0 .2rem rgba(44,110,213,.12)}
.ss-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.ss-table tbody td{padding-top:.62rem;padding-bottom:.62rem;border-color:#f0f4fb;vertical-align:middle}
.ss-table tbody tr:hover{background:#fafcff}
.ss-school{font-weight:700;color:#16203a;line-height:1.25}
.ss-meta{font-size:.71rem;color:#8a99b0;display:flex;align-items:center;gap:4px;margin-top:2px}
.ss-teacher{font-weight:600;color:#33415c}
.ss-badge{display:inline-flex;align-items:center;gap:4px;font-size:.65rem;font-weight:800;border-radius:999px;padding:2px 7px}
.ss-badge.off{background:#f1f3f7;border:1px solid #e2e6ee;color:#6b7a93}
.ss-badge.all{background:#f4f8ff;border:1px solid #dbe8fb;color:#3f5a80}
.ss-eye{display:inline-flex;align-items:center;gap:5px;border:1px solid #dfe7f5;background:#fff;border-radius:8px;padding:.24rem .5rem;font-size:.78rem;color:#2c6ed5;text-decoration:none;font-weight:700}
.ss-eye:hover{background:#eef4ff;border-color:#a9c4f2;color:#0b3d91}
.ss-empty{text-align:center;padding:2.6rem 1rem;color:#8a99b0}
.ss-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.ss-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-top:1px solid #eef2fa;background:#fff;border-radius:0 0 16px 16px}
.ss-foot .note{font-size:.75rem;color:#8a99b0}
.ss-foot .note b{color:#33415c}
.ss-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:10px;padding:10px 12px;font-size:.78rem;color:#3f5a80;line-height:1.45;margin-bottom:14px}
.ss-tip i{color:#2c6ed5;font-size:.95rem;flex:0 0 auto;margin-top:1px}
@media(max-width:575px){.ss-hero{padding:18px}.ss-hero h1{font-size:1.32rem}.ss-search input{min-width:0;width:100%}.ss-tools{width:100%}}
@media print{.ss-hero{background:#fff!important;color:#000!important;box-shadow:none}.ss-hero .actions,.ss-tools,.ss-tip{display:none!important}}
</style>

<div class="ss-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-diagram-3"></i> Academic structure</div>
    <h1>Schools offering <?php echo h($subject['name']); ?></h1>
    <p>Schools and teachers registered for this subject, with class, stream and registration date.
      <span class="ss-pill"><i class="bi bi-upc-scan"></i><?php echo h($subject['code']); ?></span>
      <span class="ss-pill"><i class="bi bi-bookmark"></i><?php echo h(subject_level_label($subject['level'])); ?></span>
      <?php if (!empty($subject['is_subsidiary'])): ?><span class="ss-pill sub"><i class="bi bi-star-fill"></i>Subsidiary</span><?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="<?php echo h($back); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="ss-stats">
  <div class="ss-stat"><span class="ico"><i class="bi bi-buildings"></i></span><div><div class="v"><?php echo $nSchools; ?></div><div class="l">Schools</div></div></div>
  <div class="ss-stat g"><span class="ico"><i class="bi bi-person-badge"></i></span><div><div class="v"><?php echo $nAssignments; ?></div><div class="l">Teacher assignments</div></div></div>
  <div class="ss-stat a"><span class="ico"><i class="bi bi-collection"></i></span><div><div class="v"><?php echo count($classIds); ?></div><div class="l">Classes covered</div></div></div>
  <div class="ss-stat o"><span class="ico"><i class="bi bi-calendar-check"></i></span><div><div class="v" style="font-size:.95rem"><?php echo $fmt($lastAt ? date('Y-m-d', $lastAt) : null); ?></div><div class="l">Latest registration</div></div></div>
</div>

<div class="ss-tip">
  <i class="bi bi-info-circle"></i>
  <span>An assignment means a teacher teaches this subject to a class in a school. A blank stream means the whole class. Open the <i class="bi bi-eye"></i> button to view the teacher's record.</span>
</div>

<div class="ss-card">
  <div class="ss-card-h">
    <span class="t"><i class="bi bi-list-check"></i> Schools, teachers and classes</span>
    <div class="ss-tools">
      <div class="ss-search">
        <i class="bi bi-search"></i>
        <input type="search" id="ss-search" placeholder="Search school, teacher or class" aria-label="Search assignments">
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 ss-table">
      <thead><tr>
        <th class="ps-3" style="width:52px">S/N</th>
        <th>School</th>
        <th>Teacher</th>
        <th class="d-none d-md-table-cell">Class</th>
        <th class="d-none d-md-table-cell">Stream</th>
        <th class="d-none d-lg-table-cell">Registered</th>
        <th class="text-end pe-3">Action</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7"><div class="ss-empty"><i class="bi bi-buildings"></i><div class="fw-bold text-dark mb-1">No schools yet.</div><div class="small">No school has registered this subject so far.</div></div></td></tr>
      <?php else: $i = 0; foreach ($rows as $r): $i++;
            $qs = http_build_query(['teacher_id' => (int)$r['teacher_id'], 'subject_id' => $subject_id, 'class_id' => (int)$r['class_id'], 'stream' => (string)$r['stream']]); ?>
        <tr data-search="<?php echo h(strtolower($r['school_name'] . ' ' . ($r['centre_no'] ?? '') . ' ' . $r['full_name'] . ' ' . $r['username'] . ' ' . $r['class_name'] . ' ' . $r['stream'])); ?>">
          <td class="ps-3 text-muted"><?php echo $i; ?></td>
          <td>
            <div class="ss-school"><?php echo h($r['school_name']); ?></div>
            <?php if ($r['centre_no']): ?><div class="ss-meta"><i class="bi bi-hash"></i><?php echo h($r['centre_no']); ?></div><?php endif; ?>
          </td>
          <td>
            <div class="ss-teacher"><?php echo h($r['full_name']); ?><?php if (!$r['active']): ?> <span class="ss-badge off">Inactive</span><?php endif; ?></div>
            <div class="ss-meta"><i class="bi bi-person"></i><?php echo h($r['username']); ?></div>
          </td>
          <td class="d-none d-md-table-cell"><?php echo h($r['class_name']); ?></td>
          <td class="d-none d-md-table-cell">
            <?php echo $r['stream'] !== '' ? h($r['stream']) : '<span class="ss-badge all"><i class="bi bi-people"></i>Whole class</span>'; ?>
          </td>
          <td class="d-none d-lg-table-cell text-muted"><?php echo h($fmt($r['assigned_at'])); ?></td>
          <td class="text-end pe-3">
            <a class="ss-eye" href="teacher_view.php?<?php echo h($qs); ?>" title="View this teacher's record"><i class="bi bi-eye"></i></a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="ss-foot">
    <div class="note"><b id="ss-count"><?php echo $nAssignments; ?></b> assignment<?php echo $nAssignments === 1 ? '' : 's'; ?> in <?php echo $nSchools; ?> school<?php echo $nSchools === 1 ? '' : 's'; ?></div>
    <div class="note"><a class="text-decoration-none" href="<?php echo h($back); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a></div>
  </div>
</div>

<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.ss-table tbody tr[data-search]'));
  var search = document.getElementById('ss-search');
  var count = document.getElementById('ss-count');
  if (!search) return;
  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (r) {
      var match = !q || r.getAttribute('data-search').indexOf(q) !== -1;
      r.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    if (count) count.textContent = shown;
  });
})();
</script>
<?php layout_end(); ?>
