<?php
/** @var array $managedUser */
/** @var array $roles */
/** @var bool $isSelf */
$userId = (int)$managedUser['id'];
?>
<form method="POST" class="space-y-3">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="update_user">
  <input type="hidden" name="user_id" value="<?= $userId ?>">

  <div>
    <label class="mb-1 block text-xs text-gray-500">Username</label>
    <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-mono"><?= h($managedUser['username']) ?></p>
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Full Name</label>
    <input name="full_name" value="<?= h($managedUser['full_name']) ?>" required maxlength="150"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Email</label>
    <input type="email" name="email" value="<?= h((string)($managedUser['email'] ?? '')) ?>" maxlength="190"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Role</label>
    <select name="role_id" required <?= $isSelf ? 'disabled' : '' ?>
            class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100">
      <?php foreach ($roles as $role): ?>
        <option value="<?= (int)$role['id'] ?>" <?= (int)$role['id'] === (int)$managedUser['role_id'] ? 'selected' : '' ?>><?= h($role['display_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isSelf): ?>
    <input type="hidden" name="role_id" value="<?= (int)$managedUser['role_id'] ?>">
    <p class="mt-1 text-xs text-gray-400">You cannot change your own role.</p>
    <?php endif; ?>
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">New Password <span class="text-gray-400">(leave blank to keep current)</span></label>
    <input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Keep current"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <label class="flex items-center gap-2 text-sm text-gray-700">
    <input type="checkbox" name="is_active" value="1" <?= (int)$managedUser['is_active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
    Active
    <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
  </label>

  <div class="flex justify-end gap-2 pt-2">
    <button type="button" @click="open[<?= $userId ?>] = false"
            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
    <button type="submit" class="theme-btn-primary rounded-lg px-4 py-2 text-sm font-medium">Save Changes</button>
  </div>
</form>
