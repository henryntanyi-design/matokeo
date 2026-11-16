<?php
require_once __DIR__ . '/../lib/grading.php';
require_once __DIR__ . '/../lib/layout.php';
$me = require_school_admin(); $school_id = active_school_id();

$roleFilter = (string)($_GET['role'] ?? $_POST['role'] ?? '');
if (!in_array($roleFilter, ['teacher', 'admin'], true)) $roleFilter = '';
$listUrl = 'admin/teachers.php' . ($roleFilter !== '' ? '?role=' . $roleFilter : '');

/* Old links (teachers.php?edit=ID) now open the dedicated form page. */
if (isset($_GET['edit']) && !is_post()) redirect('admin/teacher_form.php?id=' . (int)$_GET['edit']);

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'reset_pw') {
            $rid = (int)($_POST['id'] ?? 0);
            $t = q_one('SELECT * FROM users WHERE id = ? AND school_id = ?', [$rid, $school_id]);
            if (!$t) flash('danger', 'User not found.');
            elseif ((int)$t['id'] === (int)$me['id']) flash('danger', 'Change your own password via My Account.');
            else {
                $pw = generate_password(surname_of($t['full_name']));
                q('UPDATE users SET password_hash = ?, must_change = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $rid]);
                flash('success', 'Temporary password for <b>' . h($t['full_name']) . '</b> (' . h($t['username']) . '): <code class="fs-6">' . h($pw) . '</code><br><small>Shown only once. Share it privately; the user must set their own password on next login.</small>');
            }
        } elseif ($a === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$me['id']) flash('danger', 'You cannot delete yourself.');
            else { q('DELETE FROM users WHERE id = ? AND school_id = ?', [$id, $school_id]); flash('success', 'User deleted.'); }
        }
    } catch (PDOException $e) { flash('danger', $e->getCode() == 23000 ? 'That Cheque Number is already in use.' : h($e->getMessage())); }
    redirect($listUrl);
}

$nTeachers = (int)q_val("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher'", [$school_id]);
$nAdmins = (int)q_val("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'admin'", [$school_id]);
$nActive = (int)q_val("SELECT COUNT(*) FROM users WHERE school_id = ? AND active = 1", [$school_id]);

$w = 'u.school_id = ?'; $p = [$school_id];
if ($roleFilter !== '') { $w .= ' AND u.role = ?'; $p[] = $roleFilter; }
$rows = q_all("SELECT u.*, (SELECT COUNT(*) FROM teacher_assignments t WHERE t.teacher_id = u.id) AS n_assign FROM users u WHERE $w ORDER BY u.role, u.full_name", $p);

layout_start('Teachers & Users', 'teachers'); ?>

<style>
.tch-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;color:#fff;
  background:linear-gradient(120deg,#0b3d91 0%,#1b5bc4 55%,#2c6ed5 100%);
  box-shadow:0 20px 42px -26px rgba(11,61,145,.95)}
.tch-hero::before,.tch-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.tch-hero::before{width:260px;height:260px;right:-70px;top:-120px;background:rgba(255,255,255,.10)}
.tch-hero::after{width:170px;height:170px;right:90px;bottom:-120px;background:rgba(255,215,100,.14)}
.tch-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.tch-mark{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.5rem;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);flex:none}
.tch-kicker{font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:#ffd764}
.tch-hero h1{font-size:1.45rem;font-weight:800;margin:.15rem 0 .2rem;line-height:1.2}
.tch-hero h1 span{color:#ffd764}
.tch-sub{font-size:.78rem;color:rgba(255,255,255,.82);margin:0}
.tch-date{margin-left:auto;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  font-size:.74rem;font-weight:700;padding:.38rem .85rem;border-radius:999px;
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.26)}
.tch-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;position:relative;z-index:1}
.tch-btn{display:inline-flex;align-items:center;gap:8px;padding:.48rem .95rem;border-radius:11px;
  font-size:.79rem;font-weight:700;text-decoration:none;transition:transform .15s ease,background .15s ease}
.tch-btn:hover{transform:translateY(-1px)}
.tch-btn.solid{background:#fff;color:#0b3d91}
.tch-btn.solid:hover{background:#eef4ff;color:#0b3d91}
.tch-btn.ghost{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.3)}
.tch-btn.ghost:hover{background:rgba(255,255,255,.22);color:#fff}

.tch-stat{display:flex;align-items:center;gap:13px;height:100%;padding:14px 16px;border-radius:16px;
  background:#fff;border:1px solid #e6ecf8;text-decoration:none;color:inherit;
  box-shadow:0 2px 12px rgba(11,61,145,.05);
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.tch-stat:hover{transform:translateY(-3px);border-color:#cfddf5;color:inherit;
  box-shadow:0 18px 30px -22px rgba(11,61,145,.85)}
.tch-stat-ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;
  font-size:1.15rem;flex:none;box-shadow:0 10px 18px -12px rgba(11,61,145,.9)}
.tch-stat-l{font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8b9ab1}
.tch-stat-v{font-size:1.5rem;font-weight:800;line-height:1.15;color:#16203a}
.tch-stat-s{font-size:.7rem;color:#a3b0c4}

.tch-card{background:#fff;border:1px solid #e6ecf8;border-radius:18px;overflow:hidden;
  box-shadow:0 4px 18px rgba(11,61,145,.06)}
.tch-card-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #edf1fa}
.tch-card-t{font-weight:800;color:#16203a;font-size:.95rem;display:flex;align-items:center;gap:8px}
.tch-pill{font-size:.68rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;
  background:#eaf1fe;color:#1b5bc4;border:1px solid #d3e1fb}
.tch-empty{text-align:center;padding:34px 16px;color:#8b9ab1}
.tch-empty i{font-size:2rem;color:#c6d5ec;display:block;margin-bottom:6px}

.tch-filter .btn{border-radius:999px;font-weight:600}
.tch-table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#8b9ab1;
  font-weight:800;background:#f9fbff;border-bottom:1px solid #edf1fa;white-space:nowrap}
.tch-table tbody td{border-color:#f2f5fb;vertical-align:middle}
.tch-table tbody tr:hover{background:#fafcff}
.tch-avatar{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;color:#fff;background:linear-gradient(140deg,#2c6ed5,#0b3d91);flex:none}
.tch-name{font-weight:700;color:#16203a}
.tch-subtxt{font-size:.72rem;color:#8b9ab1}

.tch-note{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:11px 14px;border-radius:14px;
  background:linear-gradient(90deg,#eef4ff,#f7faff);border:1px solid #d3e1fb;color:#1b5bc4;font-size:.82rem}
.tch-note .tch-note-ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
  background:#eaf1fe;color:#1b5bc4;flex:none}

@media (max-width:575.98px){
  .tch-hero{padding:18px;border-radius:18px}
  .tch-hero h1{font-size:1.2rem}
  .tch-date{margin-left:0}
}
</style>

<div class="tch-hero mb-4">
    <div class="tch-head">
        <span class="tch-mark"><i class="bi bi-people-fill"></i></span>
        <div>
            <div class="tch-kicker">Staff management</div>
            <h1>Teachers &amp; <span>Users</span></h1>
            <p class="tch-sub">
                <?php echo $nTeachers; ?> teacher<?php echo $nTeachers === 1 ? '' : 's'; ?>,
                <?php echo $nAdmins; ?> admin/leadership
                <?php if ($roleFilter !== ''): ?> &middot; filtered by <?php echo h($roleFilter === 'admin' ? 'admin/leadership' : $roleFilter); ?><?php endif; ?>
            </p>
        </div>
        <span class="tch-date"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></span>
    </div>
    <div class="tch-actions">
        <a class="tch-btn solid" href="<?php echo h(url('admin/teacher_form.php')); ?>"><i class="bi bi-person-plus"></i> Register teacher</a>
        <a class="tch-btn ghost" href="<?php echo h(url('admin/students.php')); ?>"><i class="bi bi-people"></i> Students</a>
        <a class="tch-btn ghost" href="<?php echo h(url('admin/dashboard.php')); ?>"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tch-stat">
            <span class="tch-stat-ic" style="background:linear-gradient(135deg,#2c6ed5,#0b3d91)"><i class="bi bi-person-badge"></i></span>
            <span class="d-block">
                <span class="d-block tch-stat-l">Teachers</span>
                <span class="d-block tch-stat-v"><?php echo $nTeachers; ?></span>
                <span class="d-block tch-stat-s">Teaching staff</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tch-stat">
            <span class="tch-stat-ic" style="background:linear-gradient(135deg,#7048e8,#4c2fa8)"><i class="bi bi-shield-check"></i></span>
            <span class="d-block">
                <span class="d-block tch-stat-l">Admin / Leadership</span>
                <span class="d-block tch-stat-v"><?php echo $nAdmins; ?></span>
                <span class="d-block tch-stat-s">School leadership</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tch-stat">
            <span class="tch-stat-ic" style="background:linear-gradient(135deg,#12b886,#0b7a5b)"><i class="bi bi-check-circle"></i></span>
            <span class="d-block">
                <span class="d-block tch-stat-l">Active</span>
                <span class="d-block tch-stat-v"><?php echo $nActive; ?></span>
                <span class="d-block tch-stat-s">Can sign in</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tch-stat">
            <span class="tch-stat-ic" style="background:linear-gradient(135deg,#f59f00,#b06a00)"><i class="bi bi-people"></i></span>
            <span class="d-block">
                <span class="d-block tch-stat-l">Total users</span>
                <span class="d-block tch-stat-v"><?php echo $nTeachers + $nAdmins; ?></span>
                <span class="d-block tch-stat-s">All accounts</span>
            </span>
        </div>
    </div>
</div>

<div class="tch-card">
    <div class="tch-card-h">
        <span class="tch-card-t"><i class="bi bi-list-ul text-primary"></i> User list</span>
        <span class="tch-pill"><?php echo count($rows); ?> shown</span>
        <div class="tch-filter ms-auto">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter by role">
                <a href="teachers.php" class="btn <?php echo $roleFilter === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>">All <span class="badge rounded-pill text-bg-light ms-1"><?php echo $nTeachers + $nAdmins; ?></span></a>
                <a href="teachers.php?role=teacher" class="btn <?php echo $roleFilter === 'teacher' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Teachers <span class="badge rounded-pill text-bg-light ms-1"><?php echo $nTeachers; ?></span></a>
                <a href="teachers.php?role=admin" class="btn <?php echo $roleFilter === 'admin' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Admin / Leadership <span class="badge rounded-pill text-bg-light ms-1"><?php echo $nAdmins; ?></span></a>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 tch-table">
            <thead><tr>
                <th class="ps-3">Name</th><th>Cheque Number</th><th>Position</th><th>Phone</th><th>Subjects</th><th>Status</th><th class="text-end pe-3">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $u): $pos = (string)($u['position'] ?? ''); if ($pos === '') $pos = (($u['role'] ?? 'teacher') === 'admin') ? 'admin' : 'teacher';
                  $posColors = ['teacher' => 'secondary', 'academic_master' => 'info', 'second_master' => 'primary', 'headmaster' => 'success', 'admin' => 'dark'];
                  $pc = $posColors[$pos] ?? 'secondary';
                  $words = preg_split('/\s+/', trim((string)$u['full_name']));
                  $ini = $words && $words[0] !== '' ? strtoupper(mb_substr($words[0],0,1) . (count($words) > 1 ? mb_substr(end($words),0,1) : '')) : ''; ?>
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="tch-avatar"><?php echo h($ini); ?></span>
                            <div>
                                <div class="tch-name"><?php echo h($u['full_name']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo h($u['username']); ?></td>
                    <td><span class="badge bg-<?php echo $pc; ?>-subtle text-<?php echo $pc; ?>-emphasis border border-<?php echo $pc; ?>-subtle"><?php echo h(user_title($u)); ?></span></td>
                    <td><?php echo h($u['phone']); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo (int)$u['n_assign']; ?></span></td>
                    <td><?php echo $u['active'] ? '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Off</span>'; ?></td>
                    <td class="text-end pe-3 text-nowrap">
                        <a class="btn btn-outline-secondary btn-sm" href="teacher_form.php?id=<?php echo (int)$u['id']; ?>">Edit</a>
                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Set a new temporary password for this user?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="reset_pw">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <input type="hidden" name="role" value="<?php echo h($roleFilter); ?>">
                                <button class="btn btn-outline-warning btn-sm">Reset password</button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <input type="hidden" name="role" value="<?php echo h($roleFilter); ?>">
                                <button class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7">
                    <div class="tch-empty">
                        <i class="bi bi-people"></i>
                        <div class="fw-semibold text-dark"><?php echo $roleFilter !== '' ? 'No ' . ($roleFilter === 'admin' ? 'admin/leadership' : h($roleFilter)) . ' found.' : 'No users yet.'; ?></div>
                        <div class="small"><?php echo $roleFilter !== '' ? 'Try a different filter.' : 'Click <b>Register teacher</b> to add one.'; ?></div>
                        <?php if ($roleFilter === ''): ?><a class="btn btn-primary btn-sm mt-3" href="<?php echo h(url('admin/teacher_form.php')); ?>"><i class="bi bi-person-plus me-1"></i> Register teacher</a><?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layout_end(); ?>