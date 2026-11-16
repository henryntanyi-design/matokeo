<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$rows = q_all("SELECT e.*, y.name AS year_name, sc.name AS owner_name,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') FROM exam_classes ec JOIN classes c ON c.id = ec.class_id WHERE ec.exam_id = e.id) AS classes,
    (SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = e.id) AS n_sch, (SELECT COUNT(*) FROM scores s WHERE s.exam_id = e.id) AS n_scores
    FROM exams e LEFT JOIN academic_years y ON y.id = e.academic_year_id LEFT JOIN schools sc ON sc.id = e.owner_school_id ORDER BY e.id DESC");
$nOpen = 0; $nDistrict = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'open') $nOpen++;
    if (!$r['owner_school_id']) $nDistrict++;
}
layout_start('District Exams', 'exams'); ?>
<style>
.min-w-0{min-width:0}
.ex-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.ex-title{font-size:1.35rem;font-weight:800;color:#0b3d91;letter-spacing:-.01em;margin:0}
.ex-sub{font-size:.82rem;color:#8a99b0;margin:2px 0 0}
.ex-stats{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.ex-stat{flex:1 1 150px;display:flex;align-items:center;gap:11px;background:#fff;border:1px solid #e7ecf7;border-radius:14px;padding:12px 14px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.ex-stat .ic{flex:0 0 40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1rem}
.ex-blue{background:linear-gradient(135deg,#0b3d91,#2c6ed5)}
.ex-green{background:linear-gradient(135deg,#0e7a55,#19b37d)}
.ex-grey{background:linear-gradient(135deg,#5b6b85,#8a99b0)}
.ex-indigo{background:linear-gradient(135deg,#3b3f9e,#6a5cf0)}
.ex-stat .v{font-size:1.25rem;font-weight:800;line-height:1.1;color:#16203a}
.ex-stat .k{font-size:.7rem;font-weight:700;color:#5b6b85;text-transform:uppercase;letter-spacing:.04em}
.ex-card-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:.85rem 1.05rem;border-bottom:1px solid #eef2fa}
.ex-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.ex-card-h .t i{color:#2c6ed5}
.ex-filter{display:flex;gap:8px;align-items:center;padding:10px 12px;background:#fbfcff;border-bottom:1px solid #f0f3fa;flex-wrap:wrap}
.ex-filter .input-group{flex:1 1 240px;min-width:180px}
.ex-filter .input-group-text{background:#fff;border-color:#e2e9f6;border-right:0;color:#8a99b0;border-radius:10px 0 0 10px}
.ex-filter .form-control{border-color:#e2e9f6;border-left:0;border-radius:0 10px 10px 0;font-size:.82rem;background:#fff}
.ex-filter .form-select{flex:0 0 150px;border-color:#e2e9f6;border-radius:10px;font-size:.82rem;background-color:#fff}
.ex-filter .form-control:focus,.ex-filter .form-select:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12)}
.ex-filter .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:#2c6ed5}
.ex-name{font-weight:700;color:#16203a;line-height:1.3}
.ex-type{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#8a99b0}
.ex-pill{display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;border-radius:999px;padding:3px 9px;background:#f2f5fc;color:#4a5b78;border:1px solid #e5ebf7}
.ex-pill i{font-size:.72rem;color:#8a99b0}
.ex-owner{font-size:.75rem;font-weight:700;color:#4a5b78}
.ex-card{border:1px solid #e7ecf7;border-radius:15px;overflow:hidden;background:#fff;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.ex-card .table td{vertical-align:middle}
</style>

<div class="ex-head">
  <div>
    <h1 class="ex-title">District Exams</h1>
    <p class="ex-sub">District exams involve many schools. Single-school exams created by a school also appear here.</p>
  </div>
  <a class="btn btn-primary" href="exam_form.php"><i class="bi bi-plus-lg me-1"></i>New district exam</a>
</div>

<div class="ex-stats">
  <div class="ex-stat"><span class="ic ex-blue"><i class="bi bi-journals"></i></span><span><span class="d-block v"><?php echo count($rows); ?></span><span class="d-block k">Total exams</span></span></div>
  <div class="ex-stat"><span class="ic ex-green"><i class="bi bi-unlock"></i></span><span><span class="d-block v"><?php echo $nOpen; ?></span><span class="d-block k">Open</span></span></div>
  <div class="ex-stat"><span class="ic ex-grey"><i class="bi bi-lock"></i></span><span><span class="d-block v"><?php echo count($rows) - $nOpen; ?></span><span class="d-block k">Closed</span></span></div>
  <div class="ex-stat"><span class="ic ex-indigo"><i class="bi bi-building"></i></span><span><span class="d-block v"><?php echo $nDistrict; ?></span><span class="d-block k">District-owned</span></span></div>
</div>

<div class="ex-card">
  <div class="ex-card-h">
    <span class="t"><i class="bi bi-table"></i> All exams</span>
    <span class="ex-pill"><i class="bi bi-hash"></i><span id="exCount"><?php echo count($rows); ?></span></span>
  </div>
  <div class="ex-filter">
    <div class="input-group input-group-sm">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" id="exQ" class="form-control" placeholder="Search exam, owner or year..." autocomplete="off">
    </div>
    <select id="exSt" class="form-select form-select-sm" aria-label="Filter by status">
      <option value="">All statuses</option>
      <option value="open">Open only</option>
      <option value="closed">Closed only</option>
    </select>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th class="ps-3">Exam</th><th>Owner</th><th>Year</th><th>Classes</th><th>Schools</th><th>Scores</th><th>Status</th><th class="text-end pe-3">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $e): ?>
        <tr class="ex-row" data-search="<?php echo h(strtolower($e['name'] . ' ' . (string)$e['owner_name'] . ' ' . (string)$e['year_name'])); ?>" data-status="<?php echo h($e['status']); ?>">
          <td class="ps-3">
            <div class="ex-name"><?php echo h($e['name']); ?></div>
            <div class="ex-type"><?php echo h($e['exam_type']); ?></div>
          </td>
          <td><?php echo $e['owner_school_id'] ? '<span class="ex-owner"><i class="bi bi-building me-1"></i>' . h($e['owner_name']) . '</span>' : '<span class="badge bg-primary-subtle text-primary-emphasis"><i class="bi bi-geo-alt me-1"></i>District</span>'; ?></td>
          <td class="small text-muted"><?php echo h($e['year_name'] ?: '-'); ?></td>
          <td class="small"><span class="d-inline-block text-truncate" style="max-width:190px" title="<?php echo h($e['classes']); ?>"><?php echo h($e['classes'] ?: '-'); ?></span></td>
          <td><span class="ex-pill"><i class="bi bi-buildings"></i><?php echo (int)$e['n_sch']; ?></span></td>
          <td><span class="ex-pill"><i class="bi bi-clipboard-check"></i><?php echo (int)$e['n_scores']; ?></span></td>
          <td><span class="badge <?php echo $e['status'] === 'open' ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'; ?>"><?php echo $e['status'] === 'open' ? 'Open' : 'Closed'; ?></span></td>
          <td class="text-end pe-3"><a class="btn btn-outline-primary btn-sm" href="exam_view.php?id=<?php echo (int)$e['id']; ?>"><i class="bi bi-sliders me-1"></i>Manage</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox d-block fs-3 mb-2"></i>No exams yet. Create the first district exam.</td></tr><?php endif; ?>
      <tr id="exNoMatch" style="display:none"><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No exams match your filter.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var q = document.getElementById('exQ'), st = document.getElementById('exSt'),
      rows = document.querySelectorAll('.ex-row'), empty = document.getElementById('exNoMatch'), cnt = document.getElementById('exCount');
  if (!q) return;
  function apply() {
    var term = q.value.trim().toLowerCase(), status = st.value, shown = 0;
    rows.forEach(function (r) {
      var ok = (!status || r.getAttribute('data-status') === status) && (term === '' || r.getAttribute('data-search').indexOf(term) > -1);
      r.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    empty.style.display = shown ? 'none' : '';
    cnt.textContent = shown;
  }
  q.addEventListener('input', apply);
  st.addEventListener('change', apply);
})();
</script>
<?php layout_end();
