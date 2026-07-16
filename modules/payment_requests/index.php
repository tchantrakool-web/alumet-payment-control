<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('payment_requests')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Payment Requests';
$db = getDB();

$tab = trim((string) ($_GET['tab'] ?? 'pending'));
if (!in_array($tab, ['pending', 'history'], true)) {
    $tab = 'pending';
}
$historyStatuses = ['Paid', 'Rejected', 'Cancelled'];
$filterStatus = trim($_GET['status'] ?? '');
$filterVendor = trim($_GET['vendor'] ?? '');
$tabWasSpecified = isset($_GET['tab']);
if (!$tabWasSpecified && in_array($filterStatus, $historyStatuses, true)) {
    $tab = 'history';
}
if ($filterStatus !== '' && (($tab === 'history') !== in_array($filterStatus, $historyStatuses, true))) {
    $filterStatus = '';
}
$today = date('Y-m-d');

$where  = ['pr.is_deleted = 0'];
$params = [];
if ($tab === 'history') {
    $where[] = "pr.status IN ('Paid','Rejected','Cancelled')";
} else {
    $where[] = "pr.status NOT IN ('Paid','Rejected','Cancelled')";
}
if ($filterStatus) { $where[] = 'pr.status = ?'; $params[] = $filterStatus; }
if ($filterVendor) { $where[] = '(pr.vendor_name LIKE ? OR pr.vendor_code LIKE ?)'; $params[] = "%$filterVendor%"; $params[] = "%$filterVendor%"; }

if (hasRole('maker')) {
    $where[] = 'pr.created_by = ?';
    $params[] = currentUser()['id'];
}

$whereStr = implode(' AND ', $where);
$orderBy = $tab === 'history' ? 'pr.updated_at DESC, pr.id DESC' : 'pr.due_date ASC, pr.id DESC';
$requests = $db->prepare("
    SELECT pr.*, u.full_name as creator_name,
           COUNT(i.id) as invoice_count,
           GROUP_CONCAT(DISTINCT i.ap_invoice_no) as invoice_refs,
           CASE
               WHEN pr.status IN ('Paid','Rejected','Cancelled') THEN 0
               ELSE COALESCE(SUM(i.net_amount), pr.net_payable)
           END as outstanding_balance,
           CASE WHEN pr.due_date < ? AND pr.status NOT IN ('Paid','Rejected','Cancelled') THEN 1 ELSE 0 END as is_overdue
    FROM payment_requests pr
    LEFT JOIN users u ON u.id = pr.created_by
    LEFT JOIN payment_request_items i ON i.payment_request_id = pr.id
    WHERE $whereStr
    GROUP BY pr.id
    ORDER BY $orderBy
");
$params2 = array_merge([$today], $params);
$requests->execute($params2);
$requests = $requests->fetchAll();

$scopeWhere = ['pr.is_deleted = 0'];
$scopeParams = [];
if (hasRole('maker')) {
    $scopeWhere[] = 'pr.created_by = ?';
    $scopeParams[] = currentUser()['id'];
}
$scopeWhereStr = implode(' AND ', $scopeWhere);
$countStmt = $db->prepare("
    SELECT
        SUM(CASE WHEN pr.status NOT IN ('Paid','Rejected','Cancelled') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN pr.status IN ('Paid','Rejected','Cancelled') THEN 1 ELSE 0 END) AS history_count
    FROM payment_requests pr
    WHERE $scopeWhereStr
");
$countStmt->execute($scopeParams);
$tabCounts = $countStmt->fetch() ?: ['pending_count' => 0, 'history_count' => 0];

$statusScope = $tab === 'history'
    ? "pr.status IN ('Paid','Rejected','Cancelled')"
    : "pr.status NOT IN ('Paid','Rejected','Cancelled')";
$statusStmt = $db->prepare("SELECT DISTINCT pr.status FROM payment_requests pr WHERE $scopeWhereStr AND $statusScope ORDER BY pr.status");
$statusStmt->execute($scopeParams);
$statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('pr.title') ?></h1>
    <p class="text-gray-500 text-sm"><?= t('pr.subtitle') ?></p>
  </div>
  <?php if (hasRole('admin','maker','finance_manager')): ?>
  <a href="<?= BASE_URL ?>/modules/payment_requests/create.php"
     class="theme-btn-primary px-4 py-2 rounded-lg text-sm font-medium">
    <?= t('pr.create') ?>
  </a>
  <?php endif; ?>
</div>

<div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Payment Request modules">
  <a href="?tab=pending"
     class="rounded-lg px-4 py-2 text-sm font-medium <?= $tab === 'pending' ? 'bg-blue-600 text-white' : 'border bg-white text-gray-600 hover:bg-gray-50' ?>"
     <?= $tab === 'pending' ? 'aria-current="page"' : '' ?>>
    <?= t('pr.pending') ?> (<?= number_format((int) ($tabCounts['pending_count'] ?? 0)) ?>)
  </a>
  <a href="?tab=history"
     class="rounded-lg px-4 py-2 text-sm font-medium <?= $tab === 'history' ? 'bg-blue-600 text-white' : 'border bg-white text-gray-600 hover:bg-gray-50' ?>"
     <?= $tab === 'history' ? 'aria-current="page"' : '' ?>>
    <?= t('pr.history') ?> (<?= number_format((int) ($tabCounts['history_count'] ?? 0)) ?>)
  </a>
</div>

<div class="bg-white rounded-xl border p-4 mb-4">
  <form class="flex flex-wrap gap-3 items-end">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('label.vendor') ?></label>
      <input type="text" name="vendor" value="<?= h($filterVendor) ?>"
             class="theme-input border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-48"
             placeholder="Search vendor...">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('label.status') ?></label>
      <select name="status" class="theme-input border border-gray-300 rounded-lg px-3 py-1.5 text-sm min-w-48">
        <option value=""><?= t('label.all_status') ?></option>
        <?php foreach ($statuses as $status): ?>
        <option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h($status) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="theme-btn-primary px-4 py-1.5 rounded-lg text-sm"><?= t('btn.filter') ?></button>
    <a href="?tab=<?= h($tab) ?>" class="text-sm text-gray-500 hover:text-gray-700 py-1.5"><?= t('btn.reset') ?></a>
  </form>
</div>

<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm datatable">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
          <th class="px-4 py-3"><?= t('label.request_no') ?></th>
          <th class="px-4 py-3"><?= t('label.vendor') ?></th>
          <th class="px-4 py-3"><?= t('pr.col.invoice_ref') ?></th>
          <th class="px-4 py-3 text-right"><?= t('label.net_payable') ?></th>
          <th class="px-4 py-3 text-right"><?= t('pr.col.outstanding') ?></th>
          <th class="px-4 py-3"><?= t('label.due_date') ?></th>
          <th class="px-4 py-3"><?= t('label.payment_method') ?></th>
          <th class="px-4 py-3"><?= t('label.status') ?></th>
          <th class="px-4 py-3"><?= t('pr.col.created_by') ?></th>
          <th class="px-4 py-3"><?= t('label.created') ?></th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $pr): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50 <?= $pr['is_overdue'] ? 'bg-red-50' : '' ?>">
          <td class="px-4 py-2.5 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $pr['id'] ?>" class="hover:underline" style="color:#003B5C;">
              <?= h($pr['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-2.5">
            <div class="font-medium"><?= h($pr['vendor_name']) ?></div>
            <div class="text-xs text-gray-400"><?= h($pr['vendor_code'] ?? '') ?></div>
          </td>
          <td class="px-4 py-2.5 text-xs text-gray-500">
            <div class="max-w-[220px] truncate" title="<?= h($pr['invoice_refs'] ?? '') ?>">
              <?= h($pr['invoice_refs'] ?: '-') ?>
            </div>
            <div class="text-[11px] text-gray-400 mt-0.5"><?= (int) $pr['invoice_count'] ?> invoice(s)</div>
          </td>
          <td class="px-4 py-2.5 text-right font-semibold" style="color:#003B5C;"><?= fmtMoney($pr['net_payable']) ?></td>
          <td class="px-4 py-2.5 text-right font-semibold <?= (float)$pr['outstanding_balance'] > 0 ? 'text-orange-600' : 'text-gray-400' ?>">
            <?= fmtMoney((float) $pr['outstanding_balance']) ?>
          </td>
          <td class="px-4 py-2.5 text-sm <?= $pr['is_overdue'] ? 'text-red-600 font-semibold' : '' ?>">
            <?= fmtDate($pr['due_date']) ?>
            <?= $pr['is_overdue'] ? '<span class="text-xs ml-1">' . t('pr.overdue') . '</span>' : '' ?>
          </td>
          <td class="px-4 py-2.5 text-xs text-gray-500 capitalize"><?= h($pr['payment_method']) ?></td>
          <td class="px-4 py-2.5"><?= statusBadge($pr['status']) ?></td>
          <td class="px-4 py-2.5 text-xs text-gray-500"><?= h($pr['creator_name'] ?? '') ?></td>
          <td class="px-4 py-2.5 text-xs text-gray-400"><?= fmtDate($pr['created_at']) ?></td>
          <td class="px-4 py-2.5">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $pr['id'] ?>" class="text-xs hover:underline" style="color:#003B5C;">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400"><?= $tab === 'history' ? t('pr.none_history') : t('pr.none_pending') ?></td></tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($requests)): ?>
      <tfoot>
        <tr class="bg-gray-50 text-xs font-semibold text-gray-600 border-t">
          <td colspan="3" class="px-4 py-2"><?= t('pr.total') ?> (<?= count($requests) ?> <?= t('pr.requests') ?>)</td>
          <td class="px-4 py-2 text-right" style="color:#003B5C;"><?= fmtMoney(array_sum(array_column($requests, 'net_payable'))) ?></td>
          <td class="px-4 py-2 text-right text-orange-600"><?= fmtMoney(array_sum(array_map(static fn($row) => (float) $row['outstanding_balance'], $requests))) ?></td>
          <td colspan="6"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
