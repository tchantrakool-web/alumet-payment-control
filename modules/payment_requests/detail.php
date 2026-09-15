<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('payment_requests')) {
    flash('error', 'Access denied');
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'Payment Request Detail';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$user = currentUser();

function loadPaymentRequest(PDO $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT pr.*, u.full_name AS creator_name, c.full_name AS checker_name
        FROM payment_requests pr
        LEFT JOIN users u ON u.id = pr.created_by
        LEFT JOIN users c ON c.id = pr.checked_by
        WHERE pr.id = ? AND pr.is_deleted = 0
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function loadPaymentRequestItems(PDO $db, int $id): array {
    $stmt = $db->prepare("
        SELECT i.*, s.ap_invoice_doc_num AS sap_inv_no, s.grpo_doc_num, s.po_doc_num, s.ap_balance
        FROM payment_request_items i
        LEFT JOIN sap_ap_invoices s ON s.id = i.sap_invoice_id
        WHERE i.payment_request_id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function loadAttachments(PDO $db, int $id): array {
    $stmt = $db->prepare("
        SELECT a.*, u.full_name AS uploader
        FROM attachments a
        LEFT JOIN users u ON u.id = a.uploaded_by
        WHERE a.related_type = 'payment_request'
          AND a.related_id = ?
          AND a.is_deleted = 0
        ORDER BY a.id DESC
    ");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function attachmentTypeMap(array $attachments): array {
    $map = [];
    foreach ($attachments as $attachment) {
        $type = trim((string)($attachment['document_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $map[$type] = ($map[$type] ?? 0) + 1;
    }
    return $map;
}

function checklistState(array $request, array $attachmentMap): array {
    return [
        'po' => ((int)($request['has_po'] ?? 0) === 1) || !empty($attachmentMap['PO']),
        'grn' => ((int)($request['has_grn'] ?? 0) === 1) || !empty($attachmentMap['GRPO']) || !empty($attachmentMap['GRN']),
        'invoice' => ((int)($request['has_invoice'] ?? 0) === 1) || !empty($attachmentMap['Invoice']),
        'tax_invoice' => ((int)($request['has_tax_invoice'] ?? 0) === 1) || !empty($attachmentMap['Tax Invoice']),
        'payment_proof' => !empty($attachmentMap['Payment Proof']),
    ];
}

function validateChecklist(array $request, array $attachmentMap): array {
    $state = checklistState($request, $attachmentMap);
    $errors = [];
    if (!$state['po']) {
        $errors[] = 'PO is required';
    }
    if (!$state['grn']) {
        $errors[] = 'GRN / GRPO is required';
    }
    if (!$state['invoice']) {
        $errors[] = 'Vendor invoice is required';
    }
    if ((int)($request['tax_invoice_required'] ?? 1) === 1 && !$state['tax_invoice']) {
        $errors[] = 'Tax invoice is required';
    }
    if ((int)($request['wht_applicable'] ?? 0) === 1) {
        if ((float)($request['wht_base_amount'] ?? 0) <= 0) {
            $errors[] = 'WHT base amount is required';
        }
        if ((float)($request['wht_amount'] ?? 0) <= 0) {
            $errors[] = 'WHT amount is required';
        }
    }
    return [$state, $errors];
}

function updateRequestItemAmounts(PDO $db, int $paymentRequestId, float $grossAmount, float $whtAmount, int $whtApplicable): void {
    $items = loadPaymentRequestItems($db, $paymentRequestId);
    if (empty($items)) {
        return;
    }

    $baseTotal = 0.0;
    foreach ($items as $item) {
        $baseTotal += (float)($item['ap_balance'] ?? 0);
    }
    if ($baseTotal <= 0) {
        $baseTotal = $grossAmount;
    }

    $stmt = $db->prepare("UPDATE payment_request_items SET wht_amount = ?, net_amount = ? WHERE id = ?");
    $remainingWht = $whtApplicable ? $whtAmount : 0.0;
    $count = count($items);

    foreach ($items as $index => $item) {
        $itemBase = (float)($item['ap_balance'] ?? 0);
        if ($itemBase <= 0) {
            $itemBase = (float)($item['net_amount'] ?? 0) + (float)($item['wht_amount'] ?? 0);
        }

        $itemWht = 0.0;
        if ($whtApplicable && $baseTotal > 0) {
            if ($index === $count - 1) {
                $itemWht = round($remainingWht, 2);
            } else {
                $itemWht = round(($whtAmount * $itemBase) / $baseTotal, 2);
                $remainingWht -= $itemWht;
            }
        }
        $itemNet = max(0, round($itemBase - $itemWht, 2));
        $stmt->execute([$itemWht, $itemNet, $item['id']]);
    }
}

function addHistory(PDO $db, int $paymentRequestId, int $userId, string $action, string $comment, string $oldStatus, string $newStatus): void {
    $db->prepare("
        INSERT INTO approval_history (payment_request_id, user_id, action, comment, old_status, new_status)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$paymentRequestId, $userId, $action, $comment, $oldStatus, $newStatus]);
}

function sendCorrectionEmail(PDO $db, array $request, string $correctionDetails, array $checker): void {
    $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
    $stmt->execute([$request['created_by']]);
    $maker = $stmt->fetch();
    if (!$maker) return;

    $subject = "[Alumet] Payment Request {$request['request_no']} - Waiting for Correction";
    $body = "เรียน {$maker['full_name']},\n\n"
          . "Payment Request เลขที่ {$request['request_no']}\n"
          . "Vendor: {$request['vendor_name']}\n"
          . "จำนวนเงิน: THB " . number_format((float)$request['net_payable'], 2) . "\n\n"
          . "Checker ต้องการให้แก้ไขดังนี้:\n{$correctionDetails}\n\n"
          . "กรุณาตรวจสอบและแก้ไขเอกสารแล้วส่งใหม่อีกครั้ง\n\n"
          . "ตรวจสอบโดย: {$checker['full_name']}\n"
          . "วันที่: " . date('d/m/Y H:i');

    $db->prepare("
        INSERT INTO notification_logs (related_type, related_id, recipient_id, recipient_email, subject, body, status)
        VALUES ('payment_request', ?, ?, ?, ?, ?, 'pending')
    ")->execute([$request['id'], $maker['id'], $maker['email'] ?? '', $subject, $body]);

    if (!empty($maker['email'])) {
        $headers = "From: no-reply@alumet.co.th\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($maker['email'], $subject, $body, $headers);
        if ($sent) {
            $db->prepare("
                UPDATE notification_logs SET status = 'sent', sent_at = datetime('now','localtime')
                WHERE related_type = 'payment_request' AND related_id = ? ORDER BY id DESC LIMIT 1
            ")->execute([$request['id']]);
        }
    }
}

function createApprovalTasks(PDO $db, int $paymentRequestId, float $amount): int {
    $matrix = $db->prepare("
        SELECT *
        FROM approval_matrix
        WHERE is_active = 1
          AND min_amount <= ?
          AND (max_amount IS NULL OR max_amount >= ?)
        ORDER BY sequence
    ");
    $matrix->execute([$amount, $amount]);
    $rules = $matrix->fetchAll();
    if (empty($rules)) {
        return 0;
    }

    // Resolve the complete route before replacing existing tasks. A broken
    // rule must not leave the request with a partial approval chain.
    $tasks = [];
    foreach ($rules as $rule) {
        if (!empty($rule['approver_user_id'])) {
            $stmtUser = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $stmtUser->execute([(int) $rule['approver_user_id']]);
        } else {
            $stmtUser = $db->prepare("
                SELECT u.id
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = ? AND u.is_active = 1
                ORDER BY u.id
                LIMIT 1
            ");
            $stmtUser->execute([$rule['approver_role']]);
        }

        $approverId = (int) ($stmtUser->fetchColumn() ?: 0);
        if ($approverId === 0) {
            return 0;
        }
        $tasks[] = [$paymentRequestId, $approverId, (int) $rule['sequence']];
    }

    $db->prepare("DELETE FROM approval_tasks WHERE payment_request_id = ?")->execute([$paymentRequestId]);
    $insertTask = $db->prepare("
        INSERT INTO approval_tasks (payment_request_id, approver_id, sequence)
        VALUES (?, ?, ?)
    ");
    foreach ($tasks as $task) {
        $insertTask->execute($task);
    }

    return count($tasks);
}

$request = loadPaymentRequest($db, $id);
if (!$request) {
    flash('error', 'Payment Request not found');
    redirect(BASE_URL . '/modules/payment_requests/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        flash('error', 'Your session token expired. Please try again.');
        redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
    }
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $oldStatus = (string)$request['status'];
    $newStatus = $oldStatus;
    $statusChanged = false;

    if ($action === 'save_review' && hasRole('admin', 'maker', 'finance_manager') && !in_array($oldStatus, ['Paid', 'Rejected', 'Cancelled'], true)) {
        $whtApplicable = !empty($_POST['wht_applicable']) ? 1 : 0;
        $whtRate = max(0, (float)($_POST['wht_rate'] ?? 0));
        $whtBaseAmount = max(0, (float)($_POST['wht_base_amount'] ?? 0));
        $grossAmount = max(0, (float)($_POST['gross_amount'] ?? 0));
        $taxInvoiceRequired = !empty($_POST['tax_invoice_required']) ? 1 : 0;
        $hasPo = !empty($_POST['has_po']) ? 1 : 0;
        $hasGrn = !empty($_POST['has_grn']) ? 1 : 0;
        $hasInvoice = !empty($_POST['has_invoice']) ? 1 : 0;
        $hasTaxInvoice = !empty($_POST['has_tax_invoice']) ? 1 : 0;
        $note = trim($_POST['note'] ?? (string)$request['note']);

        if ($whtApplicable && !in_array($whtRate, [1.0, 3.0, 5.0], true)) {
            flash('error', 'Please select a valid WHT rate (1%, 3%, or 5%)');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }

        $whtAmount = $whtApplicable ? round(($whtBaseAmount * $whtRate) / 100, 2) : 0.0;
        if (!$whtApplicable) {
            $whtRate = 0;
            $whtBaseAmount = 0;
            $whtAmount = 0;
        }
        if ($whtApplicable && $whtAmount <= 0) {
            flash('error', 'Please provide WHT rate or WHT amount');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }

        $netPayable = round($grossAmount - $whtAmount, 2);
        if ($netPayable < 0) {
            flash('error', 'Net payable cannot be negative');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }

        $db->prepare("
            UPDATE payment_requests
            SET gross_amount = ?, total_amount = ?, wht_applicable = ?, wht_rate = ?, wht_base_amount = ?, wht_amount = ?, net_payable = ?,
                tax_invoice_required = ?, has_po = ?, has_grn = ?, has_invoice = ?, has_tax_invoice = ?, note = ?, updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([
            $grossAmount,
            $grossAmount,
            $whtApplicable,
            $whtRate,
            $whtBaseAmount,
            $whtAmount,
            $netPayable,
            $taxInvoiceRequired,
            $hasPo,
            $hasGrn,
            $hasInvoice,
            $hasTaxInvoice,
            $note,
            $id,
        ]);
        updateRequestItemAmounts($db, $id, $grossAmount, $whtAmount, $whtApplicable);
        auditLog('SAVE_REVIEW', 'payment_requests', $id, '', "gross={$grossAmount} wht={$whtAmount} net={$netPayable}");
        flash('success', 'Review details saved');
        redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
    }

    $request = loadPaymentRequest($db, $id);
    $attachments = loadAttachments($db, $id);
    $attachmentMap = attachmentTypeMap($attachments);
    [$currentChecklist, $checklistErrors] = validateChecklist($request, $attachmentMap);

    // Compute approval state now so approve/return/reject actions below can use it
    $approvalTasksStmtPost = $db->prepare("
        SELECT at.*, u.full_name
        FROM approval_tasks at
        LEFT JOIN users u ON u.id = at.approver_id
        WHERE at.payment_request_id = ?
        ORDER BY at.sequence
    ");
    $approvalTasksStmtPost->execute([$id]);
    $activePendingApprovalTask = null;
    foreach ($approvalTasksStmtPost->fetchAll() as $_task) {
        if (($_task['status'] ?? '') !== 'pending') {
            continue;
        }
        if ($activePendingApprovalTask === null || (int)$_task['sequence'] < (int)$activePendingApprovalTask['sequence']) {
            $activePendingApprovalTask = $_task;
        }
    }
    $currentPendingApprovalTask = null;
    if ($activePendingApprovalTask !== null && (int)($activePendingApprovalTask['approver_id'] ?? 0) === (int)$user['id']) {
        $currentPendingApprovalTask = $activePendingApprovalTask;
    }
    $canApproveManagementStep = $activePendingApprovalTask !== null
        && (isAdmin() || $currentPendingApprovalTask !== null);

    if ($action === 'submit_documents' && hasRole('admin', 'maker', 'finance_manager') && in_array($oldStatus, ['Pending Documents', 'Returned for Correction'], true)) {
        if (!empty($checklistErrors)) {
            flash('error', implode(', ', $checklistErrors));
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        $newStatus = 'Pending Accounting Review';
        $statusChanged = true;
    } elseif ($action === 'submit_finance_review' && hasRole('admin', 'maker', 'finance_manager') && $oldStatus === 'Pending Accounting Review') {
        if (!empty($checklistErrors)) {
            flash('error', implode(', ', $checklistErrors));
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        $newStatus = 'Pending Finance Review';
        $statusChanged = true;
    } elseif ($action === 'checker_document_review' && hasRole('admin', 'checker', 'finance_manager') && $oldStatus === 'Pending Finance Review') {
        $poAccepted      = !empty($_POST['po_accepted'])      ? 1 : 0;
        $poComment       = trim($_POST['po_comment']      ?? '');
        $invoiceAccepted = !empty($_POST['invoice_accepted']) ? 1 : 0;
        $invoiceComment  = trim($_POST['invoice_comment'] ?? '');
        $grAccepted      = !empty($_POST['gr_accepted'])      ? 1 : 0;
        $grComment       = trim($_POST['gr_comment']      ?? '');
        $reviewDecision  = trim($_POST['review_decision'] ?? 'save_only');
        $paymentMethod   = trim($_POST['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['cheque', 'transfer', 'cash'], true)) {
            $paymentMethod = '';
        }

        $db->prepare("
            UPDATE payment_requests
            SET checker_po_accepted = ?, checker_po_comment = ?,
                checker_invoice_accepted = ?, checker_invoice_comment = ?,
                checker_gr_accepted = ?, checker_gr_comment = ?,
                payment_method = CASE WHEN ? <> '' THEN ? ELSE payment_method END,
                updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$poAccepted, $poComment, $invoiceAccepted, $invoiceComment, $grAccepted, $grComment, $paymentMethod, $paymentMethod, $id]);

        if ($reviewDecision === 'document_complete') {
            if (!$poAccepted || !$invoiceAccepted || !$grAccepted) {
                flash('error', 'ต้อง Accept เอกสารครบทั้ง 3 ส่วนก่อนกด Document Complete');
                redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
            }
            if ($paymentMethod === '' && trim((string)($request['payment_method'] ?? '')) === '') {
                flash('error', 'กรุณาระบุ Payment Method ก่อนกด Document Complete');
                redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
            }
            if (!empty($checklistErrors)) {
                flash('error', implode(', ', $checklistErrors));
                redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
            }
            if (createApprovalTasks($db, $id, (float)$request['net_payable']) === 0) {
                flash('error', 'No complete approval route is configured for this amount. Please contact an administrator.');
                redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
            }
            $db->prepare("UPDATE payment_requests SET checked_by = ?, checked_at = datetime('now','localtime') WHERE id = ?")
                ->execute([$user['id'], $id]);
            $newStatus = 'Pending Management Approval';
            $statusChanged = true;
            $comment = 'Checker document review complete — all sections accepted';
        } elseif ($reviewDecision === 'waiting_correction') {
            $parts = [];
            if (!$poAccepted && $poComment !== '')      $parts[] = "PO: {$poComment}";
            if (!$invoiceAccepted && $invoiceComment !== '') $parts[] = "AP Invoice: {$invoiceComment}";
            if (!$grAccepted && $grComment !== '')      $parts[] = "GR/GRPO: {$grComment}";
            if (empty($parts)) {
                flash('error', 'กรุณาระบุ comment สำหรับส่วนที่ต้องแก้ไขก่อนส่ง Waiting for Correction');
                redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
            }
            $correctionMsg = implode(' | ', $parts);
            $db->prepare("UPDATE payment_requests SET return_to = 'Maker', return_reason = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                ->execute([$correctionMsg, $id]);
            sendCorrectionEmail($db, $request, $correctionMsg, $user);
            $newStatus = 'Returned for Correction';
            $statusChanged = true;
            $comment = $correctionMsg;
        } else {
            auditLog('CHECKER_REVIEW_SAVE', 'payment_requests', $id, '', 'Checker review progress saved');
            flash('success', 'บันทึกการตรวจสอบเรียบร้อย');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
    } elseif ($action === 'send_for_approval' && hasRole('admin', 'checker', 'finance_manager') && $oldStatus === 'Pending Finance Review') {
        if (!empty($checklistErrors)) {
            flash('error', implode(', ', $checklistErrors));
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        if (createApprovalTasks($db, $id, (float)$request['net_payable']) === 0) {
            flash('error', 'No complete approval route is configured for this amount. Please contact an administrator.');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        $newStatus = 'Pending Management Approval';
        $statusChanged = true;
    } elseif ($action === 'approve' && $canApproveManagementStep && $oldStatus === 'Pending Management Approval') {
        if ($activePendingApprovalTask !== null) {
            $db->prepare("
                UPDATE approval_tasks
                SET status = 'approved', action = 'approve', comment = ?, actioned_at = datetime('now','localtime')
                WHERE id = ? AND status = 'pending'
            ")->execute([$comment, $activePendingApprovalTask['id']]);
        }
        $remainingPendingTaskStmt = $db->prepare("
            SELECT COUNT(*)
            FROM approval_tasks
            WHERE payment_request_id = ?
              AND status = 'pending'
        ");
        $remainingPendingTaskStmt->execute([$id]);
        $remainingPendingTasks = (int)$remainingPendingTaskStmt->fetchColumn();
        $newStatus = $remainingPendingTasks > 0 ? 'Pending Management Approval' : 'Approved for Payment';
        $statusChanged = true;
    } elseif ($action === 'return' && (
        (in_array($oldStatus, ['Pending Accounting Review'], true) && hasRole('admin', 'maker', 'finance_manager')) ||
        ($oldStatus === 'Pending Finance Review' && hasRole('admin', 'checker', 'finance_manager')) ||
        ($oldStatus === 'Pending Management Approval' && $canApproveManagementStep)
    )) {
        $returnTo = trim($_POST['return_to'] ?? '');
        $returnReason = trim($_POST['return_reason'] ?? '');
        if ($returnTo === '' || $returnReason === '') {
            flash('error', 'Return target and reason are required');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        $newStatus = 'Returned for Correction';
        $statusChanged = true;
        $comment = $returnReason;
        if ($oldStatus === 'Pending Management Approval' && $activePendingApprovalTask !== null) {
            $db->prepare("
                UPDATE approval_tasks
                SET status = 'returned', action = 'return', comment = ?, actioned_at = datetime('now','localtime')
                WHERE id = ? AND status = 'pending'
            ")->execute([$comment, $activePendingApprovalTask['id']]);
        }
        $db->prepare("
            UPDATE payment_requests
            SET return_to = ?, return_reason = ?, updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$returnTo, $returnReason, $id]);
    } elseif ($action === 'reject' && (
        (in_array($oldStatus, ['Pending Accounting Review'], true) && hasRole('admin', 'maker', 'finance_manager')) ||
        ($oldStatus === 'Pending Finance Review' && hasRole('admin', 'checker', 'finance_manager')) ||
        ($oldStatus === 'Pending Management Approval' && $canApproveManagementStep)
    )) {
        if ($comment === '') {
            flash('error', 'Rejection reason is required');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }
        if ($oldStatus === 'Pending Management Approval' && $activePendingApprovalTask !== null) {
            $db->prepare("
                UPDATE approval_tasks
                SET status = 'rejected', action = 'reject', comment = ?, actioned_at = datetime('now','localtime')
                WHERE id = ? AND status = 'pending'
            ")->execute([$comment, $activePendingApprovalTask['id']]);
        }
        $newStatus = 'Rejected';
        $statusChanged = true;
    } elseif ($action === 'resubmit' && hasRole('admin', 'maker', 'finance_manager') && $oldStatus === 'Returned for Correction') {
        if (!empty($checklistErrors)) {
            $newStatus = 'Pending Documents';
        } else {
            $newStatus = 'Pending Accounting Review';
        }
        $statusChanged = true;
        $db->prepare("UPDATE payment_requests SET return_to = NULL, return_reason = NULL WHERE id = ?")->execute([$id]);
    } elseif ($action === 'mark_paid' && hasRole('admin', 'finance_manager') && $oldStatus === 'Approved for Payment') {
        $paymentDate = trim($_POST['payment_date'] ?? '');
        $paymentReference = trim($_POST['payment_reference'] ?? '');
        $paymentBank = trim($_POST['payment_bank'] ?? '');
        $payerName = trim($_POST['payer_name'] ?? '');

        if ($paymentDate === '' || $paymentReference === '' || $paymentBank === '' || $payerName === '') {
            flash('error', 'Payment date, reference, bank, and payer are required before marking paid');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }

        if (!$currentChecklist['payment_proof']) {
            flash('error', 'Payment Proof attachment is required before marking paid');
            redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
        }

        $db->prepare("
            UPDATE payment_requests
            SET payment_date = ?, payment_reference = ?, payment_bank = ?, payer_name = ?, updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$paymentDate, $paymentReference, $paymentBank, $payerName, $id]);

        $newStatus = 'Paid';
        $statusChanged = true;
    }

    if ($statusChanged) {
        $db->prepare("UPDATE payment_requests SET status = ?, updated_at = datetime('now','localtime') WHERE id = ?")
            ->execute([$newStatus, $id]);

        if ($newStatus === 'Paid') {
            foreach (loadPaymentRequestItems($db, $id) as $item) {
                if ($item['sap_invoice_id']) {
                    $db->prepare("UPDATE sap_ap_invoices SET payment_status = 'Paid', updated_at = datetime('now','localtime') WHERE id = ?")
                        ->execute([$item['sap_invoice_id']]);
                }
            }
        } elseif ($newStatus === 'Pending Documents') {
            foreach (loadPaymentRequestItems($db, $id) as $item) {
                if ($item['sap_invoice_id']) {
                    $db->prepare("UPDATE sap_ap_invoices SET payment_status = 'Pending Documents', updated_at = datetime('now','localtime') WHERE id = ?")
                        ->execute([$item['sap_invoice_id']]);
                }
            }
        }

        addHistory($db, $id, $user['id'], strtoupper($action), $comment, $oldStatus, $newStatus);
        auditLog(strtoupper($action), 'payment_requests', $id, $oldStatus, $newStatus);
        flash('success', "Action '{$action}' completed. Status: {$newStatus}");
    }

    redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $id);
}

$request = loadPaymentRequest($db, $id);
$items = loadPaymentRequestItems($db, $id);
$historyStmt = $db->prepare("
    SELECT h.*, u.full_name
    FROM approval_history h
    LEFT JOIN users u ON u.id = h.user_id
    WHERE h.payment_request_id = ?
    ORDER BY h.id DESC
");
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();
$attachments = loadAttachments($db, $id);
$attachmentMap = attachmentTypeMap($attachments);
[$checklist, $checklistErrors] = validateChecklist($request, $attachmentMap);

$approvalTasksStmt = $db->prepare("
    SELECT at.*, u.full_name
    FROM approval_tasks at
    LEFT JOIN users u ON u.id = at.approver_id
    WHERE at.payment_request_id = ?
    ORDER BY at.sequence
");
$approvalTasksStmt->execute([$id]);
$approvalTasks = $approvalTasksStmt->fetchAll();

$activePendingApprovalTask = null;
foreach ($approvalTasks as $task) {
    if (($task['status'] ?? '') !== 'pending') {
        continue;
    }
    if ($activePendingApprovalTask === null || (int)$task['sequence'] < (int)$activePendingApprovalTask['sequence']) {
        $activePendingApprovalTask = $task;
    }
}
$currentPendingApprovalTask = null;
if ($activePendingApprovalTask !== null && (int)($activePendingApprovalTask['approver_id'] ?? 0) === (int)$user['id']) {
    $currentPendingApprovalTask = $activePendingApprovalTask;
}
$canApproveManagementStep = $activePendingApprovalTask !== null
    && (isAdmin() || $currentPendingApprovalTask !== null);

$today = date('Y-m-d');
$isOverdue = $request['due_date'] && $request['due_date'] < $today && !in_array($request['status'], ['Paid', 'Rejected', 'Cancelled'], true);
$invoiceRefs = array_values(array_filter(array_map(static fn($item) => $item['ap_invoice_no'] ?: ($item['sap_inv_no'] ?? ''), $items)));
$outstandingBalance = in_array($request['status'], ['Paid', 'Rejected', 'Cancelled'], true) ? 0 : (float)$request['net_payable'];

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex items-center gap-3">
  <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-sm text-gray-500 hover:text-gray-700"><?= t('pr.detail.back') ?></a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
  <div class="space-y-4 lg:col-span-2">
    <div class="rounded-xl border bg-white p-5">
      <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-lg font-bold text-gray-800"><?= h($request['request_no']) ?></h2>
            <?= statusBadge($request['status']) ?>
            <?php if ($isOverdue): ?>
            <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700"><?= t('pr.overdue') ?></span>
            <?php endif; ?>
            <?php if ($request['priority'] === 'urgent'): ?>
            <span class="rounded bg-orange-100 px-2 py-0.5 text-xs text-orange-700" title="<?= h((string)($request['priority_reason'] ?? '')) ?>"><?= t('pr.detail.urgent') ?></span>
            <?php endif; ?>
          </div>
          <p class="mt-0.5 text-sm text-gray-600"><?= h($request['vendor_name']) ?> <?= $request['vendor_code'] ? '(' . h($request['vendor_code']) . ')' : '' ?></p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-bold" style="color:#003B5C;">THB <?= fmtMoney((float)$request['net_payable']) ?></p>
          <p class="text-xs text-gray-400"><?= t('label.net_payable') ?></p>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
        <div><p class="text-xs text-gray-500"><?= t('label.gross_amount') ?></p><p class="font-medium">THB <?= fmtMoney((float)($request['gross_amount'] ?? $request['total_amount'])) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('label.wht') ?></p><p class="font-medium">THB <?= fmtMoney((float)$request['wht_amount']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('label.outstanding') ?></p><p class="font-medium <?= $outstandingBalance > 0 ? 'text-orange-600' : 'text-gray-400' ?>">THB <?= fmtMoney($outstandingBalance) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('pr.col.invoice_ref') ?></p><p class="font-medium text-xs"><?= h(!empty($invoiceRefs) ? implode(', ', $invoiceRefs) : '-') ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('label.due_date') ?></p><p class="font-medium <?= $isOverdue ? 'text-red-600' : '' ?>"><?= fmtDate($request['due_date']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('label.payment_method') ?></p><p class="font-medium capitalize"><?= h((string)($request['payment_method'] ?: '-')) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('label.created_by') ?></p><p class="font-medium"><?= h($request['creator_name'] ?? '') ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('pr.detail.created_at') ?></p><p class="font-medium"><?= fmtDateTime($request['created_at']) ?></p></div>
        <?php if ($request['checked_by']): ?>
        <div><p class="text-xs text-gray-500"><?= t('pr.detail.finance_review_by') ?></p><p class="font-medium"><?= h($request['checker_name']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('pr.detail.finance_review_at') ?></p><p class="font-medium"><?= fmtDateTime($request['checked_at']) ?></p></div>
        <?php endif; ?>
        <?php if ($request['payment_reference']): ?>
        <div><p class="text-xs text-gray-500"><?= t('pr.detail.payment_ref') ?></p><p class="font-medium"><?= h($request['payment_reference']) ?></p></div>
        <div><p class="text-xs text-gray-500"><?= t('pr.detail.payment_date') ?></p><p class="font-medium"><?= fmtDate($request['payment_date']) ?></p></div>
        <?php endif; ?>
      </div>

      <?php if ($request['note']): ?>
      <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
        <span class="font-medium"><?= t('pr.detail.note_prefix') ?> </span><?= h($request['note']) ?>
      </div>
      <?php endif; ?>
      <?php if ($request['return_reason']): ?>
      <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
        <span class="font-medium"><?= t('pr.detail.returned_to') ?> <?= h((string)$request['return_to']) ?>:</span> <?= h((string)$request['return_reason']) ?>
      </div>
      <?php endif; ?>
    </div>

    <?php $canEditReview = hasRole('admin', 'maker', 'finance_manager') && !in_array($request['status'], ['Paid', 'Rejected', 'Cancelled'], true); ?>
    <div x-data="{ showForm: <?= ($canEditReview && isset($_GET['edit'])) ? 'true' : 'false' ?> }">
      <div class="rounded-xl border bg-white p-5">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="font-semibold text-gray-700"><?= t('pr.detail.checklist') ?></h3>
          <div class="flex items-center gap-2">
            <?php if (!empty($checklistErrors)): ?>
            <span class="rounded bg-amber-100 px-2 py-1 text-xs text-amber-700"><?= count($checklistErrors) ?> <?= t('pr.detail.items_pending') ?></span>
            <?php else: ?>
            <span class="rounded bg-emerald-100 px-2 py-1 text-xs text-emerald-700"><?= t('pr.detail.ready') ?></span>
            <?php endif; ?>
            <?php if ($canEditReview): ?>
            <button type="button" @click="showForm = true" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"><?= t('btn.edit') ?></button>
            <?php endif; ?>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <?php
          $checklistLabels = [
              'po' => t('pr.detail.po_confirmed'),
              'grn' => t('pr.detail.grn_confirmed'),
              'invoice' => t('pr.detail.invoice_confirmed'),
              'tax_invoice' => t('pr.detail.tax_confirmed'),
          ];
          foreach ($checklistLabels as $key => $label): ?>
          <div class="flex items-center gap-2 rounded-lg border p-3 text-sm <?= $checklist[$key] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'text-gray-500' ?>">
            <span><?= $checklist[$key] ? '✓' : '—' ?></span> <?= $label ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
          <div><p class="text-xs text-gray-500"><?= t('label.gross_amount') ?></p><p class="font-medium">THB <?= fmtMoney((float)($request['gross_amount'] ?? $request['total_amount'])) ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('pr.detail.wht_applicable') ?></p><p class="font-medium"><?= (int)$request['wht_applicable'] === 1 ? t('label.yes') : t('label.no') ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('label.wht_rate') ?></p><p class="font-medium"><?= (float)$request['wht_rate'] > 0 ? rtrim(rtrim(number_format((float)$request['wht_rate'], 2), '0'), '.') . '%' : '-' ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('label.wht_base') ?></p><p class="font-medium">THB <?= fmtMoney((float)$request['wht_base_amount']) ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('label.wht_amount') ?></p><p class="font-medium">THB <?= fmtMoney((float)$request['wht_amount']) ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('label.net_payable') ?></p><p class="font-medium text-emerald-700">THB <?= fmtMoney((float)$request['net_payable']) ?></p></div>
          <div><p class="text-xs text-gray-500"><?= t('pr.detail.tax_req_checkbox') ?></p><p class="font-medium"><?= (int)$request['tax_invoice_required'] === 1 ? t('label.yes') : t('label.no') ?></p></div>
        </div>

        <?php if ((string)$request['note'] !== ''): ?>
        <div class="mt-3">
          <p class="text-xs text-gray-500"><?= t('pr.detail.note_label') ?></p>
          <p class="mt-0.5 text-sm text-gray-700"><?= h((string)$request['note']) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($checklistErrors)): ?>
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
          <?= h(implode(' | ', $checklistErrors)) ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($canEditReview):
        dialogOpen('showForm', t('pr.detail.save_review'), 'max-w-3xl');
        include __DIR__ . '/_review_form.php';
        dialogClose();
      endif; ?>
    </div>

    <div class="rounded-xl border bg-white p-5">
      <h3 class="mb-3 font-semibold text-gray-700"><?= t('pr.detail.items') ?> (<?= count($items) ?>)</h3>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b bg-gray-50 text-left text-xs text-gray-500">
            <th class="px-3 py-2"><?= t('label.invoice_no') ?></th>
            <th class="px-3 py-2"><?= t('pr.detail.col.reference') ?></th>
            <th class="px-3 py-2"><?= t('pr.create.po_grpo') ?></th>
            <th class="px-3 py-2"><?= t('ap.col.invoice_date') ?></th>
            <th class="px-3 py-2 text-right"><?= t('label.amount') ?></th>
            <th class="px-3 py-2 text-right"><?= t('label.wht') ?></th>
            <th class="px-3 py-2 text-right"><?= t('pr.detail.col.net') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr class="border-b last:border-0">
            <td class="px-3 py-2 font-mono text-xs"><?= h($item['ap_invoice_no'] ?? $item['sap_inv_no'] ?? '') ?></td>
            <td class="px-3 py-2 text-xs text-gray-500"><?= h($item['sap_inv_no'] ?? $item['ap_invoice_no'] ?? '-') ?></td>
            <td class="px-3 py-2 text-xs text-gray-500"><?= h($item['po_doc_num'] ?? '-') ?> / <?= h($item['grpo_doc_num'] ?? '-') ?></td>
            <td class="px-3 py-2 text-xs"><?= fmtDate($item['invoice_date']) ?></td>
            <td class="px-3 py-2 text-right"><?= fmtMoney((float)$item['invoice_amount']) ?></td>
            <td class="px-3 py-2 text-right text-amber-700"><?= fmtMoney((float)$item['wht_amount']) ?></td>
            <td class="px-3 py-2 text-right font-semibold <?= $request['status'] === 'Paid' ? 'text-gray-400' : 'text-orange-600' ?>">
              <?= fmtMoney((float)$item['net_amount']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="rounded-xl border bg-white p-5">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="font-semibold text-gray-700"><?= t('pr.detail.documents') ?> (<?= count($attachments) ?>)</h3>
        <?php if (hasRole('admin', 'maker', 'checker', 'finance_manager')): ?><form method="POST" action="<?= BASE_URL ?>/api/attachments.php" enctype="multipart/form-data" class="flex items-center gap-2">
          <?= csrfField() ?>
          <input type="hidden" name="related_type" value="payment_request">
          <input type="hidden" name="related_id" value="<?= $id ?>">
          <input type="file" name="attachment" class="rounded border border-gray-300 px-2 py-1 text-xs">
          <select name="doc_type" class="rounded border border-gray-300 px-2 py-1 text-xs">
            <option value="Invoice">Invoice</option>
            <option value="PO">PO</option>
            <option value="GRPO">GRPO</option>
            <option value="Tax Invoice">Tax Invoice</option>
            <option value="Payment Proof">Payment Proof</option>
            <option value="Other">Other</option>
          </select>
          <button type="submit" class="theme-btn-primary rounded px-3 py-1 text-xs"><?= t('btn.upload') ?></button>
        </form><?php endif; ?>
      </div>
      <?php if (empty($attachments)): ?>
      <p class="py-3 text-center text-sm text-gray-400"><?= t('pr.detail.no_attachment') ?></p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($attachments as $attachment): ?>
        <div class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-sm">
          <div class="flex items-center gap-2">
            <span class="text-gray-400"><?= t('pr.detail.file_label') ?></span>
            <div>
              <p class="font-medium"><?= h($attachment['original_filename']) ?></p>
              <p class="text-xs text-gray-400"><?= h($attachment['document_type']) ?> | <?= h($attachment['uploader'] ?? '') ?> | <?= fmtDateTime($attachment['created_at']) ?></p>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/uploads/attachments/<?= h($attachment['filename']) ?>" target="_blank" class="text-xs hover:underline" style="color:#003B5C;"><?= t('pr.detail.download') ?></a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($request['status'] === 'Pending Finance Review' && hasRole('admin', 'checker', 'finance_manager')): ?>
    <?php
    $poItems      = array_values(array_filter(array_unique(array_column($items, 'po_doc_num'))));
    $grItems      = array_values(array_filter(array_unique(array_column($items, 'grpo_doc_num'))));
    $invItems     = array_values(array_filter(array_unique(array_map(static fn($i) => $i['ap_invoice_no'] ?: ($i['sap_inv_no'] ?? ''), $items))));
    $chkPoAcc     = (int)($request['checker_po_accepted'] ?? 0);
    $chkInvAcc    = (int)($request['checker_invoice_accepted'] ?? 0);
    $chkGrAcc     = (int)($request['checker_gr_accepted'] ?? 0);
    $allDocAcc    = $chkPoAcc && $chkInvAcc && $chkGrAcc;
    ?>
    <div class="rounded-xl border-2 border-blue-200 bg-white p-5" id="checker-review">
      <div class="mb-4 flex items-center gap-2">
        <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold text-white" style="background:#003B5C;">✓</span>
        <h3 class="font-semibold text-gray-800"><?= t('pr.detail.checker_review') ?></h3>
        <?php if ($allDocAcc): ?>
        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700 font-medium"><?= t('pr.detail.all_accepted') ?></span>
        <?php else: ?>
        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700"><?= (($chkPoAcc ? 1 : 0) + ($chkInvAcc ? 1 : 0) + ($chkGrAcc ? 1 : 0)) ?>/3 <?= t('pr.detail.x_accepted') ?></span>
        <?php endif; ?>
      </div>

      <form method="POST" id="checkerReviewForm" class="space-y-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="checker_document_review">

        <!-- PO Section -->
        <div class="rounded-lg border p-4 <?= $chkPoAcc ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 bg-white' ?>">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-700">1. Purchase Order (PO)</p>
              <?php if (!empty($poItems)): ?>
              <p class="mt-0.5 text-xs text-gray-500 font-mono"><?= h(implode(', ', $poItems)) ?></p>
              <?php else: ?>
              <p class="mt-0.5 text-xs text-gray-400 italic">ไม่พบเลข PO</p>
              <?php endif; ?>
            </div>
            <label class="flex cursor-pointer items-center gap-1.5 shrink-0">
              <input type="checkbox" name="po_accepted" value="1" id="po_accepted"
                     <?= $chkPoAcc ? 'checked' : '' ?>
                     onchange="toggleDocSection('po', this.checked)"
                     class="h-4 w-4 accent-emerald-600">
              <span class="text-sm font-medium <?= $chkPoAcc ? 'text-emerald-700' : 'text-gray-500' ?>"><?= t('pr.detail.accept_btn') ?></span>
            </label>
          </div>
          <div id="po_comment_area" class="mt-3 <?= $chkPoAcc ? 'hidden' : '' ?>">
            <label class="mb-1 block text-xs text-gray-500">Comment — เหตุผลที่ต้องแก้ไข PO</label>
            <textarea name="po_comment" rows="2"
                      placeholder="ระบุรายละเอียดที่ต้องแก้ไข..."
                      class="w-full rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"><?= h((string)($request['checker_po_comment'] ?? '')) ?></textarea>
          </div>
        </div>

        <!-- AP Invoice Section -->
        <div class="rounded-lg border p-4 <?= $chkInvAcc ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 bg-white' ?>">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-700">2. AP Invoice</p>
              <?php if (!empty($invItems)): ?>
              <p class="mt-0.5 text-xs text-gray-500 font-mono"><?= h(implode(', ', $invItems)) ?></p>
              <?php else: ?>
              <p class="mt-0.5 text-xs text-gray-400 italic">ไม่พบเลข AP Invoice</p>
              <?php endif; ?>
            </div>
            <label class="flex cursor-pointer items-center gap-1.5 shrink-0">
              <input type="checkbox" name="invoice_accepted" value="1" id="invoice_accepted"
                     <?= $chkInvAcc ? 'checked' : '' ?>
                     onchange="toggleDocSection('invoice', this.checked)"
                     class="h-4 w-4 accent-emerald-600">
              <span class="text-sm font-medium <?= $chkInvAcc ? 'text-emerald-700' : 'text-gray-500' ?>">Accept</span>
            </label>
          </div>
          <div id="invoice_comment_area" class="mt-3 <?= $chkInvAcc ? 'hidden' : '' ?>">
            <label class="mb-1 block text-xs text-gray-500">Comment — เหตุผลที่ต้องแก้ไข AP Invoice</label>
            <textarea name="invoice_comment" rows="2"
                      placeholder="ระบุรายละเอียดที่ต้องแก้ไข..."
                      class="w-full rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"><?= h((string)($request['checker_invoice_comment'] ?? '')) ?></textarea>
          </div>
        </div>

        <!-- GR / GRPO Section -->
        <div class="rounded-lg border p-4 <?= $chkGrAcc ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 bg-white' ?>">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-700">3. Goods Receipt (GR / GRPO)</p>
              <?php if (!empty($grItems)): ?>
              <p class="mt-0.5 text-xs text-gray-500 font-mono"><?= h(implode(', ', $grItems)) ?></p>
              <?php else: ?>
              <p class="mt-0.5 text-xs text-gray-400 italic">ไม่พบเลข GR/GRPO</p>
              <?php endif; ?>
            </div>
            <label class="flex cursor-pointer items-center gap-1.5 shrink-0">
              <input type="checkbox" name="gr_accepted" value="1" id="gr_accepted"
                     <?= $chkGrAcc ? 'checked' : '' ?>
                     onchange="toggleDocSection('gr', this.checked)"
                     class="h-4 w-4 accent-emerald-600">
              <span class="text-sm font-medium <?= $chkGrAcc ? 'text-emerald-700' : 'text-gray-500' ?>">Accept</span>
            </label>
          </div>
          <div id="gr_comment_area" class="mt-3 <?= $chkGrAcc ? 'hidden' : '' ?>">
            <label class="mb-1 block text-xs text-gray-500">Comment — เหตุผลที่ต้องแก้ไข GR/GRPO</label>
            <textarea name="gr_comment" rows="2"
                      placeholder="ระบุรายละเอียดที่ต้องแก้ไข..."
                      class="w-full rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"><?= h((string)($request['checker_gr_comment'] ?? '')) ?></textarea>
          </div>
        </div>

        <!-- Payment Method (set by Finance) -->
        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <label class="mb-1 block text-xs text-gray-500"><?= t('label.payment_method') ?> <span class="text-red-500">*</span></label>
          <select name="payment_method" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value=""><?= APP_LANG === 'th' ? '-- เลือกวิธีชำระเงิน --' : '-- Select payment method --' ?></option>
            <?php foreach (['cheque' => 'Cheque', 'transfer' => 'Bank Transfer', 'cash' => 'Cash'] as $methodValue => $methodLabel): ?>
              <option value="<?= $methodValue ?>" <?= $request['payment_method'] === $methodValue ? 'selected' : '' ?>><?= $methodLabel ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-2 pt-2">
          <button type="submit" name="review_decision" value="save_only"
                  class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <?= t('pr.detail.save_only_btn') ?>
          </button>
          <button type="submit" name="review_decision" value="document_complete"
                  id="btn_doc_complete"
                  <?= $allDocAcc ? '' : 'disabled' ?>
                  class="rounded-lg px-4 py-2 text-sm font-medium text-white transition-opacity <?= $allDocAcc ? 'cursor-pointer hover:opacity-90' : 'cursor-not-allowed opacity-40' ?>"
                  style="background:#006B3F;">
            <?= t('pr.detail.doc_complete_btn') ?>
          </button>
          <button type="submit" name="review_decision" value="waiting_correction"
                  class="rounded-lg bg-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-orange-600">
            <?= t('pr.detail.correction_btn') ?>
          </button>
        </div>
        <?php if (!$allDocAcc): ?>
        <p class="text-xs text-gray-400">* <?= t('pr.detail.all_accepted') ?></p>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="space-y-4">
    <div class="rounded-xl border bg-white p-5">
      <h3 class="mb-3 font-semibold text-gray-700"><?= t('pr.detail.workflow') ?></h3>

      <?php if ($request['status'] === 'Pending Documents' && hasRole('admin', 'maker', 'finance_manager')): ?>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit_documents">
        <button class="theme-btn-secondary w-full rounded-lg py-2 text-sm font-medium"><?= t('pr.detail.submit_accounting') ?></button>
      </form>
      <?php elseif ($request['status'] === 'Pending Accounting Review' && hasRole('admin', 'maker', 'finance_manager')): ?>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit_finance_review">
        <button class="theme-btn-secondary w-full rounded-lg py-2 text-sm font-medium"><?= t('pr.detail.submit_finance') ?></button>
      </form>
      <button type="button" id="returnBtn_acct" onclick="showReasonForm('returnBtn_acct','returnForm_acct')" class="mt-2 w-full rounded-lg bg-rose-500 py-2 text-sm font-medium text-white hover:bg-rose-600"><?= t('pr.detail.return_btn') ?></button>
      <form method="POST" id="returnForm_acct" class="hidden mt-2 space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="return">
        <select name="return_to" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value=""><?= t('pr.detail.return_to_label') ?></option>
          <option value="Procurement">Procurement</option>
          <option value="Accounting">Accounting</option>
        </select>
        <textarea name="return_reason" rows="2" required placeholder="<?= t('pr.detail.return_reason') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex gap-2">
          <button type="button" onclick="hideReasonForm('returnBtn_acct','returnForm_acct')" class="flex-1 rounded-lg border border-gray-300 bg-white py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
          <button class="flex-1 rounded-lg bg-rose-500 py-2 text-sm font-medium text-white hover:bg-rose-600"><?= t('pr.detail.return_btn') ?></button>
        </div>
      </form>
      <button type="button" id="rejectBtn_acct" onclick="showReasonForm('rejectBtn_acct','rejectForm_acct')" class="mt-2 w-full rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
      <form method="POST" id="rejectForm_acct" class="hidden mt-2 space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reject">
        <textarea name="comment" rows="2" required placeholder="<?= t('pr.detail.return_reason') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex gap-2">
          <button type="button" onclick="hideReasonForm('rejectBtn_acct','rejectForm_acct')" class="flex-1 rounded-lg border border-gray-300 bg-white py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
          <button class="flex-1 rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
        </div>
      </form>
      <?php elseif ($request['status'] === 'Pending Finance Review' && hasRole('admin', 'checker', 'finance_manager')): ?>
      <p class="mb-3 text-sm text-gray-600"><?= t('pr.detail.pending_finance') ?></p>
      <div class="space-y-1.5 mb-3">
        <?php
        $sPoAcc  = (int)($request['checker_po_accepted'] ?? 0);
        $sInvAcc = (int)($request['checker_invoice_accepted'] ?? 0);
        $sGrAcc  = (int)($request['checker_gr_accepted'] ?? 0);
        $docSections = [
            ['label' => '1. PO',         'accepted' => $sPoAcc],
            ['label' => '2. AP Invoice',  'accepted' => $sInvAcc],
            ['label' => '3. GR / GRPO',   'accepted' => $sGrAcc],
        ];
        foreach ($docSections as $ds):
        ?>
        <div class="flex items-center justify-between rounded px-3 py-2 text-sm
                    <?= $ds['accepted'] ? 'bg-emerald-50 border border-emerald-200' : 'bg-amber-50 border border-amber-200' ?>">
          <span class="<?= $ds['accepted'] ? 'text-emerald-800' : 'text-amber-800' ?>"><?= $ds['label'] ?></span>
          <?php if ($ds['accepted']): ?>
          <span class="text-xs font-semibold text-emerald-600">✓ <?= t('pr.detail.accepted') ?></span>
          <?php else: ?>
          <span class="text-xs text-amber-600"><?= t('approval.pending') ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <a href="#checker-review" class="block w-full rounded-lg border border-blue-300 py-2 text-center text-sm text-blue-600 hover:bg-blue-50">
        <?= t('pr.detail.goto_review') ?>
      </a>
      <button type="button" id="rejectBtn_checker" onclick="showReasonForm('rejectBtn_checker','rejectForm_checker')" class="mt-3 w-full rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
      <form method="POST" id="rejectForm_checker" class="hidden mt-3 space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reject">
        <textarea name="comment" rows="2" required placeholder="<?= t('pr.detail.return_reason') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex gap-2">
          <button type="button" onclick="hideReasonForm('rejectBtn_checker','rejectForm_checker')" class="flex-1 rounded-lg border border-gray-300 bg-white py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
          <button class="flex-1 rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
        </div>
      </form>
      <?php elseif ($request['status'] === 'Pending Management Approval' && $canApproveManagementStep): ?>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="approve">
        <textarea name="comment" rows="2" placeholder="<?= t('label.comment') ?>..." class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <button class="theme-btn-primary w-full rounded-lg py-2 text-sm font-medium"><?= t('pr.detail.approve_btn') ?></button>
      </form>
      <button type="button" id="returnBtn_appr" onclick="showReasonForm('returnBtn_appr','returnForm_appr')" class="mt-2 w-full rounded-lg bg-rose-500 py-2 text-sm font-medium text-white hover:bg-rose-600"><?= t('pr.detail.return_btn') ?></button>
      <form method="POST" id="returnForm_appr" class="hidden mt-2 space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="return">
        <select name="return_to" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value=""><?= t('pr.detail.return_to_label') ?></option>
          <option value="Procurement">Procurement</option>
          <option value="Accounting">Accounting</option>
        </select>
        <textarea name="return_reason" rows="2" required placeholder="<?= t('pr.detail.return_reason') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex gap-2">
          <button type="button" onclick="hideReasonForm('returnBtn_appr','returnForm_appr')" class="flex-1 rounded-lg border border-gray-300 bg-white py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
          <button class="flex-1 rounded-lg bg-rose-500 py-2 text-sm font-medium text-white hover:bg-rose-600"><?= t('pr.detail.return_btn') ?></button>
        </div>
      </form>
      <button type="button" id="rejectBtn_appr" onclick="showReasonForm('rejectBtn_appr','rejectForm_appr')" class="mt-2 w-full rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
      <form method="POST" id="rejectForm_appr" class="hidden mt-2 space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reject">
        <textarea name="comment" rows="2" required placeholder="<?= t('pr.detail.return_reason') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex gap-2">
          <button type="button" onclick="hideReasonForm('rejectBtn_appr','rejectForm_appr')" class="flex-1 rounded-lg border border-gray-300 bg-white py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
          <button class="flex-1 rounded-lg bg-red-500 py-2 text-sm font-medium text-white hover:bg-red-600"><?= t('pr.detail.reject_btn') ?></button>
        </div>
      </form>
      <?php elseif ($request['status'] === 'Pending Management Approval'): ?>
      <p class="py-2 text-center text-sm text-gray-400"><?= t('pr.detail.waiting_approver') ?></p>
      <?php elseif ($request['status'] === 'Returned for Correction' && hasRole('admin', 'maker', 'finance_manager')): ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="resubmit">
        <button class="theme-btn-secondary w-full rounded-lg py-2 text-sm font-medium"><?= t('pr.detail.resubmit_btn') ?></button>
      </form>
      <?php elseif ($request['status'] === 'Approved for Payment' && hasRole('admin', 'finance_manager')): ?>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="mark_paid">
        <input type="date" name="payment_date" value="<?= h((string)$request['payment_date']) ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required>
        <input type="text" name="payment_reference" value="<?= h((string)$request['payment_reference']) ?>" placeholder="<?= t('pr.detail.payment_ref') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required>
        <input type="text" name="payment_bank" value="<?= h((string)$request['payment_bank']) ?>" placeholder="<?= t('label.bank') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required>
        <input type="text" name="payer_name" value="<?= h((string)$request['payer_name']) ?>" placeholder="<?= t('label.payee') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required>
        <button class="theme-btn-primary w-full rounded-lg py-2 text-sm font-medium"><?= t('pr.detail.mark_paid_btn') ?></button>
      </form>
      <p class="mt-2 text-xs text-gray-500"><?= t('pr.detail.proof_required') ?></p>
      <?php else: ?>
      <p class="py-2 text-center text-sm text-gray-400"><?= t('pr.detail.no_action') ?></p>
      <?php endif; ?>
    </div>

    <?php if (!empty($approvalTasks)): ?>
    <div class="rounded-xl border bg-white p-5">
      <h3 class="mb-3 font-semibold text-gray-700"><?= t('pr.detail.approval_pipeline') ?></h3>
      <div class="space-y-2">
        <?php foreach ($approvalTasks as $task): ?>
        <div class="flex items-center gap-2 rounded-lg p-2 <?= $task['status'] === 'approved' ? 'bg-green-50' : ($task['status'] === 'pending' ? 'bg-yellow-50' : 'bg-gray-50') ?>">
          <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs <?= $task['status'] === 'approved' ? 'bg-green-500 text-white' : ($task['status'] === 'pending' ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-gray-600') ?>">
            <?= (int)$task['sequence'] ?>
          </div>
          <div class="flex-1 text-sm">
            <p class="font-medium"><?= h($task['full_name'] ?? t('pr.detail.pending_assignment')) ?></p>
            <?php if ($task['comment']): ?><p class="text-xs text-gray-500"><?= h($task['comment']) ?></p><?php endif; ?>
          </div>
          <?= statusBadge($task['status']) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="rounded-xl border bg-white p-5">
      <h3 class="mb-3 font-semibold text-gray-700"><?= t('label.history') ?></h3>
      <?php if (empty($history)): ?>
      <p class="text-sm text-gray-400"><?= t('pr.detail.no_history') ?></p>
      <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($history as $row): ?>
        <div class="flex gap-3">
          <div class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full" style="background:#006B3F;"></div>
          <div>
            <p class="text-sm font-medium"><?= h($row['full_name'] ?? '') ?> - <span class="font-mono text-xs"><?= h($row['action']) ?></span></p>
            <?php if ($row['comment']): ?><p class="mt-0.5 text-xs text-gray-500">"<?= h($row['comment']) ?>"</p><?php endif; ?>
            <p class="mt-0.5 text-xs text-gray-400"><?= fmtDateTime($row['created_at']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function updateWhtCalculation() {
    var form = document.getElementById('review-form');
    if (!form) return;

    var applicable = document.getElementById('wht_applicable').checked;
    var rate = Number(document.getElementById('wht_rate').value) || 0;
    var baseAmount = Number(document.getElementById('wht_base_amount').value) || 0;
    var grossAmount = Number(document.getElementById('gross_amount').value) || 0;
    var whtAmount = applicable
        ? Math.round(((baseAmount * rate) / 100 + Number.EPSILON) * 100) / 100
        : 0;
    var netPayable = Math.max(0, grossAmount - whtAmount);

    document.getElementById('wht_amount').value = whtAmount.toFixed(2);
    document.getElementById('net_payable_preview').textContent = 'THB ' + netPayable.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

['wht_applicable', 'wht_rate', 'wht_base_amount', 'gross_amount'].forEach(function (id) {
    var field = document.getElementById(id);
    if (field) {
        field.addEventListener('input', updateWhtCalculation);
        field.addEventListener('change', updateWhtCalculation);
    }
});
updateWhtCalculation();

function showReasonForm(btnId, formId) {
    document.getElementById(btnId).classList.add('hidden');
    document.getElementById(formId).classList.remove('hidden');
}

function hideReasonForm(btnId, formId) {
    document.getElementById(formId).classList.add('hidden');
    document.getElementById(btnId).classList.remove('hidden');
}

function toggleDocSection(section, accepted) {
    var area = document.getElementById(section + '_comment_area');
    if (!area) return;
    if (accepted) {
        area.classList.add('hidden');
    } else {
        area.classList.remove('hidden');
    }
    updateDocCompleteBtn();
}

function updateDocCompleteBtn() {
    var po      = document.getElementById('po_accepted');
    var inv     = document.getElementById('invoice_accepted');
    var gr      = document.getElementById('gr_accepted');
    var btn     = document.getElementById('btn_doc_complete');
    if (!btn) return;
    var allOk = (po && po.checked) && (inv && inv.checked) && (gr && gr.checked);
    btn.disabled = !allOk;
    if (allOk) {
        btn.classList.remove('cursor-not-allowed', 'opacity-40');
        btn.classList.add('cursor-pointer', 'hover:opacity-90');
    } else {
        btn.classList.remove('cursor-pointer', 'hover:opacity-90');
        btn.classList.add('cursor-not-allowed', 'opacity-40');
    }
}
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>
