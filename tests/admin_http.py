"""Isolated admin/session/ban integration test. No external services or real credentials."""
import http.server, threading, subprocess, tempfile, os, time, json, urllib.request, urllib.parse, http.cookiejar, re, sqlite3, sys, hashlib, hmac
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
checks=0
import base64
def check(value,label):
    global checks
    if not value: raise AssertionError(label)
    checks+=1;print('PASS',label,flush=True)
class Backend(http.server.BaseHTTPRequestHandler):
    records=[]
    last_api_key=None
    listmonk_messages=[]
    def log_message(self,*args): pass
    def respond(self,obj,code=200):
        self.send_response(code);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps(obj).encode())
    def do_GET(self):
        if self.path.startswith('/api/templates/'):
            if self.headers.get('Authorization')!='Basic '+base64.b64encode(b'fixture-api-user:fixture-listmonk-api-token-123456').decode():return self.respond({'message':'Unauthorized'},401)
            return self.respond({'data':{'id':7,'type':'tx'}})
        Backend.last_api_key=self.headers.get('x-api-key')
        if self.path=='/api/users/me':
            return self.respond({'id':'immich-admin-test'} if self.headers.get('Authorization')=='Bearer fixture-user' else {},200 if self.headers.get('Authorization')=='Bearer fixture-user' else 401)
        if self.headers.get('x-api-key') not in ['fixture-key','fixture-immich-api-key-123456']: return self.respond({},403)
        if self.path=='/api/server/statistics':
            return self.respond(dict(usage=3_000_000_000,photos=120,videos=7,usageByUser=[dict(userId=u['id'],usage=3_000_000_000,photos=120,videos=7,quotaSizeInBytes=u.get('quotaSizeInBytes')) for u in self.records]))
        return self.respond(self.records if '?' in self.path else self.records[0])
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.path=='/api/tx':
            if self.headers.get('Authorization')!='Basic '+base64.b64encode(b'fixture-api-user:fixture-listmonk-api-token-123456').decode():return self.respond({'message':'Unauthorized'},401)
            if body.get('subscriber_mode')!='external' or body.get('template_id')!=7:return self.respond({'message':'Invalid transactional payload'},400)
            Backend.listmonk_messages.append(body);return self.respond({'data':True})
        if self.headers.get('x-api-key') not in ['fixture-key','fixture-immich-api-key-123456']:return self.respond({},403)
        body.pop('password');body.update(id='immich-admin-test',quotaUsageInBytes=0);self.records.append(body);self.respond(body)
    def do_PUT(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.headers.get('x-api-key') not in ['fixture-key','fixture-immich-api-key-123456']:return self.respond({},403)
        self.records[0].update(body);self.respond(self.records[0])
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(opener,path,data=None,headers=None):
    req=urllib.request.Request('http://127.0.0.1:8093'+path,data=urllib.parse.urlencode(data).encode() if data is not None else None,headers=headers or {})
    try:r=opener.open(req)
    except urllib.error.HTTPError as e:r=e
    return r.status,r.read().decode(),r.headers
def csrf(html):return re.search(r'name="_csrf" value="([a-f0-9]+)"',html).group(1)
with tempfile.TemporaryDirectory(prefix='zion-admin-') as temp:
    backend=http.server.ThreadingHTTPServer(('127.0.0.1',0),Backend);threading.Thread(target=backend.serve_forever,daemon=True).start()
    env=os.environ.copy();env.update(LISTMONK_URL='',APP_ENV='test',APP_URL='http://localhost:8093',DB_PATH=temp+'/app.sqlite',APP_KEY='fixture-key-'*8,ADMIN_EMAIL='admin-fixture@example.com',ADMIN_PASSWORD='fixture-admin-password',MAIL_TRANSPORT='log',MAIL_LOG_DIRECTORY=temp+'/mail',OBLIO_ENABLED='false',STORAGE_API_URL='http://127.0.0.1:'+str(backend.server_port)+'/api',STORAGE_API_KEY='fixture-key',STORAGE_GATEWAY_KEY='fixture-gateway-key-'*3,STORAGE_GATEWAY_ENFORCED='false',STRIPE_SECRET_KEY='sk_test_unconfigured')
    subprocess.run(['php','bin/console.php','install'],cwd=ROOT,env=env,check=True,capture_output=True)
    subprocess.run(['php','-r','require "src/bootstrap.php"; (new Zion\\Database())->run("INSERT INTO integration_settings(key,value) VALUES(?,?)",["billing:vat_bps","2100"]);'],cwd=ROOT,env=env,check=True,capture_output=True) # Exercise explicit 21% configuration; operator default is 0.
    with open(temp+'/server.log','w+') as log:
        server=subprocess.Popen(['php','-S','0.0.0.0:8093','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
        try:
            admin=client();customer=client()
            for _ in range(50):
                try:status,body,headers=request(admin,'/admin');break
                except OSError:time.sleep(.1)
            check(status==200 and 'Autentificare administrator' in body,'admin requires authentication')
            check('noindex' in headers['X-Robots-Tag'] and 'no-store' in headers['Cache-Control'],'admin is private and not indexed')
            check('Comunicare API</h2>' not in request(customer,'/admin?section=api-log')[1],'API journal requires administrative authentication')
            check('path=/admin' in headers['Set-Cookie'].lower() and 'httponly' in headers['Set-Cookie'].lower(),'admin has separate scoped HttpOnly cookie')
            check(request(admin,'/admin',{'action':'login','email':'admin-fixture@example.com','password':'fixture-admin-password'})[0]==419,'admin login requires CSRF')
            token=csrf(body)
            status,body,_=request(admin,'/admin',{'_csrf':token,'action':'login','email':'admin-fixture@example.com','password':'wrong-password'})
            check(status==422 and 'Client nou' not in body,'invalid admin password denied')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'login','email':'admin-fixture@example.com','password':'fixture-admin-password'})
            check(status==200 and 'Totul, dintr-o privire.' in body,'administrator logs in to dashboard with installed env credentials')
            check('name="secret_key"' not in body and 'name="password"' not in body,'dashboard separates configuration and customer forms')
            check(request(admin,'/admin?section=unknown')[0]==404,'unknown admin section is rejected')
            lm_body=request(admin,'/admin?section=listmonk')[1]
            check('LISTMONK_API_TOKEN' in lm_body and 'type="text" name="api_token"' in lm_body,'dedicated Listmonk settings render token as masked text input')
            check(request(customer,'/admin?section=listmonk')[1].find('name="api_token"')==-1,'Listmonk credentials require admin authentication')
            check(request(admin,'/admin',{'action':'listmonk_save'})[0]==419,'Listmonk save requires CSRF')
            lm_input={'action':'listmonk_save','enabled':'1','url':'http://127.0.0.1:'+str(backend.server_port),'api_user':'fixture-api-user','api_token':'fixture-listmonk-api-token-123456','template_id':'7','from_email':'contact@example.com'}
            status,lm_body,_=request(admin,'/admin',dict(lm_input,_csrf=csrf(lm_body)))
            check(status==200 and 'value="fixture••••••••123456"' in lm_body and 'fixture-listmonk-api-token-123456' not in lm_body,'saved Listmonk token appears inside input with seven and six rule')
            status,lm_body,_=request(admin,'/admin',{'_csrf':csrf(lm_body),'action':'listmonk_check'})
            check(status==200 and 'șablonul tranzacțional sunt confirmate' in lm_body,'Listmonk authenticated template check works over real fixture HTTP')
            status,lm_body,_=request(admin,'/admin',{'_csrf':csrf(lm_body),'action':'listmonk_test','test_email':'listmonk-test@example.com'})
            check(status==200 and len(Backend.listmonk_messages)==1 and Backend.listmonk_messages[0]['subscriber_emails']==['listmonk-test@example.com'],'explicit admin test sends Listmonk transaction to chosen recipient')
            queue_script='require "src/bootstrap.php"; $d=new Zion\\Database(); $m=new Zion\\MailerService($d); foreach(["ro","en","fr","de"] as $l)$m->queue("queue-".$l."@example.com",$l,"verify",["url"=>"https://storage.example/verify?token=fixture-queue-action"],"listmonk-queue-".$l); echo $m->work();'
            queue_result=subprocess.run(['php','-r',queue_script],cwd=ROOT,env=env,check=True,capture_output=True,text=True)
            check(queue_result.stdout.strip()=='4' and len(Backend.listmonk_messages)==5,'CLI worker delivers all four localized outbox messages through real Listmonk fixture HTTP')
            check({m['data']['locale'] for m in Backend.listmonk_messages[1:]}=={'ro','en','fr','de'} and all('fixture-queue-action' in m['data']['html'] and 'fixture-queue-action' in m['altbody'] for m in Backend.listmonk_messages[1:]),'transactional messages preserve locale and action links in HTML and plaintext')
            signup_html=request(customer,'/en/register')[1]
            signup_data={'_csrf':csrf(signup_html),'name':'Immediate Mail','email':'immediate-mail@example.com','password':'fixture-mail-password','confirm':'fixture-mail-password','locale':'en','plan':'free','terms':'1','privacy':'1'}
            status,signup_html,_=request(customer,'/en/register',signup_data)
            check(status==200 and len(Backend.listmonk_messages)==7 and Backend.listmonk_messages[-2]['subscriber_emails']==['immediate-mail@example.com'],'signup sends actual verification email immediately without running worker')
            check(Backend.listmonk_messages[-1]['subscriber_emails']==['admin-fixture@example.com'] and Backend.listmonk_messages[-1]['data']['kind']=='admin_signup','new registration immediately notifies configured administrator')
            activation_body=Backend.listmonk_messages[-2]['altbody']
            activation_token=re.search(r'token=([a-f0-9]{64})',activation_body).group(1)
            verify_path='/en/verify-email?token='+activation_token
            check('name="token"' in request(customer,verify_path)[1],'immediately sent signup email contains usable verification link')
            resend_html=request(customer,'/en/resend')[1]
            status,_,_=request(customer,'/en/resend',{'_csrf':csrf(resend_html),'email':'immediate-mail@example.com'})
            check(status==200 and len(Backend.listmonk_messages)==8 and activation_token not in Backend.listmonk_messages[-1]['altbody'],'public resend immediately sends replacement confirmation instead of waiting for worker')
            check(request(admin,'/admin',{'action':'mail_retry'})[0]==419,'manual mail queue processing requires admin CSRF')
            check(sum(m['data']['kind']=='admin_signup' for m in Backend.listmonk_messages)==1,'confirmation resend does not create duplicate signup notification')
            check(request(admin,'/admin',{'action':'fiscal_retry','fiscal_id':'a'*32})[0]==419,'fiscal retry requires administrator CSRF')
            subprocess.run(['php','-r','require "src/bootstrap.php"; (new Zion\\MailerService(new Zion\\Database()))->queue("manual-retry@example.com","en","activated",[],"manual-mail-fixture");'],cwd=ROOT,env=env,check=True,capture_output=True)
            lm_body=request(admin,'/admin?section=listmonk')[1]
            status,lm_body,_=request(admin,'/admin',{'_csrf':csrf(lm_body),'action':'mail_retry'})
            check(status==200 and Backend.listmonk_messages[-1]['subscriber_emails']==['manual-retry@example.com'],'administrator can process pending email without Docker worker')
            worker_before=json.loads(sqlite3.connect(temp+'/app.sqlite').execute("SELECT value FROM integration_settings WHERE key='mail:worker'").fetchone()[0])
            check('finished_at' in worker_before,'CLI mail command records completed worker activity')
            api_body=request(admin,'/admin?section=api-log&provider=listmonk')[1]
            check('/api/tx' in api_body and 'fixture-listmonk-api-token-123456' not in api_body and 'listmonk-test@example.com' not in api_body,'Listmonk calls are filterable in redacted API journal')
            subprocess.run(['php','-r','require "src/bootstrap.php"; $clean=new Zion\\Database(); $clean->run("DELETE FROM email_verifications WHERE user_id IN (SELECT id FROM users WHERE email=?)",["immediate-mail@example.com"]); $clean->run("DELETE FROM users WHERE email=?",["immediate-mail@example.com"]);'],cwd=ROOT,env=env,check=True,capture_output=True) # Keep later fixed dashboard fixtures independent.
            request(admin,'/admin',dict(lm_input,enabled='0',_csrf=csrf(lm_body))) # Restore log transport for other isolated fixtures.
            operations_result=subprocess.run(['php','bin/console.php','operations:work'],cwd=ROOT,env=env,check=True,capture_output=True,text=True)
            check('fiscal' in json.loads(operations_result.stdout) and 'mail_accepted' in json.loads(operations_result.stdout),'single CLI command processes fiscal queue and email queue')
            tax_body=request(admin,'/admin?section=tax')[1]
            check('name="vat_rate"' in tax_body and 'value="21"' in tax_body and '2,41 EUR' in tax_body,'admin tax settings default to 21 percent and preview gross total')
            check(request(admin,'/admin',{'action':'tax_save','vat_rate':'19'})[0]==419,'VAT updates require admin CSRF')
            status,tax_body,_=request(admin,'/admin',{'_csrf':csrf(tax_body),'action':'tax_save','vat_rate':'19'})
            check(status==200 and 'value="19"' in tax_body and '2,37 EUR' in tax_body,'admin can change global exclusive VAT')
            status,tax_body,_=request(admin,'/admin',{'_csrf':csrf(tax_body),'action':'tax_save','vat_rate':'101'})
            check(status==422 and 'value="19"' in tax_body,'invalid VAT does not replace saved rate')
            request(admin,'/admin',{'_csrf':csrf(tax_body),'action':'tax_save','vat_rate':'21'})
            for loc in ['ro','en','fr','de']:
                html=request(customer,'/'+loc+'/')[1]
                check('2,41' in html or '2.41' in html,'localized public pricing discloses VAT and gross total '+loc)
            immich_body=request(admin,'/admin?section=immich')[1]
            check('Testează conexiunea Immich' in immich_body and 'Sincronizează acum statisticile' in immich_body,'Immich has dedicated configuration and diagnostic page')
            check(request(admin,'/admin',{'action':'immich_save','url':'https://invalid.example/api','api_key':'invalid'})[0]==419,'Immich settings require administrative CSRF')
            status,immich_body,_=request(admin,'/admin',{'_csrf':csrf(immich_body),'action':'immich_save','url':env['STORAGE_API_URL'],'api_key':'fixture-immich-api-key-123456'})
            check(status==200 and 'fixture••••••••123456' in immich_body and 'fixture-immich-api-key-123456' not in immich_body,'saved Immich key displays seven and six character preview only')
            status,immich_body,_=request(admin,'/admin',{'_csrf':csrf(immich_body),'action':'immich_check'})
            check(status==200 and 'Conexiunea Immich funcționează' in immich_body and Backend.last_api_key=='fixture-immich-api-key-123456','Immich connection test reads authenticated statistics using stored settings')
            body=request(admin,'/admin?section=catalog')[1]
            check('Catalog Stripe' in body and '21,89' in body and 'Titan' in body,'admin exposes six-option EUR catalog')
            check(request(admin,'/admin',{'action':'catalog_disable','plan':'120gb','billing_interval':'year'})[0]==419,'catalog writes require admin CSRF')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'catalog_save','plan':'120gb','billing_interval':'year','price_id':'invalid'})
            check(status==422,'catalog rejects invalid Price ID before network request')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'create','name':'Admin Test Client','email':'admin-client@example.com','password':'secure-admin-client-password','plan':'250gb','locale':'ro'})
            check(status==200 and 'Client creat' in body,'admin creates customer with selected manual plan')
            db=sqlite3.connect(temp+'/app.sqlite');db.row_factory=sqlite3.Row;u=dict(db.execute("SELECT * FROM users WHERE email='admin-client@example.com'").fetchone())
            check(u['selected_plan_id']=='250gb' and not u['email_verified_at'] and not u['terms_at'],'manual client must verify email and accept terms')
            check(db.execute('SELECT COUNT(*) FROM subscriptions').fetchone()[0]==0,'manual grant does not create a paid subscription')
            db.close()
            php='require "src/bootstrap.php"; $db=new Zion\\Database(); $r=$db->one("SELECT payload FROM outbox ORDER BY id DESC LIMIT 1"); echo json_encode(Zion\\MailerService::decode($r["payload"]));'
            payload=json.loads(subprocess.run(['php','-r',php],cwd=ROOT,env=env,check=True,capture_output=True,text=True).stdout)
            status,body,_=request(admin,'/admin?section=clients')
            check('Vezi linkul de activare' in body and payload['url'] not in body,'client list offers explicit link disclosure without exposing tokens by default')
            check(request(admin,'/admin',{'action':'activation_link','user_id':u['id']})[0]==419,'activation link disclosure requires administrator CSRF')
            status,body,headers=request(admin,'/admin',{'_csrf':csrf(body),'action':'activation_link','user_id':u['id']})
            check(status==200 and payload['url'] in body and 'readonly' in body and 'no-store' in headers['Cache-Control'],'admin reveals original activation link without email delivery')
            check(payload['url'] not in request(admin,'/admin?section=clients')[1],'activation link is not persistently exposed after reload')
            db=sqlite3.connect(temp+'/app.sqlite')
            check(payload['url'] not in ' '.join(str(row) for row in db.execute('SELECT detail FROM admin_audit')),'activation URLs are excluded from audit records');db.close()
            parsed=urllib.parse.urlparse(payload['url']);path=parsed.path+'?'+parsed.query
            status,html,_=request(customer,path)
            status,html,_=request(customer,parsed.path,{'_csrf':csrf(html),'token':urllib.parse.parse_qs(parsed.query)['token'][0]})
            check(status==200 and 'name="accept"' in html,'verified manual customer reaches terms and activation, not checkout')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'activation_link','user_id':u['id']})
            check(status==422 and 'confirmat deja' in body and payload['url'] not in body,'verified clients cannot receive another new-client activation link')
            data={'_csrf':csrf(html),'password':'secure-admin-client-password'}
            check(request(customer,'/ro/finalizare',data)[0]==422,'admin cannot accept legal terms on behalf of customer')
            data['accept']='1';status,html,_=request(customer,'/ro/finalizare',data)
            check(status==200 and len(Backend.records)==1 and Backend.records[0]['quotaSizeInBytes']==250_000_000_000,'manual customer activates selected Immich quota')
            immich_body=request(admin,'/admin?section=immich')[1]
            check(request(admin,'/admin',{'action':'immich_sync'})[0]==419,'Immich manual sync requires administrator CSRF')
            status,immich_body,_=request(admin,'/admin',{'_csrf':csrf(immich_body),'action':'immich_sync'})
            check(status==200 and 'sincronizate pentru 1 clienți' in immich_body,'manual Immich sync persists statistics through actual HTTP adapter')
            dashboard_html=request(admin,'/admin')[1]
            check('3,00 GB' in dashboard_html and 'Top 10 clienți' in dashboard_html and '250,00 GB' in dashboard_html,'dashboard shows measured usage and package capacity')
            check('120 poze' in request(admin,'/admin?section=clients')[1],'per-client management shows photo and video statistics')
            run=subprocess.run(['php','bin/console.php','immich:sync','--due'],cwd=ROOT,env=env,capture_output=True,text=True,check=True)
            check(json.loads(run.stdout)['status']=='skipped','CLI five-minute sync respects most recent manual collection')

            check('Autentificare administrator' in request(customer,'/admin')[1],'customer session cannot access administration')
            admin_html=request(admin,'/admin')[1]
            check(request(admin,'/admin',{'_csrf':csrf(html),'action':'ban','user_id':u['id'],'reason':'Wrong session'})[0]==419,'customer CSRF cannot authorize admin changes')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(admin_html),'action':'ban','user_id':u['id'],'reason':'Test suspension'})
            check(status==200 and 'gateway_required' in body and Backend.records[0]['quotaSizeInBytes']==1,'ban preserves files and exposes missing gateway enforcement')
            check('Cont suspendat' in request(customer,'/ro/activare')[1],'customer sees suspended state')
            gate={'X-Zion-Gateway-Key':env['STORAGE_GATEWAY_KEY'],'X-Original-URI':'/api/assets/123','Authorization':'Bearer fixture-user'}
            check(request(customer,'/internal/storage-access',headers=gate)[0]==403,'existing Immich session denied by ban gateway')
            check(request(customer,'/internal/storage-access',headers={'X-Zion-Gateway-Key':'wrong','X-Original-URI':'/api/auth/login'})[0]==403,'internal gateway requires shared secret')
            gate['X-Original-URI']='/api/auth/login';check(request(customer,'/internal/storage-access',headers=gate)[0]==204,'gateway permits public login bootstrap')
            request(admin,'/admin',{'_csrf':csrf(body),'action':'unban','user_id':u['id'],'reason':'Resolved test'})
            gate['X-Original-URI']='/api/assets/123';check(request(customer,'/internal/storage-access',headers=gate)[0]==204,'unban restores authenticated Immich access')
            check(Backend.records[0]['quotaSizeInBytes']==250_000_000_000,'unban restores current entitlement quota')
            body=request(admin,'/admin')[1]
            request(admin,'/admin',{'_csrf':csrf(body),'action':'plan','user_id':u['id'],'plan':'120gb','reason':'Manual downgrade'})
            check(Backend.records[0]['quotaSizeInBytes']==120_000_000_000,'admin manual downgrade synchronizes storage')
            body=request(admin,'/admin')[1]
            body=request(admin,'/admin?section=integrations')[1]
            check('API-uri Stripe' in body and 'Secret API Oblio' in body,'admin exposes dedicated API settings section')
            check('https://dashboard.stripe.com/apikeys' in body and 'Reveal secret' in body and 'Setări → Date Cont' in body and 'stripe listen --forward-to' in body,'API fields include official credential and local webhook setup guidance')
            check(request(admin,'/admin',{'action':'settings_save','provider':'stripe','secret_key':'sk_test_httpSaved'})[0]==419,'API settings writes require admin CSRF')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'settings_save','provider':'stripe','secret_key':'sk_test_httpSaved','webhook_secret':'whsec_httpSaved'})
            check(status==200 and 'Valorile din .env' in body and 'sk_test_httpSaved' not in body and 'whsec_httpSaved' not in body,'Stripe settings save without exposing secrets in HTML')
            check('sk_test••••••••pSaved' in body and 'whsec_h••••••••pSaved' in body,'saved TEST keys show partial previews')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'settings_save','provider':'stripe','secret_key':'sk_test••••••••pSaved','webhook_secret':'whsec_h••••••••pSaved'})
            check(status==200 and 'value="sk_test••••••••pSaved"' in body and 'value="whsec_h••••••••pSaved"' in body,'saving displayed input values preserves credentials and renders actual masked values')
            check('Verificare nereușită' in body and 'Nesincronizat' not in body,'automatic sync reports isolated transport failure instead of false success')
            check(request(admin,'/admin',{'action':'stripe_check'})[0]==419 and request(admin,'/admin',{'action':'stripe_sync'})[0]==419,'connection test and synchronization require CSRF')
            check(request(admin,'/admin',{'action':'stripe_event_replay','event_id':'evt_fixture'})[0]==419,'Stripe event recovery requires administrator CSRF')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'stripe_event_replay','event_id':'evt_fixture','credential_mode':'test'})
            check(status==422 and 'testele izolate' in body,'event recovery never contacts Stripe from isolated HTTP tests')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'stripe_check','credential_mode':'test'})
            check(status==200 and 'Verificare nereușită' in body,'connection diagnostic fails safely without contacting external services in test environment')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'settings_save','provider':'stripe','secret_key':'sk_live_httpRejected'})
            check(status==422 and 'sk_live_httpRejected' not in body,'unsupported Stripe mode is rejected without echoing key')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'settings_save','provider':'stripe','credential_mode':'live','secret_key':'sk_live_fake','webhook_secret':'whsec_liveFixture'})
            check(status==200 and 'Activează LIVE pe site' in body and 'sk_live_fake' not in body,'LIVE credentials are saved separately with explicit activation control')
            check('whsec_l••••••••ixture' in body and 'sk_test••••••••pSaved' not in body,'LIVE previews stay separate from TEST and short keys remain masked')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'stripe_activate','credential_mode':'live'})
            check(status==422 and 'Modul nu a fost activat' in body,'activation is refused when API/catalog verification is incomplete')
            for mode,secret in [('test','whsec_httpSaved'),('live','whsec_liveFixture')]:
                payload=json.dumps({'id':'evt_http_'+mode,'object':'event','livemode':mode=='live','type':'integration.fixture','data':{'object':{}}}).encode()
                timestamp=str(int(time.time()));signature='t='+timestamp+',v1='+hmac.new(secret.encode(),timestamp.encode()+b'.'+payload,hashlib.sha256).hexdigest()
                event=urllib.request.Request('http://127.0.0.1:8093/webhooks/stripe?mode='+mode,data=payload,headers={'Content-Type':'application/json','Stripe-Signature':signature})
                check(urllib.request.urlopen(event).status==204,'signed webhook reaches correct mode '+mode)
                health=request(admin,'/admin?section=integrations&stripe_mode='+mode)[1]
                check('Eveniment semnat primit' in health,'webhook health reflects verified HTTP receipt '+mode)
            payload=json.dumps({'id':'evt_http_rejected','object':'event','livemode':False,'type':'invoice.paid','data':{'object':{'id':'in_fixture'}}}).encode()
            rejected=urllib.request.Request('http://127.0.0.1:8093/webhooks/stripe?mode=test',data=payload,headers={'Content-Type':'application/json','Stripe-Signature':'t=1,v1=invalid'})
            try:urllib.request.urlopen(rejected);check(False,'invalid webhook accepted')
            except urllib.error.HTTPError as error:check(error.code==400,'invalid webhook signature is rejected over HTTP')
            sandbox={'ro':'Sandbox: conturile noi folosesc plăți de test.','en':'Sandbox: new accounts use test payments.','fr':'Sandbox : paiements de test pour les nouveaux comptes.','de':'Sandbox: Neue Konten verwenden Testzahlungen.'}
            for locale,text in sandbox.items():check(text in request(customer,'/'+locale+'/')[1],'localized public sandbox notice '+locale)
            # Fixture-only mode change exercises rendering without remote API operations.
            switch='require "src/bootstrap.php";$db=new Zion\\Database();$profiles=(new Zion\\IntegrationSettings($db))->stripeProfiles();$profiles["active_mode"]=$argv[1];$db->run("UPDATE integration_settings SET value=? WHERE key=?", [Zion\\EncryptedPayload::encode($profiles),"provider:stripe"]);'
            subprocess.run(['php','-r',switch,'live'],cwd=ROOT,env=env,check=True,capture_output=True)
            for locale in sandbox:check('sandbox-notice' not in request(customer,'/'+locale+'/')[1],'sandbox notice hidden in LIVE '+locale)
            subprocess.run(['php','-r',switch,'test'],cwd=ROOT,env=env,check=True,capture_output=True)
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'settings_save','provider':'oblio','email':'oblio@example.com','secret':'httpOblioSecret','cif':'RO12345678','series':'ZS','language':'RO','vat_rate':'0','vat_name':'Scutit'})
            check(status==200 and 'httpOblioSecret' not in body and 'Configurat din administrare' in body,'Oblio disabled configuration saves without exposing API secret')
            check('httpObl••••••••Secret' in body,'saved Oblio secret shows partial preview')
            api_seed='require "src/bootstrap.php";Zion\\ApiLog::record("stripe","GET","https://api.stripe.com/v1/products",0,microtime(true),["mode"=>"test","code"=>"curl_60","message"=>"SSL certificate problem: unable to get local issuer certificate <script>alert(1)</script>"]);'
            subprocess.run(['php','-r',api_seed],cwd=ROOT,env=env,check=True,capture_output=True)
            status,body,headers=request(admin,'/admin?section=api-log&provider=immich&status=ok')
            check(status==200 and 'Comunicare API' in body and 'Immich' in body and 'Reușită' in body and 'HTTP 200' in body,'API journal records real HTTP communication with isolated Immich fixture')
            check('no-store' in headers['Cache-Control'] and 'admin-client@example.com' not in body and 'fixture-key' not in body,'API journal is private and excludes request and response personal data')
            status,body,_=request(admin,'/admin?section=api-log&provider=stripe&direction=in&mode=test')
            check('Tip de eveniment nefolosit' in body and 'HTTP 204' in body and 'Semnătură' in body and 'valid' in body and 'evt_http_test' in body,'API journal distinguishes valid signature from ignored webhook processing')
            status,body,_=request(admin,'/admin?section=api-log&provider=stripe&event=evt_http_rejected')
            check('signature_invalid' in body and 'HTTP 400' in body and 'Etapă' in body and 'Corelează' in body,'webhook event filter exposes failed signature stage and correlation link')
            status,body,_=request(admin,'/admin?section=api-log&provider=stripe&mode=test&status=error')
            check('curl_60' in body and 'issuer certificate' in body and 'certificatele CA' in body and '&lt;script&gt;' in body and '<script>' not in body,'API journal renders detailed TLS error and escapes provider text')
            db=sqlite3.connect(temp+'/app.sqlite')
            raw=' '.join(r[0] for r in db.execute('SELECT value FROM integration_settings'))+' '.join(r[0] for r in db.execute('SELECT detail FROM admin_audit'))
            check(all(secret not in raw for secret in ['sk_test_httpSaved','whsec_httpSaved','httpOblioSecret','fixture-immich-api-key-123456']),'stored API settings and admin audit do not expose plaintext secrets')
            db.close()
            for reset_attempt in range(2):
                status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'create','name':'Disposable Reset','email':'reset-http@example.com','password':'fixture-reset-http-password','plan':'free','locale':'ro'})
                check(status==200,'same reset email can create a new customer')
                db=sqlite3.connect(temp+'/app.sqlite');reset_uid=db.execute('SELECT id FROM users WHERE email=?',('reset-http@example.com',)).fetchone()[0];db.close()
                check(request(admin,'/admin',{'action':'test_account_reset','user_id':reset_uid})[0]==419,'test account deletion requires CSRF')
                status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'test_account_reset','user_id':reset_uid,'confirm_email':'wrong@example.com','confirm_reset':'1'})
                check(status==422 and 'exact emailul' in body,'test deletion requires matching email confirmation')
                status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'test_account_reset','user_id':reset_uid,'confirm_email':'reset-http@example.com','confirm_reset':'1'})
                db=sqlite3.connect(temp+'/app.sqlite')
                check(status==200 and 'au fost șterse' in body and not db.execute('SELECT id FROM users WHERE id=?',(reset_uid,)).fetchone(),'confirmed TEST deletion removes customer and frees signup email')
                check(db.execute('SELECT status,snapshot FROM test_account_resets WHERE user_id=?',(reset_uid,)).fetchone()==('complete','{}'),'completed reset keeps only opaque recovery marker');db.close()
            public_withdrawal=request(customer,'/en/contact?request=withdrawal')[1]
            status,receipt,_=request(customer,'/en/contact?request=withdrawal',{'_csrf':csrf(public_withdrawal),'request_type':'withdrawal','name':'HTTP Withdrawal','email':'withdraw-http@example.com','contract':'Personal invoice 123'})
            check(status==200 and 'Received:' in receipt,'withdrawal receipt is available without administrative privileges')
            withdrawal_html=request(admin,'/admin?section=legal-requests')[1]
            check('HTTP Withdrawal' in withdrawal_html and 'withdraw-http@example.com' in withdrawal_html,'admin has dedicated withdrawal intake view')
            request_id=re.search(r'name="request_id" value="([a-f0-9]+)"',withdrawal_html).group(1)
            check(request(admin,'/admin',{'action':'withdrawal_resolve','request_id':request_id,'notes':'Handled through support'})[0]==419,'withdrawal resolution requires admin CSRF')
            status,withdrawal_html,_=request(admin,'/admin',{'_csrf':csrf(withdrawal_html),'action':'withdrawal_resolve','request_id':request_id,'notes':'Handled through support'})
            check(status==200 and 'Handled through support' in withdrawal_html,'admin records withdrawal resolution without automatic financial action')
            if '--browser' in sys.argv:
                db=sqlite3.connect(temp+'/app.sqlite');now=int(time.time());browser_id='b'*32;verify_token='c'*64
                db.execute('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,0,0,?,?)',('d'*32,'Pending Browser','pending-browser@example.com',u['password_hash'],'ro','free',now,now))
                db.execute('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,storage_user_id,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',(browser_id,'Upgrade Browser','upgrade-browser@example.com',u['password_hash'],'ro','120gb',now,'browser-storage','active',now,now,now,now))
                db.execute("INSERT INTO subscriptions(user_id,stripe_customer_id,stripe_subscription_id,stripe_price_id,status,plan,renewal_at,paid_confirmed,currency) VALUES(?,?,?,?,?,?,?,1,'eur')",(browser_id,'cus_browser','sub_browser','price_browser','active','120gb',now+2*86400))
                db.execute("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)",('in_dashboard_eur',browser_id,199,'eur',now))
                db.execute("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)",('in_dashboard_ron',browser_id,999,'ron',now))
                for slug,price,amount in [('120gb','price_browser',199),('250gb','price_browser_plus',359),('500gb','price_browser_titan',599)]:
                    db.execute("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,status,created_at,updated_at) VALUES(?,?,'month','eur',?,'active',?,?)",(price,slug,amount,now,now))
                db.execute('INSERT INTO subscription_origins VALUES(?,?,?,?,?)',('sub_browser',browser_id,now-86400,now+29*86400,'in_browser'))
                import hashlib
                stats_state=json.loads(db.execute("SELECT value FROM integration_settings WHERE key='immich:statistics:state'").fetchone()[0])
                db.execute('INSERT INTO immich_statistics(user_id,storage_user_id,used_bytes,photos,videos,remote_quota_bytes,synced_at,config_hash,available) VALUES(?,?,?,?,?,?,?,?,1)',(browser_id,'browser-storage',3_000_000_000,123,4,120_000_000_000,now,stats_state['config_hash']))
                db.execute('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)',(hashlib.sha256(verify_token.encode()).hexdigest(),browser_id,now+3600));db.commit();db.close()
                node=os.environ['ZION_NODE'];script=os.environ.get('ZION_BROWSER_SCRIPT',str(ROOT/'tests/admin-browser.cjs'))
                subprocess.run([node,script,'http://localhost:8093/ro/verificare-email?token='+verify_token],cwd=ROOT,env=env,check=True)
            body=request(admin,'/admin')[1];status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'logout'})
            check(status==200 and 'Autentificare administrator' in body,'logout removes administrative access')
            log.flush();log.seek(0);text=log.read();check(all(secret not in text for secret in ['secure-admin-client-password','fixture-admin-password','sk_test_httpSaved','whsec_httpSaved','httpOblioSecret','fixture-immich-api-key-123456']),'server logs contain no plaintext credentials')
            print(f'{checks} admin HTTP assertions passed. No external services contacted.')
        finally:server.terminate();server.wait(timeout=10);backend.shutdown()
