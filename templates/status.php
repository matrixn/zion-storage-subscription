<section class="wrap flow-card"><span class="flow-icon">✳</span>
<?php if($user && $user['storage_user_id'] && $user['status']==='active'): ?>
<h1><?= e($t('status.ready')) ?></h1><p><?= e($t('status.onboard')) ?></p><ol class="onboarding"><?php foreach([4,5,6] as $i): ?><li><?= e($t('step.'.$i.'.desc')) ?></li><?php endforeach ?></ol>
<?php if(env('MOBILE_SERVICE_URL')): ?><p><?= e($t('status.service')) ?>: <strong><?= e(env('MOBILE_SERVICE_URL')) ?></strong></p><?php endif ?>
<div class="actions"><?php foreach(['IOS','ANDROID'] as $platform): if(env('MOBILE_'.$platform.'_URL')): ?><a class="button outline" href="<?= e(env('MOBILE_'.$platform.'_URL')) ?>"><?= e($t('status.download')) ?> · <?= $platform==='IOS'?'iOS':'Android' ?> ↗</a><?php endif; endforeach ?></div>
<?php elseif($user && $eligible): ?><h1><?= e($t('status.activate')) ?></h1><p><?= e($t('status.password')) ?></p><form method="post" action="<?= $url('activate') ?>"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><label for="password"><?= e($t('form.password')) ?></label><input id="password" name="password" type="password" autocomplete="current-password" required><button class="button full"><?= e($t('continue')) ?> ↗</button></form>
<?php else: ?><h1><?= e($t('status.title')) ?></h1><p><?= e($t($user && $user['email_verified_at']?'status.wait':'status.verify')) ?></p><a class="button" href="<?= $url('status') ?>"><?= e($t('continue')) ?> ↻</a><?php endif ?>
<?php if($user && $user['selected_plan_id']!=='free'): ?><form class="recovery" method="post" action="<?= $url('portal') ?>"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><button class="text-link"><?= e($t('status.billing')) ?> ↗</button></form><?php endif ?>
</section>
