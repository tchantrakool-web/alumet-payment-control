<?php
$cur = basename($_SERVER['PHP_SELF']);
$dir = basename(dirname($_SERVER['PHP_SELF']));

function sideLink(string $href, string $icon, string $label, string $curDir, string $curFile, string $matchDir = '', string $matchFile = ''): void
{
    $active = ($matchDir && $curDir === $matchDir) || ($matchFile && $curFile === $matchFile);
    $cls = $active ? 'sidebar-link active' : 'sidebar-link';
    echo "<a href=\"{$href}\" class=\"{$cls}\"><span class=\"sidebar-icon\">{$icon}</span><span>{$label}</span></a>\n";
}
?>

<?php if (canAccess('dashboard')): ?>
<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-3 pb-1">Dashboard</div>
<?php sideLink(BASE_URL . '/dashboard.php', 'D', 'Executive Dashboard', $dir, $cur, '', 'dashboard.php'); ?>
<?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Maker</div>
<?php if (canAccess('import')): ?><?php sideLink(BASE_URL . '/modules/import/', 'I', 'Import Center', $dir, $cur, 'import'); ?><?php endif; ?>
<?php if (canAccess('ap_invoices')): ?><?php sideLink(BASE_URL . '/modules/ap_invoices/', 'A', 'AP Invoice Queue', $dir, $cur, 'ap_invoices'); ?><?php endif; ?>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'P', 'Payment Requests', $dir, $cur, 'payment_requests'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Checker</div>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'R', 'Review Requests', $dir, $cur, 'payment_requests'); ?><?php endif; ?>
<?php if (canAccess('approval')): ?><?php sideLink(BASE_URL . '/modules/approval/', 'Q', 'Approval Queue', $dir, $cur, 'approval'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Approver</div>
<?php if (canAccess('approval')): ?><?php sideLink(BASE_URL . '/modules/approval/', 'A', 'Approve Requests', $dir, $cur, 'approval'); ?><?php endif; ?>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'V', 'View Request Detail', $dir, $cur, 'payment_requests'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Monitoring</div>
<?php if (canAccess('payment_batch')): ?><?php sideLink(BASE_URL . '/modules/payment_batch/', 'B', 'Payment Batch', $dir, $cur, 'payment_batch'); ?><?php endif; ?>
<?php if (canAccess('cheques')): ?><?php sideLink(BASE_URL . '/modules/cheques/', 'C', 'Cheque Register', $dir, $cur, 'cheques'); ?><?php endif; ?>
<?php if (canAccess('calendar')): ?><?php sideLink(BASE_URL . '/modules/calendar/', 'K', 'Payment Calendar', $dir, $cur, 'calendar'); ?><?php endif; ?>
<?php if (canAccess('reports')): ?><?php sideLink(BASE_URL . '/modules/reports/', 'R', 'Reports', $dir, $cur, 'reports'); ?><?php endif; ?>
<?php if (canAccess('bpmn')): ?><?php sideLink(BASE_URL . '/modules/bpmn/', 'W', 'BPMN Workflow', $dir, $cur, 'bpmn'); ?><?php endif; ?>

<?php if (hasRole('admin')): ?>
<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Admin</div>
<?php sideLink(BASE_URL . '/modules/settings/', 'S', 'Settings', $dir, $cur, 'settings'); ?>
<?php endif; ?>
