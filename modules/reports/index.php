<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('reports')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$pageTitle = 'Reports';
$db = getDB();

$report = trim($_GET['report'] ?? 'aging');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-t'));
$vendor = trim($_GET['vendor'] ?? '');
$export = trim($_GET['export'] ?? '');

$allowedReports = ['aging', 'payment_forecast', 'paid_unpaid', 'approval_lead_time'];
if (!in_array($report, $allowedReports, true)) {
    $report = 'aging';
}

$data = [];
$summary = [];
$chart = ['labels' => [], 'values' => [], 'type' => 'bar', 'label' => ''];
$reportTitle = 'Vendor Aging';
$reportDescription = 'Outstanding AP balances grouped by vendor and aging bucket.';

switch ($report) {
    case 'aging':
        $reportTitle = 'Vendor Aging';
        $reportDescription = 'Outstanding AP balances grouped by vendor and aging bucket.';
        $invoiceAgeSql = sqlDaysBetween('CURRENT_DATE', 'ap_invoice_date');
        $stmt = $db->prepare("
            SELECT vendor_name, vendor_code,
                   COUNT(*) as invoice_count,
                   SUM(ap_balance) as total_balance,
                   SUM(CASE WHEN {$invoiceAgeSql} <= 30 THEN ap_balance ELSE 0 END) as d30,
                   SUM(CASE WHEN {$invoiceAgeSql} BETWEEN 31 AND 60 THEN ap_balance ELSE 0 END) as d60,
                   SUM(CASE WHEN {$invoiceAgeSql} BETWEEN 61 AND 90 THEN ap_balance ELSE 0 END) as d90,
                   SUM(CASE WHEN {$invoiceAgeSql} > 90 THEN ap_balance ELSE 0 END) as d90plus
            FROM sap_ap_invoices
            WHERE is_deleted = 0
              AND ap_balance > 0
              " . ($vendor !== '' ? "AND (vendor_name LIKE ? OR vendor_code LIKE ?)" : "") . "
            GROUP BY vendor_name, vendor_code
            ORDER BY total_balance DESC
        ");
        $params = $vendor !== '' ? ["%{$vendor}%", "%{$vendor}%"] : [];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $summary = [
            ['label' => 'Vendors', 'value' => number_format(count($data)), 'hint' => 'active suppliers with open balance'],
            ['label' => 'Open Balance', 'value' => fmtMoney((float) array_sum(array_column($data, 'total_balance'))), 'hint' => 'total AP exposure'],
            ['label' => 'Invoices', 'value' => number_format((int) array_sum(array_column($data, 'invoice_count'))), 'hint' => 'open invoices'],
            ['label' => '> 90 Days', 'value' => fmtMoney((float) array_sum(array_column($data, 'd90plus'))), 'hint' => 'highest-risk aging bucket'],
        ];

        $chart = [
            'labels' => array_slice(array_map(static fn($row) => $row['vendor_name'], $data), 0, 8),
            'values' => array_slice(array_map(static fn($row) => round((float) $row['total_balance'], 2), $data), 0, 8),
            'type' => 'bar',
            'label' => 'Top vendor exposure',
        ];
        break;

    case 'payment_forecast':
        $reportTitle = 'Payment Forecast';
        $reportDescription = 'Projected payment obligations by due date within the selected period.';
        $stmt = $db->prepare("
            SELECT id, due_date, vendor_name, request_no, net_payable, status, payment_method
            FROM payment_requests
            WHERE due_date BETWEEN ? AND ?
              AND is_deleted = 0
              AND status NOT IN ('draft', 'Rejected', 'Cancelled')
              " . ($vendor !== '' ? "AND vendor_name LIKE ?" : "") . "
            ORDER BY due_date ASC, net_payable DESC
        ");
        $params = [$dateFrom, $dateTo];
        if ($vendor !== '') {
            $params[] = "%{$vendor}%";
        }
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $today = date('Y-m-d');
        $overdueRows = array_filter($data, static fn($row) => !empty($row['due_date']) && $row['due_date'] < $today && $row['status'] !== 'Paid');
        $readyRows = array_filter($data, static fn($row) => $row['status'] === 'Approved for Payment');

        $totalsByDate = [];
        foreach ($data as $row) {
            $key = $row['due_date'] ?: 'No due date';
            $totalsByDate[$key] = ($totalsByDate[$key] ?? 0) + (float) $row['net_payable'];
        }

        $summary = [
            ['label' => 'Scheduled Amount', 'value' => fmtMoney((float) array_sum(array_column($data, 'net_payable'))), 'hint' => 'within selected date range'],
            ['label' => 'Requests', 'value' => number_format(count($data)), 'hint' => 'planned outgoing payments'],
            ['label' => 'Overdue', 'value' => number_format(count($overdueRows)), 'hint' => 'past due and not paid'],
            ['label' => 'Approved for Payment', 'value' => number_format(count($readyRows)), 'hint' => 'can be batched now'],
        ];

        $chart = [
            'labels' => array_map(static fn($key) => $key === 'No due date' ? $key : fmtDate($key), array_keys($totalsByDate)),
            'values' => array_map(static fn($value) => round((float) $value, 2), array_values($totalsByDate)),
            'type' => 'line',
            'label' => 'Forecast amount by due date',
        ];
        break;

    case 'paid_unpaid':
        $reportTitle = 'Paid / Unpaid Register';
        $reportDescription = 'Payment requests created in the period, grouped by completion status.';
        $stmt = $db->prepare("
            SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date, pr.status,
                   pr.created_at, pr.payment_method,
                   u.full_name as creator_name
            FROM payment_requests pr
            LEFT JOIN users u ON u.id = pr.created_by
            WHERE pr.created_at BETWEEN ? AND ?
              AND pr.is_deleted = 0
              " . ($vendor !== '' ? "AND pr.vendor_name LIKE ?" : "") . "
            ORDER BY pr.created_at DESC, pr.due_date ASC
        ");
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
        if ($vendor !== '') {
            $params[] = "%{$vendor}%";
        }
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $paidAmount = 0.0;
        $unpaidAmount = 0.0;
        $statusMix = [];
        foreach ($data as $row) {
            $statusMix[$row['status']] = ($statusMix[$row['status']] ?? 0) + 1;
            if ($row['status'] === 'Paid') {
                $paidAmount += (float) $row['net_payable'];
            } else {
                $unpaidAmount += (float) $row['net_payable'];
            }
        }

        $summary = [
            ['label' => 'Total Requests', 'value' => number_format(count($data)), 'hint' => 'created in selected period'],
            ['label' => 'Paid Amount', 'value' => fmtMoney($paidAmount), 'hint' => 'already completed'],
            ['label' => 'Open Amount', 'value' => fmtMoney($unpaidAmount), 'hint' => 'still in process'],
            ['label' => 'Paid Ratio', 'value' => count($data) ? number_format(($paidAmount / max($paidAmount + $unpaidAmount, 1)) * 100, 1) . '%' : '0.0%', 'hint' => 'amount-based completion rate'],
        ];

        $chart = [
            'labels' => array_keys($statusMix),
            'values' => array_values($statusMix),
            'type' => 'doughnut',
            'label' => 'Request count by status',
        ];
        break;

    case 'approval_lead_time':
        $reportTitle = 'Approval Lead Time';
        $reportDescription = 'Approval turnaround for completed payment requests.';
        $leadTimeHoursSql = sqlHoursBetween('pr.updated_at', 'pr.submitted_at');
        $stmt = $db->query("
            SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable,
                   pr.submitted_at, pr.updated_at,
                   ROUND({$leadTimeHoursSql}, 1) as hours_to_complete,
                   pr.status
            FROM payment_requests pr
            WHERE pr.status IN ('Approved', 'Approved for Payment', 'Rejected', 'Paid')
              AND pr.is_deleted = 0
              AND pr.submitted_at IS NOT NULL
            ORDER BY hours_to_complete DESC
            LIMIT 100
        ");
        $data = $stmt->fetchAll();

        $leadTimes = array_map(static fn($row) => (float) ($row['hours_to_complete'] ?? 0), $data);
        $slowItems = array_filter($leadTimes, static fn($hours) => $hours > 24);
        $avgLeadTime = !empty($leadTimes) ? array_sum($leadTimes) / count($leadTimes) : 0;

        $summary = [
            ['label' => 'Completed Items', 'value' => number_format(count($data)), 'hint' => 'latest 100 completed requests'],
            ['label' => 'Average Lead Time', 'value' => number_format($avgLeadTime, 1) . ' hrs', 'hint' => 'submission to completion'],
            ['label' => 'Slow Approvals', 'value' => number_format(count($slowItems)), 'hint' => 'took more than 24 hours'],
            ['label' => 'Fastest Item', 'value' => !empty($leadTimes) ? number_format(min($leadTimes), 1) . ' hrs' : '0.0 hrs', 'hint' => 'best turnaround'],
        ];

        $chartRows = array_slice($data, 0, 10);
        $chart = [
            'labels' => array_map(static fn($row) => $row['request_no'], $chartRows),
            'values' => array_map(static fn($row) => round((float) $row['hours_to_complete'], 1), $chartRows),
            'type' => 'bar',
            'label' => 'Hours to complete',
        ];
        break;
}

if ($export === 'csv') {
    $filename = 'report_' . $report . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($report === 'aging') {
        fputcsv($output, ['Vendor', 'Vendor Code', 'Invoices', '0-30d', '31-60d', '61-90d', '>90d', 'Total Balance']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['vendor_name'],
                $row['vendor_code'],
                $row['invoice_count'],
                $row['d30'],
                $row['d60'],
                $row['d90'],
                $row['d90plus'],
                $row['total_balance'],
            ]);
        }
    } elseif ($report === 'payment_forecast') {
        fputcsv($output, ['Due Date', 'Request No.', 'Vendor', 'Amount', 'Method', 'Status']);
        foreach ($data as $row) {
            fputcsv($output, [$row['due_date'], $row['request_no'], $row['vendor_name'], $row['net_payable'], $row['payment_method'], $row['status']]);
        }
    } elseif ($report === 'paid_unpaid') {
        fputcsv($output, ['Request No.', 'Vendor', 'Amount', 'Due Date', 'Status', 'Created By', 'Created At']);
        foreach ($data as $row) {
            fputcsv($output, [$row['request_no'], $row['vendor_name'], $row['net_payable'], $row['due_date'], $row['status'], $row['creator_name'], $row['created_at']]);
        }
    } else {
        fputcsv($output, ['Request No.', 'Vendor', 'Amount', 'Submitted', 'Completed', 'Lead Time (hrs)', 'Status']);
        foreach ($data as $row) {
            fputcsv($output, [$row['request_no'], $row['vendor_name'], $row['net_payable'], $row['submitted_at'], $row['updated_at'], $row['hours_to_complete'], $row['status']]);
        }
    }

    fclose($output);
    exit;
}

$baseQuery = ['report' => $report];
if (in_array($report, ['payment_forecast', 'paid_unpaid'], true)) {
    $baseQuery['date_from'] = $dateFrom;
    $baseQuery['date_to'] = $dateTo;
}
if ($vendor !== '') {
    $baseQuery['vendor'] = $vendor;
}

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('report.title') ?></h1>
    <p class="text-sm text-gray-500 mt-1"><?= h($reportTitle) ?> · <?= h($reportDescription) ?></p>
  </div>
  <a href="?<?= http_build_query($baseQuery + ['export' => 'csv']) ?>"
     class="inline-flex items-center rounded-lg border px-4 py-2 text-sm font-medium"
     style="border-color:#B8D9C5; background:#ECF7EF; color:#006B3F;">
    <?= t('btn.export_csv') ?>
  </a>
</div>

<div class="flex flex-wrap gap-2 mb-5">
  <?php foreach ([
      ['aging', t('report.vendor_aging')],
      ['payment_forecast', t('report.payment_forecast')],
      ['paid_unpaid', t('report.paid_unpaid')],
      ['approval_lead_time', t('report.approval_lead')],
  ] as [$key, $label]): ?>
  <a href="?report=<?= h($key) ?>"
     class="rounded-lg border px-4 py-2 text-sm font-medium <?= $report === $key ? 'text-white' : 'bg-white text-gray-600 hover:bg-gray-50' ?>"
     style="<?= $report === $key ? 'border-color:#006B3F; background:#006B3F;' : 'border-color:#DCE8F0;' ?>">
    <?= h($label) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
  <?php foreach ($summary as $card): ?>
  <div class="rounded-xl border bg-white p-4 shadow-sm">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-400"><?= h($card['label']) ?></p>
    <p class="mt-2 break-words text-xl font-bold leading-tight text-gray-800 xl:text-2xl"><?= h($card['value']) ?></p>
    <p class="mt-1 text-xs text-gray-500"><?= h($card['hint']) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <div class="xl:col-span-2 rounded-xl border bg-white p-4">
    <form class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
      <input type="hidden" name="report" value="<?= h($report) ?>">
      <?php if (in_array($report, ['payment_forecast', 'paid_unpaid'], true)): ?>
      <div>
        <label class="mb-1 block text-xs text-gray-500"><?= t('report.from') ?></label>
        <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="mb-1 block text-xs text-gray-500"><?= t('report.to') ?></label>
        <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <?php endif; ?>
      <div>
        <label class="mb-1 block text-xs text-gray-500"><?= t('label.vendor') ?></label>
        <input type="text" name="vendor" value="<?= h($vendor) ?>" placeholder="<?= t('report.vendor_filter') ?>" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div class="flex gap-2">
        <button type="submit" class="theme-btn-primary rounded-lg px-4 py-2 text-sm font-medium"><?= t('btn.apply') ?></button>
        <a href="?report=<?= h($report) ?>" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><?= t('btn.reset') ?></a>
      </div>
    </form>
  </div>

  <div class="rounded-xl border p-4 text-white" style="background:linear-gradient(135deg, #003B5C 0%, #006B3F 100%); border-color:#0A536F;">
    <p class="text-xs uppercase tracking-[0.2em]" style="color:#D9F0B4;"><?= t('report.context') ?></p>
    <div class="mt-3 space-y-2 text-sm">
      <div class="flex items-center justify-between gap-4">
        <span style="color:#DCE8F0;"><?= t('report.mode') ?></span>
        <span class="font-medium"><?= h($reportTitle) ?></span>
      </div>
      <div class="flex items-center justify-between gap-4">
        <span style="color:#DCE8F0;"><?= t('report.vendor_filter') ?></span>
        <span class="font-medium"><?= $vendor !== '' ? h($vendor) : t('report.all_vendors') ?></span>
      </div>
      <?php if (in_array($report, ['payment_forecast', 'paid_unpaid'], true)): ?>
      <div class="flex items-center justify-between gap-4">
        <span style="color:#DCE8F0;"><?= t('report.date_range') ?></span>
        <span class="font-medium"><?= h(fmtDate($dateFrom)) ?> - <?= h(fmtDate($dateTo)) ?></span>
      </div>
      <?php else: ?>
      <div class="flex items-center justify-between gap-4">
        <span style="color:#DCE8F0;"><?= t('report.date_basis') ?></span>
        <span class="font-medium"><?= $report === 'aging' ? t('report.open_balance') : t('report.completed_requests') ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <div class="xl:col-span-2 overflow-hidden rounded-xl border bg-white p-5 shadow-sm">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-gray-800"><?= t('report.visual_summary') ?></h2>
        <p class="mt-0.5 text-xs text-gray-400"><?= h($chart['label']) ?></p>
      </div>
      <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700"><?= number_format(count($chart['values'])) ?> data point(s)</span>
    </div>
    <div class="relative h-[300px] w-full sm:h-[320px]">
      <canvas id="reportChart"></canvas>
    </div>
  </div>

  <div class="rounded-xl border bg-white p-5 shadow-sm">
    <h2 class="text-sm font-semibold text-gray-700 mb-3"><?= t('report.highlights') ?></h2>
    <div class="space-y-3 text-sm">
      <?php if ($report === 'aging'): ?>
      <div class="rounded-lg bg-red-50 p-3">
        <p class="font-medium text-red-700">Aging pressure</p>
        <p class="mt-1 text-red-600"><?= fmtMoney((float) array_sum(array_column($data, 'd90plus'))) ?> in balances are older than 90 days.</p>
      </div>
      <div class="rounded-lg p-3" style="background:#ECF7EF;">
        <p class="font-medium" style="color:#006B3F;">Concentration</p>
        <p class="mt-1" style="color:#0F5B37;"><?= !empty($data) ? h($data[0]['vendor_name']) : 'No vendor data' ?> carries the largest open exposure.</p>
      </div>
      <?php elseif ($report === 'payment_forecast'): ?>
      <div class="rounded-lg bg-orange-50 p-3">
        <p class="font-medium text-orange-700">Cash planning</p>
        <p class="mt-1 text-orange-600">Use this view to batch payments by due date and reduce last-minute releases.</p>
      </div>
      <div class="rounded-lg p-3" style="background:#ECF1F7;">
        <p class="font-medium" style="color:#003B5C;">Execution</p>
        <p class="mt-1" style="color:#1C4A63;"><?= number_format(count(array_filter($data, static fn($row) => $row['status'] === 'Approved for Payment'))) ?> requests are already approved for payment.</p>
      </div>
      <?php elseif ($report === 'paid_unpaid'): ?>
      <div class="rounded-lg bg-emerald-50 p-3">
        <p class="font-medium text-emerald-700">Completion status</p>
        <p class="mt-1 text-emerald-600">Paid requests are separated from all remaining workflow states in this register.</p>
      </div>
      <div class="rounded-lg bg-slate-50 p-3">
        <p class="font-medium text-slate-700">Follow-up</p>
        <p class="mt-1 text-slate-600">Open items can be reviewed from the detail link for escalation or release.</p>
      </div>
      <?php else: ?>
      <div class="rounded-lg bg-violet-50 p-3">
        <p class="font-medium text-violet-700">Cycle time</p>
        <p class="mt-1 text-violet-600">Long approval duration often points to bottlenecks in checker or approver workload.</p>
      </div>
      <div class="rounded-lg bg-amber-50 p-3">
        <p class="font-medium text-amber-700">SLA watch</p>
        <p class="mt-1 text-amber-600">Items above 24 hours are useful candidates for workflow tuning in Settings.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="rounded-xl border bg-white overflow-hidden">
  <div class="overflow-x-auto">
    <?php if ($report === 'aging'): ?>
    <table class="datatable w-full text-sm">
      <thead>
        <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3 text-right">Invoices</th>
          <th class="px-4 py-3 text-right">0-30d</th>
          <th class="px-4 py-3 text-right">31-60d</th>
          <th class="px-4 py-3 text-right">61-90d</th>
          <th class="px-4 py-3 text-right">&gt;90d</th>
          <th class="px-4 py-3 text-right font-bold">Total Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $row): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-4 py-3">
            <div class="font-medium text-gray-800"><?= h($row['vendor_name']) ?></div>
            <div class="text-xs text-gray-400"><?= h($row['vendor_code'] ?? '') ?></div>
          </td>
          <td class="px-4 py-3 text-right"><?= number_format((int) $row['invoice_count']) ?></td>
          <td class="px-4 py-3 text-right text-emerald-600"><?= fmtMoney((float) $row['d30']) ?></td>
          <td class="px-4 py-3 text-right text-amber-600"><?= fmtMoney((float) $row['d60']) ?></td>
          <td class="px-4 py-3 text-right text-orange-600"><?= fmtMoney((float) $row['d90']) ?></td>
          <td class="px-4 py-3 text-right font-semibold text-red-600"><?= fmtMoney((float) $row['d90plus']) ?></td>
          <td class="px-4 py-3 text-right font-bold" style="color:#003B5C;"><?= fmtMoney((float) $row['total_balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data)): ?>
        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No data found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($report === 'payment_forecast'): ?>
    <table class="datatable w-full text-sm">
      <thead>
        <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
          <th class="px-4 py-3">Due Date</th>
          <th class="px-4 py-3">Request No.</th>
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3">Method</th>
          <th class="px-4 py-3">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $row): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-4 py-3 font-medium"><?= fmtDate($row['due_date']) ?></td>
          <td class="px-4 py-3 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int) $row['id'] ?>" class="hover:underline" style="color:#003B5C;">
              <?= h($row['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-3"><?= h($row['vendor_name']) ?></td>
          <td class="px-4 py-3 text-right font-semibold" style="color:#003B5C;"><?= fmtMoney((float) $row['net_payable']) ?></td>
          <td class="px-4 py-3 text-xs capitalize"><?= h((string)($row['payment_method'] ?: '-')) ?></td>
          <td class="px-4 py-3"><?= statusBadge($row['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data)): ?>
        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No data found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($report === 'paid_unpaid'): ?>
    <table class="datatable w-full text-sm">
      <thead>
        <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
          <th class="px-4 py-3">Request No.</th>
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3">Due Date</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Created By</th>
          <th class="px-4 py-3">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $row): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-4 py-3 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int) $row['id'] ?>" class="hover:underline" style="color:#003B5C;">
              <?= h($row['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-3"><?= h($row['vendor_name']) ?></td>
          <td class="px-4 py-3 text-right font-semibold" style="color:<?= $row['status'] === 'Paid' ? '#006B3F' : '#003B5C' ?>;">
            <?= fmtMoney((float) $row['net_payable']) ?>
          </td>
          <td class="px-4 py-3 text-xs"><?= fmtDate($row['due_date']) ?></td>
          <td class="px-4 py-3"><?= statusBadge($row['status']) ?></td>
          <td class="px-4 py-3 text-xs text-gray-500"><?= h($row['creator_name'] ?? '') ?></td>
          <td class="px-4 py-3 text-xs text-gray-400"><?= fmtDateTime($row['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data)): ?>
        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No data found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php else: ?>
    <table class="datatable w-full text-sm">
      <thead>
        <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
          <th class="px-4 py-3">Request No.</th>
          <th class="px-4 py-3">Vendor</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3">Submitted</th>
          <th class="px-4 py-3">Completed</th>
          <th class="px-4 py-3 text-right">Lead Time (hrs)</th>
          <th class="px-4 py-3">Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $row): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-4 py-3 font-mono text-xs">
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int) $row['id'] ?>" class="hover:underline" style="color:#003B5C;">
              <?= h($row['request_no']) ?>
            </a>
          </td>
          <td class="px-4 py-3"><?= h($row['vendor_name']) ?></td>
          <td class="px-4 py-3 text-right"><?= fmtMoney((float) $row['net_payable']) ?></td>
          <td class="px-4 py-3 text-xs"><?= fmtDateTime($row['submitted_at']) ?></td>
          <td class="px-4 py-3 text-xs"><?= fmtDateTime($row['updated_at']) ?></td>
          <td class="px-4 py-3 text-right font-semibold <?= ((float) ($row['hours_to_complete'] ?? 0)) > 24 ? 'text-red-600' : 'text-emerald-600' ?>">
            <?= number_format((float) ($row['hours_to_complete'] ?? 0), 1) ?>
          </td>
          <td class="px-4 py-3"><?= statusBadge($row['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data)): ?>
        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No data found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
const chartLabels = <?= json_encode($chart['labels']) ?>;
const chartValues = <?= json_encode($chart['values']) ?>;
const chartType = <?= json_encode($chart['type']) ?>;
const chartLabel = <?= json_encode($chart['label']) ?>;
const chartValueKind = <?= json_encode(in_array($report, ['aging', 'payment_forecast'], true) ? 'currency' : ($report === 'approval_lead_time' ? 'hours' : 'count')) ?>;
const isDoughnut = chartType === 'doughnut';
const isHorizontalBar = chartType === 'bar';

function compactNumber(value) {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value) || 0);
}

function formattedValue(value) {
    const number = Number(value) || 0;
    if (chartValueKind === 'currency') return 'THB ' + number.toLocaleString('en-US', {maximumFractionDigits: 2});
    if (chartValueKind === 'hours') return number.toLocaleString('en-US', {maximumFractionDigits: 1}) + ' hrs';
    return number.toLocaleString('en-US');
}

function shortLabel(value) {
    const label = String(value ?? '');
    return label.length > 24 ? label.slice(0, 23) + '…' : label;
}

new Chart(document.getElementById('reportChart'), {
    type: chartType,
    data: {
        labels: chartLabels,
        datasets: [{
            label: chartLabel,
            data: chartValues,
            backgroundColor: isDoughnut
                ? ['#006B3F', '#0A536F', '#A4D65E', '#F59E0B', '#DC2626', '#64748B', '#7BBF56']
                : isHorizontalBar ? 'rgba(0, 107, 63, 0.78)' : 'rgba(10, 83, 111, 0.14)',
            borderColor: isDoughnut
                ? ['#006B3F', '#0A536F', '#A4D65E', '#F59E0B', '#DC2626', '#64748B', '#7BBF56']
                : '#006B3F',
            borderWidth: isDoughnut ? 0 : 2,
            borderRadius: isHorizontalBar ? 6 : 0,
            maxBarThickness: 28,
            pointRadius: chartType === 'line' ? 3 : 0,
            pointHoverRadius: chartType === 'line' ? 5 : 0,
            pointBackgroundColor: '#006B3F',
            fill: chartType === 'line',
            tension: 0.32,
            spacing: isDoughnut ? 3 : 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: isHorizontalBar ? 'y' : 'x',
        cutout: isDoughnut ? '68%' : undefined,
        animation: {duration: 350},
        layout: {padding: {top: 4, right: 8, bottom: 0, left: 4}},
        plugins: {
            legend: {
                display: isDoughnut,
                position: window.innerWidth < 640 ? 'bottom' : 'right',
                labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14, color: '#475569', font: {size: 11}}
            },
            tooltip: {
                backgroundColor: '#17313D',
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: context => `${context.dataset.label}: ${formattedValue(context.raw)}`
                }
            }
        },
        scales: isDoughnut ? {} : isHorizontalBar ? {
            x: {
                beginAtZero: true,
                grid: {color: 'rgba(148,163,184,.16)', drawBorder: false},
                border: {display: false},
                ticks: {color: '#64748B', font: {size: 10}, callback: value => compactNumber(value)}
            },
            y: {
                grid: {display: false},
                border: {display: false},
                ticks: {color: '#475569', font: {size: 10}, callback: function(value) { return shortLabel(this.getLabelForValue(value)); }}
            }
        } : {
            x: {
                grid: {display: false},
                border: {display: false},
                ticks: {color: '#64748B', font: {size: 10}, maxRotation: 0, autoSkip: true, maxTicksLimit: 8}
            },
            y: {
                beginAtZero: true,
                grid: {color: 'rgba(148,163,184,.16)', drawBorder: false},
                border: {display: false},
                ticks: {color: '#64748B', font: {size: 10}, callback: value => compactNumber(value)}
            }
        }
    }
});
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
