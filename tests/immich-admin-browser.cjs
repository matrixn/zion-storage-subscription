const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),http=require('node:http'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),temp=fs.mkdtempSync(path.join(os.tmpdir(),'zion-onboarding-')),origin='http://127.0.0.1:8099';
let config={machineLearning:{enabled:true,facialRecognition:{enabled:false,modelName:'untouched'},clip:{enabled:true}},map:{enabled:true},oauth:{clientSecret:'never-render-fixture-secret'},passwordLogin:{enabled:true}},writes=0,checks=0;
const backend=http.createServer((req,res)=>{
 if(req.headers['x-api-key']!=='fixture-unused'||req.url!=='/api/system-config'){res.writeHead(403);return res.end('{}');}
 let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{if(req.method==='PUT'){config=JSON.parse(body);writes++;}res.setHeader('Content-Type','application/json');res.end(JSON.stringify(config));});
});
function check(ok,label){if(!ok)throw Error(label);checks++;}
(async()=>{
 let browser,server;
 try{
  await new Promise(resolve=>backend.listen(0,'127.0.0.1',resolve));
  const env={...process.env,APP_ENV:'test',APP_KEY:'isolated-admin-configuration-key-'.repeat(3),APP_URL:origin,DB_PATH:temp+'/app.sqlite',ONBOARDING_TEST_DIRECTORY:temp,MAIL_TRANSPORT:'log',CAPTCHA_ENABLED:'false',OBLIO_ENABLED:'false',STRIPE_SECRET_KEY:'sk_test_fixture',STRIPE_WEBHOOK_SECRET:'whsec_fixture',STORAGE_API_URL:'http://127.0.0.1:'+backend.address().port+'/api',STORAGE_API_KEY:'fixture-unused',LISTMONK_URL:'http://127.0.0.1:9'};
  const sessions=JSON.parse(execFileSync('php',['tests/setup-onboarding.php'],{cwd:root,env,encoding:'utf8'}));
  server=spawn('php',['-d','session.save_path='+temp+'/sessions','-S','127.0.0.1:8099','-t','public','public/router.php'],{cwd:root,env,stdio:'ignore'});
  for(let i=0;i<50;i++){try{await fetch(origin+'/ro/');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}}),page=await ctx.newPage();
  await ctx.addCookies([{name:'zion_admin_session',value:sessions.admin,url:origin+'/admin'}]);
  const response=await page.goto(origin+'/admin?section=immich-general');
  check(response.status()===200,'dedicated admin route');
  check(response.headers()['content-security-policy'].includes("script-src 'none'"),'admin remains functional without scripts');
  check(await page.locator('input[type=checkbox][name="features[facial_recognition]"]').isChecked()===false,'actual global state loaded');
  check(await page.locator('input[type=checkbox][name="defaults[memories]"]').isChecked()===true,'default profile loaded');
  check(!(await page.content()).includes('never-render-fixture-secret'),'no full server configuration in HTML');
  check(await page.locator('.sidebar nav ul ul ul').count()===0,'at most two vertical navigation levels');
  const artifacts=path.join(__dirname,'artifacts','immich-general');fs.mkdirSync(artifacts,{recursive:true});
  await page.screenshot({path:path.join(artifacts,'desktop.png'),fullPage:true});
  for(const [width,height] of [[320,760],[390,844],[820,1180],[1180,820],[1440,1000]]){
   await page.setViewportSize({width,height});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow '+width+'x'+height);
   if(width===390||width===820)await page.screenshot({path:path.join(artifacts,width===390?'phone.png':'tablet.png'),fullPage:true});
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.locator('input[type=checkbox][name="features[facial_recognition]"]').focus();await page.keyboard.press('Space');
  await Promise.all([page.waitForURL('**/admin?section=immich-general'),page.getByRole('button',{name:'Salvează funcțiile serverului',exact:true}).click()]);
  await page.waitForLoadState('networkidle');
  check(config.machineLearning.facialRecognition.enabled===true&&writes===1,'keyboard toggle and explicit save reach backend');
  check(config.oauth.clientSecret==='never-render-fixture-secret'&&config.map.enabled===true,'unrelated secret and map settings preserved');
  await page.locator('input[type=checkbox][name="defaults[cast]"]').check();await page.locator('input[type=checkbox][name="defaults[people]"]').check();await page.locator('input[type=checkbox][name="defaults[memories]"]').uncheck();
  await page.getByRole('button',{name:'Salvează preferințele conturilor noi',exact:true}).click();await page.waitForLoadState('networkidle');
  check(await page.locator('input[type=checkbox][name="defaults[cast]"]').isChecked()&&!await page.locator('input[type=checkbox][name="defaults[memories]"]').isChecked(),'local defaults persist after navigation');
  check(writes===1,'default profile save does not write global config');
  console.log(JSON.stringify({checks,screenshots:artifacts}));
 }finally{if(browser)await browser.close();if(server)server.kill();await new Promise(resolve=>backend.close(resolve));if(temp.startsWith(os.tmpdir()+'/zion-onboarding-'))fs.rmSync(temp,{recursive:true,force:true});}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
