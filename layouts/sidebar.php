<?php
$cur = basename($_SERVER['PHP_SELF']);
$dir = basename(dirname($_SERVER['PHP_SELF']));
$menuTranslations = require ROOT_PATH . '/config/lang.php';
$biNav = static function (string $key) use ($menuTranslations): array {
    return [
        'th' => $menuTranslations['th'][$key] ?? $key,
        'en' => $menuTranslations['en'][$key] ?? $key,
    ];
};

$navGroups = [
    [
        'label' => $biNav('nav.section.dashboard'),
        'items' => canAccess('dashboard') ? [
            [BASE_URL . '/dashboard.php', 'dashboard', $biNav('nav.dashboard'), '', 'dashboard.php'],
        ] : [],
    ],
    [
        'label' => $biNav('nav.section.maker'),
        'items' => array_values(array_filter([
            canAccess('import') ? [BASE_URL . '/modules/import/', 'import', $biNav('nav.import'), 'import', ''] : null,
            canAccess('ap_invoices') ? [BASE_URL . '/modules/ap_invoices/', 'ap_invoices', $biNav('nav.ap_invoices'), 'ap_invoices', ''] : null,
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'payment_requests', $biNav('nav.payment_requests'), 'payment_requests', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.checker'),
        'items' => array_values(array_filter([
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'payment_requests', $biNav('nav.review_requests'), 'payment_requests', ''] : null,
            canAccess('approval') ? [BASE_URL . '/modules/approval/', 'approval', $biNav('nav.approval_queue'), 'approval', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.approver'),
        'items' => array_values(array_filter([
            canAccess('approval') ? [BASE_URL . '/modules/approval/', 'approval', $biNav('nav.approve_requests'), 'approval', ''] : null,
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'payment_requests', $biNav('nav.view_request_detail'), 'payment_requests', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.monitoring'),
        'items' => array_values(array_filter([
            canAccess('payment_batch') ? [BASE_URL . '/modules/payment_batch/', 'payment_batch', $biNav('nav.payment_batch'), 'payment_batch', ''] : null,
            canAccess('cheques') ? [BASE_URL . '/modules/cheques/', 'cheques', $biNav('nav.cheques'), 'cheques', ''] : null,
            canAccess('calendar') ? [BASE_URL . '/modules/calendar/', 'calendar', $biNav('nav.calendar'), 'calendar', ''] : null,
            canAccess('reports') ? [BASE_URL . '/modules/reports/', 'reports', $biNav('nav.reports'), 'reports', ''] : null,
            canAccess('bpmn') ? [BASE_URL . '/modules/bpmn/', 'bpmn', $biNav('nav.bpmn'), 'bpmn', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.admin'),
        'items' => canAccess('settings') ? [
            [BASE_URL . '/modules/settings/', 'settings', $biNav('nav.settings'), 'settings', ''],
        ] : [],
    ],
];

// Heroicons (outline, 24x24, stroke-based) inlined by key so every nav item
// gets a real icon with no icon-font and no extra <script> tag. stroke is
// left as currentColor so .sidebar-link's own color rules (default/hover/
// active) drive the icon's color along with the label text.
$navIconPaths = [
    'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />',
    'import' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />',
    'ap_invoices' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />',
    'payment_requests' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />',
    'approval' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
    'payment_batch' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3.75a2.25 2.25 0 0 0-2.25 2.25v.75c0 .414.336.75.75.75h15a.75.75 0 0 0 .75-.75V6a2.25 2.25 0 0 0-2.25-2.25H6ZM3.75 9v9.75a2.25 2.25 0 0 0 2.25 2.25h12a2.25 2.25 0 0 0 2.25-2.25V9H3.75Zm7.5 3.75h1.5a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 .75-.75Z" />',
    'cheques' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />',
    'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />',
    'reports' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
    'bpmn' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />',
    'settings' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.646.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />',
];

$navGroups = array_values(array_filter($navGroups, static fn(array $group): bool => count($group['items']) > 0));

$navItemActive = static function (array $item, string $curDir, string $curFile): bool {
    return ($item[3] !== '' && $curDir === $item[3]) || ($item[4] !== '' && $curFile === $item[4]);
};
?>

<nav class="sidebar-scroll flex-1 overflow-y-auto px-3 py-4 space-y-5" aria-label="Main navigation">
  <?php foreach ($navGroups as $group): ?>
    <div>
      <div class="sidebar-section-label"><span><?= h($group['label']['th']) ?></span> · <span><?= h($group['label']['en']) ?></span></div>
      <div class="mt-1.5 space-y-0.5">
        <?php foreach ($group['items'] as $item): ?>
          <a href="<?= h($item[0]) ?>" class="sidebar-link <?= $navItemActive($item, $dir, $cur) ? 'active' : '' ?>" @click="mobileNavOpen = false">
            <span class="sidebar-icon">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><?= $navIconPaths[$item[1]] ?? '' ?></svg>
            </span>
            <span class="nav-bilingual"><span><?= h($item[2]['th']) ?></span><small><?= h($item[2]['en']) ?></small></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</nav>
