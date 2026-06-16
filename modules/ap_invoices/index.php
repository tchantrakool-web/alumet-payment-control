<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('ap_invoices')) {
    flash('error', 'Access denied');
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'AP Invoice Queue';
$db = getDB();

$filterVendor = trim($_GET['vendor'] ?? '');
$filterInvoiceFrom = trim($_GET['invoice_from'] ?? '');
$filterInvoiceTo = trim($_GET['invoice_to'] ?? '');
$where = [
    'i.is_deleted = 0',
    'COALESCE(i.ap_balance, 0) > 0',
    "COALESCE(i.payment_status, '') NOT IN ('Pending Documents', 'Paid', 'Rejected', 'Cancelled')",
];
$params = [];

if ($filterVendor !== '') {
    $where[] = '(i.vendor_name LIKE ? OR i.vendor_code LIKE ?)';
    $params[] = "%{$filterVendor}%";
    $params[] = "%{$filterVendor}%";
}
if ($filterInvoiceFrom !== '') {
    $where[] = 'i.ap_invoice_date >= ?';
    $params[] = $filterInvoiceFrom;
}
if ($filterInvoiceTo !== '') {
    $where[] = 'i.ap_invoice_date <= ?';
    $params[] = $filterInvoiceTo;
}

$invoices = [];
$whereSql = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT i.*, CAST(julianday('now') - julianday(i.ap_invoice_date) AS INTEGER) AS aging_days
    FROM sap_ap_invoices i
    WHERE {$whereSql}
    ORDER BY i.ap_invoice_date ASC, i.vendor_name ASC, i.ap_invoice_doc_num ASC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('ap.title') ?></h1>
    <p class="text-sm text-gray-500"><?= t('ap.subtitle') ?></p>
  </div>
  <div class="flex flex-wrap gap-3">
    <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
    <button type="button" id="submitSelectedInvoicesBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed" disabled>
      <?= t('ap.create_pr') ?>
    </button>
    <a href="<?= BASE_URL ?>/modules/payment_requests/create.php"
       class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <?= t('ap.create_payment_request') ?>
    </a>
    <?php endif; ?>
    <?php if (canAccess('import')): ?>
    <a href="<?= BASE_URL ?>/modules/ap_invoices/upload.php"
       class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium border border-slate-200">
      <?= t('ap.upload_sap') ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="bg-white rounded-xl border p-4 mb-4">
  <form class="flex flex-wrap items-end gap-3" method="GET">
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('label.supplier') ?></label>
      <input
        type="text"
        name="vendor"
        value="<?= h($filterVendor) ?>"
        placeholder="Search supplier name or code"
        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-60 outline-none focus:ring-2 focus:ring-blue-400"
      >
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('ap.filter.invoice_from') ?></label>
      <input
        type="date"
        name="invoice_from"
        value="<?= h($filterInvoiceFrom) ?>"
        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-blue-400"
      >
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('ap.filter.invoice_to') ?></label>
      <input
        type="date"
        name="invoice_to"
        value="<?= h($filterInvoiceTo) ?>"
        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-blue-400"
      >
    </div>
    <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm"><?= t('btn.search') ?></button>
    <a href="?" class="text-sm text-gray-500 hover:text-gray-700 py-1.5"><?= t('btn.reset') ?></a>
  </form>
  <p class="mt-3 text-xs text-gray-500"><?= t('ap.filter.hint') ?></p>
</div>

<div class="mb-3 flex items-center justify-between text-sm text-gray-600">
  <div><?= t('ap.waiting') ?> <span class="font-semibold text-gray-800"><?= number_format(count($invoices)) ?></span></div>
  <?php if (!empty($invoices)): ?>
  <div><?= t('ap.balance_total') ?> <span class="font-semibold text-orange-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_balance'))) ?></span></div>
  <?php endif; ?>
</div>

  <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
  <form id="selectInvoicesForm" method="POST" action="<?= BASE_URL ?>/modules/payment_requests/create.php">
  <?php endif; ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
            <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
            <th class="px-4 py-3 text-center">
              <input type="checkbox" id="selectAllInvoices" class="form-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded">
            </th>
            <?php endif; ?>
            <th class="px-4 py-3"><?= t('ap.col.batch') ?></th>
            <th class="px-4 py-3"><?= t('label.invoice_no') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.supplier_code') ?></th>
            <th class="px-4 py-3"><?= t('label.supplier') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.po') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.grpo') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.grpo_date') ?></th>
            <th class="px-4 py-3 text-right"><?= t('ap.col.grpo_total') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.invoice_date') ?></th>
            <th class="px-4 py-3 text-right"><?= t('ap.col.invoice_total') ?></th>
            <th class="px-4 py-3 text-right"><?= t('ap.col.paid') ?></th>
            <th class="px-4 py-3 text-right"><?= t('ap.col.balance') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.payment_doc') ?></th>
            <th class="px-4 py-3 text-right"><?= t('ap.col.payment_total') ?></th>
            <th class="px-4 py-3"><?= t('ap.col.aging') ?></th>
            <th class="px-4 py-3"><?= t('label.status') ?></th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <tr class="border-b last:border-0 hover:bg-gray-50 <?= ($inv['aging_days'] > 90 && $inv['ap_balance'] > 0) ? 'bg-red-50' : '' ?>">
            <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
            <td class="px-4 py-2.5 text-center">
              <input type="checkbox" name="invoice_ids[]" value="<?= $inv['id'] ?>" class="invoice-checkbox form-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded">
            </td>
            <?php endif; ?>
            <td class="px-4 py-2.5 text-xs text-gray-500"><?= h((string)($inv['import_batch_id'] ?? '-')) ?></td>
            <td class="px-4 py-2.5 font-mono text-xs"><?= h($inv['ap_invoice_doc_num']) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500"><?= h($inv['vendor_code'] ?: '-') ?></td>
            <td class="px-4 py-2.5">
              <div class="font-medium text-gray-800"><?= h($inv['vendor_name']) ?></div>
            </td>
            <td class="px-4 py-2.5 text-xs text-gray-500"><?= h($inv['po_doc_num'] ?: '-') ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500"><?= h($inv['grpo_doc_num'] ?: '-') ?></td>
            <td class="px-4 py-2.5 text-xs"><?= fmtDate($inv['grpo_date']) ?></td>
            <td class="px-4 py-2.5 text-right text-xs"><?= fmtMoney((float)$inv['grpo_total']) ?></td>
            <td class="px-4 py-2.5 text-xs"><?= fmtDate($inv['ap_invoice_date']) ?></td>
            <td class="px-4 py-2.5 text-right font-medium"><?= fmtMoney($inv['ap_invoice_total']) ?></td>
            <td class="px-4 py-2.5 text-right text-green-600"><?= fmtMoney($inv['ap_paid_amount']) ?></td>
            <td class="px-4 py-2.5 text-right font-semibold <?= $inv['ap_balance'] > 0 ? 'text-orange-600' : 'text-gray-400' ?>"><?= fmtMoney($inv['ap_balance']) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500"><?= h($inv['payment_doc_num'] ?: '-') ?></td>
            <td class="px-4 py-2.5 text-right text-xs"><?= fmtMoney((float)$inv['payment_total']) ?></td>
            <td class="px-4 py-2.5"><?= agingLabel((int)$inv['aging_days']) ?></td>
            <td class="px-4 py-2.5"><?= statusBadge($inv['payment_status']) ?></td>
            <td class="px-4 py-2.5">
              <?php if (hasRole('admin', 'maker', 'finance_manager') && $inv['ap_balance'] > 0 && !in_array((string)($inv['payment_status'] ?? ''), ['Pending Documents', 'Paid', 'Rejected', 'Cancelled'], true)): ?>
              <a href="<?= BASE_URL ?>/modules/payment_requests/create.php?invoice_id=<?= $inv['id'] ?>"
                 class="text-xs text-blue-600 hover:underline whitespace-nowrap">Create PR</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
        <tr>
          <td colspan="<?= hasRole('admin', 'maker', 'finance_manager') ? 18 : 17 ?>" class="px-4 py-8 text-center text-gray-400"><?= t('ap.none') ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($invoices)): ?>
      <tfoot>
        <tr class="bg-gray-50 text-xs font-semibold text-gray-600 border-t">
          <td colspan="<?= hasRole('admin', 'maker', 'finance_manager') ? 9 : 8 ?>" class="px-4 py-2"><?= t('pr.total') ?> (<?= count($invoices) ?> <?= t('label.records') ?>)</td>
          <td class="px-4 py-2 text-right"><?= fmtMoney(array_sum(array_column($invoices, 'ap_invoice_total'))) ?></td>
          <td class="px-4 py-2 text-right text-green-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_paid_amount'))) ?></td>
          <td class="px-4 py-2 text-right text-orange-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_balance'))) ?></td>
          <td></td>
          <td class="px-4 py-2 text-right"><?= fmtMoney(array_sum(array_column($invoices, 'payment_total'))) ?></td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
  <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm text-gray-500">
      <span id="selectedCount">0</span> <?= t('ap.selected') ?>
    </div>
    <button type="submit" id="createPrFromSelected" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
      <?= t('ap.create_pr') ?>
    </button>
  </div>
  </form>
  <script>
    const selectAll = document.getElementById('selectAllInvoices');
    const checkboxes = Array.from(document.querySelectorAll('.invoice-checkbox'));
    const selectedCountEl = document.getElementById('selectedCount');
    const createButton = document.getElementById('createPrFromSelected');
    const topCreateButton = document.getElementById('submitSelectedInvoicesBtn');

    function refreshSelection() {
      const selected = checkboxes.filter(chk => chk.checked).length;
      selectedCountEl.textContent = selected;
      if (createButton) createButton.disabled = selected === 0;
      if (topCreateButton) topCreateButton.disabled = selected === 0;
      if (selectAll) selectAll.checked = selected === checkboxes.length;
    }

    if (selectAll) {
      selectAll.addEventListener('change', () => {
        checkboxes.forEach(chk => chk.checked = selectAll.checked);
        refreshSelection();
      });
    }
    checkboxes.forEach(chk => chk.addEventListener('change', refreshSelection));

    if (topCreateButton) {
      topCreateButton.addEventListener('click', () => {
        if (topCreateButton.disabled) return;
        document.getElementById('selectInvoicesForm')?.submit();
      });
    }
  </script>
<?php endif; ?>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
