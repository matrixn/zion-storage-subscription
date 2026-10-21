<section class="wrap flow-card confirmation-card">
<span class="flow-icon confirmed-icon" aria-hidden="true">✓</span>
<p class="eyebrow">ZION STORAGE</p><h1><?= e($t('checkout.title')) ?></h1><p><?= e($t('checkout.desc')) ?></p>
<?php if($user): $p=$this->plans->get($user['selected_plan_id'],$user['billing_interval'],$user['stripe_mode']);if($p && $p['price_amount']>0): $total=number_format($p['gross_amount']/100,2,$locale==='en'?'.':',',''); ?>
<div class="confirmed-plan"><div class="confirmed-plan-heading"><div><span class="micro"><?= e($t('checkout.plan')) ?></span><strong><?= e($p['name']) ?> <span>· <?= e($p['capacity']) ?></span></strong></div><span class="confirmed-period"><?= e($t($p['billing_interval']==='year'?'billing.year.interval':'pricing.interval')) ?></span></div>
<div class="confirmed-total"><span><?= e($t('checkout.total')) ?></span><strong><?= e($total) ?> <small>RON</small></strong></div>
<?php if($p['tax_bps']>0): ?><p class="confirmed-tax"><?= e($t($p['tax_bps']===0?'tax.no_vat':'tax.breakdown',['base'=>number_format($p['price_amount']/100,2,$locale==='en'?'.':',',''),'rate'=>Zion\BillingTax::percentage($p['tax_bps']),'tax'=>number_format(($p['gross_amount']-$p['price_amount'])/100,2,$locale==='en'?'.':',',''),'total'=>$total])) ?></p><?php endif ?><?php if($p['fx']): ?><p class="micro"><?= e($t('fx.rate',['rate'=>Zion\BnrRates::formatted($p['fx']),'date'=>$p['fx']['rate_date']])) ?></p><?php endif ?><p class="micro"><?= e($t('fx.policy')) ?></p></div>
<form method="post" action="<?= $url('checkout') ?>"><input type="hidden" name="price_quote" value="<?= e(Zion\BnrRates::signature($p,$user['id'])) ?>"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><button class="button full" data-confirmed-payment><?= e($t('checkout.pay',['amount'=>$total])) ?> <span aria-hidden="true">↗</span></button></form>
<p class="confirmed-security"><?= e($t('checkout.secure')) ?></p><p class="payment-mode-label"><?= e($t('billing.payment_mode_'.$p['stripe_mode'])) ?></p>
<?php else: ?><a class="button" href="<?= $url('status') ?>"><?= e($t('continue')) ?> ↗</a><?php endif;endif ?>
</section>
