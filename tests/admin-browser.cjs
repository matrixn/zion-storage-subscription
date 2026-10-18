const path=require('node:path');const fs=require('node:fs');
let playwright;try{playwright=require(process.env.PLAYWRIGHT_MODULE||'playwright');}catch{playwright=require(path.join(path.dirname(process.execPath),'..','node_modules','playwright'));}
const origin='http://localhost:8093';const verify=process.argv[2];
if(!verify?.startsWith(origin+'/ro/verificare-email?token='))throw Error('Run through isolated tests/admin_http.py --browser');
let checks=0;function check(ok,label){if(!ok)throw Error(label);checks++;}
(async()=>{
 const browser=await playwright.chromium.launch({channel:process.env.BROWSER_CHANNEL||'chrome',headless:true});
 const context=await browser.newContext();const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(origin+'/admin');await page.locator('input[name=email]').fill('admin-fixture@example.com');await page.locator('input[name=password]').fill('fixture-admin-password');await page.getByRole('button',{name:'Intră în administrare'}).click();
 check(await page.locator('h1').textContent()==='Dashboard','browser administrator lands on dashboard');
 const artifacts=path.join(__dirname,'artifacts');fs.mkdirSync(artifacts,{recursive:true});
 const sections=['dashboard','clients','new-client','subscriptions','changes','invoices','operations','legal-requests','integrations','catalog','tax','immich','audit','api-log'];
 for(const width of [320,390,768,1440]){
  await page.setViewportSize({width,height:1000});
  for(const section of sections){
   const response=await page.goto(origin+'/admin?section='+section);
   check(response.status()===200,'admin section renders '+section+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'admin no page overflow '+section+width);
   if(width<761)await page.locator('label[for=admin-menu]').click();
   check(await page.locator('.sidebar a[aria-current=page]').count()===1,'one active navigation item '+section);
   check(await page.locator('.sidebar nav ul ul ul').count()===0,'menu has at most two levels');
   check(await page.locator('.sidebar nav a[aria-current=page]').isVisible(),'active item visible in vertical menu');
   const rects=await page.locator('.sidebar nav a').evaluateAll(els=>els.map(el=>el.getBoundingClientRect().top));
   check(rects.every((v,i)=>!i||v>=rects[i-1]),'navigation stays vertical');
   if(width<761)await page.locator('label[for=admin-menu]').click();
   if(section==='immich'){check(await page.locator('input[name=api_key]').inputValue()==='fixture••••••••123456','Immich saved key is masked in its field');check(await page.getByRole('button',{name:'Testează conexiunea Immich',exact:true}).isVisible(),'Immich connection test is available');await page.screenshot({path:path.join(artifacts,'admin-immich-'+width+'.png'),fullPage:true});}
   if(section==='dashboard'){
    check((await page.locator('.storage-ranking').innerText()).includes('3,00 GB / 120,00 GB'),'top user shows measured usage and quota');
    check((await page.locator('.storage-ranking').innerText()).includes('123'),'top user shows photo count');
    check(await page.locator('[data-metric=clients]').textContent()==='3','dashboard reflects actual clients');
    check((await page.locator('.revenue-values').innerText()).includes('1,99 EUR')&&(await page.locator('.revenue-values').innerText()).includes('9,99 RON'),'dashboard separates actual payment currencies');
    check(await page.locator('input[name=secret_key],input[name=password]').count()===0,'dashboard excludes management forms');
    await page.screenshot({path:path.join(artifacts,'admin-dashboard-'+width+'.png'),fullPage:true});
   }
   if(section==='tax'){check(await page.locator('input[name=vat_rate]').inputValue()==='21','VAT default visible');check((await page.locator('table').innerText()).includes('2,41 EUR'),'VAT preview includes gross total');}
   if(section==='new-client')check(await page.locator('input[name=password]').getAttribute('type')==='password','new customer password masked');
   if(section==='clients'){
    const pending=page.locator('tr').filter({hasText:'pending-browser@example.com'});
    await pending.getByText('Șterge contul TEST și tranzacțiile',{exact:true}).click();
    check(await pending.locator('input[name=confirm_email]').inputValue()==='' && await pending.locator('input[name=confirm_reset]').isChecked()===false,'TEST reset requires unfilled email and explicit checkbox');
    check(await pending.getByRole('button',{name:'Șterge definitiv contul TEST',exact:true}).isVisible(),'TEST reset is available in customer management');
    await pending.getByText('Șterge contul TEST și tranzacțiile',{exact:true}).click();
    await pending.getByRole('button',{name:'Vezi linkul de activare',exact:true}).click();
    const link=page.getByRole('textbox',{name:'Link de activare',exact:true});
    check((await link.inputValue()).startsWith(origin+'/ro/verificare-email?token=') && await link.getAttribute('readonly')!==null,'admin reveals copyable activation link through browser form');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'activation link panel fits viewport');
    await page.screenshot({path:path.join(artifacts,'admin-activation-link-'+width+'.png'),fullPage:false});
    await page.reload();
    check(await page.getByRole('textbox',{name:'Link de activare',exact:true}).count()===0,'activation link disclosure is consumed once');
   }
   if(section==='subscriptions'){
    check((await page.locator('table').innerText()).includes('upgrade-browser@example.com'),'subscription list includes active paid client');
    await page.locator('.filter-tabs').getByRole('link',{name:'De verificat',exact:true}).click();
    check(!(await page.locator('table').innerText()).includes('upgrade-browser@example.com'),'attention filter excludes healthy active subscription');
    check(new URL(page.url()).searchParams.get('attention')==='1','attention filter is reflected in URL');
   }
   if(section==='integrations'){
    check(await page.getByRole('button',{name:'Testează conexiunea Stripe',exact:true}).isVisible()&&await page.getByRole('button',{name:'Sincronizează automat catalogul',exact:true}).isVisible(),'Stripe connection and full catalog controls are available');
    check(await page.locator('input[name=secret_key]').getAttribute('type')==='text','Stripe key field displays readable text');
    check(await page.locator('input[name=secret_key]').inputValue()==='sk_test••••••••pSaved','saved TEST preview is the actual input value');
    if(width===320){
     await page.locator('input[name=secret_key]').fill('sk_test_httpSaved');
     await page.locator('input[name=webhook_secret]').fill('whsec_httpSaved');
     await page.getByRole('button',{name:'Salvează, testează și sincronizează TEST',exact:true}).click();
     check(await page.locator('input[name=secret_key]').inputValue()==='sk_test••••••••pSaved' && await page.locator('input[name=webhook_secret]').inputValue()==='whsec_h••••••••pSaved','browser form submission replaces full credentials with saved masks');
     await page.getByRole('button',{name:'Salvează, testează și sincronizează TEST',exact:true}).click();
     await page.reload();
     check(await page.locator('input[name=secret_key]').inputValue()==='sk_test••••••••pSaved' && await page.locator('input[name=webhook_secret]').inputValue()==='whsec_h••••••••pSaved','browser resave and reload preserve saved masks');
    }
    await page.getByText('Oblio · Configurat din administrare',{exact:true}).click();
    check(await page.locator('input[name=secret]').getAttribute('type')==='text','Oblio field displays readable text');
    check(await page.locator('input[name=secret]').inputValue()==='httpObl••••••••Secret','saved Oblio preview is the actual input value');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'expanded integration settings fit');
    await page.screenshot({path:path.join(artifacts,'admin-integrations-'+width+'.png'),fullPage:true});
    await page.locator('nav[aria-label="Configurație Stripe"]').getByRole('link',{name:'LIVE · Plăți reale',exact:true}).click();
    check(await page.locator('input[name=credential_mode]').first().inputValue()==='live','LIVE profile selected independently');
    check(await page.getByRole('button',{name:'Activează LIVE pe site',exact:true}).isVisible(),'LIVE activation remains an explicit action');
    check(await page.locator('input[name=secret_key]').inputValue()==='••••••••'&&await page.locator('input[name=webhook_secret]').inputValue()==='whsec_l••••••••ixture','LIVE fields contain only masked previews');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'LIVE profile fits mobile');
    await page.screenshot({path:path.join(artifacts,'admin-stripe-live-'+width+'.png'),fullPage:true});
   }
   if(section==='catalog')check(await page.locator('.table').first().locator('input[name=price_id]').count()===0,'main catalog no longer asks for manual Price IDs');
   if(section==='api-log'){
    check(await page.locator('h2').textContent()==='Comunicare API','API journal has a dedicated page');
    await page.getByText('Debug webhook și recuperarea unei plăți confirmate',{exact:true}).click();
    check(await page.locator('input[name=event_id]').isVisible() && await page.locator('select[name=credential_mode]').isVisible(),'webhook recovery offers event ID and explicit TEST LIVE selection');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'expanded webhook troubleshooting guide fits viewport');
    await page.getByText('Debug webhook și recuperarea unei plăți confirmate',{exact:true}).click();
    await page.locator('select[name=provider]').selectOption('immich');await page.locator('select[name=status]').selectOption('ok');
    await page.getByRole('button',{name:'Filtrează',exact:true}).click();
    check(new URL(page.url()).searchParams.get('section')==='api-log' && (await page.locator('table').innerText()).includes('Immich'),'API log filters preserve section and show recorded traffic');
    await page.screenshot({path:path.join(artifacts,'admin-api-log-'+width+'.png'),fullPage:true});
    await page.getByRole('link',{name:'Webhookuri cu erori',exact:true}).click();
    check((await page.locator('table').innerText()).includes('signature_invalid') && (await page.locator('table').innerText()).includes('signature'),'webhook journal exposes signature rejection and stage');
    await page.screenshot({path:path.join(artifacts,'admin-webhook-log-'+width+'.png'),fullPage:true});
   }
  }
 }
 // Navigation and search also work without scripts.
 const plain=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}});
 const nojs=await plain.newPage();await nojs.goto(origin+'/admin');
 await nojs.locator('input[name=email]').fill('admin-fixture@example.com');await nojs.locator('input[name=password]').fill('fixture-admin-password');await nojs.getByRole('button',{name:'Intră în administrare'}).click();
 await nojs.locator('label[for=admin-menu]').click();await nojs.locator('.sidebar').getByRole('link',{name:'Clienți',exact:true}).click();
 await nojs.locator('input[name=q]').fill('upgrade-browser');await nojs.getByRole('button',{name:'Caută',exact:true}).click();
 check(new URL(nojs.url()).searchParams.get('section')==='clients','search preserves dedicated section without JavaScript');
 check((await nojs.locator('table').innerText()).includes('upgrade-browser@example.com')&&!(await nojs.locator('table').innerText()).includes('admin-client@example.com'),'client search filters matching records');
 await plain.close();
 await page.goto(origin+'/ro/');
 const personal=page.locator('.plan.recommended');
 check(await page.locator('.price-net-label').count()===3 && (await personal.locator('.price-net-label').innerText())==='fără TVA','every paid plan labels its base price as excluding VAT');
 check((await personal.locator('.plan-tax').innerText()).includes('2,41 EUR'),'public monthly gross total includes VAT');
 await page.locator('[data-billing-switch] button[value=year]').click();
 check((await personal.locator('.plan-tax').innerText()).includes('26,49 EUR'),'annual switch updates gross total including VAT');
 await page.locator('[data-billing-switch] button[value=month]').click();
 check((await personal.locator('.plan-tax').innerText()).includes('2,41 EUR'),'monthly switch restores gross total');

 for(const width of [390,1440]){
  await page.setViewportSize({width,height:1000});
  for(const [locale,slug] of Object.entries({ro:'documente',en:'legal',fr:'documents',de:'rechtliches'}))for(const doc of ['privacy','terms','cookies']){
   const res=await page.goto(origin+'/'+locale+'/'+slug+'?document='+doc);
   check(res.status()===200 && await page.locator('.legal-content h2').count()>=4,'structured legal content renders '+locale+doc);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'legal content fits viewport '+width+locale+doc);
   check((await page.locator('.legal-operator').innerText()).includes('F38/36/2024'),'legal operator identity present');
   check((await page.locator('.header .languages a[lang=en]').getAttribute('href')).endsWith('?document='+doc),'language switch retains selected legal document');
  }
  await page.goto(origin+'/ro/documente?document=terms');await page.screenshot({path:path.join(artifacts,'legal-terms-'+width+'.png'),fullPage:false});
 }
 await page.goto(origin+'/en/contact?request=withdrawal');await page.locator('input[name=name]').fill('Withdrawal Browser');await page.locator('input[name=email]').fill('withdrawal-browser@example.com');await page.locator('input[name=contract]').fill('Personal / browser fixture order');await page.getByRole('button',{name:'Confirm withdrawal',exact:true}).click();
 check((await page.locator('[data-legal-receipt]').innerText()).includes('I withdraw from the identified contract'),'browser withdrawal records explicit declaration');
 const [receiptDownload]=await Promise.all([page.waitForEvent('download'),page.locator('[data-receipt-download]').click()]);check(receiptDownload.suggestedFilename()==='zion-storage-withdrawal.txt','withdrawal receipt can be downloaded as durable text');
 await page.goto(origin+'/admin?section=legal-requests');check((await page.locator('main').innerText()).includes('Withdrawal Browser'),'operator can find received withdrawal in admin');
 const customer=await browser.newContext();const flow=await customer.newPage();flow.on('pageerror',e=>errors.push(e.message));
 await flow.goto(verify);await flow.locator('button[type=submit],form button').first().click();
 await flow.getByRole('link',{name:'Mai mult spațiu'}).click();
 check((await flow.locator('h1').textContent()).includes('Mai mult spațiu'),'customer can find upgrade');
 for(const width of [320,390,768,1440]){
  await flow.setViewportSize({width,height:900});
  for(const locale of ['ro','en','fr','de']){
   const res=await flow.goto(origin+'/'+locale+'/upgrade');check(res.status()===200,'localized upgrade '+locale+' '+width);
   check(await flow.locator('html').getAttribute('lang')===locale,'upgrade document language');
   check(await flow.locator('meta[name=robots]').getAttribute('content')==='noindex,nofollow','upgrade private indexing');
   check(await flow.locator('button.button.full').count()===2,'only larger paid offers');
   const text=await flow.locator('main').innerText();check(text.includes('1,94') && text.includes('4,34') && text.includes('21%'),'upgrade gross difference and recurring gross amount include disclosed VAT');
   check(await flow.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'upgrade no page overflow');
  }
  await flow.goto(origin+'/ro/upgrade');await flow.screenshot({path:path.join(artifacts,'upgrade-'+width+'.png'),fullPage:true});
 }
 await flow.goto(origin+'/admin');check(await flow.getByRole('heading',{name:'Autentificare administrator'}).isVisible(),'customer browser has no admin privilege');
 check(errors.length===0,'no browser runtime errors');await browser.close();console.log(checks+' admin/upgrade Chrome assertions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
