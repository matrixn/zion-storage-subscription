const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'zion-onboarding-')),origin='http://127.0.0.1:8097';
const env={...process.env,APP_ENV:'test',APP_KEY:'onboarding-isolated-fixture-key-'.repeat(3),APP_URL:origin,DB_PATH:temp+'/app.sqlite',ONBOARDING_TEST_DIRECTORY:temp,MAIL_TRANSPORT:'log',CAPTCHA_ENABLED:'false',OBLIO_ENABLED:'false',STRIPE_SECRET_KEY:'sk_test_fixture',STRIPE_WEBHOOK_SECRET:'whsec_fixture',STORAGE_API_URL:'http://127.0.0.1:9/api',STORAGE_API_KEY:'fixture-unused',LISTMONK_URL:'http://127.0.0.1:9'};
const sessions=JSON.parse(execFileSync('php',['tests/setup-onboarding.php'],{cwd:root,env,encoding:'utf8'}));
const server=spawn('php',['-d','session.save_path='+temp+'/sessions','-S','127.0.0.1:8097','-t','public','public/router.php'],{cwd:root,env,stdio:'ignore'});
const artifacts=path.join(__dirname,'artifacts','onboarding');fs.mkdirSync(artifacts,{recursive:true});
let checks=0;function check(ok,label){if(!ok)throw Error(label);checks++;}
(async()=>{
 let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch(origin+'/ro/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
  await context.addCookies([{name:'zion_session',value:sessions.ready,url:origin}]);
  await page.goto(origin+'/ro/activare');await page.evaluate(()=>document.fonts.ready);
  if(process.argv.includes('--baseline')){
   await page.screenshot({path:path.join(artifacts,'before-desktop.png'),fullPage:true});
   await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(artifacts,'before-phone.png'),fullPage:true});
   console.log('Before screenshots captured from the authenticated isolated fixture.');return;
  }
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  check((await page.locator('[data-onboarding]').count())===1,'ready onboarding rendered');
  const serverURL='https://photos.zion3d.ro';
  check(await page.locator('#on-server').inputValue()===serverURL,'exact public login URL');
  check(await page.locator('[data-store]:visible').count()===2,'desktop both stores');
  check(await page.locator('.on-qr:visible').count()===2,'desktop local QR codes');
  check(await page.locator('[data-store=android] a').getAttribute('href')==='https://play.google.com/store/apps/details?id=app.alextran.immich','official Google Play target');
  check(await page.locator('[data-store=ios] a').getAttribute('href')==='https://apps.apple.com/us/app/immich/id1613945652','official App Store target');
  await page.screenshot({path:path.join(artifacts,'after-desktop.png'),fullPage:true});
  // Decode rasterized versions of the actual, locally served QR assets when tools are available.
  if(process.env.QR_TEST_MODULES){
   const {PNG}=require(process.env.QR_TEST_MODULES+'/pngjs'),decode=require(process.env.QR_TEST_MODULES+'/jsqr');
   const qrPage=await context.newPage();
   for(const platform of ['android','ios']){
    await qrPage.goto(origin+'/assets/onboarding/'+platform+'.svg');
    const png=PNG.sync.read(await qrPage.screenshot({clip:{x:0,y:0,width:256,height:256}}));
    const found=decode(new Uint8ClampedArray(png.data),png.width,png.height);
    check(found?.data===await page.locator('[data-store='+platform+'] a').getAttribute('href'),'QR decodes to official '+platform+' URL');
   }await qrPage.close();
  }
  const profiles=[
   ['android_phone','android','Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36',390,844,5],
   ['android_tablet','android','Mozilla/5.0 (Linux; Android 14; SM-X700) Safari/537.36',820,1180,5],
   ['iphone','ios','Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148 Safari/604.1',390,844,5],
   ['ipad','ios','Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) Mobile/15E148 Safari/604.1',768,1024,5],
   ['ipad','ios','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Version/18.0 Safari/605.1',1024,1366,5],
   ['desktop','both','Mozilla/5.0 (Windows NT 10.0; Win64; x64)',1440,900,0],
   ['desktop','both','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)',1440,900,0],
   ['desktop','both','Mozilla/5.0 (X11; Linux x86_64)',1440,900,0],
   ['unknown','both','UnknownBrowser',320,760,0],
  ];
  for(const [category,platform,ua,width,height,touch] of profiles){
   const ctx=await browser.newContext({userAgent:ua,viewport:{width,height}});
   await ctx.addInitScript(t=>Object.defineProperty(navigator,'maxTouchPoints',{get:()=>t}),touch);
   await ctx.addCookies([{name:'zion_session',value:sessions.ready,url:origin}]);const p=await ctx.newPage();
   await p.goto(origin+'/en/activation');
   check(await p.locator('[data-onboarding]').getAttribute('data-device')===category,'browser category '+category+' '+ua.slice(0,25));
   check(await p.locator('[data-platform][aria-pressed=true]').getAttribute('data-platform')===platform,'primary store '+category);
   check(await p.locator('[data-store]:visible').count()===(platform==='both'?2:1),'visible stores '+category);
   check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no overflow '+category+' portrait');
   if(category==='android_phone'||category==='android_tablet')await p.screenshot({path:path.join(artifacts,'after-'+category+'.png'),fullPage:true});
   await p.setViewportSize({width:height,height:width});
   check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no overflow '+category+' landscape');
   if(category==='iphone')await p.screenshot({path:path.join(artifacts,'after-iphone-landscape.png'),fullPage:true});
   await p.locator('button[data-platform=ios]').click();check(await p.locator('[data-store=ios]').isVisible()&&!await p.locator('[data-store=android]').isVisible(),'manual iOS override '+category);
   await p.locator('button[data-platform=android]').click();check(await p.locator('[data-store=android]').isVisible()&&!await p.locator('[data-store=ios]').isVisible(),'manual Android override '+category);
   await ctx.close();
  }
  for(const [loc,slug,heading] of [['ro','activare','Contul tău Zion Storage este pregătit!'],['en','activation','Your Zion Storage account is ready!'],['fr','activation','Votre compte Zion Storage est prêt !'],['de','aktivierung','Dein Zion Storage Konto ist bereit!']]){
   await page.goto(origin+'/'+loc+'/'+slug);
   check(await page.locator('h1').textContent()===heading,'localized heading '+loc);
   check(await page.locator('html').getAttribute('lang')===loc,'document language '+loc);
   check(await page.locator('.header .languages a[lang=en]').getAttribute('href')==='/en/activation','language switch stays on onboarding '+loc);
   check(!/on\.[a-z]|Missing translation|Warning:|Fatal error:/.test(await page.locator('main').innerText()),'no untranslated keys/errors '+loc);
   await page.setViewportSize({width:320,height:760});
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'small screen translated layout '+loc);
   const targets=await page.locator('[data-platform], [data-copy-server], .on-store a, .on-steps a, .on-help summary').evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length).map(n=>n.getBoundingClientRect().height));
   check(targets.every(h=>h>=44),'44px onboarding targets '+loc);
  }
  await page.setViewportSize({width:390,height:844});await page.goto(origin+'/ro/activare');
  await page.locator('button[data-platform=android]').click();await page.screenshot({path:path.join(artifacts,'after-phone.png'),fullPage:true});
  await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copied=text;}}}));
  await page.locator('[data-copy-server]').click();check(await page.evaluate(()=>window.copied)===serverURL,'clipboard exact URL');
  check(await page.locator('[data-copy-feedback]').textContent()==='Adresa serverului a fost copiată.','accessible copy success');
  await page.evaluate(()=>{Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw Error('denied');}}});document.execCommand=command=>{window.fallbackCommand=command;return true;};});
  await page.locator('[data-copy-server]').click();check(await page.evaluate(()=>window.fallbackCommand)==='copy','clipboard denial uses fallback');
  await page.evaluate(()=>{document.execCommand=()=>false;});await page.locator('[data-copy-server]').click();
  check((await page.locator('[data-copy-feedback]').textContent()).includes('manual'),'copy error guides manual selection');
  check(await page.locator('#on-server').evaluate(n=>n.selectionEnd-n.selectionStart)===serverURL.length,'failed copy leaves public address selected');
  const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}});await nojs.addCookies([{name:'zion_session',value:sessions.ready,url:origin}]);const plain=await nojs.newPage();await plain.goto(origin+'/en/activation');
  check(await plain.locator('[data-store]:visible').count()===2,'no-JS both stores');check(await plain.locator('#on-server').inputValue()===serverURL,'no-JS copy manually');check(await plain.locator('[data-copy-server]').isHidden(),'no-JS no dead copy button');await nojs.close();
  const paid=await browser.newContext();await paid.addCookies([{name:'zion_session',value:sessions['paid-ready'],url:origin}]);const pp=await paid.newPage();await pp.goto(origin+'/en/activation');
  check(await pp.locator('[data-onboarding]').count()===1,'authoritatively paid account ready');
  check(await pp.locator('form[action="/en/billing"]').count()===1,'billing portal action retained');
  check(await pp.locator('a[href="/en/billing-details"]').count()===1,'billing history retained');await paid.close();
  const blocked=await browser.newContext();await blocked.addCookies([{name:'zion_session',value:sessions.pending,url:origin}]);
  await blocked.addInitScript(()=>Object.defineProperty(window,'sessionStorage',{get(){throw new Error('blocked');}}));
  const bp=await blocked.newPage();await bp.goto(origin+'/en/activation');
  check((await bp.locator('[data-poll-feedback]').textContent()).includes('stopped'),'unavailable storage stops automatic reload safely');await blocked.close();
  for(const [fixture,expected] of [['pending','payment'],['provisioning','provisioning'],['failed','failed'],['password','password'],['inactive','inactive'],['banned','suspended'],['unverified','verification']]){
   const ctx=await browser.newContext();await ctx.addCookies([{name:'zion_session',value:sessions[fixture],url:origin}]);const p=await ctx.newPage();
   await p.goto(origin+'/en/activation?session_id=cs_fake');
   check(await p.locator('[data-onboarding-state]').getAttribute('data-onboarding-state')===expected,'safe status '+expected);
   check(await p.locator('[data-onboarding]').count()===0,'not falsely ready '+expected);
   check(!(await p.content()).includes(fixture+'@example.test'),'non-ready state no customer email '+expected);
   if(expected==='failed')check(await p.locator('[data-auto-activation]').count()===0,'failure no automatic provisioning loop');
   if(expected==='payment'){
    await p.evaluate(()=>sessionStorage.setItem('zion:onboarding:checks',JSON.stringify({count:6,at:Date.now()})));await p.reload();
    check((await p.locator('[data-poll-feedback]').textContent()).includes('stopped'),'poll budget exhausted offers manual check');
   }
   await ctx.close();
  }
  const anonymous=await browser.newContext(),a=await anonymous.newPage();await a.goto(origin+'/en/activation?session_id=cs_fake');
  check(await a.locator('[data-onboarding]').count()===0&&!(await a.content()).includes('ready@example.test'),'unauthenticated checkout identifier exposes no account');
  await anonymous.close();
  const expired=await browser.newContext();await expired.addCookies([{name:'zion_session',value:sessions.expired,url:origin}]);const ep=await expired.newPage();await ep.goto(origin+'/en/activation');
  check(await ep.locator('[data-onboarding]').count()===0&&!(await ep.content()).includes('expired@example.test'),'expired session recovery reveals no account');await expired.close();
  const privateHTML=await page.content();check(!/sk_test_fixture|whsec_fixture|fixture-unused|fixture-password-not-real|fixture-storage-|activation_credentials/.test(privateHTML),'no integration/password/provisioning secrets in HTML');
  check(errors.length===0,'no browser script errors');
  console.log(JSON.stringify({checks,screenshots:artifacts,engines:'Chromium; device signals emulated, real devices still require manual checks'}));
 }finally{if(browser)await browser.close();server.kill();if(temp.startsWith(os.tmpdir()+'/zion-onboarding-'))fs.rmSync(temp,{recursive:true,force:true});}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
