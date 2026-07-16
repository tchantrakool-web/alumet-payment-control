<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('import')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Import Center';
$db = getDB();

// Recent batches
$sapBatches = $db->query("SELECT b.*, u.full_name FROM sap_import_batches b LEFT JOIN users u ON u.id=b.imported_by ORDER BY b.id DESC LIMIT 20")->fetchAll();
$finBatches = $db->query("SELECT b.*, u.full_name FROM finance_import_batches b LEFT JOIN users u ON u.id=b.imported_by ORDER BY b.id DESC LIMIT 20")->fetchAll();

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center justify-between">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('import.title') ?></h1>
    <p class="text-gray-500 text-sm"><?= t('import.subtitle') ?></p>
  </div>
</div>

<!-- Upload Cards -->
<?php if (!canEdit('import')): ?>
<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">View-only access: importing data is assigned to Maker and Finance roles.</div>
<?php endif; ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">

  <!-- SAP Import -->
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 text-xl">📊</div>
      <div>
        <h3 class="font-semibold text-gray-800"><?= t('import.sap.title') ?></h3>
        <p class="text-xs text-gray-500"><?= t('import.sap.desc') ?></p>
      </div>
    </div>
    <?php if (canEdit('import')): ?><form method="POST" action="<?= BASE_URL ?>/modules/import/upload_sap.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center mb-3 hover:border-blue-400 transition-colors">
        <input type="file" name="excel_file" id="sapFile" accept=".xlsx,.xls,.csv" class="hidden"
               onchange="document.getElementById('sapName').textContent = this.files[0]?.name || '<?= t('import.sap.click') ?>'">
        <label for="sapFile" class="cursor-pointer">
          <div class="text-2xl mb-1">📁</div>
          <p class="text-sm text-gray-600" id="sapName"><?= t('import.sap.click') ?></p>
          <p class="text-xs text-gray-400"><?= t('import.supported') ?></p>
        </label>
      </div>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium transition-colors">
        <?= t('import.sap.btn') ?>
      </button>
    </form><?php else: ?><p class="rounded-lg bg-gray-50 p-4 text-center text-sm text-gray-500">Import history is available below.</p><?php endif; ?>
  </div>

  <!-- Finance Import -->
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center text-green-600 text-xl">📋</div>
      <div>
        <h3 class="font-semibold text-gray-800"><?= t('import.finance.title') ?></h3>
        <p class="text-xs text-gray-500"><?= t('import.finance.desc') ?></p>
      </div>
    </div>
    <?php if (canEdit('import')): ?><form method="POST" action="<?= BASE_URL ?>/modules/import/upload_finance.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center mb-3 hover:border-green-400 transition-colors">
        <input type="file" name="excel_file" id="finFile" accept=".xlsx,.csv" class="hidden"
               onchange="document.getElementById('finName').textContent = this.files[0]?.name || '<?= t('import.finance.click') ?>'">
        <label for="finFile" class="cursor-pointer">
          <div class="text-2xl mb-1">📁</div>
          <p class="text-sm text-gray-600" id="finName"><?= t('import.finance.click') ?></p>
          <p class="text-xs text-gray-400"><?= t('import.supported') ?></p>
        </label>
      </div>
      <div class="mb-3">
        <input type="text" name="period" placeholder="<?= t('import.finance.period_ph') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 outline-none">
      </div>
      <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-sm font-medium transition-colors">
        <?= t('import.finance.btn') ?>
      </button>
    </form><?php else: ?><p class="rounded-lg bg-gray-50 p-4 text-center text-sm text-gray-500">Import history is available below.</p><?php endif; ?>
  </div>
</div>

<!-- Import History -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

  <!-- SAP Batches -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold text-gray-700 mb-3"><?= t('import.sap.history') ?></h3>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-xs text-gray-500 border-b">
        <th class="pb-2"><?= t('label.batch_no') ?></th>
        <th class="pb-2"><?= t('import.col.file') ?></th>
        <th class="pb-2 text-right"><?= t('label.records') ?></th>
        <th class="pb-2 text-right"><?= t('label.errors') ?></th>
        <th class="pb-2"><?= t('label.date') ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($sapBatches as $b): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="py-1.5 font-mono text-xs"><?= h($b['batch_no']) ?></td>
          <td class="py-1.5 text-xs text-gray-600 max-w-[120px] truncate" title="<?= h($b['filename']) ?>"><?= h($b['filename']) ?></td>
          <td class="py-1.5 text-right"><?= $b['imported_records'] ?></td>
          <td class="py-1.5 text-right <?= $b['error_records'] > 0 ? 'text-red-500' : 'text-gray-400' ?>"><?= $b['error_records'] ?></td>
          <td class="py-1.5 text-xs text-gray-500"><?= fmtDateTime($b['imported_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($sapBatches)): ?><tr><td colspan="5" class="py-4 text-center text-gray-400"><?= t('msg.no_imports') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Finance Batches -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold text-gray-700 mb-3"><?= t('import.finance.history') ?></h3>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-xs text-gray-500 border-b">
        <th class="pb-2"><?= t('label.batch_no') ?></th>
        <th class="pb-2"><?= t('label.period') ?></th>
        <th class="pb-2 text-right"><?= t('label.records') ?></th>
        <th class="pb-2 text-right"><?= t('label.errors') ?></th>
        <th class="pb-2"><?= t('label.date') ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($finBatches as $b): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="py-1.5 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/import/finance_detail.php?id=<?= (int)$b['id'] ?>" class="text-blue-600 hover:underline"><?= h($b['batch_no']) ?></a>
          </td>
          <td class="py-1.5 text-xs"><?= h($b['period'] ?? '-') ?></td>
          <td class="py-1.5 text-right"><?= $b['imported_records'] ?></td>
          <td class="py-1.5 text-right <?= $b['error_records'] > 0 ? 'text-red-500' : 'text-gray-400' ?>"><?= $b['error_records'] ?></td>
          <td class="py-1.5 text-xs text-gray-500"><?= fmtDateTime($b['imported_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($finBatches)): ?><tr><td colspan="5" class="py-4 text-center text-gray-400"><?= t('msg.no_imports') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
