<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();

$me  = user();
$sch = active_school();

/* ---------- Numbers ---------- */
$nStudents    = (int)q_val("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'", [$school_id]);
$nStudentsAll = (int)q_val("SELECT COUNT(*) FROM students WHERE school_id = ?", [$school_id]);
$nStaff       = (int)q_val("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher' AND active = 1", [$school_id]);
$nClasses     = (int)q_val("SELECT COUNT(*) FROM classes");
$nExams       = (int)q_val("SELECT COUNT(*) FROM exam_schools WHERE school_id = ?", [$school_id]);
$nSubjects    = (int)q_val("SELECT COUNT(*) FROM subjects");

/* ---------- Classes with active students in this school ---------- */
$classRows = q_all("SELECT c.id, c.name, c.level, c.sort_order,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.school_id = ? AND s.status = 'active') AS n
    FROM classes c ORDER BY c.sort_order, c.id", [$school_id]);
$maxClass = 1;
foreach ($classRows as $cr) { $maxClass = max($maxClass, (int)$cr['n']); }

/* ---------- Recent exams of this school ---------- */
$examRows = q_all("SELECT e.id, e.name, e.status, es.published,
        (SELECT COUNT(*) FROM exam_candidates x WHERE x.exam_id = e.id AND x.school_id = es.school_id) AS cands,
        (SELECT COUNT(*) FROM scores s WHERE s.exam_id = e.id AND s.school_id = es.school_id) AS n_scores,
        (SELECT ROUND(AVG(s.score), 1) FROM scores s WHERE s.exam_id = e.id AND s.school_id = es.school_id AND s.score IS NOT NULL) AS avg_score
    FROM exam_schools es
    JOIN exams e ON e.id = es.exam_id
    WHERE es.school_id = ? ORDER BY e.id DESC LIMIT 4", [$school_id]);

/* ---------- Lists ---------- */
$recentStudents = q_all("SELECT s.*, c.name AS cname FROM students s
    JOIN classes c ON c.id = s.class_id
    WHERE s.school_id = ? ORDER BY s.id DESC LIMIT 5", [$school_id]);
$staffRows = q_all("SELECT id, full_name, username, role, active FROM users
    WHERE school_id = ? AND active = 1 ORDER BY full_name LIMIT 5", [$school_id]);

$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$parts = preg_split('/\s+/', trim((string)($me['full_name'] ?? '')));
$firstName = $parts[0] ?? '';

/* Herufi mbili za kwanza za majina (mf. "Juma Ally" -> "JA"). */
$ini = function ($name) {
    $out = ''; $n = 0;
    foreach (preg_split('/\s+/', trim((string)$name)) as $part) {
        if ($part === '' || $n >= 2) continue;
        $out .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        $n++;
    }
    return strtoupper($out);
};

layout_start('Dashboard', 'dashboard'); ?>

<style>
/* ---------- Hero ---------- */
.dsh-hero{position:relative;overflow:hidden;border-radius:20px;padding:24px 26px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 22px 44px -28px rgba(11,61,145,.95);margin-bottom:1rem}
.dsh-hero:before,.dsh-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.dsh-hero:before{width:280px;height:280px;right:-90px;top:-140px;background:rgba(255,255,255,.10)}
.dsh-hero:after{width:180px;height:180px;right:110px;bottom:-130px;background:rgba(255,215,100,.15)}
.dsh-hero-in{position:relative;z-index:2;display:flex;flex-wrap:wrap;align-items:center;gap:18px}
.dsh-hero-txt{flex:1 1 320px;min-width:0}
.dsh-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764;display:flex;align-items:center;gap:6px}
.dsh-hero h1{font-size:1.5rem;font-weight:800;margin:.2rem 0 .25rem;line-height:1.2;color:#fff}
.dsh-hero h1 span{color:#ffd764}
.dsh-hero p{margin:0;font-size:.82rem;color:rgba(255,255,255,.82);line-height:1.45;max-width:620px}
.dsh-hero-act{display:flex;flex-wrap:wrap;gap:8px;position:relative;z-index:2}
.dsh-btn{display:inline-flex;align-items:center;gap:7px;padding:.5rem 1rem;border-radius:11px;font-size:.79rem;font-weight:700;
  text-decoration:none;transition:transform .15s ease,background .15s ease,color .15s ease}
.dsh-btn:hover{transform:translateY(-1px)}
.dsh-btn.solid{background:#fff;color:#0b3d91}
.dsh-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.dsh-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.dsh-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

/* ---------- Stat tiles ---------- */
.dsh-stat{display:flex;align-items:flex-start;gap:13px;background:#fff;border:1px solid #e7ecf7;border-radius:16px;padding:16px 17px;
  height:100%;text-decoration:none;color:inherit;box-shadow:0 2px 10px rgba(11,61,145,.05);
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.dsh-stat:hover{transform:translateY(-3px);border-color:#cfddf5;box-shadow:0 16px 30px -22px rgba(11,61,145,.9);color:inherit}
.dsh-stat .ic{flex:0 0 46px;height:46px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1.12rem}
.dsh-stat .body{min-width:0;flex:1 1 auto}
.dsh-stat .v{font-size:1.7rem;font-weight:800;line-height:1;color:#16203a;letter-spacing:-.02em;display:block}
.dsh-stat .k{font-size:.72rem;font-weight:800;color:#33415c;text-transform:uppercase;letter-spacing:.05em;margin-top:5px;display:block}
.dsh-stat .s{font-size:.72rem;color:#8a99b0;margin-top:3px;display:block}
.dsh-stat .go{color:#c3cfe4;font-size:1.1rem;transition:transform .15s ease,color .15s ease}
.dsh-stat:hover .go{transform:translateX(3px);color:#2c6ed5}
.c-blue{background:linear-gradient(135deg,#0b3d91,#2c6ed5)}
.c-green{background:linear-gradient(135deg,#0e7a55,#19b37d)}
.c-violet{background:linear-gradient(135deg,#4c2fa8,#7048e8)}
.c-amber{background:linear-gradient(135deg,#b06a00,#e8a019)}

/* ---------- Cards ---------- */
.dsh-card{background:#fff;border:1px solid #e7ecf7;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.05);height:100%;display:flex;flex-direction:column}
.dsh-card-h{display:flex;align-items:center;gap:9px;padding:.85rem 1.05rem;border-bottom:1px solid #eef2fa;flex-wrap:wrap}
.dsh-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px;font-size:.93rem}
.dsh-card-h .t i{color:#2c6ed5}
.dsh-card-h .lnk{margin-left:auto;font-size:.76rem;font-weight:700;text-decoration:none;color:#2c6ed5}
.dsh-card-h .lnk:hover{color:#0b3d91}
.dsh-card-b{padding:14px 16px;flex:1 1 auto}
.dsh-empty{text-align:center;padding:26px 12px;color:#8a99b0;font-size:.83rem}
.dsh-empty i{display:block;font-size:1.9rem;color:#c6d5ec;margin-bottom:6px}

/* Class bars */
.dsh-bar{margin-bottom:13px}
.dsh-bar:last-child{margin-bottom:0}
.dsh-bar-t{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.82rem;margin-bottom:5px}
.dsh-bar-t .nm{font-weight:700;color:#16203a;text-decoration:none;display:inline-flex;align-items:center;gap:7px;min-width:0}
.dsh-bar-t .nm:hover{color:#0b3d91}
.dsh-bar-t .pv{font-weight:800;color:#2c6ed5;font-size:.78rem;white-space:nowrap}
.dsh-track{height:8px;border-radius:999px;background:#eef2fa;overflow:hidden}
.dsh-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#2c6ed5,#0b3d91);min-width:3px}
.dsh-fill.a{background:linear-gradient(90deg,#7048e8,#4c2fa8)}
.dsh-fill.zero{background:#e3e9f4}

/* Exam rows */
.dsh-exam{display:flex;align-items:flex-start;gap:11px;padding:11px 0;border-bottom:1px dashed #eef2fa}
.dsh-exam:last-child{border-bottom:0;padding-bottom:0}
.dsh-exam:first-child{padding-top:0}
.dsh-exam .ic{flex:0 0 36px;height:36px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;
  background:#f2f6ff;color:#2c6ed5;border:1px solid #e2ebfb}
.dsh-exam .nm{font-weight:700;font-size:.85rem;color:#16203a;line-height:1.25}
.dsh-exam .mt{font-size:.71rem;color:#8a99b0;display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:3px}
.dsh-exam .go{margin-left:auto;align-self:center;color:#2c6ed5;text-decoration:none;font-size:.75rem;font-weight:700;white-space:nowrap}
.dsh-exam .go:hover{color:#0b3d91}
.dsh-badge{font-size:.66rem;font-weight:800;border-radius:999px;padding:2px 8px;border:1px solid transparent;white-space:nowrap}
.dsh-badge.open{background:#e6f7ef;color:#0e7a55;border-color:#c9ecda}
.dsh-badge.closed{background:#f1f3f8;color:#5b6b85;border-color:#e3e8f2}
.dsh-badge.pub{background:#eaf1fe;color:#1b5bc4;border-color:#d3e1fb}

/* Table + list */
.dsh-table{margin:0}
.dsh-table th{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#8b9ab1;font-weight:800;
  background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap;padding:.6rem .75rem}
.dsh-table td{padding:.6rem .75rem;border-color:#f2f5fb;vertical-align:middle;font-size:.85rem}
.dsh-table tbody tr:hover{background:#fafcff}
.dsh-av{width:32px;height:32px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;color:#0b3d91;background:#eaf1fd;border:1px solid #d9e6fb;flex:0 0 auto}
.dsh-av.round{border-radius:50%;color:#fff;background:linear-gradient(140deg,#2c6ed5,#0b3d91);border:0}
.dsh-list{list-style:none;margin:0;padding:0}
.dsh-li{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px dashed #eef2fa}
.dsh-li:last-child{border-bottom:0;padding-bottom:0}
.dsh-li:first-child{padding-top:0}
.dsh-li .nm{font-weight:700;font-size:.85rem;color:#16203a;line-height:1.2;min-width:0}
.dsh-li .mt{font-size:.71rem;color:#8a99b0}
.dsh-li .act{margin-left:auto;color:#c3cfe4;text-decoration:none}
.dsh-li .act:hover{color:#2c6ed5}

@media(max-width:575.98px){.dsh-hero{padding:18px;border-radius:16px}.dsh-hero h1{font-size:1.22rem}.dsh-hero-act{width:100%}}
@media print{.dsh-hero{background:#fff!important;color:#0b3d91!important;box-shadow:none}.dsh-hero-act{display:none!important}.dsh-hero p,.dsh-hero h1{color:#16203a!important}}
</style>

<div class="dsh-hero">
  <div class="dsh-hero-in">
    <div class="dsh-hero-txt">
      <div class="dsh-kicker"><i class="bi bi-stars"></i> <?php echo h(date('l, d M Y')); ?></div>
      <h1><?php echo $greet; ?><?php echo $firstName !== '' ? ', <span>' . h($firstName) . '</span>' : ''; ?></h1>
      <p><i class="bi bi-building me-1"></i><?php echo h($sch['name'] ?? 'Your school'); ?> &middot; <?php echo number_format($nStudents); ?> active student<?php echo $nStudents === 1 ? '' : 's'; ?>, <?php echo number_format($nStaff); ?> teacher<?php echo $nStaff === 1 ? '' : 's'; ?>.</p>
    </div>
    <div class="dsh-hero-act">
      <a class="dsh-btn solid" href="<?php echo h(url('admin/student_form.php')); ?>"><i class="bi bi-person-plus"></i> Add student</a>
      <a class="dsh-btn ghost" href="<?php echo h(url('admin/exam_form.php')); ?>"><i class="bi bi-journal-plus"></i> New exam</a>
      <a class="dsh-btn ghost" href="<?php echo h(url('admin/report_info_view.php')); ?>"><i class="bi bi-clipboard2-check"></i> Results</a>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/students.php')); ?>">
      <span class="ic c-blue"><i class="bi bi-people-fill"></i></span>
      <span class="body">
        <span class="v"><?php echo number_format($nStudents); ?></span>
        <span class="k">Students</span>
        <span class="s"><?php echo number_format($nStudentsAll); ?> on the register</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/classes.php')); ?>">
      <span class="ic c-green"><i class="bi bi-building"></i></span>
      <span class="body">
        <span class="v"><?php echo number_format($nClasses); ?></span>
        <span class="k">Classes</span>
        <span class="s"><i class="bi bi-book me-1"></i><?php echo number_format($nSubjects); ?> subjects</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/teachers.php')); ?>">
      <span class="ic c-violet"><i class="bi bi-person-badge"></i></span>
      <span class="body">
        <span class="v"><?php echo number_format($nStaff); ?></span>
        <span class="k">Staff</span>
        <span class="s">Active teachers</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/exams.php')); ?>">
      <span class="ic c-amber"><i class="bi bi-journal-text"></i></span>
      <span class="body">
        <span class="v"><?php echo number_format($nExams); ?></span>
        <span class="k">Exams</span>
        <span class="s">For this school</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="dsh-card">
      <div class="dsh-card-h">
        <span class="t"><i class="bi bi-bar-chart-steps"></i> Class overview</span>
        <a class="lnk" href="<?php echo h(url('admin/classes.php')); ?>">Manage classes <i class="bi bi-arrow-right-short"></i></a>
      </div>
      <div class="dsh-card-b">
        <?php if ($classRows): ?>
          <?php foreach ($classRows as $cr): $n = (int)$cr['n']; $w = $n > 0 ? max(4, (int)round($n / $maxClass * 100)) : 0; ?>
            <div class="dsh-bar">
              <div class="dsh-bar-t">
                <a class="nm" href="<?php echo h(url('admin/students.php?class_id=' . (int)$cr['id'])); ?>"><i class="bi bi-<?php echo ($cr['level'] ?? 'O') === 'A' ? 'mortarboard' : 'book'; ?>"></i><?php echo h($cr['name']); ?></a>
                <span class="pv"><?php echo number_format($n); ?> student<?php echo $n === 1 ? '' : 's'; ?></span>
              </div>
              <div class="dsh-track"><div class="dsh-fill<?php echo ($cr['level'] ?? 'O') === 'A' ? ' a' : ''; ?><?php echo $n ? '' : ' zero'; ?>" style="width:<?php echo $n ? $w : 100; ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="dsh-empty"><i class="bi bi-building"></i>No classes yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="dsh-card">
      <div class="dsh-card-h">
        <span class="t"><i class="bi bi-journal-text"></i> Recent exams</span>
        <a class="lnk" href="<?php echo h(url('admin/exams.php')); ?>">All exams <i class="bi bi-arrow-right-short"></i></a>
      </div>
      <div class="dsh-card-b">
        <?php if ($examRows): ?>
          <?php foreach ($examRows as $e): ?>
            <div class="dsh-exam">
              <span class="ic"><i class="bi bi-file-earmark-text"></i></span>
              <div class="min-w-0">
                <div class="nm text-truncate" style="max-width:200px"><?php echo h($e['name']); ?></div>
                <div class="mt">
                  <span class="dsh-badge <?php echo $e['status'] === 'open' ? 'open' : 'closed'; ?>"><?php echo $e['status'] === 'open' ? 'Open' : 'Closed'; ?></span>
                  <?php if ($e['published']): ?><span class="dsh-badge pub">Published</span><?php endif; ?>
                  <span><?php echo (int)$e['cands']; ?> candidates</span>
                  <span>&middot;</span>
                  <span><?php echo (int)$e['n_scores']; ?> scores</span>
                  <?php if ($e['avg_score'] !== null): ?><span>&middot;</span><span>avg <?php echo h(number_format((float)$e['avg_score'], 1)); ?></span><?php endif; ?>
                </div>
              </div>
              <a class="go" href="<?php echo h(url('admin/exam_view.php?id=' . (int)$e['id'])); ?>">Manage <i class="bi bi-chevron-right"></i></a>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="dsh-empty"><i class="bi bi-inbox"></i>No exams yet. <a class="fw-bold text-decoration-none" href="<?php echo h(url('admin/exam_form.php')); ?>">Create one</a>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="dsh-card">
      <div class="dsh-card-h">
        <span class="t"><i class="bi bi-person-vcard"></i> Newest students</span>
        <a class="lnk" href="<?php echo h(url('admin/students.php')); ?>">All students <i class="bi bi-arrow-right-short"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table dsh-table align-middle">
          <thead><tr><th class="ps-3">Student</th><th>Class</th><th>Status</th><th class="text-end pe-3">Edit</th></tr></thead>
          <tbody>
          <?php foreach ($recentStudents as $st): ?>
            <tr>
              <td class="ps-3">
                <div class="d-flex align-items-center gap-2">
                  <span class="dsh-av"><?php echo h($ini(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))); ?></span>
                  <div class="min-w-0">
                    <div class="fw-semibold text-truncate" style="max-width:180px"><?php echo h(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))); ?></div>
                    <div class="small text-muted"><?php echo h($st['admission_no'] ?? ''); ?></div>
                  </div>
                </div>
              </td>
              <td class="small"><?php echo h(preg_replace('/^Form\s+/i', '', (string)$st['cname'])); ?><?php echo ($st['stream'] ?? '') !== '' ? ' / ' . h($st['stream']) : ''; ?></td>
              <td><span class="dsh-badge <?php echo $st['status'] === 'active' ? 'open' : 'closed'; ?>"><?php echo h(ucfirst((string)$st['status'])); ?></span></td>
              <td class="text-end pe-3">
                <a class="btn btn-outline-primary btn-sm" href="<?php echo h(url('admin/student_form.php?id=' . (int)$st['id'])); ?>" title="Edit student"><i class="bi bi-pencil"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentStudents): ?>
            <tr><td colspan="4"><div class="dsh-empty"><i class="bi bi-inbox"></i>No students registered yet.</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="dsh-card">
      <div class="dsh-card-h">
        <span class="t"><i class="bi bi-person-badge"></i> Staff</span>
        <a class="lnk" href="<?php echo h(url('admin/teachers.php')); ?>">All staff <i class="bi bi-arrow-right-short"></i></a>
      </div>
      <div class="dsh-card-b">
        <?php if ($staffRows): ?>
          <ul class="dsh-list">
          <?php foreach ($staffRows as $sf): ?>
            <li class="dsh-li">
              <span class="dsh-av round"><?php echo h($ini($sf['full_name'])); ?></span>
              <div class="min-w-0">
                <div class="nm text-truncate" style="max-width:170px"><?php echo h($sf['full_name']); ?></div>
                <div class="mt">@<?php echo h($sf['username'] ?? '—'); ?> &middot; <?php echo h($sf['role'] === 'teacher' ? 'Teacher' : ucfirst((string)$sf['role'])); ?></div>
              </div>
              <a class="act" href="<?php echo h(url('admin/teacher_form.php?id=' . (int)$sf['id'])); ?>" title="Edit staff"><i class="bi bi-pencil"></i></a>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="dsh-empty"><i class="bi bi-person-x"></i>No staff added yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/classes.php')); ?>">
      <span class="ic c-green"><i class="bi bi-book"></i></span>
      <span class="body">
        <span class="k" style="margin-top:0">Subjects by class</span>
        <span class="s">Subjects taught in each class</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/assignments.php')); ?>">
      <span class="ic c-violet"><i class="bi bi-diagram-3"></i></span>
      <span class="body">
        <span class="k" style="margin-top:0">Teacher assignments</span>
        <span class="s">Teachers to classes and subjects</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/report_info_view.php')); ?>">
      <span class="ic c-blue"><i class="bi bi-clipboard2-check"></i></span>
      <span class="body">
        <span class="k" style="margin-top:0">Results</span>
        <span class="s">Published results and candidates</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="dsh-stat" href="<?php echo h(url('admin/settings.php')); ?>">
      <span class="ic c-amber"><i class="bi bi-gear"></i></span>
      <span class="body">
        <span class="k" style="margin-top:0">School profile</span>
        <span class="s">Name, logo and school details</span>
      </span>
      <i class="bi bi-arrow-right go"></i>
    </a>
  </div>
</div>

<?php layout_end();
