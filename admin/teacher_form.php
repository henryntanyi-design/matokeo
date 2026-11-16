<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$me = require_school_admin(); $school_id = active_school_id();

/** Is the `stream` column on teacher_assignments installed? */
function teacher_form_streams_ready() {
    static $ok = null;
    if ($ok === null) {
        try { q_val('SELECT stream FROM teacher_assignments LIMIT 1'); $ok = true; }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

/** Default password: the last name, first letter capitalised (e.g. "MASANJA" -> "Masanja"). */
function teacher_default_password($fullName) {
    $s = surname_of($fullName);
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/**
 * Save the subjects a teacher teaches for the whole class (stream = '').
 * Stream-specific assignments are left untouched; they are managed on the Assignments page.
 */
function teacher_form_sync_subjects($teacher_id, array $pairs) {
    $teacher_id = (int)$teacher_id;
    if (!$teacher_id) return;
    $hasStream = teacher_form_streams_ready();
    $valid = [];
    foreach ($pairs as $pair) {
        $parts = explode(':', (string)$pair);
        $cid = (int)($parts[0] ?? 0); $sid = (int)($parts[1] ?? 0);
        if ($cid && $sid && q_val('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?', [$cid, $sid])) {
            $valid[$cid . ':' . $sid] = [$cid, $sid];
        }
    }
    $sql = 'SELECT id, class_id, subject_id FROM teacher_assignments WHERE teacher_id = ?';
    if ($hasStream) $sql .= " AND stream = ''";
    $existing = q_all($sql, [$teacher_id]);
    $keep = []; $have = [];
    foreach ($existing as $r) {
        $k = (int)$r['class_id'] . ':' . (int)$r['subject_id'];
        if (isset($valid[$k])) { $keep[(int)$r['id']] = true; $have[$k] = true; }
    }
    foreach ($existing as $r) {
        if (!isset($keep[(int)$r['id']])) q('DELETE FROM teacher_assignments WHERE id = ? AND teacher_id = ?', [(int)$r['id'], $teacher_id]);
    }
    foreach ($valid as $k => $pair) {
        if (isset($have[$k])) continue;
        if ($hasStream) q("INSERT IGNORE INTO teacher_assignments (teacher_id, class_id, subject_id, stream) VALUES (?, ?, ?, '')", [$teacher_id, $pair[0], $pair[1]]);
        else q('INSERT IGNORE INTO teacher_assignments (teacher_id, class_id, subject_id) VALUES (?, ?, ?)', [$teacher_id, $pair[0], $pair[1]]);
    }
}

$id = (int)($_GET['id'] ?? $_GET['edit'] ?? $_POST['id'] ?? 0);
$edit = $id ? q_one('SELECT * FROM users WHERE id = ? AND school_id = ?', [$id, $school_id]) : null;
if ($id && !$edit) { flash('danger', 'User not found.'); redirect('admin/teachers.php'); }

$form = $edit ?: ['role' => 'teacher', 'position' => 'teacher', 'active' => 1];
$editPosition = '';
if ($edit) {
    $editPosition = (string)($edit['position'] ?? '');
    if ($editPosition === '') $editPosition = (($edit['role'] ?? 'teacher') === 'admin') ? 'admin' : 'teacher';
    $form['position'] = $editPosition;
}
$postedSubjects = null;

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') !== 'save') redirect('admin/teachers.php');

    $un = trim($_POST['username'] ?? ''); $fn = trim($_POST['full_name'] ?? ''); $pw = (string)($_POST['password'] ?? '');
    $position = (string)($_POST['position'] ?? 'teacher');
    if (!array_key_exists($position, user_positions())) $position = 'teacher';
    $role = role_for_position($position);
    $active = isset($_POST['active']) ? 1 : 0;
    $phone = trim($_POST['phone'] ?? ''); $email = trim($_POST['email'] ?? '');
    if ($id === (int)$me['id']) { $role = 'admin'; $position = $position === 'teacher' ? 'admin' : $position; $active = 1; }   // admin cannot demote or deactivate themselves

    /* New user: default password is the last name (first letter capital). */
    $suggestedPw = teacher_default_password($fn);
    if (!$id) {
        if ($pw === '') $pw = $suggestedPw;
        $usingSuggestion = ($pw !== '' && $pw === $suggestedPw);
    } else {
        $usingSuggestion = false;
    }

    /* Cheque Number must be 7-10 digits. A legacy (non-numeric) value may be kept as-is on edit. */
    $chequeUnchanged = ($id && $edit && $un === (string)$edit['username'] && !preg_match('/^\d{7,10}$/', $un));

    try {
        if ($fn === '') flash('danger', 'Full name is required.');
        elseif (!$chequeUnchanged && !preg_match('/^\d{7,10}$/', $un)) flash('danger', 'Cheque Number must be 7-10 digits.');
        elseif (!$id && !$usingSuggestion && strlen($pw) < 8) flash('danger', 'Password must be at least 8 characters.');
        elseif ($id && $pw !== '' && strlen($pw) < 8) flash('danger', 'New password must be at least 8 characters.');
        else {
            if ($id) {
                q('UPDATE users SET username = ?, full_name = ?, role = ?, position = ?, phone = ?, email = ?, active = ? WHERE id = ? AND school_id = ?', [$un, $fn, $role, $position, $phone, $email, $active, $id, $school_id]);
                if ($pw !== '') q('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ? AND school_id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id, $school_id]);
            } else {
                q('INSERT INTO users (username, password_hash, full_name, role, position, school_id, phone, email, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$un, password_hash($pw, PASSWORD_DEFAULT), $fn, $role, $position, $school_id, $phone, $email, $active]);
                $id = (int)db()->lastInsertId();
            }
            if (in_array($position, ['teacher', 'academic_master', 'second_master', 'headmaster'], true)) teacher_form_sync_subjects($id, (array)($_POST['subjects'] ?? []));
            flash('success', 'User saved.');
            redirect('admin/teachers.php');
        }
    } catch (PDOException $e) {
        flash('danger', $e->getCode() == 23000 ? 'That Cheque Number is already in use.' : h($e->getMessage()));
    }

    /* Validation failed: keep what was typed. */
    $form = array_merge($form, ['username' => $un, 'full_name' => $fn, 'role' => $role, 'position' => $position, 'phone' => $phone, 'email' => $email, 'active' => $active]);
    $postedSubjects = array_values(array_filter(array_map('strval', (array)($_POST['subjects'] ?? [])), function ($v) { return $v !== ''; }));
}

/* All class subjects, for the teacher's "Subjects taught" dropdowns. */
$pairGroups = [];
foreach (q_all('SELECT cs.class_id, c.name AS cname, cs.subject_id, s.name AS sname
                FROM class_subjects cs JOIN classes c ON c.id = cs.class_id JOIN subjects s ON s.id = cs.subject_id
                ORDER BY c.sort_order, c.id, s.name') as $r) {
    $cid = (int)$r['class_id'];
    if (!isset($pairGroups[$cid])) $pairGroups[$cid] = ['name' => (string)$r['cname'], 'items' => []];
    $pairGroups[$cid]['items'][] = ['id' => (int)$r['subject_id'], 'name' => (string)$r['sname']];
}

$selectedPairList = [];
if ($postedSubjects !== null) {
    $selectedPairList = $postedSubjects;
} elseif ($edit && in_array($editPosition, ['teacher', 'academic_master', 'second_master', 'headmaster'], true)) {
    $sql = 'SELECT class_id, subject_id FROM teacher_assignments WHERE teacher_id = ?';
    if (teacher_form_streams_ready()) $sql .= " AND stream = ''";
    foreach (q_all($sql, [(int)$edit['id']]) as $r) {
        $selectedPairList[] = (int)$r['class_id'] . ':' . (int)$r['subject_id'];
    }
}
$maxSubjects = 6;
$streamAssignCount = ($edit && teacher_form_streams_ready())
    ? (int)q_val("SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id = ? AND stream <> ''", [(int)$edit['id']]) : 0;
$canTeach = in_array(($form['position'] ?? 'teacher'), ['teacher', 'academic_master', 'second_master', 'headmaster'], true);

layout_start($edit ? 'Edit user' : 'Register teacher', 'teachers'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="fw-bold mb-0"><?php echo $edit ? 'Edit user' : 'Register teacher'; ?></h5>
    <div class="small text-muted"><?php echo $edit ? h($edit['full_name']) : 'Add a teacher or user and choose the subjects they teach.'; ?></div>
  </div>
  <a class="btn btn-outline-secondary" href="teachers.php"><i class="bi bi-arrow-left"></i> Back to teachers</a>
</div>
<div class="row g-3"><div class="col-12"><div class="card shadow-sm"><div class="card-body">
<form method="post" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
<div class="row g-3">
  <div class="col-md-6 col-xl-4"><label class="form-label small fw-bold">Full name *</label><input name="full_name" class="form-control" required value="<?php echo h($form['full_name'] ?? ''); ?>"></div>
  <div class="col-md-6 col-xl-4"><label class="form-label small fw-bold" for="chequeInput">Cheque Number *</label><input name="username" id="chequeInput" class="form-control" required value="<?php echo h($form['username'] ?? ''); ?>" inputmode="numeric" maxlength="10"<?php echo $edit ? '' : ' pattern="[0-9]{7,10}"'; ?>>
    <div class="form-text">7-10 digits only.</div></div>
  <div class="col-md-6 col-xl-4"><label class="form-label small fw-bold">Password <?php echo $edit ? '(leave blank to keep current)' : '*'; ?></label><input type="password" name="password" id="passwordInput" class="form-control" autocomplete="new-password" <?php echo $edit ? '' : 'required'; ?>>
    <div class="form-text"><?php echo $edit ? 'Leave blank to keep the current password.' : 'Defaults to the last name with a capital first letter (e.g. Masanja).'; ?></div></div>
  <div class="col-md-4 col-xl-3"><label class="form-label small fw-bold">Role</label><select name="position" id="userPosition" class="form-select"><?php foreach (user_positions() as $pk => $pl): ?><option value="<?php echo h($pk); ?>" <?php echo ($form['position'] ?? 'teacher') === $pk ? 'selected' : ''; ?>><?php echo h($pl); ?></option><?php endforeach; ?></select>
    <div class="form-text">Headmaster, Academic Master na Second Master hupata ufikiaji wa admin.</div></div>
  <div class="col-md-4 col-xl-3"><label class="form-label small fw-bold">Phone</label><input name="phone" class="form-control" value="<?php echo h($form['phone'] ?? ''); ?>"></div>
  <div class="col-md-4 col-xl-6"><label class="form-label small fw-bold">Email <span class="text-muted fw-normal">(lets the user reset their own password)</span></label><input name="email" type="email" class="form-control" value="<?php echo h($form['email'] ?? ''); ?>"></div>
</div>
<div class="mt-3<?php echo $canTeach ? '' : ' d-none'; ?>" id="subjectsWrap">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <label class="form-label small fw-bold mb-0">Subjects taught</label>
    <span class="badge text-bg-light border">Up to <?php echo $maxSubjects; ?> subjects</span>
  </div>
<?php if (!$pairGroups): ?>
  <div class="alert alert-warning py-2 small mb-0">No subjects are assigned to any class yet. Add subjects under <b>Classes &rarr; Subjects</b> first.</div>
<?php else: ?>
  <div class="row g-3">
  <?php for ($i = 0; $i < $maxSubjects; $i++): $cur = $selectedPairList[$i] ?? ''; ?>
    <div class="col-md-6 col-xl-4">
      <div class="input-group">
        <span class="input-group-text" style="min-width:82px">Subject <?php echo $i + 1; ?></span>
        <select name="subjects[]" class="form-select teacher-subject">
          <option value="">&mdash; none &mdash;</option>
          <?php foreach ($pairGroups as $cid => $g): ?>
            <optgroup label="<?php echo h($g['name']); ?>">
              <?php foreach ($g['items'] as $s): $key = $cid . ':' . (int)$s['id']; ?>
                <option value="<?php echo h($key); ?>"<?php echo $key === $cur ? ' selected' : ''; ?>><?php echo h($s['name']); ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php endfor; ?>
  </div>
  <div class="form-text">Each dropdown holds one subject; class and subject are chosen together. Leave &ldquo;none&rdquo; if the teacher has no extra subject.</div>
<?php endif; ?>
<?php if ($edit && $streamAssignCount): ?>
  <div class="small text-muted mt-1"><?php echo $streamAssignCount; ?> stream-specific subject assignment(s) are not changed here. <a href="assignments.php?teacher_id=<?php echo (int)$edit['id']; ?>">Manage them on Assignments</a>.</div>
<?php elseif ($edit): ?>
  <div class="small text-muted mt-1"><a href="assignments.php?teacher_id=<?php echo (int)$edit['id']; ?>">Set stream-specific subjects on Assignments</a>.</div>
<?php endif; ?>
</div>
<div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="active" id="ac" <?php echo (!isset($form['active']) || $form['active']) ? 'checked' : ''; ?>><label class="form-check-label small" for="ac">Active account</label></div>
<div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Save</button><a class="btn btn-link" href="teachers.php">Cancel</a></div>
</form>
</div></div></div></div>
<script>
(function () {
  var sels = Array.prototype.slice.call(document.querySelectorAll('.teacher-subject'));
  function sync() {
    var used = {};
    sels.forEach(function (s) { if (s.value) used[s.value] = true; });
    sels.forEach(function (s) {
      Array.prototype.forEach.call(s.options, function (o) {
        if (!o.value) return;
        o.disabled = o.value !== s.value && !!used[o.value];
      });
    });
  }
  sels.forEach(function (s) { s.addEventListener('change', sync); });
  sync();

  var posSel = document.getElementById('userPosition');
  var wrap = document.getElementById('subjectsWrap');
  var teachPositions = ['teacher', 'academic_master', 'second_master', 'headmaster'];
  if (posSel && wrap) posSel.addEventListener('change', function () {
    wrap.classList.toggle('d-none', teachPositions.indexOf(posSel.value) === -1);
  });

<?php if (!$edit): ?>
  /* Cheque Number: digits only, max 10. */
  var chequeInput = document.getElementById('chequeInput');
  if (chequeInput) {
    chequeInput.addEventListener('input', function () {
      var v = chequeInput.value.replace(/\D+/g, '').slice(0, 10);
      if (v !== chequeInput.value) chequeInput.value = v;
    });
  }

  var fnInput = document.querySelector('input[name="full_name"]');
  var pwInput = document.getElementById('passwordInput');
  if (fnInput && pwInput) {
    var pwTouched = false;
    pwInput.addEventListener('input', function () { pwTouched = true; });
    var fillPw = function () {
      if (pwTouched) return;
      var parts = fnInput.value.trim().split(/\s+/);
      var last = parts.length ? parts[parts.length - 1] : '';
      pwInput.value = last ? last.charAt(0).toUpperCase() + last.slice(1).toLowerCase() : '';
    };
    fnInput.addEventListener('input', fillPw);
    fillPw();
  }
<?php endif; ?>
})();
</script>
<?php layout_end(); ?>
