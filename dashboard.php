<?php
require_once __DIR__ . '/config/bootstrap.php';
requireLogin();
$pageTitle = 'Dashboard';
$db = getDB();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekEnd = date('Y-m-d', strtotime('+7 days'));
$monthEnd = date('Y-m-d', strtotime('+30 days'));
$openRequestStatuses = ['Pending Documents', 'Pending Accounting Review', 'Pending Finance Review', 'Pending Management Approval', 'Approved for Payment', 'Returned for Correction'];
$closedRequestStatuses = ['Paid', 'Rejected', 'Cancelled', 'draft'];

function compactMoney(float $amount): string {
    $abs = abs($amount);
    if ($abs >= 1000000) {
        return number_format($amount / 1000000, 1) . 'M';
    }
    if ($abs >= 1000) {
        return number_format($amount / 1000, 1) . 'K';
    }
    return number_format($amount, 0);
}

function avgHoursLabel(?float $hours): string {
    if ($hours === null) {
        return '-';
    }
    return number_format($hours, 1) . ' hr';
}

function buildInClause(array $values): string {
    return implode(',', array_fill(0, count($values), '?'));
}

$openStatusSql = buildInClause($openRequestStatuses);
$closedStatusSql = buildInClause($closedRequestStatuses);

$stmtPending = $db->prepare("
    SELECT COUNT(*) AS invoice_count, COALESCE(SUM(ap_balance), 0) AS total_amount
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND ap_balance > 0
      AND COALESCE(payment_status, '') NOT IN ('Paid', 'Rejected', 'Cancelled')
");
$stmtPending->execute();
$pendingExposure = $stmtPending->fetch();

$stmtPipeline = $db->prepare("
    SELECT status, COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND status IN ($openStatusSql)
    GROUP BY status
");
$stmtPipeline->execute($openRequestStatuses);
$pipelineMap = [];
foreach ($stmtPipeline->fetchAll() as $row) {
    $pipelineMap[$row['status']] = $row;
}

$stmtOverdue = $db->prepare("
    SELECT COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date < ?
      AND status NOT IN ($closedStatusSql)
");
$stmtOverdue->execute(array_merge([$today], $closedRequestStatuses));
$overdue = $stmtOverdue->fetch();

$forecastSql = "
    SELECT COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date BETWEEN ? AND ?
      AND status NOT IN ($closedStatusSql)
";
$stmtForecast = $db->prepare($forecastSql);
$stmtForecast->execute(array_merge([$today, $today], $closedRequestStatuses));
$forecastToday = $stmtForecast->fetch();
$stmtForecast->execute(array_merge([$today, $weekEnd], $closedRequestStatuses));
$forecastWeek = $stmtForecast->fetch();
$stmtForecast->execute(array_merge([$today, $monthEnd], $closedRequestStatuses));
$forecastMonth = $stmtForecast->fetch();

$stmtTomorrow = $db->prepare("
    SELECT COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date = ?
      AND status NOT IN ($closedStatusSql)
");
$stmtTomorrow->execute(array_merge([$tomorrow], $closedRequestStatuses));
$forecastTomorrow = $stmtTomorrow->fetch();

$stmtWeekCalendar = $db->prepare("
    SELECT COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date BETWEEN ? AND ?
      AND status NOT IN ($closedStatusSql)
");
$stmtWeekCalendar->execute(array_merge([$today, $weekEnd], $closedRequestStatuses));
$calendarWeek = $stmtWeekCalendar->fetch();

$statusSummary = [
    ['label' => 'Pending Approval', 'amount' => (float)($pipelineMap['Pending Management Approval']['total_amount'] ?? 0), 'count' => (int)($pipelineMap['Pending Management Approval']['request_count'] ?? 0), 'color' => '#F59E0B'],
    ['label' => 'Approved for Payment', 'amount' => (float)($pipelineMap['Approved for Payment']['total_amount'] ?? 0), 'count' => (int)($pipelineMap['Approved for Payment']['request_count'] ?? 0), 'color' => '#003B5C'],
    ['label' => 'Paid', 'amount' => 0.0, 'count' => 0, 'color' => '#006B3F'],
    ['label' => 'Overdue', 'amount' => (float)($overdue['total_amount'] ?? 0), 'count' => (int)($overdue['request_count'] ?? 0), 'color' => '#DC2626'],
    ['label' => 'Rejected', 'amount' => 0.0, 'count' => 0, 'color' => '#9CA3AF'],
];
$stmtStatus = $db->prepare("
    SELECT status, COUNT(*) AS request_count, COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND status IN ('Paid', 'Rejected')
    GROUP BY status
");
$stmtStatus->execute();
$statusRows = $stmtStatus->fetchAll();
foreach ($statusRows as $row) {
    foreach ($statusSummary as &$item) {
        if ($item['label'] === $row['status']) {
            $item['amount'] = (float)$row['total_amount'];
            $item['count'] = (int)$row['request_count'];
        }
    }
    unset($item);
}
$statusSummary = array_values(array_filter($statusSummary, static fn($row) => $row['amount'] > 0 || $row['count'] > 0));

$aging = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN julianday('now') - julianday(ap_invoice_date) <= 30 THEN ap_balance ELSE 0 END), 0) AS d30,
        COALESCE(SUM(CASE WHEN julianday('now') - julianday(ap_invoice_date) BETWEEN 31 AND 60 THEN ap_balance ELSE 0 END), 0) AS d60,
        COALESCE(SUM(CASE WHEN julianday('now') - julianday(ap_invoice_date) BETWEEN 61 AND 90 THEN ap_balance ELSE 0 END), 0) AS d90,
        COALESCE(SUM(CASE WHEN julianday('now') - julianday(ap_invoice_date) > 90 THEN ap_balance ELSE 0 END), 0) AS d90plus
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND ap_balance > 0
")->fetch();

$top10 = $db->query("
    SELECT vendor_name, COALESCE(SUM(ap_balance), 0) AS total
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND ap_balance > 0
    GROUP BY vendor_name
    ORDER BY total DESC
    LIMIT 10
")->fetchAll();

$upcomingStmt = $db->prepare("
    SELECT id, vendor_name, due_date, net_payable, status, request_no
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date >= ?
      AND status NOT IN ($closedStatusSql)
    ORDER BY due_date ASC, net_payable DESC
    LIMIT 10
");
$upcomingStmt->execute(array_merge([$today], $closedRequestStatuses));
$upcomingPayments = $upcomingStmt->fetchAll();

$riskStmt = $db->prepare("
    SELECT
        SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) AS overdue_count,
        SUM(CASE WHEN due_date < ? THEN net_payable ELSE 0 END) AS overdue_amount,
        SUM(CASE WHEN due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS due_soon_count,
        SUM(CASE WHEN due_date BETWEEN ? AND ? THEN net_payable ELSE 0 END) AS due_soon_amount,
        SUM(CASE WHEN due_date > ? OR due_date IS NULL THEN 1 ELSE 0 END) AS normal_count,
        SUM(CASE WHEN due_date > ? OR due_date IS NULL THEN net_payable ELSE 0 END) AS normal_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND status NOT IN ($closedStatusSql)
");
$riskStmt->execute(array_merge([$today, $today, $today, $weekEnd, $today, $weekEnd, $weekEnd, $weekEnd], $closedRequestStatuses));
$risk = $riskStmt->fetch();

$leadTime = $db->query("
    SELECT AVG((julianday(checked_at) - julianday(submitted_at)) * 24.0) AS maker_to_checker
    FROM payment_requests
    WHERE is_deleted = 0
      AND submitted_at IS NOT NULL
      AND checked_at IS NOT NULL
")->fetch();

$leadTimeApprove = $db->query("
    SELECT AVG((julianday(approved_at) - julianday(submitted_at)) * 24.0) AS overall_hours,
           AVG((julianday(approved_at) - julianday(checked_at)) * 24.0) AS checker_to_approver
    FROM (
        SELECT pr.submitted_at,
               pr.checked_at,
               (
                   SELECT MIN(ah.created_at)
                   FROM approval_history ah
                   WHERE ah.payment_request_id = pr.id
                     AND ah.new_status = 'Approved for Payment'
               ) AS approved_at
        FROM payment_requests pr
        WHERE pr.is_deleted = 0
          AND pr.submitted_at IS NOT NULL
          AND pr.checked_at IS NOT NULL
    ) t
    WHERE approved_at IS NOT NULL
")->fetch();

$recent = $db->query("
    SELECT al.*, u.full_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.id DESC
    LIMIT 8
")->fetchAll();

$headlineCards = [
    [
        'label' => 'Total Pending Exposure',
        'value' => fmtMoney((float)($pendingExposure['total_amount'] ?? 0)),
        'sub' => number_format((int)($pendingExposure['invoice_count'] ?? 0)) . ' unpaid invoices',
        'compact' => compactMoney((float)($pendingExposure['total_amount'] ?? 0)),
        'classes' => 'bg-emerald-50 border-emerald-200',
        'valueClass' => 'text-emerald-800',
    ],
    [
        'label' => 'Pending Finance Review',
        'value' => fmtMoney((float)($pipelineMap['Pending Finance Review']['total_amount'] ?? 0)),
        'sub' => number_format((int)($pipelineMap['Pending Finance Review']['request_count'] ?? 0)) . ' requests',
        'compact' => compactMoney((float)($pipelineMap['Pending Finance Review']['total_amount'] ?? 0)),
        'classes' => 'bg-amber-50 border-amber-200',
        'valueClass' => 'text-amber-700',
    ],
    [
        'label' => 'Approved for Payment',
        'value' => fmtMoney((float)($pipelineMap['Approved for Payment']['total_amount'] ?? 0)),
        'sub' => number_format((int)($pipelineMap['Approved for Payment']['request_count'] ?? 0)) . ' requests',
        'compact' => compactMoney((float)($pipelineMap['Approved for Payment']['total_amount'] ?? 0)),
        'classes' => 'bg-sky-50 border-sky-200',
        'valueClass' => 'text-sky-800',
    ],
    [
        'label' => 'Overdue',
        'value' => fmtMoney((float)($overdue['total_amount'] ?? 0)),
        'sub' => number_format((int)($overdue['request_count'] ?? 0)) . ' requests',
        'compact' => compactMoney((float)($overdue['total_amount'] ?? 0)),
        'classes' => 'bg-red-50 border-red-300',
        'valueClass' => 'text-red-700',
    ],
];

$forecastCards = [
    [
        'label' => 'Cash Requirement Today',
        'amount' => (float)($forecastToday['total_amount'] ?? 0),
        'count' => (int)($forecastToday['request_count'] ?? 0),
        'classes' => 'bg-white',
        'accent' => 'text-[#003B5C]',
    ],
    [
        'label' => 'Next 7 Days',
        'amount' => (float)($forecastWeek['total_amount'] ?? 0),
        'count' => (int)($forecastWeek['request_count'] ?? 0),
        'classes' => 'bg-white',
        'accent' => 'text-[#006B3F]',
    ],
    [
        'label' => 'Next 30 Days',
        'amount' => (float)($forecastMonth['total_amount'] ?? 0),
        'count' => (int)($forecastMonth['request_count'] ?? 0),
        'classes' => 'bg-white',
        'accent' => 'text-[#0A536F]',
    ],
];

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
  <div>
    <h1 class="text-3xl font-bold text-gray-800">Executive Dashboard</h1>
    <p class="mt-1 text-base text-gray-500">Daily payment exposure, approval pipeline, and short-term cash requirement as of <?= date('d/m/Y') ?></p>
  </div>
  <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-900">
    Pending exposure now aligns to unpaid SAP invoice balances, so the KPI cards match aging and top vendor views.
  </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 mb-4">
  <?php foreach ($headlineCards as $card): ?>
  <div class="rounded-2xl border p-4 <?= $card['classes'] ?>">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-gray-500"><?= h($card['label']) ?></p>
        <p class="mt-2 text-3xl font-bold <?= $card['valueClass'] ?>"><?= $card['value'] ?></p>
        <p class="mt-1 text-base text-gray-500"><?= h($card['sub']) ?></p>
      </div>
      <div class="rounded-full bg-white/90 px-3 py-1 text-base font-semibold text-gray-700 shadow-sm"><?= h($card['compact']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3 mb-6">
  <?php foreach ($forecastCards as $card): ?>
  <div class="rounded-2xl border border-gray-200 p-4 shadow-sm">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-gray-500"><?= h($card['label']) ?></p>
        <p class="mt-2 text-3xl font-bold <?= $card['accent'] ?>"><?= fmtMoney($card['amount']) ?></p>
      </div>
      <div class="rounded-xl bg-gray-50 px-3 py-2 text-right">
        <p class="text-sm text-gray-500">Requests</p>
        <p class="text-xl font-semibold text-gray-800"><?= number_format($card['count']) ?></p>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-base font-semibold text-gray-700">Payment Status Summary</h3>
      <span class="text-sm text-gray-400">amount by status</span>
    </div>
    <div class="mt-4 flex justify-center">
      <div class="relative h-44 w-full max-w-[260px]">
        <canvas id="statusChart"></canvas>
      </div>
    </div>
    <div class="mt-4 space-y-2">
      <?php foreach ($statusSummary as $item): ?>
      <div class="flex items-center justify-between rounded-xl bg-gray-50 px-3 py-2 text-base">
        <div class="flex items-center gap-2">
          <span class="inline-block h-2.5 w-2.5 rounded-full" style="background-color: <?= h($item['color']) ?>"></span>
          <span class="font-semibold text-gray-700"><?= h($item['label']) ?></span>
        </div>
        <div class="text-right">
          <div class="text-lg font-semibold text-gray-800"><?= fmtMoney($item['amount']) ?></div>
          <div class="text-sm text-gray-500"><?= number_format($item['count']) ?> requests</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-base font-semibold text-gray-700">AP Invoice Aging</h3>
      <span class="text-sm text-gray-400">unpaid SAP balance</span>
    </div>
    <div class="mt-4">
      <div class="relative h-44 w-full">
        <canvas id="agingChart"></canvas>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-base font-semibold text-gray-700">Top Vendor Exposure</h3>
      <span class="text-sm text-gray-400">highest unpaid balance</span>
    </div>
    <div class="mt-4">
      <div class="relative h-44 w-full">
        <canvas id="vendorChart"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Payment Calendar Snapshot</h3>
      <a href="<?= BASE_URL ?>/modules/calendar/" class="text-xs font-medium hover:underline" style="color:#003B5C;">Open calendar</a>
    </div>
    <div class="mt-4 space-y-3">
      <div class="rounded-xl bg-gray-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-gray-700">Today</p>
          <p class="text-lg font-semibold text-[#003B5C]"><?= number_format((int)($forecastToday['request_count'] ?? 0)) ?> Payments</p>
        </div>
        <p class="mt-1 text-sm text-gray-500"><?= fmtMoney((float)($forecastToday['total_amount'] ?? 0)) ?> THB</p>
      </div>
      <div class="rounded-xl bg-gray-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-gray-700">Tomorrow</p>
          <p class="text-lg font-semibold text-[#006B3F]"><?= number_format((int)($forecastTomorrow['request_count'] ?? 0)) ?> Payments</p>
        </div>
        <p class="mt-1 text-sm text-gray-500"><?= fmtMoney((float)($forecastTomorrow['total_amount'] ?? 0)) ?> THB</p>
      </div>
      <div class="rounded-xl bg-gray-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-gray-700">This Week</p>
          <p class="text-lg font-semibold text-[#0A536F]"><?= number_format((int)($calendarWeek['request_count'] ?? 0)) ?> Payments</p>
        </div>
        <p class="mt-1 text-sm text-gray-500"><?= fmtMoney((float)($calendarWeek['total_amount'] ?? 0)) ?> THB</p>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Approval Lead Time</h3>
      <a href="<?= BASE_URL ?>/modules/reports/?report=approval_lead_time" class="text-xs font-medium hover:underline" style="color:#003B5C;">Open report</a>
    </div>
    <div class="mt-4 space-y-3">
      <div class="rounded-xl border border-lime-200 bg-lime-50 px-4 py-3">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-lime-700">Average Approval Time</p>
        <p class="mt-2 text-2xl font-bold text-lime-800"><?= avgHoursLabel(isset($leadTimeApprove['overall_hours']) ? (float)$leadTimeApprove['overall_hours'] : null) ?></p>
      </div>
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div class="rounded-xl bg-gray-50 px-4 py-3">
          <p class="text-xs text-gray-500">Maker to Checker</p>
          <p class="mt-1 text-lg font-semibold text-gray-800"><?= avgHoursLabel(isset($leadTime['maker_to_checker']) ? (float)$leadTime['maker_to_checker'] : null) ?></p>
        </div>
        <div class="rounded-xl bg-gray-50 px-4 py-3">
          <p class="text-xs text-gray-500">Checker to Approver</p>
          <p class="mt-1 text-lg font-semibold text-gray-800"><?= avgHoursLabel(isset($leadTimeApprove['checker_to_approver']) ? (float)$leadTimeApprove['checker_to_approver'] : null) ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Supplier Risk Indicator</h3>
      <span class="text-xs text-gray-400">open payment requests</span>
    </div>
    <div class="mt-4 space-y-3">
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="font-medium text-emerald-800">Normal</p>
          <p class="text-sm text-emerald-700"><?= number_format((int)($risk['normal_count'] ?? 0)) ?> requests</p>
        </div>
        <p class="mt-1 text-xl font-bold text-emerald-900"><?= compactMoney((float)($risk['normal_amount'] ?? 0)) ?></p>
      </div>
      <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="font-medium text-amber-800">Due within 7 days</p>
          <p class="text-sm text-amber-700"><?= number_format((int)($risk['due_soon_count'] ?? 0)) ?> requests</p>
        </div>
        <p class="mt-1 text-xl font-bold text-amber-900"><?= compactMoney((float)($risk['due_soon_amount'] ?? 0)) ?></p>
      </div>
      <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3">
        <div class="flex items-center justify-between">
          <p class="font-medium text-red-800">Overdue</p>
          <p class="text-sm text-red-700"><?= number_format((int)($risk['overdue_count'] ?? 0)) ?> requests</p>
        </div>
        <p class="mt-1 text-xl font-bold text-red-900"><?= compactMoney((float)($risk['overdue_amount'] ?? 0)) ?></p>
      </div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-5 mb-6">
  <div class="xl:col-span-3 rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Top 10 Upcoming Payments</h3>
      <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-xs font-medium hover:underline" style="color:#003B5C;">View all requests</a>
    </div>
    <div class="mt-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b text-left text-xs uppercase tracking-[0.16em] text-gray-500">
            <th class="px-3 py-2">Vendor</th>
            <th class="px-3 py-2">Due Date</th>
            <th class="px-3 py-2 text-right">Amount</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($upcomingPayments as $row): ?>
          <tr class="border-b last:border-0 hover:bg-gray-50">
            <td class="px-3 py-3">
              <div class="font-medium text-gray-800"><?= h($row['vendor_name']) ?></div>
              <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int)$row['id'] ?>" class="text-xs hover:underline" style="color:#003B5C;"><?= h($row['request_no']) ?></a>
            </td>
            <td class="px-3 py-3 text-gray-600"><?= fmtDate($row['due_date']) ?></td>
            <td class="px-3 py-3 text-right font-semibold text-gray-800"><?= fmtMoney((float)$row['net_payable']) ?></td>
            <td class="px-3 py-3"><?= statusBadge($row['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($upcomingPayments)): ?>
          <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-gray-400">No upcoming payments in the current pipeline.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="xl:col-span-2 rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-700">Recent Activity</h3>
      <span class="text-xs text-gray-400">latest 8 events</span>
    </div>
    <div class="mt-4 space-y-3">
      <?php foreach ($recent as $log): ?>
      <div class="rounded-xl bg-gray-50 px-4 py-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-gray-800"><?= h($log['full_name'] ?? $log['username']) ?></p>
            <p class="text-xs text-gray-500"><?= h($log['module']) ?> · <?= h($log['action']) ?></p>
          </div>
          <p class="text-xs text-gray-400"><?= fmtDateTime($log['created_at']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($recent)): ?>
      <p class="py-6 text-center text-sm text-gray-400">No activity yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const moneyAxis = value => {
  const num = Number(value || 0);
  if (Math.abs(num) >= 1000000) return (num / 1000000).toFixed(1) + 'M';
  if (Math.abs(num) >= 1000) return (num / 1000).toFixed(0) + 'K';
  return num.toFixed(0);
};

const statusSummary = <?= json_encode($statusSummary, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: statusSummary.map(item => item.label),
    datasets: [{
      data: statusSummary.map(item => item.amount),
      backgroundColor: statusSummary.map(item => item.color),
      borderWidth: 0
    }]
  },
  options: {
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { boxWidth: 14, padding: 16, font: { size: 13, weight: '600' } }
      },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.label}: ${Number(ctx.raw || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} THB`
        }
      }
    }
  }
});

new Chart(document.getElementById('agingChart'), {
  type: 'bar',
  data: {
    labels: ['0-30d', '31-60d', '61-90d', '>90d'],
    datasets: [{
      label: 'Balance (THB)',
      data: [<?= round((float)$aging['d30'], 2) ?>, <?= round((float)$aging['d60'], 2) ?>, <?= round((float)$aging['d90'], 2) ?>, <?= round((float)$aging['d90plus'], 2) ?>],
      backgroundColor: ['#A4D65E', '#7BBF56', '#006B3F', '#003B5C'],
      borderRadius: 8
    }]
  },
  options: {
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false }
    },
    scales: {
      y: {
        ticks: { callback: moneyAxis, font: { size: 12, weight: '600' } }
      },
      x: {
        ticks: { font: { size: 12, weight: '600' } }
      }
    }
  }
});

const vendorLabels = <?= json_encode(array_column($top10, 'vendor_name'), JSON_UNESCAPED_UNICODE) ?>;
const vendorData = <?= json_encode(array_map('floatval', array_column($top10, 'total'))) ?>;
new Chart(document.getElementById('vendorChart'), {
  type: 'bar',
  data: {
    labels: vendorLabels,
    datasets: [{
      label: 'Balance (THB)',
      data: vendorData,
      backgroundColor: '#003B5C',
      borderRadius: 8
    }]
  },
  options: {
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: {
      legend: { display: false }
    },
    scales: {
      x: {
        ticks: { callback: moneyAxis, font: { size: 12, weight: '600' } }
      },
      y: {
        ticks: { font: { size: 12, weight: '600' } }
      }
    }
  }
});
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
