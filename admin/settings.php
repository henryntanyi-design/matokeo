<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_school_admin();
$school_id = active_school_id();
if (is_post()) {
    csrf_check();
    $f = []; foreach (['name', 'centre_no', 'region', 'district', 'ward', 'phone', 'motto'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    $f['centre_no'] = strtoupper($f['centre_no']) ?: null;
    if ($f['name'] === '') flash('danger', 'The school name is required.');
    else {
        try {
            q('UPDATE schools SET name=?, centre_no=?, region=?, district=?, ward=?, phone=?, motto=? WHERE id=?', [$f['name'], $f['centre_no'], $f['region'], $f['district'], $f['ward'], $f['phone'], $f['motto'], $school_id]);
            flash('success', 'School information saved. The new centre number applies only to new candidates (existing ones keep their numbers).');
        } catch (PDOException $e) { flash('danger', $e->getCode() == 23000 ? 'The school name or centre number is already used by another school.' : h($e->getMessage())); }
    }
    redirect('admin/settings.php');
}
$sc = q_one('SELECT * FROM schools WHERE id = ?', [$school_id]);
$v = function ($k) use ($sc) { return h($sc[$k] ?? ''); };
layout_start('School Profile', 'settings'); ?>
<form method="post" class="card shadow-sm" style="max-width:760px"><div class="card-body"><?php echo csrf_field(); ?><div class="row g-3">
<div class="col-md-8"><label class="form-label small fw-bold">School name *</label><input name="name" class="form-control" required value="<?php echo $v('name'); ?>"></div>
<div class="col-md-4"><label class="form-label small fw-bold">Centre number</label><input name="centre_no" class="form-control" value="<?php echo $v('centre_no'); ?>"></div>
<div class="col-md-4"><label class="form-label small fw-bold">Region</label><input name="region" class="form-control" value="<?php echo $v('region'); ?>"></div>
<div class="col-md-4"><label class="form-label small fw-bold">District</label><input name="district" class="form-control" value="<?php echo $v('district'); ?>"></div>
<div class="col-md-4"><label class="form-label small fw-bold">Ward</label><input name="ward" class="form-control" value="<?php echo $v('ward'); ?>"></div>
<div class="col-md-6"><label class="form-label small fw-bold">Phone</label><input name="phone" class="form-control" value="<?php echo $v('phone'); ?>"></div>
<div class="col-md-6"><label class="form-label small fw-bold">Motto</label><input name="motto" class="form-control" value="<?php echo $v('motto'); ?>"></div></div></div>
<div class="card-footer bg-white"><button class="btn btn-primary">Save</button></div></form>
<?php layout_end(); ?>