<section class="wrap billing-page">
<div class="page-heading"><p class="eyebrow">ZION STORAGE</p><h1><?= e($t('billing.title')) ?></h1><p class="lead"><?= e($t('billing.intro')) ?></p></div>
<?php if(isset($data['notice'])): ?><p class="alert success" role="status"><?= e($t($data['notice'])) ?></p><?php endif ?>
<div class="billing-layout">
<form method="post" action="<?= $url('billing-profile') ?>" class="form-card" data-billing-profile>
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>">
<?php require __DIR__.'/billing-fields.php' ?>
<button class="button full"><?= e($t('billing.save')) ?> ↗</button>
<?php if($profile && $user['selected_plan_id']!=='free'): ?><a class="text-link recovery" href="<?= $url('checkout') ?>"><?= e($t('checkout.button')) ?> ↗</a><?php endif ?>
</form>
<div class="invoice-history"><h2><?= e($t('billing.history')) ?></h2><p class="micro"><?= e($t('billing.history.note')) ?></p>
<?php if(!$fiscalInvoices): ?><p><?= e($t('billing.empty')) ?></p><?php endif ?>
<?php foreach($fiscalInvoices as $fiscalInvoice): if($fiscalInvoice['status']==='simulated' && production()) continue; ?>
<article class="invoice-card"><div><strong><?= e($fiscalInvoice['oblio_series']?$fiscalInvoice['oblio_series'].' '.$fiscalInvoice['oblio_number']:$t('billing.document')) ?></strong><span class="invoice-status"><?= e($t('billing.status.'.$fiscalInvoice['status'])) ?></span></div>
<p><?= number_format($fiscalInvoice['amount']/100,2,',','') ?> <?= e($fiscalInvoice['currency']) ?></p>
<?php if($fiscalInvoice['billing_period_start'] && $fiscalInvoice['billing_period_end']): ?><p class="micro"><?= e(Zion\OblioInvoiceService::coveredPeriod($fiscalInvoice['billing_period_start'],$fiscalInvoice['billing_period_end'])) ?></p><?php endif ?>
<?php if($fiscalInvoice['status']==='issued' && $fiscalInvoice['invoice_url']): ?><a class="text-link" href="<?= e($fiscalInvoice['invoice_url']) ?>" rel="noopener noreferrer" target="_blank"><?= e($t('billing.download')) ?> ↗</a><?php endif ?>
</article>
<?php endforeach ?>
</div></div></section>
