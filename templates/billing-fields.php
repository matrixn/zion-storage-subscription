<fieldset class="billing-fields" data-billing>
<legend><?= e($t('billing.title')) ?></legend>
<p class="micro"><?= e($t('billing.intro')) ?></p>
<label for="billing-type"><?= e($t('billing.type')) ?></label>
<select id="billing-type" name="billing[type]" data-billing-type><option value="individual" <?= ($billing['type']??'individual')==='individual'?'selected':'' ?>><?= e($t('billing.individual')) ?></option><option value="company" <?= ($billing['type']??'')==='company'?'selected':'' ?>><?= e($t('billing.company')) ?></option></select>
<?php foreach(['name','billing_email','country','county','city','address','postal_code','phone','company_name','tax_id','registration_number'] as $field):
$companyField=in_array($field,['company_name','tax_id','registration_number'],true);
$value=$billing[$field]??($field==='name'?($user['name']??$_POST['name']??''):($field==='billing_email'?($user['email']??$_POST['email']??''):''));
$value=is_string($value)?$value:'';
?>
<div <?= $companyField?'data-company-field':'' ?>>
<label for="billing-<?= $field ?>"><?= e($t('billing.'.$field)) ?></label>
<input id="billing-<?= $field ?>" name="billing[<?= $field ?>]" value="<?= e($value) ?>" type="<?= $field==='billing_email'?'email':'text' ?>" maxlength="<?= $field==='address'?500:($field==='billing_email'?254:($field==='country'?2:($field==='tax_id'?40:($field==='registration_number'?80:($field==='phone'?40:($field==='company_name'?200:150)))))) ?>" <?= in_array($field,['phone','registration_number'],true)?'':'data-billing-required' ?> <?= $field==='country'?'pattern="[A-Za-z]{2}"':'' ?> autocomplete="<?= e(['name'=>'billing name','billing_email'=>'billing email','country'=>'billing country','county'=>'billing address-level1','city'=>'billing address-level2','address'=>'billing street-address','postal_code'=>'billing postal-code','phone'=>'billing tel','company_name'=>'billing organization'][$field]??'off') ?>">
</div>
<?php endforeach ?>
</fieldset>
