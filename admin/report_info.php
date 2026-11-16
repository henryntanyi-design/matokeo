<?php
/** Report preliminary information: term, class, school dates, teachers and instructions. */
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
report_info_table();

$classes = q_all('SELECT id, name FROM classes ORDER BY sort_order, id');
$staff = q_all("SELECT id, full_name, phone, position, role FROM users WHERE school_id = ? AND active = 1 ORDER BY full_name", [$school_id]);
$terms = ['I' => 'Kwanza', 'II' => 'Pili'];

$class_id = (int)($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if (!$class_id && $classes) $class_id = (int)$classes[0]['id'];
$term = (string)($_POST['term'] ?? $_GET['term'] ?? 'I');
if (!isset($terms[$term])) $term = 'I';

$staff_ids = array_map('intval', array_column($staff, 'id'));
$pick = function ($id) use ($staff_ids) { $id = (int)$id; return in_array($id, $staff_ids, true) ? $id : null; };

if (is_post()) {
    csrf_check();
    $date = function ($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $closing = $date($_POST['closing_date'] ?? '');
    $opening = $date($_POST['opening_date'] ?? '');
    if ($closing && $opening && $opening < $closing) {
        flash('danger', 'The opening date cannot be before the closing date.');
    } elseif (!$class_id || !in_array($class_id, array_map('intval', array_column($classes, 'id')), true)) {
        flash('danger', 'Please choose a valid class.');
    } else {
        // Picha za ripoti (school logo, stamp, signature) - ni za lazima
        $brandErrors = [];
        $brandFiles = (array)($_FILES['brand'] ?? []);
        $brandLabels = ['school_logo' => 'School logo', 'stamp' => 'School stamp', 'signature' => 'Headmaster signature'];
        foreach ($brandLabels as $bk => $bl) {
            try {
                $one = ['error' => $brandFiles['error'][$bk] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $brandFiles['size'][$bk] ?? 0,
                        'tmp_name' => $brandFiles['tmp_name'][$bk] ?? '',
                        'name' => $brandFiles['name'][$bk] ?? ''];
                report_branding_upload($school_id, $bk, $one);
            } catch (Exception $e) {
                $brandErrors[] = $bl . ': ' . $e->getMessage();
            }
        }
        if ($brandErrors) flash('warning', implode(' ', $brandErrors));
        $brandNow = report_branding_get($school_id);
        $missingBrand = [];
        foreach ($brandLabels as $bk => $bl) if (trim((string)($brandNow[$bk] ?? '')) === '') $missingBrand[] = $bl;
        if ($missingBrand) {
            flash('danger', 'Please upload the required report images: ' . implode(', ', $missingBrand) . '.');
        } else {
        $items = [];
        foreach ((array)($_POST['instructions'] ?? []) as $line) {
            $line = trim((string)$line);
            if ($line !== '') $items[] = $line;
            if (count($items) >= 10) break;
        }
        try {
            q('INSERT INTO report_info (school_id, class_id, term, closing_date, opening_date, class_teacher_id, subject_teacher_id, headmaster_id, instructions)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE closing_date = VALUES(closing_date), opening_date = VALUES(opening_date),
                 class_teacher_id = VALUES(class_teacher_id), subject_teacher_id = VALUES(subject_teacher_id),
                 headmaster_id = VALUES(headmaster_id), instructions = VALUES(instructions)',
              [$school_id, $class_id, $term, $closing, $opening,
               $pick($_POST['class_teacher_id'] ?? 0), $pick($_POST['subject_teacher_id'] ?? 0), $pick($_POST['headmaster_id'] ?? 0),
               implode("\n", $items)]);
            report_remarks_table();
            $rmkIn = (array)($_POST['remarks'] ?? []);
            foreach (['A', 'B', 'C', 'D', 'F'] as $g) {
                $ct = trim((string)($rmkIn[$g]['class_teacher'] ?? ''));
                $st = trim((string)($rmkIn[$g]['staff'] ?? ''));
                if ($ct === '' && $st === '') {
                    q('DELETE FROM report_remarks WHERE school_id = ? AND grade = ?', [$school_id, $g]);
                } else {
                    q('INSERT INTO report_remarks (school_id, grade, class_teacher, subject_teacher, headmaster)
                       VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE class_teacher = VALUES(class_teacher), subject_teacher = VALUES(subject_teacher),
                         headmaster = VALUES(headmaster)',
                      [$school_id, $g, $ct !== '' ? $ct : null, $st !== '' ? $st : null, $st !== '' ? $st : null]);
                }
            }
            flash('success', 'Report information saved for the ' . ($term === 'II' ? 'Second Term' : 'First Term') . '.');
        } catch (PDOException $e) {
            flash('danger', 'Could not save: ' . h($e->getMessage()));
        }
        }
    }
    redirect('admin/report_info.php?class_id=' . $class_id . '&term=' . urlencode($term));
}

$ri = report_info_get($school_id, $class_id, $term);
$hmDefault = 0;
foreach ($staff as $s) if (strtolower((string)$s['position']) === 'headmaster') { $hmDefault = (int)$s['id']; break; }
if (!$hmDefault) foreach ($staff as $s) if ($s['role'] === 'admin') { $hmDefault = (int)$s['id']; break; }
$v = function ($k, $d = '') use ($ri) { return h($ri[$k] ?? $d); };
$instructions = array_values(array_filter(array_map('trim', explode("\n", (string)($ri['instructions'] ?? ''))), 'strlen'));
$instructions = array_slice($instructions, 0, 10);
if (!$instructions) $instructions[] = '';
$teachers = array_values(array_filter($staff, function ($s) { return $s['role'] !== 'super'; }));
$opts = function ($selected, $emptyLabel) use ($teachers) {
    $out = '<option value="">' . h($emptyLabel) . '</option>';
    foreach ($teachers as $t) {
        $out .= '<option value="' . (int)$t['id'] . '" data-phone="' . h((string)$t['phone']) . '"' . ((int)$selected === (int)$t['id'] ? ' selected' : '') . '>'
              . h(strtoupper($t['full_name'])) . '</option>';
    }
    return $out;
};
$dval = function ($k) use ($ri) { return !empty($ri[$k]) ? date('Y-m-d', strtotime($ri[$k])) : ''; };
$termRemark = report_remarks('A');
$brand = report_branding_get($school_id);
$brandSlot = function ($key, $label) use ($brand) {
    $src = report_branding_src($brand[$key] ?? '');
    $out = '<div class="col-sm-6 col-lg-4"><label class="form-label">' . h($label) . ' <span class="text-danger">*</span></label>';
    $out .= '<div class="brand-box">';
    $out .= $src !== '' ? '<img src="' . $src . '" alt="' . h($label) . '" class="brand-img">' : '<div class="brand-empty"><i class="bi bi-image"></i><span>No image</span></div>';
    $out .= '</div>';
    $out .= '<div class="brand-slot" data-key="' . h($key) . '">';
    $out .= '<div class="input-group input-group-sm mt-2">';
    $out .= '<input type="file" name="brand[' . h($key) . ']" class="form-control brand-file" accept="image/png,image/jpeg,image/gif,image/webp"' . ($src === '' ? ' required' : '') . '>';
    $out .= '<button type="button" class="btn btn-primary brand-upload" title="Upload"><i class="bi bi-upload"></i></button>';
    $out .= '<button type="button" class="btn btn-outline-danger brand-delete" title="Delete"' . ($src === '' ? ' style="display:none"' : '') . '><i class="bi bi-trash"></i></button>';
    $out .= '</div>';
    $out .= '<div class="ri-note mt-1 brand-status"></div>';
    $out .= '</div></div>';
    return $out;
};
layout_start('Report Info', 'reportinfo'); ?>
<div id="riFlash"></div>
<style>
.ri-head{background:linear-gradient(135deg,#0b3d91,#2c6ed5);color:#fff;border-radius:16px;padding:18px 22px;box-shadow:0 10px 22px rgba(11,61,145,.2)}
.ri-head .k{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;font-weight:700;opacity:.85}
.ri-head .t{font-size:1.22rem;font-weight:800}
.ri-card{border:1px solid #e7ecf7;border-radius:14px;box-shadow:0 5px 16px rgba(11,61,145,.06);height:100%}
.ri-card .card-header{background:#f7f9fe;border-bottom:1px solid #e7ecf7;font-weight:800;font-size:.86rem;color:#0b3d91;letter-spacing:.03em;text-transform:uppercase}
.ri-card .card-header .n{display:inline-flex;width:22px;height:22px;border-radius:7px;background:#0b3d91;color:#fff;align-items:center;justify-content:center;font-size:.72rem;margin-right:7px}
.form-label{font-size:.78rem;font-weight:700;color:#33415c;margin-bottom:.25rem}
.ri-auto{background:#f4f6fb;color:#33415c;font-weight:600}
.ri-note{font-size:.74rem;color:#6c757d}
.brand-box{border:1px dashed #cfd9ee;border-radius:12px;background:#fbfcff;height:120px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.brand-box img.brand-img{max-height:110px;max-width:100%;object-fit:contain}
.brand-empty{color:#9aa7bd;font-size:.72rem;text-align:center;line-height:1.3}
.brand-empty i{display:block;font-size:1.5rem;margin-bottom:2px}
</style>

<div class="ri-head d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><div class="k"><i class="bi bi-clipboard2-check me-1"></i>Report Preliminary Information</div>
    <div class="t mt-1">Report Details</div></div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-light btn-sm fw-semibold" href="<?php echo h(url('admin/report_info_view.php')); ?>"><i class="bi bi-archive me-1"></i>Saved Info</a>
    <a class="btn btn-light btn-sm fw-semibold" href="<?php echo h(url('exam_reports.php')); ?>"><i class="bi bi-file-earmark-text me-1"></i>Exam Report Forms</a>
  </div>
</div>

<?php if (!$staff): ?>
<div class="alert alert-warning py-2">No teachers are registered for this school yet. Add teachers first, then come back to fill this form.</div>
<?php endif; ?>

<form method="get" class="card ri-card mb-3"><div class="card-body"><div class="row g-3 align-items-end">
  <div class="col-sm-5"><label class="form-label">Term</label>
    <select name="term" class="form-select" onchange="this.form.submit()"><?php foreach ($terms as $tk => $tl): ?><option value="<?php echo h($tk); ?>"<?php echo $tk === $term ? ' selected' : ''; ?>><?php echo h($tl); ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-5"><label class="form-label">Class</label>
    <select name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $class_id ? ' selected' : ''; ?>><?php echo h(preg_replace('/^Form\s+/i', '', $c['name'])); ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-arrow-clockwise me-1"></i>Load</button></div>
</div><div class="ri-note mt-2">Choose the term and class to load the saved report information.</div></div></form>

<form method="post" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
<input type="hidden" name="term" value="<?php echo h($term); ?>">
<input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
<div class="row g-3">

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">1</span>Preliminary Information</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-sm-6 col-lg-3"><label class="form-label">Term</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php echo h($terms[$term]); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Class</label>
          <input type="text" class="form-control ri-auto" readonly value="<?php foreach ($classes as $c) if ((int)$c['id'] === $class_id) echo h(preg_replace('/^Form\s+/i', '', $c['name'])); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Closing date</label>
          <input type="date" name="closing_date" class="form-control" value="<?php echo h($dval('closing_date')); ?>"></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label">Opening date</label>
          <input type="date" name="opening_date" class="form-control" value="<?php echo h($dval('opening_date')); ?>"></div>
      </div></div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card ri-card"><div class="card-header"><span class="n">2</span>Class Teacher</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-7"><label class="form-label">Class teacher name</label>
          <select name="class_teacher_id" class="form-select ri-teacher" data-phone-target="ct_phone"><?php echo $opts($ri['class_teacher_id'] ?? 0, '— Select class teacher —'); ?></select></div>
        <div class="col-md-5"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" id="ct_phone" readonly value="<?php echo $v('class_phone'); ?>" placeholder="Auto from teacher record"></div>
        <div class="col-12"><div class="ri-note"><b>Class teacher remarks:</b> generated automatically from the student's average grade — A: “<?php echo h($termRemark['class_teacher']); ?>”; B–F follow the same pattern.</div></div>
      </div></div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card ri-card"><div class="card-header"><span class="n">3</span>Subject Teacher</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-7"><label class="form-label">Subject teacher name</label>
          <select name="subject_teacher_id" class="form-select ri-teacher" data-phone-target="st_phone"><?php echo $opts($ri['subject_teacher_id'] ?? 0, '— Select subject teacher —'); ?></select></div>
        <div class="col-md-5"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" id="st_phone" readonly value="<?php echo $v('subject_phone'); ?>" placeholder="Auto from teacher record"></div>
        <div class="col-12"><div class="ri-note"><b>Subject teacher remarks:</b> generated automatically from the student's average grade (A–F).</div></div>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">4</span>Headmaster / Headmistress</div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label">Headmaster name</label>
          <select name="headmaster_id" class="form-select ri-teacher" data-phone-target="hm_phone"><?php echo $opts(!$ri ? $hmDefault : ($ri['headmaster_id'] ?? 0), '— Select headmaster —'); ?></select></div>
        <div class="col-md-3"><label class="form-label">Phone number</label>
          <input type="text" class="form-control ri-auto" id="hm_phone" readonly value="<?php echo $v('head_phone'); ?>" placeholder="Auto from teacher record"></div>
        <div class="col-md-5"><div class="ri-note pt-md-4"><b>Headmaster remarks:</b> generated automatically from the student's average grade (A–F).</div></div>

        <div class="col-12"><hr class="my-1"><div class="ri-note mb-1"><b>Report logos, stamp &amp; signature</b> — <b>school logo, stamp and signature are required</b> before report information can be saved. They are saved for the whole school (not per class or term) and appear automatically on every report card. The national logo (coat of arms) is built into the system and always appears on the left of the report header. Use PNG, JPG, GIF or WEBP up to 3MB.</div></div>
        <div class="col-12"><div class="row g-3">
          <?php
          echo $brandSlot('school_logo', 'School logo');
          echo $brandSlot('stamp', 'School stamp');
          echo $brandSlot('signature', 'Headmaster signature');
          ?>
        </div></div>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">5</span>Instructions When School Opens</div>
      <div class="card-body">
        <div class="row g-3" id="instructionRows">
        <?php foreach ($instructions as $i => $line): ?>
        <div class="col-12 col-md-6 ri-instr-row"><label class="form-label ri-instr-label">Instruction <?php echo $i + 1; ?></label>
          <input type="text" name="instructions[]" class="form-control ri-instr-input" value="<?php echo h($line); ?>" placeholder="<?php echo $i === 0 ? 'e.g. Every student must take this report home to his/her parent or guardian.' : ''; ?>"></div>
        <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
          <button type="button" class="btn btn-outline-primary btn-sm" id="addInstruction"><i class="bi bi-plus-lg me-1"></i>Add instruction</button>
          <span class="ri-note" id="instrCount"><?php echo count($instructions); ?> of 10 instructions</span>
        </div>
      </div></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card ri-card"><div class="card-header"><span class="n">6</span>Automatic Remarks (by average grade)</div>
      <div class="card-body">
        <div class="ri-note mb-2">You can edit these remarks. They are saved for this school and used automatically on every report card. Clear a field to restore the default text.</div>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th class="ps-3" style="width:70px">Grade</th><th style="width:45%">Class teacher remark</th><th>Subject teacher &amp; headmaster remark</th></tr></thead>
        <tbody><?php foreach (['A', 'B', 'C', 'D', 'F'] as $g): $rm = report_remarks($g); ?>
          <tr><td class="ps-3 fw-bold"><?php echo h($g); ?></td>
            <td><textarea name="remarks[<?php echo h($g); ?>][class_teacher]" class="form-control form-control-sm" rows="2"><?php echo h($rm['class_teacher']); ?></textarea></td>
            <td><textarea name="remarks[<?php echo h($g); ?>][staff]" class="form-control form-control-sm" rows="2"><?php echo h($rm['subject_teacher']); ?></textarea></td></tr>
        <?php endforeach; ?></tbody>
      </table></div></div>
    </div>
  </div>

</div>
<div class="d-flex justify-content-center gap-2 mt-3">
  <a class="btn btn-outline-secondary" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
  <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
</div>
</form>
<script>
(function () {
  var box = document.getElementById('instructionRows');
  var btn = document.getElementById('addInstruction');
  var count = document.getElementById('instrCount');
  if (!box || !btn) return;
  var MAX = 10;
  var firstPlaceholder = 'e.g. Every student must take this report home to his/her parent or guardian.';
  function rows() { return box.querySelectorAll('.ri-instr-row'); }
  function refresh() {
    var r = rows();
    for (var i = 0; i < r.length; i++) {
      var lbl = r[i].querySelector('.ri-instr-label');
      if (lbl) lbl.textContent = 'Instruction ' + (i + 1);
      var inp = r[i].querySelector('.ri-instr-input');
      if (inp) inp.placeholder = i === 0 ? firstPlaceholder : '';
    }
    count.textContent = r.length + ' of ' + MAX + ' instructions';
    btn.disabled = r.length >= MAX;
  }
  btn.addEventListener('click', function () {
    var r = rows();
    if (r.length >= MAX) return;
    var clone = r[r.length - 1].cloneNode(true);
    var inp = clone.querySelector('.ri-instr-input');
    if (inp) { inp.value = ''; inp.placeholder = ''; }
    box.appendChild(clone);
    refresh();
    if (inp) inp.focus();
  });
  refresh();
})();
</script>
<script>
(function () {
  var endpoint = '<?php echo h(url('admin/branding_upload.php')); ?>';
  var csrfEl = document.querySelector('input[name="csrf"]');
  var flashBox = document.getElementById('riFlash');
  function showTop(msg, type) {
    var popup = document.getElementById('riPopup');
    if (!popup) {
      popup = document.createElement('div');
      popup.id = 'riPopup';
      popup.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;pointer-events:none;';
      popup.innerHTML = '<div id="riPopupCard" style="pointer-events:auto;background:#d4e4dc;border:1px solid #9fb9a8;border-radius:18px;padding:14px 26px;display:flex;align-items:center;gap:10px;box-shadow:0 12px 30px rgba(0,0,0,.18);font-weight:600;color:#1f2d24;opacity:0;transform:scale(.92);transition:opacity .18s ease,transform .18s ease;"><span style="width:26px;height:26px;border-radius:50%;background:#2f6b4a;color:#fff;display:grid;place-items:center;font-size:.9rem;"><i class="bi bi-check-lg"></i></span><span id="riPopupMsg"></span></div>';
      document.body.appendChild(popup);
    }
    var card = document.getElementById('riPopupCard');
    var msgEl = document.getElementById('riPopupMsg');
    if (msgEl) msgEl.textContent = msg;
    if (card) {
      card.style.opacity = '1';
      card.style.transform = 'scale(1)';
    }
    window.clearTimeout(popup._t);
    popup._t = window.setTimeout(function () {
      if (card) { card.style.opacity = '0'; card.style.transform = 'scale(.92)'; }
      window.setTimeout(function () { if (popup) popup.style.display = 'none'; }, 200);
    }, 1600);
    popup.style.display = 'flex';
  }
  document.querySelectorAll('.brand-slot').forEach(function (slot) {
    var key = slot.getAttribute('data-key');
    var box = slot.parentNode.querySelector('.brand-box');
    var input = slot.querySelector('.brand-file');
    var btn = slot.querySelector('.brand-upload');
    var status = slot.querySelector('.brand-status');
    var delBtn = slot.querySelector('.brand-delete');
    if (!input || !btn) return;
    btn.addEventListener('click', function () {
      if (!input.files || !input.files.length) {
        status.className = 'ri-note mt-1 brand-status text-danger';
        status.textContent = 'Please choose an image first.';
        return;
      }
      var fd = new FormData();
      fd.append('csrf', csrfEl ? csrfEl.value : '');
      fd.append('type', key);
      fd.append('file', input.files[0]);
      btn.disabled = true;
      status.className = 'ri-note mt-1 brand-status text-muted';
      status.textContent = 'Uploading…';
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { throw new Error('The server response is invalid.'); }); })
        .then(function (j) {
          if (!j.ok) throw new Error(j.error || 'Upload failed.');
          if (box) box.innerHTML = '<img src="' + j.src + '" alt="" class="brand-img">';
          input.value = '';
          input.removeAttribute('required');
          if (delBtn) delBtn.style.display = '';
          status.className = 'ri-note mt-1 brand-status';
          status.textContent = '';
          showTop('Saved');
        })
        .catch(function (e) {
          status.className = 'ri-note mt-1 brand-status text-danger';
          status.textContent = e.message;
        })
        .then(function () { btn.disabled = false; });
    });
    if (delBtn) delBtn.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('csrf', csrfEl ? csrfEl.value : '');
      fd.append('type', key);
      fd.append('action', 'delete');
      delBtn.disabled = true;
      status.className = 'ri-note mt-1 brand-status text-muted';
      status.textContent = 'Deleting…';
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { throw new Error('The server response is invalid.'); }); })
        .then(function (j) {
          if (!j.ok) throw new Error(j.error || 'Could not delete the image.');
          if (box) box.innerHTML = '<div class="brand-empty"><i class="bi bi-image"></i><span>No image</span></div>';
          input.value = '';
          input.setAttribute('required', 'required');
          delBtn.style.display = 'none';
          status.className = 'ri-note mt-1 brand-status';
          status.textContent = '';
          showTop('Deleted');
        })
        .catch(function (e) {
          status.className = 'ri-note mt-1 brand-status text-danger';
          status.textContent = e.message;
        })
        .then(function () { delBtn.disabled = false; });
    });
  });
})();
</script>
<script>
document.querySelectorAll('.ri-teacher').forEach(function (sel) {
  // Jaza simu mwanzoni kama select ina mwalimu tayari lakini simu haina thamani
  var t0 = document.getElementById(sel.getAttribute('data-phone-target'));
  if (t0 && !t0.value && sel.value) {
    var o0 = sel.options[sel.selectedIndex];
    if (o0) t0.value = o0.getAttribute('data-phone') || '';
  }
  sel.addEventListener('change', function () {
    var target = document.getElementById(sel.getAttribute('data-phone-target'));
    if (!target) return;
    var opt = sel.options[sel.selectedIndex];
    target.value = opt ? (opt.getAttribute('data-phone') || '') : '';
  });
});
</script>
<?php layout_end();
