<section class="wrap form-page"><div class="form-intro"><p class="eyebrow">ZION / <?= e($t('start')) ?></p><h1><?= e($t('register.title')) ?></h1><p class="lead"><?= e($t('register.desc')) ?></p><div class="intro-orbit"><img src="/assets/icon.png" width="92" height="92" alt=""></div><p class="micro"><?= e($t('hero.note')) ?></p></div><div class="form-card">
<ol class="form-steps"><li class="active">01 <span><?= e($t('register.step1')) ?></span></li><li>02 <span><?= e($t('register.step2')) ?></span></li><li>03 <span><?= e($t('register.step3')) ?></span></li></ol>
<form method="post" action="<?= $url('register') ?>" data-registration>
<?php require __DIR__.'/form-fields.php' ?>
<fieldset data-details><legend class="sr-only"><?= e($t('register.step1')) ?></legend>
<label for="name"><?= e($t('form.name')) ?></label><input id="name" name="name" autocomplete="name" minlength="2" maxlength="150" value="<?= e($_POST['name']??'') ?>" required>
<label for="email"><?= e($t('form.email')) ?></label><input id="email" type="email" name="email" autocomplete="email" maxlength="254" value="<?= e($_POST['email']??'') ?>" required>
<div class="two-fields"><div><label for="password"><?= e($t('form.password')) ?></label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" aria-describedby="password-help" required></div><div><label for="confirm"><?= e($t('form.confirm')) ?></label><input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></div></div><p id="password-help" class="micro"><?= e($t('form.password.help')) ?></p>
<label for="locale"><?= e($t('form.language')) ?></label><select id="locale" name="locale"><?php foreach(['ro'=>'Română','en'=>'English','fr'=>'Français','de'=>'Deutsch'] as $l=>$name): ?><option value="<?= $l ?>" <?= $l===$locale?'selected':'' ?>><?= $name ?></option><?php endforeach ?></select></fieldset>
<fieldset data-plan><legend><?= e($t('register.step2')) ?></legend>
<label for="plan"><?= e($t('form.plan')) ?></label><select id="plan" name="plan"><?php foreach($plans as $p): ?><option value="<?= $p['slug'] ?>" <?= $selected===$p['slug']?'selected':'' ?>><?= e($p['name'].' · '.$p['capacity'].' · '.number_format($p['monthly_price']/100,2).' RON / '.$t('month')) ?></option><?php endforeach ?></select>
<p class="micro"><?= e($t('pricing.note')) ?></p>
<?php require __DIR__.'/billing-fields.php' ?>
<label class="checkbox"><input type="checkbox" name="terms" required value="1"><span><?= e($t('form.terms')) ?> <a target="_blank" rel="noopener" href="<?= $url('legal') ?>?document=terms">↗</a></span></label>
<label class="checkbox"><input type="checkbox" name="privacy" required value="1"><span><?= e($t('form.privacy')) ?> <a target="_blank" rel="noopener" href="<?= $url('legal') ?>?document=privacy">↗</a></span></label>
<button class="button full" type="submit"><?= e($t('form.submit')) ?> ↗</button>
</fieldset>
<button class="button full review-button" type="button" hidden data-next><?= e($t('register.next')) ?> →</button><button type="button" class="text-link review-back" data-back hidden>← <?= e($t('back')) ?></button>
</form><a class="micro recovery" href="<?= $url('resend') ?>"><?= e($t('resend')) ?></a></div></section>
