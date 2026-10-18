<section id="integrations"><h2>API-uri Stripe și Oblio</h2>
<p>După salvarea unui serviciu, întreaga configurație a acestuia se citește din administrare, inclusiv valorile goale și opțiunile dezactivate. Setările acelui serviciu din .env sunt ignorate. Cheile sunt criptate în baza de date cu APP_KEY.</p>
<p>La prima salvare, introdu din nou cheile pe care vrei să le folosești. După salvare, un câmp secret gol păstrează cheia salvată; bifează ștergerea pentru a o elimina. Salvarea validează formatul, fără a contacta furnizorii.</p>
<details open><summary>Stripe · <?= $stripeSaved?'Configurat din administrare':'.env (până la prima salvare)' ?></summary>
<p>Această versiune funcționează în Stripe TEST MODE. Creează separat prețurile din catalogul de mai jos.</p>
<p>Endpoint webhook: <code><?= e(base_url().'/webhooks/stripe') ?></code>. Evenimente: checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted.</p>
<form method="post" class="integration-form">
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="settings_save"><input type="hidden" name="provider" value="stripe">
<?php foreach(['secret_key'=>'Cheie API secretă Stripe (sk_test_ / rk_test_)','webhook_secret'=>'Secret semnare webhook (whsec_)'] as $key=>$label): ?>
<label><?= e($label) ?><input type="password" name="<?= $key ?>" autocomplete="new-password" maxlength="2048" placeholder="<?= $stripeSaved&&$stripeSettings[$key]?'Salvată — lasă gol pentru păstrare':'Introdu cheia pentru administrare' ?>"></label>
<label class="check"><input type="checkbox" name="clear_<?= $key ?>" value="1"> Șterge cheia salvată pentru <?= e($label) ?></label>
<?php endforeach ?>
<button>Salvează configurația Stripe</button></form></details>
<details><summary>Oblio · <?= $oblioSaved?'Configurat din administrare':'.env (până la prima salvare)' ?></summary>
<p>Activarea emite documente fiscale reale. Pentru plăți Stripe de test, permisiunea separată de mai jos trebuie activată explicit. Fără emitere activată, documentele rămân simulări locale.</p>
<form method="post" class="integration-form">
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="settings_save"><input type="hidden" name="provider" value="oblio">
<label class="check"><input type="checkbox" name="enabled" value="1" <?= $oblioSettings['enabled']?'checked':'' ?>> Activează emiterea reală în Oblio</label>
<label>Email cont Oblio<input type="email" name="email" maxlength="254" value="<?= e($oblioSettings['email']) ?>" autocomplete="off"></label>
<label>Secret API Oblio<input type="password" name="secret" maxlength="2048" autocomplete="new-password" placeholder="<?= $oblioSaved&&$oblioSettings['secret']?'Salvat — lasă gol pentru păstrare':'Introdu secretul pentru administrare' ?>"></label>
<label class="check"><input type="checkbox" name="clear_secret" value="1"> Șterge secretul API salvat</label>
<div class="grid">
<?php foreach(['cif'=>'CIF firmă emitentă','series'=>'Serie facturi','workstation'=>'Punct de lucru (opțional)','vat_rate'=>'Cotă TVA (%)','vat_name'=>'Denumire cotă TVA'] as $key=>$label): ?>
<label><?= e($label) ?><input name="<?= $key ?>" maxlength="254" value="<?= e($oblioSettings[$key]) ?>"></label>
<?php endforeach ?>
<label>Limba documentelor<select name="language"><?php foreach(['RO','EN','FR','DE'] as $language): ?><option <?= $oblioSettings['language']===$language?'selected':'' ?>><?= $language ?></option><?php endforeach ?></select></label>
</div>
<label class="check"><input type="checkbox" name="allow_test_payments" value="1" <?= $oblioSettings['allow_test_payments']?'checked':'' ?>> Permite emiterea reală pentru plăți Stripe TEST</label>
<button>Salvează configurația Oblio</button></form></details>
</section>
