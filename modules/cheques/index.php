<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('cheques')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Cheque Register';
$db   = getDB();
$user = currentUser();
$_SESSION['cheque_csrf'] ??= bin2hex(random_bytes(32));
$chequeCsrf = $_SESSION['cheque_csrf'];

function isValidIsoDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

$thaiBanks = [
    'ธนาคารกรุงเทพ จำกัด (มหาชน)',
    'ธนาคารกรุงไทย จำกัด (มหาชน)',
    'ธนาคารกรุงศรีอยุธยา จำกัด (มหาชน)',
    'ธนาคารกสิกรไทย จำกัด (มหาชน)',
    'ธนาคารเกียรตินาคินภัทร จำกัด (มหาชน)',
    'ธนาคารซีไอเอ็มบี ไทย จำกัด (มหาชน)',
    'ธนาคารทหารไทยธนชาต จำกัด (มหาชน)',
    'ธนาคารทิสโก้ จำกัด (มหาชน)',
    'ธนาคารไทยเครดิต จำกัด (มหาชน)',
    'ธนาคารไทยพาณิชย์ จำกัด (มหาชน)',
    'ธนาคารยูโอบี จำกัด (มหาชน)',
    'ธนาคารแลนด์ แอนด์ เฮ้าส์ จำกัด (มหาชน)',
    'ธนาคารไอซีบีซี (ไทย) จำกัด (มหาชน)',
    'ธนาคารแห่งประเทศจีน (ไทย) จำกัด (มหาชน)',
    'ธนาคารออมสิน',
    'ธนาคารเพื่อการเกษตรและสหกรณ์การเกษตร',
    'ธนาคารอาคารสงเคราะห์',
    'ธนาคารอิสลามแห่งประเทศไทย',
    'ธนาคารเพื่อการส่งออกและนำเข้าแห่งประเทศไทย',
    'ธนาคารพัฒนาวิสาหกิจขนาดกลางและขนาดย่อมแห่งประเทศไทย',
];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $makerStageUpdate = hasRole('maker') && $action === 'update_status';
    if (!canEdit('cheques') && !$makerStageUpdate) {
        flash('error', 'Cheque Register is read-only for your role.');
        redirect(BASE_URL . '/modules/cheques/');
    }
    if (!hash_equals($chequeCsrf, (string)($_POST['csrf_token'] ?? ''))) {
        flash('error', 'Your session token expired. Please try again.');
        redirect(BASE_URL . '/modules/cheques/');
    }
    if ($action === 'sync_finance') {
        $batchId = (int)($_POST['finance_batch_id'] ?? 0);
        $stmtBatch = $db->prepare("SELECT * FROM finance_import_batches WHERE id=? AND status IN ('completed','completed_with_errors')");
        $stmtBatch->execute([$batchId]);
        $financeBatch = $stmtBatch->fetch();
        if (!$financeBatch) {
            flash('error', 'Finance import batch not found or not ready.');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $stmtGroups = $db->prepare("
            SELECT TRIM(cheque_no) AS cheque_no,
                   MIN(cheque_date) AS cheque_date,
                   MIN(cheque_bank) AS cheque_bank,
                   ROUND(SUM(net_payable), 2) AS amount,
                   COUNT(DISTINCT vendor_name) AS vendor_count,
                   GROUP_CONCAT(DISTINCT vendor_name) AS vendor_names,
                   SUM(CASE WHEN payment_status='Paid' THEN 1 ELSE 0 END) AS paid_rows,
                   COUNT(*) AS total_rows,
                   MAX(NULLIF(paid_date, '')) AS received_date
            FROM finance_ap_records
            WHERE import_batch_id=? AND TRIM(COALESCE(cheque_no,''))<>''
            GROUP BY TRIM(cheque_no)
            ORDER BY TRIM(cheque_no)
        ");
        $stmtGroups->execute([$batchId]);
        $groups = $stmtGroups->fetchAll();
        if (!$groups) {
            flash('error', 'No cheque numbers were found in this Finance batch.');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $findExisting = $db->prepare("SELECT * FROM cheques WHERE cheque_no=? COLLATE NOCASE LIMIT 1");
        $findVendor = $db->prepare("SELECT id FROM vendors WHERE vendor_name=? COLLATE NOCASE LIMIT 1");
        $insertCheque = $db->prepare("INSERT INTO cheques
            (cheque_no, cheque_date, bank, amount, payee_name, vendor_id, status, receiver_name, received_date, created_by, source_type, source_id)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'finance_import', ?)");
        $updateCheque = $db->prepare("UPDATE cheques SET cheque_date=?,bank=?,amount=?,payee_name=?,vendor_id=?,status=?,receiver_name=?,received_date=?,source_id=?,updated_at=datetime('now','localtime') WHERE id=?");

        $created = 0;
        $updated = 0;
        $conflicts = 0;
        $db->beginTransaction();
        try {
            foreach ($groups as $group) {
                $vendorCount = (int)$group['vendor_count'];
                $payeeName = $vendorCount === 1 ? (string)$group['vendor_names'] : "Multiple suppliers ({$vendorCount})";
                $vendorId = null;
                if ($vendorCount === 1) {
                    $findVendor->execute([$payeeName]);
                    $vendorId = $findVendor->fetchColumn() ?: null;
                }
                $isReceived = (int)$group['paid_rows'] === (int)$group['total_rows'];
                $status = $isReceived ? 'received' : 'prepared';
                $receivedDate = $isReceived ? ($group['received_date'] ?: null) : null;
                $receiverName = $isReceived ? $payeeName : null;

                $findExisting->execute([$group['cheque_no']]);
                $existing = $findExisting->fetch();
                if ($existing && ($existing['source_type'] ?? '') !== 'finance_import') {
                    $conflicts++;
                    continue;
                }
                if ($existing) {
                    $updateCheque->execute([
                        $group['cheque_date'] ?: null, $group['cheque_bank'] ?: null, $group['amount'], $payeeName,
                        $vendorId, $status, $receiverName, $receivedDate, $batchId, $existing['id'],
                    ]);
                    $updated++;
                } else {
                    $insertCheque->execute([
                        $group['cheque_no'], $group['cheque_date'] ?: null, $group['cheque_bank'] ?: null, $group['amount'],
                        $payeeName, $vendorId, $status, $receiverName, $receivedDate, $user['id'], $batchId,
                    ]);
                    $created++;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Unable to synchronize Finance cheques.');
            redirect(BASE_URL . '/modules/cheques/');
        }

        auditLog('SYNC_FINANCE_CHEQUES', 'cheques', $batchId, '', "created={$created} updated={$updated} conflicts={$conflicts}");
        flash('success', "Finance cheque sync completed: {$created} created, {$updated} updated, {$conflicts} conflicts skipped.");
    }

    if ($action === 'create_cheque') {
        $prId      = (int)($_POST['payment_request_id'] ?? 0);
        $chequeNo  = trim($_POST['cheque_no'] ?? '');
        $chequeDate= trim($_POST['cheque_date'] ?? '');
        $bank      = trim($_POST['bank'] ?? '');

        if ($chequeNo === '' || mb_strlen($chequeNo) > 100) {
            flash('error', 'กรุณาระบุเลขที่เช็คที่ถูกต้อง');
            redirect(BASE_URL . '/modules/cheques/');
        }
        if (!isValidIsoDate($chequeDate)) {
            flash('error', 'กรุณาระบุวันที่เช็คที่ถูกต้อง');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $stmtPR = $db->prepare("SELECT * FROM payment_requests WHERE id=? AND is_deleted=0");
        $stmtPR->execute([$prId]);
        $pr = $stmtPR->fetch();

        if (!$pr) { flash('error','Payment Request not found'); redirect(BASE_URL . '/modules/cheques/'); }
        if (!in_array($pr['status'], ['Approved for Payment'], true)) {
            flash('error', 'Cheque สามารถสร้างได้เฉพาะรายการที่ Approved แล้วเท่านั้น');
            redirect(BASE_URL . '/modules/cheques/');
        }
        if (!in_array($bank, $thaiBanks, true)) {
            flash('error', 'กรุณาเลือกธนาคารจากรายการ');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $stmtDuplicate = $db->prepare("SELECT id FROM cheques WHERE cheque_no = ? COLLATE NOCASE LIMIT 1");
        $stmtDuplicate->execute([$chequeNo]);
        if ($stmtDuplicate->fetchColumn()) {
            flash('error', 'เลขที่เช็คนี้มีอยู่ในระบบแล้ว');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $stmtExisting = $db->prepare("SELECT id FROM cheques WHERE payment_request_id = ? AND status NOT IN ('void', 'cancelled') LIMIT 1");
        $stmtExisting->execute([$prId]);
        if ($stmtExisting->fetchColumn()) {
            flash('error', 'Payment Request นี้มีเช็คที่ใช้งานอยู่แล้ว');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $db->prepare("INSERT INTO cheques (cheque_no, cheque_date, bank, amount, payee_name, payment_request_id, vendor_id, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$chequeNo, $chequeDate, $bank, $pr['net_payable'], $pr['vendor_name'], $prId, $pr['vendor_id'], 'prepared', $user['id']]);
        $chequeId = $db->lastInsertId();
        auditLog('CREATE_CHEQUE', 'cheques', $chequeId, '', "cheque_no=$chequeNo amount={$pr['net_payable']}");
        flash('success', "เช็ค {$chequeNo} สร้างสำเร็จ");
    }

    if ($action === 'update_status') {
        $chequeId  = (int)($_POST['cheque_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $receiver  = trim($_POST['receiver_name'] ?? '');
        $recvDate  = trim($_POST['received_date'] ?? '');
        $voidReason= trim($_POST['void_reason'] ?? '');

        $validStatuses = ['prepared','signed','released','received','cancelled','void'];
        if (!in_array($newStatus, $validStatuses)) {
            flash('error', 'Invalid status');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $stmtC = $db->prepare("SELECT * FROM cheques WHERE id=?");
        $stmtC->execute([$chequeId]);
        $cheque = $stmtC->fetch();
        if (!$cheque) { flash('error','Cheque not found'); redirect(BASE_URL . '/modules/cheques/'); }

        $allowedTransitions = [
            'prepared' => ['signed', 'cancelled', 'void'],
            'signed' => ['released', 'cancelled', 'void'],
            'released' => ['received', 'cancelled', 'void'],
            'received' => [],
            'cancelled' => [],
            'void' => [],
        ];
        if (!in_array($newStatus, $allowedTransitions[$cheque['status']] ?? [], true)) {
            flash('error', "Cheque status cannot change from {$cheque['status']} to {$newStatus}.");
            redirect(BASE_URL . '/modules/cheques/');
        }
        if (!canEdit('cheques') && !($cheque['status'] === 'prepared' && $newStatus === 'signed')) {
            flash('error', 'Your role can only move cheques from Prepared to Signed.');
            redirect(BASE_URL . '/modules/cheques/?status=' . urlencode((string)$cheque['status']));
        }

        if ($newStatus === 'received' && ($receiver === '' || !isValidIsoDate($recvDate))) {
            flash('error', 'กรุณาระบุชื่อผู้รับและวันที่รับเช็ค');
            redirect(BASE_URL . '/modules/cheques/');
        }
        if (in_array($newStatus, ['void', 'cancelled'], true) && $voidReason === '') {
            flash('error', 'กรุณาระบุเหตุผลในการยกเลิกเช็ค');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $db->prepare("UPDATE cheques SET status=?, receiver_name=?, received_date=?, void_reason=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$newStatus, $receiver ?: null, $recvDate ?: null, $voidReason ?: null, $chequeId]);

        auditLog('UPDATE_CHEQUE_STATUS', 'cheques', $chequeId, $cheque['status'], $newStatus);
        flash('success', "เช็ค #" . h($cheque['cheque_no']) . " อัปเดตสถานะเป็น {$newStatus}");
        redirect(BASE_URL . '/modules/cheques/?status=' . urlencode($newStatus));
    }

    redirect(BASE_URL . '/modules/cheques/');
}

$filterStatus = trim($_GET['status'] ?? '');
$filterQuery = trim($_GET['q'] ?? '');
$filterBank = trim($_GET['bank'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'c.status=?'; $params[] = $filterStatus; }
if ($filterQuery !== '') {
    $where[] = '(c.cheque_no LIKE ? OR c.payee_name LIKE ? OR c.receiver_name LIKE ? OR c.bank LIKE ? OR pr.request_no LIKE ?)';
    $searchLike = '%' . $filterQuery . '%';
    array_push($params, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
}
if ($filterBank !== '') { $where[] = 'c.bank=?'; $params[] = $filterBank; }
if ($filterDateFrom !== '' && isValidIsoDate($filterDateFrom)) { $where[] = 'c.cheque_date>=?'; $params[] = $filterDateFrom; }
if ($filterDateTo !== '' && isValidIsoDate($filterDateTo)) { $where[] = 'c.cheque_date<=?'; $params[] = $filterDateTo; }

$cheques = $db->prepare("SELECT c.*, pr.request_no, pr.vendor_name, u.full_name as creator_name
                          FROM cheques c
                          LEFT JOIN payment_requests pr ON pr.id = c.payment_request_id
                          LEFT JOIN users u ON u.id = c.created_by
                          WHERE " . implode(' AND ', $where) . "
                          ORDER BY c.id DESC");
$cheques->execute($params);
$cheques = $cheques->fetchAll();

// PRs eligible for cheque creation. A void/cancelled cheque can be replaced,
// but a request with an active cheque must not be issued twice.
$eligiblePRs = $db->query("
    SELECT pr.*
    FROM payment_requests pr
    WHERE pr.status = 'Approved for Payment'
      AND pr.is_deleted = 0
      AND pr.payment_method = 'cheque'
      AND NOT EXISTS (
          SELECT 1
          FROM cheques c
          WHERE c.payment_request_id = pr.id
            AND c.status NOT IN ('void', 'cancelled')
      )
    ORDER BY pr.due_date
")->fetchAll();

$chequeStatuses = ['prepared','signed','released','received','cancelled','void'];
$chequeBanks = $db->query("SELECT DISTINCT bank FROM cheques WHERE TRIM(COALESCE(bank,''))<>'' ORDER BY bank")->fetchAll(PDO::FETCH_COLUMN);
$preservedFilters = array_filter([
    'q' => $filterQuery,
    'bank' => $filterBank,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
], static fn($value): bool => $value !== '');
$latestFinanceBatch = $db->query("SELECT * FROM finance_import_batches WHERE status IN ('completed','completed_with_errors') ORDER BY id DESC LIMIT 1")->fetch();
$financeChequeCount = 0;
if ($latestFinanceBatch) {
    $stmtFinanceCount = $db->prepare("SELECT COUNT(DISTINCT TRIM(cheque_no)) FROM finance_ap_records WHERE import_batch_id=? AND TRIM(COALESCE(cheque_no,''))<>''");
    $stmtFinanceCount->execute([$latestFinanceBatch['id']]);
    $financeChequeCount = (int)$stmtFinanceCount->fetchColumn();
}

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center justify-between">
  <div>
    <h1 class="text-2xl font-bold text-gray-800"><?= t('cheque.title') ?></h1>
    <p class="text-gray-500 text-sm"><?= t('cheque.subtitle') ?></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/cheques/paid_history.php"
       class="bg-slate-700 hover:bg-slate-800 text-white px-4 py-2 rounded-lg text-sm font-medium">
      Validate Paid History
    </a>
    <?php if (canEdit('cheques') && $latestFinanceBatch && $financeChequeCount > 0): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= h($chequeCsrf) ?>">
      <input type="hidden" name="action" value="sync_finance">
      <input type="hidden" name="finance_batch_id" value="<?= (int)$latestFinanceBatch['id'] ?>">
      <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
              onclick="return confirm('Synchronize <?= $financeChequeCount ?> Finance cheques into the register?')">
        Sync Finance Cheques (<?= number_format($financeChequeCount) ?>)
      </button>
    </form>
    <?php endif; ?>
    <?php if (canEdit('cheques')): ?><button onclick="document.getElementById('createModal').classList.remove('hidden')"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <?= t('cheque.create') ?>
    </button><?php endif; ?>
  </div>
</div>

<!-- Search and Filters -->
<div class="mb-4 rounded-xl border bg-white p-4 shadow-sm">
  <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
    <div class="xl:col-span-2">
      <label class="mb-1 block text-xs font-medium text-gray-500">Search</label>
      <input type="search" name="q" value="<?= h($filterQuery) ?>"
             placeholder="Cheque no., payee, PR, receiver or bank"
             class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500"><?= t('label.status') ?></label>
      <select name="status" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value=""><?= t('label.all') ?></option>
        <?php foreach ($chequeStatuses as $status): ?><option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500"><?= t('label.bank') ?></label>
      <select name="bank" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value=""><?= t('label.all') ?></option>
        <?php foreach ($chequeBanks as $bank): ?><option value="<?= h($bank) ?>" <?= $filterBank === $bank ? 'selected' : '' ?>><?= h($bank) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500">Cheque Date From</label>
      <input type="date" name="date_from" value="<?= h($filterDateFrom) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs font-medium text-gray-500">Cheque Date To</label>
      <input type="date" name="date_to" value="<?= h($filterDateTo) ?>" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div class="flex items-end gap-2 md:col-span-2 xl:col-span-6">
      <button type="submit" class="theme-btn-primary rounded-lg px-5 py-2 text-sm font-medium">Search</button>
      <a href="<?= BASE_URL ?>/modules/cheques/" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Reset</a>
      <span class="ml-auto text-sm text-gray-500"><?= number_format(count($cheques)) ?> result(s)</span>
    </div>
  </form>
</div>

<!-- Status Filter -->
<div class="flex flex-wrap gap-2 mb-4">
  <a href="?<?= h(http_build_query($preservedFilters)) ?>" class="px-3 py-1.5 rounded-lg border text-sm <?= !$filterStatus ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 hover:bg-gray-50' ?>"><?= t('label.all') ?></a>
  <?php foreach ($chequeStatuses as $s): ?>
  <a href="?<?= h(http_build_query(array_merge($preservedFilters, ['status' => $s]))) ?>" class="px-3 py-1.5 rounded-lg border text-sm <?= $filterStatus === $s ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 hover:bg-gray-50' ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<!-- Cheque Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm datatable" data-searching="false">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
          <th class="px-4 py-3"><?= t('cheque.col.cheque_no') ?></th>
          <th class="px-4 py-3"><?= t('cheque.col.date') ?></th>
          <th class="px-4 py-3"><?= t('label.bank') ?></th>
          <th class="px-4 py-3"><?= t('cheque.col.payee') ?></th>
          <th class="px-4 py-3 text-right"><?= t('label.amount') ?></th>
          <th class="px-4 py-3"><?= t('cheque.col.pr_no') ?></th>
          <th class="px-4 py-3"><?= t('label.status') ?></th>
          <th class="px-4 py-3"><?= t('cheque.col.receiver') ?></th>
          <th class="px-4 py-3"><?= t('label.created') ?></th>
          <th class="px-4 py-3"><?= t('label.actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cheques as $c): ?>
        <tr class="border-b last:border-0 hover:bg-gray-50">
          <td class="px-4 py-2.5 font-mono font-semibold"><?= h($c['cheque_no']) ?></td>
          <td class="px-4 py-2.5 text-xs"><?= fmtDate($c['cheque_date']) ?></td>
          <td class="px-4 py-2.5 text-xs"><?= h($c['bank'] ?? '-') ?></td>
          <td class="px-4 py-2.5">
            <div><?= h($c['payee_name']) ?></div>
          </td>
          <td class="px-4 py-2.5 text-right font-bold text-blue-700">฿<?= fmtMoney($c['amount']) ?></td>
          <td class="px-4 py-2.5 font-mono text-xs">
            <?php if (!empty($c['payment_request_id'])): ?>
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $c['payment_request_id'] ?>" class="text-blue-600 hover:underline">
              <?= h($c['request_no'] ?? '') ?>
            </a>
            <?php elseif (($c['source_type'] ?? '') === 'finance_import'): ?>
            <a href="<?= BASE_URL ?>/modules/import/finance_detail.php?id=<?= (int)$c['source_id'] ?>" class="text-green-700 hover:underline">Finance #<?= (int)$c['source_id'] ?></a>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td class="px-4 py-2.5"><?= statusBadge($c['status']) ?></td>
          <td class="px-4 py-2.5 text-xs text-gray-500">
            <?= h($c['receiver_name'] ?? '-') ?>
            <?php if ($c['received_date']): ?>
            <br><?= fmtDate($c['received_date']) ?>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2.5 text-xs text-gray-400"><?= fmtDateTime($c['created_at']) ?></td>
          <td class="px-4 py-2.5">
            <?php if ($c['status'] === 'prepared' && hasRole('admin', 'finance_manager', 'maker')): ?>
              <form method="POST" class="min-w-28">
                <input type="hidden" name="csrf_token" value="<?= h($chequeCsrf) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                <select name="new_status" onchange="if(this.value !== 'prepared' && confirm('Move cheque <?= h((string)$c['cheque_no']) ?> to Signed?')) this.form.submit(); else this.value='prepared';"
                        class="w-full rounded-lg border border-blue-200 bg-blue-50 px-2 py-1.5 text-xs font-medium text-blue-700 outline-none focus:ring-2 focus:ring-blue-300">
                  <option value="prepared" selected>Prepared</option>
                  <option value="signed">Signed</option>
                </select>
              </form>
            <?php elseif (canEdit('cheques') && !in_array($c['status'], ['received','void','cancelled'])): ?>
              <?php $nextStage = ['signed' => 'released', 'released' => 'received'][$c['status']] ?? ''; ?>
              <div class="flex items-center gap-2 whitespace-nowrap">
                <?php if ($nextStage === 'released'): ?>
                <form method="POST" onsubmit="return confirm('Move cheque <?= h((string)$c['cheque_no']) ?> to <?= h(ucfirst($nextStage)) ?>?');">
                  <input type="hidden" name="csrf_token" value="<?= h($chequeCsrf) ?>">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="new_status" value="<?= h($nextStage) ?>">
                  <button type="submit" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Mark <?= h(ucfirst($nextStage)) ?></button>
                </form>
                <?php elseif ($nextStage === 'received'): ?>
                <button type="button" onclick="openUpdateModal(<?= (int)$c['id'] ?>, <?= h(json_encode((string)$c['cheque_no'])) ?>, '<?= h((string)$c['status']) ?>', 'received')"
                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">Mark Received</button>
                <?php endif; ?>
                <button type="button" onclick="openUpdateModal(<?= (int)$c['id'] ?>, <?= h(json_encode((string)$c['cheque_no'])) ?>, '<?= h((string)$c['status']) ?>', 'cancelled')"
                        class="text-xs text-gray-500 hover:text-red-600 hover:underline">More</button>
              </div>
            <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cheques)): ?>
        <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400"><?= t('cheque.no_cheques') ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Cheque Modal -->
<div id="createModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
    <div class="flex items-center justify-between px-5 py-4 border-b">
      <h3 class="font-semibold text-gray-800"><?= t('cheque.create_title') ?></h3>
      <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <input type="hidden" name="csrf_token" value="<?= h($chequeCsrf) ?>">
      <input type="hidden" name="action" value="create_cheque">
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.select_pr') ?></label>
        <select name="payment_request_id" id="createPaymentRequest" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
          <option value="">-- เลือก PR --</option>
          <?php foreach ($eligiblePRs as $pr): ?>
          <option value="<?= $pr['id'] ?>" data-supplier="<?= h($pr['vendor_name']) ?>"><?= h($pr['request_no']) ?> — <?= h($pr['vendor_name']) ?> — ฿<?= fmtMoney($pr['net_payable']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.col.cheque_no') ?></label>
          <input type="text" name="cheque_no" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Cheque Date</label>
          <input type="date" name="cheque_date" required value="<?= date('Y-m-d') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Bank / ธนาคาร</label>
          <select name="bank" required
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
            <option value="">-- เลือกธนาคาร --</option>
            <?php foreach ($thaiBanks as $bankName): ?>
            <option value="<?= h($bankName) ?>"><?= h($bankName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Payee Name / Supplier</label>
          <input type="text" name="payee_name" id="createPayeeName" readonly required
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none bg-gray-100 text-gray-700 cursor-not-allowed"
                 placeholder="เลือก Payment Request ก่อน">
        </div>
      </div>
      <div class="flex gap-2 justify-end pt-2">
        <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Update Status Modal -->
<div id="updateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
    <div class="flex items-center justify-between px-5 py-4 border-b">
      <h3 class="font-semibold text-gray-800"><?= t('cheque.update_title') ?></h3>
      <button onclick="document.getElementById('updateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <input type="hidden" name="csrf_token" value="<?= h($chequeCsrf) ?>">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="cheque_id" id="updateChequeId">
      <p class="text-sm text-gray-600">Cheque: <span id="updateChequeNo" class="font-semibold"></span></p>
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.new_status') ?></label>
        <select name="new_status" id="updateNewStatus" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none"></select>
      </div>
      <div id="receiverFields" class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.receiver_name') ?></label>
          <input type="text" name="receiver_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="<?= t('cheque.receiver_name') ?>...">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.received_date') ?></label>
          <input type="date" name="received_date" value="<?= date('Y-m-d') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
        </div>
      </div>
      <div id="voidReasonField">
        <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.void_reason') ?></label>
        <input type="text" name="void_reason" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="เหตุผล...">
      </div>
      <div class="flex gap-2 justify-end pt-2">
        <button type="button" onclick="document.getElementById('updateModal').classList.add('hidden')"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Update</button>
      </div>
    </form>
  </div>
</div>

<script>
const createPaymentRequest = document.getElementById('createPaymentRequest');
const createPayeeName = document.getElementById('createPayeeName');

function syncPayeeWithSupplier() {
    const selectedOption = createPaymentRequest.options[createPaymentRequest.selectedIndex];
    createPayeeName.value = selectedOption?.dataset.supplier || '';
}

createPaymentRequest.addEventListener('change', syncPayeeWithSupplier);
syncPayeeWithSupplier();

const chequeTransitions = {
    prepared: ['signed', 'cancelled', 'void'],
    signed: ['released', 'cancelled', 'void'],
    released: ['received', 'cancelled', 'void']
};

function syncUpdateFields() {
    const status = document.getElementById('updateNewStatus').value;
    const receiverFields = document.getElementById('receiverFields');
    const voidReasonField = document.getElementById('voidReasonField');
    const receiverInput = receiverFields.querySelector('input[name="receiver_name"]');
    const receivedDateInput = receiverFields.querySelector('input[name="received_date"]');
    const reasonInput = voidReasonField.querySelector('input[name="void_reason"]');
    receiverFields.classList.toggle('hidden', status !== 'received');
    voidReasonField.classList.toggle('hidden', !['cancelled', 'void'].includes(status));
    receiverInput.required = status === 'received';
    receivedDateInput.required = status === 'received';
    reasonInput.required = ['cancelled', 'void'].includes(status);
}

function openUpdateModal(id, no, currentStatus, preferredStatus = '') {
    document.getElementById('updateChequeId').value = id;
    document.getElementById('updateChequeNo').textContent = no;
    const statusSelect = document.getElementById('updateNewStatus');
    const labels = {signed: 'Signed', released: 'Released', received: 'Received by Supplier', cancelled: 'Cancelled', void: 'Void'};
    statusSelect.innerHTML = '';
    (chequeTransitions[currentStatus] || []).forEach(status => {
        const option = document.createElement('option');
        option.value = status;
        option.textContent = labels[status] || status;
        statusSelect.appendChild(option);
    });
    statusSelect.value = preferredStatus && (chequeTransitions[currentStatus] || []).includes(preferredStatus)
        ? preferredStatus
        : (chequeTransitions[currentStatus] || [])[0] || '';
    syncUpdateFields();
    document.getElementById('updateModal').classList.remove('hidden');
}

document.getElementById('updateNewStatus').addEventListener('change', syncUpdateFields);
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
