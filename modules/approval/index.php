<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('approval')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Approval Queue';
$db   = getDB();
$user = currentUser();

// Queue: Waiting Check (for checker) or Waiting Approval (for approver/executive)
$pendingStatuses = [];
if (hasRole('admin','checker','finance_manager')) $pendingStatuses[] = 'Waiting Check';
if (hasRole('admin','approver','finance_manager','executive')) $pendingStatuses[] = 'Waiting Approval';

$filterTab = trim($_GET['tab'] ?? 'pending');

if ($filterTab === 'pending' && !empty($pendingStatuses)) {
    $phs  = implode(',', array_fill(0, count($pendingStatuses), '?'));
    $stmt = $db->prepare("SELECT pr.*, u.full_name as creator_name,
                                 CASE WHEN pr.due_date < date('now') THEN 1 ELSE 0 END as is_overdue
                          FROM payment_requests pr
                          LEFT JOIN users u ON u.id = pr.created_by
                          WHERE pr.status IN ($phs) AND pr.is_deleted = 0
                          ORDER BY pr.priority DESC, pr.due_date ASC");
    $stmt->execute($pendingStatuses);
    $requests = $stmt->fetchAll();
} else {
    // History
    $stmt = $db->prepare("SELECT pr.*, u.full_name as creator_name FROM payment_requests pr
                          LEFT JOIN users u ON u.id = pr.created_by
                          WHERE pr.status NOT IN ('draft','Waiting Check','Waiting Approval') AND pr.is_deleted = 0
                          ORDER BY pr.updated_at DESC LIMIT 100");
    $stmt->execute();
    $requests = $stmt->fetchAll();
}

// Stats
$stats = $db->query("SELECT status, COUNT(*) cnt FROM payment_requests WHERE is_deleted=0 GROUP BY status")->fetchAll();
$statMap = array_column($stats, 'cnt', 'status');

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5">
  <h1 class="text-2xl font-bold text-gray-800">Approval Queue</h1>
  <p class="text-gray-500 text-sm">รายการรออนุมัติและประวัติการอนุมัติ</p>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
  <?php foreach ([
    ['Waiting Check',    $statMap['Waiting Check'] ?? 0,    'bg-yellow-50 border-yellow-200', 'text-yellow-700'],
    ['Waiting Approval', $statMap['Waiting Approval'] ?? 0,  'bg-orange-50 border-orange-200', 'text-orange-700'],
    ['Approved',         $statMap['Approved'] ?? 0,          'bg-green-50 border-green-200',  'text-green-700'],
    ['Rejected',         $statMap['Rejected'] ?? 0,          'bg-red-50 border-red-200',      'text-red-700'],
  ] as [$label, $cnt, $bg, $color]): ?>
  <div class="bg-white border rounded-xl p-4 <?= $bg ?>">
    <p class="text-xs text-gray-500"><?= $label ?></p>
    <p class="text-2xl font-bold <?= $color ?>"><?= $cnt ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tab -->
<div class="flex gap-2 mb-4">
  <a href="?tab=pending" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filterTab === 'pending' ? 'bg-blue-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>">
    Pending (<?= ($statMap['Waiting Check'] ?? 0) + ($statMap['Waiting Approval'] ?? 0) ?>)
  </a>
  <a href="?tab=history" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filterTab === 'history' ? 'bg-blue-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>">
    History
  </a>
</div>

<!-- Queue Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
          <th class="px-4 py-3">Request No.</th>
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3">Due Date</th>
          <th class="px-4 py-3">Priority</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Submitted By</th>
          <th class="px-4 py-3">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $pr): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50 <?= $pr['is_overdue'] ?? false ? 'bg-red-50' : '' ?>">
          <td class="px-4 py-3 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $pr['id'] ?>" class="text-blue-600 hover:underline">
              <?= h($pr['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-3">
            <div class="font-medium"><?= h($pr['vendor_name']) ?></div>
          </td>
          <td class="px-4 py-3 text-right font-bold text-blue-700">฿<?= fmtMoney($pr['net_payable']) ?></td>
          <td class="px-4 py-3 text-sm <?= ($pr['is_overdue'] ?? false) ? 'text-red-600 font-semibold' : '' ?>">
            <?= fmtDate($pr['due_date']) ?>
          </td>
          <td class="px-4 py-3">
            <?php if ($pr['priority'] === 'urgent'): ?>
              <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded">🔥 Urgent</span>
            <?php elseif ($pr['priority'] === 'low'): ?>
              <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Low</span>
            <?php else: ?>
              <span class="text-xs text-gray-400">Normal</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3"><?= statusBadge($pr['status']) ?></td>
          <td class="px-4 py-3 text-xs text-gray-500"><?= h($pr['creator_name'] ?? '') ?></td>
          <td class="px-4 py-3">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $pr['id'] ?>"
               class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1.5 rounded-lg">
              Review →
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">
          <?= $filterTab === 'pending' ? 'ไม่มีรายการรออนุมัติ 🎉' : 'ไม่พบประวัติการอนุมัติ' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
