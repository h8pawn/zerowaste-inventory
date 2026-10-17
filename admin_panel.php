<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
require_role($user, ['admin', 'manager']);
$isAdmin = is_admin($user);

$allRoles = ['staff' => 'Staff', 'manager' => 'Manager', 'admin' => 'Admin'];
// Managers can add and remove staff and managers, but cannot create admins.
$roles = $isAdmin ? $allRoles : array_diff_key($allRoles, ['admin' => true]);

function active_admin_count(): int {
    return (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
}

// Admins can manage every account; managers only staff and other managers.
function can_manage_account(array $actor, array $target): bool {
    return is_admin($actor) || $target['role'] !== 'admin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $target = $targetId ? db_one('SELECT id, username, role, is_active FROM users WHERE id = ?', 'i', [$targetId]) : null;
    $isSelf = $targetId === $user['id'];

    if ($target && !can_manage_account($user, $target)) {
        flash('error', 'Only an admin can change an admin account.');
        redirect('admin_panel.php');
    }
    if ($action === 'reset_system' && !$isAdmin) {
        flash('error', 'Only an admin can reset system data.');
        redirect('admin_panel.php');
    }

    switch ($action) {
        case 'create_account':
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? 'staff');

            if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A username and a valid email are required.');
            } elseif (strlen($password) < 6) {
                flash('error', 'Password must be at least 6 characters.');
            } elseif ($password !== ($_POST['confirm_password'] ?? '')) {
                flash('error', 'Passwords do not match.');
            } elseif (!isset($roles[$role])) {
                flash('error', isset($allRoles[$role]) ? 'Only an admin can create admin accounts.' : 'Invalid role selected.');
            } elseif (db_one('SELECT id FROM users WHERE email = ? OR username = ?', 'ss', [$email, $username])) {
                flash('error', 'Email or username already exists.');
            } else {
                db_exec(
                    'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)',
                    'ssss',
                    [$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]
                );
                flash('success', 'Account "' . $username . '" created and active.');
            }
            break;

        case 'edit_user':
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            $losesAdmin = $target && $target['role'] === 'admin' && $target['is_active'] && ($role !== 'admin' || !$active);

            if (!$target) {
                flash('error', 'User not found.');
            } elseif ($username === '' || mb_strlen($username) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A username (max 100 characters) and a valid email are required.');
            } elseif (!isset($roles[$role])) {
                flash('error', isset($allRoles[$role]) ? 'Only an admin can give the admin role.' : 'Invalid role selected.');
            } elseif ($isSelf && ($role !== $user['role'] || !$active)) {
                flash('error', 'You cannot change your own role or deactivate yourself.');
            } elseif ($losesAdmin && active_admin_count() <= 1) {
                flash('error', 'There must be at least one active admin.');
            } elseif (db_one('SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ?', 'ssi', [$email, $username, $targetId])) {
                flash('error', 'Another account already uses that username or email.');
            } else {
                db_exec(
                    'UPDATE users SET username = ?, email = ?, role = ?, is_active = ? WHERE id = ?',
                    'sssii',
                    [$username, $email, $role, $active, $targetId]
                );
                flash('success', 'Account "' . $username . '" updated.');
            }
            break;

        case 'update_role':
            $role = (string) ($_POST['role'] ?? '');
            if (!$target || !isset($roles[$role])) {
                flash('error', isset($allRoles[$role]) && $target ? 'Only an admin can give the admin role.' : 'Invalid role update request.');
            } elseif ($isSelf) {
                flash('error', 'You cannot change your own role.');
            } elseif ($target['role'] === 'admin' && $role !== 'admin' && $target['is_active'] && active_admin_count() <= 1) {
                flash('error', 'There must be at least one active admin.');
            } else {
                db_exec('UPDATE users SET role = ? WHERE id = ?', 'si', [$role, $targetId]);
                flash('success', $target['username'] . ' is now ' . $roles[$role] . '.');
            }
            break;

        case 'activate':
        case 'deactivate':
            $activate = $action === 'activate';
            if (!$target) {
                flash('error', 'User not found.');
            } elseif ($isSelf && !$activate) {
                flash('error', 'You cannot deactivate your own account.');
            } elseif (!$activate && $target['role'] === 'admin' && active_admin_count() <= 1) {
                flash('error', 'There must be at least one active admin.');
            } else {
                db_exec('UPDATE users SET is_active = ? WHERE id = ?', 'ii', [$activate ? 1 : 0, $targetId]);
                flash('success', $target['username'] . ($activate ? ' can now sign in.' : ' has been deactivated.'));
            }
            break;

        case 'reset_password':
            $password = (string) ($_POST['password'] ?? '');
            if (!$target) {
                flash('error', 'User not found.');
            } elseif (strlen($password) < 6) {
                flash('error', 'New password must be at least 6 characters.');
            } else {
                db_exec('UPDATE users SET password = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $targetId]);
                flash('success', 'Password for ' . $target['username'] . ' has been reset.');
            }
            break;

        case 'delete_account':
            if (!$target) {
                flash('error', 'User not found.');
                break;
            }
            // Waste logs and returns keep the name of whoever recorded them, so those accounts can't be removed.
            $history = db_one(
                'SELECT (SELECT COUNT(*) FROM waste_logs WHERE user_id = ?) AS waste, (SELECT COUNT(*) FROM item_returns WHERE user_id = ?) AS returns_count',
                'ii',
                [$targetId, $targetId]
            );
            if ($isSelf) {
                flash('error', 'You cannot delete your own account.');
            } elseif ($target['role'] === 'admin' && $target['is_active'] && active_admin_count() <= 1) {
                flash('error', 'There must be at least one active admin.');
            } elseif ((int) $history['waste'] + (int) $history['returns_count'] > 0) {
                flash('error', $target['username'] . ' has ' . (int) $history['waste'] . ' waste log(s) and ' . (int) $history['returns_count']
                    . ' return(s) on record, so the account is kept for that history. Deactivate it instead to stop them signing in.');
            } else {
                db_exec('DELETE FROM users WHERE id = ?', 'i', [$targetId]);
                flash('success', 'Account "' . $target['username'] . '" deleted.');
            }
            break;

        case 'reset_system':
            if (trim((string) ($_POST['reset_phrase'] ?? '')) !== 'RESET ALL DATA') {
                flash('error', 'Confirmation phrase is incorrect. Type RESET ALL DATA exactly.');
                break;
            }
            try {
                db_exec('SET FOREIGN_KEY_CHECKS = 0');
                db_transaction(function () {
                    foreach (['customer_order_items', 'customer_orders', 'item_returns', 'stock_receipts', 'waste_logs', 'supplier_delivery_events', 'supplier_deliveries', 'food_items', 'products', 'suppliers'] as $table) {
                        if (db_table_exists($table)) {
                            db_exec("DELETE FROM `$table`");
                        }
                    }
                });
                flash('success', 'System data reset. User accounts, categories and waste reasons were kept.');
            } catch (Throwable $e) {
                flash('error', 'Reset failed: ' . $e->getMessage());
            } finally {
                db_exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            break;
    }

    redirect('admin_panel.php');
}

$users = db_all(
    'SELECT u.id, u.username, u.email, u.role, u.is_active, u.created_at, COUNT(wl.id) AS waste_entries
     FROM users u
     LEFT JOIN waste_logs wl ON wl.user_id = u.id
     GROUP BY u.id
     ORDER BY u.is_active ASC, u.created_at DESC'
);
$pending = array_values(array_filter($users, fn ($u) => !$u['is_active']));

$stats = db_one(
    "SELECT (SELECT COUNT(*) FROM users) AS users,
            (SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
            (SELECT COUNT(*) FROM food_items) AS items,
            (SELECT COUNT(*) FROM waste_logs) AS waste_entries,
            (SELECT COALESCE(SUM(estimated_value), 0) FROM waste_logs) AS waste_value"
);

$roleTones = ['admin' => 'purple', 'manager' => 'blue', 'staff' => 'gray'];

render_shell_start($user, $isAdmin ? 'Users & System' : 'User Accounts', 'admin');
page_header(
    $isAdmin ? 'Users & System' : 'User Accounts',
    $isAdmin ? 'Approve sign-ups, add and delete accounts, manage roles, and maintain system data.' : 'Approve sign-ups and add or delete staff and manager accounts.',
    '<button type="button" class="btn btn-primary" data-open-modal="createUserModal"><i class="fa-solid fa-user-plus"></i> Add User</button>'
);
?>

<div class="stats">
    <div class="stat">
        <span class="stat-icon tone-green"><i class="fa-solid fa-users"></i></span>
        <span><div class="stat-label">Users</div><div class="stat-value"><?= (int) $stats['users'] ?></div><div class="stat-sub"><?= (int) $stats['active_users'] ?> active · <?= count($pending) ?> inactive</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-boxes-stacked"></i></span>
        <span><div class="stat-label">Item records</div><div class="stat-value"><?= number_format((int) $stats['items']) ?></div><div class="stat-sub">Including archived</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-trash-can"></i></span>
        <span><div class="stat-label">Waste entries</div><div class="stat-value"><?= number_format((int) $stats['waste_entries']) ?></div><div class="stat-sub">All time</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-peso-sign"></i></span>
        <span><div class="stat-label">Total loss</div><div class="stat-value"><?= peso($stats['waste_value'], 0) ?></div><div class="stat-sub">All time</div></span>
    </div>
</div>

<?php if ($pending): ?>
    <div class="card" style="margin-top:18px;border-color:var(--amber-bd)">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-user-clock" style="color:var(--amber-fg)"></i> Waiting for approval</div>
                <div class="card-sub">New sign-ups and deactivated accounts. Activate to let them sign in.</div>
            </div>
        </div>
        <div class="card-body">
            <ul class="list">
                <?php foreach ($pending as $p): ?>
                    <li>
                        <div>
                            <div class="cell-title"><?= e($p['username']) ?> <span class="chip chip-<?= $roleTones[$p['role']] ?? 'gray' ?>"><?= e(ucfirst($p['role'])) ?></span></div>
                            <div class="cell-sub"><?= e($p['email']) ?> · signed up <?= date('M j, Y', strtotime($p['created_at'])) ?></div>
                        </div>
                        <?php if (can_manage_account($user, $p)): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-check"></i> Activate</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Admin approves</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="table-card" style="margin-top:18px">
    <div class="card-head" style="padding-bottom:14px">
        <div>
            <div class="card-title"><i class="fa-solid fa-user-shield"></i> All accounts</div>
            <div class="card-sub">Staff: view and print only · Managers: add items, suppliers and users, delete accounts · Admins: everything, including admin accounts and store settings</div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th>User</th><th>Role</th><th>Status</th><th class="center">Waste logged</th><th>Joined</th><th class="center">Action</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $self = (int) $u['id'] === $user['id']; $locked = !can_manage_account($user, $u); ?>
                <tr>
                    <td>
                        <div class="cell-title"><?= e($u['username']) ?><?= $self ? ' <span class="muted">(you)</span>' : '' ?></div>
                        <div class="cell-sub"><?= e($u['email']) ?></div>
                    </td>
                    <td>
                        <?php if ($self || $locked): ?>
                            <span class="chip chip-<?= $roleTones[$u['role']] ?? 'gray' ?>"><?= e(ucfirst($u['role'])) ?></span>
                        <?php else: ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <select class="select" name="role" style="height:32px" onchange="this.form.submit()" aria-label="Role for <?= e($u['username']) ?>">
                                    <?php foreach ($roles as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $u['role'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript><button type="submit" class="btn btn-sm">Save</button></noscript>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><span class="chip chip-<?= $u['is_active'] ? 'green' : 'red' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="center"><?= (int) $u['waste_entries'] ?></td>
                    <td class="nowrap"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td class="center">
                        <?php if ($locked): ?>
                            <span class="muted" title="Only an admin can change admin accounts"><i class="fa-solid fa-lock"></i></span>
                        <?php else: ?>
                        <div class="menu-wrap">
                            <button type="button" class="kebab" data-menu aria-label="Actions for <?= e($u['username']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            <div class="menu">
                                <button type="button" class="menu-item" data-edit-user='<?= e(json_encode([
                                    'id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'],
                                    'role' => $u['role'], 'is_active' => (bool) $u['is_active'], 'self' => $self,
                                ])) ?>'><i class="fa-solid fa-user-pen"></i> Edit user</button>
                                <button type="button" class="menu-item" data-reset-pw="<?= (int) $u['id'] ?>" data-name="<?= e($u['username']) ?>"><i class="fa-solid fa-key"></i> Reset password</button>
                                <?php if (!$self): ?>
                                    <form method="POST" <?= $u['is_active'] ? 'data-confirm="Deactivate ' . e($u['username']) . '? They will be signed out immediately."' : '' ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="<?= $u['is_active'] ? 'deactivate' : 'activate' ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <?php if ($u['is_active']): ?>
                                            <button type="submit" class="menu-item danger"><i class="fa-solid fa-user-slash"></i> Deactivate</button>
                                        <?php else: ?>
                                            <button type="submit" class="menu-item"><i class="fa-solid fa-user-check"></i> Activate</button>
                                        <?php endif; ?>
                                    </form>
                                    <div class="menu-sep"></div>
                                    <form method="POST" data-confirm="Delete the account &quot;<?= e($u['username']) ?>&quot; permanently? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="menu-item danger"><i class="fa-solid fa-user-xmark"></i> Delete account</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="card" style="margin-top:18px;border-color:var(--red-bd)">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--red-fg)"></i> Danger zone — reset system data</div>
            <div class="card-sub">Permanently deletes all food items (including the archive), product templates, suppliers and their deliveries, waste logs, returns, stock receipts and online orders. Users, categories, waste reasons and store settings are kept.</div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" class="toolbar" style="margin:0" data-confirm="This permanently deletes all inventory and waste data. Continue?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_system">
            <input class="input" type="text" name="reset_phrase" placeholder="Type RESET ALL DATA" required autocomplete="off" style="min-width:240px" aria-label="Confirmation phrase">
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Reset system data</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal" id="createUserModal" role="dialog" aria-modal="true" aria-labelledby="createUserTitle">
    <form class="modal-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_account">
        <div class="modal-head">
            <h2 id="createUserTitle">Create account</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field">
                    <label for="newUsername">Username <span class="req">*</span></label>
                    <input class="input" type="text" name="username" id="newUsername" required maxlength="100" autocomplete="off">
                </div>
                <div class="field">
                    <label for="newEmail">Email <span class="req">*</span></label>
                    <input class="input" type="email" name="email" id="newEmail" required autocomplete="off">
                </div>
                <div class="field">
                    <label for="newPassword">Password <span class="req">*</span></label>
                    <input class="input" type="password" name="password" id="newPassword" minlength="6" required autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="newConfirm">Confirm password <span class="req">*</span></label>
                    <input class="input" type="password" name="confirm_password" id="newConfirm" minlength="6" required autocomplete="new-password">
                </div>
                <div class="field span-2">
                    <label for="newRole">Role</label>
                    <select class="select" name="role" id="newRole">
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Accounts created here are active immediately.</span>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Create account</button>
        </div>
    </form>
</div>

<div class="modal" id="editUserModal" role="dialog" aria-modal="true" aria-labelledby="editUserTitle">
    <form class="modal-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-head">
            <h2 id="editUserTitle">Edit user</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field">
                    <label for="editUsername">Username <span class="req">*</span></label>
                    <input class="input" type="text" name="username" id="editUsername" required maxlength="100" autocomplete="off">
                </div>
                <div class="field">
                    <label for="editEmail">Email <span class="req">*</span></label>
                    <input class="input" type="email" name="email" id="editEmail" required autocomplete="off">
                </div>
                <div class="field">
                    <label for="editRole">Role</label>
                    <select class="select" name="role" id="editRole">
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <span class="field-label">Status</span>
                    <label class="check" style="height:38px"><input type="checkbox" name="is_active" id="editActive" value="1"> Active (can sign in)</label>
                </div>
            </div>
            <p class="hint muted" id="editSelfNote" style="margin-top:0">You can't change your own role or deactivate yourself.</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
        </div>
    </form>
</div>

<div class="modal" id="resetPwModal" role="dialog" aria-modal="true" aria-labelledby="resetPwTitle">
    <form class="modal-card sm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetPwId">
        <div class="modal-head">
            <h2 id="resetPwTitle">Reset password</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p class="muted" style="margin-top:0">Set a new password for <strong id="resetPwName"></strong> and share it with them.</p>
            <div class="field">
                <label for="resetPwInput">New password</label>
                <input class="input" type="text" name="password" id="resetPwInput" minlength="6" required autocomplete="off">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Reset password</button>
        </div>
    </form>
</div>

<?php ob_start(); ?>
<script>
document.addEventListener('click', (e) => {
    const editBtn = e.target.closest('[data-edit-user]');
    if (editBtn) {
        const u = JSON.parse(editBtn.dataset.editUser);
        document.getElementById('editUserId').value = u.id;
        document.getElementById('editUsername').value = u.username;
        document.getElementById('editEmail').value = u.email;
        document.getElementById('editRole').value = u.role;
        document.getElementById('editActive').checked = u.is_active;
        // Your own role and status are locked; disabled fields aren't submitted, so mirror them in hidden inputs.
        const role = document.getElementById('editRole');
        const active = document.getElementById('editActive');
        role.disabled = active.disabled = u.self;
        document.getElementById('editSelfNote').classList.toggle('hidden', !u.self);
        document.querySelectorAll('#editUserModal .self-lock').forEach((el) => el.remove());
        if (u.self) {
            role.insertAdjacentHTML('afterend', `<input type="hidden" class="self-lock" name="role" value="${escapeHtml(u.role)}">`);
            active.insertAdjacentHTML('afterend', '<input type="hidden" class="self-lock" name="is_active" value="1">');
        }
        document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
        openModal('editUserModal');
        return;
    }

    const btn = e.target.closest('[data-reset-pw]');
    if (!btn) return;
    document.getElementById('resetPwId').value = btn.dataset.resetPw;
    document.getElementById('resetPwName').textContent = btn.dataset.name;
    document.getElementById('resetPwInput').value = '';
    document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
    openModal('resetPwModal');
});
</script>
<?php render_shell_end(ob_get_clean()); ?>
