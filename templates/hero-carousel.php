<?php $heroPersonal=current(array_filter($plans,static fn(array $p):bool=>$p['recommended'])); ?>
<div class="hero-carousel" data-hero-carousel role="region" aria-roledescription="<?= e($t('carousel.role')) ?>" aria-label="<?= e($t('carousel.label')) ?>">
 <div class="hero-scenes" data-carousel-viewport>
  <div class="hero-scene" id="hero-backup" data-hero-scene data-title="<?= e($t('carousel.backup')) ?>" role="group" aria-roledescription="<?= e($t('carousel.slide')) ?>" aria-label="<?= e($t('carousel.position',['current'=>1,'total'=>2,'title'=>$t('carousel.backup')])) ?>">
   <?php require __DIR__.'/hero-backup.php'; ?>
  </div>
  <div class="hero-scene hero-comparison-scene" id="hero-comparison" data-hero-scene data-title="<?= e($t('carousel.compare')) ?>" role="group" aria-roledescription="<?= e($t('carousel.slide')) ?>" aria-label="<?= e($t('carousel.position',['current'=>2,'total'=>2,'title'=>$t('carousel.compare')])) ?>" hidden>
   <div class="hero-comparison-panel">
    <h2><?= e($t('carousel.title')) ?></h2>
    <div class="hero-storage-card hero-shared-card">
     <div class="hero-storage-heading"><div><strong>Google Photos</strong><small><?= e($t('carousel.shared')) ?></small></div><span>100 GB</span></div>
     <div class="hero-capacity-bar" aria-hidden="true"><i class="hero-media-segment"></i><i class="hero-files-segment"></i><i class="hero-email-segment"></i></div>
     <ul class="hero-capacity-legend"><li><i class="hero-media-dot" aria-hidden="true"></i><?= e($t('carousel.media')) ?> <b>65 GB</b></li><li><i class="hero-files-dot" aria-hidden="true"></i>Drive <b>20 GB</b></li><li><i class="hero-email-dot" aria-hidden="true"></i>Gmail <b>15 GB</b></li></ul>
    </div>
    <div class="hero-storage-card hero-zion-card">
     <div class="hero-storage-heading"><div class="hero-zion-name"><img src="/assets/icon.png" width="32" height="32" alt=""><div><strong>Zion Storage</strong><small><?= e($heroPersonal['name']) ?></small></div></div><span><?= e($heroPersonal['capacity']) ?></span></div>
     <div class="hero-capacity-bar" aria-hidden="true"><i class="hero-zion-segment"></i></div>
     <p><?= e($t('carousel.dedicated')) ?></p>
    </div>
    <p class="hero-example-note"><?= e($t('carousel.note')) ?></p>
    <a class="text-link" href="#pricing"><?= e($t('carousel.details')) ?> <span aria-hidden="true">↗</span></a>
   </div>
  </div>
 </div>
 <div class="hero-carousel-controls" data-carousel-controls hidden>
  <button class="scene-arrow" type="button" data-scene-previous aria-label="<?= e($t('carousel.previous')) ?>"><span aria-hidden="true">←</span></button>
  <div class="scene-picker"><button type="button" data-scene-select="0" aria-controls="hero-backup" aria-pressed="true"><?= e($t('carousel.backup')) ?></button><button type="button" data-scene-select="1" aria-controls="hero-comparison" aria-pressed="false"><?= e($t('carousel.compare')) ?></button></div>
  <button class="scene-arrow" type="button" data-scene-next aria-label="<?= e($t('carousel.next')) ?>"><span aria-hidden="true">→</span></button>
  <div class="scene-progress-track" aria-hidden="true"><span data-scene-progress></span></div>
 </div>
 <span class="sr-only" data-scene-status aria-live="polite" aria-atomic="true" data-status-template="<?= e($t('carousel.position',['current'=>'{current}','total'=>'{total}','title'=>'{title}'])) ?>"></span>
</div>
