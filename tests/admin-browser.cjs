const path=require('node:path');const fs=require('node:fs');
let playwright;try{playwright=require(process.env.PLAYWRIGHT_MODULE||'playwright');}catch{playwright=require(path.join(path.dirname(process.execPath),'..','node_modules','playwright'));}
const origin='http://localhost:8093';const verify=process.argv[2];
if(!verify?.startsWith(origin+'/ro/verificare-email?token='))throw Error('Run through isolated tests/admin_http.py --browser');
let checks=0;function check(ok,label){if(!ok)throw Error(label);checks++;}
(async()=>{
 const browser=await playwright.chromium.launch({channel:process.env.BROWSER_CHANNEL||'chrome',headless:true});
 const context=await browser.newContext();const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(origin+'/admin');await page.locator('input[name=email]').fill('admin-fixture@example.com');await page.locator('input[name=password]').fill('fixture-admin-password');await page.getByRole('button',{name:'Intră în administrare'}).click();
 check(await page.getByRole('heading',{name:'Client nou'}).isVisible(),'browser administrator login');
 const artifacts=path.join(__dirname,'artifacts');fs.mkdirSync(artifacts,{recursive:true});
 for(const width of [320,390,768,1440]){
  await page.setViewportSize({width,height:900});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'admin no page overflow '+width);
  check(await page.getByRole('heading',{name:'Jurnal administrare'}).isVisible(),'admin audit visible '+width);
  check(await page.getByRole('heading',{name:'Upgrade-uri și schimbări de plan'}).isVisible(),'admin upgrades visible '+width);
  check(await page.locator('input[name=password]').getAttribute('type')==='password','new customer password masked');
  check(await page.locator('input[name=secret_key]').getAttribute('type')==='password' && await page.locator('input[name=secret_key]').inputValue()==='','Stripe key masked and never prefilled');
  await page.getByText('Oblio · Configurat din administrare',{exact:true}).click();
  check(await page.locator('input[name=secret]').inputValue()==='' && await page.locator('input[name=secret]').getAttribute('type')==='password','Oblio secret masked and never prefilled');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'expanded integration settings fit mobile');
  await page.getByText('Oblio · Configurat din administrare',{exact:true}).click();
  await page.screenshot({path:path.join(artifacts,'admin-'+width+'.png'),fullPage:true});
 }
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
