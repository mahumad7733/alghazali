<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

ob_start();
renderAdminPage(requireAdminPage('settings'));
$html = (string) ob_get_clean();
$style = '<style id="settings-order-style">#app[data-admin-page="settings"] #dash-page{display:flex;flex-direction:column}#app[data-admin-page="settings"] #dash-page>.dash-title{order:0}#app[data-admin-page="settings"] #dash-page>.settings-navigation{order:1}#app[data-admin-page="settings"] #dash-page>.site-settings-panel,#app[data-admin-page="settings"] #dash-page>.settings-standalone-panel,#app[data-admin-page="settings"] #dash-page>.panel:not(.settings-navigation){order:2}.settings-section-grid{display:flex;align-items:stretch;gap:8px;overflow-x:auto;padding:4px 2px 9px;scrollbar-width:thin}.settings-section-card{display:flex;align-items:center;justify-content:center;flex:0 0 auto;min-height:0;min-width:145px;padding:11px 15px;border-radius:10px;background:var(--bg-card);white-space:nowrap;text-align:center}.settings-section-card small{display:none}.settings-section-card:hover,.settings-section-card.active{transform:none;box-shadow:0 4px 12px rgb(30 58 138/.1)}</style>';
echo str_replace('</head>', $style . '</head>', $html);
