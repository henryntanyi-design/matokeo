<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin(); $school_id = active_school_id();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $id ? q_one('SELECT * FROM students WHERE id = ? AND school_id = ?', [$id, $school_id]) : null;
if ($id && !$st) redirect('admin/students.php');
$classes = q_all('SELECT * FROM classes ORDER BY sort_order, id');
if (is_post()) {
    csrf_check();
    $f = [];
    foreach (['prems_no', 'first_name', 'middle_name', 'last_name', 'nationality', 'stream', 'guardian_name', 'guardian_phone', 'address'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    $f['sex'] = (($_POST['sex'] ?? '') === 'F') ? 'F' : (($_POST['sex'] ?? '') === 'M' ? 'M' : '');
    $f['class_id'] = (int)($_POST['class_id'] ?? 0);
    $f['status'] = in_array($_POST['status'] ?? 'active', ['active', 'transferred', 'graduated', 'inactive'], true) ? $_POST['status'] : 'active';
    $dob = trim($_POST['dob'] ?? ''); $f['dob'] = ($dob !== '' && strtotime($dob)) ? date('Y-m-d', strtotime($dob)) : null;
    $f['nationality'] = strtoupper($f['nationality']);
    if ($f['nationality'] === '') $f['nationality'] = 'TANZANIA';

    $missing = [];
    if ($f['prems_no'] === '') $missing[] = 'PREMS No';
    if ($f['first_name'] === '') $missing[] = 'first name';
    if ($f['last_name'] === '') $missing[] = 'surname';
    if ($f['sex'] === '') $missing[] = 'sex';
    if (!q_val('SELECT 1 FROM classes WHERE id = ?', [$f['class_id']])) $missing[] = 'class';
    if ($f['stream'] === '') $missing[] = 'stream';
    if ($f['guardian_name'] === '') $missing[] = 'parent/guardian name';
    if ($f['guardian_phone'] === '') $missing[] = 'parent phone number';

    $dupPrems = $f['prems_no'] !== '' ? q_val('SELECT id FROM students WHERE prems_no = ? AND id <> ? LIMIT 1', [$f['prems_no'], $id]) : null;
    $admClash = $f['prems_no'] !== '' ? q_val('SELECT id FROM students WHERE school_id = ? AND admission_no = ? AND id <> ? LIMIT 1', [$school_id, $f['prems_no'], $id]) : null;

    if ($missing) {
        flash('danger', 'Please fill: ' . implode(', ', $missing) . '.');
    } elseif ($dupPrems) {
        flash('danger', 'This PREMS No is already used by another student (any school).');
    } elseif ($admClash) {
        flash('danger', 'This number is already used as Admission No by another student in this school.');
    } else {
        try {
            db()->beginTransaction();
            if ($id) {
                // Admission No ya zamani haibadilishwi; PREMS No ndiyo kitambulisho kinachotumika sasa.
                q('UPDATE students SET prems_no=?, first_name=?, middle_name=?, last_name=?, sex=?, dob=?, nationality=?, class_id=?, stream=?, guardian_name=?, guardian_phone=?, address=?, status=? WHERE id=? AND school_id=?',
                  [$f['prems_no'], $f['first_name'], $f['middle_name'], $f['last_name'], $f['sex'], $f['dob'], $f['nationality'], $f['class_id'], $f['stream'], $f['guardian_name'], $f['guardian_phone'], $f['address'], $f['status'], $id, $school_id]);
            } else {
                // Mwanafunzi mpya: admission_no huchukua PREMS No ili mifumo ya zamani iendelee kufanya kazi.
                q('INSERT INTO students (school_id, admission_no, prems_no, first_name, middle_name, last_name, sex, dob, nationality, class_id, stream, guardian_name, guardian_phone, address, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$school_id, $f['prems_no'], $f['prems_no'], $f['first_name'], $f['middle_name'], $f['last_name'], $f['sex'], $f['dob'], $f['nationality'], $f['class_id'], $f['stream'], $f['guardian_name'], $f['guardian_phone'], $f['address'], $f['status']]);
                $id = (int)db()->lastInsertId();
            }
            db()->commit();
            flash('success', 'Student saved.');
            redirect('admin/students.php');
        } catch (PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $e->getCode() == 23000 ? 'This PREMS No or Admission No is already used by another student.' : h($e->getMessage()));
            $st = array_merge($st ?: [], $f);
        }
    }
    if (!$st) $st = $f;
}
$cls = (int)($st['class_id'] ?? ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0)));
$v = function ($k, $d = '') use ($st) { return h($st[$k] ?? $d); };
$className = function ($name) { return preg_replace('/^Form\s+/i', '', (string)$name); };
layout_start($id ? 'Edit student details' : 'New Student registration', 'students'); ?>

<style>
.sf-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95);margin-bottom:16px}
.sf-hero::before,.sf-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.sf-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.sf-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.sf-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.sf-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.sf-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.sf-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.sf-hero h1 span{color:#ffd764}
.sf-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.sf-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.sf-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.sf-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.sf-btn:hover{transform:translateY(-1px)}
.sf-btn.solid{background:#fff;color:#0b3d91}
.sf-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.sf-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.sf-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}
.sf-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.sf-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.sf-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
@media (max-width:575.98px){
  .sf-hero{padding:18px;border-radius:18px}
  .sf-hero h1{font-size:1.2rem}
  .sf-date{margin-left:0}
}
</style>

<div class="sf-hero">
    <div class="sf-head">
        <span class="sf-mark"><i class="bi bi-person-plus"></i></span>
        <div>
            <div class="sf-kicker"><?php echo $id ? 'Edit student' : 'New student'; ?></div>
            <h1><?php echo $id ? 'Edit <span>student details</span>' : 'New <span>student registration</span>'; ?></h1>
            <p class="sf-sub">Fill in the required fields marked with <span style="color:#ffd764">*</span>.</p>
        </div>
        <span class="sf-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="sf-actions">
        <a class="sf-btn ghost" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-arrow-left"></i> Back to students</a>
        <a class="sf-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-house"></i> Dashboard</a>
    </div>
</div>

<div class="sf-card">
    <div class="sf-card-h">
        <span class="sf-card-t"><i class="bi bi-pencil-square text-primary"></i> Student details</span>
    </div>
    <div class="card-body p-4">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="row g-4">
                <div class="col-12"><div class="small fw-bold text-primary text-uppercase" style="letter-spacing:.06em">Personal information</div></div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">PREMS No <span class="req" style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="prems_no" class="form-control" required value="<?php echo $v('prems_no'); ?>">
                    <div class="form-text">This number is required and identifies the student across all schools.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active' => 'Active', 'transferred' => 'Transferred', 'graduated' => 'Graduated', 'inactive' => 'Inactive'] as $sk => $sl) echo '<option value="' . $sk . '"' . (($st['status'] ?? 'active') === $sk ? ' selected' : '') . '>' . $sl . '</option>'; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Nationality</label>
                    <select name="nationality" class="form-select">
                        <?php
                        $countries = ['Tanzania','Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo','Costa Rica','Croatia','Cuba','Cyprus','Czech Republic','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kiribati','Korea, North','Korea, South','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Macedonia','Norway','Oman','Pakistan','Palau','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Thailand','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Vatican City','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
                        $selNat = $st['nationality'] ?? 'TANZANIA';
                        foreach ($countries as $ctry) {
                            $val = strtoupper($ctry);
                            $selected = (strtoupper($selNat) === $val) ? ' selected' : '';
                            echo '<option value="' . h($val) . '"' . $selected . '>' . h($ctry) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">First name <span style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="first_name" class="form-control" required value="<?php echo $v('first_name'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Middle name</label>
                    <input name="middle_name" class="form-control" value="<?php echo $v('middle_name'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Surname <span style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="last_name" class="form-control" required value="<?php echo $v('last_name'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Sex <span style="color:#dc3545;font-weight:700">*</span></label>
                    <select name="sex" class="form-select" required>
                        <option value="">--</option>
                        <option value="M" <?php echo ($st['sex'] ?? '') === 'M' ? 'selected' : ''; ?>>Male</option>
                        <option value="F" <?php echo ($st['sex'] ?? '') === 'F' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Date of birth</label>
                    <input type="date" name="dob" class="form-control" value="<?php echo $v('dob'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Class <span style="color:#dc3545;font-weight:700">*</span></label>
                    <select name="class_id" id="studentClass" class="form-select" required>
                        <?php foreach ($classes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($cls === (int)$c['id'] ? ' selected' : '') . '>' . h($className($c['name'])) . '</option>'; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Stream <span style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="stream" class="form-control" required value="<?php echo $v('stream'); ?>" placeholder="e.g. A">
                    <div class="form-text">Required. Use the student's stream letter or name (A, B, Science...).</div>
                </div>
                <div class="col-12"><hr class="my-1"><div class="small fw-bold text-primary text-uppercase" style="letter-spacing:.06em">Parent / Guardian</div></div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Parent/Guardian name <span style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="guardian_name" class="form-control" required value="<?php echo $v('guardian_name'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Parent phone number <span style="color:#dc3545;font-weight:700">*</span></label>
                    <input name="guardian_phone" class="form-control" required value="<?php echo $v('guardian_phone'); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Address</label>
                    <input name="address" class="form-control" value="<?php echo $v('address'); ?>">
                </div>
            </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <button class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save</button>
        <a href="students.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>
</div>
</form>
<?php layout_end(); ?>