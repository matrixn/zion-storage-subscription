const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs');const path=require('node:path');
const origin=process.env.TEST_URL||'http://localhost:8088';
const dir=path.join(__dirname,'artifacts');fs.mkdirSync(dir,{recursive:true});
let checks=0;const results=[];function check(ok,label){if(!ok)throw Error(label);checks++;results.push(label);}
(async()=>{
 const browser=await chromium.launch({channel:process.env.BROWSER_CHANNEL||'chrome',headless:true});
 const context=await browser.newContext();const page=await context.newPage();const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 async function capture(name){await page.evaluate(async()=>{for(let y=0;y<document.documentElement.scrollHeight;y+=600){scrollTo({top:y,behavior:'instant'});await new Promise(r=>setTimeout(r,40));}scrollTo({top:0,behavior:'instant'});});await page.waitForTimeout(750);await page.screenshot({path:path.join(dir,name),fullPage:true});}
 const routes={en:['','how-it-works','register','contact'],ro:['','cum-functioneaza','inregistrare','contact'],fr:['','comment-ca-marche','inscription','contact'],de:['','so-funktioniert-es','registrieren','kontakt']};
 for(const width of [320,360,390,430,768,1024,1440]){
  await page.setViewportSize({width,height:900});
  for(const [locale,slugs] of Object.entries(routes))for(const slug of slugs){
   const res=await page.goto(origin+'/'+locale+'/'+slug);await page.waitForLoadState('networkidle');
   check(res.status()===200,width+' '+locale+'/'+slug+' 200');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+' '+locale+'/'+slug+' no horizontal overflow');
   check(await page.locator('html').getAttribute('lang')===locale,'html lang '+locale);
   check(await page.locator('link[hreflang]').count()===5,'five language alternates');
   check(await page.locator('meta[name=description]').getAttribute('content'),'localized description');
   check(!/immich|docker|synology|postgres|mariadb/i.test(await page.locator('body').innerText()),'no internal branding');
  }
 }
 await page.setViewportSize({width:390,height:844});await page.goto(origin+'/ro/');
 await capture('home-ro-390.png');
 await page.locator('.menu-toggle').click();check(await page.locator('#navigation').isVisible(),'mobile menu opens');
 await page.keyboard.press('Escape');check(!(await page.locator('#navigation').isVisible()),'mobile menu closes with Escape');
 await page.setViewportSize({width:1440,height:1000});await page.goto(origin+'/en/');
 await capture('home-en-1440.png');
 await page.goto(origin+'/en/register?plan=120gb');
 check(await page.locator('#plan').inputValue()==='120gb','plan preselection');
 await page.locator('#name').fill('Browser Test');await page.locator('#email').fill('browser-'+Date.now()+'@example.com');
 await page.locator('#password').fill('browser-test-password');await page.locator('#confirm').fill('browser-test-password');
 await page.locator('[data-next]').click();check(await page.locator('[data-plan]').isVisible(),'registration advances to plan review');
 check(await page.locator('[data-billing]').isVisible(),'paid plan collects billing details');
 await page.locator('#billing-country').fill('RO');await page.locator('#billing-county').fill('Bucuresti');await page.locator('#billing-city').fill('Bucuresti');await page.locator('#billing-address').fill('Strada Test 1');await page.locator('#billing-postal_code').fill('010101');
 await page.locator('[data-back]').click();check(await page.locator('#name').isVisible(),'registration back preserves fields');
 await page.locator('[data-next]').click();await page.locator('[name=terms]').check();await page.locator('[name=privacy]').check();await page.locator('[type=submit]').click();
 check((await page.locator('h1').innerText())==='Check your inbox.','registration response');
 const req=context.request;
 const noCsrf=await req.post(origin+'/en/contact',{form:{name:'Test',email:'test@example.com',subject:'Hi',message:'A useful message'}});
 check(noCsrf.status()===419,'contact rejects missing CSRF');
 await page.goto(origin+'/en/contact');
 const csrf=await page.locator('[name=_csrf]').inputValue();
 let response=await req.post(origin+'/en/contact',{form:{_csrf:csrf,name:'Test',email:'invalid',subject:'Hi',message:'A useful message'}});
 check(response.status()===422,'contact rejects invalid email');
 response=await req.post(origin+'/en/contact',{form:{_csrf:csrf,name:'Browser Test',email:'contact@example.com',subject:'Test subject',message:'This is a local automated contact test.'}});
 check(response.status()===200 && (await response.text()).includes('Your message has been received'),'contact submission works');
 response=await req.post(origin+'/en/register',{form:{_csrf:csrf,name:'Test User',email:'tamper@example.com',password:'a-long-test-password',confirm:'a-long-test-password',locale:'en',plan:'injected',terms:'1',privacy:'1'}});
 check(response.status()===422 && (await response.text()).includes('Choose an available plan'),'rejects tampered plan');
 const sitemap=await req.get(origin+'/sitemap.xml');check(((await sitemap.text()).match(/<url>/g)||[]).length===16,'sitemap contains exactly 16 marketing URLs');
 const robots=await req.get(origin+'/robots.txt');check((await robots.text()).includes('Sitemap:'),'robots sitemap');
 const webhook=await req.post(origin+'/webhooks/stripe',{data:'{}',headers:{'Stripe-Signature':'bad'}});check([400,503].includes(webhook.status()),'unsigned webhook rejected');
 const fresh=await browser.newContext({extraHTTPHeaders:{'Accept-Language':'fr-FR,fr;q=0.9'}});
 const fp=await fresh.newPage();await fp.goto(origin+'/');check(fp.url().endsWith('/fr/'),'root uses Accept-Language');
 await fp.goto(origin+'/de/');await fp.goto(origin+'/');check(fp.url().endsWith('/de/'),'selected locale persists');
 const nojs=await browser.newContext({javaScriptEnabled:false});const np=await nojs.newPage();await np.goto(origin+'/fr/inscription?plan=250gb');
 check(await np.locator('#plan').isVisible(),'registration works without JavaScript');
 check(await np.locator('#plan').inputValue()==='250gb','no-JS server rendered plan');
 await np.goto(origin+'/de/');check((await np.locator('h1').innerText()).includes('Deine Erinnerungen'),'translation rendered without JavaScript');
 await page.emulateMedia({reducedMotion:'reduce'});await page.goto(origin+'/en/');
 check(await page.locator('.core').evaluate(el=>getComputedStyle(el).animationName)==='none','reduced motion respected');
 check(errors.length===0,'no browser JavaScript errors');
 await browser.close();
 fs.writeFileSync(path.join(dir,'browser-results.json'),JSON.stringify({checks,errors,results},null,2));
 console.log(checks+' browser assertions passed.');
})().catch(e=>{console.error(e);process.exit(1)});
