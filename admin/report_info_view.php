<?php
/** View saved report preliminary information using the same layout as the report info form. */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
report_info_table();

$classes = q_all('SELECT id, name FROM classes ORDER BY sort_order, id');
$terms = ['I' => 'Kwanza', 'II' => 'Pili'];

$rows = [];
try {
    $rows = q_all("SELECT ri.*, c.name AS class_name,
                          ct.full_name AS class_teacher, ct.phone AS class_phone,
                          st.full_name AS subject_teacher, st.phone AS subject_phone,
                          hm.full_name AS headmaster, hm.phone AS head_phone
                   FROM report_info ri
                   LEFT JOIN classes c ON c.id = ri.class_id
                   LEFT JOIN users ct ON ct.id = ri.class_teacher_id
                   LEFT JOIN users st ON st.id = ri.subject_teacher_id
                   LEFT JOIN users hm ON hm.id = ri.headmaster_id
                   WHERE ri.school_id = ?
                   ORDER BY c.sort_order, c.id, ri.term", [$school_id]);
} catch (Exception $e) { $rows = []; }

$saved = [];
foreach ($rows as $r) $saved[(int)$r['class_id'] . '|' . $r['term']] = $r;

$class_id = (int)($_GET['class_id'] ?? 0);
if (!$class_id && $rows) $class_id = (int)$rows[0]['class_id'];
if (!$class_id && $classes) $class_id = (int)$classes[0]['id'];
$term = (string)($_GET['term'] ?? '');
if (!isset($terms[$term])) {
    $term = '';
    foreach ($terms as $tk => $tl) if (isset($saved[$class_id . '|' . $tk])) { $term = $tk; break; }
    if ($term === '') $term = 'I';
}
$ri = $saved[$class_id . '|' . $term] ?? null;

$fmtDate = function ($d) { return !empty($d) ? date('d M Y', strtotime($d)) : ''; };
$class_name = $ri['class_name'] ?? '';
if ($class_name === '') foreach ($classes as $c) if ((int)$c['id'] === $class_id) $class_name = $c['name'];
$instructions = array_values(array_filter(array_map('trim', explode("\n", (string)($ri['instructions'] ?? ''))), 'strlen'));
$termRemark = report_remarks('A');
$brand = report_branding_get($school_id);
$editUrl = 'admin/report_info.php?class_id=' . $class_id . '&term=' . urlencode($term);
layout_start('Saved Report Info', 'reportinfo'); ?>
<style>
.ri-head{position:relative;overflow:hidden;background:linear-gradient(135deg,#0b3d91 0%,#2c6ed5 60%,#3f8ae0 100%);color:#fff;border-radius:16px;padding:18px 22px;box-shadow:0 12px 28px rgba(11,61,145,.28)}
.ri-head::after{content:"";position:absolute;right:-60px;top:-80px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.08)}
.ri-head::before{content:"";position:absolute;right:70px;bottom:-110px;width:190px;height:190px;border-radius:50%;background:rgba(255,255,255,.06)}
.ri-head>*{position:relative;z-index:1}
.ri-head .k{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;font-weight:700;opacity:.85}
.ri-head .t{font-size:1.22rem;font-weight:800;letter-spacing:.01em}
.ri-head .hi{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);display:inline-flex;align-items:center;justify-content:center;font-size:1.45rem;flex:0 0 auto;backdrop-filter:blur(2px)}
.ri-head .pill{border-radius:999px;font-weight:700;font-size:.72rem;padding:.35rem .8rem;box-shadow:0 4px 10px rgba(0,0,0,.12)}
.ri-card{border:1px solid #e7ecf7;border-radius:14px;box-shadow:0 5px 16px rgba(11,61,145,.06);height:100%;transition:box-shadow .2s ease,transform .2s ease;overflow:hidden}
.ri-card:hover{box-shadow:0 14px 30px rgba(11,61,145,.13);transform:translateY(-2px)}
.ri-card .card-header{background:linear-gradient(90deg,#f7f9fe,#eef3fd);border-bottom:1px solid #e7ecf7;font-weight:800;font-size:.86rem;color:#0b3d91;letter-spacing:.03em;text-transform:uppercase;padding:.7rem 1rem}
.ri-card .card-header .n{display:inline-flex;width:22px;height:22px;border-radius:7px;background:linear-gradient(135deg,#0b3d91,#2c6ed5);color:#fff;align-items:center;justify-content:center;font-size:.72rem;margin-right:7px;box-shadow:0 3px 8px rgba(11,61,145,.25)}
.form-label{font-size:.78rem;font-weight:700;color:#33415c;margin-bottom:.25rem}
.ri-auto{background:#f7f9fe;border-color:#e2e8f6;color:#22304a;font-weight:600;box-shadow:inset 0 1px 2px rgba(11,61,145,.04)}
.ri-auto:focus{border-color:#bfd0ee;box-shadow:none}
.ri-note{font-size:.74rem;color:#6c757d}
.ri-card .card-footer{background:transparent;border-top:1px dashed #e2e8f6}
.brand-box{border:1px dashed #cfd9ee;border-radius:12px;background:#fbfcff;height:110px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.brand-box img{max-height:100px;max-width:100%;object-fit:contain}
.brand-empty{color:#9aa7bd;font-size:.72rem;text-align:center;line-height:1.3}
.brand-empty i{display:block;font-size:1.4rem;margin-bottom:2px}
.btn-action{border-radius:10px;font-weight:700;box-shadow:0 6px 14px rgba(11,61,145,.16)}
</style>

<div class="ri-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div class="d-flex align-items-center gap-3">
    <span class="hi"><i class="bi bi-clipboard2-check"></i></span>
    <div><div class="k"><i class="bi bi-file-earmark-text me-1"></i>Report Preliminary Information</div>
      <div class="t mt-1">Report Details</div>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="badge pill <?php echo $ri ? 'bg-success' : 'bg-secondary'; ?>"><i class="bi <?php echo $ri ? 'bi-check-circle' : 'bi-dash-circle'; ?> me-1"></i><?php echo $ri ? 'Saved' : 'Not saved'; ?></span>
        <?php if ($ri): ?><span class="small text-white-50"><i class="bi bi-clock-history me-1"></i>Last saved <?php echo h(date('d M Y H:i', strtotime($ri['updated_at']))); ?></span><?php endif; ?>
      </div></div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-light btn-sm fw-semibold rounded-3" href="<?php echo h(url('admin/report_info.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <a class="btn btn-warning btn-sm fw-semibold rounded-3" href="<?php echo h(url($editUrl)); ?>"><i class="bi bi-pencil-square me-1"></i>Edit</a>
  </div>
</div>

<form method="get" class="card ri-card mb-3"><div class="card-body"><div class="row g-3 align-items-end">
  <div class="col-sm-5"><label class="form-label">Term</label>
    <select name="term" class="form-select" onchange="this.form.submit()"><?php foreach ($terms as $tk => $tl): ?><option value="<?php echo h($tk); ?>"<?php echo $tk === $term ? ' selected' : ''; ?>><?php echo h($tl) . (isset($saved[$class_id . '|' . $tk]) ? ' ✓' : ''); ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-5"><label class="form-label">Class</label>
    <select name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $class_id ? ' selected' : ''; ?>><?php echo h($c['name']); ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-arrow-clockwise me-1"></i>Load</button></div>
</div><div class="ri-note mt-2">Choose the term and class to view its saved report information. A ✓ marks terms that already have saved details.</div></div></form>

<?php if (!$ri): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2">
  <span><i class="bi bi-exclamation-triangle me-1"></i>No report information has been saved for this class and term.</span>
  <a class="btn btn-warning btn-sm ms-auto" href="<?php echo h(url($editUrl)); ?>"><i class="bi bi-pencil-square me-1"></i>Fill the form</a>
</div>
<?php endif; ?>

<div class="row g-3">

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">1</span>Preliminary Information</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-sm-6 col-lg-3"><label class="form-label">Term</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($terms[$term]); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Class</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($class_name); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Closing date</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($fmtDate($ri['closing_date'] ?? '')); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Opening date</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($fmtDate($ri['opening_date'] ?? '')); ?>"></div>
      </div></div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card ri-card"><div class="card-header"><span class="n">2</span>Class Teacher</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-7"><label class="form-label">Class teacher name</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['class_teacher'] ?? ''); ?>"></div>
        <div class="col-md-5"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['class_phone'] ?? ''); ?>"></div>
        <div class="col-12"><div class="ri-note"><b>Class teacher remarks:</b> generated automatically from the student's average grade — A: “<?php echo h($termRemark['class_teacher']); ?>”; B–F follow the same pattern.</div></div>
      </div></div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card ri-card"><div class="card-header"><span class="n">3</span>Subject Teacher</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-7"><label class="form-label">Subject teacher name</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['subject_teacher'] ?? ''); ?>"></div>
        <div class="col-md-5"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['subject_phone'] ?? ''); ?>"></div>
        <div class="col-12"><div class="ri-note"><b>Subject teacher remarks:</b> generated automatically from the student's average grade (A–F).</div></div>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">4</span>Headmaster / Headmistress</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label">Headmaster name</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['headmaster'] ?? ''); ?>"></div>
        <div class="col-md-3"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($ri['head_phone'] ?? ''); ?>"></div>
        <div class="col-md-5"><div class="ri-note pt-md-4"><b>Headmaster remarks:</b> generated automatically from the student's average grade (A–F).</div></div>

        <div class="col-12"><hr class="my-1"></div>
        <?php foreach ([['school_logo', 'School logo'], ['stamp', 'School stamp'], ['signature', 'Headmaster signature']] as $bz): $bsrc = report_branding_src($brand[$bz[0]] ?? ''); ?>
        <div class="col-md-4"><label class="form-label"><?php echo h($bz[1]); ?></label>
          <div class="brand-box"><?php echo $bsrc !== '' ? '<img src="' . $bsrc . '" alt="' . h($bz[1]) . '">' : '<div class="brand-empty"><i class="bi bi-image"></i><span>No image</span></div>'; ?></div></div>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">5</span>Instructions When School Opens</div>
      <div class="card-body">
        <?php if ($instructions): ?>
        <div class="row g-3">
          <?php foreach ($instructions as $i => $line): ?>
          <div class="col-12 col-md-6"><label class="form-label">Instruction <?php echo $i + 1; ?></label>
            <input type="text" class="form-control ri-auto" readonly value="<?php echo h($line); ?>"></div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ri-note fst-italic">No instructions were provided.</div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">6</span>Automatic Remarks (by average grade)</div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th class="ps-3" style="width:70px">Grade</th><th>Class teacher remark</th><th>Subject teacher &amp; headmaster remark</th></tr></thead>
        <tbody><?php foreach (['A', 'B', 'C', 'D', 'F'] as $g): $rm = report_remarks($g); ?>
          <tr><td class="ps-3 fw-bold"><?php echo h($g); ?></td><td class="small"><?php echo h($rm['class_teacher']); ?></td><td class="small"><?php echo h($rm['subject_teacher']); ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div></div>
    </div>
  </div>

</div>

<?php layout_end();
