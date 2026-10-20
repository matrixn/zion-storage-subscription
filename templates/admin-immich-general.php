<div class="quick-links"><a href="/admin?section=immich">API și statistici Immich ↗</a><a href="/admin?section=api-log&amp;provider=immich">Jurnal API Immich ↗</a></div>
<section class="immich-settings">
 <div class="panel-heading"><div><p class="eyebrow">TOATE CONTURILE</p><h2>Funcțiile serverului Immich</h2><p>Starea curentă este citită din Immich. Poți activa sau dezactiva aceste funcții oricând, apoi salvezi explicit. Celelalte setări ale serverului sunt păstrate.</p></div></div>
 <?php if($immichGeneralError): ?><p class="alert" role="alert"><?= e($immichGeneralError) ?></p><a class="button secondary" href="/admin?section=immich-general">Reîncarcă starea serverului</a>
 <?php elseif($immichServer): ?>
 <form method="post"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="immich_general_server"><input type="hidden" name="revision" value="<?= e($immichServer['revision']) ?>">
 <div class="immich-options">
 <?php foreach([
  'machine_learning'=>['Machine Learning','Motorul comun pentru recunoașterea facială și căutarea inteligentă. Când este oprit, aceste două funcții nu procesează fișiere, indiferent de opțiunile lor individuale.'],
  'facial_recognition'=>['Recunoaștere facială','Detectează și grupează fețe pentru toate conturile. Necesită Machine Learning activ. Oprirea nu șterge metadatele faciale deja generate.'],
  'smart_search'=>['Căutare inteligentă','Analiză semantică pentru căutarea fotografiilor după conținut. Necesită Machine Learning activ.'],
  'map'=>['Hartă și locații','Afișează harta folosind coordonatele din metadatele fișierelor. Oprirea hărții nu elimină coordonatele din fotografii.'],
 ] as $key=>[$label,$description]): ?>
  <label class="immich-option"><input type="hidden" name="features[<?= $key ?>]" value="0"><input type="checkbox" name="features[<?= $key ?>]" value="1" <?= $immichServer['flags'][$key]?'checked':'' ?>><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small><em>Stare salvată: <?= $immichServer['flags'][$key]?'activată':'oprită' ?></em></span></label>
 <?php endforeach ?>
 </div>
 <p class="micro">Oprirea funcțiilor nu șterge fotografii, videoclipuri sau date deja procesate. Pentru recunoaștere facială vizibilă conturilor noi, activează și „Persoane” mai jos. Harta și celelalte opțiuni pot fi controlate independent.</p>
 <button>Salvează funcțiile serverului</button>
 </form>
 <?php endif ?>
 <p class="micro">Cheia API de administrator necesită permisiunile <code>systemConfig.read</code> și <code>systemConfig.update</code>. Dacă serverul folosește <code>IMMICH_CONFIG_FILE</code>, modificarea prin API este blocată de Immich; pagina afișează eroarea în jurnal.</p>
</section>
<section class="immich-settings">
 <p class="eyebrow">LA CREAREA CONTULUI</p><h2>Preferințe implicite pentru clienții noi</h2><p>Aceste valori sunt salvate și verificate înainte de finalizarea onboardingului Immich. Nu se aplică retroactiv clienților deja activați. Clienții le pot modifica ulterior în Immich.</p>
 <form method="post"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="immich_general_defaults">
 <div class="immich-options"><?php foreach([
  'cast'=>['Google Cast','Permite funcția de transmitere pe dispozitive Cast. Profilul orientat spre confidențialitate o păstrează oprită.'],
  'people'=>['Persoane','Activează secțiunea Persoane și intrarea ei în meniu. Nu controlează analiza facială globală a serverului.'],
  'memories'=>['Amintiri','Afișează amintirile în experiența clientului. Restul preferințelor păstrează valorile standard Immich.'],
 ] as $key=>[$label,$description]): ?><label class="immich-option"><input type="hidden" name="defaults[<?= $key ?>]" value="0"><input type="checkbox" name="defaults[<?= $key ?>]" value="1" <?= $immichDefaults[$key]?'checked':'' ?>><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small><em>Implicit salvat: <?= $immichDefaults[$key]?'activat':'oprit' ?></em></span></label><?php endforeach ?></div>
 <button>Salvează preferințele conturilor noi</button>
 </form>
 <p class="micro">Tema și limba sunt preferințe locale ale browserului/aplicației, nu opțiuni configurabile prin API-ul contului în Immich 3.3.3. Albumele de backup și permisiunile telefonului rămân sub controlul clientului.</p>
</section>
