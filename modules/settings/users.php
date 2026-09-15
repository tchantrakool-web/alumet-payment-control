<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('settings')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$pageTitle = 'Manage Users';
$db = getDB();
$currentUser = currentUser();
$canManageUsers = canEdit('settings');

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
    if (!verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
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

<div x-data="{ showForm: false, editing: null, open: {}, closeAll() { this.showForm = false; this.open = {}; } }">

  <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
      <a href="<?= BASE_URL ?>/modules/settings/" class="text-sm text-blue-600 hover:underline">← Back to Settings</a>
      <h1 class="mt-2 text-2xl font-bold text-gray-800">Manage Users</h1>
      <p class="mt-1 text-sm text-gray-500">Create accounts, assign access roles, reset passwords, and control account status.</p>
    </div>
    <div class="flex items-center gap-3">
      <div class="rounded-xl border bg-white px-4 py-3 text-right">
        <p class="text-xs text-gray-500">Active users</p>
        <p class="text-xl font-bold text-green-700"><?= number_format($activeUsers) ?> / <?= number_format(count($users)) ?></p>
      </div>
      <?php if ($canManageUsers): ?>
      <button type="button"
              @click="closeAll(); editing = { username: '', full_name: '', email: '', role_id: '', password: '', password_confirmation: '' }; showForm = true"
              class="theme-btn-primary rounded-lg px-4 py-2 text-sm font-medium">Insert User</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$canManageUsers): ?><div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">View-only access: user accounts can only be changed by an administrator.</div><?php endif; ?>

  <div class="rounded-xl border bg-white overflow-hidden">
    <div class="border-b px-5 py-4"><h2 class="text-lg font-semibold text-gray-800">User Accounts</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-4 py-3">Account</th><th class="px-4 py-3">Full Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Active</th><th class="px-4 py-3"></th></tr></thead>
        <tbody>
        <?php foreach ($users as $managedUser): $isSelf = (int)$managedUser['id'] === (int)$currentUser['id']; ?>
          <tr class="border-b last:border-0 align-top <?= (int)$managedUser['is_active'] === 0 ? 'bg-gray-50 opacity-75' : '' ?>">
            <td class="px-4 py-3">
              <div class="font-mono font-semibold text-gray-800"><?= h($managedUser['username']) ?></div>
              <div class="mt-1 text-xs text-gray-400">Created <?= fmtDate($managedUser['created_at']) ?><?= $isSelf ? ' · You' : '' ?></div>
            </td>
            <td class="px-4 py-3"><?= h($managedUser['full_name']) ?></td>
            <td class="px-4 py-3"><?= h((string)($managedUser['email'] ?: '-')) ?></td>
            <td class="px-4 py-3"><?= h($managedUser['role_display'] ?? '') ?></td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium <?= (int)$managedUser['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600' ?>">
                <?= (int)$managedUser['is_active'] === 1 ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <?php if ($canManageUsers): ?>
              <div class="flex items-center gap-2 whitespace-nowrap">
                <button type="button" @click="closeAll(); open[<?= (int)$managedUser['id'] ?>] = true"
                        class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Edit</button>
                <?php if (!$isSelf): ?>
                  <form method="POST" onsubmit="return confirm('Remove this user permanently? This action cannot be undone.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$managedUser['id'] ?>">
                    <button type="submit" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-100">Remove</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php else: ?><span class="text-xs font-medium text-gray-400">View only</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
  dialogOpen('showForm', 'Insert User', 'max-w-md');
  include __DIR__ . '/_user_form.php';
  dialogClose();

  // One dialog per row, rendered here (after the table, never inside it —
  // a <div> is not valid table content and the browser would hoist it out,
  // away from this x-data scope). Each dialog is permanently bound to its
  // own row's data, so there's no shared "editing" state to get wrong.
  if ($canManageUsers):
      foreach ($users as $managedUser):
          $isSelf = (int)$managedUser['id'] === (int)$currentUser['id'];
          $userId = (int)$managedUser['id'];
          dialogOpen("open[{$userId}]", 'Edit User: ' . $managedUser['username'], 'max-w-md', null, "open[{$userId}] = false");
          include __DIR__ . '/_user_edit_form.php';
          dialogClose();
      endforeach;
  endif;
  ?>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
