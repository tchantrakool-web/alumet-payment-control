<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('approval')) {
    flash('error', 'Access denied');
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'Approval Queue';
$db = getDB();
$user = currentUser();

$pendingStatuses = [];
if (hasRole('admin', 'checker', 'finance_manager')) {
    $pendingStatuses[] = 'Pending Finance Review';
}
if (hasRole('admin', 'approver', 'finance_manager', 'executive')) {
    $pendingStatuses[] = 'Pending Management Approval';
}

$filterTab = trim($_GET['tab'] ?? 'pending');

if ($filterTab === 'pending' && !empty($pendingStatuses)) {
    $requests = [];

    if (in_array('Pending Finance Review', $pendingStatuses, true)) {
        $stmtFinance = $db->prepare("
            SELECT pr.*, u.full_name AS creator_name,
                   CASE WHEN pr.due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
            FROM payment_requests pr
            LEFT JOIN users u ON u.id = pr.created_by
            WHERE pr.status = 'Pending Finance Review'
              AND pr.is_deleted = 0
            ORDER BY pr.priority DESC, pr.due_date ASC
        ");
        $stmtFinance->execute();
        $requests = array_merge($requests, $stmtFinance->fetchAll());
    }

    if (in_array('Pending Management Approval', $pendingStatuses, true)) {
        $currentUserId = (int)$user['id'];
        if (isAdmin()) {
            $stmtApproval = $db->prepare("
                SELECT pr.*, u.full_name AS creator_name,
                       CASE WHEN pr.due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
                FROM payment_requests pr
                LEFT JOIN users u ON u.id = pr.created_by
                WHERE pr.status = 'Pending Management Approval'
                  AND pr.is_deleted = 0
                ORDER BY pr.priority DESC, pr.due_date ASC
            ");
            $stmtApproval->execute();
        } else {
            $stmtApproval = $db->prepare("
                SELECT pr.*, u.full_name AS creator_name,
                       CASE WHEN pr.due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
                FROM payment_requests pr
                LEFT JOIN users u ON u.id = pr.created_by
                JOIN approval_tasks at ON at.payment_request_id = pr.id
                WHERE pr.status = 'Pending Management Approval'
                  AND pr.is_deleted = 0
                  AND at.approver_id = ?
                  AND at.status = 'pending'
                  AND at.sequence = (
                      SELECT MIN(at2.sequence)
                      FROM approval_tasks at2
                      WHERE at2.payment_request_id = pr.id
                        AND at2.status = 'pending'
                  )
                ORDER BY pr.priority DESC, pr.due_date ASC
            ");
            $stmtApproval->execute([$currentUserId]);
        }
        $requests = array_merge($requests, $stmtApproval->fetchAll());
    }

    usort($requests, static function (array $a, array $b): int {
        $priorityRank = ['urgent' => 0, 'normal' => 1, 'low' => 2];
        $aRank = $priorityRank[$a['priority'] ?? 'normal'] ?? 1;
        $bRank = $priorityRank[$b['priority'] ?? 'normal'] ?? 1;
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }

        $aDue = $a['due_date'] ?? '9999-12-31';
        $bDue = $b['due_date'] ?? '9999-12-31';
        if ($aDue !== $bDue) {
            return strcmp($aDue, $bDue);
        }

        return ((int)$b['id']) <=> ((int)$a['id']);
    });
} else {
    $stmt = $db->prepare("
        SELECT pr.*, u.full_name AS creator_name
        FROM payment_requests pr
        LEFT JOIN users u ON u.id = pr.created_by
        WHERE pr.status NOT IN ('draft', 'Pending Finance Review', 'Pending Management Approval')
          AND pr.is_deleted = 0
        ORDER BY pr.updated_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll();
}

$stats = $db->query("SELECT status, COUNT(*) AS cnt FROM payment_requests WHERE is_deleted = 0 GROUP BY status")->fetchAll();
$statMap = array_column($stats, 'cnt', 'status');

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5">
  <h1 class="text-2xl font-bold text-gray-800"><?= t('approval.title') ?></h1>
  <p class="text-sm text-gray-500"><?= t('approval.subtitle') ?></p>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
  <?php foreach ([
    ['Pending Documents', $statMap['Pending Documents'] ?? 0, 'bg-slate-50 border-slate-200', 'text-slate-700'],
    ['Pending Finance Review', $statMap['Pending Finance Review'] ?? 0, 'bg-sky-50 border-sky-200', 'text-sky-700'],
    ['Pending Management Approval', $statMap['Pending Management Approval'] ?? 0, 'bg-orange-50 border-orange-200', 'text-orange-700'],
    ['Returned for Correction', $statMap['Returned for Correction'] ?? 0, 'bg-rose-50 border-rose-200', 'text-rose-700'],
  ] as [$label, $count, $bg, $color]): ?>
  <div class="rounded-xl border bg-white p-4 <?= $bg ?>">
    <p class="text-xs text-gray-500"><?= h($label) ?></p>
    <p class="text-2xl font-bold <?= $color ?>"><?= number_format((int)$count) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<div class="mb-4 flex gap-2">
  <a href="?tab=pending" class="rounded-lg px-4 py-2 text-sm font-medium <?= $filterTab === 'pending' ? 'bg-blue-600 text-white' : 'border bg-white text-gray-600 hover:bg-gray-50' ?>">
    <?= t('approval.pending') ?> (<?= number_format((int)(($statMap['Pending Finance Review'] ?? 0) + ($statMap['Pending Management Approval'] ?? 0))) ?>)
  </a>
  <a href="?tab=history" class="rounded-lg px-4 py-2 text-sm font-medium <?= $filterTab === 'history' ? 'bg-blue-600 text-white' : 'border bg-white text-gray-600 hover:bg-gray-50' ?>">
    <?= t('approval.history') ?>
  </a>
</div>

<div class="overflow-hidden rounded-xl border bg-white">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
          <th class="px-4 py-3"><?= t('label.request_no') ?></th>
          <th class="px-4 py-3"><?= t('label.vendor') ?></th>
          <th class="px-4 py-3 text-right"><?= t('label.amount') ?></th>
          <th class="px-4 py-3"><?= t('label.due_date') ?></th>
          <th class="px-4 py-3"><?= t('label.priority') ?></th>
          <th class="px-4 py-3"><?= t('label.status') ?></th>
          <th class="px-4 py-3"><?= t('approval.col.submitted_by') ?></th>
          <th class="px-4 py-3"><?= t('approval.col.action') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $request): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50 <?= !empty($request['is_overdue']) ? 'bg-red-50' : '' ?>">
          <td class="px-4 py-3 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int)$request['id'] ?>" class="text-blue-600 hover:underline">
              <?= h($request['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-3">
            <div class="font-medium"><?= h($request['vendor_name']) ?></div>
            <div class="text-xs text-gray-400"><?= h((string)($request['vendor_code'] ?? '')) ?></div>
          </td>
          <td class="px-4 py-3 text-right font-bold text-blue-700">THB <?= fmtMoney((float)$request['net_payable']) ?></td>
          <td class="px-4 py-3 text-sm <?= !empty($request['is_overdue']) ? 'font-semibold text-red-600' : '' ?>"><?= fmtDate($request['due_date']) ?></td>
          <td class="px-4 py-3">
            <?php if ($request['priority'] === 'urgent'): ?>
            <span class="rounded bg-orange-100 px-2 py-0.5 text-xs text-orange-700">Urgent</span>
            <?php elseif ($request['priority'] === 'low'): ?>
            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Low</span>
            <?php else: ?>
            <span class="text-xs text-gray-400">Normal</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3"><?= statusBadge($request['status']) ?></td>
          <td class="px-4 py-3 text-xs text-gray-500"><?= h($request['creator_name'] ?? '') ?></td>
          <td class="px-4 py-3">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int)$request['id'] ?>" class="inline-block rounded-lg bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700">
              <?= t('btn.review') ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr>
          <td colspan="8" class="px-4 py-8 text-center text-gray-400">
            <?= $filterTab === 'pending' ? t('approval.empty.pending') : t('approval.empty.history') ?>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
