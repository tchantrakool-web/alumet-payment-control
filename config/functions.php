<?php
function auditLog(string $action, string $module, int $recordId = 0, string $oldVal = '', string $newVal = ''): void {
    try {
        $db   = getDB();
        $user = currentUser();
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, module, record_id, old_value, new_value, ip_address)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user['id'] ?? 0,
            $user['username'] ?? 'system',
            $action,
            $module,
            $recordId,
            $oldVal ?: null,
            $newVal ?: null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (Exception $e) {
        // audit must not break the main flow
    }
}

function generateNo(string $prefix, string $table, string $column): string {
    $db   = getDB();
    $year = date('Y');
    $mon  = date('m');
    $like = "{$prefix}{$year}{$mon}%";
    $last = $db->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$like]);
    $row  = $last->fetchColumn();
    $seq  = $row ? ((int)substr($row, -4) + 1) : 1;
    return sprintf('%s%s%s%04d', $prefix, $year, $mon, $seq);
}

function fmtMoney(float $amount): string {
    return number_format($amount, 2);
}

function fmtDate(?string $date): string {
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function fmtDateTime(?string $dt): string {
    if (!$dt) return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : $dt;
}

function statusBadge(string $status): string {
    $map = [
        'Imported'          => 'bg-gray-100 text-gray-700',
        'Pending Documents' => 'bg-slate-100 text-slate-700',
        'Pending Accounting Review' => 'bg-yellow-100 text-yellow-700',
        'Pending Finance Review' => 'bg-sky-100 text-sky-700',
        'Pending Management Approval' => 'bg-orange-100 text-orange-700',
        'Approved for Payment' => 'bg-teal-100 text-teal-700',
        'Cheque Prepared'   => 'bg-blue-100 text-blue-700',
        'Returned for Correction' => 'bg-rose-100 text-rose-700',
        'Waiting Check'     => 'bg-yellow-100 text-yellow-700',
        'Checked'           => 'bg-blue-100 text-blue-700',
        'Waiting Approval'  => 'bg-orange-100 text-orange-700',
        'Approved'          => 'bg-green-100 text-green-700',
        'Ready to Pay'      => 'bg-teal-100 text-teal-700',
        'Paid'              => 'bg-emerald-100 text-emerald-700',
        'Rejected'          => 'bg-red-100 text-red-700',
        'Overdue'           => 'bg-red-200 text-red-800',
        'Cancelled'         => 'bg-gray-200 text-gray-600',
        'draft'             => 'bg-gray-100 text-gray-700',
        'pending'           => 'bg-yellow-100 text-yellow-700',
        'approved'          => 'bg-green-100 text-green-700',
        'rejected'          => 'bg-red-100 text-red-700',
        'prepared'          => 'bg-blue-100 text-blue-700',
        'signed'            => 'bg-purple-100 text-purple-700',
        'released'          => 'bg-teal-100 text-teal-700',
        'received'          => 'bg-emerald-100 text-emerald-700',
        'void'              => 'bg-gray-200 text-gray-600',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-600';
    $label = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    return "<span class=\"inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {$cls}\">{$label}</span>";
}

function agingLabel(int $days): string {
    if ($days < 0) return "<span class='text-red-600 font-semibold'>Overdue " . abs($days) . "d</span>";
    if ($days === 0) return "<span class='text-orange-600 font-semibold'>Due Today</span>";
    if ($days <= 7) return "<span class='text-yellow-600'>{$days}d</span>";
    return "<span class='text-gray-600'>{$days}d</span>";
}

function redirect(string $url): never {
    header("Location: {$url}");
    exit;
}

function flash(string $key, string $msg): void {
    $_SESSION["flash_{$key}"] = $msg;
}

function getFlash(string $key): string {
    $msg = $_SESSION["flash_{$key}"] ?? '';
    unset($_SESSION["flash_{$key}"]);
    return $msg;
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function t(string $key, string $default = ''): string {
    static $translations = null;
    if ($translations === null) {
        $translations = require __DIR__ . '/lang.php';
    }
    $lang = defined('APP_LANG') ? APP_LANG : 'en';
    return $translations[$lang][$key] ?? $translations['en'][$key] ?? ($default ?: $key);
}

function getSetting(string $key, string $default = ''): string {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val  = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}
