<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('ap_invoices')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'AP Invoice Queue';
$db = getDB();

$today = date('Y-m-d');
$filterStatus = trim($_GET['status'] ?? '');
$filterVendor = trim($_GET['vendor'] ?? '');
$filterDueTo  = trim($_GET['due_to'] ?? '');
$filterDueFrom= trim($_GET['due_from'] ?? '');

$where  = ['i.is_deleted = 0'];
$params = [];

if ($filterStatus) { $where[] = 'i.payment_status = ?'; $params[] = $filterStatus; }
if ($filterVendor) { $where[] = '(i.vendor_name LIKE ? OR i.vendor_code LIKE ?)'; $params[] = "%$filterVendor%"; $params[] = "%$filterVendor%"; }
if ($filterDueFrom){ $where[] = 'i.ap_invoice_date >= ?'; $params[] = $filterDueFrom; }
if ($filterDueTo)  { $where[] = 'i.ap_invoice_date <= ?'; $params[] = $filterDueTo; }

$whereStr = implode(' AND ', $where);
$invoices = $db->prepare("SELECT i.*, CAST(julianday('now') - julianday(i.ap_invoice_date) AS INTEGER) as aging_days FROM sap_ap_invoices i WHERE $whereStr ORDER BY i.ap_invoice_date ASC");
$invoices->execute($params);
$invoices = $invoices->fetchAll();

// Stats
$stats = $db->query("SELECT payment_status, COUNT(*) as cnt, COALESCE(SUM(ap_balance),0) as total FROM sap_ap_invoices WHERE is_deleted=0 GROUP BY payment_status")->fetchAll();

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold text-gray-800">AP Invoice Queue</h1>
    <p class="text-gray-500 text-sm">รายการเจ้าหนี้ทั้งหมดจาก SAP Import</p>
  </div>
  <?php if (hasRole('admin','maker','finance_manager')): ?>
  <a href="<?= BASE_URL ?>/modules/payment_requests/create.php"
     class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    + Create Payment Request
  </a>
  <?php endif; ?>
</div>

<!-- Status Summary Cards -->
<div class="flex flex-wrap gap-2 mb-5">
  <a href="?" class="px-3 py-1.5 rounded-lg border text-sm font-medium <?= !$filterStatus ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 hover:bg-gray-50' ?>">
    All (<?= count($invoices) ?>)
  </a>
  <?php foreach ($stats as $s): ?>
  <a href="?status=<?= urlencode($s['payment_status']) ?>"
     class="px-3 py-1.5 rounded-lg border text-sm font-medium <?= $filterStatus === $s['payment_status'] ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 hover:bg-gray-50' ?>">
    <?= h($s['payment_status']) ?> (<?= $s['cnt'] ?>)
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border p-4 mb-4">
  <form class="flex flex-wrap gap-3 items-end">
    <div>
      <label class="block text-xs text-gray-500 mb-1">Vendor</label>
      <input type="text" name="vendor" value="<?= h($filterVendor) ?>"
             class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-48 outline-none focus:ring-2 focus:ring-blue-400"
             placeholder="ค้นหา Vendor...">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Invoice Date From</label>
      <input type="date" name="due_from" value="<?= h($filterDueFrom) ?>"
             class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Invoice Date To</label>
      <input type="date" name="due_to" value="<?= h($filterDueTo) ?>"
             class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <?php if ($filterStatus): ?>
    <input type="hidden" name="status" value="<?= h($filterStatus) ?>">
    <?php endif; ?>
    <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm">Filter</button>
    <a href="?" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
  </form>
</div>

<!-- Invoice Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm datatable">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
          <th class="px-4 py-3">Invoice No.</th>
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3">Invoice Date</th>
          <th class="px-4 py-3">PO / GRPO</th>
          <th class="px-4 py-3 text-right">Invoice Total</th>
          <th class="px-4 py-3 text-right">Paid</th>
          <th class="px-4 py-3 text-right">Balance</th>
          <th class="px-4 py-3">Aging</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50 <?= ($inv['aging_days'] > 90 && $inv['ap_balance'] > 0) ? 'bg-red-50' : '' ?>">
          <td class="px-4 py-2.5 font-mono text-xs"><?= h($inv['ap_invoice_doc_num']) ?></td>
          <td class="px-4 py-2.5">
            <div class="font-medium text-gray-800"><?= h($inv['vendor_name']) ?></div>
            <div class="text-xs text-gray-400"><?= h($inv['vendor_code'] ?? '') ?></div>
          </td>
          <td class="px-4 py-2.5 text-xs"><?= fmtDate($inv['ap_invoice_date']) ?></td>
          <td class="px-4 py-2.5 text-xs text-gray-500">
            <?= h($inv['po_doc_num'] ?: '-') ?><br>
            <span class="text-gray-400"><?= h($inv['grpo_doc_num'] ?: '') ?></span>
          </td>
          <td class="px-4 py-2.5 text-right font-medium"><?= fmtMoney($inv['ap_invoice_total']) ?></td>
          <td class="px-4 py-2.5 text-right text-green-600"><?= fmtMoney($inv['ap_paid_amount']) ?></td>
          <td class="px-4 py-2.5 text-right font-semibold <?= $inv['ap_balance'] > 0 ? 'text-orange-600' : 'text-gray-400' ?>"><?= fmtMoney($inv['ap_balance']) ?></td>
          <td class="px-4 py-2.5"><?= agingLabel((int)$inv['aging_days']) ?></td>
          <td class="px-4 py-2.5"><?= statusBadge($inv['payment_status']) ?></td>
          <td class="px-4 py-2.5">
            <?php if (hasRole('admin','maker','finance_manager') && $inv['ap_balance'] > 0 && in_array((string)($inv['payment_status'] ?? ''), ['', 'Imported', 'pending', 'Unpaid', 'Outstanding'], true)): ?>
            <a href="<?= BASE_URL ?>/modules/payment_requests/create.php?invoice_id=<?= $inv['id'] ?>"
               class="text-xs text-blue-600 hover:underline whitespace-nowrap">Create PR</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
        <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">ไม่พบข้อมูล — กรุณา Import ข้อมูล SAP ก่อน</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($invoices)): ?>
      <tfoot>
        <tr class="bg-gray-50 text-xs font-semibold text-gray-600 border-t">
          <td colspan="4" class="px-4 py-2">Total (<?= count($invoices) ?> records)</td>
          <td class="px-4 py-2 text-right"><?= fmtMoney(array_sum(array_column($invoices, 'ap_invoice_total'))) ?></td>
          <td class="px-4 py-2 text-right text-green-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_paid_amount'))) ?></td>
          <td class="px-4 py-2 text-right text-orange-600"><?= fmtMoney(array_sum(array_column($invoices, 'ap_balance'))) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
