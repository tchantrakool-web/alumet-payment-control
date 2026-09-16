<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('cheques')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Cheque Register';
$db   = getDB();
$user = currentUser();

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
    if (!verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
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

        // Case-insensitive lookup stays a plain SELECT — resolving a vendor's
        // id by name isn't an insert-or-update decision, just an FK lookup.
        $findVendor = $db->prepare("SELECT id FROM vendors WHERE LOWER(vendor_name)=LOWER(?) LIMIT 1");
        // The existing-source-type check is a genuine business rule (don't
        // let a finance sync silently overwrite a manually-created cheque),
        // not a race-prone existence check — it stays a read. What it used
        // to gate is a hand-written INSERT-vs-UPDATE branch; that part is
        // now the single upsert() call below, relying on the UNIQUE index
        // added in database/migrations/*/001_unique_cheque_no.sql.
        $findExistingSourceType = $db->prepare("SELECT source_type FROM cheques WHERE LOWER(cheque_no)=LOWER(?) LIMIT 1");

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

                $findExistingSourceType->execute([$group['cheque_no']]);
                $existingSourceType = $findExistingSourceType->fetchColumn();
                $isNew = $existingSourceType === false;
                if (!$isNew && $existingSourceType !== 'finance_import') {
                    $conflicts++;
                    continue;
                }

                upsert($db, 'cheques', [
                    'cheque_no' => $group['cheque_no'],
                    'cheque_date' => $group['cheque_date'] ?: null,
                    'bank' => $group['cheque_bank'] ?: null,
                    'amount' => $group['amount'],
                    'payee_name' => $payeeName,
                    'vendor_id' => $vendorId,
                    'status' => $status,
                    'receiver_name' => $receiverName,
                    'received_date' => $receivedDate,
                    'created_by' => $user['id'],
                    'source_type' => 'finance_import',
                    'source_id' => $batchId,
                    'created_at' => sqlNow(),
                    'updated_at' => sqlNow(),
                ], ['cheque_no'], ['created_by', 'source_type', 'created_at']);

                $isNew ? $created++ : $updated++;
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

        $stmtDuplicate = $db->prepare("SELECT id FROM cheques WHERE LOWER(cheque_no) = LOWER(?) LIMIT 1");
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

        $db->prepare("INSERT INTO cheques (cheque_no, cheque_date, bank, amount, payee_name, payment_request_id, vendor_id, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
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
            'prepared' => ['released', 'received', 'cancelled', 'void'],
            'signed' => ['released', 'received', 'cancelled', 'void'],
            'released' => ['released', 'received', 'cancelled', 'void'],
            'received' => [],
            'cancelled' => [],
            'void' => [],
        ];
        if (!in_array($newStatus, $allowedTransitions[$cheque['status']] ?? [], true)) {
            flash('error', "Cheque status cannot change from {$cheque['status']} to {$newStatus}.");
            redirect(BASE_URL . '/modules/cheques/');
        }
        if (!canEdit('cheques') && !($cheque['status'] === 'prepared' && $newStatus === 'released')) {
            flash('error', 'Your role can only move cheques from Prepared to Bank Transfer.');
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

        $db->prepare("UPDATE cheques SET status=?, receiver_name=?, received_date=?, void_reason=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
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

// Show cheque-related PR numbers in the dropdown, while only allowing an
// approved request without an active cheque to be selected.
$chequePRs = $db->query("
    SELECT pr.*,
           EXISTS (
               SELECT 1
               FROM cheques c
               WHERE c.payment_request_id = pr.id
                 AND c.status NOT IN ('void', 'cancelled')
           ) AS has_active_cheque
    FROM payment_requests pr
    WHERE pr.is_deleted = 0
      AND pr.payment_method = 'cheque'
      AND pr.status NOT IN ('Paid', 'Rejected', 'Cancelled')
    ORDER BY pr.due_date, pr.id DESC
")->fetchAll();
$eligiblePRs = array_values(array_filter($chequePRs, static fn(array $pr): bool =>
    $pr['status'] === 'Approved for Payment' && (int) $pr['has_active_cheque'] === 0
));
$unavailablePRs = array_values(array_filter($chequePRs, static fn(array $pr): bool =>
    $pr['status'] !== 'Approved for Payment' || (int) $pr['has_active_cheque'] === 1
));

$chequeStatuses = ['prepared','signed','released','received','cancelled','void'];
$chequeStatusLabels = [
    'prepared' => 'Prepared',
    'signed' => 'Signed (Legacy)',
    'released' => 'Bank Transfer',
    'received' => 'Receive by Supplier',
    'cancelled' => 'Cancelled',
    'void' => 'Void',
];
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

$prSupplierMap = [];
foreach ($eligiblePRs as $pr) {
    $prSupplierMap[(int)$pr['id']] = $pr['vendor_name'];
}
$chequeAllowedTransitions = [
    'prepared' => ['released', 'received', 'cancelled', 'void'],
    'signed' => ['released', 'received', 'cancelled', 'void'],
    'released' => ['released', 'received', 'cancelled', 'void'],
];

include ROOT_PATH . '/layouts/header.php';
?>

<div x-data='{
  showForm: false,
  open: {},
  newCheque: { payment_request_id: "", cheque_no: "", cheque_date: "<?= date('Y-m-d') ?>", bank: "", payee_name: "" },
  prSupplierMap: <?= json_encode($prSupplierMap, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  closeAll() { this.showForm = false; this.open = {}; },
  openCreate() {
    this.closeAll();
    this.newCheque = { payment_request_id: "", cheque_no: "", cheque_date: "<?= date('Y-m-d') ?>", bank: "", payee_name: "" };
    this.showForm = true;
  }
}'>

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
      <?= csrfField() ?>
      <input type="hidden" name="action" value="sync_finance">
      <input type="hidden" name="finance_batch_id" value="<?= (int)$latestFinanceBatch['id'] ?>">
      <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
              onclick="return confirm('Synchronize <?= $financeChequeCount ?> Finance cheques into the register?')">
        Sync Finance Cheques (<?= number_format($financeChequeCount) ?>)
      </button>
    </form>
    <?php endif; ?>
    <?php if (canEdit('cheques')): ?><button type="button" @click="openCreate()"
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
        <?php foreach ($chequeStatuses as $status): ?><option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h($chequeStatusLabels[$status] ?? ucfirst($status)) ?></option><?php endforeach; ?>
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
  <a href="?<?= h(http_build_query(array_merge($preservedFilters, ['status' => $s]))) ?>" class="px-3 py-1.5 rounded-lg border text-sm <?= $filterStatus === $s ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 hover:bg-gray-50' ?>"><?= h($chequeStatusLabels[$s] ?? ucfirst($s)) ?></a>
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
            <?php if (canEdit('cheques') && !in_array($c['status'], ['received','void','cancelled'], true)): ?>
              <button type="button" @click="closeAll(); open[<?= (int)$c['id'] ?>] = true"
                      class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                Edit
              </button>
            <?php elseif ($c['status'] === 'prepared' && hasRole('maker')): ?>
              <form method="POST" class="min-w-28">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="cheque_id" value="<?= (int)$c['id'] ?>">
                <select name="new_status" onchange="if(this.value !== 'prepared' && confirm('Move cheque <?= h((string)$c['cheque_no']) ?> to Bank Transfer?')) this.form.submit(); else this.value='prepared';"
                        class="w-full rounded-lg border border-blue-200 bg-blue-50 px-2 py-1.5 text-xs font-medium text-blue-700 outline-none focus:ring-2 focus:ring-blue-300">
                  <option value="prepared" selected>Prepared</option>
                  <option value="released">Bank Transfer</option>
                </select>
              </form>
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

<?php
dialogOpen('showForm', t('cheque.create_title'));
include __DIR__ . '/_create_form.php';
dialogClose();

// One dialog per cheque, rendered here (after the table, never inside it —
// a <div> is not valid table content and the browser would hoist it out,
// away from this x-data scope). Each dialog is bound to its own row's data,
// so there is no shared "which cheque" state to get wrong.
foreach ($cheques as $c) {
    if (!(canEdit('cheques') && !in_array($c['status'], ['received', 'void', 'cancelled'], true))) {
        continue;
    }
    $allowedStatuses = $chequeAllowedTransitions[$c['status']] ?? [];
    if (empty($allowedStatuses)) {
        continue;
    }
    $chequeId = (int)$c['id'];
    dialogOpen("open[{$chequeId}]", t('cheque.update_title') . ': ' . $c['cheque_no'], 'max-w-md', null, "open[{$chequeId}] = false");
    include __DIR__ . '/_status_form.php';
    dialogClose();
}
?>

</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
