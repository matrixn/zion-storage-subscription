<section id="integrations"><h2>API-uri Stripe și Oblio</h2>
<p>După salvarea unui serviciu, întreaga configurație a acestuia se citește din administrare, inclusiv valorile goale și opțiunile dezactivate. Setările acelui serviciu din .env sunt ignorate. Cheile sunt criptate în baza de date cu APP_KEY.</p>
<p>La prima salvare, introdu cheile pe care vrei să le folosești. Cheile salvate afișează primele 7 și ultimele 6 caractere, cu mijlocul mascat. Pentru înlocuire, selectează întreaga valoare și introdu cheia nouă completă. Valoarea mascată neschimbată sau un câmp gol păstrează cheia salvată; bifează ștergerea pentru a o elimina. Salvarea Stripe testează conexiunea și sincronizează automat produsele și prețurile. Salvarea Oblio validează local configurația.</p>
<nav class="filter-tabs" aria-label="Configurație Stripe"><?php foreach(['test'=>'TEST · Sandbox','live'=>'LIVE · Plăți reale'] as $mode=>$label): ?><a href="/admin?section=integrations&amp;stripe_mode=<?= $mode ?>" <?= $stripeMode===$mode?'aria-current="page"':'' ?>><?= e($label) ?><?= $stripeActiveMode===$mode?' · Activ':'' ?></a><?php endforeach ?></nav>
<details open><summary>Stripe <?= strtoupper($stripeMode) ?> · <?= $stripeSaved?'Configurat din administrare':'.env (până la prima salvare)' ?></summary>
<p>Modul activ pe site: <strong><?= strtoupper($stripeActiveMode) ?></strong>. Configurezi acum <strong><?= strtoupper($stripeMode) ?></strong>. Cheile, prețurile și verificările sunt separate între TEST și LIVE.</p>
<p>3 produse și 6 prețuri recurente sunt create sau reutilizate automat în modul selectat: Personal, Plus și Titan, lunar și anual. Nu trebuie să completezi Product ID sau Price ID. Salvarea cheilor nu schimbă modul activ.</p>
<?php require __DIR__.'/admin-stripe-status.php'; ?>
<p>Endpoint webhook <?= strtoupper($stripeMode) ?>: <code><?= e(base_url().'/webhooks/stripe?mode='.$stripeMode) ?></code>. Evenimente: checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted.</p>
<form method="post" class="integration-form">
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="settings_save"><input type="hidden" name="provider" value="stripe"><input type="hidden" name="credential_mode" value="<?= $stripeMode ?>">
<?php foreach(['secret_key'=>'Cheie API secretă Stripe (sk_'.$stripeMode.'_ / rk_'.$stripeMode.'_)','webhook_secret'=>'Secret semnare webhook (whsec_)'] as $key=>$label): ?>
<label><?= e($label) ?><input type="text" name="<?= $key ?>" autocomplete="off" spellcheck="false" autocapitalize="none" maxlength="2048" value="<?= e($stripeSaved?Zion\IntegrationSettings::maskedSecret($stripeSettings[$key]):'') ?>" placeholder="Introdu cheia pentru administrare"></label>
<?php if($key==='secret_key'): ?>
<small>În <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer">Stripe → API keys</a>, selectează <?= $stripeMode==='test'?'sandboxul folosit la testare':'modul LIVE' ?> și copiază cheia secretă <code>sk_<?= $stripeMode ?>_…</code> sau o cheie restricționată <code>rk_<?= $stripeMode ?>_…</code> cu permisiunile necesare integrării. Cheia publică <code>pk_…</code> nu se folosește aici. <a href="https://docs.stripe.com/keys" target="_blank" rel="noopener noreferrer">Ghid oficial</a>.</small>
<?php else: ?>
<small>În <a href="https://dashboard.stripe.com/webhooks" target="_blank" rel="noopener noreferrer">Stripe → Workbench → Webhooks</a>, selectează același mediu <?= strtoupper($stripeMode) ?>, creează o destinație cu endpointul și evenimentele afișate mai sus, apoi deschide destinația și apasă <strong>Reveal secret</strong> la <strong>Signing secret</strong>. Copiază valoarea <code>whsec_…</code>. <a href="https://docs.stripe.com/events/manage-webhook-endpoints#check-the-endpoint-secret" target="_blank" rel="noopener noreferrer">Ghid oficial</a>.</small>
<?php if($stripeMode==='test'): ?><small>Pentru localhost, rulează <code>stripe listen --forward-to http://localhost:8088/webhooks/stripe?mode=test</code> în sandboxul potrivit și folosește secretul <code>whsec_…</code> afișat de CLI; acesta diferă de secretul destinației din Dashboard.</small><?php endif ?>
<?php endif ?>
<label class="check"><input type="checkbox" name="clear_<?= $key ?>" value="1"> Șterge cheia salvată pentru <?= e($label) ?></label>
<?php endforeach ?>
<button>Salvează, testează și sincronizează <?= strtoupper($stripeMode) ?></button></form>
<?php if($stripeActiveMode!==$stripeMode): ?><form method="post" class="integration-form"><input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="credential_mode" value="<?= $stripeMode ?>"><p><?= $stripeMode==='live'?'Activarea LIVE permite plăți reale pentru înregistrările noi. Abonamentele de test rămân în TEST.':'Activarea TEST pornește plățile sandbox pentru înregistrările noi. Abonamentele LIVE existente continuă în LIVE.' ?></p><button name="action" value="stripe_activate">Activează <?= strtoupper($stripeMode) ?> pe site</button></form><?php endif ?>
</details>
<details><summary>Oblio · <?= $oblioSaved?'Configurat din administrare':'.env (până la prima salvare)' ?></summary>
<p>Activarea emite documente fiscale reale. Pentru plăți Stripe de test, permisiunea separată de mai jos trebuie activată explicit. Fără emitere activată, documentele rămân simulări locale.</p>
<form method="post" class="integration-form">
<input type="hidden" name="_csrf" value="<?= e(Zion\Security::csrf()) ?>"><input type="hidden" name="action" value="settings_save"><input type="hidden" name="provider" value="oblio">
<label class="check"><input type="checkbox" name="enabled" value="1" <?= $oblioSettings['enabled']?'checked':'' ?>> Activează emiterea reală în Oblio</label>
<label>Email cont Oblio<input type="email" name="email" maxlength="254" value="<?= e($oblioSettings['email']) ?>" autocomplete="off"></label>
<small>Adresa de email cu care te autentifici în Oblio; este identificatorul API <code>client_id</code>.</small>
<label>Secret API Oblio<input type="text" name="secret" maxlength="2048" autocomplete="off" spellcheck="false" autocapitalize="none" value="<?= e($oblioSaved?Zion\IntegrationSettings::maskedSecret($oblioSettings['secret']):'') ?>" placeholder="Introdu secretul pentru administrare"></label>
<small>În contul Oblio, deschide <strong>Setări → Date Cont</strong> și copiază tokenul <code>client_secret</code>. Nu introduce parola contului sau un <code>access_token</code> temporar. Secretul se regenerează când resetezi parola Oblio și trebuie actualizat aici. <a href="https://www.oblio.eu/api/" target="_blank" rel="noopener noreferrer">Documentație API Oblio</a>.</small>
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
