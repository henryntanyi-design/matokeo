<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$exam = get_exam($id);
if (!$exam) redirect('super/exams.php');
if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? ''; $sid = (int)($_POST['school_id'] ?? 0);
    try {
        if ($a === 'toggle_status') { q('UPDATE exams SET status = ? WHERE id = ?', [$exam['status'] === 'open' ? 'closed' : 'open', $id]); flash('success', $exam['status'] === 'open' ? 'Exam closed; teachers can no longer change scores.' : 'Exam opened.'); }
        elseif ($a === 'register_all') { $n = 0; foreach (exam_school_list($id) as $sc) $n += register_candidates($id, $sc['id']); flash('success', "New candidates registered in all schools: <b>$n</b>."); }
        elseif ($a === 'toggle_publish') {
            if ($exam['owner_school_id']) flash('danger', 'This is a school exam; only that school\'s admin can publish its results.');
            elseif (!exam_has_school($id, $sid)) flash('danger', 'That school does not take part in this exam.');
            else {
                $cur = is_published($id, $sid);
                if (!$cur && !(int)q_val('SELECT COUNT(*) FROM scores WHERE exam_id = ? AND school_id = ?', [$id, $sid])) flash('danger', 'This school has no scores yet; it cannot be published.');
                else { q('UPDATE exam_schools SET published = ? WHERE exam_id = ? AND school_id = ?', [$cur ? 0 : 1, $id, $sid]); flash('success', $cur ? 'Publication removed.' : 'Results for that school have been published.'); }
            }
        } elseif ($a === 'publish_all') {
            if ($exam['owner_school_id']) flash('danger', 'This is a school exam; only that school\'s admin can publish its results.');
            else {
                $withScores = (int)q_val('SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = ? AND EXISTS (SELECT 1 FROM scores s WHERE s.exam_id = x.exam_id AND s.school_id = x.school_id)', [$id]);
                if (!$withScores) flash('danger', 'No school has entered scores yet; nothing to publish.');
                else {
                    $pubScored = (int)q_val('SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = ? AND x.published = 1 AND EXISTS (SELECT 1 FROM scores s WHERE s.exam_id = x.exam_id AND s.school_id = x.school_id)', [$id]);
                    if ($pubScored >= $withScores) { q('UPDATE exam_schools SET published = 0 WHERE exam_id = ?', [$id]); flash('success', "Results unpublished for <b>$withScores</b> school(s)."); }
                    else {
                        q('UPDATE exam_schools SET published = 1 WHERE exam_id = ?', [$id]);
                        flash('success', 'Results published.');
                    }
                }
            }
        } elseif ($a === 'delete') {
            if (($_POST['confirm'] ?? '') !== 'DELETE') flash('danger', 'Type DELETE to confirm.');
            else { q('DELETE FROM exams WHERE id = ?', [$id]); flash('success', 'Exam deleted together with its scores.'); redirect('super/exams.php'); }
        }
    } catch (Exception $e) { flash('danger', h($e->getMessage())); }
    redirect('super/exam_view.php?id=' . $id);
}
$classes = exam_class_list($id); $schools = exam_school_list($id);
$cand = []; foreach (q_all('SELECT school_id, COUNT(*) n FROM exam_candidates WHERE exam_id = ? GROUP BY school_id', [$id]) as $r) $cand[(int)$r['school_id']] = (int)$r['n'];
$act = []; foreach (q_all("SELECT school_id, COUNT(*) n FROM students WHERE status = 'active' GROUP BY school_id") as $r) $act[(int)$r['school_id']] = (int)$r['n'];
$scr = []; foreach (q_all('SELECT school_id, COUNT(*) n FROM scores WHERE exam_id = ? GROUP BY school_id', [$id]) as $r) $scr[(int)$r['school_id']] = (int)$r['n'];
$totCand = array_sum($cand); $totScr = array_sum($scr); $totAct = 0;
foreach ($schools as $s) $totAct += (int)($act[(int)$s['id']] ?? 0);
$nPub = 0; foreach ($schools as $s) { if ($s['published']) $nPub++; }
$isDistrict = !$exam['owner_school_id'];
$withScores = (int)q_val('SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = ? AND EXISTS (SELECT 1 FROM scores s WHERE s.exam_id = x.exam_id AND s.school_id = x.school_id)', [$id]);
$pubScored = (int)q_val('SELECT COUNT(*) FROM exam_schools x WHERE x.exam_id = ? AND x.published = 1 AND EXISTS (SELECT 1 FROM scores s WHERE s.exam_id = x.exam_id AND s.school_id = x.school_id)', [$id]);
$allPublished = $withScores > 0 && $pubScored >= $withScores;
layout_start('Exam - ' . $exam['name'], 'exams', ''); ?>
<style>
.min-w-0{min-width:0}
.ev-hero{position:relative;overflow:hidden;border-radius:16px;padding:22px 22px 20px;color:#fff;margin-bottom:14px;
  background:linear-gradient(135deg,#0b3d91 0%,#1a55b4 52%,#2c6ed5 100%);box-shadow:0 18px 38px -24px rgba(11,61,145,.95);
  display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;align-items:flex-start}
.ev-hero:before{content:"";position:absolute;right:-60px;top:-80px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.10)}
.ev-hero:after{content:"";position:absolute;right:80px;bottom:-110px;width:190px;height:190px;border-radius:50%;background:rgba(15,157,107,.28);filter:blur(5px)}
.ev-hero>*{position:relative;z-index:1}
.ev-name{font-size:1.32rem;font-weight:800;color:#fff;letter-spacing:-.01em;margin:0 0 7px}
.ev-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,.85)}
.ev-meta .badge{font-weight:700}
.ev-actions{display:flex;flex-wrap:wrap;gap:7px}
.ev-actions .btn{border-radius:10px;font-size:.78rem;font-weight:700;padding:.42rem .8rem;display:inline-flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.32);color:#fff}
.ev-actions .btn:hover{background:#fff;border-color:#fff;color:#0b3d91}
.ev-actions .btn-success{background:#1b7f4b;border-color:#1b7f4b;color:#fff}
.ev-actions .btn-success:hover{background:#146b3f;border-color:#146b3f;color:#fff}
.ev-actions .btn-outline-danger{background:rgba(255,255,255,.1);border-color:rgba(255,143,143,.6);color:#ffe3e3}
.ev-actions .btn-outline-danger:hover{background:#c92a2a;border-color:#c92a2a;color:#fff}
.ev-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0;border-bottom:1px solid #eef2fa}
.ev-stat{display:flex;align-items:center;gap:11px;padding:14px 18px;border-right:1px solid #f0f3fa}
.ev-stat:last-child{border-right:0}
.ev-stat .ic{flex:0 0 38px;height:38px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:.95rem}
.ev-blue{background:linear-gradient(135deg,#0b3d91,#2c6ed5)}
.ev-indigo{background:linear-gradient(135deg,#3b3f9e,#6a5cf0)}
.ev-green{background:linear-gradient(135deg,#0e7a55,#19b37d)}
.ev-amber{background:linear-gradient(135deg,#b06a00,#e8a019)}
.ev-stat .v{font-size:1.2rem;font-weight:800;line-height:1.1;color:#16203a}
.ev-stat .k{font-size:.68rem;font-weight:700;color:#8a99b0;text-transform:uppercase;letter-spacing:.04em}
.ev-card-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:.85rem 1.05rem;border-bottom:1px solid #eef2fa}
.ev-card-h .t{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px}
.ev-card-h .t i{color:#2c6ed5}
.ev-class{border:1px solid #e9eef8;border-radius:13px;padding:12px 13px;margin-bottom:10px;background:#fbfcff}
.ev-class:last-child{margin-bottom:0}
.ev-class-h{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:9px}
.ev-chip{display:inline-flex;align-items:center;gap:6px;font-size:.75rem;background:#fff;border:1px solid #e2e9f6;border-radius:999px;padding:3px 10px;color:#33415c}
.ev-chip b{font-size:.66rem;font-weight:800;color:#0b3d91;background:#eaf1fd;border-radius:5px;padding:1px 5px}
.ev-pill{display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;border-radius:999px;padding:3px 9px;background:#f2f5fc;color:#4a5b78;border:1px solid #e5ebf7}
.ev-pill i{font-size:.72rem;color:#8a99b0}
.ev-av{flex:0 0 32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:.82rem;color:#0b3d91;background:#eaf1fd;border:1px solid #d9e6fb}
@media(max-width:575px){.ev-hero{padding:16px}.ev-name{font-size:1.12rem}.ev-stat{padding:12px 14px}}
</style>

<div class="ev-hero">
    <div class="min-w-0">
      <h1 class="ev-name"><?php echo h($exam['name']); ?></h1>
      <div class="ev-meta">
        <span><i class="bi bi-journal-text me-1"></i><?php echo h($exam['exam_type']); ?></span>
        <span>&middot;</span>
        <span><i class="bi bi-calendar3 me-1"></i><?php echo h($exam['year_name']); ?></span>
        <span class="badge <?php echo $exam['status'] === 'open' ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'; ?>"><?php echo $exam['status'] === 'open' ? 'Open' : 'Closed'; ?></span>
        <?php echo $exam['owner_school_id'] ? '<span class="badge bg-light text-dark border">School exam</span>' : '<span class="badge bg-primary-subtle text-primary-emphasis"><i class="bi bi-geo-alt me-1"></i>District</span>'; ?>
      </div>
    </div>
    <div class="ev-actions">
      <a class="btn btn-outline-secondary" href="exam_form.php?id=<?php echo $id; ?>"><i class="bi bi-pencil"></i>Edit</a>
      <a class="btn btn-outline-primary" href="results_analysis.php?exam_id=<?php echo $id; ?>" title="Rankings and analysis of all published schools"><i class="bi bi-bar-chart-line"></i>Analysis</a>
      <?php if ($isDistrict): ?>
      <form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="publish_all"><button class="btn <?php echo $allPublished ? 'btn-outline-success' : 'btn-success'; ?>" title="Publish results for every school with scores in this district exam"><i class="bi bi-send-check"></i><?php echo $allPublished ? 'Unpublish all results' : 'Publish all results'; ?></button></form>
      <?php endif; ?>
      <form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="register_all"><button class="btn btn-outline-primary"><i class="bi bi-person-plus"></i>Register all candidates</button></form>
      <form method="post" class="m-0"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="action" value="toggle_status"><button class="btn btn-outline-warning"><i class="bi bi-<?php echo $exam['status'] === 'open' ? 'lock' : 'unlock'; ?>"></i><?php echo $exam['status'] === 'open' ? 'Close exam' : 'Open exam'; ?></button></form>
      <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteExamModal"><i class="bi bi-trash"></i>Delete</button>
    </div>
</div>

<div class="card mb-3">
  <div class="ev-stats" style="border-bottom:0">
    <div class="ev-stat"><span class="ic ev-blue"><i class="bi bi-mortarboard"></i></span><span><span class="d-block v"><?php echo count($classes); ?></span><span class="d-block k">Classes</span></span></div>
    <div class="ev-stat"><span class="ic ev-indigo"><i class="bi bi-buildings"></i></span><span><span class="d-block v"><?php echo count($schools); ?></span><span class="d-block k">Schools</span></span></div>
    <div class="ev-stat"><span class="ic ev-green"><i class="bi bi-people"></i></span><span><span class="d-block v"><?php echo $totCand; ?></span><span class="d-block k">Candidates</span></span></div>
    <div class="ev-stat"><span class="ic ev-amber"><i class="bi bi-clipboard-check"></i></span><span><span class="d-block v"><?php echo $totScr; ?></span><span class="d-block k">Scores</span></span></div>
  </div>
</div>

<div class="card mb-3">
  <div class="ev-card-h">
    <span class="t"><i class="bi bi-mortarboard"></i> Classes involved</span>
    <span class="ev-pill"><i class="bi bi-hash"></i><?php echo count($classes); ?></span>
  </div>
  <div class="card-body py-3">
    <?php foreach ($classes as $c): $subs = exam_subject_list($id, (int)$c['id']); ?>
      <div class="ev-class">
        <div class="ev-class-h">
          <a class="btn btn-outline-primary btn-sm" href="council.php?exam_id=<?php echo $id; ?>&class_id=<?php echo (int)$c['id']; ?>"><i class="bi bi-mortarboard me-1"></i><?php echo h($c['name']); ?></a>
          <span class="ev-pill"><i class="bi bi-book"></i><?php echo count($subs); ?> subject<?php echo count($subs) === 1 ? '' : 's'; ?></span>
          <span class="text-muted small">Subjects registered for this exam</span>
        </div>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($subs as $s): ?><span class="ev-chip"><b><?php echo h($s['code']); ?></b><?php echo h($s['name']); ?></span><?php endforeach; ?>
          <?php if (!$subs): ?><span class="text-muted small">No subjects chosen.</span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; if (!$classes) echo '<span class="text-muted small">No classes found.</span>'; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="ev-card-h">
    <span class="t"><i class="bi bi-buildings"></i> Participants <span class="ev-pill ms-1"><?php echo count($schools); ?></span></span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="ev-pill"><i class="bi bi-check2-circle"></i><?php echo $nPub; ?> published</span>
      <?php if (!$isDistrict): ?><span class="badge bg-light text-dark border" title="School exams are published by the school admin"><i class="bi bi-info-circle me-1"></i>Published by school admin</span><?php endif; ?>
      <a class="btn btn-outline-success btn-sm" href="../admin/exam_download.php?exam_id=<?php echo $id; ?>&type=stats_all" title="Download combined candidate statistics for all schools with their registered subjects"><i class="bi bi-download"></i> Registered stats (all schools)</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th class="ps-3">School</th><th>Qualified students</th><th>Candidates</th><th>Scores</th><th>Published</th><th class="text-end pe-3">Action</th></tr></thead>
      <tbody>
      <?php foreach ($schools as $s): $sid = (int)$s['id']; ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <span class="ev-av"><?php echo h(strtoupper(mb_substr($s['name'], 0, 1))); ?></span>
              <span class="min-w-0">
                <span class="d-block fw-semibold text-truncate" style="max-width:240px"><?php echo h($s['name']); ?></span>
                <span class="d-block small text-muted"><?php echo h(school_centre($s)); ?></span>
              </span>
            </div>
          </td>
          <td><span class="ev-pill"><i class="bi bi-people"></i><?php echo (int)($act[$sid] ?? 0); ?></span></td>
          <td><span class="ev-pill"><i class="bi bi-person-check"></i><?php echo (int)($cand[$sid] ?? 0); ?></span></td>
          <td><span class="ev-pill"><i class="bi bi-clipboard-check"></i><?php echo (int)($scr[$sid] ?? 0); ?></span></td>
          <td><?php echo $s['published'] ? '<span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check2 me-1"></i>Yes</span>' : '<span class="badge bg-light text-dark border">No</span>'; ?></td>
          <td class="text-end pe-3 text-nowrap">
            <form method="post" action="schools.php" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="enter"><input type="hidden" name="id" value="<?php echo $sid; ?>"><button class="btn btn-outline-primary btn-sm py-0"><i class="bi bi-box-arrow-up-right me-1"></i>Open</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="4" class="ps-3 text-muted small">Qualified active students across participating schools: <b><?php echo $totAct; ?></b></td><td colspan="2" class="text-end pe-3"><a class="small fw-semibold text-decoration-none" href="exams.php">All exams <i class="bi bi-arrow-right-short"></i></a></td></tr>
      </tbody>
    </table>
  </div>
</div>

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
                    <p class="mb-2">This will delete <strong><?php echo h($exam['name']); ?></strong> together with its scores for all schools. This action cannot be undone.</p>
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

<?php layout_end();
