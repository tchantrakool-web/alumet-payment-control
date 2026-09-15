<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('settings')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$pageTitle = 'Settings';
$db = getDB();
$user = currentUser();
$canEditSettings = canEdit('settings');

$generalSettingDefs = [
    'company_name' => ['label' => 'Company Name', 'description' => 'Shown in headers and exported reports.'],
    'currency' => ['label' => 'Currency', 'description' => 'Default display currency code.'],
    'date_format' => ['label' => 'Date Format', 'description' => 'Reference format for user-facing screens.'],
    'default_payment_method' => ['label' => 'Default Payment Method', 'description' => 'Used as the preferred method for new requests.'],
    'urgent_threshold' => ['label' => 'Urgent Threshold (THB)', 'description' => 'Reference amount that should be treated as urgent.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canEdit('settings')) {
        flash('error', 'This module is read-only for your role.');
        redirect(BASE_URL . '/modules/settings/');
    }
    if (!verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        flash('error', 'Your session token expired. Please try again.');
        redirect(BASE_URL . '/modules/settings/');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $postedSettings = $_POST['settings'] ?? [];
        $previous = [];
        $updated = [];

        foreach (array_keys($generalSettingDefs) as $key) {
            $previous[$key] = getSetting($key, '');
            $updated[$key] = trim((string) ($postedSettings[$key] ?? ''));
        }

        foreach ($generalSettingDefs as $key => $meta) {
            upsert($db, 'system_settings', [
                'setting_key' => $key,
                'setting_value' => $updated[$key],
                'description' => $meta['description'],
                'updated_by' => $user['id'],
                'updated_at' => sqlNow(),
            ], ['setting_key']);
        }

        auditLog('UPDATE_SETTINGS', 'settings', 0, json_encode($previous), json_encode($updated));
        flash('success', 'General settings updated successfully.');
        redirect(BASE_URL . '/modules/settings/');
    }

    if ($action === 'save_approval_matrix') {
        $existingIds = $_POST['matrix_id'] ?? [];
        $minAmounts = $_POST['min_amount'] ?? [];
        $maxAmounts = $_POST['max_amount'] ?? [];
        $roles = $_POST['approver_role'] ?? [];
        $sequences = $_POST['sequence'] ?? [];
        $activeRows = $_POST['is_active'] ?? [];

        $previous = $db->query("SELECT * FROM approval_matrix ORDER BY sequence, id")->fetchAll();
        $db->beginTransaction();

        try {
            $updateStmt = $db->prepare("
                UPDATE approval_matrix
                SET min_amount = ?, max_amount = ?, approver_role = ?, sequence = ?, is_active = ?
                WHERE id = ?
            ");
            $insertStmt = $db->prepare("
                INSERT INTO approval_matrix (min_amount, max_amount, approver_role, sequence, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");

            foreach ($roles as $idx => $roleName) {
                $roleName = trim((string) $roleName);
                $min = (float) ($minAmounts[$idx] ?? 0);
                $maxRaw = trim((string) ($maxAmounts[$idx] ?? ''));
                $max = $maxRaw === '' ? null : (float) $maxRaw;
                $sequence = max(1, (int) ($sequences[$idx] ?? 1));
                $isActive = isset($activeRows[$idx]) ? 1 : 0;
                $rowId = (int) ($existingIds[$idx] ?? 0);

                if ($roleName === '') {
                    continue;
                }

                if ($rowId > 0) {
                    $updateStmt->execute([$min, $max, $roleName, $sequence, $isActive, $rowId]);
                } else {
                    $insertStmt->execute([$min, $max, $roleName, $sequence, $isActive]);
                }
            }

            $db->commit();
            $current = $db->query("SELECT * FROM approval_matrix ORDER BY sequence, id")->fetchAll();
            auditLog('UPDATE_APPROVAL_MATRIX', 'settings', 0, json_encode($previous), json_encode($current));
            flash('success', 'Approval matrix updated successfully.');
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Unable to save approval matrix.');
        }

        redirect(BASE_URL . '/modules/settings/');
    }
}

$settings = [];
foreach ($generalSettingDefs as $key => $meta) {
    $settings[$key] = getSetting($key, '');
}

$approvalMatrix = $db->query("
    SELECT am.*, r.display_name
    FROM approval_matrix am
    LEFT JOIN roles r ON r.name = am.approver_role
    ORDER BY am.sequence, am.id
")->fetchAll();

$roles = $db->query("SELECT name, display_name FROM roles ORDER BY id")->fetchAll();
$usersByRole = $db->query("
    SELECT r.display_name, COUNT(u.id) AS total_users
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.id AND u.is_active = 1
    GROUP BY r.id, r.display_name
    ORDER BY r.id
")->fetchAll();
$recentAdmins = $db->query("
    SELECT setting_key, setting_value, updated_at
    FROM system_settings
    ORDER BY updated_at DESC, id DESC
    LIMIT 6
")->fetchAll();

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('settings.title') ?></h1>
    <p class="mt-1 text-sm text-gray-500"><?= t('settings.subtitle') ?></p>
  </div>
  <a href="<?= BASE_URL ?>/modules/settings/users.php"
     class="theme-btn-secondary inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium shadow-sm">
    <span aria-hidden="true">👥</span>
    Manage Users
  </a>
</div>
<?php if (!$canEditSettings): ?><div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">View-only access: system settings can only be changed by an administrator.</div><?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <div class="xl:col-span-2 rounded-xl border bg-white p-5">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-gray-800"><?= t('settings.general') ?></h2>
        <p class="text-sm text-gray-500"><?= t('settings.general_desc') ?></p>
      </div>
    </div>

    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_general">
      <fieldset class="contents" <?= !$canEditSettings ? 'disabled' : '' ?>>
      <?php foreach ($generalSettingDefs as $key => $meta): ?>
      <div class="<?= $key === 'company_name' ? 'md:col-span-2' : '' ?>">
        <label class="mb-1 block text-sm font-medium text-gray-700"><?= h($meta['label']) ?></label>
        <input
          type="text"
          name="settings[<?= h($key) ?>]"
          value="<?= h($settings[$key] ?? '') ?>"
          class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
        <p class="mt-1 text-xs text-gray-400"><?= h($meta['description']) ?></p>
      </div>
      <?php endforeach; ?>

      <div class="md:col-span-2 flex justify-end">
        <button type="submit" class="theme-btn-primary rounded-lg px-4 py-2 text-sm font-medium"><?= t('settings.save_general') ?></button>
      </div>
      </fieldset>
    </form>
  </div>

  <div class="rounded-xl border p-5 text-white" style="background:linear-gradient(135deg, #003B5C 0%, #006B3F 100%); border-color:#0A536F;">
    <p class="text-xs uppercase tracking-[0.2em]" style="color:#D9F0B4;"><?= t('settings.control_summary') ?></p>
    <div class="mt-4 space-y-3">
      <div class="rounded-lg bg-white/10 p-3">
        <p class="text-xs" style="color:#DCE8F0;"><?= t('settings.active_rules') ?></p>
        <p class="mt-1 text-2xl font-bold"><?= number_format(count(array_filter($approvalMatrix, static fn($row) => (int) $row['is_active'] === 1))) ?></p>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <p class="text-xs" style="color:#DCE8F0;"><?= t('settings.configured_roles') ?></p>
        <p class="mt-1 text-2xl font-bold"><?= number_format(count($roles)) ?></p>
      </div>
      <div class="rounded-lg bg-white/10 p-3">
        <p class="text-xs" style="color:#DCE8F0;"><?= t('settings.urgent_threshold') ?></p>
        <p class="mt-1 text-2xl font-bold"><?= h($settings['urgent_threshold'] !== '' ? $settings['urgent_threshold'] : t('settings.not_set')) ?></p>
      </div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
  <div class="xl:col-span-2 rounded-xl border bg-white p-5">
    <div class="mb-4">
      <h2 class="text-lg font-semibold text-gray-800"><?= t('settings.approval_matrix') ?></h2>
      <p class="text-sm text-gray-500"><?= t('settings.approval_matrix_desc') ?></p>
    </div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_approval_matrix">
      <fieldset <?= !$canEditSettings ? 'disabled' : '' ?>>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
              <th class="px-3 py-3"><?= t('settings.col.min_amount') ?></th>
              <th class="px-3 py-3"><?= t('settings.col.max_amount') ?></th>
              <th class="px-3 py-3"><?= t('settings.col.approver_role') ?></th>
              <th class="px-3 py-3"><?= t('settings.col.sequence') ?></th>
              <th class="px-3 py-3"><?= t('settings.col.active') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($approvalMatrix as $idx => $row): ?>
            <tr class="border-b last:border-0">
              <td class="px-3 py-3">
                <input type="hidden" name="matrix_id[<?= $idx ?>]" value="<?= (int) $row['id'] ?>">
                <input type="number" step="0.01" min="0" name="min_amount[<?= $idx ?>]" value="<?= h((string) $row['min_amount']) ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2">
              </td>
              <td class="px-3 py-3">
                <input type="number" step="0.01" min="0" name="max_amount[<?= $idx ?>]" value="<?= h((string) $row['max_amount']) ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="No limit">
              </td>
              <td class="px-3 py-3">
                <select name="approver_role[<?= $idx ?>]" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                  <?php foreach ($roles as $role): ?>
                  <option value="<?= h($role['name']) ?>" <?= $role['name'] === $row['approver_role'] ? 'selected' : '' ?>>
                    <?= h($role['display_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="px-3 py-3">
                <input type="number" min="1" name="sequence[<?= $idx ?>]" value="<?= (int) $row['sequence'] ?>" class="w-24 rounded-lg border border-gray-300 px-3 py-2">
              </td>
              <td class="px-3 py-3">
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                  <input type="checkbox" name="is_active[<?= $idx ?>]" value="1" <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>>
                  <?= t('settings.enabled') ?>
                </label>
              </td>
            </tr>
            <?php endforeach; ?>

            <?php for ($extra = 0; $extra < 2; $extra++):
                $idx = count($approvalMatrix) + $extra; ?>
            <tr class="border-b last:border-0" style="background:#F1F9E6;">
              <td class="px-3 py-3">
                <input type="hidden" name="matrix_id[<?= $idx ?>]" value="0">
                <input type="number" step="0.01" min="0" name="min_amount[<?= $idx ?>]" value="" class="w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="New rule">
              </td>
              <td class="px-3 py-3">
                <input type="number" step="0.01" min="0" name="max_amount[<?= $idx ?>]" value="" class="w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Optional">
              </td>
              <td class="px-3 py-3">
                <select name="approver_role[<?= $idx ?>]" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                  <option value="">Select role</option>
                  <?php foreach ($roles as $role): ?>
                  <option value="<?= h($role['name']) ?>"><?= h($role['display_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="px-3 py-3">
                <input type="number" min="1" name="sequence[<?= $idx ?>]" value="<?= $idx + 1 ?>" class="w-24 rounded-lg border border-gray-300 px-3 py-2">
              </td>
              <td class="px-3 py-3">
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                  <input type="checkbox" name="is_active[<?= $idx ?>]" value="1" checked>
                  <?= t('settings.enabled') ?>
                </label>
              </td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex justify-end">
        <button type="submit" class="theme-btn-secondary rounded-lg px-4 py-2 text-sm font-medium"><?= t('settings.save_matrix') ?></button>
      </div>
      </fieldset>
    </form>
  </div>

  <div class="space-y-5">
    <div class="rounded-xl border bg-white p-5">
      <h2 class="text-lg font-semibold text-gray-800"><?= t('settings.users_by_role') ?></h2>
      <div class="mt-4 space-y-3">
        <?php foreach ($usersByRole as $row): ?>
        <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
          <span class="text-sm text-gray-600"><?= h($row['display_name']) ?></span>
          <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" style="background:#ECF7EF; color:#006B3F;"><?= number_format((int) $row['total_users']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rounded-xl border bg-white p-5">
      <h2 class="text-lg font-semibold text-gray-800"><?= t('settings.recent_updates') ?></h2>
      <div class="mt-4 space-y-3">
        <?php foreach ($recentAdmins as $row): ?>
        <div class="rounded-lg border border-gray-100 px-3 py-2">
          <p class="text-sm font-medium text-gray-700"><?= h($row['setting_key']) ?></p>
          <p class="mt-1 text-xs text-gray-500"><?= h($row['setting_value'] ?? '') ?></p>
          <p class="mt-1 text-xs text-gray-400"><?= fmtDateTime($row['updated_at']) ?></p>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recentAdmins)): ?>
        <p class="text-sm text-gray-400"><?= t('settings.no_updates') ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
