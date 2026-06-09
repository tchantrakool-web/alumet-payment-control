<?php
require_once __DIR__ . '/config/bootstrap.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();

$today = date('Y-m-d');
$weekEnd = date('Y-m-d', strtotime('+7 days'));
$nextWeekStart = date('Y-m-d', strtotime('+8 days'));
$nextWeekEnd = date('Y-m-d', strtotime('+14 days'));

$activeRequestStatuses = [
    'Pending Documents',
    'Pending Accounting Review',
    'Pending Finance Review',
    'Pending Management Approval',
    'Approved for Payment',
    'Returned for Correction',
];
$activeStatusSql = implode(',', array_fill(0, count($activeRequestStatuses), '?'));

$stmtOpenAp = $db->prepare("
    SELECT COALESCE(SUM(ap_balance), 0) AS total_amount
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND COALESCE(ap_balance, 0) > 0
      AND COALESCE(payment_status, '') NOT IN ('Paid', 'Rejected', 'Cancelled')
");
$stmtOpenAp->execute();
$openAp = $stmtOpenAp->fetch();

$stmtDueThisWeek = $db->prepare("
    SELECT COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date BETWEEN ? AND ?
      AND status IN ($activeStatusSql)
");
$stmtDueThisWeek->execute(array_merge([$today, $weekEnd], $activeRequestStatuses));
$dueThisWeek = $stmtDueThisWeek->fetch();

$stmtDueNextWeek = $db->prepare("
    SELECT COALESCE(SUM(net_payable), 0) AS total_amount
    FROM payment_requests
    WHERE is_deleted = 0
      AND due_date BETWEEN ? AND ?
      AND status IN ($activeStatusSql)
");
$stmtDueNextWeek->execute(array_merge([$nextWeekStart, $nextWeekEnd], $activeRequestStatuses));
$dueNextWeek = $stmtDueNextWeek->fetch();

$stmtPendingApproval = $db->prepare("
    SELECT COUNT(*) AS request_count
    FROM payment_requests
    WHERE is_deleted = 0
      AND status = 'Pending Management Approval'
");
$stmtPendingApproval->execute();
$pendingApproval = $stmtPendingApproval->fetch();

$stmtApproved = $db->prepare("
    SELECT COALESCE(SUM(net_payable), 0) AS total_amount, COUNT(*) AS request_count
    FROM payment_requests
    WHERE is_deleted = 0
      AND status = 'Approved for Payment'
");
$stmtApproved->execute();
$approvedForPayment = $stmtApproved->fetch();

$activeQueueLabels = [
    'Pending Documents',
    'Pending Accounting Review',
    'Pending Finance Review',
    'Pending Management Approval',
    'Approved for Payment',
];

$archiveLabels = [
    'Paid',
    'Cancelled',
    'Rejected',
];

$widgets = [
    [
        'label' => 'Open AP Amount',
        'value' => fmtMoney((float)($openAp['total_amount'] ?? 0)),
        'sub' => 'Outstanding SAP AP invoices',
        'classes' => 'bg-emerald-50 border-emerald-200',
        'valueClass' => 'text-emerald-800',
    ],
    [
        'label' => 'Due This Week',
        'value' => fmtMoney((float)($dueThisWeek['total_amount'] ?? 0)),
        'sub' => 'Active queue due within 7 days',
        'classes' => 'bg-amber-50 border-amber-200',
        'valueClass' => 'text-amber-700',
    ],
    [
        'label' => 'Pending Approval',
        'value' => number_format((int)($pendingApproval['request_count'] ?? 0)),
        'sub' => 'Requests waiting management approval',
        'classes' => 'bg-sky-50 border-sky-200',
        'valueClass' => 'text-sky-800',
    ],
    [
        'label' => 'Approved for Payment',
        'value' => fmtMoney((float)($approvedForPayment['total_amount'] ?? 0)),
        'sub' => number_format((int)($approvedForPayment['request_count'] ?? 0)) . ' request(s) ready to pay',
        'classes' => 'bg-teal-50 border-teal-200',
        'valueClass' => 'text-teal-800',
    ],
];

$focusAreas = [
    [
        'title' => 'Visibility of Open Liability',
        'description' => 'See unpaid SAP AP exposure clearly, with active obligations separated from archived paid items.',
        'metric' => 'Open AP Amount',
        'value' => fmtMoney((float)($openAp['total_amount'] ?? 0)),
        'classes' => 'border-emerald-200 bg-emerald-50',
        'titleClass' => 'text-emerald-900',
        'metricClass' => 'text-emerald-700',
    ],
    [
        'title' => 'Payment Approval Efficiency',
        'description' => 'Track how many requests are waiting for management approval so the team can unblock decisions quickly.',
        'metric' => 'Pending Approval',
        'value' => number_format((int)($pendingApproval['request_count'] ?? 0)) . ' request(s)',
        'classes' => 'border-sky-200 bg-sky-50',
        'titleClass' => 'text-sky-900',
        'metricClass' => 'text-sky-700',
    ],
    [
        'title' => 'Cash Planning Readiness',
        'description' => 'Focus on what is due this week and what is already approved for payment to support short-term cash planning.',
        'metric' => 'Due This Week / Approved for Payment',
        'value' => fmtMoney((float)($dueThisWeek['total_amount'] ?? 0)) . ' / ' . fmtMoney((float)($approvedForPayment['total_amount'] ?? 0)),
        'classes' => 'border-amber-200 bg-amber-50',
        'titleClass' => 'text-amber-900',
        'metricClass' => 'text-amber-700',
    ],
];

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
  <div>
    <h1 class="text-3xl font-bold text-gray-800">Executive Dashboard</h1>
    <p class="mt-1 text-base text-gray-500">Active queue only. Paid items leave the dashboard immediately and remain available in payment history.</p>
  </div>
  <div class="flex flex-wrap gap-3">
    <a href="<?= BASE_URL ?>/modules/payment_requests/?status=Paid" class="rounded-xl bg-[#003B5C] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0a4f78]">
      View Paid History
    </a>
    <a href="<?= BASE_URL ?>/modules/payment_requests/" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
      Open Active Queue
    </a>
  </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 mb-6">
  <?php foreach ($widgets as $widget): ?>
  <div class="rounded-2xl border p-5 shadow-sm <?= $widget['classes'] ?>">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-gray-500"><?= h($widget['label']) ?></p>
    <p class="mt-3 text-3xl font-bold <?= $widget['valueClass'] ?>"><?= h($widget['value']) ?></p>
    <p class="mt-2 text-sm text-gray-600"><?= h($widget['sub']) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
  <?php foreach ($focusAreas as $area): ?>
  <div class="rounded-2xl border p-5 shadow-sm <?= $area['classes'] ?>">
    <h2 class="text-lg font-bold <?= $area['titleClass'] ?>"><?= h($area['title']) ?></h2>
    <p class="mt-2 text-sm leading-6 text-gray-600"><?= h($area['description']) ?></p>
    <div class="mt-4 rounded-xl bg-white/80 px-4 py-3">
      <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500"><?= h($area['metric']) ?></p>
      <p class="mt-2 text-2xl font-bold <?= $area['metricClass'] ?>"><?= h($area['value']) ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
  <div class="rounded-2xl border bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-bold text-gray-800">Active Queue</h2>
        <p class="mt-1 text-sm text-gray-500">Only active workflow items should stay on the dashboard.</p>
      </div>
      <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-sm font-semibold hover:underline" style="color:#003B5C;">View payment requests</a>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
      <?php foreach ($activeQueueLabels as $label): ?>
      <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700"><?= h($label) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="rounded-2xl border bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-bold text-gray-800">Payment History / Archive</h2>
        <p class="mt-1 text-sm text-gray-500">Paid, cancelled, and rejected items are retained for reference and audit, not deleted.</p>
      </div>
      <a href="<?= BASE_URL ?>/modules/payment_requests/?status=Paid" class="text-sm font-semibold hover:underline" style="color:#003B5C;">Open paid history</a>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
      <?php foreach ($archiveLabels as $label): ?>
      <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700"><?= h($label) ?></span>
      <?php endforeach; ?>
    </div>
    <p class="mt-4 text-sm text-gray-500">Typical questions this archive should answer: when payment was made, who approved it, whether proof of payment exists, and why payment was delayed.</p>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
