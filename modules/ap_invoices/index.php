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
$isSearchActive = $filterVendor !== '' || $filterInvoiceFrom !== '' || $filterInvoiceTo !== '';
$where = ['i.is_deleted = 0'];

// The default screen is a work queue. An explicit search also returns historical
// matches so paid invoices and invoices already converted to a PR can be found.
if (!$isSearchActive) {
    $where[] = 'COALESCE(i.ap_balance, 0) > 0';
    $where[] = "COALESCE(i.payment_status, '') NOT IN ('Pending Documents', 'Paid', 'Rejected', 'Cancelled')";
    $where[] = 'NOT EXISTS (SELECT 1 FROM payment_request_items pri2 JOIN payment_requests pr2 ON pr2.id = pri2.payment_request_id WHERE pri2.sap_invoice_id = i.id AND pr2.is_deleted = 0)';
}
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
    SELECT i.*,
           CAST(julianday('now') - julianday(i.ap_invoice_date) AS INTEGER) AS aging_days,
           pr.id AS linked_pr_id,
           pr.request_no AS linked_pr_no,
           pr.status AS linked_pr_status
    FROM sap_ap_invoices i
    LEFT JOIN payment_request_items pri ON pri.sap_invoice_id = i.id
    LEFT JOIN payment_requests pr ON pr.id = pri.payment_request_id AND pr.is_deleted = 0
    WHERE {$whereSql}
    ORDER BY
        CASE
            WHEN COALESCE(i.ap_balance, 0) > 0
             AND COALESCE(i.payment_status, '') <> 'Paid' THEN 0
            ELSE 1
        END ASC,
        i.ap_invoice_date ASC,
        i.vendor_name ASC,
        i.ap_invoice_doc_num ASC
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
    <?php if (canEdit('import')): ?>
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
  <div><?= $isSearchActive ? 'Search result(s):' : t('ap.waiting') ?> <span class="font-semibold text-gray-800"><?= number_format(count($invoices)) ?></span></div>
  <?php if (!empty($invoices)): ?>
  <div><?= t('ap.balance_total') ?> <span class="font-semibold text-orange-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_balance'))) ?></span></div>
  <?php endif; ?>
</div>

  <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
  <form id="selectInvoicesForm" method="POST" action="<?= BASE_URL ?>/modules/payment_requests/create.php">
  <?php endif; ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full table-fixed text-[11px] leading-tight">
        <colgroup>
          <?php if (hasRole('admin', 'maker', 'finance_manager')): ?><col style="width:3%"> <?php endif; ?>
          <col style="width:8%"><col style="width:18%"><col style="width:9%"><col style="width:8%">
          <col style="width:11%"><col style="width:12%"><col style="width:9%"><col style="width:7%">
          <col style="width:9%"><col style="width:6%">
        </colgroup>
        <thead>
          <tr class="bg-gray-50 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-normal border-b">
            <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
            <th class="px-1 py-2.5 text-center">
              <input type="checkbox" id="selectAllInvoices" class="form-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded">
            </th>
            <?php endif; ?>
            <th class="px-2 py-2.5"><?= t('label.invoice_no') ?></th>
            <th class="px-2 py-2.5"><?= t('label.supplier') ?></th>
            <th class="px-2 py-2.5">PO / GRPO</th>
            <th class="px-2 py-2.5 text-right"><?= t('ap.col.grpo_total') ?></th>
            <th class="px-2 py-2.5 text-right"><?= t('ap.col.invoice_total') ?></th>
            <th class="px-2 py-2.5 text-right"><?= t('ap.col.payment_total') ?></th>
            <th class="px-2 py-2.5 text-right"><?= t('ap.col.balance') ?></th>
            <th class="px-2 py-2.5 text-center"><?= t('ap.col.aging') ?></th>
            <th class="px-2 py-2.5 text-center"><?= t('label.status') ?></th>
            <th class="px-2 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            $isInvoiceSelectable = (float)$inv['ap_balance'] > 0
                && empty($inv['linked_pr_id'])
                && !in_array((string)($inv['payment_status'] ?? ''), ['Pending Documents', 'Paid', 'Rejected', 'Cancelled'], true);
            $displayStatus = !empty($inv['linked_pr_status']) ? (string)$inv['linked_pr_status'] : (string)($inv['payment_status'] ?? '');
          ?>
          <tr class="border-b last:border-0 hover:bg-gray-50 <?= ($inv['aging_days'] > 90 && $inv['ap_balance'] > 0) ? 'bg-red-50' : '' ?>">
            <?php if (hasRole('admin', 'maker', 'finance_manager')): ?>
            <td class="px-1 py-2.5 text-center">
              <?php if ($isInvoiceSelectable): ?>
              <input type="checkbox" name="invoice_ids[]" value="<?= $inv['id'] ?>" class="invoice-checkbox form-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded">
              <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="px-2 py-2.5 align-top">
              <div class="truncate font-mono font-semibold text-gray-800" title="<?= h($inv['ap_invoice_doc_num']) ?>"><?= h($inv['ap_invoice_doc_num']) ?></div>
              <div class="mt-1 truncate text-[10px] text-gray-400">Batch #<?= h((string)($inv['import_batch_id'] ?? '-')) ?></div>
            </td>
            <td class="px-2 py-2.5 align-top">
              <div class="truncate font-medium text-gray-800" title="<?= h($inv['vendor_name']) ?>"><?= h($inv['vendor_name']) ?></div>
              <div class="mt-1 truncate font-mono text-[10px] text-gray-400"><?= h($inv['vendor_code'] ?: '-') ?></div>
            </td>
            <td class="px-2 py-2.5 align-top text-gray-600">
              <div class="truncate" title="PO: <?= h($inv['po_doc_num'] ?: '-') ?>">PO: <?= h($inv['po_doc_num'] ?: '-') ?></div>
              <div class="mt-1 truncate" title="GRPO: <?= h($inv['grpo_doc_num'] ?: '-') ?>">GRPO: <?= h($inv['grpo_doc_num'] ?: '-') ?></div>
              <div class="mt-1 text-[10px] text-gray-400"><?= fmtDate($inv['grpo_date']) ?></div>
            </td>
            <td class="px-2 py-2.5 text-right align-top whitespace-nowrap"><?= fmtMoney((float)$inv['grpo_total']) ?></td>
            <td class="px-2 py-2.5 text-right align-top">
              <div class="font-semibold whitespace-nowrap"><?= fmtMoney($inv['ap_invoice_total']) ?></div>
              <div class="mt-1 text-[10px] text-gray-400 whitespace-nowrap"><?= fmtDate($inv['ap_invoice_date']) ?></div>
            </td>
            <td class="px-2 py-2.5 text-right align-top">
              <div class="truncate text-[10px] text-gray-400" title="<?= h($inv['payment_doc_num'] ?: '-') ?>"><?= h($inv['payment_doc_num'] ?: '-') ?></div>
              <div class="mt-1 whitespace-nowrap text-green-600">Paid <?= fmtMoney($inv['ap_paid_amount']) ?></div>
              <div class="mt-1 whitespace-nowrap text-gray-500">Total <?= fmtMoney((float)$inv['payment_total']) ?></div>
            </td>
            <td class="px-2 py-2.5 text-right align-top font-bold whitespace-nowrap <?= $inv['ap_balance'] > 0 ? 'text-orange-600' : 'text-gray-400' ?>"><?= fmtMoney($inv['ap_balance']) ?></td>
            <td class="px-2 py-2.5 text-center align-top"><?= agingLabel((int)$inv['aging_days']) ?></td>
            <td class="px-2 py-2.5 text-center align-top">
              <?= statusBadge($displayStatus) ?>
              <?php if (!empty($inv['linked_pr_no'])): ?><div class="mt-1 text-[10px] text-gray-400"><?= h($inv['linked_pr_no']) ?></div><?php endif; ?>
            </td>
            <td class="px-2 py-2.5 text-right align-top">
              <?php if (!empty($inv['linked_pr_id'])): ?>
              <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int)$inv['linked_pr_id'] ?>"
                 class="text-[10px] font-medium text-emerald-700 hover:underline whitespace-nowrap">Open PR</a>
              <?php elseif (hasRole('admin', 'maker', 'finance_manager') && $isInvoiceSelectable): ?>
              <a href="<?= BASE_URL ?>/modules/payment_requests/create.php?invoice_id=<?= $inv['id'] ?>"
                 class="text-[10px] text-blue-600 hover:underline whitespace-nowrap">Create PR</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
        <tr>
          <td colspan="<?= hasRole('admin', 'maker', 'finance_manager') ? 11 : 10 ?>" class="px-4 py-8 text-center text-gray-400"><?= t('ap.none') ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($invoices)): ?>
      <tfoot>
        <tr class="bg-gray-50 text-xs font-semibold text-gray-600 border-t">
          <td colspan="<?= hasRole('admin', 'maker', 'finance_manager') ? 4 : 3 ?>" class="px-2 py-2"><?= t('pr.total') ?> (<?= count($invoices) ?> <?= t('label.records') ?>)</td>
          <td class="px-2 py-2 text-right whitespace-nowrap"><?= fmtMoney(array_sum(array_column($invoices, 'grpo_total'))) ?></td>
          <td class="px-2 py-2 text-right whitespace-nowrap"><?= fmtMoney(array_sum(array_column($invoices, 'ap_invoice_total'))) ?></td>
          <td class="px-2 py-2 text-right"><div class="text-green-600 whitespace-nowrap">Paid <?= fmtMoney(array_sum(array_column($invoices, 'ap_paid_amount'))) ?></div><div class="mt-1 whitespace-nowrap text-gray-500">Total <?= fmtMoney(array_sum(array_column($invoices, 'payment_total'))) ?></div></td>
          <td class="px-2 py-2 text-right text-orange-600 whitespace-nowrap"><?= fmtMoney(array_sum(array_column($invoices, 'ap_balance'))) ?></td>
          <td colspan="3"></td>
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
