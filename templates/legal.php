<?php
$document=in_array($_GET['document']??'',['privacy','terms','cookies'],true)?$_GET['document']:'privacy';
$operator=Zion\LegalDocuments::operator();
$text=Zion\LegalDocuments::read($locale,$document);
$sections=[];
foreach($text ? preg_split('/\n\s*\n/u',trim($text)) : [] as $block){
    $lines=explode("\n",$block);
    $heading=preg_match('/^(\d+)\. (.+)$/u',$lines[0],$match);
    if($heading){array_shift($lines);}
    $sections[]=['number'=>$heading?$match[1]:'','title'=>$heading?$match[2]:'','body'=>implode("\n",$lines)];
}
$icons=[
    'privacy'=>'<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    'terms'=>'<path d="M14 3H6v18h12V7l-4-4Z"/><path d="M14 3v5h4M9 12h6M9 16h6"/>',
    'cookies'=>'<path d="M21 12a9 9 0 1 1-9-9 4 4 0 0 0 5 5 4 4 0 0 0 4 4Z"/><path d="M8 9h.01M7 14h.01M12 17h.01M14 12h.01"/>',
];
?>
<section class="wrap legal-page legal-document" id="legal-top">
    <header class="legal-heading">
        <div class="legal-hero-copy">
            <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span>ZION STORAGE / <?= e($t('legal.title')) ?></p>
            <h1><?= e($t('legal.'.$document)) ?></h1>
            <p class="legal-intro"><?= e($t('legal.'.$document.'_intro')) ?></p>
            <p class="legal-version"><span aria-hidden="true">↻</span><?= e($t('legal.updated')) ?> <time datetime="<?= Zion\LegalDocuments::VERSION ?>"><?= Zion\LegalDocuments::VERSION ?></time></p>
        </div>
        <div class="legal-emblem" aria-hidden="true"><span class="legal-emblem-orbit"></span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$document] ?></svg><span class="legal-emblem-caption">ZION / <?= strtoupper($document) ?></span></div>
    </header>
    <nav class="legal-nav" aria-label="<?= e($t('legal.title')) ?>">
        <?php foreach(['privacy','terms','cookies'] as $index=>$doc): ?>
        <a href="<?= $url('legal') ?>?document=<?= $doc ?>" <?= $doc===$document?'aria-current="page"':'' ?>><span class="legal-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$doc] ?></svg></span><span><?= e($t('legal.'.$doc)) ?></span><span class="legal-nav-arrow" aria-hidden="true"><?= $doc===$document?'↘':'↗' ?></span></a>
        <?php endforeach ?>
    </nav>
    <div class="legal-layout">
        <aside class="legal-sidebar">
            <details class="legal-toc" open>
                <summary><?= e($t('legal.contents')) ?><span aria-hidden="true">⌄</span></summary>
                <nav aria-label="<?= e($t('legal.contents')) ?>"><?php foreach($sections as $index=>$section): if(!$section['title'])continue; ?><a href="#legal-section-<?= $index+1 ?>"><span><?= e(str_pad($section['number'],2,'0',STR_PAD_LEFT)) ?></span><?= e($section['title']) ?></a><?php endforeach ?></nav>
            </details>
            <div class="legal-operator"><p class="legal-label"><?= e($t('legal.operator')) ?></p><strong><?= e($operator['name']) ?></strong><p>CUI <?= e($operator['tax_id']) ?> · <?= e($operator['registration']) ?></p><p><?= e($operator['address']) ?></p><a href="mailto:<?= e($operator['email']) ?>"><?= e($operator['email']) ?></a><a href="tel:+40770675597"><?= e($operator['phone']) ?></a></div>
        </aside>
        <div class="legal-reading">
            <article class="legal-content"><?php if($text): foreach($sections as $index=>$section): ?><section id="legal-section-<?= $index+1 ?>"><?php if($section['title']): ?><h2><span class="legal-section-number"><?= e(str_pad($section['number'],2,'0',STR_PAD_LEFT)) ?></span><span><?= e($section['title']) ?></span></h2><?php endif ?><p><?= nl2br(e($section['body'])) ?></p></section><?php endforeach;else: ?><p><?= e($t('legal.pending')) ?></p><?php endif ?></article>
            <div class="legal-help"><span class="legal-help-mark" aria-hidden="true">↗</span><h2><?= e($t('legal.questions')) ?></h2><p><?= e($t('legal.help')) ?></p><a class="button small" href="<?= $url('contact') ?>"><?= e($t('legal.contact')) ?><span aria-hidden="true">↗</span></a><?php if($document==='terms'): ?><a class="legal-withdrawal" href="<?= $url('contact') ?>?request=withdrawal"><?= e($t('withdrawal.title')) ?> <span aria-hidden="true">↗</span></a><?php endif ?></div>
            <a class="legal-back" href="#legal-top"><?= e($t('legal.back')) ?> <span aria-hidden="true">↑</span></a>
        </div>
    </div>
</section>
