const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');const fs=require('node:fs');const path=require('node:path');
const origin=process.env.TEST_URL||'http://localhost:8088';const dir=path.join(__dirname,'artifacts');fs.mkdirSync(dir,{recursive:true});
let checks=0;function check(v,label){if(!v)throw Error(label);checks++;}
(async()=>{
 const browser=await chromium.launch({channel:process.env.BROWSER_CHANNEL||'chrome',headless:true});const ctx=await browser.newContext({reducedMotion:'reduce'});const page=await ctx.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 for(const width of [320,360,390,430,768,1024,1440,1920])for(const locale of ['ro','en','fr','de']){
  await page.setViewportSize({width,height:1000});const res=await page.goto(origin+'/'+locale+'/');check(res.status()===200,'home '+locale+width);
  check(await page.locator('#hero-backup').isVisible(),'backup initially visible');
  check(await page.locator('[data-hero-carousel]').getAttribute('data-playing')==='false','reduced motion starts paused');
  check(await page.locator('[data-carousel-controls]').isVisible(),'controls progressively enabled');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'backup no horizontal overflow '+locale+width);
  const before=await page.locator('.hero').evaluate(el=>el.getBoundingClientRect().height);
  if(locale==='ro'&&[390,1440].includes(width))await page.locator('.hero').screenshot({path:path.join(dir,'hero-backup-'+width+'.png')});
  await page.locator('[data-scene-select="1"]').click();
  check(await page.locator('#hero-comparison').isVisible()&&await page.locator('#hero-backup').isHidden(),'comparison selection');
  check(await page.locator('[data-scene-select="1"]').getAttribute('aria-pressed')==='true','active scene announced');
  check(await page.locator('.hero-zion-card').innerText().then(t=>t.includes('120 GB')),'Zion capacity from catalog');
  check(await page.locator('.hero-shared-card').innerText().then(t=>t.includes('Google Photos')&&t.includes('65 GB')&&t.includes('20 GB')&&t.includes('15 GB')),'illustrative shared allocation');
  check((await page.locator('.hero-example-note').innerText()).length>60,'illustrative disclaimer translated');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'comparison no horizontal overflow '+locale+width);
  check(await page.locator('.hero-comparison-panel').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'comparison text fits');
  check(await page.locator('.hero-comparison-panel').evaluate(el=>el.getBoundingClientRect().height<=el.closest('.hero-scenes').getBoundingClientRect().height),'scene fits fixed viewport '+locale+width);
  check(Math.abs(before-await page.locator('.hero').evaluate(el=>el.getBoundingClientRect().height))<1,'scene changes preserve layout height');
  check(await page.locator('[data-carousel-controls] button:not(.scene-play)').evaluateAll(buttons=>buttons.every(b=>b.getBoundingClientRect().height>=44)),'touch-friendly controls');
  if(locale==='ro'&&[320,390,768,1440].includes(width))await page.locator('.hero').screenshot({path:path.join(dir,'hero-comparison-'+width+'.png')});
  await page.locator('[data-scene-next]').focus();await page.keyboard.press('ArrowRight');check(await page.locator('#hero-backup').isVisible(),'keyboard wraps to backup');
  await page.locator('[data-carousel-viewport]').dispatchEvent('pointerdown',{pointerType:'touch',clientX:200,clientY:200});await page.locator('[data-carousel-viewport]').dispatchEvent('pointerup',{pointerType:'touch',clientX:100,clientY:205});check(await page.locator('#hero-comparison').isVisible(),'horizontal touch swipe');
  check(await page.locator('.hero-scene.is-entering').evaluate(el=>getComputedStyle(el).animationName)==='none','reduced-motion transitions disabled');
 }
 const automatic=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'no-preference'});const auto=await automatic.newPage();
 await auto.clock.install();await auto.goto(origin+'/en/');await auto.waitForTimeout(200);await auto.clock.runFor(8200);
 check(await auto.locator('#hero-comparison').isVisible(),'autoplay advances after eight seconds');
 await auto.locator('[data-scene-select="0"]').click();await auto.mouse.move(0,0);await auto.clock.runFor(9000);check(await auto.locator('#hero-backup').isVisible(),'manual interaction pauses autoplay');
 await auto.locator('[data-scene-play]').click();await auto.mouse.move(0,0);await auto.clock.runFor(8200);check(await auto.locator('#hero-comparison').isVisible(),'explicit play resumes autoplay');
 await auto.locator('[data-hero-carousel]').hover();await auto.clock.runFor(9000);check(await auto.locator('#hero-comparison').isVisible(),'hover suspends rotation');
 await auto.mouse.move(0,0);await auto.locator('#pricing').scrollIntoViewIfNeeded();await auto.waitForTimeout(100);await auto.clock.runFor(9000);check(await auto.locator('#hero-comparison').isVisible(),'offscreen carousel does not rotate');
 const fallback=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900}});const nojs=await fallback.newPage();await nojs.goto(origin+'/ro/');
 check(await nojs.locator('#hero-backup').isVisible()&&await nojs.locator('[data-carousel-controls]').isHidden(),'no-JS original illustration remains visible');
 check(await nojs.locator('.storage-comparison').count()===1,'no-JS comparison remains available below pricing');
 check(errors.length===0,'no runtime errors');await browser.close();console.log(checks+' hero carousel Chrome assertions passed.');
})().catch(e=>{console.error(e);process.exit(1);});
