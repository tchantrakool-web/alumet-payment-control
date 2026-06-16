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

// Overdue aging buckets
$d30  = date('Y-m-d', strtotime('-30 days'));
$d90  = date('Y-m-d', strtotime('-90 days'));
$d120 = date('Y-m-d', strtotime('-120 days'));

$overdueBase = "FROM payment_requests WHERE is_deleted=0 AND status NOT IN ('Paid','Rejected','Cancelled') AND due_date IS NOT NULL AND due_date != ''";

$overdue30      = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(net_payable),0) AS total $overdueBase AND due_date < '$today' AND due_date >= '$d30'")->fetch();
$overdue90      = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(net_payable),0) AS total $overdueBase AND due_date < '$d30' AND due_date >= '$d90'")->fetch();
$overdue120     = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(net_payable),0) AS total $overdueBase AND due_date < '$d90' AND due_date >= '$d120'")->fetch();
$overdue120plus = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(net_payable),0) AS total $overdueBase AND due_date < '$d120'")->fetch();

$overdueStatusRows = $db->query("
    SELECT status, COUNT(*) AS cnt, COALESCE(SUM(net_payable),0) AS total
    $overdueBase AND due_date < '$today'
    GROUP BY status ORDER BY cnt DESC
")->fetchAll();

$totalOverdueCnt   = (int)$overdue30['cnt']   + (int)$overdue90['cnt']   + (int)$overdue120['cnt']   + (int)$overdue120plus['cnt'];
$totalOverdueAmt   = (float)$overdue30['total'] + (float)$overdue90['total'] + (float)$overdue120['total'] + (float)$overdue120plus['total'];

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


<!-- Overdue Aging Analysis -->
<div class="rounded-2xl border bg-white p-5 shadow-sm mb-6">
  <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 class="text-lg font-bold text-gray-800">Overdue Aging Analysis</h2>
      <p class="mt-0.5 text-sm text-gray-500">Unpaid requests past due date — broken down by aging bucket</p>
    </div>
    <div class="text-right">
      <p class="text-xs text-gray-400">As of <?= date('d/m/Y') ?></p>
      <?php if ($totalOverdueCnt > 0): ?>
      <p class="mt-0.5 text-sm font-semibold text-red-600"><?= $totalOverdueCnt ?> request(s) overdue · THB <?= fmtMoney($totalOverdueAmt) ?></p>
      <?php else: ?>
      <p class="mt-0.5 text-sm font-semibold text-emerald-600">No overdue items</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Aging bucket cards -->
  <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-6">
    <?php
    $buckets = [
        ['label' => '≤ 30 Days',   'sub' => '1 – 30 วัน',    'data' => $overdue30,      'card' => 'border-amber-200 bg-amber-50', 'cnt' => 'text-amber-700', 'amt' => 'text-amber-900', 'badge' => 'bg-amber-100 text-amber-700'],
        ['label' => '31 – 90 Days','sub' => '31 – 90 วัน',   'data' => $overdue90,      'card' => 'border-orange-200 bg-orange-50','cnt' => 'text-orange-700','amt' => 'text-orange-900','badge' => 'bg-orange-100 text-orange-700'],
        ['label' => '91 – 120 Days','sub' => '91 – 120 วัน', 'data' => $overdue120,     'card' => 'border-red-200 bg-red-50',     'cnt' => 'text-red-700',   'amt' => 'text-red-900',   'badge' => 'bg-red-100 text-red-700'],
        ['label' => '120+ Days',    'sub' => 'เกิน 120 วัน', 'data' => $overdue120plus, 'card' => 'border-rose-300 bg-rose-100',  'cnt' => 'text-rose-800',  'amt' => 'text-rose-900',  'badge' => 'bg-rose-200 text-rose-800'],
    ];
    foreach ($buckets as $b):
    $cnt = (int)$b['data']['cnt'];
    $amt = (float)$b['data']['total'];
    ?>
    <div class="rounded-xl border p-4 <?= $b['card'] ?>">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide <?= $b['cnt'] ?>"><?= $b['label'] ?></span>
        <?php if ($cnt > 0): ?>
        <span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $b['badge'] ?>"><?= $cnt ?></span>
        <?php endif; ?>
      </div>
      <p class="mt-3 text-2xl font-bold <?= $b['cnt'] ?>"><?= $cnt ?></p>
      <p class="text-xs <?= $b['cnt'] ?> opacity-70 mt-0.5">requests</p>
      <div class="mt-3 border-t border-current border-opacity-10 pt-3">
        <p class="text-xs text-gray-500">Amount</p>
        <p class="text-sm font-bold <?= $b['amt'] ?>"><?= fmtMoney($amt) ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Chart -->
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
    <div class="lg:col-span-3">
      <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Amount (THB) by Aging Bucket</p>
      <div class="relative h-[160px]">
        <canvas id="overdueAmtChart"></canvas>
      </div>
    </div>
    <div class="flex flex-col items-center">
      <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 self-start">Number of Requests</p>
      <div class="w-full max-w-[200px]">
        <canvas id="overdueCountChart"></canvas>
      </div>
    </div>
  </div>

  <?php if (!empty($overdueStatusRows)): ?>
  <div class="mt-5 border-t pt-4">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Overdue by Status</p>
    <div class="space-y-2">
      <?php foreach ($overdueStatusRows as $sr): ?>
      <?php
      $pct = $totalOverdueAmt > 0 ? round(((float)$sr['total'] / $totalOverdueAmt) * 100) : 0;
      ?>
      <div class="flex items-center gap-3 text-sm">
        <div class="w-36 shrink-0"><?= statusBadge($sr['status']) ?></div>
        <div class="flex-1 rounded-full bg-gray-100 h-2">
          <div class="h-2 rounded-full bg-red-400" style="width:<?= $pct ?>%"></div>
        </div>
        <span class="w-8 text-right text-xs text-gray-500"><?= (int)$sr['cnt'] ?></span>
        <span class="w-32 text-right text-xs font-medium text-gray-700"><?= fmtMoney((float)$sr['total']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels  = ['≤ 30 Days', '31–90 Days', '91–120 Days', '120+ Days'];
  const amounts = [
    <?= (float)$overdue30['total'] ?>,
    <?= (float)$overdue90['total'] ?>,
    <?= (float)$overdue120['total'] ?>,
    <?= (float)$overdue120plus['total'] ?>
  ];
  const counts = [
    <?= (int)$overdue30['cnt'] ?>,
    <?= (int)$overdue90['cnt'] ?>,
    <?= (int)$overdue120['cnt'] ?>,
    <?= (int)$overdue120plus['cnt'] ?>
  ];
  const colors = [
    'rgba(245,158,11,0.75)',
    'rgba(249,115,22,0.75)',
    'rgba(239,68,68,0.75)',
    'rgba(225,29,72,0.85)',
  ];
  const borders = ['#f59e0b','#f97316','#ef4444','#e11d48'];

  const fmtTHB = v => 'THB ' + v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const fmtK   = v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v;

  const baseOpts = (horizontal) => ({
    responsive: true,
    indexAxis: horizontal ? 'y' : 'x',
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } },
      y: { grid: { display: false }, ticks: { font: { size: 11 } } }
    }
  });

  // Amount bar chart (horizontal)
  new Chart(document.getElementById('overdueAmtChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: amounts,
        backgroundColor: colors,
        borderColor: borders,
        borderWidth: 1,
        borderRadius: 5,
        maxBarThickness: 32,
      }]
    },
    options: {
      ...baseOpts(true),
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ' ' + fmtTHB(ctx.raw) } }
      },
      scales: {
        x: { ticks: { callback: fmtK, font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.05)' } },
        y: { grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });

  // Count doughnut chart
  new Chart(document.getElementById('overdueCountChart'), {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: counts,
        backgroundColor: colors,
        borderColor: borders,
        borderWidth: 1,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 10 }, boxWidth: 12, padding: 8 }
        },
        tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.raw + ' req' } }
      }
    }
  });
})();
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
