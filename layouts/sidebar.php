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
            [BASE_URL . '/dashboard.php', 'D', $biNav('nav.dashboard'), '', 'dashboard.php'],
        ] : [],
    ],
    [
        'label' => $biNav('nav.section.maker'),
        'items' => array_values(array_filter([
            canAccess('import') ? [BASE_URL . '/modules/import/', 'I', $biNav('nav.import'), 'import', ''] : null,
            canAccess('ap_invoices') ? [BASE_URL . '/modules/ap_invoices/', 'A', $biNav('nav.ap_invoices'), 'ap_invoices', ''] : null,
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'P', $biNav('nav.payment_requests'), 'payment_requests', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.checker'),
        'items' => array_values(array_filter([
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'R', $biNav('nav.review_requests'), 'payment_requests', ''] : null,
            canAccess('approval') ? [BASE_URL . '/modules/approval/', 'Q', $biNav('nav.approval_queue'), 'approval', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.approver'),
        'items' => array_values(array_filter([
            canAccess('approval') ? [BASE_URL . '/modules/approval/', 'A', $biNav('nav.approve_requests'), 'approval', ''] : null,
            canAccess('payment_requests') ? [BASE_URL . '/modules/payment_requests/', 'V', $biNav('nav.view_request_detail'), 'payment_requests', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.monitoring'),
        'items' => array_values(array_filter([
            canAccess('payment_batch') ? [BASE_URL . '/modules/payment_batch/', 'B', $biNav('nav.payment_batch'), 'payment_batch', ''] : null,
            canAccess('cheques') ? [BASE_URL . '/modules/cheques/', 'C', $biNav('nav.cheques'), 'cheques', ''] : null,
            canAccess('calendar') ? [BASE_URL . '/modules/calendar/', 'K', $biNav('nav.calendar'), 'calendar', ''] : null,
            canAccess('reports') ? [BASE_URL . '/modules/reports/', 'R', $biNav('nav.reports'), 'reports', ''] : null,
            canAccess('bpmn') ? [BASE_URL . '/modules/bpmn/', 'W', $biNav('nav.bpmn'), 'bpmn', ''] : null,
        ])),
    ],
    [
        'label' => $biNav('nav.section.admin'),
        'items' => canAccess('settings') ? [
            [BASE_URL . '/modules/settings/', 'S', $biNav('nav.settings'), 'settings', ''],
        ] : [],
    ],
];

$navGroups = array_values(array_filter($navGroups, static fn(array $group): bool => count($group['items']) > 0));

$topNavItemActive = static function (array $item, string $curDir, string $curFile): bool {
    return ($item[3] !== '' && $curDir === $item[3]) || ($item[4] !== '' && $curFile === $item[4]);
};
?>

<nav class="hidden lg:flex h-12 items-center gap-1 overflow-visible px-4 sm:px-6" aria-label="Main navigation">
  <?php foreach ($navGroups as $group):
      $groupActive = false;
      foreach ($group['items'] as $item) {
          if ($topNavItemActive($item, $dir, $cur)) { $groupActive = true; break; }
      }
  ?>
    <?php if (count($group['items']) === 1): $item = $group['items'][0]; ?>
      <a href="<?= h($item[0]) ?>" class="topnav-link <?= $topNavItemActive($item, $dir, $cur) ? 'active' : '' ?>">
        <span class="topnav-icon"><?= h($item[1]) ?></span><span class="nav-bilingual"><span><?= h($item[2]['th']) ?></span><small><?= h($item[2]['en']) ?></small></span>
      </a>
    <?php else: ?>
      <div class="relative h-full flex items-center" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" @keydown.escape.window="open = false">
        <button type="button" class="topnav-link <?= $groupActive ? 'active' : '' ?>" @click="open = !open" :aria-expanded="open.toString()">
          <span class="nav-bilingual"><span><?= h($group['label']['th']) ?></span><small><?= h($group['label']['en']) ?></small></span>
          <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top.left class="topnav-dropdown">
          <div class="topnav-dropdown-title"><span><?= h($group['label']['th']) ?></span> · <span><?= h($group['label']['en']) ?></span></div>
          <?php foreach ($group['items'] as $item): ?>
            <a href="<?= h($item[0]) ?>" class="topnav-dropdown-link <?= $topNavItemActive($item, $dir, $cur) ? 'active' : '' ?>">
              <span class="topnav-icon"><?= h($item[1]) ?></span><span class="nav-bilingual"><span><?= h($item[2]['th']) ?></span><small><?= h($item[2]['en']) ?></small></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>

<div x-cloak x-show="mobileNavOpen" x-transition.opacity class="lg:hidden fixed inset-x-0 top-16 bottom-0 z-40 bg-slate-950/30" @click.self="mobileNavOpen = false">
  <nav class="bg-white border-t border-slate-200 shadow-2xl max-h-[calc(100vh-4rem)] overflow-y-auto p-4" aria-label="Mobile navigation">
    <div class="grid gap-5 sm:grid-cols-2">
      <?php foreach ($navGroups as $group): ?>
        <section>
          <div class="mobile-nav-section"><?= h($group['label']['th']) ?> <span>· <?= h($group['label']['en']) ?></span></div>
          <div class="space-y-1">
            <?php foreach ($group['items'] as $item): ?>
              <a href="<?= h($item[0]) ?>" class="mobile-nav-link <?= $topNavItemActive($item, $dir, $cur) ? 'active' : '' ?>" @click="mobileNavOpen = false">
                <span class="topnav-icon"><?= h($item[1]) ?></span><span class="nav-bilingual"><span><?= h($item[2]['th']) ?></span><small><?= h($item[2]['en']) ?></small></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </nav>
</div>
