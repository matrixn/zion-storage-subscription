<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Zion Storage</title>
<meta name="description" content="<?= e($description) ?>">
<meta property="og:title" content="<?= e($title) ?> · Zion Storage"><meta property="og:description" content="<?= e($description) ?>">
<meta property="og:type" content="website"><meta property="og:url" content="<?= e(base_url().$url($canonicalPage)) ?>"><meta property="og:image" content="<?= e(base_url()) ?>/assets/og.png"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<link rel="apple-touch-icon" href="/assets/app-icon.png">
<link rel="canonical" href="<?= e(base_url().$url($canonicalPage)) ?>">
<?php foreach(Zion\TranslationService::LOCALES as $l): ?><link rel="alternate" hreflang="<?= $l ?>" href="<?= e(base_url().$url($canonicalPage,$l)) ?>"><?php endforeach ?>
<link rel="alternate" hreflang="x-default" href="<?= e(base_url().$url($canonicalPage,'en')) ?>">
<?php if(!in_array($page,['home','how','register','contact'])): ?><meta name="robots" content="noindex,nofollow"><?php endif ?>
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/site.css"><script src="/assets/site.js" defer></script>
<?php if($page==='home'): ?><link rel="stylesheet" href="/assets/pricing.css"><?php endif ?>
<?php if(env('CAPTCHA_ENABLED')==='true'): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif ?>
</head>
<body>
<a class="skip" href="#main"><?= e($t('skip')) ?></a>
<header class="header wrap">
<a class="brand" href="<?= $url('home') ?>" aria-label="Zion Storage"><img src="/assets/icon.svg" width="36" height="36" alt="">zion<span>storage</span><i></i></a>
<button class="menu-toggle" aria-expanded="false" aria-controls="navigation" aria-label="<?= e($t('menu')) ?>"><span></span><span></span></button>
<nav id="navigation" aria-label="<?= e($t('nav.home')) ?>">
<?php foreach(['home','how','pricing','contact'] as $p): ?><a <?= $page===$p?'aria-current="page"':'' ?> href="<?= $p==='pricing'?$url('home').'#pricing':$url($p) ?>"><?= e($t('nav.'.$p)) ?></a><?php endforeach ?>
<div class="languages" aria-label="<?= e($t('language')) ?>"><?php foreach(Zion\TranslationService::LOCALES as $l): ?><a lang="<?= $l ?>" hreflang="<?= $l ?>" class="<?= $locale===$l?'selected':'' ?>" href="<?= $url($canonicalPage,$l) ?>"><?= strtoupper($l) ?></a><?php endforeach ?></div>
<a class="button small" href="<?= $url('register') ?>?plan=free"><?= e($t('start')) ?><span>↗</span></a>
</nav>
</header>
<main id="main"><?php if($error): ?><div class="wrap alert" role="alert"><?= e($t($error)) ?></div><?php endif ?><?= $content ?></main>
<footer class="wrap footer">
<div><a class="brand" href="<?= $url('home') ?>"><img src="/assets/icon.svg" width="32" height="32" alt="">zion<span>storage</span></a><p><?= e($t('footer.tag')) ?></p></div>
<div class="footer-links"><?php foreach(['home','how','contact'] as $p): ?><a href="<?= $url($p) ?>"><?= e($t('nav.'.$p)) ?></a><?php endforeach ?></div>
<div class="footer-links"><?php foreach(['privacy','terms','cookies'] as $p): ?><a href="<?= $url('legal') ?>?document=<?= $p ?>"><?= e($t('legal.'.$p)) ?></a><?php endforeach ?></div>
<div class="footer-bottom"><span>© <?= date('Y') ?> Zion Storage. <?= e($t('footer.rights')) ?></span><div class="languages"><?php foreach(Zion\TranslationService::LOCALES as $l): ?><a href="<?= $url($canonicalPage,$l) ?>" lang="<?= $l ?>"><?= strtoupper($l) ?></a><?php endforeach ?></div></div>
</footer>
</body></html>
