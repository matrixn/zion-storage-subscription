const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs');const path=require('node:path');
const origin=process.env.TEST_URL||'http://localhost:8088';const dir=path.join(__dirname,'artifacts');fs.mkdirSync(dir,{recursive:true});
let checks=0;function check(ok,label){if(!ok)throw Error(label);checks++;}
const slugs=['free','120gb','250gb','500gb'];
const registration={ro:'inregistrare',en:'register',fr:'inscription',de:'registrieren'};
const expected={ro:['0,00 lei','9,99 lei','17,99 lei','29,99 lei'],en:['RON 0.00','RON 9.99','RON 17.99','RON 29.99'],fr:['0,00 RON','9,99 RON','17,99 RON','29,99 RON'],de:['0,00 RON','9,99 RON','17,99 RON','29,99 RON']};
(async()=>{
 const browser=await chromium.launch({channel:process.env.BROWSER_CHANNEL||'chrome',headless:true});const context=await browser.newContext();const page=await context.newPage();const errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
 for(const width of [320,360,390,430,768,1024,1440,1920]){
  await page.setViewportSize({width,height:1000});
  for(const locale of ['ro','en','fr','de']){
   const response=await page.goto(origin+'/'+locale+'/#pricing');check(response.status()===200,'home response '+width+locale);
   await page.locator('#pricing').scrollIntoViewIfNeeded();await page.waitForTimeout(750);
   check(await page.locator('#pricing .plan').count()===4,'four plans');
   check(JSON.stringify(await page.locator('.plan h3').allTextContents())===JSON.stringify(['10 GB','120 GB','250 GB','500 GB']),'catalog capacities');
   check(JSON.stringify(await page.locator('.price-amount').allTextContents())===JSON.stringify(expected[locale]),'localized catalog prices '+locale);
   check((await page.locator('.plan.recommended .plan-name').textContent())==='Personal','Personal remains recommended');
   check(await page.locator('.recommendation').isVisible(),'ribbon visible');
   check(JSON.stringify(await page.locator('.plan').evaluateAll(cards=>cards.map(c=>c.querySelectorAll('li').length)))==='[6,6,6,5]','specific plan benefits');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no page horizontal scroll '+width+locale);
   const metrics=await page.locator('.plan').evaluateAll(cards=>cards.map(c=>({top:c.getBoundingClientRect().top,button:c.querySelector('.button').getBoundingClientRect().bottom,touch:c.querySelector('.button').getBoundingClientRect().height,fit:c.scrollWidth<=c.clientWidth+1})));
   check(metrics.every(c=>c.fit&&c.touch>=44),'card text fits and buttons are touch-friendly');
   const columns=width>=1200?4:width>=680?2:1;
   check(metrics.filter(c=>Math.abs(c.top-metrics[0].top)<2).length===columns,'responsive card columns');
   for(let start=0;start<4;start+=columns)check(metrics.slice(start,start+columns).every(c=>Math.abs(c.button-metrics[start].button)<2),'buttons align within each row');
   check(await page.locator('link[rel=canonical]').getAttribute('href')===origin+'/'+locale+'/','canonical preserved');
   check(await page.locator('link[rel=alternate][hreflang]').count()===5,'hreflang preserved');
   const links=await page.locator('.plan .button').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
   check(links.every((href,i)=>href==='/'+locale+'/'+registration[locale]+'?plan='+slugs[i]),'all CTA routes preserved');
   check((await page.locator('.storage-comparison').innerText()).includes('120 GB'),'dedicated capacity from Personal');
   const range=page.locator('#shared-usage');await range.scrollIntoViewIfNeeded();await range.focus();await range.press('End');await page.waitForTimeout(450);
   check((await page.locator('[data-media]').textContent())==='20','interactive media capacity recalculates');
   check((await page.locator('[data-files]').textContent())==='46'&&(await page.locator('[data-email]').textContent())==='34','interactive example allocation sums to 100 GB');
   check(await page.locator('[data-bar-media]').evaluate(el=>Math.abs(el.getBoundingClientRect().width/el.parentElement.getBoundingClientRect().width-.2)<.01),'animated bar matches media amount');
   check((await page.locator('.dedicated-caption').innerText()).includes('120 GB'),'Zion capacity unchanged by shared usage');
   await range.press('Home');check((await page.locator('[data-media]').textContent())==='100','keyboard zero overhead works');
   await range.fill('35');await range.dispatchEvent('input');await page.waitForTimeout(450);
   if(locale==='ro'&&[320,390,768,1440].includes(width))await page.locator('#pricing').screenshot({path:path.join(dir,'pricing-redesign-'+width+'.png')});
  }
 }
 for(const locale of ['ro','en','fr','de'])for(const [i,slug] of slugs.entries()){
  await page.goto(origin+'/'+locale+'/'+registration[locale]+'?plan='+slug);
  check(await page.locator('#plan').inputValue()===slug,'CTA preselects '+locale+slug);
  check((await page.locator('#plan option[value="250gb"]').textContent()).includes('17.99'),'registration matches new Plus price');
 }
 const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900}});const fallback=await nojs.newPage();
 for(const locale of ['ro','en','fr','de']){
  await fallback.goto(origin+'/'+locale+'/#pricing');check(await fallback.locator('.plan').count()===4,'all prices server-rendered without JS');
  check(await fallback.locator('.comparison-control').isHidden(),'nonfunctional slider hidden without JS');
  check(await fallback.locator('[data-media]').textContent()==='65','illustration works without JS');
  check(await fallback.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no JS mobile layout fits');
 }
 await page.emulateMedia({reducedMotion:'reduce'});await page.goto(origin+'/ro/#pricing');
 check(await page.locator('.plan').first().evaluate(el=>getComputedStyle(el).transitionDuration)==='0s','reduced-motion card transitions disabled');
 check(await page.locator('.capacity-bar').first().evaluate(el=>getComputedStyle(el).animationName)==='none','reduced-motion bar animation disabled');
 const xml=await (await context.request.get(origin+'/sitemap.xml')).text();check((xml.match(/<loc>/g)||[]).length===16&&!xml.includes('/pricing'),'16 marketing URLs, no new pricing page');
 check(errors.length===0,'no runtime or CSP errors: '+errors.join('; '));await browser.close();console.log(checks+' pricing Chrome assertions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
