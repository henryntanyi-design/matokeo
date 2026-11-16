<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'clear') {
        $sid = (int)($_POST['id'] ?? 0);
        if (!q_val('SELECT 1 FROM subjects WHERE id = ?', [$sid])) {
            flash('danger', 'Subject not found.');
        } else {
            $st = q('DELETE ss FROM student_subjects ss JOIN students st ON st.id = ss.student_id WHERE ss.subject_id = ? AND st.school_id = ?', [$sid, $school_id]);
            $n = $st->rowCount();
            flash($n ? 'success' : 'info', $n
                ? 'Enrolments for <b>' . $n . '</b> student(s) in this subject were removed.'
                : 'No student is enrolled in this subject.');
        }
        redirect('admin/subjects.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'level' => $_POST['level'] ?? ''])));
    }
}

$qs = trim((string)($_GET['q'] ?? ''));
$level = (string)($_GET['level'] ?? '');
$where = [];
$p = [];
if ($qs !== '') {
    $where[] = '(s.name LIKE ? OR s.code LIKE ? OR s.abbr LIKE ?)';
    $like = '%' . $qs . '%';
    array_push($p, $like, $like, $like);
}
if (in_array($level, ['O', 'A', 'B'], true)) { $where[] = 's.level = ?'; $p[] = $level; }
$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = q_all("SELECT s.*,
        (SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
          WHERE ss.subject_id = s.id AND st.school_id = ? AND st.status = 'active') AS n_students
    FROM subjects s $W
    ORDER BY s.is_subsidiary, s.name", array_merge([$school_id], $p));
$totalEnrolled = (int)q_val("SELECT COUNT(*) FROM student_subjects ss JOIN students st ON st.id = ss.student_id
    WHERE st.school_id = ? AND st.status = 'active'", [$school_id]);
$stat = q_one("SELECT COUNT(*) AS total, COALESCE(SUM(level = 'O'), 0) AS o, COALESCE(SUM(level = 'A'), 0) AS a, COALESCE(SUM(level = 'B'), 0) AS b FROM subjects");
$nTotal = (int)$stat['total']; $nO = (int)$stat['o']; $nA = (int)$stat['a']; $nB = (int)$stat['b'];
$filtered = $qs !== '' || $level !== '';
$lvlLabel = ['O' => 'O-Level', 'A' => 'A-Level', 'B' => 'O & A'];
$lvlIcon = ['O' => 'book', 'A' => 'mortarboard', 'B' => 'collection'];
$totalStudents = (int)q_val("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'", [$school_id]);

layout_start('Subjects', 'subjects'); ?>

<style>
.asu-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.asu-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.asu-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.asu-hero .inner{position:relative;z-index:2;min-width:0}
.asu-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.asu-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.asu-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:650px;line-height:1.45}
.asu-hero-chip{position:relative;z-index:2;display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:12px;padding:9px 14px}
.asu-hero-chip i{font-size:1.15rem;opacity:.9}
.asu-hero-chip span{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.06em;font-weight:800;opacity:.75}
.asu-hero-chip b{font-size:1.02rem;line-height:1.1}
.asu-note{display:flex;gap:10px;background:#eef5ff;border:1px solid #d6e5fb;border-radius:14px;padding:11px 14px;font-size:.79rem;color:#2c4a77;line-height:1.5;margin-bottom:16px}
.asu-note i{color:#1b5bc4;font-size:1rem;flex:0 0 auto;margin-top:1px}
.asu-note b{color:#123266}
.asu-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.asu-stat{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(11,61,145,.05)}
.asu-stat .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:1.08rem;background:#eef3fd;color:#0b3d91;flex:0 0 auto}
.asu-stat.g .ico{background:#e8f7ee;color:#0b8a3e}
.asu-stat.a .ico{background:#f0ecfd;color:#5b3fb5}
.asu-stat.b .ico{background:#eaf1fe;color:#1b5bc4}
.asu-stat.s .ico{background:#fdf1dc;color:#b06a00}
.asu-stat .v{font-size:1.28rem;font-weight:800;line-height:1.05;color:#16203a}
.asu-stat .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#8a99b0;font-weight:800;margin-top:2px}
.asu-toolbar{background:#fff;border:1px solid #e8eef9;border-radius:14px;padding:12px 14px;box-shadow:0 2px 10px rgba(11,61,145,.05);margin-bottom:16px}
.asu-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden}
.asu-card-h{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;padding:13px 16px;border-bottom:1px solid #eef2fa}
.asu-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.asu-card-h .t i{color:#2c6ed5}
.asu-card-h .badge{background:#eef3fd!important;color:#0b3d91!important;border:1px solid #d9e6fb;border-radius:999px;font-weight:800}
.asu-card-h .hint{font-size:.72rem;color:#8a99b0}
.asu-table thead th{font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.62rem;padding-bottom:.62rem}
.asu-table tbody td{padding-top:.68rem;padding-bottom:.68rem;border-color:#f0f4fb;vertical-align:middle}
.asu-table tbody tr:hover{background:#fafcff}
.asu-mark{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:1rem;color:#fff;flex:0 0 auto;background:linear-gradient(135deg,#0b3d91,#2c6ed5);box-shadow:0 3px 8px rgba(11,61,145,.22)}
.asu-mark.o{background:linear-gradient(135deg,#0b8a3e,#20b15b);box-shadow:0 3px 8px rgba(11,138,62,.22)}
.asu-mark.a{background:linear-gradient(135deg,#5b3fb5,#8a6fe0);box-shadow:0 3px 8px rgba(91,63,181,.22)}
.asu-name{font-weight:700;color:#16203a;line-height:1.25;word-break:break-word}
.asu-meta{font-size:.71rem;color:#8a99b0;display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap}
.asu-code{font-size:.68rem;font-weight:800;background:#eef3fd;color:#0b3d91;border:1px solid #d9e6fb;border-radius:6px;padding:1px 6px;letter-spacing:.02em}
.asu-subbadge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:800;color:#8a5a00;background:#fdf1dc;border:1px solid #f5dfb2;border-radius:999px;padding:2px 8px}
.asu-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:2px 9px;border:1px solid;white-space:nowrap}
.asu-pill.o{background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.asu-pill.a{background:#f0ecfd;color:#5b3fb5;border-color:#ddd3f8}
.asu-pill.b{background:#eaf1fe;color:#1b5bc4;border-color:#cfe0fb}
.asu-enrol{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:800;border-radius:999px;padding:2px 9px;border:1px solid;white-space:nowrap;background:#e8f7ee;color:#0b8a3e;border-color:#c6ecd6}
.asu-none{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;color:#8a99b0}
.asu-none i{color:#c3cfe4}
.asu-actions{display:inline-flex;gap:5px;align-items:center}
.asu-actions .btn{--bs-btn-padding-y:.26rem;--bs-btn-padding-x:.46rem;--bs-btn-font-size:.75rem;border-radius:8px}
.asu-empty{text-align:center;padding:2.8rem 1rem;color:#8a99b0}
.asu-empty i{font-size:2rem;display:block;margin-bottom:6px;color:#c3cfe4}
.asu-tip{display:flex;gap:8px;background:#f4f8ff;border:1px solid #dbe8fb;border-radius:12px;padding:10px 12px;font-size:.75rem;color:#3f5a80;line-height:1.45;margin-top:16px}
.asu-tip i{color:#2c6ed5;font-size:.95rem;flex:0 0 auto;margin-top:1px}
@media(max-width:575px){.asu-hero{padding:18px}.asu-hero h1{font-size:1.32rem}.asu-hero-chip{width:100%}}
@media print{.asu-hero,.asu-note,.asu-toolbar,.asu-actions,.asu-tip{display:none!important}.asu-hero{background:#fff!important;color:#000!important;box-shadow:none}}
</style>

<div class="asu-hero no-print">
  <div class="inner">
    <div class="kicker"><i class="bi bi-journals"></i> Curriculum &middot; School</div>
    <h1>Subjects</h1>
    <p>Browse the subjects offered by your school, check enrolments, and manage the students taking each subject.</p>
  </div>
  <div class="asu-hero-chip">
    <i class="bi bi-people-fill"></i>
    <div><span>Total enrolments</span><b><?php echo $totalEnrolled; ?></b></div>
  </div>
</div>

<div class="asu-note no-print">
  <i class="bi bi-info-circle-fill"></i>
  <div><b>How enrolment works.</b> Open a subject with the students button to add or remove the students taking it. Clearing enrolment only removes student links &mdash; <b>the subject itself is not deleted</b>.</div>
</div>

<div class="asu-stats">
  <div class="asu-stat"><span class="ico"><i class="bi bi-journals"></i></span><div><div class="v"><?php echo $nTotal; ?></div><div class="l">Total subjects</div></div></div>
  <div class="asu-stat g"><span class="ico"><i class="bi bi-book"></i></span><div><div class="v"><?php echo $nO; ?></div><div class="l">O-Level</div></div></div>
  <div class="asu-stat a"><span class="ico"><i class="bi bi-mortarboard"></i></span><div><div class="v"><?php echo $nA; ?></div><div class="l">A-Level</div></div></div>
  <div class="asu-stat b"><span class="ico"><i class="bi bi-collection"></i></span><div><div class="v"><?php echo $nB; ?></div><div class="l">O &amp; A</div></div></div>
  <div class="asu-stat s"><span class="ico"><i class="bi bi-people"></i></span><div><div class="v"><?php echo $totalEnrolled; ?></div><div class="l">Enrolments</div></div></div>
</div>

<div class="asu-toolbar no-print">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-lg-6">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Search subject name, code or NECTA abbreviation" value="<?php echo h($qs); ?>">
      </div>
    </div>
    <div class="col-6 col-lg-2">
      <select name="level" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All levels</option>
        <?php foreach (['O' => 'O-Level', 'A' => 'A-Level', 'B' => 'O & A'] as $k => $v): ?>
          <option value="<?php echo $k; ?>"<?php echo $level === $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-lg-2">
      <span class="small text-muted d-block text-center text-lg-start"><i class="bi bi-people me-1"></i><?php echo $totalStudents; ?> active students</span>
    </div>
    <div class="col-12 col-lg-2 d-flex gap-2">
      <button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
      <?php if ($filtered): ?><a class="btn btn-outline-secondary btn-sm" href="subjects.php" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
  </form>
</div>

<div class="asu-card">
  <div class="asu-card-h">
    <span class="t"><i class="bi bi-list-ul"></i> Subject list</span>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="badge">Showing <?php echo count($rows); ?> of <?php echo $nTotal; ?></span>
      <span class="hint d-none d-md-inline"><i class="bi bi-mouse2 me-1"></i>Use the students button to manage enrolment</span>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 asu-table">
      <thead>
        <tr>
          <th class="ps-3">Subject</th>
          <th>NECTA abbreviation</th>
          <th>Level</th>
          <th>Students</th>
          <th class="text-end pe-3" style="width:150px">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $s): $lv = in_array($s['level'], ['O', 'A', 'B'], true) ? $s['level'] : 'B'; ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <span class="asu-mark <?php echo strtolower($lv); ?>"><i class="bi bi-<?php echo $lvlIcon[$lv]; ?>"></i></span>
              <div style="min-width:0">
                <div class="asu-name"><?php echo h($s['name']); ?></div>
                <div class="asu-meta">
                  <span class="asu-code"><?php echo h($s['code']); ?></span>
                  <?php if (!empty($s['is_subsidiary'])): ?><span class="asu-subbadge"><i class="bi bi-star-fill"></i>Subsidiary</span><?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td><?php echo trim((string)$s['abbr']) !== '' ? '<span class="fw-semibold text-dark">' . h($s['abbr']) . '</span>' : '<span class="text-muted small">&mdash;</span>'; ?></td>
          <td><span class="asu-pill <?php echo strtolower($lv); ?>"><?php echo $lvlLabel[$lv]; ?></span></td>
          <td>
            <?php if ((int)$s['n_students'] > 0): ?>
              <span class="asu-enrol"><i class="bi bi-people-fill"></i><?php echo (int)$s['n_students']; ?> enrolled</span>
            <?php else: ?>
              <span class="asu-none"><i class="bi bi-dash-circle"></i>None</span>
            <?php endif; ?>
          </td>
          <td class="text-end pe-3 text-nowrap">
            <div class="asu-actions justify-content-end">
              <a class="btn btn-primary" href="subject_students.php?id=<?php echo (int)$s['id']; ?>" title="Manage students taking this subject"><i class="bi bi-people"></i></a>
              <?php if ((int)$s['n_students'] > 0): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Remove all student enrolments for this subject? The subject itself will not be deleted.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <input type="hidden" name="q" value="<?php echo h($qs); ?>">
                <input type="hidden" name="level" value="<?php echo h($level); ?>">
                <button class="btn btn-outline-danger" title="Clear all student enrolments"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="5">
          <div class="asu-empty">
            <i class="bi bi-journal-x"></i>
            <div class="fw-bold text-dark mb-1"><?php echo $filtered ? 'No subject matches the filters you applied.' : 'No subjects are registered yet.'; ?></div>
            <div class="small"><?php echo $filtered ? 'Change the filters or clear the search.' : 'Subjects are added by the district administrator.'; ?></div>
          </div>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="asu-tip no-print"><i class="bi bi-lightbulb"></i><span>Student enrolment controls the subjects that appear on results and reports. Keep it up to date after registration or when a student changes subject combination.</span></div>
<?php layout_end(); ?>
