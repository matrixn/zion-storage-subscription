<?php
$adminUrl=static fn(string $target):string=>'/admin?section='.$target;
$formatDate=static fn(?int $timestamp):string=>$timestamp?(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i'):'—';
$money=static fn(int $minor,string $currency):string=>number_format($minor/100,2,',',' ').' '.strtoupper($currency);
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($admin?$sections[$section]:'Autentificare') ?> · Zion Admin</title><link rel="icon" href="/assets/favicon-32.png" sizes="32x32" type="image/png"><link rel="stylesheet" href="/assets/admin.css?v=customer-details-1"><script src="/assets/admin.js?v=customer-details-1" defer></script></head><body class="<?= $admin?'admin-shell'.($section==='immich-general'?' immich-general-page':''):'login-page' ?>">
<a class="skip-link" href="#admin-content">Sari la conținut</a>
<?php if($admin): ?>
<input type="checkbox" id="admin-menu" class="menu-toggle"><label for="admin-menu" class="menu-trigger">☰ Meniu administrare</label>
<aside class="sidebar" aria-label="Navigare administrare">
 <a class="brand" href="<?= $adminUrl('dashboard') ?>"><img src="/assets/icon.png" width="38" height="38" alt=""><span>ZION <small>STORAGE / ADMIN</small></span></a>
 <nav aria-label="Meniu principal"><ul>
 <li><a href="<?= $adminUrl('dashboard') ?>" <?= $section==='dashboard'?'aria-current="page"':'' ?>><span class="nav-icon">◫</span> Dashboard</a></li>
 <?php foreach(['Management'=>['clients'=>'Clienți','new-client'=>'Client nou','subscriptions'=>'Abonamente','changes'=>'Upgrade-uri','invoices'=>'Facturi fiscale','operations'=>'Operațiuni','legal-requests'=>'Retrageri consumatori'],'Configurare'=>['integrations'=>'API-uri Stripe și Oblio','catalog'=>'Catalog Stripe','tax'=>'TVA produse','immich'=>'API Immich','immich-general'=>'Immich · General','listmonk'=>'Email / Listmonk'],'Jurnal administrare'=>['audit'=>'Acțiuni administrare','api-log'=>'Comunicare API']] as $group=>$items): ?>
 <li class="nav-group"><span><?= e($group) ?></span><ul><?php foreach($items as $target=>$label): ?><li><a href="<?= $adminUrl($target) ?>" <?= $section===$target?'aria-current="page"':'' ?>><span class="nav-dot" aria-hidden="true"></span><?= e($label) ?></a></li><?php endforeach ?></ul></li>
 <?php endforeach ?>
 </ul></nav>
 <div class="sidebar-footer"><span class="status-dot"></span> Stripe <?= strtoupper($stripeActiveMode) ?><a href="/ro/">Deschide site-ul ↗</a></div>
</aside>
<?php endif ?>
<main id="admin-content" class="<?= $admin?'admin-content':'login-content' ?>">
<header class="topbar"><?php if($admin): ?><div><p class="eyebrow">ZION / CONTROL CENTER</p><h1><?= e($sections[$section]) ?></h1></div><div class="admin-identity"><span><?= e($admin['email']) ?><small>Administrator</small></span><form method="post"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><button class="secondary" name="action" value="logout">Ieșire ↗</button></form></div><?php else: ?><a class="brand" href="/ro/"><img src="/assets/icon.png" width="38" height="38" alt=""><span>ZION <small>STORAGE / ADMIN</small></span></a><?php endif ?></header>
<?php if($error): ?><p role="alert" class="alert"><?= e($error) ?></p><?php endif ?><?php if($notice): ?><p role="status" class="notice"><?= e($notice) ?></p><?php endif ?>
<?php if(!$admin): ?><section class="login"><h2>Autentificare administrator</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="login"><label>Email<input name="email" type="email" autocomplete="username" required maxlength="254"></label><label>Parolă<input name="password" type="password" autocomplete="current-password" required maxlength="200"></label><button>Intră în administrare</button></form></section>

<?php else:
 if(in_array($section,['dashboard','clients'],true) && !Zion\AccountControl::gatewayConfigured()): ?><div class="alert compact"><strong>Protecția Immich necesită configurare.</strong> Suspendarea limitează încărcările; blocarea accesului la fișiere necesită proxy-ul Immich.</div><?php endif ?>
<?php if(in_array($section,['clients','subscriptions','changes','invoices'],true)): ?><nav class="filter-tabs" aria-label="Filtrare rezultate"><a href="<?= $adminUrl($section) ?>" <?= !$attentionOnly?'aria-current="page"':'' ?>>Toate</a><a href="<?= $adminUrl($section) ?>&amp;attention=1" <?= $attentionOnly?'aria-current="page"':'' ?>>De verificat</a></nav><?php endif ?>
<?php require __DIR__.'/admin-'.$section.'.php'; ?>
<?php endif ?>
<footer class="page-footer">Zion Storage · Administrare <span>Date locale · Ore Europe/Bucharest</span></footer>
</main></body></html>
