<?php $d=$dashboard;$c=$d['clients'];$s=$d['subscriptions'];$q=$d['queues'];$attention=$q['changes']+$q['fiscal']+$q['mail_failed']+$q['webhooks']+$q['quota']+$s['attention']; ?>
<div class="dashboard-intro"><div><h2>Totul, dintr-o privire.</h2><p>Clienți, reînnoiri și prioritățile de azi.</p></div><a class="button" href="<?= $adminUrl('new-client') ?>">+ Client nou</a></div>
<div class="metric-grid">
<a class="metric" href="<?= $adminUrl('clients') ?>"><span class="metric-label">Clienți înregistrați <i>↗</i></span><strong data-metric="clients"><?= $c['total'] ?></strong><small><b>+<?= $c['new30'] ?></b> în ultimele 30 de zile</small></a>
<a class="metric" href="<?= $adminUrl('clients') ?>"><span class="metric-label">Acces activ <i>↗</i></span><strong data-metric="active"><?= $c['active'] ?></strong><small><?= $c['banned'] ?> suspendați · <?= $c['unverified'] ?> emailuri neconfirmate</small></a>
<a class="metric" href="<?= $adminUrl('subscriptions') ?>"><span class="metric-label">Abonamente active <i>↗</i></span><strong data-metric="subscriptions"><?= $s['active'] ?></strong><small><?= $s['active']-$s['yearly'] ?> lunare · <?= $s['yearly'] ?> anuale</small></a>
<a class="metric <?= $attention?'needs-attention':'' ?>" href="<?= $adminUrl('operations') ?>"><span class="metric-label">De verificat <i>↗</i></span><strong data-metric="attention"><?= $attention ?></strong><small>Operațiuni și stări care cer atenție</small></a>
</div>
<div class="dashboard-grid">
<section class="panel revenue-panel"><div class="panel-heading"><div><p class="eyebrow">ULTIMELE 30 DE ZILE</p><h2>Încasări înregistrate</h2></div><span class="badge">Stripe <?= strtoupper($stripeActiveMode) ?></span></div>
<?php if(!$d['revenue']): ?><div class="empty-state"><span>↗</span><h3>Nicio încasare în acest interval</h3><p>Plățile confirmate vor apărea aici, separat pe monedă.</p></div><?php else: ?><div class="revenue-values"><?php foreach($d['revenue'] as $r): ?><div><strong><?= e($money($r['amount'],$r['currency'])) ?></strong><span><?= $r['payments'] ?> plăți înregistrate</span></div><?php endforeach ?></div><?php endif ?>
<p class="micro">Sume brute din evidența Stripe locală, fără scăderea rambursărilor. Monedele nu sunt convertite sau însumate între ele.</p></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">PORTOFOLIU</p><h2>Distribuția planurilor</h2></div><a class="text-link" href="<?= $adminUrl('clients') ?>">Vezi clienții ↗</a></div>
<div class="plan-distribution"><?php $counts=array_column($d['plans'],'clients','plan');foreach($plans as $p): $n=$counts[$p['slug']]??0; ?><div class="distribution-row"><div><span><?= e($p['name']) ?> <small><?= e($p['capacity']) ?></small></span><b><?= $n ?></b></div><meter min="0" max="<?= max(1,$c['total']) ?>" value="<?= $n ?>" aria-label="<?= e($p['name'].': '.$n.' clienți') ?>"><?= $n ?></meter></div><?php endforeach ?></div>
<div class="panel-foot"><span><?= $c['manual'] ?> planuri manuale</span><span><?= e(number_format($c['allocated']/1_000_000_000,0,',',' ')) ?> GB cotă alocată</span></div><p class="micro">Cota configurată în Zion; nu reprezintă spațiul utilizat pe disc.</p></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">PRIORITĂȚI</p><h2>Centrul de operațiuni</h2></div><span class="badge <?= $attention?'amber':'green' ?>"><?= $attention?'Necesită atenție':'La zi' ?></span></div>
<div class="priority-list"><?php foreach([
 ['Abonamente cu plată de verificat',$s['attention'],'subscriptions'],
 ['Upgrade-uri de procesat / revizuit',$q['changes'],'changes'],
 ['Facturi fiscale în așteptare',$q['fiscal'],'invoices'],
 ['Emailuri cu încercări nereușite',$q['mail_failed'],'operations'],
 ['Webhook-uri nefinalizate',$q['webhooks'],'operations'],
 ['Conturi cu sincronizare incompletă',$q['quota'],'clients']
] as [$label,$value,$target]): ?><a href="<?= $adminUrl($target).(in_array($target,['clients','subscriptions','changes','invoices'],true)?'&attention=1':'') ?>"><span><?= e($label) ?></span><b class="<?= $value?'count-warning':'' ?>"><?= $value ?></b><span aria-hidden="true">↗</span></a><?php endforeach ?></div></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">URMĂTOARELE 7 ZILE</p><h2>Reînnoiri apropiate</h2></div><a class="text-link" href="<?= $adminUrl('subscriptions') ?>">Toate ↗</a></div>
<?php if(!$d['renewals']): ?><div class="empty-state"><span>◷</span><h3>Nicio reînnoire apropiată</h3><p>Abonamentele active vor fi listate după data de reînnoire.</p></div><?php else: ?><div class="compact-list"><?php foreach($d['renewals'] as $r): ?><div><span><strong><?= e($r['email']) ?></strong><small><?= e($r['plan']) ?> · <?= $r['billing_interval']==='year'?'Anual':'Lunar' ?> · <?= e(strtoupper($r['currency'])) ?></small></span><time><?= e($formatDate($r['renewal_at'])) ?></time></div><?php endforeach ?></div><?php endif ?></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">CLIENȚI</p><h2>Ultimele înregistrări</h2></div><a class="text-link" href="<?= $adminUrl('clients') ?>">Toți ↗</a></div>
<?php if(!$d['recent']): ?><div class="empty-state"><span>＋</span><h3>Primul client începe aici</h3><p>Adaugă un client sau urmărește înregistrările de pe site.</p><a class="text-link" href="<?= $adminUrl('new-client') ?>">Adaugă client ↗</a></div><?php else: ?><div class="compact-list"><?php foreach($d['recent'] as $u): ?><div><span><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></span><span class="badge"><?= e($u['selected_plan_id']) ?></span></div><?php endforeach ?></div><?php endif ?></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">SERVICII</p><h2>Starea integrărilor</h2></div><a class="text-link" href="<?= $adminUrl('integrations') ?>">Configurează ↗</a></div>
<div class="priority-list">
<a href="<?= $adminUrl('integrations') ?>"><span>Stripe API și webhook</span><span class="badge <?= $stripeSettings['secret_key']&&$stripeSettings['webhook_secret']?'green':'amber' ?>"><?= $stripeSettings['secret_key']&&$stripeSettings['webhook_secret']?'Configurat':'De configurat' ?></span></a>
<a href="<?= $adminUrl('catalog') ?>"><span>Prețuri active în catalog</span><b><?= count(array_filter($catalogRows,static fn($r)=>$r['status']==='active')) ?> / 6</b></a>
<a href="<?= $adminUrl('integrations') ?>"><span>Oblio · emitere fiscală</span><span class="badge"><?= $oblioSettings['enabled']?'Activată':'Simulare' ?></span></a>
<a href="<?= $adminUrl('operations') ?>"><span>Emailuri în coadă</span><b><?= $q['mail'] ?></b></a>
</div><p class="micro">Configurație locală. Starea nu confirmă conectivitatea sau validitatea cheilor la furnizor.</p></section>
</div>
<p class="micro dashboard-updated">Actualizat la <?= e($formatDate($d['now'])) ?> · Reîncarcă pagina pentru date noi.</p>
