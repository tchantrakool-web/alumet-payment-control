<?php

/**
 * Return work items that currently require action from the signed-in user.
 * Notifications are derived from workflow state, so completed work disappears
 * without needing a second notification queue to stay in sync.
 */
function getActionableNotifications(PDO $db, array $user): array {
    $userId = (int) ($user['id'] ?? 0);
    $role = (string) ($user['role_name'] ?? '');
    if ($userId <= 0 || $role === '') {
        return [];
    }

    $rows = [];
    $append = static function (PDOStatement $stmt) use (&$rows): void {
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }
    };

    // Maker-owned work: complete documents, perform accounting review, or fix a return.
    if (in_array($role, ['admin', 'maker', 'finance_manager'], true)) {
        $ownedOnly = $role === 'maker' ? ' AND pr.created_by = ?' : '';
        $stmt = $db->prepare("
            SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date,
                   pr.priority, pr.status, pr.updated_at, pr.return_reason
            FROM payment_requests pr
            WHERE pr.is_deleted = 0
              AND pr.status IN ('Pending Documents', 'Pending Accounting Review', 'Returned for Correction')
              {$ownedOnly}
        ");
        $stmt->execute($role === 'maker' ? [$userId] : []);
        $append($stmt);
    }

    // Finance review is a shared queue for checker-capable roles.
    if (in_array($role, ['admin', 'checker', 'finance_manager'], true)) {
        $stmt = $db->query("
            SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date,
                   pr.priority, pr.status, pr.updated_at, pr.return_reason
            FROM payment_requests pr
            WHERE pr.is_deleted = 0 AND pr.status = 'Pending Finance Review'
        ");
        $append($stmt);
    }

    // Only the active sequence is actionable for an assigned approver. Admin can see all.
    if (in_array($role, ['admin', 'approver', 'finance_manager', 'executive'], true)) {
        if ($role === 'admin') {
            $stmt = $db->query("
                SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date,
                       pr.priority, pr.status, pr.updated_at, pr.return_reason
                FROM payment_requests pr
                WHERE pr.is_deleted = 0 AND pr.status = 'Pending Management Approval'
            ");
        } else {
            $stmt = $db->prepare("
                SELECT DISTINCT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date,
                       pr.priority, pr.status, pr.updated_at, pr.return_reason
                FROM payment_requests pr
                JOIN approval_tasks at ON at.payment_request_id = pr.id
                WHERE pr.is_deleted = 0
                  AND pr.status = 'Pending Management Approval'
                  AND at.approver_id = ?
                  AND at.status = 'pending'
                  AND at.sequence = (
                      SELECT MIN(at2.sequence) FROM approval_tasks at2
                      WHERE at2.payment_request_id = pr.id AND at2.status = 'pending'
                  )
            ");
            $stmt->execute([$userId]);
        }
        $append($stmt);
    }

    // Approved requests still require payment preparation by Finance Manager.
    if (in_array($role, ['admin', 'finance_manager'], true)) {
        $stmt = $db->query("
            SELECT pr.id, pr.request_no, pr.vendor_name, pr.net_payable, pr.due_date,
                   pr.priority, pr.status, pr.updated_at, pr.return_reason
            FROM payment_requests pr
            WHERE pr.is_deleted = 0 AND pr.status = 'Approved for Payment'
        ");
        $append($stmt);
    }

    $lang = defined('APP_LANG') ? APP_LANG : 'en';
    $copy = [
        'Pending Documents' => ['th' => ['เอกสารรอดำเนินการ', 'กรุณาแนบและตรวจสอบเอกสาร'], 'en' => ['Documents required', 'Attach and verify supporting documents']],
        'Pending Accounting Review' => ['th' => ['รอตรวจสอบบัญชี', 'กรุณาตรวจสอบข้อมูลบัญชีและภาษี'], 'en' => ['Accounting review', 'Review accounting and tax details']],
        'Pending Finance Review' => ['th' => ['งานส่งมาให้ทบทวน', 'กรุณาทบทวนคำขอชำระเงิน'], 'en' => ['Ready for your review', 'Review this payment request']],
        'Pending Management Approval' => ['th' => ['งานรอการอนุมัติ', 'กรุณาพิจารณาอนุมัติคำขอ'], 'en' => ['Approval required', 'Review and approve this request']],
        'Approved for Payment' => ['th' => ['รอดำเนินการชำระเงิน', 'คำขออนุมัติแล้วและพร้อมจัดเตรียมการจ่าย'], 'en' => ['Payment preparation', 'Approved and ready for payment']],
        'Returned for Correction' => ['th' => ['งานถูกส่งกลับแก้ไข', 'กรุณาตรวจสอบเหตุผลและแก้ไขคำขอ'], 'en' => ['Correction required', 'Review the return reason and correct the request']],
    ];

    $items = [];
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        $updatedAt = (string) ($row['updated_at'] ?: 'unknown');
        $key = 'payment_request:' . (int) $row['id'] . ':' . hash('sha256', $status . '|' . $updatedAt);
        $dueTimestamp = !empty($row['due_date']) ? strtotime((string) $row['due_date']) : false;
        $isOverdue = $dueTimestamp !== false && $dueTimestamp < strtotime(date('Y-m-d'));
        $text = $copy[$status][$lang] ?? $copy[$status]['en'] ?? [$status, $status];
        $message = $text[1];
        if ($status === 'Returned for Correction' && trim((string) ($row['return_reason'] ?? '')) !== '') {
            $message = ($lang === 'th' ? 'เหตุผล: ' : 'Reason: ') . trim((string) $row['return_reason']);
        }
        $items[] = [
            'key' => $key,
            'request_id' => (int) $row['id'],
            'request_no' => (string) $row['request_no'],
            'vendor_name' => (string) ($row['vendor_name'] ?? ''),
            'amount' => (float) ($row['net_payable'] ?? 0),
            'due_date' => $row['due_date'] ? fmtDate((string) $row['due_date']) : '-',
            'is_overdue' => $isOverdue,
            'priority' => (string) ($row['priority'] ?? 'normal'),
            'status' => $status,
            'title' => $text[0],
            'message' => $message,
            'url' => BASE_URL . '/modules/payment_requests/detail.php?id=' . (int) $row['id'],
            'is_read' => false,
            'updated_at' => $updatedAt,
        ];
    }

    // Read only the fingerprints in the current queue. This keeps the header
    // query fast even after a user has accumulated old read-history records.
    $readKeys = [];
    $currentKeys = array_column($items, 'key');
    foreach (array_chunk($currentKeys, 500) as $keyChunk) {
        $placeholders = implode(',', array_fill(0, count($keyChunk), '?'));
        $readStmt = $db->prepare("SELECT notification_key FROM notification_reads WHERE user_id = ? AND notification_key IN ({$placeholders})");
        $readStmt->execute(array_merge([$userId], $keyChunk));
        foreach ($readStmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $readKeys[(string) $key] = true;
        }
    }
    foreach ($items as &$item) {
        $item['is_read'] = isset($readKeys[$item['key']]);
    }
    unset($item);

    usort($items, static function (array $a, array $b): int {
        if ($a['is_read'] !== $b['is_read']) return $a['is_read'] <=> $b['is_read'];
        if ($a['is_overdue'] !== $b['is_overdue']) return $b['is_overdue'] <=> $a['is_overdue'];
        $priority = ['urgent' => 0, 'normal' => 1, 'low' => 2];
        $priorityCompare = ($priority[$a['priority']] ?? 1) <=> ($priority[$b['priority']] ?? 1);
        if ($priorityCompare !== 0) return $priorityCompare;
        return strcmp($b['updated_at'], $a['updated_at']);
    });

    return $items;
}

function notificationPayload(PDO $db, array $user, int $limit = 20): array {
    $items = getActionableNotifications($db, $user);
    return [
        'items' => array_slice($items, 0, $limit),
        'unread_count' => count(array_filter($items, static fn(array $item): bool => !$item['is_read'])),
        'total_count' => count($items),
    ];
}

function markNotificationRead(PDO $db, int $userId, string $key): void {
    $stmt = $db->prepare("
        INSERT INTO notification_reads (user_id, notification_key, read_at)
        VALUES (?, ?, datetime('now','localtime'))
        ON CONFLICT(user_id, notification_key) DO UPDATE SET read_at = excluded.read_at
    ");
    $stmt->execute([$userId, $key]);
}
