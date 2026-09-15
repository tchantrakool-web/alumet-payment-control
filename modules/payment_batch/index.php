<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('payment_batch')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Payment Batch';
$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !canEdit('payment_batch')) {
    flash('error', 'Payment Batch is read-only for your role.');
    redirect(BASE_URL . '/modules/payment_batch/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    flash('error', 'Your session token expired. Please try again.');
    redirect(BASE_URL . '/modules/payment_batch/');
}

// Handle create batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_batch') {
    $prIds    = $_POST['pr_ids'] ?? [];
    $batchDate = trim($_POST['batch_date'] ?? date('Y-m-d'));
    $payType  = trim($_POST['payment_type'] ?? 'cheque');
    $note     = trim($_POST['note'] ?? '');

    if (empty($prIds)) { flash('error','กรุณาเลือก Payment Request'); redirect(BASE_URL . '/modules/payment_batch/'); }

    $phs  = implode(',', array_fill(0, count($prIds), '?'));
    $prs  = $db->prepare("
        SELECT *
        FROM payment_requests
        WHERE id IN ($phs)
          AND status='Approved for Payment'
          AND is_deleted=0
          AND id NOT IN (
              SELECT payment_request_id
              FROM payment_batch_items
          )
    ");
    $prs->execute($prIds);
    $prs  = $prs->fetchAll();

    if (empty($prs)) { flash('error','No payment request is approved for payment'); redirect(BASE_URL . '/modules/payment_batch/'); }
    if (count($prs) !== count($prIds)) {
        flash('error', 'One or more selected payment requests are already assigned to another batch');
        redirect(BASE_URL . '/modules/payment_batch/');
    }

    $totalAmt = array_sum(array_column($prs, 'net_payable'));
    $batchNo  = generateNo('PB', 'payment_batches', 'batch_no');

    $db->prepare("INSERT INTO payment_batches (batch_no, batch_date, payment_type, total_amount, status, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
       ->execute([$batchNo, $batchDate, $payType, $totalAmt, 'draft', $note, $user['id']]);
    $batchId = $db->lastInsertId();

    foreach ($prs as $pr) {
        $db->prepare("INSERT INTO payment_batch_items (payment_batch_id, payment_request_id, amount, created_at) VALUES (?,?,?,CURRENT_TIMESTAMP)")
           ->execute([$batchId, $pr['id'], $pr['net_payable']]);
        $db->prepare("UPDATE payment_requests SET status='Approved for Payment', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$pr['id']]);
    }

    auditLog('CREATE_BATCH', 'payment_batch', $batchId, '', "total=$totalAmt count=" . count($prs));
    flash('success', "Payment Batch {$batchNo} สร้างสำเร็จ — ฿" . fmtMoney($totalAmt));
    redirect(BASE_URL . '/modules/payment_batch/?id=' . $batchId);
}

// Lock batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lock_batch') {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $db->prepare("UPDATE payment_batches SET is_locked=1, status='locked', locked_by=?, locked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=? AND is_locked=0")
       ->execute([$user['id'], $batchId]);
    auditLog('LOCK_BATCH', 'payment_batch', $batchId);
    flash('success', 'Batch ถูก Lock แล้ว ไม่สามารถแก้ไขได้');
    redirect(BASE_URL . '/modules/payment_batch/?id=' . $batchId);
}

$viewId = (int)($_GET['id'] ?? 0);
$viewBatch = null;
$batchItems = [];
if ($viewId) {
    $stmtB = $db->prepare("SELECT b.*, u.full_name as creator_name, l.full_name as locker_name FROM payment_batches b LEFT JOIN users u ON u.id=b.created_by LEFT JOIN users l ON l.id=b.locked_by WHERE b.id=?");
    $stmtB->execute([$viewId]);
    $viewBatch = $stmtB->fetch();

    $stmtI = $db->prepare("SELECT bi.*, pr.request_no, pr.vendor_name, pr.due_date, pr.status as pr_status FROM payment_batch_items bi JOIN payment_requests pr ON pr.id=bi.payment_request_id WHERE bi.payment_batch_id=?");
    $stmtI->execute([$viewId]);
    $batchItems = $stmtI->fetchAll();
}

$batches = $db->query("SELECT b.*, u.full_name as creator_name FROM payment_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 50")->fetchAll();

// Available PRs for new batch
$readyPRs = $db->query("
    SELECT *
    FROM payment_requests
    WHERE status='Approved for Payment'
      AND is_deleted=0
      AND id NOT IN (
          SELECT payment_request_id
          FROM payment_batch_items
      )
    ORDER BY due_date ASC
")->fetchAll();

include ROOT_PATH . '/layouts/header.php';
?>

<div x-data="{ showForm: false }">

<div class="mb-5">
  <h1 class="text-2xl font-bold text-gray-800"><?= t('batch.title') ?></h1>
  <p class="text-gray-500 text-sm"><?= t('batch.subtitle') ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

  <!-- Batch List -->
  <div>
    <div class="bg-white rounded-xl border overflow-hidden">
      <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
        <h3 class="font-semibold text-gray-700 text-sm"><?= t('batch.batches') ?></h3>
        <?php if (canEdit('payment_batch')): ?><button type="button" @click="showForm = true"
                class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700"><?= t('batch.new') ?></button>
        <?php endif; ?>
      </div>
      <div class="divide-y max-h-96 overflow-y-auto">
        <?php foreach ($batches as $b): ?>
        <a href="?id=<?= $b['id'] ?>" class="block px-4 py-3 hover:bg-gray-50 <?= $viewId == $b['id'] ? 'bg-blue-50 border-l-4 border-blue-600' : '' ?>">
          <div class="flex items-center justify-between">
            <span class="font-mono text-xs font-semibold"><?= h($b['batch_no']) ?></span>
            <?= statusBadge($b['status']) ?>
          </div>
          <div class="text-xs text-gray-500 mt-0.5">฿<?= fmtMoney($b['total_amount']) ?> | <?= fmtDate($b['batch_date']) ?></div>
          <?php if ($b['is_locked']): ?>
          <div class="text-xs text-orange-500 mt-0.5"><?= t('batch.locked') ?></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (empty($batches)): ?>
        <div class="px-4 py-6 text-center text-sm text-gray-400"><?= t('batch.no_batches') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Batch Detail -->
  <div class="lg:col-span-2">
    <?php if ($viewBatch): ?>
    <div class="bg-white rounded-xl border p-5">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="text-lg font-bold text-gray-800"><?= h($viewBatch['batch_no']) ?></h3>
          <p class="text-sm text-gray-500"><?= t('batch.created_by') ?> <?= h($viewBatch['creator_name'] ?? '') ?> | <?= fmtDateTime($viewBatch['created_at']) ?></p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-bold text-blue-700">฿<?= fmtMoney($viewBatch['total_amount']) ?></p>
          <?= statusBadge($viewBatch['status']) ?>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-3 mb-4 text-sm">
        <div><p class="text-xs text-gray-500"><?= t('batch.batch_date') ?></p><p class="font-medium"><?= fmtDate($viewBatch['batch_date']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('batch.payment_type') ?></p><p class="font-medium capitalize"><?= h($viewBatch['payment_type']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('batch.locked') ?></p>
          <p class="font-medium"><?= $viewBatch['is_locked'] ? '🔒 Yes — ' . h($viewBatch['locker_name'] ?? '') : 'No' ?></p>
        </div>
      </div>

      <?php if (!$viewBatch['is_locked'] && canEdit('payment_batch')): ?>
      <form method="POST" class="mb-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="lock_batch">
        <input type="hidden" name="batch_id" value="<?= $viewBatch['id'] ?>">
        <button class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <?= t('batch.lock_btn') ?>
        </button>
      </form>
      <?php endif; ?>

      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
          <th class="px-3 py-2"><?= t('label.request_no') ?></th>
          <th class="px-3 py-2"><?= t('label.vendor') ?></th>
          <th class="px-3 py-2"><?= t('label.due_date') ?></th>
          <th class="px-3 py-2 text-right"><?= t('label.amount') ?></th>
          <th class="px-3 py-2"><?= t('label.status') ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($batchItems as $item): ?>
          <tr class="border-b last:border-0">
            <td class="px-3 py-2 font-mono text-xs">
              <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $item['payment_request_id'] ?>" class="text-blue-600 hover:underline">
                <?= h($item['request_no']) ?>
              </a>
            </td>
            <td class="px-3 py-2"><?= h($item['vendor_name']) ?></td>
            <td class="px-3 py-2 text-xs"><?= fmtDate($item['due_date']) ?></td>
            <td class="px-3 py-2 text-right font-semibold">฿<?= fmtMoney($item['amount']) ?></td>
            <td class="px-3 py-2"><?= statusBadge($item['pr_status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="bg-gray-50 font-semibold border-t text-sm">
          <td colspan="3" class="px-3 py-2"><?= t('batch.total') ?> (<?= count($batchItems) ?> <?= t('batch.items') ?>)</td>
          <td class="px-3 py-2 text-right text-blue-700">฿<?= fmtMoney(array_sum(array_column($batchItems, 'amount'))) ?></td>
          <td></td>
        </tr></tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border p-8 text-center text-gray-400">
      <p class="text-4xl mb-2">💼</p>
      <p><?= t('batch.select_or_create') ?></p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
dialogOpen('showForm', t('batch.create_title'), 'max-w-2xl');
include __DIR__ . '/_create_form.php';
dialogClose();
?>

</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
