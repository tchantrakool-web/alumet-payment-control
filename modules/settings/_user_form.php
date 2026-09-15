<?php /** @var array $roles */ ?>
<form method="POST" class="space-y-3">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="create_user">

  <div>
    <label class="mb-1 block text-xs text-gray-500">Username</label>
    <input name="username" x-model="editing.username" required minlength="3" maxlength="50"
           pattern="[A-Za-z0-9._-]+" autocomplete="off"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Full Name</label>
    <input name="full_name" x-model="editing.full_name" required maxlength="150"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Email</label>
    <input type="email" name="email" x-model="editing.email" maxlength="190"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Role</label>
    <select name="role_id" x-model="editing.role_id" required
            class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      <option value="">Select role</option>
      <?php foreach ($roles as $role): ?>
        <option value="<?= (int)$role['id'] ?>"><?= h($role['display_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Password</label>
    <input type="password" name="password" x-model="editing.password" required minlength="8" autocomplete="new-password"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div>
    <label class="mb-1 block text-xs text-gray-500">Confirm Password</label>
    <input type="password" name="password_confirmation" x-model="editing.password_confirmation" required minlength="8" autocomplete="new-password"
           class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
  </div>

  <div class="flex justify-end gap-2 pt-2">
    <button type="button" @click="showForm = false; editing = null"
            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
    <button type="submit" class="theme-btn-primary rounded-lg px-4 py-2 text-sm font-medium">Create User</button>
  </div>
</form>
