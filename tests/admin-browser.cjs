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
 const sections=['dashboard','clients','new-client','subscriptions','changes','invoices','operations','integrations','catalog','audit'];
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
   if(section==='dashboard'){
    check(await page.locator('[data-metric=clients]').textContent()==='2','dashboard reflects actual clients');
    check((await page.locator('.revenue-values').innerText()).includes('1,99 EUR')&&(await page.locator('.revenue-values').innerText()).includes('9,99 RON'),'dashboard separates actual payment currencies');
    check(await page.locator('input[name=secret_key],input[name=password]').count()===0,'dashboard excludes management forms');
    await page.screenshot({path:path.join(artifacts,'admin-dashboard-'+width+'.png'),fullPage:true});
   }
   if(section==='new-client')check(await page.locator('input[name=password]').getAttribute('type')==='password','new customer password masked');
   if(section==='subscriptions'){
    check((await page.locator('table').innerText()).includes('upgrade-browser@example.com'),'subscription list includes active paid client');
    await page.locator('.filter-tabs').getByRole('link',{name:'De verificat',exact:true}).click();
    check(!(await page.locator('table').innerText()).includes('upgrade-browser@example.com'),'attention filter excludes healthy active subscription');
    check(new URL(page.url()).searchParams.get('attention')==='1','attention filter is reflected in URL');
   }
   if(section==='integrations'){
    check(await page.getByRole('button',{name:'Testează conexiunea Stripe',exact:true}).isVisible()&&await page.getByRole('button',{name:'Sincronizează automat catalogul',exact:true}).isVisible(),'Stripe connection and full catalog controls are available');
    check(await page.locator('input[name=secret_key]').inputValue()==='','Stripe key never prefilled');
    await page.getByText('Oblio · Configurat din administrare',{exact:true}).click();
    check(await page.locator('input[name=secret]').inputValue()===''&&await page.locator('input[name=secret]').getAttribute('type')==='password','Oblio secret masked and never prefilled');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'expanded integration settings fit');
    await page.screenshot({path:path.join(artifacts,'admin-integrations-'+width+'.png'),fullPage:true});
    await page.locator('nav[aria-label="Configurație Stripe"]').getByRole('link',{name:'LIVE · Plăți reale',exact:true}).click();
    check(await page.locator('input[name=credential_mode]').first().inputValue()==='live','LIVE profile selected independently');
    check(await page.getByRole('button',{name:'Activează LIVE pe site',exact:true}).isVisible(),'LIVE activation remains an explicit action');
    check(await page.locator('input[name=secret_key]').inputValue()===''&&await page.locator('input[name=webhook_secret]').inputValue()==='','neither LIVE secret is rendered back into HTML');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'LIVE profile fits mobile');
    await page.screenshot({path:path.join(artifacts,'admin-stripe-live-'+width+'.png'),fullPage:true});
   }
   if(section==='catalog')check(await page.locator('.table').first().locator('input[name=price_id]').count()===0,'main catalog no longer asks for manual Price IDs');
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
   const text=await flow.locator('main').innerText();check(text.includes('1,60') && text.includes('3,59'),'upgrade difference and future monthly amount');
   check(await flow.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'upgrade no page overflow');
  }
  await flow.goto(origin+'/ro/upgrade');await flow.screenshot({path:path.join(artifacts,'upgrade-'+width+'.png'),fullPage:true});
 }
 await flow.goto(origin+'/admin');check(await flow.getByRole('heading',{name:'Autentificare administrator'}).isVisible(),'customer browser has no admin privilege');
 check(errors.length===0,'no browser runtime errors');await browser.close();console.log(checks+' admin/upgrade Chrome assertions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
