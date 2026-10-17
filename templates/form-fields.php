<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>">
<div class="honeypot" aria-hidden="true"><label><?= e($t('form.company')) ?><input name="company" tabindex="-1" autocomplete="off"></label></div>
<?php if(env('CAPTCHA_ENABLED')==='true'): ?><div class="cf-turnstile" data-sitekey="<?= e(env('CAPTCHA_SITE_KEY')) ?>" data-language="<?= e($locale) ?>"></div><?php endif ?>
