<?php $state=$onboarding['state']??'session'; ?>
<?php if($state==='ready'): require __DIR__.'/mobile-onboarding.php'; else: ?>
<section class="wrap flow-card on-state" data-onboarding-state="<?= e($state) ?>">
 <span class="flow-icon" aria-hidden="true">✳</span>
 <?php if($state==='suspended'): ?><h1><?= e($t('account.banned')) ?></h1><p><?= e($t('account.banned.info')) ?></p>
 <?php elseif($state==='inactive'): ?><h1><?= e($t('on.inactive')) ?></h1><p><?= e($t('on.inactive.info')) ?></p>
 <?php elseif($state==='provisioning' || ($state==='failed' && $automaticActivation)): ?>
  <h1><?= e($t('on.'.$state)) ?></h1><p role="status"><?= e($t('on.'.$state.'.info')) ?></p>
  <?php if($eligible): ?><form method="post" action="<?= $url('activate') ?>"<?= $state==='provisioning' && $activationDue?' data-auto-activation':'' ?>><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="automatic" value="1"><button class="button"><?= e($t('on.retry')) ?> ↻</button></form><?php endif ?>
 <?php elseif($state==='password' || ($state==='failed' && $eligible)): ?>
  <h1><?= e($t($state==='failed'?'on.failed':'status.activate')) ?></h1><p><?= e($t($state==='failed'?'on.failed.info':'status.password')) ?></p><form method="post" action="<?= $url('activate') ?>"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><label for="password"><?= e($t('form.password')) ?></label><input id="password" name="password" type="password" autocomplete="current-password" required><?php if(!$user['terms_at'] || !$user['privacy_at']): ?><label><input name="accept" type="checkbox" value="1" required><?= e($t('account.accept')) ?></label><?php endif ?><button class="button full"><?= e($t('continue')) ?> ↗</button></form>
 <?php elseif($state==='payment'): ?><h1><?= e($t('on.payment')) ?></h1><p><?= e($t('on.payment.info')) ?></p>
 <?php else: ?><h1><?= e($t('status.title')) ?></h1><p><?= e($t('status.verify')) ?></p><?php endif ?>
 <?php if(in_array($state,['provisioning','payment'],true)): ?><div data-status-poll><p class="on-note" role="status" data-poll-feedback data-stopped="<?= e($t('on.poll.stopped')) ?>"><?= e($t('on.poll')) ?></p></div><a class="button outline" data-status-refresh href="<?= $url('status') ?>"><?= e($t('on.refresh')) ?> ↻</a><?php endif ?>
 <p><a class="text-link" href="<?= $url('contact') ?>"><?= e($t('on.support')) ?> ↗</a></p>
</section>
<?php endif ?>
<?php if($user && $user['selected_plan_id']!=='free'): ?><div class="wrap billing-shortcut"><form method="post" action="<?= $url('portal') ?>"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><button class="text-link"><?= e($t('status.billing')) ?> ↗</button></form><a class="text-link" href="<?= $url('billing-profile') ?>"><?= e($t('billing.title')) ?> · <?= e($t('billing.history')) ?> ↗</a></div><?php endif ?>
<?php if($user && ($upgradeOffers || $latestChange)): ?><div class="wrap billing-shortcut"><a class="button outline" href="<?= $url('upgrade') ?>"><?= e($t('upgrade.title')) ?> ↗</a></div><?php endif ?>
