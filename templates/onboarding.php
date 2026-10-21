<section class="wrap flow-card">
 <p class="eyebrow">ZION STORAGE</p><h1><?= e($t('guide.title')) ?></h1>
 <p><?= e($t('guide.intro')) ?></p>
 <?php if($error): ?><p role="alert"><?= e($t($error)) ?></p><?php endif ?>
 <form method="post" data-guide-entry>
  <input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>">
  <label><?= e($t('guide.input')) ?><input name="token" type="text" required autocomplete="off" spellcheck="false" data-guide-token></label>
  <button class="button"><?= e($t('guide.continue')) ?> ↗</button>
 </form>
</section>
