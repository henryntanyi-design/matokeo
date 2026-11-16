<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$me = require_district();
if (isset($_GET['exit'])) { leave_school(); redirect('super/dashboard.php'); }
leave_school();   // district dashboard has no active school
$cnt = [
    'Schools' => (int)q_val('SELECT COUNT(*) FROM schools WHERE active = 1'),
    'Students' => (int)q_val("SELECT COUNT(*) FROM students WHERE status = 'active'"),
    'Teachers' => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND active = 1"),
    'Exams' => (int)q_val('SELECT COUNT(*) FROM exams'),   // jumla sawa na super/exams.php
];
$schools = q_all("SELECT sc.*, (SELECT COUNT(*) FROM students s WHERE s.school_id = sc.id AND s.status = 'active') AS n_students,
    (SELECT COUNT(*) FROM users u WHERE u.school_id = sc.id AND u.role = 'teacher' AND u.active = 1) AS n_teachers,
    (SELECT COUNT(*) FROM users u WHERE u.school_id = sc.id AND u.role = 'admin' AND u.active = 1) AS n_admins FROM schools sc ORDER BY sc.name");
$exams = q_all('SELECT e.*, y.name AS year_name, sc.name AS owner_name, (SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = e.id) AS n_sch
    FROM exams e LEFT JOIN academic_years y ON y.id = e.academic_year_id LEFT JOIN schools sc ON sc.id = e.owner_school_id
    ORDER BY e.id DESC LIMIT 6');
$noAdmin = 0;
foreach ($schools as $s) { if ($s['active'] && !(int)$s['n_admins']) $noAdmin++; }
$statCards = [
    ['Schools', $cnt['Schools'], 'buildings', 'sd-blue', 'super/schools.php', 'Active schools'],
    ['Students', $cnt['Students'], 'people-fill', 'sd-indigo', 'super/schools.php', 'Enrolled students'],
    ['Teachers', $cnt['Teachers'], 'person-badge', 'sd-green', 'super/schools.php', 'Active teachers'],
    ['Exams', $cnt['Exams'], 'journal-text', 'sd-amber', 'super/exams.php', 'District and school exams'],
];
layout_start('Dashboard', 'dashboard'); ?>
<style>
.min-w-0{min-width:0}
.sd-welcome{font-size:1.4rem;font-weight:800;color:#0b3d91;letter-spacing:-.01em;margin:0}
.sd-stat{display:flex;align-items:center;gap:13px;background:#fff;border:1px solid #e7ecf7;border-radius:15px;padding:15px 16px;height:100%;
  text-decoration:none;color:inherit;box-shadow:0 2px 10px rgba(11,61,145,.05);transition:transform .16s,box-shadow .16s}
.sd-stat:hover{transform:translateY(-2px);box-shadow:0 14px 26px -18px rgba(11,61,145,.8);color:inherit}
.sd-stat .ic{flex:0 0 46px;height:46px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;font-size:1.15rem;color:#fff}
.sd-blue{background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 8px 16px -10px rgba(11,61,145,.9)}
.sd-indigo{background:linear-gradient(135deg,#3b3f9e,#6a5cf0);box-shadow:0 8px 16px -10px rgba(59,63,158,.9)}
.sd-green{background:linear-gradient(135deg,#0e7a55,#19b37d);box-shadow:0 8px 16px -10px rgba(14,122,85,.9)}
.sd-amber{background:linear-gradient(135deg,#b06a00,#e8a019);box-shadow:0 8px 16px -10px rgba(176,106,0,.9)}
.sd-stat .v{font-size:1.45rem;font-weight:800;line-height:1.1;color:#16203a}
.sd-stat .k{font-size:.74rem;font-weight:700;color:#33415c;text-transform:uppercase;letter-spacing:.04em}
.sd-stat .h{font-size:.7rem;color:#8a99b0;line-height:1.3}
.sd-card-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:.85rem 1.05rem;border-bottom:1px solid #eef2fa}
.sd-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.sd-card-h .t i{color:#2c6ed5}
.sd-school{display:flex;align-items:center;gap:10px}
.sd-av{flex:0 0 34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;
  color:#0b3d91;background:#eaf1fd;border:1px solid #d9e6fb}
.sd-filter{display:flex;gap:8px;align-items:center;padding:10px 12px;background:#fbfcff;border-bottom:1px solid #f0f3fa;flex-wrap:wrap}
.sd-filter .input-group{flex:1 1 220px;min-width:180px}
.sd-filter .input-group-text{background:#fff;border-color:#e2e9f6;border-right:0;color:#8a99b0;border-radius:10px 0 0 10px}
.sd-filter .form-control{border-color:#e2e9f6;border-left:0;border-radius:0 10px 10px 0;font-size:.82rem;background:#fff}
.sd-filter .form-control:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12)}
.sd-filter .input-group:focus-within .input-group-text{border-color:#8fb4ea;color:#2c6ed5}
.sd-filter .form-select{flex:0 0 150px;border-color:#e2e9f6;border-radius:10px;font-size:.82rem;background-color:#fff}
.sd-filter .form-select:focus{border-color:#8fb4ea;box-shadow:0 0 0 3px rgba(44,110,213,.12)}
.sd-pill{display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;border-radius:999px;padding:3px 9px;background:#f2f5fc;color:#4a5b78;border:1px solid #e5ebf7}
.sd-pill i{font-size:.72rem;color:#8a99b0}
.sd-dot{width:7px;height:7px;border-radius:50%;display:inline-block;background:#19b37d;box-shadow:0 0 0 3px rgba(25,179,125,.16)}
.sd-dot.off{background:#adb5bd;box-shadow:0 0 0 3px rgba(173,181,189,.18)}
.sd-exam{display:flex;align-items:center;gap:11px;padding:12px 14px;border-bottom:1px solid #f0f3fa}
.sd-exam:last-child{border-bottom:0}
.sd-exam .ic{flex:0 0 36px;height:36px;border-radius:11px;background:#f2f6ff;color:#2c6ed5;border:1px solid #e2ebfb;display:inline-flex;align-items:center;justify-content:center}
.sd-exam .n{font-weight:700;font-size:.87rem;line-height:1.25}
.sd-exam .m{font-size:.73rem;color:#8a99b0;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.sd-empty{padding:22px;text-align:center;color:#8a99b0;font-size:.85rem}
@media(max-width:575px){.sd-welcome{font-size:1.2rem}}
</style>

<div class="mb-3">
  <h1 class="sd-welcome">Welcome to OASIS</h1>
</div>

<div class="row g-3 mb-3">
<?php foreach ($statCards as $c): ?>
  <div class="col-6 col-lg-3">
    <a class="sd-stat" href="<?php echo h(url($c[4])); ?>">
      <span class="ic <?php echo $c[3]; ?>"><i class="bi bi-<?php echo $c[2]; ?>"></i></span>
      <span class="min-w-0">
        <span class="d-block v"><?php echo (int)$c[1]; ?></span>
        <span class="d-block k"><?php echo h($c[0]); ?></span>
        <span class="d-block h d-none d-sm-block"><?php echo h($c[5]); ?></span>
      </span>
    </a>
  </div>
<?php endforeach; ?>
</div>

<?php if (!$schools): ?><div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No schools yet. <a href="schools.php">Add the first school</a>.</div><?php endif; ?>
<?php if ($noAdmin): ?><div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><b><?php echo $noAdmin; ?></b> school(s) have no administrator. <a href="schools.php">Review schools</a>.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="sd-card-h">
        <span class="t"><i class="bi bi-buildings"></i> Schools</span>
        <span class="d-flex align-items-center gap-2">
          <span class="sd-pill"><i class="bi bi-hash"></i><span id="sdCount"><?php echo count($schools); ?></span></span>
          <a class="btn btn-primary btn-sm" href="schools.php"><i class="bi bi-plus-lg me-1"></i>School</a>
        </span>
      </div>
      <div class="sd-filter">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="sdQ" class="form-control" placeholder="Search school name or centre no..." autocomplete="off">
        </div>
        <select id="sdSt" class="form-select form-select-sm" aria-label="Filter by status">
          <option value="">All schools</option>
          <option value="1">Active only</option>
          <option value="0">Disabled only</option>
        </select>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th class="ps-3">School</th><th>Centre</th><th>Students</th><th>Teachers</th><th>Admin</th><th class="text-end pe-3">Action</th></tr></thead>
          <tbody>
          <?php foreach ($schools as $s): ?>
            <tr class="sd-row <?php echo $s['active'] ? '' : 'text-muted'; ?>" data-name="<?php echo h(strtolower($s['name'])); ?>" data-centre="<?php echo h(strtolower((string)$s['centre_no'])); ?>" data-active="<?php echo $s['active'] ? '1' : '0'; ?>">
              <td class="ps-3">
                <div class="sd-school">
                  <span class="sd-av"><?php echo h(strtoupper(mb_substr($s['name'], 0, 1))); ?></span>
                  <span class="min-w-0">
                    <span class="d-block fw-semibold text-truncate" style="max-width:230px"><?php echo h($s['name']); ?></span>
                    <span class="d-block small text-muted"><span class="sd-dot<?php echo $s['active'] ? '' : ' off'; ?>"></span> <?php echo $s['active'] ? 'Active' : 'Disabled'; ?></span>
                  </span>
                </div>
              </td>
              <td class="small text-muted"><?php echo h($s['centre_no'] ?: '-'); ?></td>
              <td><span class="sd-pill"><i class="bi bi-people-fill"></i><?php echo (int)$s['n_students']; ?></span></td>
              <td><span class="sd-pill"><i class="bi bi-person-badge"></i><?php echo (int)$s['n_teachers']; ?></span></td>
              <td><?php echo (int)$s['n_admins'] ? '<span class="sd-pill"><i class="bi bi-shield-check"></i>' . (int)$s['n_admins'] . '</span>' : '<span class="badge bg-warning text-dark">none</span>'; ?></td>
              <td class="text-end pe-3">
                <?php if ($s['active']): ?>
                  <form method="post" action="schools.php" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="enter"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-in-right me-1"></i>Open</button></form>
                <?php else: ?><span class="badge bg-secondary">off</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="sdNoMatch" style="display:none"><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No schools match your filter.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="sd-card-h">
        <span class="t"><i class="bi bi-journal-text"></i> Exams</span>
        <a class="btn btn-primary btn-sm" href="exam_form.php"><i class="bi bi-plus-lg me-1"></i>Exam</a>
      </div>
      <?php if (!$exams): ?><div class="sd-empty"><i class="bi bi-inbox d-block fs-3 mb-2"></i>No exams yet.</div><?php endif; ?>
      <?php foreach ($exams as $e): ?>
        <div class="sd-exam">
          <span class="ic"><i class="bi bi-file-earmark-text"></i></span>
          <div class="min-w-0 flex-grow-1">
            <div class="n text-truncate"><?php echo h($e['name']); ?></div>
            <div class="m">
              <span><?php echo h($e['year_name']); ?></span><span>&middot;</span>
              <span><?php echo (int)$e['n_sch']; ?> schools</span>
              <span class="badge <?php echo $e['owner_school_id'] ? 'bg-light text-secondary-emphasis border' : 'bg-primary-subtle text-primary-emphasis'; ?>"><?php echo $e['owner_school_id'] ? h($e['owner_name']) : 'District'; ?></span>
              <span class="badge <?php echo $e['status'] === 'open' ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'; ?>"><?php echo $e['status'] === 'open' ? 'Open' : 'Closed'; ?></span>
            </div>
          </div>
          <a class="btn btn-outline-primary btn-sm" href="exam_view.php?id=<?php echo (int)$e['id']; ?>">Open</a>
        </div>
      <?php endforeach; ?>
      <div class="p-3 border-top text-center">
        <a class="small fw-semibold text-decoration-none" href="exams.php">All exams <i class="bi bi-arrow-right-short"></i></a>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var q = document.getElementById('sdQ'), st = document.getElementById('sdSt'),
      rows = document.querySelectorAll('.sd-row'), empty = document.getElementById('sdNoMatch'), cnt = document.getElementById('sdCount');
  if (!q) return;
  function apply() {
    var term = q.value.trim().toLowerCase(), status = st.value, shown = 0;
    rows.forEach(function (r) {
      var ok = (!status || r.getAttribute('data-active') === status) &&
               (term === '' || r.getAttribute('data-name').indexOf(term) > -1 || r.getAttribute('data-centre').indexOf(term) > -1);
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
