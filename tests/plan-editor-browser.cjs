const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'zion-onboarding-')),origin='http://127.0.0.1:8107';let checks=0;
function check(ok,label){if(!ok)throw Error(label);checks++;console.log('PASS '+label);}
(async()=>{let browser,server;try{
 const env={...process.env,APP_ENV:'test',APP_KEY:'isolated-plan-editor-fixture-'.repeat(3),APP_URL:origin,DB_PATH:temp+'/app.sqlite',ONBOARDING_TEST_DIRECTORY:temp,MAIL_TRANSPORT:'log',MAIL_LOG_DIRECTORY:temp+'/mail',CAPTCHA_ENABLED:'false',STRIPE_SECRET_KEY:'sk_test_fixture',STRIPE_WEBHOOK_SECRET:'whsec_fixture',LISTMONK_URL:''};
 const fixture=JSON.parse(execFileSync('php',['tests/setup-onboarding.php'],{cwd:root,env,encoding:'utf8'}));
 server=spawn('php',['-d','session.save_path='+temp+'/sessions','-S','127.0.0.1:8107','-t','public','public/router.php'],{cwd:root,env,stdio:'ignore'});
 for(let i=0;i<60;i++){try{await fetch(origin+'/ro/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}}),page=await ctx.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await ctx.addCookies([{name:'zion_admin_session',value:fixture.admin,url:origin+'/admin'}]);
 await page.goto(origin+'/admin?section=plans');
 check(await page.locator('[data-plan-editor]').count()===4,'all existing packages editable');
 check(await page.locator('[data-plan-editor]').first().locator('[name=monthly_price]').getAttribute('readonly')!==null,'free package price remains read-only');
 const personal=page.locator('[data-plan-editor]').nth(1);
 await personal.locator('[name=name]').fill('Essential Prime');await personal.locator('[name=monthly_price]').fill('4,59');
 check((await personal.locator('[data-plan-annual]').innerText()).includes('50,49'),'annual preview updates immediately at eleven monthly prices');
 await personal.getByRole('button').click();await page.waitForLoadState('networkidle');
 check((await page.locator('[role=status]').innerText()).includes('Pachet salvat.'),'save confirms persistence independently from unavailable test Stripe transport');
 await page.reload();check(await page.locator('[data-plan-editor]').nth(1).locator('[name=name]').inputValue()==='Essential Prime','saved name survives reload');
 const denied=await ctx.request.post(origin+'/admin?section=plans',{form:{action:'plan_save',plan:'120gb',name:'Forged',monthly_price:'1.00'}});
 check(denied.status()===419,'edit action protected by administrator CSRF');
 for(const locale of ['ro','en','fr','de']){
  await page.goto(origin+'/'+locale+'/');
  check((await page.locator('.plan-name').allTextContents()).includes('Essential Prime'),'saved name appears publicly in '+locale);
  check((await page.locator('.price-amount').nth(1).innerText()).includes(locale==='en'?'4.59':'4,59'),'updated monthly price in '+locale);
  await page.locator('[data-billing-switch] [value=year]').click();
  check((await page.locator('.price-amount').nth(1).innerText()).includes(locale==='en'?'50.49':'50,49'),'annual price stays eleven months in '+locale);
 }
 const dir=path.join(root,'tests/artifacts/plan-editor');fs.mkdirSync(dir,{recursive:true});
 for(const width of [390,820,1440]){
  await page.setViewportSize({width,height:1100});await page.goto(origin+'/admin?section=plans');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'plan editor has no page overflow at '+width);
  await page.screenshot({path:path.join(dir,'admin-'+width+'.png'),fullPage:true});
 }
 check(!errors.length,'no browser JavaScript errors');console.log(JSON.stringify({checks,screenshots:dir}));
}finally{if(browser)await browser.close();if(server)server.kill();fs.rmSync(temp,{recursive:true,force:true});}})().catch(e=>{console.error(e);process.exitCode=1;});
