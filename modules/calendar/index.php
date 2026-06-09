<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
$pageTitle = 'Payment Calendar';
$db = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2020 || $year > 2035) $year = date('Y');

$firstDay   = mktime(0,0,0,$month,1,$year);
$daysInMonth= (int)date('t', $firstDay);
$startDow   = (int)date('N', $firstDay); // 1=Mon, 7=Sun

$monthStart = date('Y-m-d', $firstDay);
$monthEnd   = date('Y-m-d', mktime(0,0,0,$month,$daysInMonth,$year));

// Load payment by date
$stmt = $db->prepare("
    SELECT due_date, SUM(net_payable) as total, COUNT(*) as cnt,
           SUM(CASE WHEN status IN ('Paid') THEN net_payable ELSE 0 END) as paid_total
    FROM payment_requests
    WHERE due_date BETWEEN ? AND ? AND is_deleted=0 AND status NOT IN ('draft','Rejected','Cancelled')
    GROUP BY due_date
");
$stmt->execute([$monthStart, $monthEnd]);
$payByDate = [];
foreach ($stmt->fetchAll() as $row) {
    $payByDate[$row['due_date']] = $row;
}

// Summary stats
$today = date('Y-m-d');
$todayPay  = $payByDate[$today]['total'] ?? 0;
$monthTotal = array_sum(array_column(array_values($payByDate), 'total'));

// Weekly view data (next 4 weeks)
$weekly = $db->query("
    SELECT
        strftime('%W', due_date) as week,
        MIN(due_date) as week_start,
        MAX(due_date) as week_end,
        SUM(net_payable) as total,
        COUNT(*) as cnt
    FROM payment_requests
    WHERE due_date >= date('now') AND due_date <= date('now', '+28 days')
      AND is_deleted=0 AND status NOT IN ('draft','Rejected','Cancelled','Paid')
    GROUP BY week ORDER BY week
")->fetchAll();

// Overdue
$overdue = $db->query("
    SELECT vendor_name, request_no, net_payable, due_date, status
    FROM payment_requests
    WHERE due_date < date('now') AND status NOT IN ('Paid','Rejected','Cancelled','draft') AND is_deleted=0
    ORDER BY due_date ASC LIMIT 20
")->fetchAll();

$prevMonth = $month == 1 ? 12 : $month - 1;
$prevYear  = $month == 1 ? $year - 1 : $year;
$nextMonth = $month == 12 ? 1 : $month + 1;
$nextYear  = $month == 12 ? $year + 1 : $year;

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center justify-between">
  <div>
    <h1 class="text-2xl font-bold text-gray-800">Payment Calendar</h1>
    <p class="text-gray-500 text-sm">ตารางการจ่ายเงินรายเดือน</p>
  </div>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
  <div class="bg-white border rounded-xl p-4 bg-blue-50 border-blue-200">
    <p class="text-xs text-gray-500">Today's Payment</p>
    <p class="text-xl font-bold text-blue-700">฿<?= fmtMoney($todayPay) ?></p>
  </div>
  <div class="bg-white border rounded-xl p-4 bg-purple-50 border-purple-200">
    <p class="text-xs text-gray-500">This Month Total</p>
    <p class="text-xl font-bold text-purple-700">฿<?= fmtMoney($monthTotal) ?></p>
  </div>
  <div class="bg-white border rounded-xl p-4 bg-red-50 border-red-200">
    <p class="text-xs text-gray-500">Overdue Items</p>
    <p class="text-xl font-bold text-red-700"><?= count($overdue) ?></p>
  </div>
  <div class="bg-white border rounded-xl p-4 bg-green-50 border-green-200">
    <p class="text-xs text-gray-500">Days with Payment</p>
    <p class="text-xl font-bold text-green-700"><?= count($payByDate) ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

  <!-- Calendar -->
  <div class="lg:col-span-2 bg-white rounded-xl border p-5">
    <!-- Month Nav -->
    <div class="flex items-center justify-between mb-4">
      <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="p-2 hover:bg-gray-100 rounded-lg text-gray-600">◀</a>
      <h3 class="font-bold text-gray-800 text-lg"><?= date('F Y', $firstDay) ?></h3>
      <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="p-2 hover:bg-gray-100 rounded-lg text-gray-600">▶</a>
    </div>

    <!-- Day Headers -->
    <div class="grid grid-cols-7 mb-2">
      <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?>
      <div class="text-center text-xs font-medium text-gray-400 py-1"><?= $dn ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Calendar Grid -->
    <div class="grid grid-cols-7 gap-1">
      <?php
      // Empty cells before first day
      for ($i = 1; $i < $startDow; $i++):
      ?>
      <div></div>
      <?php endfor; ?>

      <?php for ($day = 1; $day <= $daysInMonth; $day++):
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $hasPay  = isset($payByDate[$dateStr]);
        $isToday = $dateStr === $today;
        $dow     = (int)date('N', mktime(0,0,0,$month,$day,$year));
        $isWeekend = $dow >= 6;
      ?>
      <div class="min-h-[60px] rounded-lg p-1 border <?= $isToday ? 'border-blue-500 bg-blue-50' : ($hasPay ? 'border-orange-200 bg-orange-50' : 'border-gray-100') ?> <?= $isWeekend ? 'opacity-60' : '' ?>">
        <div class="text-xs font-medium mb-0.5 <?= $isToday ? 'text-blue-600' : 'text-gray-600' ?>"><?= $day ?></div>
        <?php if ($hasPay): ?>
        <div class="text-xs leading-tight">
          <div class="text-orange-700 font-semibold">฿<?= number_format($payByDate[$dateStr]['total'] / 1000, 0) ?>K</div>
          <div class="text-gray-500"><?= $payByDate[$dateStr]['cnt'] ?> req</div>
        </div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="space-y-4">

    <!-- Weekly Forecast -->
    <div class="bg-white rounded-xl border p-4">
      <h4 class="font-semibold text-gray-700 mb-3 text-sm">Next 4 Weeks Forecast</h4>
      <?php if (empty($weekly)): ?>
      <p class="text-sm text-gray-400">No upcoming payments</p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($weekly as $w): ?>
        <div class="flex justify-between items-center p-2 bg-blue-50 rounded-lg">
          <div class="text-xs text-gray-600">
            <div class="font-medium"><?= fmtDate($w['week_start']) ?></div>
            <div class="text-gray-400"><?= $w['cnt'] ?> requests</div>
          </div>
          <div class="text-sm font-bold text-blue-700">฿<?= fmtMoney($w['total']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Overdue -->
    <?php if (!empty($overdue)): ?>
    <div class="bg-white rounded-xl border p-4">
      <h4 class="font-semibold text-red-600 mb-3 text-sm">⚠️ Overdue (<?= count($overdue) ?>)</h4>
      <div class="space-y-2 max-h-64 overflow-y-auto">
        <?php foreach ($overdue as $ov): ?>
        <div class="p-2 bg-red-50 border border-red-100 rounded-lg text-xs">
          <div class="flex items-center justify-between">
            <a href="<?= BASE_URL ?>/modules/payment_requests/index.php" class="font-mono text-blue-600 hover:underline"><?= h($ov['request_no']) ?></a>
            <span class="font-bold text-red-700">฿<?= fmtMoney($ov['net_payable']) ?></span>
          </div>
          <div class="text-gray-600 mt-0.5 truncate"><?= h($ov['vendor_name']) ?></div>
          <div class="text-red-500 mt-0.5">Due: <?= fmtDate($ov['due_date']) ?> | <?= h($ov['status']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
