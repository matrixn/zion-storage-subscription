const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'zion-onboarding-')),origin='http://127.0.0.1:8102';let checks=0;
function check(ok,label){if(!ok)throw Error(label);checks++;console.log('PASS '+label);}
(async()=>{let browser,server;try{
 const env={...process.env,APP_ENV:'test',APP_KEY:'isolated-customer-documents-key-'.repeat(3),APP_URL:origin,DB_PATH:temp+'/app.sqlite',ONBOARDING_TEST_DIRECTORY:temp,MAIL_TRANSPORT:'log',MAIL_LOG_DIRECTORY:temp+'/mail',CAPTCHA_ENABLED:'false',OBLIO_ENABLED:'false',STRIPE_SECRET_KEY:'sk_test_fixture',STRIPE_WEBHOOK_SECRET:'whsec_fixture',LISTMONK_URL:''};
 const fixtures=JSON.parse(execFileSync('php',['tests/setup-customer-documents.php'],{cwd:root,env,encoding:'utf8'}));
 server=spawn('php',['-d','session.save_path='+temp+'/sessions','-S','127.0.0.1:8102','-t','public','public/router.php'],{cwd:root,env,stdio:'ignore'});
 for(let i=0;i<60;i++){try{await fetch(origin+'/ro/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true});const admin=await browser.newContext({viewport:{width:1440,height:1000},permissions:['clipboard-read','clipboard-write']}),page=await admin.newPage();
 await admin.addCookies([{name:'zion_admin_session',value:fixtures.admin,url:origin+'/admin'}]);
 let response=await page.goto(origin+'/admin?section=client&user_id='+fixtures.customer_id);
 check(response.status()===200 && await page.locator('#customer-guide-url').count()===1,'admin customer details show recoverable onboarding URL');
 check(await page.locator('#customer-invoices tbody tr').count()===2 && await page.locator('#customer-invoices tbody a[href*=download_invoice]').count()===2,'customer history lists all invoices with separate PDF downloads');
 check(await page.getByRole('button',{name:'Trimite factura prin email',exact:false}).count()===2,'each issued invoice has an email attachment action');
 check(response.headers()['content-security-policy'].includes("script-src 'self'") && !response.headers()['content-security-policy'].includes("'unsafe-inline'"),'admin clipboard script uses restrictive self CSP');
 await page.getByRole('button',{name:'Copiază linkul',exact:true}).click();
 check(await page.evaluate(()=>navigator.clipboard.readText())===fixtures.guide_url,'one-click copy preserves complete personal link');
 const artifacts=path.join(root,'tests/artifacts/customer-documents');fs.mkdirSync(artifacts,{recursive:true});
 await page.screenshot({path:path.join(artifacts,'desktop.png'),fullPage:true});
 for(const [width,height] of [[320,760],[390,844],[820,1180],[1440,1000]]){
  await page.setViewportSize({width,height});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'customer detail has no horizontal overflow '+width);
  if(width===390)await page.screenshot({path:path.join(artifacts,'phone.png'),fullPage:true});
 }
 await page.getByRole('button',{name:'Trimite factura prin email',exact:false}).first().click();await page.waitForLoadState('networkidle');
 check((await page.content()).includes('Emailul nu a fost trimis încă; a rămas în coadă'),'provider PDF outage keeps invoice email pending without false success');
 const customer=await browser.newContext(),guide=await customer.newPage();
 await guide.goto(fixtures.guide_url);await guide.waitForURL('**/ro/activare');
 check(await guide.locator('[data-onboarding]').count()===1,'fragment link opens existing device-aware onboarding guide');
 check(!await guide.locator('form[action="/ro/facturare"]').count(),'guide-only session hides billing portal');
 response=await guide.goto(origin+'/ro/date-facturare');check(response.status()===403 && !(await guide.content()).includes('data-billing-type'),'guide token cannot access billing details');
 response=await guide.goto(origin+'/admin?section=client&user_id='+fixtures.customer_id);check((await guide.content()).includes('Autentificare administrator'),'customer guide cannot access admin details');
 await page.getByRole('button',{name:'Regenerează linkul și trimite email',exact:true}).click();await page.waitForLoadState('networkidle');
 check((await page.locator('[role=status]').first().innerText()).includes('Am regenerat linkul') && (await page.content()).includes('simulat local'),'regeneration reports explicit local email simulation rather than false delivery');
 const renewed=await page.locator('#customer-guide-url').inputValue();check(renewed!==fixtures.guide_url,'admin regeneration rotates token');
 await guide.goto(fixtures.guide_url);await guide.waitForLoadState('networkidle');check((await guide.content()).includes('Linkul a expirat'),'old link denied after regeneration');
 await guide.goto(renewed);await guide.waitForURL('**/ro/activare');check(await guide.locator('[data-onboarding]').count()===1,'new link restores guide access');
 await page.goto(origin+'/admin?section=client&user_id='+fixtures.edit_id);
 await page.locator('input[name=name]').first().fill('Edited Customer');await page.locator('select[name=locale]').selectOption('de');
 await page.getByRole('button',{name:'Salvează datele de contact',exact:true}).click();await page.waitForLoadState('networkidle');
 check(await page.locator('input[name=name]').first().inputValue()==='Edited Customer' && await page.locator('select[name=locale]').inputValue()==='de','customer contact and locale persist on detail page');
 response=await page.goto(origin+'/admin?section=client&user_id=missing');check(response.status()===404,'missing customer has safe 404');
 for(const locale of ['ro','en','fr','de']){await page.goto(origin+'/'+locale+'/');check((await page.content()).includes('5 GB') || (await page.content()).includes('5 Go'),'5 GB free plan visible in '+locale);}
 console.log(JSON.stringify({checks,screenshots:artifacts}));
}finally{if(browser)await browser.close();if(server)server.kill();if(temp.startsWith(os.tmpdir()+'/zion-onboarding-'))fs.rmSync(temp,{recursive:true,force:true});}})().catch(error=>{console.error(error.stack);process.exitCode=1;});
