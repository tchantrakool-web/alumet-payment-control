<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('cheques')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Cheque Register';
$db   = getDB();
$user = currentUser();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_cheque') {
        $prId      = (int)($_POST['payment_request_id'] ?? 0);
        $chequeNo  = trim($_POST['cheque_no'] ?? '');
        $chequeDate= trim($_POST['cheque_date'] ?? '');
        $bank      = trim($_POST['bank'] ?? '');
        $payee     = trim($_POST['payee_name'] ?? '');

        $pr = $db->prepare("SELECT * FROM payment_requests WHERE id=? AND is_deleted=0")->execute([$prId])
              ? null : null;
        $stmtPR = $db->prepare("SELECT * FROM payment_requests WHERE id=? AND is_deleted=0");
        $stmtPR->execute([$prId]);
        $pr = $stmtPR->fetch();

        if (!$pr) { flash('error','Payment Request not found'); redirect(BASE_URL . '/modules/cheques/'); }
        if (!in_array($pr['status'], ['Approved','Ready to Pay'])) {
            flash('error', 'Cheque สามารถสร้างได้เฉพาะรายการที่ Approved แล้วเท่านั้น');
            redirect(BASE_URL . '/modules/cheques/');
        }

        $db->prepare("INSERT INTO cheques (cheque_no, cheque_date, bank, amount, payee_name, payment_request_id, vendor_id, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$chequeNo, $chequeDate, $bank, $pr['net_payable'], $payee ?: $pr['vendor_name'], $prId, $pr['vendor_id'], 'prepared', $user['id']]);
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

        $db->prepare("UPDATE cheques SET status=?, receiver_name=?, received_date=?, void_reason=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$newStatus, $receiver ?: null, $recvDate ?: null, $voidReason ?: null, $chequeId]);

        auditLog('UPDATE_CHEQUE_STATUS', 'cheques', $chequeId, $cheque['status'], $newStatus);
        flash('success', "เช็ค #" . h($cheque['cheque_no']) . " อัปเดตสถานะเป็น {$newStatus}");
    }

    redirect(BASE_URL . '/modules/cheques/');
}

$filterStatus = trim($_GET['status'] ?? '');
$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'c.status=?'; $params[] = $filterStatus; }

$cheques = $db->prepare("SELECT c.*, pr.request_no, pr.vendor_name, u.full_name as creator_name
                          FROM cheques c
                          LEFT JOIN payment_requests pr ON pr.id = c.payment_request_id
                          LEFT JOIN users u ON u.id = c.created_by
                          WHERE " . implode(' AND ', $where) . "
                          ORDER BY c.id DESC");
$cheques->execute($params);
$cheques = $cheques->fetchAll();

// PRs eligible for cheque creation
$eligiblePRs = $db->query("SELECT * FROM payment_requests WHERE status IN ('Approved','Ready to Pay') AND is_deleted=0 ORDER BY due_date")->fetchAll();

$chequeStatuses = ['prepared','signed','released','received','cancelled','void'];

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center justify-between">
  <div>
    <h1 class="text-2xl font-bold text-gray-800">Cheque Register</h1>
    <p class="text-gray-500 text-sm">ทะเบียนเช็คและการติดตามสถานะ</p>
  </div>
  <button onclick="document.getElementById('createModal').classList.remove('hidden')"
          class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    + Create Cheque
  </button>
</div>

<!-- Status Filter -->
<div class="flex flex-wrap gap-2 mb-4">
  <a href="?" class="px-3 py-1.5 rounded-lg border text-sm <?= !$filterStatus ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 hover:bg-gray-50' ?>">All</a>
  <?php foreach ($chequeStatuses as $s): ?>
  <a href="?status=<?= $s ?>" class="px-3 py-1.5 rounded-lg border text-sm <?= $filterStatus === $s ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 hover:bg-gray-50' ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<!-- Cheque Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm datatable">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide border-b">
          <th class="px-4 py-3">Cheque No.</th>
          <th class="px-4 py-3">Date</th>
          <th class="px-4 py-3">Bank</th>
          <th class="px-4 py-3">Payee</th>
          <th class="px-4 py-3 text-right">Amount</th>
          <th class="px-4 py-3">PR No.</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Receiver</th>
          <th class="px-4 py-3">Created</th>
          <th class="px-4 py-3">Actions</th>
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
            <a href="<?= BASE_URL ?>/modules/payment_requests/detail.php?id=<?= $c['payment_request_id'] ?>" class="text-blue-600 hover:underline">
              <?= h($c['request_no'] ?? '') ?>
            </a>
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
            <?php if (!in_array($c['status'], ['received','void','cancelled'])): ?>
            <button onclick="openUpdateModal(<?= $c['id'] ?>, '<?= h($c['cheque_no']) ?>', '<?= $c['status'] ?>')"
                    class="text-xs text-blue-600 hover:underline">Update Status</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cheques)): ?>
        <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">ยังไม่มีข้อมูลเช็ค</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Cheque Modal -->
<div id="createModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
    <div class="flex items-center justify-between px-5 py-4 border-b">
      <h3 class="font-semibold text-gray-800">Create Cheque</h3>
      <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <input type="hidden" name="action" value="create_cheque">
      <div>
        <label class="block text-xs text-gray-500 mb-1">Payment Request</label>
        <select name="payment_request_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
          <option value="">-- เลือก PR --</option>
          <?php foreach ($eligiblePRs as $pr): ?>
          <option value="<?= $pr['id'] ?>"><?= h($pr['request_no']) ?> — <?= h($pr['vendor_name']) ?> — ฿<?= fmtMoney($pr['net_payable']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Cheque No.</label>
          <input type="text" name="cheque_no" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Cheque Date</label>
          <input type="date" name="cheque_date" required value="<?= date('Y-m-d') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Bank</label>
          <input type="text" name="bank" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="ชื่อธนาคาร...">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Payee Name</label>
          <input type="text" name="payee_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="ชื่อผู้รับเช็ค...">
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
      <h3 class="font-semibold text-gray-800">Update Cheque Status</h3>
      <button onclick="document.getElementById('updateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="cheque_id" id="updateChequeId">
      <p class="text-sm text-gray-600">Cheque: <span id="updateChequeNo" class="font-semibold"></span></p>
      <div>
        <label class="block text-xs text-gray-500 mb-1">New Status</label>
        <select name="new_status" id="updateNewStatus" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
          <option value="prepared">Prepared</option>
          <option value="signed">Signed</option>
          <option value="released">Released</option>
          <option value="received">Received by Supplier</option>
          <option value="cancelled">Cancelled</option>
          <option value="void">Void</option>
        </select>
      </div>
      <div id="receiverFields" class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Receiver Name</label>
          <input type="text" name="receiver_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="ผู้รับเช็ค...">
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Received Date</label>
          <input type="date" name="received_date" value="<?= date('Y-m-d') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
        </div>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Void Reason (if void/cancel)</label>
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
function openUpdateModal(id, no, currentStatus) {
    document.getElementById('updateChequeId').value = id;
    document.getElementById('updateChequeNo').textContent = no;
    document.getElementById('updateNewStatus').value = currentStatus;
    document.getElementById('updateModal').classList.remove('hidden');
}
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
