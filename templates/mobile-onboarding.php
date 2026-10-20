<section class="wrap mobile-onboarding" data-onboarding>
 <header class="on-heading"><p class="on-eyebrow"><span aria-hidden="true">✳</span> <?= e($t('on.tag')) ?></p><h1><?= e($t('on.ready')) ?></h1><p><?= e($t('on.intro')) ?></p></header>
 <nav class="on-steps" aria-label="<?= e($t('status.onboard')) ?>"><?php foreach(['install','connect','backup'] as $i=>$step): ?><a href="#on-<?= $step ?>"><span><?= $i+1 ?></span><?= e($t('on.'.$step)) ?><b aria-hidden="true">↗</b></a><?php endforeach ?></nav>
 <div class="on-grid">
  <section class="on-card" id="on-install" aria-labelledby="on-install-title">
   <p class="on-kicker"><?= e($t('on.step')) ?> 01 <span aria-hidden="true">↗</span></p><h2 id="on-install-title"><?= e($t('on.install')) ?></h2><p><?= e($t('on.app')) ?></p>
   <div class="on-platforms" role="group" aria-label="<?= e($t('on.device')) ?>" data-platform-selector hidden><button type="button" data-platform="android" aria-pressed="false">Android</button><button type="button" data-platform="ios" aria-pressed="false">iPhone / iPad</button><button type="button" data-platform="both" aria-pressed="true"><?= e($t('on.both')) ?></button></div>
   <p class="on-desktop" data-desktop-help><?= e($t('on.desktop')) ?></p>
   <div class="on-stores"><?php foreach(['android'=>['Google Play',Zion\MobileOnboarding::ANDROID],'ios'=>['App Store',Zion\MobileOnboarding::IOS]] as $platform=>[$store,$href]): ?><div class="on-store" data-store="<?= $platform ?>"><img class="on-qr" src="/assets/onboarding/<?= $platform ?>.svg" width="144" height="144" loading="lazy" alt="<?= e($t('on.scan.'.$platform)) ?>"><a class="button full" href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"><span><small><?= e($t('on.'.$platform)) ?></small><?= $store ?></span><span aria-hidden="true">↗</span></a></div><?php endforeach ?></div>
  </section>
  <section class="on-card on-connect" id="on-connect" aria-labelledby="on-connect-title">
   <p class="on-kicker"><?= e($t('on.step')) ?> 02 <span aria-hidden="true">⌁</span></p><h2 id="on-connect-title"><?= e($t('on.connect')) ?></h2><p><?= e($t('on.server.help')) ?></p>
   <label for="on-server"><?= e($t('on.server')) ?></label><input id="on-server" class="on-server" type="text" readonly value="<?= e(Zion\MobileOnboarding::SERVER) ?>" spellcheck="false" autocapitalize="none">
   <button class="button full on-copy" type="button" data-copy-server hidden><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M15 5V4H4v11h1"/></svg><?= e($t('on.copy')) ?></button><p class="on-feedback" role="status" aria-live="polite" aria-atomic="true" data-copy-feedback data-success="<?= e($t('on.copied')) ?>" data-error="<?= e($t('on.copy.error')) ?>"></p>
   <dl class="on-account"><dt><?= e($t('on.email')) ?></dt><dd><?= e($user['email']) ?></dd></dl><p class="on-note"><?= e($t('on.password')) ?></p>
  </section>
  <section class="on-card on-backup" id="on-backup" aria-labelledby="on-backup-title">
   <div class="on-backup-heading"><div><p class="on-kicker"><?= e($t('on.step')) ?> 03 <span aria-hidden="true">↑</span></p><h2 id="on-backup-title"><?= e($t('on.backup')) ?></h2><p><?= e($t('on.backup.intro')) ?></p></div><span class="on-cloud" aria-hidden="true">↑</span></div>
   <ol class="on-instructions"><?php for($i=1;$i<=6;$i++): ?><li><?= e($t('on.instruction.'.$i)) ?></li><?php endfor ?></ol>
   <p class="on-tip"><span aria-hidden="true">↯</span><?= e($t('on.wifi')) ?></p>
   <?php foreach(['android','ios'] as $platform): ?><details class="on-help" data-help="<?= $platform ?>"><summary><?= e($t('on.'.$platform.'.help')) ?></summary><p><?= e($t('on.'.$platform.'.detail')) ?></p></details><?php endforeach ?>
   <?php if($onboarding['firstFile']!==null): ?><aside class="on-first" role="note"><strong><?= e($t($onboarding['firstFile']?'on.first.saved':'on.first.wait')) ?></strong><p><?= e($t('on.first.note')) ?></p></aside><?php endif ?>
  </section>
 </div>
 <p class="on-support"><a class="text-link" href="<?= $url('contact') ?>"><?= e($t('on.support')) ?> ↗</a></p>
</section>
