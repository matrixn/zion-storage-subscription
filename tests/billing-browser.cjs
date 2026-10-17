const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs'),path=require('node:path');
const origin=process.env.TEST_URL||'http://localhost:8089';
const fixture=JSON.parse(fs.readFileSync(process.env.BILLING_FIXTURE_FILE,'utf8'));
const dir=path.join(__dirname,'artifacts');let count=0;const errors=[];
function check(ok,label){if(!ok)throw Error(label);count++;}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const page=await browser.newPage();
 page.on('pageerror',e=>errors.push(e.message));
 const routes={en:['register','billing-details'],ro:['inregistrare','date-facturare'],fr:['inscription','coordonnees-facturation'],de:['registrieren','rechnungsdaten']};
 for(const width of [320,360,390,430,768,1024,1440]){
  await page.setViewportSize({width,height:900});
  for(const [locale,[register]] of Object.entries(routes)){
   await page.goto(origin+'/'+locale+'/'+register+'?plan=120gb');
   await page.locator('#name').fill('Browser Company');await page.locator('#email').fill('company@example.com');await page.locator('#password').fill('billing-browser-password');await page.locator('#confirm').fill('billing-browser-password');
   await page.locator('[data-next]').click();
   check(await page.locator('[data-billing]').isVisible(),'paid profile visible');
   await page.locator('#billing-type').selectOption('company');
   check(await page.locator('#billing-tax_id').isVisible() && await page.locator('#billing-tax_id').getAttribute('required')!==null,'company tax ID required');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'paid form no overflow');
   await page.locator('#plan').selectOption('free');
   check(!(await page.locator('[data-billing]').isVisible()),'free plan needs no billing profile');
  }
 }
 await page.setViewportSize({width:390,height:844});
 await page.goto(fixture.verification_url);await page.locator('main form button').click();
 for(const width of [320,390,768,1440]){
  await page.setViewportSize({width,height:900});
  for(const [locale,[,billing]] of Object.entries(routes)){
   const response=await page.goto(origin+'/'+locale+'/'+billing);
   check(response.status()===200,'private billing route renders');
   check(await page.locator('#billing-address').inputValue()==='Strada Amintirilor 16','local profile prefilled');
   check(await page.locator('.invoice-card').count()===1,'fiscal history has one simulation');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'billing history no horizontal overflow '+width+' '+locale+' '+JSON.stringify(await page.evaluate(()=>[...document.querySelectorAll('main *')].map(e=>({tag:e.tagName,c:e.className,r:e.getBoundingClientRect().right})).filter(e=>e.r>innerWidth+1))));
   if(locale==='ro'&&width===390)await page.screenshot({path:path.join(dir,'billing-ro-390.png'),fullPage:true});
   if(locale==='en'&&width===1440)await page.screenshot({path:path.join(dir,'billing-en-1440.png'),fullPage:true});
  }
 }
 await page.goto(origin+'/en/billing-details');
 await page.locator('#billing-type').selectOption('company');await page.locator('#billing-company_name').fill('Browser Company SRL');await page.locator('#billing-tax_id').fill('RO12345678');await page.locator('main button').click();
 check((await page.locator('main').innerText()).includes('Your billing details have been saved.'),'billing save confirmation');
 check(await page.locator('#billing-company_name').inputValue()==='Browser Company SRL','company update persists');
 const privateUrl=origin+'/en/billing-details';
 const outsider=await browser.newPage();await outsider.goto(privateUrl);
 check(await outsider.locator('#billing-address').count()===0,'anonymous visitor cannot read billing profile');
 check(!(await outsider.locator('body').innerText()).includes('Browser Company SRL'),'anonymous visitor cannot read company');
 check(errors.length===0,'no billing browser errors');
 await browser.close();
 fs.writeFileSync(path.join(dir,'billing-browser-results.json'),JSON.stringify({checks:count,errors},null,2));
 console.log(count+' billing browser assertions passed.');
})().catch(e=>{console.error(e);process.exit(1)});
