<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('import')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$db = getDB();
$batchId = (int)($_GET['id'] ?? 0);
$stmtBatch = $db->prepare("SELECT b.*, u.full_name FROM finance_import_batches b LEFT JOIN users u ON u.id=b.imported_by WHERE b.id=?");
$stmtBatch->execute([$batchId]);
$batch = $stmtBatch->fetch();
if (!$batch) { flash('error', 'Finance import batch not found'); redirect(BASE_URL . '/modules/import/'); }

$stmtRows = $db->prepare("SELECT * FROM finance_ap_records WHERE import_batch_id=? ORDER BY source_row, id");
$stmtRows->execute([$batchId]);
$records = $stmtRows->fetchAll();
$stmtErrors = $db->prepare("SELECT * FROM import_error_logs WHERE import_batch_id=? AND import_type='Finance' ORDER BY row_number, id");
$stmtErrors->execute([$batchId]);
$errors = $stmtErrors->fetchAll();

$grossTotal = array_sum(array_map(static fn($row): float => (float)$row['amount'], $records));
$whtTotal = array_sum(array_map(static fn($row): float => (float)$row['wht_amount'], $records));
$netTotal = array_sum(array_map(static fn($row): float => (float)$row['net_payable'], $records));
$paidCount = count(array_filter($records, static fn($row): bool => ($row['payment_status'] ?? '') === 'Paid'));
$pageTitle = 'Finance Import Detail';
include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
  <div>
    <a href="<?= BASE_URL ?>/modules/import/" class="text-sm text-blue-600 hover:underline">← Back to Import Center</a>
    <h1 class="mt-2 text-2xl font-bold text-gray-800"><?= h($batch['batch_no']) ?></h1>
    <p class="text-sm text-gray-500"><?= h($batch['filename']) ?> · <?= h($batch['period'] ?: 'No period') ?> · <?= fmtDateTime($batch['imported_at']) ?></p>
  </div>
  <?= statusBadge((string)$batch['status']) ?>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Imported rows</p><p class="text-xl font-bold text-gray-800"><?= number_format(count($records)) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Gross amount</p><p class="text-xl font-bold text-blue-700">฿<?= fmtMoney($grossTotal) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">WHT</p><p class="text-xl font-bold text-orange-600">฿<?= fmtMoney($whtTotal) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Net payable</p><p class="text-xl font-bold text-green-700">฿<?= fmtMoney($netTotal) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Paid / Errors</p><p class="text-xl font-bold text-gray-800"><?= number_format($paidCount) ?> / <span class="text-red-600"><?= number_format(count($errors)) ?></span></p></div>
</div>

<div class="rounded-xl border bg-white overflow-hidden">
  <div class="overflow-x-auto">
    <table class="datatable w-full text-sm">
      <thead><tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
        <th class="px-3 py-3">Row</th><th class="px-3 py-3">Supplier</th><th class="px-3 py-3">A/P Invoice</th>
        <th class="px-3 py-3">Tax Invoice</th><th class="px-3 py-3">Invoice Date</th><th class="px-3 py-3">Due Date</th>
        <th class="px-3 py-3 text-right">Gross</th><th class="px-3 py-3 text-right">WHT</th><th class="px-3 py-3 text-right">Net</th>
        <th class="px-3 py-3">Cheque</th><th class="px-3 py-3">Bank</th><th class="px-3 py-3">Paid Date</th><th class="px-3 py-3">Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($records as $row): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-3 py-2 text-xs text-gray-400"><?= (int)($row['source_row'] ?? 0) ?></td>
          <td class="px-3 py-2 min-w-52"><?= h((string)$row['vendor_name']) ?></td>
          <td class="px-3 py-2 font-mono text-xs"><?= h((string)$row['invoice_no']) ?></td>
          <td class="px-3 py-2 font-mono text-xs"><?= h((string)($row['tax_invoice_no'] ?? '')) ?></td>
          <td class="px-3 py-2 text-xs"><?= fmtDate($row['invoice_date']) ?></td>
          <td class="px-3 py-2 text-xs"><?= fmtDate($row['due_date']) ?></td>
          <td class="px-3 py-2 text-right"><?= fmtMoney((float)$row['amount']) ?></td>
          <td class="px-3 py-2 text-right text-orange-600"><?= fmtMoney((float)$row['wht_amount']) ?></td>
          <td class="px-3 py-2 text-right font-semibold text-green-700"><?= fmtMoney((float)$row['net_payable']) ?></td>
          <td class="px-3 py-2 text-xs"><span class="font-mono"><?= h((string)$row['cheque_no']) ?></span><br><?= fmtDate($row['cheque_date']) ?></td>
          <td class="px-3 py-2 text-xs"><?= h((string)($row['cheque_bank'] ?? '')) ?></td>
          <td class="px-3 py-2 text-xs"><?= fmtDate($row['paid_date'] ?? null) ?></td>
          <td class="px-3 py-2"><?= statusBadge((string)$row['payment_status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($errors): ?>
<div class="mt-5 rounded-xl border border-red-200 bg-white p-5">
  <h2 class="mb-3 font-semibold text-red-700">Import errors</h2>
  <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left text-xs text-gray-500"><th class="py-2">Row</th><th>Field</th><th>Message</th></tr></thead><tbody>
  <?php foreach ($errors as $error): ?><tr class="border-b last:border-0"><td class="py-2"><?= (int)$error['row_number'] ?></td><td><?= h((string)$error['field_name']) ?></td><td><?= h((string)$error['error_message']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
