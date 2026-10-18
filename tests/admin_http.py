"""Isolated admin/session/ban integration test. No external services or real credentials."""
import http.server, threading, subprocess, tempfile, os, time, json, urllib.request, urllib.parse, http.cookiejar, re, sqlite3, sys, hashlib, hmac
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
checks=0
def check(value,label):
    global checks
    if not value: raise AssertionError(label)
    checks+=1;print('PASS',label,flush=True)
class Backend(http.server.BaseHTTPRequestHandler):
    records=[]
    def log_message(self,*args): pass
    def respond(self,obj,code=200):
        self.send_response(code);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps(obj).encode())
    def do_GET(self):
        if self.path=='/api/users/me':
            return self.respond({'id':'immich-admin-test'} if self.headers.get('Authorization')=='Bearer fixture-user' else {},200 if self.headers.get('Authorization')=='Bearer fixture-user' else 401)
        if self.headers.get('x-api-key')!='fixture-key': return self.respond({},403)
        return self.respond(self.records if '?' in self.path else self.records[0])
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.headers.get('x-api-key')!='fixture-key':return self.respond({},403)
        body.pop('password');body.update(id='immich-admin-test',quotaUsageInBytes=0);self.records.append(body);self.respond(body)
    def do_PUT(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.headers.get('x-api-key')!='fixture-key':return self.respond({},403)
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
    env=os.environ.copy();env.update(APP_ENV='test',APP_URL='http://localhost:8093',DB_PATH=temp+'/app.sqlite',APP_KEY='fixture-key-'*8,ADMIN_EMAIL='admin-fixture@example.com',ADMIN_PASSWORD='fixture-admin-password',MAIL_TRANSPORT='log',MAIL_LOG_DIRECTORY=temp+'/mail',OBLIO_ENABLED='false',STORAGE_API_URL='http://127.0.0.1:'+str(backend.server_port)+'/api',STORAGE_API_KEY='fixture-key',STORAGE_GATEWAY_KEY='fixture-gateway-key-'*3,STORAGE_GATEWAY_ENFORCED='false',STRIPE_SECRET_KEY='sk_test_unconfigured')
    subprocess.run(['php','bin/console.php','install'],cwd=ROOT,env=env,check=True,capture_output=True)
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
            body=request(admin,'/admin?section=catalog')[1]
            check('Catalog Stripe' in body and '21,89' in body and 'Titan' in body,'admin exposes six-option EUR catalog')
            check(request(admin,'/admin',{'action':'catalog_disable','plan':'120gb','billing_interval':'year'})[0]==419,'catalog writes require admin CSRF')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'catalog_save','plan':'120gb','billing_interval':'year','price_id':'invalid'})
            check(status==422,'catalog rejects invalid Price ID before network request')
            status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'create','name':'Admin Test Client','email':'admin-client@example.com','password':'secure-admin-client-password','plan':'250gb','locale':'ro'})
            check(status==200 and 'Client creat' in body,'admin creates customer with selected manual plan')
            db=sqlite3.connect(temp+'/app.sqlite');db.row_factory=sqlite3.Row;u=dict(db.execute('SELECT * FROM users').fetchone())
            check(u['selected_plan_id']=='250gb' and not u['email_verified_at'] and not u['terms_at'],'manual client must verify email and accept terms')
            check(db.execute('SELECT COUNT(*) FROM subscriptions').fetchone()[0]==0,'manual grant does not create a paid subscription')
            db.close()
            php='require "src/bootstrap.php"; $db=new Zion\\Database(); $r=$db->one("SELECT payload FROM outbox ORDER BY id DESC LIMIT 1"); echo json_encode(Zion\\MailerService::decode($r["payload"]));'
            payload=json.loads(subprocess.run(['php','-r',php],cwd=ROOT,env=env,check=True,capture_output=True,text=True).stdout)
            parsed=urllib.parse.urlparse(payload['url']);path=parsed.path+'?'+parsed.query
            status,html,_=request(customer,path)
            status,html,_=request(customer,parsed.path,{'_csrf':csrf(html),'token':urllib.parse.parse_qs(parsed.query)['token'][0]})
            check(status==200 and 'name="accept"' in html,'verified manual customer reaches terms and activation, not checkout')
            data={'_csrf':csrf(html),'password':'secure-admin-client-password'}
            check(request(customer,'/ro/finalizare',data)[0]==422,'admin cannot accept legal terms on behalf of customer')
            data['accept']='1';status,html,_=request(customer,'/ro/finalizare',data)
            check(status==200 and len(Backend.records)==1 and Backend.records[0]['quotaSizeInBytes']==250_000_000_000,'manual customer activates selected Immich quota')
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
            check('Webhook procesat.' in body and 'HTTP 204' in body,'API journal includes incoming Stripe TEST webhooks')
            status,body,_=request(admin,'/admin?section=api-log&provider=stripe&mode=test&status=error')
            check('curl_60' in body and 'issuer certificate' in body and 'certificatele CA' in body and '&lt;script&gt;' in body and '<script>' not in body,'API journal renders detailed TLS error and escapes provider text')
            db=sqlite3.connect(temp+'/app.sqlite')
            raw=' '.join(r[0] for r in db.execute('SELECT value FROM integration_settings'))+' '.join(r[0] for r in db.execute('SELECT detail FROM admin_audit'))
            check(all(secret not in raw for secret in ['sk_test_httpSaved','whsec_httpSaved','httpOblioSecret']),'stored API settings and admin audit do not expose plaintext secrets')
            db.close()
            if '--browser' in sys.argv:
                db=sqlite3.connect(temp+'/app.sqlite');now=int(time.time());browser_id='b'*32;verify_token='c'*64
                db.execute('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,storage_user_id,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',(browser_id,'Upgrade Browser','upgrade-browser@example.com',u['password_hash'],'ro','120gb',now,'browser-storage','active',now,now,now,now))
                db.execute("INSERT INTO subscriptions(user_id,stripe_customer_id,stripe_subscription_id,stripe_price_id,status,plan,renewal_at,paid_confirmed,currency) VALUES(?,?,?,?,?,?,?,1,'eur')",(browser_id,'cus_browser','sub_browser','price_browser','active','120gb',now+2*86400))
                db.execute("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)",('in_dashboard_eur',browser_id,199,'eur',now))
                db.execute("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)",('in_dashboard_ron',browser_id,999,'ron',now))
                for slug,price,amount in [('120gb','price_browser',199),('250gb','price_browser_plus',359),('500gb','price_browser_titan',599)]:
                    db.execute("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,status,created_at,updated_at) VALUES(?,?,'month','eur',?,'active',?,?)",(price,slug,amount,now,now))
                db.execute('INSERT INTO subscription_origins VALUES(?,?,?,?,?)',('sub_browser',browser_id,now-86400,now+29*86400,'in_browser'))
                import hashlib
                db.execute('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)',(hashlib.sha256(verify_token.encode()).hexdigest(),browser_id,now+3600));db.commit();db.close()
                node=os.environ['ZION_NODE'];script=os.environ.get('ZION_BROWSER_SCRIPT',str(ROOT/'tests/admin-browser.cjs'))
                subprocess.run([node,script,'http://localhost:8093/ro/verificare-email?token='+verify_token],cwd=ROOT,env=env,check=True)
            body=request(admin,'/admin')[1];status,body,_=request(admin,'/admin',{'_csrf':csrf(body),'action':'logout'})
            check(status==200 and 'Autentificare administrator' in body,'logout removes administrative access')
            log.flush();log.seek(0);text=log.read();check(all(secret not in text for secret in ['secure-admin-client-password','fixture-admin-password','sk_test_httpSaved','whsec_httpSaved','httpOblioSecret']),'server logs contain no plaintext credentials')
            print(f'{checks} admin HTTP assertions passed. No external services contacted.')
        finally:server.terminate();server.wait(timeout=10);backend.shutdown()
