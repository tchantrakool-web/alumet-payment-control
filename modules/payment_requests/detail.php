<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('payment_requests')) { flash('error','Access denied'); redirect(BASE_URL . '/dashboard.php'); }
$pageTitle = 'Payment Request Detail';
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$user = currentUser();

$pr = $db->prepare("SELECT pr.*, u.full_name as creator_name, c.full_name as checker_name
                    FROM payment_requests pr
                    LEFT JOIN users u ON u.id = pr.created_by
                    LEFT JOIN users c ON c.id = pr.checked_by
                    WHERE pr.id = ? AND pr.is_deleted = 0");
$pr->execute([$id]);
$pr = $pr->fetch();
if (!$pr) { flash('error','ไม่พบ Payment Request'); redirect(BASE_URL . '/modules/payment_requests/'); }

$stmtItems = $db->prepare("SELECT i.*, s.ap_invoice_doc_num as sap_inv_no, s.grpo_doc_num, s.po_doc_num FROM payment_request_items i LEFT JOIN sap_ap_invoices s ON s.id=i.sap_invoice_id WHERE i.payment_request_id=?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

$history = $db->prepare("SELECT h.*, u.full_name FROM approval_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.payment_request_id=? ORDER BY h.id DESC");
$history->execute([$id]);
$history = $history->fetchAll();

$attachments = $db->prepare("SELECT a.*, u.full_name as uploader FROM attachments a LEFT JOIN users u ON u.id=a.uploaded_by WHERE a.related_type='payment_request' AND a.related_id=? AND a.is_deleted=0");
$attachments->execute([$id]);
$attachments = $attachments->fetchAll();

$approvalTasks = $db->prepare("SELECT at.*, u.full_name FROM approval_tasks at LEFT JOIN users u ON u.id=at.approver_id WHERE at.payment_request_id=? ORDER BY at.sequence");
$approvalTasks->execute([$id]);
$approvalTasks = $approvalTasks->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $oldStatus = $pr['status'];

    $allowed = false;
    $newStatus = $oldStatus;

    if ($action === 'check' && hasRole('admin','checker','finance_manager') && $oldStatus === 'Waiting Check') {
        $newStatus = 'Waiting Approval';
        $allowed = true;
        $db->prepare("UPDATE payment_requests SET status=?, checked_by=?, checked_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$newStatus, $user['id'], $id]);
        createApprovalTasks($db, $id, $pr['net_payable']);
    } elseif ($action === 'approve' && hasRole('admin','approver','finance_manager','executive') && $oldStatus === 'Waiting Approval') {
        $newStatus = 'Approved';
        $allowed = true;
        $db->prepare("UPDATE payment_requests SET status=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$newStatus, $id]);
        $db->prepare("UPDATE approval_tasks SET status='approved', action='approve', comment=?, actioned_at=datetime('now','localtime') WHERE payment_request_id=? AND approver_id=? AND status='pending'")
           ->execute([$comment, $id, $user['id']]);
    } elseif ($action === 'reject' && in_array($oldStatus, ['Waiting Check','Waiting Approval']) && !hasRole('maker')) {
        $newStatus = 'Rejected';
        $allowed = true;
        $db->prepare("UPDATE payment_requests SET status=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$newStatus, $id]);
    } elseif ($action === 'ready_to_pay' && hasRole('admin','finance_manager') && $oldStatus === 'Approved') {
        $newStatus = 'Ready to Pay';
        $allowed = true;
        $db->prepare("UPDATE payment_requests SET status=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$newStatus, $id]);
    } elseif ($action === 'mark_paid' && hasRole('admin','finance_manager') && $oldStatus === 'Ready to Pay') {
        $newStatus = 'Paid';
        $allowed = true;
        $db->prepare("UPDATE payment_requests SET status=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$newStatus, $id]);
        foreach ($items as $item) {
            if ($item['sap_invoice_id']) {
                $db->prepare("UPDATE sap_ap_invoices SET payment_status='Paid', updated_at=datetime('now','localtime') WHERE id=?")->execute([$item['sap_invoice_id']]);
            }
        }
    }

    if ($allowed) {
        $db->prepare("INSERT INTO approval_history (payment_request_id, user_id, action, comment, old_status, new_status) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $user['id'], strtoupper($action), $comment, $oldStatus, $newStatus]);
        auditLog(strtoupper($action), 'payment_requests', $id, $oldStatus, $newStatus);
        flash('success', "Action '{$action}' completed. Status: {$newStatus}");
    }
    redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
}

function createApprovalTasks(PDO $db, int $prId, float $amount): void {
    $db->prepare("DELETE FROM approval_tasks WHERE payment_request_id=?")->execute([$prId]);
    $matrix = $db->prepare("SELECT * FROM approval_matrix WHERE is_active=1 AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?) ORDER BY sequence");
    $matrix->execute([$amount, $amount]);
    foreach ($matrix->fetchAll() as $rule) {
        $u = $db->prepare("SELECT id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=? AND u.is_active=1 LIMIT 1");
        $u->execute([$rule['approver_role']]);
        $approverId = $u->fetchColumn();
        if ($approverId) {
            $db->prepare("INSERT INTO approval_tasks (payment_request_id, approver_id, sequence) VALUES (?,?,?)")
               ->execute([$prId, $approverId, $rule['sequence']]);
        }
    }
}

$today = date('Y-m-d');
$isOverdue = $pr['due_date'] && $pr['due_date'] < $today && !in_array($pr['status'], ['Paid','Rejected','Cancelled'], true);
$invoiceRefs = array_values(array_filter(array_map(static fn($item) => $item['ap_invoice_no'] ?: ($item['sap_inv_no'] ?? ''), $items)));
$outstandingBalance = in_array($pr['status'], ['Paid','Rejected','Cancelled'], true)
    ? 0
    : array_sum(array_map(static fn($item) => (float) ($item['net_amount'] ?? 0), $items));

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center gap-3">
  <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-sm text-gray-500 hover:text-gray-700">← Payment Requests</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="lg:col-span-2 space-y-4">
    <div class="bg-white rounded-xl border p-5">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-lg font-bold text-gray-800"><?= h($pr['request_no']) ?></h2>
            <?= statusBadge($pr['status']) ?>
            <?php if ($isOverdue): ?>
            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded">Overdue</span>
            <?php endif; ?>
            <?php if ($pr['priority'] === 'urgent'): ?>
            <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded">Urgent</span>
            <?php endif; ?>
          </div>
          <p class="text-gray-600 text-sm mt-0.5"><?= h($pr['vendor_name']) ?> <?= $pr['vendor_code'] ? '(' . h($pr['vendor_code']) . ')' : '' ?></p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-bold" style="color:#003B5C;">฿<?= fmtMoney($pr['net_payable']) ?></p>
          <p class="text-xs text-gray-400">Net Payable</p>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><p class="text-xs text-gray-500">Total Amount</p><p class="font-medium">฿<?= fmtMoney($pr['total_amount']) ?></p></div>
        <div><p class="text-xs text-gray-500">WHT</p><p class="font-medium">฿<?= fmtMoney($pr['wht_amount']) ?></p></div>
        <div><p class="text-xs text-gray-500">Outstanding Balance</p><p class="font-medium <?= $outstandingBalance > 0 ? 'text-orange-600' : 'text-gray-400' ?>">฿<?= fmtMoney($outstandingBalance) ?></p></div>
        <div><p class="text-xs text-gray-500">Invoice Ref.</p><p class="font-medium text-xs"><?= h(!empty($invoiceRefs) ? implode(', ', $invoiceRefs) : '-') ?></p></div>
        <div><p class="text-xs text-gray-500">Due Date</p><p class="font-medium <?= $isOverdue ? 'text-red-600' : '' ?>"><?= fmtDate($pr['due_date']) ?></p></div>
        <div><p class="text-xs text-gray-500">Payment Method</p><p class="font-medium capitalize"><?= h($pr['payment_method']) ?></p></div>
        <div><p class="text-xs text-gray-500">Created By</p><p class="font-medium"><?= h($pr['creator_name'] ?? '') ?></p></div>
        <div><p class="text-xs text-gray-500">Created At</p><p class="font-medium"><?= fmtDateTime($pr['created_at']) ?></p></div>
        <?php if ($pr['checked_by']): ?>
        <div><p class="text-xs text-gray-500">Checked By</p><p class="font-medium"><?= h($pr['checker_name']) ?></p></div>
        <div><p class="text-xs text-gray-500">Checked At</p><p class="font-medium"><?= fmtDateTime($pr['checked_at']) ?></p></div>
        <?php endif; ?>
      </div>

      <?php if ($pr['note']): ?>
      <div class="mt-3 p-3 bg-gray-50 rounded-lg text-sm text-gray-600">
        <span class="font-medium">Note: </span><?= h($pr['note']) ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold text-gray-700 mb-3">AP Invoice Items (<?= count($items) ?>)</h3>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
          <th class="px-3 py-2">Invoice No.</th>
          <th class="px-3 py-2">Reference</th>
          <th class="px-3 py-2">PO / GRPO</th>
          <th class="px-3 py-2">Invoice Date</th>
          <th class="px-3 py-2 text-right">Amount</th>
          <th class="px-3 py-2 text-right">Outstanding</th>
        </tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr class="border-b last:border-0">
            <td class="px-3 py-2 font-mono text-xs"><?= h($item['ap_invoice_no'] ?? $item['sap_inv_no'] ?? '') ?></td>
            <td class="px-3 py-2 text-xs text-gray-500"><?= h($item['sap_inv_no'] ?? $item['ap_invoice_no'] ?? '-') ?></td>
            <td class="px-3 py-2 text-xs text-gray-500"><?= h($item['po_doc_num'] ?? '-') ?> / <?= h($item['grpo_doc_num'] ?? '-') ?></td>
            <td class="px-3 py-2 text-xs"><?= fmtDate($item['invoice_date']) ?></td>
            <td class="px-3 py-2 text-right"><?= fmtMoney($item['invoice_amount']) ?></td>
            <td class="px-3 py-2 text-right font-semibold <?= $pr['status'] === 'Paid' ? 'text-gray-400' : 'text-orange-600' ?>">
              <?= fmtMoney($pr['status'] === 'Paid' ? 0 : (float) $item['net_amount']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="bg-gray-50 font-semibold text-sm border-t">
          <td colspan="4" class="px-3 py-2">Total</td>
          <td class="px-3 py-2 text-right"><?= fmtMoney(array_sum(array_column($items, 'invoice_amount'))) ?></td>
          <td class="px-3 py-2 text-right text-orange-600"><?= fmtMoney($outstandingBalance) ?></td>
        </tr></tfoot>
      </table>
    </div>

    <div class="bg-white rounded-xl border p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-700">Documents (<?= count($attachments) ?>)</h3>
        <form method="POST" action="<?= BASE_URL ?>/api/attachments.php" enctype="multipart/form-data" class="flex items-center gap-2">
          <input type="hidden" name="related_type" value="payment_request">
          <input type="hidden" name="related_id" value="<?= $id ?>">
          <input type="file" name="attachment" class="text-xs border border-gray-300 rounded px-2 py-1">
          <select name="doc_type" class="text-xs border border-gray-300 rounded px-2 py-1">
            <option value="Invoice">Invoice</option>
            <option value="PO">PO</option>
            <option value="GRPO">GRPO</option>
            <option value="Tax Invoice">Tax Invoice</option>
            <option value="Other">Other</option>
          </select>
          <button type="submit" class="theme-btn-primary text-xs px-3 py-1 rounded">Upload</button>
        </form>
      </div>
      <?php if (empty($attachments)): ?>
        <p class="text-sm text-gray-400 text-center py-3">No attachment yet</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($attachments as $att): ?>
          <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg text-sm">
            <div class="flex items-center gap-2">
              <span class="text-gray-400">File</span>
              <div>
                <p class="font-medium"><?= h($att['original_filename']) ?></p>
                <p class="text-xs text-gray-400"><?= h($att['document_type']) ?> | <?= h($att['uploader'] ?? '') ?> | <?= fmtDateTime($att['created_at']) ?></p>
              </div>
            </div>
            <a href="<?= BASE_URL ?>/uploads/attachments/<?= h($att['filename']) ?>" target="_blank" class="text-xs hover:underline" style="color:#003B5C;">Download</a>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="space-y-4">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold text-gray-700 mb-3">Actions</h3>

      <?php if ($pr['status'] === 'Waiting Check' && hasRole('admin','checker','finance_manager')): ?>
      <form method="POST" class="space-y-2">
        <input type="hidden" name="action" value="check">
        <textarea name="comment" rows="2" placeholder="Comment (optional)..." class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
        <button class="theme-btn-secondary w-full py-2 rounded-lg text-sm font-medium">Pass to Approver</button>
      </form>
      <form method="POST" class="mt-2">
        <input type="hidden" name="action" value="reject">
        <textarea name="comment" rows="2" placeholder="Reason for rejection..." required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-red-400 mb-2"></textarea>
        <button class="w-full bg-red-500 hover:bg-red-600 text-white py-2 rounded-lg text-sm font-medium">Reject</button>
      </form>
      <?php elseif ($pr['status'] === 'Waiting Approval' && hasRole('admin','approver','finance_manager','executive')): ?>
      <form method="POST" class="space-y-2">
        <input type="hidden" name="action" value="approve">
        <textarea name="comment" rows="2" placeholder="Approval comment (optional)..." class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
        <button class="theme-btn-primary w-full py-2 rounded-lg text-sm font-medium">Approve</button>
      </form>
      <form method="POST" class="mt-2">
        <input type="hidden" name="action" value="reject">
        <textarea name="comment" rows="2" placeholder="Reason for rejection..." required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-red-400 mb-2"></textarea>
        <button class="w-full bg-red-500 hover:bg-red-600 text-white py-2 rounded-lg text-sm font-medium">Reject</button>
      </form>
      <?php elseif ($pr['status'] === 'Approved' && hasRole('admin','finance_manager')): ?>
      <form method="POST">
        <input type="hidden" name="action" value="ready_to_pay">
        <button class="theme-btn-secondary w-full py-2 rounded-lg text-sm font-medium">Mark Ready to Pay</button>
      </form>
      <?php elseif ($pr['status'] === 'Ready to Pay' && hasRole('admin','finance_manager')): ?>
      <form method="POST">
        <input type="hidden" name="action" value="mark_paid">
        <button class="theme-btn-primary w-full py-2 rounded-lg text-sm font-medium">Mark as Paid</button>
      </form>
      <?php else: ?>
      <p class="text-sm text-gray-400 text-center py-2">No actions available for current status</p>
      <?php endif; ?>
    </div>

    <?php if (!empty($approvalTasks)): ?>
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold text-gray-700 mb-3">Approval Pipeline</h3>
      <div class="space-y-2">
        <?php foreach ($approvalTasks as $task): ?>
        <div class="flex items-center gap-2 p-2 rounded-lg <?= $task['status'] === 'approved' ? 'bg-green-50' : ($task['status'] === 'pending' ? 'bg-yellow-50' : 'bg-gray-50') ?>">
          <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs <?= $task['status'] === 'approved' ? 'bg-green-500 text-white' : ($task['status'] === 'pending' ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-gray-600') ?>">
            <?= $task['sequence'] ?>
          </div>
          <div class="flex-1 text-sm">
            <p class="font-medium"><?= h($task['full_name'] ?? 'Pending assignment') ?></p>
            <?php if ($task['comment']): ?><p class="text-xs text-gray-500"><?= h($task['comment']) ?></p><?php endif; ?>
          </div>
          <?= statusBadge($task['status']) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold text-gray-700 mb-3">History</h3>
      <?php if (empty($history)): ?>
      <p class="text-sm text-gray-400">No history yet</p>
      <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($history as $h): ?>
        <div class="flex gap-3">
          <div class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" style="background:#006B3F;"></div>
          <div>
            <p class="text-sm font-medium"><?= h($h['full_name'] ?? '') ?> - <span class="font-mono text-xs"><?= h($h['action']) ?></span></p>
            <?php if ($h['comment']): ?><p class="text-xs text-gray-500 mt-0.5">"<?= h($h['comment']) ?>"</p><?php endif; ?>
            <p class="text-xs text-gray-400 mt-0.5"><?= fmtDateTime($h['created_at']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
