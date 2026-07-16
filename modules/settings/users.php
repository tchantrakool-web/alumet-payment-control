<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('settings')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$pageTitle = 'Manage Users';
$db = getDB();
$currentUser = currentUser();
$canManageUsers = canEdit('settings');
$_SESSION['user_management_csrf'] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION['user_management_csrf'];

function loadValidRole(PDO $db, int $roleId): ?array {
    $stmt = $db->prepare('SELECT id, name, display_name FROM roles WHERE id = ?');
    $stmt->execute([$roleId]);
    return $stmt->fetch() ?: null;
}

function validUsername(string $username): bool {
    return preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canEdit('settings')) {
        flash('error', 'User management is read-only for your role.');
        redirect(BASE_URL . '/modules/settings/users.php');
    }
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        flash('error', 'Your session token expired. Please try again.');
        redirect(BASE_URL . '/modules/settings/users.php');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 0);
            $role = loadValidRole($db, $roleId);

            if (!validUsername($username)) throw new RuntimeException('Username must be 3-50 characters using letters, numbers, dot, underscore, or hyphen.');
            if ($fullName === '') throw new RuntimeException('Full name is required.');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Email address is invalid.');
            if (strlen($password) < 8) throw new RuntimeException('Password must contain at least 8 characters.');
            if ($password !== $passwordConfirmation) throw new RuntimeException('Password confirmation does not match.');
            if (!$role) throw new RuntimeException('Please select a valid role.');

            $duplicate = $db->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE');
            $duplicate->execute([$username]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('Username already exists.');

            $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role_id, is_active) VALUES (?,?,?,?,?,1)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email ?: null, $roleId]);
            $newId = (int)$db->lastInsertId();
            auditLog('CREATE_USER', 'users', $newId, '', json_encode(['username' => $username, 'role' => $role['name']]));
            flash('success', "User {$username} created successfully.");
        } elseif ($action === 'update_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $stmtUser = $db->prepare("SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?");
            $stmtUser->execute([$userId]);
            $target = $stmtUser->fetch();
            if (!$target) throw new RuntimeException('User not found.');

            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $roleId = (int)($_POST['role_id'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $newPassword = (string)($_POST['new_password'] ?? '');
            $role = loadValidRole($db, $roleId);

            if ($fullName === '') throw new RuntimeException('Full name is required.');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Email address is invalid.');
            if (!$role) throw new RuntimeException('Please select a valid role.');
            if ($newPassword !== '' && strlen($newPassword) < 8) throw new RuntimeException('New password must contain at least 8 characters.');

            if ($userId === (int)$currentUser['id']) {
                $isActive = 1;
                $roleId = (int)$target['role_id'];
                $role = loadValidRole($db, $roleId);
            }

            if ($target['role_name'] === 'admin' && ($role['name'] !== 'admin' || $isActive === 0)) {
                $activeAdmins = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.is_active=1")->fetchColumn();
                if ($activeAdmins <= 1) throw new RuntimeException('The final active administrator cannot be deactivated or reassigned.');
            }

            $oldValue = json_encode(['full_name' => $target['full_name'], 'email' => $target['email'], 'role' => $target['role_name'], 'is_active' => (int)$target['is_active']]);
            if ($newPassword !== '') {
                $db->prepare("UPDATE users SET full_name=?, email=?, role_id=?, is_active=?, password=?, updated_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$fullName, $email ?: null, $roleId, $isActive, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            } else {
                $db->prepare("UPDATE users SET full_name=?, email=?, role_id=?, is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$fullName, $email ?: null, $roleId, $isActive, $userId]);
            }

            $newValue = json_encode(['full_name' => $fullName, 'email' => $email, 'role' => $role['name'], 'is_active' => $isActive, 'password_reset' => $newPassword !== '']);
            auditLog('UPDATE_USER', 'users', $userId, $oldValue, $newValue);
            flash('success', "User {$target['username']} updated successfully.");
        } elseif ($action === 'delete_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new RuntimeException('Invalid user account.');
            if ($userId === (int)$currentUser['id']) throw new RuntimeException('You cannot remove your own account.');

            $stmtUser = $db->prepare("SELECT u.id,u.username,u.full_name,u.is_active,r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?");
            $stmtUser->execute([$userId]);
            $target = $stmtUser->fetch();
            if (!$target) throw new RuntimeException('User not found.');

            if ($target['role_name'] === 'admin' && (int)$target['is_active'] === 1) {
                $activeAdmins = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.is_active=1")->fetchColumn();
                if ($activeAdmins <= 1) throw new RuntimeException('The final active administrator cannot be removed.');
            }

            $oldValue = json_encode(['username' => $target['username'], 'full_name' => $target['full_name'], 'role' => $target['role_name'], 'is_active' => (int)$target['is_active']]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
            auditLog('DELETE_USER', 'users', $userId, $oldValue, '');
            flash('success', "User {$target['username']} removed successfully.");
        } else {
            throw new RuntimeException('Invalid user-management action.');
        }
    } catch (PDOException $e) {
        $message = strtolower($e->getMessage());
        if ($action === 'delete_user' && str_contains($message, 'foreign key')) {
            flash('error', 'This user has activity history and cannot be permanently removed. Deactivate the account instead.');
        } else {
            flash('error', str_contains($message, 'unique') ? 'Username already exists.' : 'Unable to save the user.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }

    redirect(BASE_URL . '/modules/settings/users.php');
}

$roles = $db->query('SELECT id, name, display_name, description FROM roles ORDER BY id')->fetchAll();
$users = $db->query("SELECT u.id,u.username,u.full_name,u.email,u.role_id,u.is_active,u.created_at,u.updated_at,r.name AS role_name,r.display_name AS role_display FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY u.is_active DESC,u.full_name,u.username")->fetchAll();
$activeUsers = count(array_filter($users, static fn($user): bool => (int)$user['is_active'] === 1));

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
  <div>
    <a href="<?= BASE_URL ?>/modules/settings/" class="text-sm text-blue-600 hover:underline">← Back to Settings</a>
    <h1 class="mt-2 text-2xl font-bold text-gray-800">Manage Users</h1>
    <p class="mt-1 text-sm text-gray-500">Create accounts, assign access roles, reset passwords, and control account status.</p>
  </div>
  <div class="rounded-xl border bg-white px-4 py-3 text-right">
    <p class="text-xs text-gray-500">Active users</p>
    <p class="text-xl font-bold text-green-700"><?= number_format($activeUsers) ?> / <?= number_format(count($users)) ?></p>
  </div>
</div>

<?php if (!$canManageUsers): ?><div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">View-only access: user accounts can only be changed by an administrator.</div><?php endif; ?>
<?php if ($canManageUsers): ?>
<div class="mb-6 rounded-xl border bg-white p-5">
  <h2 class="text-lg font-semibold text-gray-800">Create User</h2>
  <p class="mb-4 text-sm text-gray-500">New accounts are active immediately.</p>
  <form method="POST" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="action" value="create_user">
    <div><label class="mb-1 block text-xs text-gray-500">Username</label><input name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="off" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></div>
    <div><label class="mb-1 block text-xs text-gray-500">Full Name</label><input name="full_name" required maxlength="150" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></div>
    <div><label class="mb-1 block text-xs text-gray-500">Email</label><input type="email" name="email" maxlength="190" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></div>
    <div><label class="mb-1 block text-xs text-gray-500">Role</label><select name="role_id" required class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><option value="">Select role</option><?php foreach ($roles as $role): ?><option value="<?= (int)$role['id'] ?>"><?= h($role['display_name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="mb-1 block text-xs text-gray-500">Password</label><input type="password" name="password" required minlength="8" autocomplete="new-password" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></div>
    <div><label class="mb-1 block text-xs text-gray-500">Confirm Password</label><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></div>
    <div class="md:col-span-2 xl:col-span-6 flex justify-end"><button class="theme-btn-primary rounded-lg px-5 py-2 text-sm font-medium">Create User</button></div>
  </form>
</div>
<?php endif; ?>

<div class="rounded-xl border bg-white overflow-hidden">
  <div class="border-b px-5 py-4"><h2 class="text-lg font-semibold text-gray-800">User Accounts</h2><p class="text-sm text-gray-500">Leave “New Password” blank to keep the current password.</p></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-4 py-3">Account</th><th class="px-4 py-3">Full Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">New Password</th><th class="px-4 py-3">Active</th><th class="px-4 py-3"></th></tr></thead>
      <tbody>
      <?php foreach ($users as $managedUser): $isSelf = (int)$managedUser['id'] === (int)$currentUser['id']; $formId = 'userForm' . (int)$managedUser['id']; ?>
        <tr class="border-b last:border-0 align-top <?= (int)$managedUser['is_active'] === 0 ? 'bg-gray-50 opacity-75' : '' ?>">
          <td class="px-4 py-3"><form id="<?= $formId ?>" method="POST"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int)$managedUser['id'] ?>"></form><div class="font-mono font-semibold text-gray-800"><?= h($managedUser['username']) ?></div><div class="mt-1 text-xs text-gray-400">Created <?= fmtDate($managedUser['created_at']) ?><?= $isSelf ? ' · You' : '' ?></div></td>
          <td class="px-4 py-3"><input form="<?= $formId ?>" name="full_name" required value="<?= h($managedUser['full_name']) ?>" class="theme-input min-w-44 rounded-lg border border-gray-300 px-3 py-2 disabled:bg-gray-100" <?= !$canManageUsers ? 'disabled' : '' ?>></td>
          <td class="px-4 py-3"><input form="<?= $formId ?>" type="email" name="email" value="<?= h((string)$managedUser['email']) ?>" class="theme-input min-w-52 rounded-lg border border-gray-300 px-3 py-2 disabled:bg-gray-100" <?= !$canManageUsers ? 'disabled' : '' ?>></td>
          <td class="px-4 py-3"><select form="<?= $formId ?>" name="role_id" class="theme-input min-w-40 rounded-lg border border-gray-300 px-3 py-2 disabled:bg-gray-100" <?= ($isSelf || !$canManageUsers) ? 'disabled' : '' ?>><?php foreach ($roles as $role): ?><option value="<?= (int)$role['id'] ?>" <?= (int)$role['id'] === (int)$managedUser['role_id'] ? 'selected' : '' ?>><?= h($role['display_name']) ?></option><?php endforeach; ?></select><?php if ($isSelf && $canManageUsers): ?><input form="<?= $formId ?>" type="hidden" name="role_id" value="<?= (int)$managedUser['role_id'] ?>"><?php endif; ?></td>
          <td class="px-4 py-3"><?php if ($canManageUsers): ?><input form="<?= $formId ?>" type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Keep current" class="theme-input min-w-36 rounded-lg border border-gray-300 px-3 py-2"><?php else: ?><span class="text-gray-400">—</span><?php endif; ?></td>
          <td class="px-4 py-3 text-center"><input form="<?= $formId ?>" type="checkbox" name="is_active" value="1" <?= (int)$managedUser['is_active'] === 1 ? 'checked' : '' ?> <?= ($isSelf || !$canManageUsers) ? 'disabled' : '' ?>><?php if ($isSelf && $canManageUsers): ?><input form="<?= $formId ?>" type="hidden" name="is_active" value="1"><?php endif; ?></td>
          <td class="px-4 py-3">
            <?php if ($canManageUsers): ?><div class="flex items-center gap-2 whitespace-nowrap">
              <button form="<?= $formId ?>" class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-medium text-white hover:bg-blue-700">Save</button>
              <?php if (!$isSelf): ?>
                <form method="POST" onsubmit="return confirm('Remove this user permanently? This action cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="user_id" value="<?= (int)$managedUser['id'] ?>">
                  <button type="submit" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-100">Remove</button>
                </form>
              <?php endif; ?>
            </div><?php else: ?><span class="text-xs font-medium text-gray-400">View only</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
