const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'zion-onboarding-')),origin='http://127.0.0.1:8105';let checks=0;
function check(ok,label){if(!ok)throw Error(label);checks++;console.log('PASS '+label);}
(async()=>{let browser,server;try{
 const env={...process.env,APP_ENV:'test',APP_KEY:'isolated-bnr-currency-fixture-'.repeat(3),APP_URL:origin,DB_PATH:temp+'/app.sqlite',ONBOARDING_TEST_DIRECTORY:temp,MAIL_TRANSPORT:'log',MAIL_LOG_DIRECTORY:temp+'/mail',CAPTCHA_ENABLED:'false',OBLIO_ENABLED:'false',STRIPE_SECRET_KEY:'sk_test_fixture',STRIPE_WEBHOOK_SECRET:'whsec_fixture',LISTMONK_URL:''};
 const fixture=JSON.parse(execFileSync('php',['tests/setup-customer-documents.php'],{cwd:root,env,encoding:'utf8'}));
 execFileSync('php',['-r',String.raw`require 'src/bootstrap.php';$db=new Zion\Database();$id=$db->one("SELECT id FROM users WHERE email='pending@example.test'")['id'];(new Zion\BillingProfileService($db))->save($id,['type'=>'individual','name'=>'BNR Browser','billing_email'=>'pending@example.test','country'=>'RO','county'=>'VL','city'=>'Ramnicu Valcea','address'=>'Fixture street 1','postal_code'=>'240001']);`],{cwd:root,env});
 server=spawn('php',['-d','session.save_path='+temp+'/sessions','-S','127.0.0.1:8105','-t','public','public/router.php'],{cwd:root,env,stdio:'ignore'});
 for(let i=0;i<60;i++){try{await fetch(origin+'/ro/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}}),page=await ctx.newPage();
 await ctx.addCookies([{name:'zion_admin_session',value:fixture.admin,url:origin+'/admin'},{name:'zion_session',value:fixture.pending,url:origin}]);
 let response=await page.goto(origin+'/admin?section=exchange');check(response.status()===200,'dedicated BNR administration page');
 check((await page.content()).includes('1.0000 RON') && await page.locator('a[href="https://curs.bnr.ro/nbrfxrates10days.xml"]').count()===1,'admin shows exact saved rate and official source');
 check(await page.getByRole('button',{name:'Actualizează cursul BNR și catalogul Stripe'}).count()===1,'explicit update action available');
 const rejected=await ctx.request.post(origin+'/admin?section=exchange',{form:{action:'bnr_update'}});check(rejected.status()===419,'BNR update requires administrator CSRF');
 await page.goto(origin+'/ro/plata');const before=await page.locator('input[name=price_quote]').inputValue();check((await page.locator('[data-confirmed-payment]').innerText()).includes('3,99 RON'),'initial payment displays RON total');
 execFileSync('php',['-r',String.raw`require 'src/bootstrap.php';(new Zion\Database())->run('UPDATE exchange_rates SET eur_ron=53285');`],{cwd:root,env});
 await page.reload();check(await page.locator('input[name=price_quote]').inputValue()===before && (await page.locator('[data-confirmed-payment]').innerText()).includes('3,99 RON'),'FX update does not change the fixed RON charge or quote');
 execFileSync('php',['-r',String.raw`require 'src/bootstrap.php';(new Zion\Database())->run("UPDATE plan_settings SET monthly_price=499 WHERE slug='120gb'");`],{cwd:root,env});
 await page.locator('[data-confirmed-payment]').click();await page.waitForLoadState('networkidle');
 check((await page.locator('[data-confirmed-payment]').innerText()).includes('4,99 RON') && await page.locator('input[name=price_quote]').inputValue()!==before,'changed RON amount requires confirmation before any Stripe request');
 execFileSync('php',['-r',String.raw`require 'src/bootstrap.php';(new Zion\Database())->run("UPDATE plan_settings SET monthly_price=399 WHERE slug='120gb'");`],{cwd:root,env});
 for(const locale of ['ro','en','fr','de']){
  await page.goto(origin+'/'+locale+'/#pricing');check(await page.locator('.plan-ron').count()===4,'RON equivalent for every plan '+locale);
  check(await page.locator('.price-net-label,.plan-tax').count()===0,'old tax text removed beneath prices '+locale);
  check((await page.locator('.plan-ron').nth(1).innerText()).includes(locale==='ro'?'0,75':(locale==='en'?'3.99':'3,99')),'monthly RON equivalent uses saved BNR rate '+locale);
  await page.locator('[data-billing-switch] button[value=year]').click();
  check((await page.locator('.plan-ron').nth(1).innerText()).includes(locale==='ro'?'8,24':(locale==='en'?'43.89':'43,89')),'annual RON equivalent updates with switcher '+locale);
 }
 const artifacts=path.join(root,'tests/artifacts/bnr-ron');fs.mkdirSync(artifacts,{recursive:true});
 await page.goto(origin+'/admin?section=exchange');await page.screenshot({path:path.join(artifacts,'admin-desktop.png'),fullPage:true});
 for(const width of [320,390,820,1440]){await page.setViewportSize({width,height:900});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'BNR admin has no horizontal page overflow '+width);}
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(artifacts,'admin-phone.png'),fullPage:true});await page.goto(origin+'/ro/#pricing');await page.locator('#pricing').screenshot({path:path.join(artifacts,'pricing-phone.png')});
 console.log(JSON.stringify({checks,screenshots:artifacts}));
}finally{if(browser)await browser.close();if(server)server.kill();if(temp.startsWith(os.tmpdir()+'/zion-onboarding-'))fs.rmSync(temp,{recursive:true,force:true});}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
