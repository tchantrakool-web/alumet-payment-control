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
<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-3 pb-1"><?= t('nav.section.dashboard') ?></div>
<?php sideLink(BASE_URL . '/dashboard.php', 'D', t('nav.dashboard'), $dir, $cur, '', 'dashboard.php'); ?>
<?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1"><?= t('nav.section.maker') ?></div>
<?php if (canAccess('import')): ?><?php sideLink(BASE_URL . '/modules/import/', 'I', t('nav.import'), $dir, $cur, 'import'); ?><?php endif; ?>
<?php if (canAccess('ap_invoices')): ?><?php sideLink(BASE_URL . '/modules/ap_invoices/', 'A', t('nav.ap_invoices'), $dir, $cur, 'ap_invoices'); ?><?php endif; ?>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'P', t('nav.payment_requests'), $dir, $cur, 'payment_requests'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1"><?= t('nav.section.checker') ?></div>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'R', t('nav.review_requests'), $dir, $cur, 'payment_requests'); ?><?php endif; ?>
<?php if (canAccess('approval')): ?><?php sideLink(BASE_URL . '/modules/approval/', 'Q', t('nav.approval_queue'), $dir, $cur, 'approval'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1"><?= t('nav.section.approver') ?></div>
<?php if (canAccess('approval')): ?><?php sideLink(BASE_URL . '/modules/approval/', 'A', t('nav.approve_requests'), $dir, $cur, 'approval'); ?><?php endif; ?>
<?php if (canAccess('payment_requests')): ?><?php sideLink(BASE_URL . '/modules/payment_requests/', 'V', t('nav.view_request_detail'), $dir, $cur, 'payment_requests'); ?><?php endif; ?>

<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1"><?= t('nav.section.monitoring') ?></div>
<?php if (canAccess('payment_batch')): ?><?php sideLink(BASE_URL . '/modules/payment_batch/', 'B', t('nav.payment_batch'), $dir, $cur, 'payment_batch'); ?><?php endif; ?>
<?php if (canAccess('cheques')): ?><?php sideLink(BASE_URL . '/modules/cheques/', 'C', t('nav.cheques'), $dir, $cur, 'cheques'); ?><?php endif; ?>
<?php if (canAccess('calendar')): ?><?php sideLink(BASE_URL . '/modules/calendar/', 'K', t('nav.calendar'), $dir, $cur, 'calendar'); ?><?php endif; ?>
<?php if (canAccess('reports')): ?><?php sideLink(BASE_URL . '/modules/reports/', 'R', t('nav.reports'), $dir, $cur, 'reports'); ?><?php endif; ?>
<?php if (canAccess('bpmn')): ?><?php sideLink(BASE_URL . '/modules/bpmn/', 'W', t('nav.bpmn'), $dir, $cur, 'bpmn'); ?><?php endif; ?>

<?php if (hasRole('admin')): ?>
<div class="sidebar-section text-xs uppercase tracking-wider px-3 pt-4 pb-1"><?= t('nav.section.admin') ?></div>
<?php sideLink(BASE_URL . '/modules/settings/', 'S', t('nav.settings'), $dir, $cur, 'settings'); ?>
<?php endif; ?>
