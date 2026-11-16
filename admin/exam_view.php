<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$u = require_school_admin();
$school_id = active_school_id();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$exam = get_exam($id);
if (!$exam || !exam_has_school($id, $school_id)) { flash('danger', 'This exam is not part of this school.'); redirect('admin/exams.php'); }
$isOwner = (int)$exam['owner_school_id'] === $school_id;       // owner of a school exam
$published = is_published($id, $school_id);
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'register') { $n = register_candidates($id, $school_id); flash('success', "New candidates registered and given numbers: <b>$n</b>."); }
        elseif ($a === 'toggle_status' && ($isOwner || $u['role'] === 'super')) { q('UPDATE exams SET status = ? WHERE id = ?', [$exam['status'] === 'open' ? 'closed' : 'open', $id]); flash('success', $exam['status'] === 'open' ? 'Exam closed.' : 'Exam reopened.'); }
        elseif ($a === 'toggle_publish') {
            if (!$published && !(int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ?', [$id, $school_id])) flash('danger', 'No scores have been entered; you cannot publish.');
            else { q('UPDATE exam_schools SET published = ? WHERE exam_id = ? AND school_id = ?', [$published ? 0 : 1, $id, $school_id]); flash('success', $published ? 'Results have been hidden from students.' : 'Results published; students of this school can now see them.'); }
        } elseif ($a === 'delete' && $isOwner) {
            if (($_POST['confirm'] ?? '') !== 'DELETE') flash('danger', 'Type DELETE to confirm.');
            else { q('DELETE FROM exams WHERE id = ? AND owner_school_id = ?', [$id, $school_id]); flash('success', 'Exam deleted together with its scores.'); redirect('admin/exams.php'); }
        }
    } catch (Exception $e) { flash('danger', h($e->getMessage())); }
    redirect('admin/exam_view.php?id=' . $id);
}
$classes = exam_class_list($id);
$classSubs = []; foreach ($classes as $c) $classSubs[(int)$c['id']] = exam_subject_list($id, (int)$c['id']);
$candCount = []; foreach (q_all('SELECT class_id, COUNT(*) n FROM exam_candidates WHERE exam_id = ? AND school_id = ? GROUP BY class_id', [$id, $school_id]) as $r) $candCount[(int)$r['class_id']] = (int)$r['n'];
$regCount = [];
foreach (q_all("SELECT st.class_id, ss.subject_id, COUNT(*) n FROM student_subjects ss JOIN students st ON st.id = ss.student_id
                WHERE st.school_id = ? AND st.status = 'active' GROUP BY st.class_id, ss.subject_id", [$school_id]) as $r) {
    $regCount[(int)$r['class_id']][(int)$r['subject_id']] = (int)$r['n'];
}
$subjectRows = [];
foreach ($classes as $c) {
    $cid = (int)$c['id'];
    foreach ($classSubs[$cid] ?? [] as $s) {
        $subjectRows[] = [
            'class_id' => $cid,
            'class_name' => $c['name'],
            'subject_id' => (int)$s['id'],
            'subject_code' => $s['code'],
            'subject_name' => $s['name'],
            'candidates' => $candCount[$cid] ?? 0,
            'registered' => $regCount[$cid][(int)$s['id']] ?? 0,
        ];
    }
}

layout_start('Exam: ' . $exam['name'], 'exams'); ?>

<style>
.ev-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.ev-hero::before,.ev-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.ev-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.ev-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.ev-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.ev-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.ev-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.ev-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.ev-hero h1 span{color:#ffd764}
.ev-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.ev-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.ev-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.ev-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.ev-btn:hover{transform:translateY(-1px)}
.ev-btn.solid{background:#fff;color:#0b3d91}
.ev-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.ev-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.ev-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.ev-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06);margin-bottom:16px}
.ev-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.ev-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.ev-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.ev-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.ev-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}
.ev-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.ev-table tbody td{border-color:#f2f5fb;vertical-align:middle}
.ev-table tbody tr:hover{background:#fafcff}
@media (max-width:575.98px){
  .ev-hero{padding:18px;border-radius:18px}
  .ev-hero h1{font-size:1.2rem}
  .ev-date{margin-left:0}
}
</style>

<div class="ev-hero">
    <div class="ev-head">
        <span class="ev-mark"><i class="bi bi-clipboard2-check"></i></span>
        <div>
            <div class="ev-kicker">Exam</div>
            <h1><?php echo h($exam['name']); ?></h1>
            <p class="ev-sub">
                <?php echo h($exam['exam_type'] . ' - ' . $exam['year_name']); ?>
                &middot; <span class="badge bg-<?php echo $exam['status'] === 'open' ? 'success' : 'secondary'; ?>"><?php echo h(ucfirst($exam['status'])); ?></span>
                <?php echo $exam['owner_school_id'] ? '' : ' &middot; <span class="badge bg-primary">district</span>'; ?>
                &middot; <?php echo $published ? '<span class="badge bg-info">published to students</span>' : '<span class="badge bg-light text-dark border">not published</span>'; ?>
            </p>
        </div>
        <span class="ev-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="ev-actions">
        <a class="ev-btn ghost" href="exams.php"><i class="bi bi-arrow-left"></i> Back to exams</a>
        <?php if ($isOwner): ?>
        <a class="ev-btn ghost" href="exam_form.php?id=<?php echo $id; ?>"><i class="bi bi-pencil"></i> Edit</a>
        <button type="button" class="ev-btn ghost" data-bs-toggle="modal" data-bs-target="#deleteExamModal"><i class="bi bi-trash"></i> Delete</button>
        <?php endif; ?>
        <form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="register"><button class="ev-btn solid"><i class="bi bi-person-plus"></i> Register candidates</button></form>
        <?php if ($isOwner || $u['role'] === 'super'): ?><form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="toggle_status"><button class="ev-btn ghost"><?php echo $exam['status'] === 'open' ? 'Close exam' : 'Open exam'; ?></button></form><?php endif; ?>
        <?php if ($isOwner): ?><form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="toggle_publish"><button class="ev-btn <?php echo $published ? 'ghost' : 'solid'; ?>"><?php echo $published ? 'Unpublish' : 'Publish to students'; ?></button></form><?php endif; ?>
    </div>
</div>

<?php if (!$isOwner && $exam['status'] === 'closed'): ?><div class="alert alert-warning py-2 small">This district exam is closed; teachers cannot change scores. Contact the district office.</div><?php endif; ?>

<div class="ev-card">
    <div class="card-body py-3">
    <?php $firstClass = true; foreach ($classes as $c): $subs = $classSubs[(int)$c['id']] ?? []; ?>
        <div class="<?php echo $firstClass ? '' : 'mt-3 pt-3 border-top'; ?>">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 pb-2 border-bottom">
                <span class="fw-bold fs-5"><i class="bi bi-mortarboard me-1"></i><?php echo h($c['name']); ?></span>
                <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2"><i class="bi bi-book me-1"></i><?php echo count($subs); ?> subject<?php echo count($subs) === 1 ? '' : 's'; ?></span>
                <div class="ms-auto d-flex flex-wrap gap-1">
                    <a class="btn btn-outline-primary btn-sm py-0" href="../results.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$c['id']; ?>">Results</a>
                    <?php if ($firstClass): ?>
                    <a class="btn btn-outline-info btn-sm py-0" href="exam_progress.php?exam_id=<?php echo $id; ?>" title="View score entry progress per subject"><i class="bi bi-bar-chart-line"></i> Score Progress</a>
                    <?php endif; ?>
                    <a class="btn btn-outline-success btn-sm py-0" href="exam_download.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$c['id']; ?>&type=cal" title="Collective Attendance List"><i class="bi bi-file-earmark-spreadsheet"></i> CAL</a>
                    <a class="btn btn-outline-success btn-sm py-0" href="exam_download.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$c['id']; ?>&type=stats" title="Registered candidates statistics for this class"><i class="bi bi-file-earmark-bar-graph"></i> Stats</a>
                    <a class="btn btn-outline-success btn-sm py-0" href="exam_download.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$c['id']; ?>&type=sitting" title="Sitting plan"><i class="bi bi-file-earmark-text"></i> Sitting</a>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($subs as $s): ?>
                <span class="btn btn-sm btn-light border py-0 px-2 text-dark"><span class="badge bg-secondary-subtle text-secondary-emphasis me-1"><?php echo h($s['code']); ?></span><?php echo h($s['name']); ?></span>
            <?php endforeach; ?>
            <?php if (!$subs): ?><span class="text-muted small">No subject chosen.</span><?php endif; ?>
            </div>
        </div>
    <?php $firstClass = false; endforeach; ?>
    <?php if (!$classes): ?><div class="text-muted small">No class found.</div><?php endif; ?>
    </div>
</div>

<div class="ev-card">
    <div class="ev-card-h d-flex justify-content-between align-items-center">
        <span class="ev-card-t"><i class="bi bi-book text-primary"></i> Subjects registered for this exam</span>
        <span class="text-muted small"><?php echo count($subjectRows); ?> entr<?php echo count($subjectRows) === 1 ? 'y' : 'ies'; ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 ev-table">
            <thead><tr><th class="ps-3">Class</th><th>Code</th><th>Subject</th><th title="Students enrolled in the subject">Enrolled</th><th class="text-end pe-3">Vitendo</th></tr></thead>
            <tbody>
            <?php foreach ($subjectRows as $sr): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?php echo h($sr['class_name']); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo h($sr['subject_code']); ?></span></td>
                    <td><?php echo h($sr['subject_name']); ?></td>
                    <td>
                        <?php if ((int)$sr['registered'] > 0): ?>
                            <span class="badge bg-primary" title="Students enrolled in this subject"><?php echo (int)$sr['registered']; ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border" title="No subject enrolment; ISAL and template use all class candidates">all (<?php echo (int)$sr['candidates']; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3 text-nowrap">
                        <a class="btn btn-outline-primary btn-sm py-0" href="subject_students.php?id=<?php echo (int)$sr['subject_id']; ?>&class_id=<?php echo (int)$sr['class_id']; ?>&exam_id=<?php echo $id; ?>" title="Enrol / remove students for this subject"><i class="bi bi-people"></i> Enrolment</a>
                        <a class="btn btn-outline-success btn-sm py-0" href="exam_download.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$sr['class_id']; ?>&subject_id=<?php echo (int)$sr['subject_id']; ?>&type=isal" title="Download ISAL for this subject"><i class="bi bi-download"></i> ISAL</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$subjectRows): ?><tr><td colspan="5" class="text-center text-muted py-3">No subject registered for this exam.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted py-2">
        <i class="bi bi-info-circle me-1"></i> ISAL, score template and score entry use students enrolled in the subject. Click <b>Enrolment</b> to change the list.
    </div>
</div>

<?php if ($isOwner): ?>
<div class="modal fade" id="deleteExamModal" tabindex="-1" aria-labelledby="deleteExamModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="delete">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteExamModalLabel">Delete exam</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">This will delete <strong><?php echo h($exam['name']); ?></strong> together with all its scores. This action cannot be undone.</p>
                    <label class="form-label small">Type <strong>DELETE</strong> to confirm</label>
                    <input name="confirm" class="form-control form-control-sm" placeholder="DELETE" autocomplete="off" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger btn-sm">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showIsalToast(msg) {
  var top = document.querySelector('.flash-top');
  if (!top) {
    top = document.createElement('div');
    top.className = 'flash-top no-print';
    document.body.appendChild(top);
  }
  var t = document.createElement('div');
  t.className = 'flash-toast flash-info';
  t.setAttribute('role', 'status');
  t.innerHTML = '<span class="flash-ic"><i class="bi bi-info-circle-fill"></i></span>'
    + '<div class="flash-msg"></div>'
    + '<button type="button" class="flash-x" aria-label="Close">&times;</button>';
  t.querySelector('.flash-msg').textContent = msg || 'No student is enrolled in this subject for this class.';
  top.appendChild(t);
  function kill() {
    if (t.classList.contains('hide')) return;
    t.classList.add('hide');
    setTimeout(function () { t.remove(); }, 260);
  }
  var x = t.querySelector('.flash-x');
  if (x) x.addEventListener('click', kill);
  setTimeout(kill, 4500);
}
(function () {
  document.querySelectorAll('a[href*="type=isal"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var url = a.getAttribute('href');
      var checkUrl = url + (url.indexOf('?') > -1 ? '&' : '?') + 'check=1';
      fetch(checkUrl, {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            window.location.href = url;
          } else {
            showIsalToast((d && d.message) ? d.message : 'No student is enrolled in this subject for this class.');
          }
        })
        .catch(function () {
          window.location.href = url;
        });
    });
  });
})();
</script>

<?php layout_end(); ?>
