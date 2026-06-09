<?php
$cur = basename($_SERVER['PHP_SELF']);
$dir = basename(dirname($_SERVER['PHP_SELF']));

function sideLink(string $href, string $icon, string $label, string $curDir, string $curFile, string $matchDir = '', string $matchFile = ''): void {
    $active = ($matchDir && $curDir === $matchDir) || ($matchFile && $curFile === $matchFile);
    $cls    = $active ? 'sidebar-link active' : 'sidebar-link';
    echo "<a href=\"{$href}\" class=\"{$cls}\">{$icon} <span>{$label}</span></a>\n";
}
?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-3 pb-1">Main</div>
<?php sideLink(BASE_URL . '/dashboard.php', '📊', 'Dashboard', $dir, $cur, '', 'dashboard.php'); ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Finance</div>
<?php sideLink(BASE_URL . '/modules/import/',            '📥', 'Import Center',      $dir, $cur, 'import'); ?>
<?php sideLink(BASE_URL . '/modules/ap_invoices/',       '📋', 'AP Invoice Queue',   $dir, $cur, 'ap_invoices'); ?>
<?php sideLink(BASE_URL . '/modules/payment_requests/',  '📝', 'Payment Requests',   $dir, $cur, 'payment_requests'); ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Approval</div>
<?php sideLink(BASE_URL . '/modules/approval/',          '✅', 'Approval Queue',     $dir, $cur, 'approval'); ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Payment</div>
<?php sideLink(BASE_URL . '/modules/payment_batch/',     '💼', 'Payment Batch',      $dir, $cur, 'payment_batch'); ?>
<?php sideLink(BASE_URL . '/modules/cheques/',           '🏦', 'Cheque Register',    $dir, $cur, 'cheques'); ?>
<?php sideLink(BASE_URL . '/modules/calendar/',          '📅', 'Payment Calendar',   $dir, $cur, 'calendar'); ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Analytics</div>
<?php sideLink(BASE_URL . '/modules/reports/',           '📈', 'Reports',            $dir, $cur, 'reports'); ?>

<?php if (hasRole('admin')): ?>
<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1">Admin</div>
<?php sideLink(BASE_URL . '/modules/settings/',          '⚙️', 'Settings',           $dir, $cur, 'settings'); ?>
<?php endif; ?>
