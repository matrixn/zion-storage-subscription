<section><p class="eyebrow">CATALOG / RON</p><h2>Denumiri, dimensiuni și prețuri pachete</h2><p>Editează dimensiunea în GB și prețul contractual în RON. Prețul anual se calculează automat la 11 luni, cu o lună gratuită. Echivalentul EUR folosește cursul BNR configurat.</p><p class="micro">Denumirile se actualizează pe site și în Stripe pentru ambele medii. Dimensiunile și prețurile noi se aplică abonamentelor noi. Abonamentele existente păstrează capacitatea și condițiile monetare cumpărate, inclusiv la reînnoire, până când sunt înlocuite printr-o comandă nouă. Facturile emise rămân neschimbate.</p><div class="quick-links"><a href="/admin?section=catalog">Starea catalogului Stripe ↗</a><a href="/admin?section=exchange">Curs BNR / RON ↗</a></div></section>
<div class="plan-editor-grid">
<?php foreach($plans as $p):
 $draft=($error && ($_POST['plan']??'')===$p['slug'])?$_POST:[];
 $name=is_string($draft['name']??null)?$draft['name']:$p['name'];
 $amount=is_string($draft['monthly_price']??null)?$draft['monthly_price']:number_format($p['monthly_price']/100,2,'.','');
?>
<section class="plan-editor"><p class="eyebrow"><?= e($p['capacity']) ?> · <?= e($p['slug']) ?></p><h2><?= e($p['name']) ?></h2><form method="post" data-plan-editor data-fx-rate="<?= (int)($p['fx']['eur_ron']??0) ?>">
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="plan_save"><input type="hidden" name="price_currency" value="ron"><input type="hidden" name="plan" value="<?= e($p['slug']) ?>">
<label>Denumirea pachetului<input name="name" value="<?= e($name) ?>" minlength="2" maxlength="60" required></label>
<label>Dimensiune · GB<input name="storage_gb" type="number" min="1" max="1000000" step="1" value="<?= e(is_string($draft['storage_gb']??null)?$draft['storage_gb']:(string)intdiv($p['storage_bytes'],1_000_000_000)) ?>" required></label>
<label>Preț lunar · RON<input name="monthly_price" type="text" inputmode="decimal" value="<?= e($amount) ?>" pattern="[0-9]+([.,][0-9]{1,2})?" maxlength="7" required <?= $p['slug']==='free'?'readonly':'' ?> aria-describedby="price-help-<?= e($p['slug']) ?>"></label>
<p id="price-help-<?= e($p['slug']) ?>" class="micro"><?= $p['slug']==='free'?'Pachetul gratuit rămâne gratuit.':'Folosește punct sau virgulă: de exemplu 21,99 (minimum 2,00 RON). Prețul anual rămâne 11 × prețul lunar.' ?></p>
<p class="plan-annual-preview">Anual: <strong><output data-plan-annual><?= e(number_format($p['yearly_price']/100,2,',','')) ?> RON</output></strong><small> · <?= $p['slug']==='free'?'gratuit permanent':'o lună gratuită' ?></small></p>
<p class="micro">La cursul curent: <output data-plan-eur-month><?= $p['eur_monthly']===null?'Indisponibil':e($money($p['eur_monthly'],'eur')) ?></output> / lună · <output data-plan-eur-year><?= $p['eur_yearly']===null?'Indisponibil':e($money($p['eur_yearly'],'eur')) ?></output> / an</p>
<button>Salvează și sincronizează</button></form></section>
<?php endforeach ?>
</div>
