<?php
$personal=current(array_filter($plans,static fn(array $p):bool=>$p['recommended']));
$formatAmount=static fn(int $minor):string=>number_format($minor/100,2,$locale==='en'?'.':',','');
$sharedTotal=100;$exampleMedia=65;$exampleFiles=20;$exampleEmail=15;
?>
<section class="section wrap pricing-section" id="pricing" aria-labelledby="pricing-title">
 <div class="pricing-heading"><p class="eyebrow">ZION / 03</p><h2 id="pricing-title"><?= e($t('pricing.title')) ?></h2><p class="section-intro"><?= e($t('pricing.desc')) ?></p></div>
 <form class="billing-switch" data-billing-switch method="get" action="<?= $url('home') ?>#pricing" aria-label="<?= e($t('billing.period')) ?>"><?php foreach(['month','year'] as $period): ?><button type="submit" name="billing_interval" value="<?= $period ?>" aria-pressed="<?= $billingInterval===$period?'true':'false' ?>"><?= e($t('billing.'.$period)) ?><?php if($period==='year'): ?><span><?= e($t('billing.discount')) ?></span><?php endif ?></button><?php endforeach ?></form>
 <div class="pricing-grid">
 <?php foreach($plans as $plan): $amount=$formatAmount($plan['price_amount']);
  $taxLabels=[];foreach(['month','year'] as $period)$taxLabels[$period]=$t('tax.summary',['rate'=>Zion\BillingTax::percentage($plan['tax_bps']),'total'=>$formatAmount(Zion\BillingTax::total($period==='year'?$plan['yearly_price']:$plan['monthly_price'],$plan['tax_bps']))]);
  $captions=[];foreach(['month','year'] as $period)$captions[$period]=!$plan['monthly_price']?$t('pricing.forever'):($plan['recommended']?$t('pricing.personal',['capacity'=>$plan['capacity'],'amount'=>$formatAmount($period==='year'?$plan['yearly_price']:$plan['monthly_price']),'interval'=>$t($period==='year'?'billing.year.interval':'pricing.interval')]):$t($period==='year'?'billing.annual.caption':'pricing.paid'));
 ?>
  <article class="plan <?= $plan['recommended']?'recommended':'' ?>" aria-labelledby="plan-<?= e($plan['slug']) ?>" data-month-amount="<?= e($t('pricing.amount',['amount'=>$formatAmount($plan['monthly_price'])])) ?>" data-year-amount="<?= e($t('pricing.amount',['amount'=>$formatAmount($plan['yearly_price'])])) ?>" data-month-interval="<?= e($t('pricing.interval')) ?>" data-year-interval="<?= e($t('billing.year.interval')) ?>" data-month-caption="<?= e($captions['month']) ?>" data-year-caption="<?= e($captions['year']) ?>">
   <?php if($plan['recommended']): ?><span class="recommendation"><?= e($t('recommended')) ?><span aria-hidden="true">✳</span></span><?php endif ?>
   <p class="plan-name" id="plan-<?= e($plan['slug']) ?>"><?= e($plan['name']) ?></p>
   <h3><?= e($plan['capacity']) ?></h3>
   <div class="plan-rule"></div>
   <p class="price"><span class="price-amount"><?= e($t('pricing.amount',['amount'=>$amount])) ?></span><span class="price-interval"><?= e($t($billingInterval==='year'?'billing.year.interval':'pricing.interval')) ?></span></p>
   <?php if($plan['monthly_price']): ?><p class="micro price-net-label"><?= e($t('tax.excluded')) ?></p><p class="micro plan-tax" data-month-tax="<?= e($taxLabels['month']) ?>" data-year-tax="<?= e($taxLabels['year']) ?>"><?= e($taxLabels[$billingInterval]) ?></p><?php endif ?>
   <p class="plan-caption"><?= e($captions[$billingInterval]) ?></p>
   <ul><?php foreach($plan['features'] as $feature): ?><li><?= e($t('pricing.feature.'.$feature,['capacity'=>$plan['capacity']])) ?></li><?php endforeach ?></ul>
   <a class="button <?= $plan['recommended']?'':'outline' ?>" href="<?= $url('register') ?>?plan=<?= e($plan['slug']) ?>&amp;billing_interval=<?= e($billingInterval) ?>"><?= e($t($plan['monthly_price']?'pricing.paid_cta':'pricing.free_cta')) ?><span aria-hidden="true">↗</span></a>
  </article>
 <?php endforeach ?>
 </div>
 <p class="micro pricing-note"><?= e($t('pricing.note')) ?></p>
 <div class="storage-comparison" data-storage-comparison data-total="<?= $sharedTotal ?>" aria-labelledby="comparison-title">
  <div class="comparison-heading"><div><p class="eyebrow"><?= e($t('comparison.eyebrow')) ?></p><h3 id="comparison-title"><?= e($t('comparison.title')) ?></h3></div><p><?= e($t('comparison.desc')) ?></p></div>
  <div class="comparison-rows">
   <div class="comparison-row shared-cloud">
    <div class="comparison-label"><span><?= e($t('comparison.shared')) ?></span><strong><?= $sharedTotal ?> GB</strong></div>
    <div class="capacity-bar" aria-hidden="true"><span class="bar-media" data-bar-media></span><span class="bar-files" data-bar-files></span><span class="bar-email" data-bar-email></span></div>
    <ul class="capacity-legend"><li><i class="media-dot" aria-hidden="true"></i><?= e($t('comparison.photos')) ?> <strong><span data-media><?= $exampleMedia ?></span> GB</strong></li><li><i class="files-dot" aria-hidden="true"></i><?= e($t('comparison.files')) ?> <strong><span data-files><?= $exampleFiles ?></span> GB</strong></li><li><i class="email-dot" aria-hidden="true"></i><?= e($t('comparison.email')) ?> <strong><span data-email><?= $exampleEmail ?></span> GB</strong></li></ul>
   </div>
   <div class="comparison-row dedicated-cloud">
    <div class="comparison-label"><span><?= e($t('comparison.dedicated',['plan'=>$personal['name']])) ?></span><strong><?= e($personal['capacity']) ?></strong></div>
    <div class="capacity-bar" aria-hidden="true"><span class="bar-dedicated"></span></div>
    <p class="dedicated-caption"><span aria-hidden="true">✳</span><?= e($t('comparison.available',['capacity'=>$personal['capacity']])) ?></p>
   </div>
  </div>
  <div class="comparison-control" data-comparison-control hidden>
   <label for="shared-usage"><?= e($t('comparison.control')) ?><output for="shared-usage" data-overhead>35 GB</output></label>
   <input id="shared-usage" type="range" min="0" max="80" step="1" value="35" aria-describedby="comparison-hint comparison-result">
   <p id="comparison-hint"><?= e($t('comparison.hint')) ?></p>
   <p id="comparison-result" class="sr-only" aria-live="polite" data-result data-result-template="<?= e($t('comparison.result',['media'=>'{media}','total'=>'{total}'])) ?>"><?= e($t('comparison.result',['media'=>$exampleMedia,'total'=>$sharedTotal])) ?></p>
  </div>
  <p class="comparison-disclaimer"><?= e($t('comparison.example')) ?></p>
 </div>
</section>
