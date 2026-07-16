<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('cheques')) { flash('error', 'Access denied'); redirect(BASE_URL . '/dashboard.php'); }

$pageTitle = 'Paid History Validation';
$db = getDB();

function scalarCount(PDO $db, string $sql): int {
    return (int)$db->query($sql)->fetchColumn();
}

$financeHistory = $db->query("
    SELECT c.id,c.cheque_no,c.cheque_date,c.bank,c.amount,c.payee_name,c.status,c.received_date,c.source_id,
           COUNT(f.id) AS invoice_rows,
           ROUND(COALESCE(SUM(f.net_payable),0),2) AS source_amount,
           MIN(f.paid_date) AS first_paid_date,
           MAX(f.paid_date) AS last_paid_date
    FROM cheques c
    LEFT JOIN finance_ap_records f
      ON f.import_batch_id=c.source_id
     AND TRIM(f.cheque_no)=TRIM(c.cheque_no)
     AND f.payment_status='Paid'
    WHERE c.source_type='finance_import' AND c.status='received'
    GROUP BY c.id
    ORDER BY c.received_date DESC,c.cheque_no DESC
")->fetchAll();

$invalidFinanceGroups = 0;
foreach ($financeHistory as &$row) {
    $row['amount_delta'] = round((float)$row['amount'] - (float)$row['source_amount'], 2);
    $row['is_valid'] = abs((float)$row['amount_delta']) < 0.01
        && $row['received_date'] !== ''
        && $row['received_date'] === $row['last_paid_date']
        && (int)$row['invoice_rows'] > 0;
    if (!$row['is_valid']) $invalidFinanceGroups++;
}
unset($row);

$paidRequests = $db->query("
    SELECT pr.*,
           (SELECT COUNT(*) FROM attachments a
            WHERE a.related_type='payment_request' AND a.related_id=pr.id
              AND a.document_type='Payment Proof' AND a.is_deleted=0) AS proof_count
    FROM payment_requests pr
    WHERE pr.status='Paid' AND pr.is_deleted=0
    ORDER BY pr.payment_date DESC,pr.id DESC
")->fetchAll();

$invalidPaidRequests = 0;
foreach ($paidRequests as &$request) {
    $request['is_valid'] = $request['payment_date'] !== ''
        && $request['payment_reference'] !== ''
        && $request['payment_bank'] !== ''
        && $request['payer_name'] !== ''
        && (int)$request['proof_count'] > 0;
    if (!$request['is_valid']) $invalidPaidRequests++;
}
unset($request);

$paidFinanceRows = scalarCount($db, "SELECT COUNT(*) FROM finance_ap_records WHERE payment_status='Paid'");
$paidFinanceGroups = scalarCount($db, "SELECT COUNT(DISTINCT TRIM(cheque_no)) FROM finance_ap_records WHERE payment_status='Paid' AND TRIM(COALESCE(cheque_no,''))<>''");
$missingFinanceGroups = max(0, $paidFinanceGroups - count($financeHistory));
$paidRowsMissingDate = scalarCount($db, "SELECT COUNT(*) FROM finance_ap_records WHERE payment_status='Paid' AND COALESCE(paid_date,'')=''");
$nonPaidWithDate = scalarCount($db, "SELECT COUNT(*) FROM finance_ap_records WHERE payment_status<>'Paid' AND COALESCE(paid_date,'')<>''");
$receivedMissingEvidence = scalarCount($db, "SELECT COUNT(*) FROM cheques WHERE status='received' AND (COALESCE(received_date,'')='' OR COALESCE(receiver_name,'')='')");
$futurePaymentDates = scalarCount($db, "SELECT (SELECT COUNT(*) FROM finance_ap_records WHERE paid_date>date('now','localtime')) + (SELECT COUNT(*) FROM cheques WHERE received_date>date('now','localtime'))");
$duplicateSourceLines = scalarCount($db, "SELECT COUNT(*) FROM (
    SELECT cheque_no,vendor_name,invoice_no,COALESCE(tax_invoice_no,'')
    FROM finance_ap_records WHERE payment_status='Paid'
    GROUP BY cheque_no,vendor_name,invoice_no,COALESCE(tax_invoice_no,'') HAVING COUNT(*)>1
)");

$financePaidTotal = (float)$db->query("SELECT COALESCE(SUM(net_payable),0) FROM finance_ap_records WHERE payment_status='Paid'")->fetchColumn();
$registeredPaidTotal = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM cheques WHERE source_type='finance_import' AND status='received'")->fetchColumn();
$paidRequestTotal = array_sum(array_map(static fn($row): float => (float)$row['net_payable'], $paidRequests));
$reconciliationDelta = round($registeredPaidTotal - $financePaidTotal, 2);

$filterQuery = trim($_GET['q'] ?? '');
$filterSource = trim($_GET['source'] ?? 'all');
$filterCheck = trim($_GET['check'] ?? '');
$filterBank = trim($_GET['bank'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
if (!in_array($filterSource, ['all', 'finance', 'payment_requests'], true)) $filterSource = 'all';
if (!in_array($filterCheck, ['', 'pass', 'review'], true)) $filterCheck = '';

$matchesHistoryFilter = static function (array $row, string $type) use ($filterQuery, $filterCheck, $filterBank, $filterDateFrom, $filterDateTo): bool {
    $isValid = !empty($row['is_valid']);
    if ($filterCheck === 'pass' && !$isValid) return false;
    if ($filterCheck === 'review' && $isValid) return false;
    $bank = $type === 'finance' ? (string)($row['bank'] ?? '') : (string)($row['payment_bank'] ?? '');
    if ($filterBank !== '' && $bank !== $filterBank) return false;
    $date = $type === 'finance' ? (string)($row['received_date'] ?? '') : (string)($row['payment_date'] ?? '');
    if ($filterDateFrom !== '' && $date < $filterDateFrom) return false;
    if ($filterDateTo !== '' && $date > $filterDateTo) return false;
    if ($filterQuery !== '') {
        $haystack = $type === 'finance'
            ? implode(' ', [$row['cheque_no'] ?? '', $row['payee_name'] ?? '', $row['bank'] ?? ''])
            : implode(' ', [$row['request_no'] ?? '', $row['vendor_name'] ?? '', $row['payment_reference'] ?? '', $row['payment_bank'] ?? '', $row['payer_name'] ?? '']);
        if (stripos($haystack, $filterQuery) === false) return false;
    }
    return true;
};

$filteredFinanceHistory = $filterSource === 'payment_requests' ? [] : array_values(array_filter($financeHistory, static fn(array $row): bool => $matchesHistoryFilter($row, 'finance')));
$filteredPaidRequests = $filterSource === 'finance' ? [] : array_values(array_filter($paidRequests, static fn(array $row): bool => $matchesHistoryFilter($row, 'payment_request')));
$filteredPaidRequestTotal = array_sum(array_map(static fn($row): float => (float)$row['net_payable'], $filteredPaidRequests));
$historyBanks = array_values(array_unique(array_filter(array_merge(
    array_map(static fn($row): string => (string)($row['bank'] ?? ''), $financeHistory),
    array_map(static fn($row): string => (string)($row['payment_bank'] ?? ''), $paidRequests)
))));
sort($historyBanks, SORT_NATURAL | SORT_FLAG_CASE);

$checks = [
    ['name' => 'Finance paid rows have paid dates', 'failures' => $paidRowsMissingDate],
    ['name' => 'Non-paid rows do not contain paid dates', 'failures' => $nonPaidWithDate],
    ['name' => 'Every paid Finance cheque is registered', 'failures' => $missingFinanceGroups],
    ['name' => 'Finance cheque amounts and dates match the source', 'failures' => $invalidFinanceGroups],
    ['name' => 'Received cheques have receiver and received date', 'failures' => $receivedMissingEvidence],
    ['name' => 'Payment dates are not in the future', 'failures' => $futurePaymentDates],
    ['name' => 'No exact duplicate paid source lines', 'failures' => $duplicateSourceLines],
    ['name' => 'Paid Payment Requests have required fields and proof', 'failures' => $invalidPaidRequests],
];
$totalFailures = array_sum(array_column($checks, 'failures'));
$validationPassed = $totalFailures === 0 && abs($reconciliationDelta) < 0.01;

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
  <div>
    <a href="<?= BASE_URL ?>/modules/cheques/" class="text-sm text-blue-600 hover:underline">← Back to Cheque Register</a>
    <h1 class="mt-2 text-2xl font-bold text-gray-800">Paid History Validation</h1>
    <p class="mt-1 text-sm text-gray-500">Reconciles Finance paid records, received cheques, Payment Requests, and payment evidence.</p>
  </div>
  <div class="rounded-xl px-5 py-3 text-white <?= $validationPassed ? 'bg-green-600' : 'bg-red-600' ?>">
    <p class="text-xs uppercase tracking-wider">Validation status</p>
    <p class="text-2xl font-bold"><?= $validationPassed ? 'PASS' : 'REVIEW' ?></p>
  </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Paid invoice rows</p><p class="text-xl font-bold text-gray-800"><?= number_format($paidFinanceRows) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Received cheques</p><p class="text-xl font-bold text-gray-800"><?= number_format(count($financeHistory)) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Finance paid total</p><p class="text-xl font-bold text-green-700">฿<?= fmtMoney($financePaidTotal) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Register total</p><p class="text-xl font-bold text-blue-700">฿<?= fmtMoney($registeredPaidTotal) ?></p></div>
  <div class="rounded-xl border bg-white p-4"><p class="text-xs text-gray-500">Difference</p><p class="text-xl font-bold <?= abs($reconciliationDelta) < 0.01 ? 'text-green-700' : 'text-red-700' ?>">฿<?= fmtMoney($reconciliationDelta) ?></p></div>
</div>

<div class="mb-5 rounded-xl border bg-white p-4 shadow-sm">
  <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
    <div class="xl:col-span-2">
      <label class="mb-1 block text-xs font-medium text-gray-500">Search</label>
      <input type="search" name="q" value="<?= h($filterQuery) ?>" placeholder="Cheque, supplier, request, reference, payer..." class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500">Record Type</label>
      <select name="source" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value="all" <?= $filterSource === 'all' ? 'selected' : '' ?>>All records</option>
        <option value="finance" <?= $filterSource === 'finance' ? 'selected' : '' ?>>Finance paid cheques</option>
        <option value="payment_requests" <?= $filterSource === 'payment_requests' ? 'selected' : '' ?>>Paid Payment Requests</option>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500">Validation</label>
      <select name="check" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value="">All results</option><option value="pass" <?= $filterCheck === 'pass' ? 'selected' : '' ?>>PASS</option><option value="review" <?= $filterCheck === 'review' ? 'selected' : '' ?>>REVIEW</option>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500">Bank</label>
      <select name="bank" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value="">All banks</option><?php foreach ($historyBanks as $bank): ?><option value="<?= h($bank) ?>" <?= $filterBank === $bank ? 'selected' : '' ?>><?= h($bank) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <div><label class="mb-1 block text-xs font-medium text-gray-500">Date From</label><input type="date" name="date_from" value="<?= h($filterDateFrom) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-2 py-2 text-sm"></div>
      <div><label class="mb-1 block text-xs font-medium text-gray-500">Date To</label><input type="date" name="date_to" value="<?= h($filterDateTo) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-2 py-2 text-sm"></div>
    </div>
    <div class="flex items-end gap-2 md:col-span-2 xl:col-span-6">
      <button type="submit" class="theme-btn-primary rounded-lg px-5 py-2 text-sm font-medium">Search</button>
      <a href="<?= BASE_URL ?>/modules/cheques/paid_history.php" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Reset</a>
      <span class="ml-auto text-sm text-gray-500"><?= number_format(count($filteredFinanceHistory) + count($filteredPaidRequests)) ?> result(s)</span>
    </div>
  </form>
</div>

<div class="mb-5 rounded-xl border bg-white p-5">
  <h2 class="mb-3 text-lg font-semibold text-gray-800">Validation Checks</h2>
  <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($checks as $check): $passed = (int)$check['failures'] === 0; ?>
    <div class="flex items-center justify-between rounded-lg border px-3 py-3 <?= $passed ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?>">
      <span class="text-sm text-gray-700"><?= h($check['name']) ?></span>
      <span class="ml-3 rounded-full px-2 py-0.5 text-xs font-semibold <?= $passed ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800' ?>"><?= $passed ? 'PASS' : number_format($check['failures']) . ' issue(s)' ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($filterSource !== 'payment_requests'): ?><div class="mb-5 rounded-xl border bg-white overflow-hidden">
  <div class="border-b px-5 py-4"><h2 class="text-lg font-semibold text-gray-800">Finance Paid Cheques</h2><p class="text-sm text-gray-500">Each cheque is reconciled to its underlying paid invoice rows.</p></div>
  <div class="overflow-x-auto"><table class="datatable w-full text-sm" data-searching="false"><thead><tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-3 py-3">Cheque</th><th class="px-3 py-3">Payee</th><th class="px-3 py-3">Bank</th><th class="px-3 py-3">Cheque Date</th><th class="px-3 py-3">Paid Date</th><th class="px-3 py-3 text-right">Invoices</th><th class="px-3 py-3 text-right">Source</th><th class="px-3 py-3 text-right">Register</th><th class="px-3 py-3 text-right">Difference</th><th class="px-3 py-3">Check</th></tr></thead><tbody>
  <?php foreach ($filteredFinanceHistory as $row): ?>
    <tr class="border-b last:border-0 hover:bg-gray-50"><td class="px-3 py-2 font-mono font-semibold"><?= h($row['cheque_no']) ?></td><td class="px-3 py-2"><?= h($row['payee_name']) ?></td><td class="px-3 py-2 text-xs"><?= h((string)$row['bank']) ?></td><td class="px-3 py-2 text-xs"><?= fmtDate($row['cheque_date']) ?></td><td class="px-3 py-2 text-xs"><?= fmtDate($row['received_date']) ?></td><td class="px-3 py-2 text-right"><?= number_format((int)$row['invoice_rows']) ?></td><td class="px-3 py-2 text-right"><?= fmtMoney((float)$row['source_amount']) ?></td><td class="px-3 py-2 text-right font-semibold"><?= fmtMoney((float)$row['amount']) ?></td><td class="px-3 py-2 text-right <?= abs((float)$row['amount_delta']) < 0.01 ? 'text-green-700' : 'text-red-700' ?>"><?= fmtMoney((float)$row['amount_delta']) ?></td><td class="px-3 py-2"><?= $row['is_valid'] ? '<span class="text-green-700">PASS</span>' : '<span class="font-semibold text-red-700">REVIEW</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$filteredFinanceHistory): ?><tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">No Finance paid cheques matched the filters.</td></tr><?php endif; ?>
  </tbody></table></div>
</div><?php endif; ?>

<?php if ($filterSource !== 'finance'): ?><div class="rounded-xl border bg-white overflow-hidden">
  <div class="border-b px-5 py-4"><h2 class="text-lg font-semibold text-gray-800">Paid Payment Requests</h2><p class="text-sm text-gray-500"><?= number_format(count($paidRequests)) ?> request(s), total ฿<?= fmtMoney($paidRequestTotal) ?></p></div>
  <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-4 py-3">Request</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Payment Date</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3">Bank / Payer</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3">Proof</th><th class="px-4 py-3">Check</th></tr></thead><tbody>
  <?php foreach ($filteredPaidRequests as $request): ?><tr class="border-b last:border-0"><td class="px-4 py-3"><a class="font-mono text-blue-600 hover:underline" href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= (int)$request['id'] ?>"><?= h($request['request_no']) ?></a></td><td class="px-4 py-3"><?= h($request['vendor_name']) ?></td><td class="px-4 py-3"><?= fmtDate($request['payment_date']) ?></td><td class="px-4 py-3 font-mono text-xs"><?= h($request['payment_reference']) ?></td><td class="px-4 py-3 text-xs"><?= h($request['payment_bank']) ?><br><?= h($request['payer_name']) ?></td><td class="px-4 py-3 text-right font-semibold"><?= fmtMoney((float)$request['net_payable']) ?></td><td class="px-4 py-3"><?= number_format((int)$request['proof_count']) ?></td><td class="px-4 py-3"><?= $request['is_valid'] ? '<span class="text-green-700">PASS</span>' : '<span class="font-semibold text-red-700">REVIEW</span>' ?></td></tr><?php endforeach; ?>
  <?php if (!$filteredPaidRequests): ?><tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No paid Payment Requests matched the filters.</td></tr><?php endif; ?>
  </tbody></table></div>
</div><?php endif; ?>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
