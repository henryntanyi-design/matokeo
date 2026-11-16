<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();
if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'reset_pin') {
        $rid = (int)($_POST['id'] ?? 0);
        $t = q_one('SELECT * FROM students WHERE id = ? AND school_id = ?', [$rid, $school_id]);
        if (!$t) flash('danger', 'Student not found.');
        else {
            $pin = generate_pin();
            q('UPDATE students SET pin_hash = ?, must_change = 1, pin_failed = 0, pin_locked_until = NULL WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $rid]);
            flash('success', 'New PIN for <b>' . h(strtoupper(full_name($t))) . '</b> (' . h($t['prems_no'] ?: $t['admission_no']) . '): <code class="fs-5">' . h($pin) . '</code><br><small>It is shown once only. The student must set their own PIN at the next sign-in.</small>');
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!q_val('SELECT 1 FROM students WHERE id = ? AND school_id = ?', [$id, $school_id])) { flash('danger', 'Student not found.'); }
        elseif ((int)q_val('SELECT COUNT(*) FROM exam_candidates WHERE student_id = ?', [$id]) > 0) {
            q("UPDATE students SET status = 'inactive' WHERE id = ? AND school_id = ?", [$id, $school_id]);
            flash('warning', 'This student has exam records, so the account was set to <b>inactive</b> (results preserved) instead of being deleted.');
        } else { q('DELETE FROM students WHERE id = ? AND school_id = ?', [$id, $school_id]); flash('success', 'Student deleted.'); }
    }
    redirect('admin/students.php?' . http_build_query(array_filter(['class_id' => $_POST['class_id'] ?? '', 'q' => $_POST['q'] ?? '', 'status' => $_POST['status'] ?? ''])));
}
$class_id = (int)($_GET['class_id'] ?? 0); $status = $_GET['status'] ?? 'active'; $qs = trim($_GET['q'] ?? ''); $page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
$where = ['s.school_id = ?']; $p = [$school_id];
if ($class_id) { $where[] = 's.class_id = ?'; $p[] = $class_id; }
if (in_array($status, ['active', 'transferred', 'graduated', 'inactive'], true)) { $where[] = 's.status = ?'; $p[] = $status; } else $status = '';
if ($qs !== '') { $where[] = "(s.admission_no LIKE ? OR s.prems_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)"; $like = '%' . $qs . '%'; array_push($p, $like, $like, $like, $like); }
$W = implode(' AND ', $where);
$total = (int)q_val("SELECT COUNT(*) FROM students s WHERE $W", $p);
$rows = q_all("SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE $W ORDER BY c.sort_order, s.last_name, s.first_name LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
$pages = max(1, (int)ceil($total / $per));
$qsBase = http_build_query(array_filter(['class_id' => $class_id, 'status' => $status, 'q' => $qs]));
$stat = q_one("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(sex = 'M'), 0) AS male, COALESCE(SUM(sex = 'F'), 0) AS female, COALESCE(SUM(pin_hash IS NOT NULL), 0) AS pinned FROM students WHERE school_id = ?", [$school_id]);
$nAll = (int)$stat['total']; $nActive = (int)$stat['active']; $nMale = (int)$stat['male']; $nFemale = (int)$stat['female']; $nPinned = (int)$stat['pinned'];
$from = $total ? (($page - 1) * $per + 1) : 0; $to = min($total, $page * $per);
$statusMap = ['active' => ['Active', 'g'], 'transferred' => ['Transferred', 'b'], 'graduated' => ['Graduated', 'a'], 'inactive' => ['Inactive', 'n']];
$filtered = $class_id || $qs !== '' || $status !== 'active';
$prettyName = function ($r) {
    $full = trim((string)$r['first_name'] . ' ' . (string)$r['middle_name'] . ' ' . (string)$r['last_name']);
    $full = preg_replace('/\s+/', ' ', $full);
    return mb_convert_case(mb_strtolower($full, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
};
$initials = function ($r) {
    $a = mb_substr(trim((string)$r['first_name']), 0, 1, 'UTF-8');
    $b = mb_substr(trim((string)$r['last_name']), 0, 1, 'UTF-8');
    $s = mb_strtoupper($a . $b, 'UTF-8');
    return $s !== '' ? $s : '?';
};
layout_start('Students', 'students'); ?>

<style>
.stu-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.stu-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.stu-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.stu-hero .inner{position:relative;z-index:2;min-width:0}
.stu-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.stu-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.stu-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:640px;line-height:1.45}
.stu-hero .actions{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:8px}
.stu-hero .actions .btn{font-weight:700;border-radius:10px;padding:.5rem .95rem}
.stu-hero .btn-light{color:#0b3d91;border:0;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.stu-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.stu-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.stu-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.stu-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.stu-stat.f .ico{background:#fdeef5;color:#b03572}
.stu-stat.m .ico{background:#e8f1fd;color:#1f6fb2}
.stu-stat.s .ico{background:#fdf1dc;color:#b06a00}
.stu-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.stu-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.stu-toolbar{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:12px 14px;box-shadow:0 2px 10px rgba(11,61,145,.05);margin-bottom:16px}
.stu-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.stu-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;border-bottom:1px solid #eef2fa}
.stu-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.stu-card-h .t i{color:#2c6ed5}
.stu-card-h .badge{background:#eef3fd!important;color:#0b3d91!important;border:1px solid #d9e6fb;border-radius:999px;font-weight:800}
.stu-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.stu-table tbody td{padding-top:.68rem;padding-bottom:.68rem;border-color:#f0f4fb;vertical-align:middle}
.stu-table tbody tr:hover{background:#fafcff}
.stu-av{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:.76rem;font-weight:800;color:#fff;flex:0 0 auto;letter-spacing:.02em;background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 3px 8px rgba(11,61,145,.2)}
.stu-av.f{background:linear-gradient(135deg,#8e2a63,#cf5b9b);box-shadow:0 3px 8px rgba(142,42,99,.2)}
.stu-av.m{background:linear-gradient(135deg,#125b96,#3b9ad9);box-shadow:0 3px 8px rgba(18,91,150,.2)}
.stu-name{font-weight:700;color:#16203a;line-height:1.25;word-break:break-word}
.stu-meta{font-size:.71rem;color:#8a99b0;display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap}
.stu-code{font-size:.68rem;font-weight:800;background:#eef3fd;color:#0b3d91;border:1px solid #d9e6fb;border-radius:6px;padding:1px 6px;letter-spacing:.02em}
.stu-pill{display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:800;border-radius:999px;padding:2px 9px;border:1px solid;white-space:nowrap}
.stu-pill.g{background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.stu-pill.b{background:#eaf1fe;color:#1b5bc4;border-color:#cfe0fb}
.stu-pill.a{background:#f0ecfd;color:#5b3fb5;border-color:#ddd3f8}
.stu-pill.n{background:#f2f4f8;color:#6b7a93;border-color:#e2e8f2}
.stu-pill.w{background:#fdf1dc;color:#8a5a00;border-color:#f5dfb2}
.stu-sex{display:inline-flex;align-items:center;gap:4px;font-size:.76rem;color:#33415c;font-weight:600}
.stu-sex i{color:#8a99b0}
.stu-count{display:inline-flex;align-items:center;gap:5px;font-size:.8rem;color:#33415c;font-weight:600}
.stu-count i{color:#8a99b0}
.stu-actions{display:inline-flex;gap:5px;align-items:center}
.stu-actions .btn{--bs-btn-padding-y:.26rem;--bs-btn-padding-x:.46rem;--bs-btn-font-size:.75rem;border-radius:8px}
.stu-empty{text-align:center;padding:2.8rem 1rem;color:#8a99b0}
.stu-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.stu-pager{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:11px 16px;border-top:1px solid #eef2fa;background:linear-gradient(180deg,#fbfcff,#fff)}
.stu-pager .pagination{margin:0}
.stu-pager .page-link{font-size:.76rem;font-weight:700;color:#33415c;border-color:#e2e9f5}
.stu-pager .page-item.active .page-link{background:#1b5bc4;border-color:#1b5bc4;color:#fff}
@media(max-width:575px){.stu-hero{padding:18px}.stu-hero h1{font-size:1.32rem}}
@media print{.stu-hero .actions,.stu-toolbar,.stu-actions,.stu-pager{display:none!important}.stu-hero{background:#fff!important;color:#000!important;box-shadow:none}}
</style>

<div class="stu-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-people"></i> Student register</div>
    <h1>Students</h1>
    <p>Manage school enrolment, class placement and PIN access. Search the register, edit student details, or reset a PIN.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light btn-sm" href="student_form.php"><i class="bi bi-plus-lg me-1"></i> New student</a>
    <a class="btn btn-light btn-sm" href="student_import.php"><i class="bi bi-file-earmark-excel me-1"></i> Import</a>
    <a class="btn btn-light btn-sm" href="student_pins.php"><i class="bi bi-key me-1"></i> Student PINs</a>
  </div>
</div>

<div class="stu-stats">
  <div class="stu-stat"><span class="ico"><i class="bi bi-people"></i></span><div><div class="v"><?php echo $nAll; ?></div><div class="l">Total students</div></div></div>
  <div class="stu-stat g"><span class="ico"><i class="bi bi-person-check"></i></span><div><div class="v"><?php echo $nActive; ?></div><div class="l">Active</div></div></div>
  <div class="stu-stat f"><span class="ico"><i class="bi bi-gender-female"></i></span><div><div class="v"><?php echo $nFemale; ?></div><div class="l">Female</div></div></div>
  <div class="stu-stat m"><span class="ico"><i class="bi bi-gender-male"></i></span><div><div class="v"><?php echo $nMale; ?></div><div class="l">Male</div></div></div>
  <div class="stu-stat s"><span class="ico"><i class="bi bi-key"></i></span><div><div class="v"><?php echo $nPinned; ?></div><div class="l">PIN set</div></div></div>
</div>

<div class="stu-toolbar no-print">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-lg-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Search name, PREMS No or admission No" value="<?php echo h($qs); ?>">
      </div>
    </div>
    <div class="col-6 col-lg-2">
      <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All classes</option>
        <?php foreach ($classes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($class_id === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; ?>
      </select>
    </div>
    <div class="col-6 col-lg-2">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Any status</option>
        <?php foreach ($statusMap as $k => $v) echo '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $v[0] . '</option>'; ?>
      </select>
    </div>
    <div class="col-lg-3 d-flex gap-2">
      <button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
      <?php if ($filtered): ?><a class="btn btn-outline-secondary btn-sm" href="students.php" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
  </form>
</div>

<div class="stu-card">
  <div class="stu-card-h">
    <span class="t"><i class="bi bi-list-ul"></i> Student list</span>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="badge">Showing <?php echo $from; ?>&ndash;<?php echo $to; ?> of <?php echo $total; ?></span>
      <?php if ($filtered): ?><span class="small text-muted">Filtered from <?php echo $nAll; ?> total</span><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 stu-table">
      <thead>
        <tr>
          <th class="ps-3">Student</th>
          <th>Sex</th>
          <th>Class</th>
          <th>Nationality</th>
          <th>PIN</th>
          <th>Status</th>
          <th class="text-end pe-3">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $st = $statusMap[$r['status']] ?? [ucfirst((string)$r['status']), 'n']; ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <span class="stu-av <?php echo strtolower(h($r['sex'])) === 'f' ? 'f' : (strtolower(h($r['sex'])) === 'm' ? 'm' : ''); ?>"><?php echo h($initials($r)); ?></span>
              <div style="min-width:0">
                <div class="stu-name"><?php echo h($prettyName($r)); ?></div>
                <div class="stu-meta">
                  <span class="stu-code"><?php echo h($r['prems_no'] ?: $r['admission_no'] ?: 'No ID'); ?></span>
                  <?php if (!empty($r['guardian_phone'])): ?><span><i class="bi bi-telephone"></i> <?php echo h($r['guardian_phone']); ?></span><?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td><span class="stu-sex"><i class="bi bi-<?php echo strtolower(h($r['sex'])) === 'f' ? 'gender-female' : 'gender-male'; ?>"></i><?php echo h($r['sex']); ?></span></td>
          <td><span class="stu-count"><i class="bi bi-journal-bookmark"></i><?php echo h($r['class_name'] . ($r['stream'] ? ' ' . $r['stream'] : '')); ?></span></td>
          <td><?php echo $r['nationality'] ? h($r['nationality']) : '<span class="text-muted">&mdash;</span>'; ?></td>
          <td>
            <?php if ($r['pin_hash']): ?>
              <span class="stu-pill g"><i class="bi bi-check-circle-fill"></i>Set</span>
            <?php else: ?>
              <span class="stu-pill n"><i class="bi bi-dash-circle"></i>Not set</span>
            <?php endif; ?>
          </td>
          <td><span class="stu-pill <?php echo $st[1]; ?>"><?php echo h($st[0]); ?></span></td>
          <td class="text-end pe-3 text-nowrap">
            <div class="stu-actions justify-content-end">
              <a class="btn btn-outline-secondary" href="student_form.php?id=<?php echo (int)$r['id']; ?>" title="Edit student"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Create a new temporary PIN for this student?')">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="reset_pin"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>"><input type="hidden" name="q" value="<?php echo h($qs); ?>"><input type="hidden" name="status" value="<?php echo h($status); ?>">
                <button class="btn btn-outline-warning" title="Reset PIN"><i class="bi bi-key"></i></button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this student? If they have exam records, the account will be set to inactive instead.')">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>"><input type="hidden" name="q" value="<?php echo h($qs); ?>"><input type="hidden" name="status" value="<?php echo h($status); ?>">
                <button class="btn btn-outline-danger" title="Delete student"><i class="bi bi-trash3"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7">
          <div class="stu-empty">
            <i class="bi bi-people"></i>
            <div class="fw-bold text-dark mb-1"><?php echo $filtered ? 'No students match the filters you applied.' : 'No students yet.'; ?></div>
            <div class="small"><?php echo $filtered ? 'Change the filters or clear the search.' : 'Add your first student using the New student button.'; ?></div>
          </div>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="stu-pager">
    <span class="small text-muted">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
    <ul class="pagination pagination-sm">
      <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="?<?php echo h($qsBase . ($qsBase ? '&' : '') . 'page=' . max(1, $page - 1)); ?>"><i class="bi bi-chevron-left"></i></a></li>
      <?php for ($i = 1; $i <= $pages; $i++) echo '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="?' . h($qsBase . ($qsBase ? '&' : '') . 'page=' . $i) . '">' . $i . '</a></li>'; ?>
      <li class="page-item<?php echo $page >= $pages ? ' disabled' : ''; ?>"><a class="page-link" href="?<?php echo h($qsBase . ($qsBase ? '&' : '') . 'page=' . min($pages, $page + 1)); ?>"><i class="bi bi-chevron-right"></i></a></li>
    </ul>
  </div>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
