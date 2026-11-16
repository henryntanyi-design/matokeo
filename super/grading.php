<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
require_district();
if (is_post()) {
    csrf_check();
    $lvl = (($_POST['level'] ?? 'O') === 'A') ? 'A' : 'O';
    try {
        if (($_POST['action'] ?? '') === 'reset') {
            db()->beginTransaction(); q('DELETE FROM grading_scales WHERE level = ?', [$lvl]);
            foreach (default_grading($lvl) as $g) q('INSERT INTO grading_scales (level, grade, min_score, points, remark) VALUES (?,?,?,?,?)', [$lvl, $g['grade'], $g['min_score'], $g['points'], $g['remark']]);
            db()->commit(); flash('success', 'Grades for ' . ($lvl === 'A' ? 'A-Level' : 'O-Level') . ' restored to NECTA standards.');
        } else {
            $rows = []; $seen = [];
            foreach (($_POST['grade'] ?? []) as $i => $g) {
                $g = strtoupper(trim($g)); $min = trim($_POST['min'][$i] ?? ''); $pt = trim($_POST['pts'][$i] ?? '');
                if ($g === '' && $min === '' && $pt === '') continue;
                if ($g === '' || !is_numeric($min) || $min < 0 || $min > 100 || !ctype_digit($pt) || isset($seen[$g])) throw new Exception('Each row must have a unique grade, a minimum score between 0 and 100, and points (whole number).');
                $seen[$g] = 1; $rows[] = [$g, (float)$min, (int)$pt, trim($_POST['remark'][$i] ?? '')];
            }
            if (count($rows) < 2) throw new Exception('Add at least two grades.');
            if (!in_array(0.0, array_column($rows, 1), true)) throw new Exception('There must be a grade with a minimum score of 0 (the fail grade).');
            db()->beginTransaction(); q('DELETE FROM grading_scales WHERE level = ?', [$lvl]);
            foreach ($rows as $r) q('INSERT INTO grading_scales (level, grade, min_score, points, remark) VALUES (?,?,?,?,?)', [$lvl, $r[0], $r[1], $r[2], $r[3]]);
            db()->commit(); flash('success', 'Grades saved. All results will be recalculated using these grades.');
        }
    } catch (Exception $e) { if (db()->inTransaction()) db()->rollBack(); flash('danger', h($e->getMessage())); }
    redirect('super/grading.php');
}
layout_start('Grading', 'grading'); ?>

<style>
.grd-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);color:#fff;margin-bottom:16px;box-shadow:0 10px 24px rgba(11,61,145,.22)}
.grd-hero:before{content:"";position:absolute;right:-40px;top:-70px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.08)}
.grd-hero:after{content:"";position:absolute;right:90px;bottom:-80px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06)}
.grd-hero .inner{position:relative;z-index:2;min-width:0}
.grd-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;opacity:.78;display:flex;align-items:center;gap:6px}
.grd-hero h1{font-size:1.65rem;font-weight:800;margin:5px 0 3px;letter-spacing:-.01em;color:#fff}
.grd-hero p{margin:0;font-size:.85rem;opacity:.85;max-width:700px;line-height:1.45}
.grd-note{display:flex;gap:10px;background:#eef5ff;border:1px solid #d6e5fb;border-radius:14px;padding:12px 14px;font-size:.8rem;color:#2c4a77;line-height:1.5;margin-bottom:16px}
.grd-note i{color:#1b5bc4;font-size:1rem;flex:0 0 auto;margin-top:1px}
.grd-note b{color:#123266}
.grd-card{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);overflow:hidden;height:100%}
.grd-card-h{display:flex;align-items:center;gap:10px;padding:14px 16px;color:#fff}
.grd-card.o .grd-card-h{background:linear-gradient(120deg,#0b7a37,#20b15b)}
.grd-card.a .grd-card-h{background:linear-gradient(120deg,#4a2fa8,#8a6fe0)}
.grd-ico{width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.16);display:grid;place-items:center;font-size:1.05rem;flex:0 0 auto}
.grd-title{font-weight:800;font-size:1rem;line-height:1.15}
.grd-sub{font-size:.68rem;opacity:.8;margin-top:1px}
.grd-count{margin-left:auto;font-size:.66rem;font-weight:800;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:2px 9px;white-space:nowrap}
.grd-table thead th{font-size:.64rem;text-transform:uppercase;letter-spacing:.045em;color:#6b7a93;background:#f7f9fe;border-bottom:1px solid #e8eef9;white-space:nowrap;font-weight:800;padding-top:.55rem;padding-bottom:.55rem}
.grd-table tbody td{padding-top:.42rem;padding-bottom:.42rem;border-color:#f0f4fb;vertical-align:middle}
.grd-table tbody tr:hover{background:#fafcff}
.grd-table tr.grd-blank{background:#fcfdff}
.grd-table .form-control{font-size:.82rem;border-color:#dfe7f5;border-radius:8px;padding:.32rem .45rem}
.grd-table .form-control:focus{border-color:#7aa5ea;box-shadow:0 0 0 .18rem rgba(44,110,213,.12)}
.grd-table .grade-input{width:64px;text-align:center;font-weight:800;text-transform:uppercase}
.grd-table .grd-pts{width:76px}
.grd-min{max-width:126px}
.grd-min .input-group-text{font-size:.72rem;background:#f4f7fe;border-color:#dfe7f5;color:#5b7bb5}
.grd-range{display:inline-block;font-size:.7rem;font-weight:800;color:#33415c;background:#f2f6fd;border:1px solid #e0e9f8;border-radius:6px;padding:1px 7px;white-space:nowrap}
.grd-card-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px 16px;border-top:1px solid #eef2fa;background:linear-gradient(180deg,#fbfcff,#fff)}
.grd-card-f .btn{border-radius:9px;font-weight:700}
.grd-rules{background:#fff;border:1px solid #e8eef9;border-radius:16px;box-shadow:0 2px 12px rgba(11,61,145,.06);padding:16px;margin-top:16px}
.grd-rules-h{font-weight:800;color:#16203a;display:flex;align-items:center;gap:8px;margin-bottom:12px}
.grd-rules-h i{color:#2c6ed5}
.grd-rule{background:#f8faff;border:1px solid #e8eef9;border-radius:12px;padding:11px 13px;height:100%}
.grd-rule-t{font-weight:800;font-size:.82rem;color:#1b5bc4;display:flex;align-items:center;gap:6px;margin-bottom:4px}
.grd-rule p{margin:0;font-size:.76rem;color:#5a6b85;line-height:1.5}
@media(max-width:575px){.grd-hero{padding:18px}.grd-hero h1{font-size:1.32rem}.grd-card-f .ms-auto{margin-left:0!important}}
@media print{.grd-hero,.grd-note,.grd-card-f,.grd-rules{display:none!important}.grd-card{box-shadow:none}}
</style>

<div class="grd-hero">
  <div class="inner">
    <div class="kicker"><i class="bi bi-sliders"></i> Assessment rules</div>
    <h1>Grading</h1>
    <p>Set the grade boundaries, points and remarks used across the system. Changes take effect immediately and apply to every exam.</p>
  </div>
</div>

<div class="grd-note">
  <i class="bi bi-info-circle-fill"></i>
  <div><b>Changes affect all results.</b> These grades are used to compute results immediately, including past exams. Divisions follow NECTA rules (O-Level: best 7 subjects; A-Level: best 3 principal subjects).</div>
</div>

<div class="row g-3">
<?php
$levels = [
    'O' => ['title' => 'O-Level', 'sub' => 'CSEE &middot; Form I&ndash;IV', 'icon' => 'book', 'class' => 'o'],
    'A' => ['title' => 'A-Level', 'sub' => 'ACSEE &middot; Form V&ndash;VI', 'icon' => 'mortarboard', 'class' => 'a'],
];
foreach ($levels as $lvl => $meta):
    $g = get_grading($lvl);
    $nGrades = count($g);
    $mins = [];
    foreach ($g as $r) { if (is_numeric($r['min_score'])) $mins[] = (float)$r['min_score']; }
    $mins = array_values(array_unique($mins));
    sort($mins);
    $g = array_pad($g, $nGrades + 1, ['grade' => '', 'min_score' => '', 'points' => '', 'remark' => '']);
?>
  <div class="col-lg-6">
    <form method="post" class="grd-card <?php echo $meta['class']; ?>">
      <?php echo csrf_field(); ?><input type="hidden" name="level" value="<?php echo $lvl; ?>">
      <div class="grd-card-h">
        <span class="grd-ico"><i class="bi bi-<?php echo $meta['icon']; ?>"></i></span>
        <div>
          <div class="grd-title"><?php echo $meta['title']; ?></div>
          <div class="grd-sub"><?php echo $meta['sub']; ?></div>
        </div>
        <span class="grd-count"><?php echo $nGrades; ?> grades</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table mb-0 grd-table">
          <thead><tr><th class="ps-3">Grade</th><th>Range</th><th>Min score</th><th>Points</th><th class="pe-3">Remark</th></tr></thead>
          <tbody>
          <?php foreach ($g as $r):
              $blank = trim((string)$r['grade']) === '' && trim((string)$r['min_score']) === '' && trim((string)$r['points']) === '';
              $range = '&mdash;';
              if (!$blank && is_numeric($r['min_score'])) {
                  $v = (float)$r['min_score'];
                  $higher = null;
                  foreach ($mins as $m) { if ($m > $v) { $higher = $m; break; } }
                  $upper = $higher === null ? 100 : $higher - 1;
                  $fmt = function ($n) { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
                  $range = $fmt($v) . ' &ndash; ' . $fmt($upper);
              }
          ?>
            <tr<?php echo $blank ? ' class="grd-blank"' : ''; ?>>
              <td class="ps-3"><input name="grade[]" class="form-control form-control-sm grade-input" maxlength="2" value="<?php echo h($r['grade']); ?>" aria-label="Grade"></td>
              <td><span class="grd-range"><?php echo $range; ?></span></td>
              <td><div class="input-group input-group-sm grd-min"><input name="min[]" type="number" min="0" max="100" step="any" class="form-control" value="<?php echo h($r['min_score']); ?>" aria-label="Minimum score"><span class="input-group-text">%</span></div></td>
              <td><input name="pts[]" type="number" min="0" max="99" step="1" class="form-control form-control-sm grd-pts" value="<?php echo h($r['points']); ?>" aria-label="Points"></td>
              <td class="pe-3"><input name="remark[]" class="form-control form-control-sm" value="<?php echo h($r['remark']); ?>" placeholder="e.g. Excellent" aria-label="Remark"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <div class="grd-card-f">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Save</button>
        <button type="button" class="btn btn-outline-primary btn-sm grd-add"><i class="bi bi-plus-lg me-1"></i>Add grade</button>
        <button name="action" value="reset" class="btn btn-outline-secondary btn-sm ms-auto" onclick="return confirm('Reset this level to NECTA standards?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset NECTA</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>

<div class="grd-rules">
  <div class="grd-rules-h"><i class="bi bi-diagram-2"></i>Division rules (NECTA)</div>
  <div class="row g-3">
    <div class="col-md-6"><div class="grd-rule"><div class="grd-rule-t"><i class="bi bi-book"></i>O-Level (CSEE)</div><p>Best 7 subjects points: Division I = 7&ndash;17, II = 18&ndash;21, III = 22&ndash;25, IV = 26&ndash;33, 0 = 34&ndash;35.</p></div></div>
    <div class="col-md-6"><div class="grd-rule"><div class="grd-rule-t"><i class="bi bi-mortarboard"></i>A-Level (ACSEE)</div><p>Best 3 principal subjects: Division I = 3&ndash;9, II = 10&ndash;12, III = 13&ndash;17, IV = 18&ndash;19, 0 = 20&ndash;21. Subsidiary (GS, BAM): S/F only.</p></div></div>
  </div>
</div>

<script>
(function () {
  function fmt(n) {
    return String(Math.round(n * 100) / 100);
  }
  function recalc(form) {
    var rows = Array.prototype.slice.call(form.querySelectorAll('tbody tr'));
    var mins = [];
    rows.forEach(function (tr) {
      var v = parseFloat(tr.querySelector('input[name="min[]"]').value);
      if (!isNaN(v)) mins.push(v);
    });
    mins.sort(function (a, b) { return a - b; });
    rows.forEach(function (tr) {
      var el = tr.querySelector('.grd-range');
      if (!el) return;
      var v = parseFloat(tr.querySelector('input[name="min[]"]').value);
      if (isNaN(v)) { el.textContent = '\u2014'; return; }
      var higher = null;
      for (var i = 0; i < mins.length; i++) { if (mins[i] > v) { higher = mins[i]; break; } }
      var upper = higher === null ? 100 : higher - 1;
      el.textContent = fmt(v) + ' \u2013 ' + fmt(upper);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('.grd-card'), function (form) {
    form.addEventListener('input', function (e) {
      var tr = e.target.closest ? e.target.closest('tr') : null;
      if (tr && tr.classList.contains('grd-blank') && e.target.value.trim() !== '') tr.classList.remove('grd-blank');
      if (e.target.name === 'min[]' || e.target.name === 'grade[]') recalc(form);
    });
    var addBtn = form.querySelector('.grd-add');
    if (addBtn) addBtn.addEventListener('click', function () {
      var tbody = form.querySelector('tbody');
      var blank = tbody.querySelector('tr.grd-blank');
      if (blank) {
        var filled = false;
        Array.prototype.forEach.call(blank.querySelectorAll('input'), function (i) {
          if (i.value.trim() !== '') filled = true;
        });
        if (!filled) {
          var b = blank.querySelector('input[name="grade[]"]');
          if (b) b.focus();
          return;
        }
        blank.classList.remove('grd-blank');
        recalc(form);
      }
      var last = tbody.rows[tbody.rows.length - 1];
      if (!last) return;
      var fresh = last.cloneNode(true);
      Array.prototype.forEach.call(fresh.querySelectorAll('input'), function (i) { i.value = ''; });
      fresh.classList.add('grd-blank');
      tbody.appendChild(fresh);
      recalc(form);
      var inp = fresh.querySelector('input[name="grade[]"]');
      if (inp) inp.focus();
    });
    recalc(form);
  });
})();
</script>
<?php layout_end(); ?>
