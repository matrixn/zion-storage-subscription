"""Isolated HTTP signup/verification/provisioning test with a local storage API fixture."""
import base64, http.server, threading, subprocess, tempfile, os, time, json, urllib.request, urllib.parse, http.cookiejar, re, sqlite3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
checks=0
def check(condition,label):
    global checks
    if not condition: raise AssertionError(label)
    checks+=1
    print("PASS",label)
class Backend(http.server.BaseHTTPRequestHandler):
    records=[]
    creates=0
    onboarded=False
    preferences={}
    logouts=0
    token="fixture-immich-session-token-not-real"
    config=dict(machineLearning=dict(enabled=True,facialRecognition=dict(enabled=False,modelName="preserved-model"),clip=dict(enabled=True)),map=dict(enabled=True),oauth=dict(clientSecret="fixture-admin-config-secret"),passwordLogin=dict(enabled=True))
    config_reads=0
    config_writes=0
    deny_config=False
    def log_message(self,*args): pass
    def respond(self,obj,status=200):
        self.send_response(status);self.send_header("Content-Type","application/json");self.end_headers();self.wfile.write(json.dumps(obj).encode())
    def do_GET(self):
        if self.path=="/api/system-config":
            Backend.config_reads+=1
            if self.headers.get("x-api-key")!="fixture-storage-key" or Backend.deny_config: return self.respond({},403)
            return self.respond(Backend.config)
        if self.path=="/api/users/me/preferences":
            assert self.headers.get("Authorization")=="Bearer "+self.token and not self.headers.get("x-api-key")
            return self.respond(dict(message="invalid session "+self.token),403)
        if self.path=="/api/users/me/onboarding":
            assert self.headers.get("Authorization")=="Bearer "+self.token and not self.headers.get("x-api-key")
            return self.respond(dict(isOnboarded=Backend.onboarded))
        if self.headers.get("x-api-key")!="fixture-storage-key": return self.respond({},403)
        if "?" in self.path: return self.respond(self.records)
        return self.respond(self.records[0] if self.records else {})
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers.get("Content-Length",0))) or b'{}')
        if self.path=="/api/auth/login":
            assert not self.headers.get("x-api-key") and not self.headers.get("Authorization")
            assert body["email"]=="http-fixture@example.com" and body["password"]=="secure-http-test-password"
            return self.respond(dict(accessToken=self.token,userId=self.records[0]["id"],userEmail=body["email"],isAdmin=False))
        if self.path=="/api/auth/logout":
            assert self.headers.get("Authorization")=="Bearer "+self.token and not self.headers.get("x-api-key")
            Backend.logouts+=1
            return self.respond(dict(successful=True))
        if self.headers.get("x-api-key")!="fixture-storage-key": return self.respond({},403)
        assert body.pop("password")=="secure-http-test-password"
        assert body["isAdmin"] is False and body["notify"] is False
        Backend.creates+=1
        body.update(id="11111111-1111-4111-8111-111111111111",quotaUsageInBytes=0)
        self.records.append(body);self.respond(body,201)
    def do_PUT(self):
        body=json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.path=="/api/system-config":
            if self.headers.get("x-api-key")!="fixture-storage-key" or Backend.deny_config: return self.respond({},403)
            Backend.config_writes+=1
            Backend.config=body
            return self.respond(Backend.config)
        if self.path.startswith("/api/users/me/"):
            assert self.headers.get("Authorization")=="Bearer "+self.token and not self.headers.get("x-api-key")
            if self.path=="/api/users/me/preferences":
                Backend.preferences.update(body)
                return self.respond(Backend.preferences)
            if self.path=="/api/users/me/onboarding":
                assert Backend.preferences.get("cast",{}).get("gCastEnabled") is False
                Backend.onboarded=body["isOnboarded"]
                return self.respond(dict(isOnboarded=Backend.onboarded))
        self.records[0].update(body);self.respond(self.records[0])
with tempfile.TemporaryDirectory(prefix="zion-http-") as temp:
    backend=http.server.ThreadingHTTPServer(("127.0.0.1",0),Backend)
    threading.Thread(target=backend.serve_forever,daemon=True).start()
    env=os.environ.copy()
    env.update(APP_ENV="test",APP_KEY="http-fixture-key-"*4,APP_URL="http://127.0.0.1:8092",DB_PATH=temp+"/test.sqlite",MAIL_TRANSPORT="log",STRIPE_SECRET_KEY="sk_test_fixture",STRIPE_WEBHOOK_SECRET="whsec_fixture",STORAGE_API_URL="http://127.0.0.1:"+str(backend.server_port)+"/api",STORAGE_API_KEY="fixture-storage-key",CAPTCHA_ENABLED="false",OBLIO_ENABLED="false",STRIPE_PRICE_120GB="price_http")
    def cli(*args):
        result=subprocess.run(["php",*args],cwd=ROOT,env=env,text=True,capture_output=True)
        if result.returncode: raise RuntimeError(result.stderr)
        return result.stdout
    env['MAIL_LOG_DIRECTORY']=temp+'/mail'
    cli("bin/console.php","migrate")
    cli("tests/seed-fx.php")
    log=open(temp+"/server.log","w")
    server=subprocess.Popen(["php","-S","127.0.0.1:8092","-t","public","public/router.php"],cwd=ROOT,env=env,stdout=log,stderr=log)
    cookies=http.cookiejar.CookieJar()
    client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
    base=env["APP_URL"]
    def request(path,data=None):
        body=urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            with client.open(base+path,data=body) as response: return response.status,response.read().decode()
        except urllib.error.HTTPError as response: return response.code,response.read().decode()
    def csrf(html): return re.search(r'name="_csrf" value="([^"]+)"',html).group(1)
    def mail_link():
        code='require "src/bootstrap.php";$db=new Zion\\Database();$r=$db->one("SELECT payload FROM outbox WHERE kind=? ORDER BY id DESC",["verify"]);echo Zion\\MailerService::decode($r["payload"])["url"];'
        return cli("-r",code).replace(base,"")
    try:
        for _ in range(100):
            try: request("/en/");break
            except Exception: time.sleep(.05)
        _,html=request("/en/register")
        token=csrf(html)
        data=dict(_csrf=token,name="HTTP Test",email="http-fixture@example.com",password="secure-http-test-password",confirm="secure-http-test-password",locale="en",plan="free",terms="1",privacy="1")
        status,html=request("/en/register",data)
        check(status==200 and "Check your inbox." in html,"HTTP registration")
        status,duplicate=request("/en/register",data)
        check(status==200 and "Check your inbox." in duplicate,"HTTP duplicate generic response")
        link=mail_link()
        _,html=request(link);_,second=request(link)
        check("Confirm your email" not in second and 'name="token"' in second,"GET link scanners do not consume verification")
        verification=re.search(r'name="token" value="([^"]+)"',html).group(1)
        status,html=request("/en/verify-email",dict(_csrf=csrf(html),token=verification))
        check(status==200 and "Preparing your account" in html,"HTTP email verification reaches automatic activation")
        check(Backend.creates==0,"GET status does not provision storage")
        check('data-auto-activation' in html and 'name="password"' not in html,"HTTP new accounts do not ask for password twice")
        status,html=request("/en/complete",dict(_csrf=csrf(html),automatic="1"))
        check(status==200 and "Your Zion Storage account is ready!" in html,"HTTP automatic activation without password through actual cURL adapter")
        check(Backend.creates==1 and Backend.records[0]["quotaSizeInBytes"]==5_000_000_000,"HTTP free quota and single account")
        check(Backend.onboarded and Backend.preferences==dict(cast=dict(gCastEnabled=False),people=dict(enabled=False,sidebarWeb=False),memories=dict(enabled=True)),"HTTP real adapter configures account before completing Immich onboarding")
        check(Backend.logouts==1,"HTTP transient provisioning session is revoked")
        status,html=request("/en/complete",dict(_csrf=csrf(request("/en/register")[1]),password="secure-http-test-password"))
        check(Backend.creates==1,"HTTP activation replay idempotent")
        status,_=request("/en/verify-email",dict(_csrf=csrf(request("/en/register")[1]),token=verification))
        check(status==422,"HTTP token replay rejected")
        _,html=request("/en/resend")
        request("/en/resend",dict(_csrf=csrf(html),email="http-fixture@example.com"))
        link=mail_link();_,html=request(link)
        verification=re.search(r'name="token" value="([^"]+)"',html).group(1)
        status,html=request("/en/verify-email",dict(_csrf=csrf(html),token=verification))
        check("Your Zion Storage account is ready!" in html,"HTTP account recovery preserves active state")
        check("fixture-storage-key" not in html and 'http://127.0.0.1:'+str(backend.server_port) not in html and '11111111-1111-4111-8111-111111111111' not in html,"HTTP no internal API details")
        connection=sqlite3.connect(temp+"/test.sqlite")
        row=connection.execute("SELECT password_hash,status,storage_user_id FROM users").fetchone()
        check(row[0].startswith("$argon2id$") and row[1]=="active" and row[2] is not None,"HTTP persisted security and provisioning state")
        check(b"secure-http-test-password" not in Path(temp+"/test.sqlite").read_bytes(),"HTTP no plaintext password in database")
        for loc,slug in [('ro','documente'),('en','legal'),('fr','documents'),('de','rechtliches')]:
            for doc in ['privacy','terms','cookies']:
                status,page=request('/'+loc+'/'+slug+'?document='+doc)
                check(status==200 and 'F38/36/2024' in page and 'contact@zion3d.ro' in page and '<article class="legal-content">' in page,'HTTP legal document renders '+loc+'/'+doc)
                check('href="/en/legal?document='+doc+'"' in page,'HTTP language switch preserves legal document '+loc+'/'+doc)
        _,withdrawal_page=request('/en/contact?request=withdrawal')
        check('Confirm withdrawal' in withdrawal_page and 'name="contract"' in withdrawal_page,'public withdrawal form is available without login')
        status,_=request('/en/contact?request=withdrawal',dict(request_type='withdrawal',name='Test User',email='withdrawal@example.com',contract='Personal original order'))
        check(status==419,'withdrawal submission requires CSRF')
        status,receipt=request('/en/contact?request=withdrawal',dict(_csrf=csrf(withdrawal_page),request_type='withdrawal',name='Test User',email='withdrawal@example.com',contract='Personal original order'))
        check(status==200 and 'I withdraw from the identified contract' in receipt and 'data-receipt-download' in receipt,'HTTP withdrawal creates downloadable timestamped receipt')
        _,html=request("/fr/contact")
        status,html=request("/fr/contact",dict(_csrf=csrf(html),name="Test",email="http@example.com",subject="Bonjour",message="Un message de test local."))
        check(status==200 and "Votre message a bien" in html,"HTTP localized contact")
        cli("bin/console.php","mail:work")
        check(connection.execute("SELECT COUNT(*) FROM outbox WHERE sent_at IS NULL").fetchone()[0]==0,"HTTP worker delivers local email previews")
        check("secure-http-test-password" not in Path(temp+"/server.log").read_text(),"HTTP server logs exclude passwords")
        probe='require "src/bootstrap.php";$s=new class extends Zion\\StorageProvisioningService {public function probe():void{$this->userRequest("GET","/users/me/preferences",null,"fixture-immich-session-token-not-real");}};try{$s->probe();exit(2);}catch(Zion\\StorageApiException $e){if($e->httpStatus!==403)exit(3);}'
        cli("-r",probe)
        diagnostic=sqlite3.connect(temp+"/api-communication.sqlite")
        messages=str(diagnostic.execute("SELECT endpoint,message,context FROM api_log").fetchall())
        check(Backend.token not in messages and "secure-http-test-password" not in messages and "fixture-storage-key" not in messages,"HTTP API journal excludes session tokens and credentials")
        check("invalid session [redacted]" in messages,"HTTP API error echoing raw user token is redacted")
        diagnostic.close()

        check(connection.execute("SELECT COUNT(*) FROM fiscal_invoices").fetchone()[0]==0,"HTTP free signup has no fiscal document")
        _,html=request("/en/register")
        bad_paid=data.copy()
        bad_paid.update(email="paid-http@example.com",plan="120gb",_csrf=csrf(html))
        status,html=request("/en/register",bad_paid)
        check(status==422 and "billing details" in html,"HTTP paid registration requires local billing profile")
        billing=dict(type="individual",name="HTTP Paid",billing_email="paid-billing@example.com",country="RO",county="Bucuresti",city="Bucuresti",address="Strada Test 1",postal_code="010101")
        paid=bad_paid.copy()
        paid.update(locale="fr")
        paid.update({"billing["+key+"]":value for key,value in billing.items()})
        status,html=request("/en/register",paid)
        check(status==200 and "Check your inbox." in html,"HTTP paid registration saves billing profile without JavaScript")
        link=mail_link();_,html=request(link)
        verification=re.search(r'name="token" value="([^"]+)"',html).group(1)
        status,html=request("/fr/verification-email",dict(_csrf=csrf(html),token=verification))
        check(status==200 and "Votre compte est confirmé" in html and "Continuer avec le paiement de 3,99 RON" in html and "votre espace sera activé après confirmation du paiement" in html,"HTTP paid verification shows confirmed account and exact payment total")
        check('TVA non collectée' not in html and 'BNR' in html and 'paiements sont effectués en RON' in html,'Checkout shows BNR policy in RON without zero-tax text')
        _,html=request("/fr/coordonnees-facturation")
        check("paid-billing@example.com" in html and 'name="robots" content="noindex,nofollow"' in html,"HTTP private billing page renders local profile and noindex")
        company=billing.copy()
        company.update(type="company",company_name="HTTP Company SRL",tax_id="RO12345678",name="HTTP Contact")
        update={"billing["+key+"]":value for key,value in company.items()}
        update["_csrf"]=csrf(html)
        status,html=request("/fr/coordonnees-facturation",update)
        check(status==200 and "enregistrées" in html,"HTTP company billing profile update")
        connection.close()
        connection=sqlite3.connect(temp+"/test.sqlite")
        check(connection.execute("PRAGMA integrity_check").fetchone()[0]=="ok","HTTP database integrity after billing profile writes")
        profile_row=connection.execute("SELECT type,company_name,tax_id FROM billing_profiles WHERE user_id=(SELECT id FROM users WHERE email='paid-http@example.com')").fetchone()
        check(profile_row==("company","HTTP Company SRL","RO12345678"),"HTTP company data persists separately from authentication")
        code='require "src/bootstrap.php";$db=new Zion\\Database();$u=$db->one("SELECT * FROM users WHERE email=?",["paid-http@example.com"]);$plans=new Zion\\Plans();$s=new Zion\\OblioInvoiceService($db,new Zion\\MailerService($db));$invoice=["id"=>"in_http_sim","status"=>"paid","livemode"=>false,"subscription"=>"sub_http","customer"=>"cus_http","amount_paid"=>650,"currency"=>"ron","created"=>1792108800,"lines"=>["data"=>[["price"=>["id"=>$plans->get("120gb")["stripe_price_id"]],"period"=>["start"=>1792108800,"end"=>1794787200],"quantity"=>1]],"has_more"=>false]];$subscription=["id"=>"sub_http","current_period_end"=>1794787200];$id=$s->capture($invoice,$u,$subscription,$plans->get("120gb"));$s->process($id);'
        cli("-r",code)
        check(connection.execute("SELECT status,amount,oblio_invoice_id FROM fiscal_invoices").fetchone()==("simulated",650,None),"HTTP local fiscal simulation keeps authoritative collected amount")
        _,html=request("/fr/coordonnees-facturation")
        check("sans valeur fiscale" in html and "6,50 RON" in html,"HTTP history labels simulation without claiming fiscal issuance")
        result=json.loads(cli("bin/retry-oblio-invoices.php"))
        check(result["processed"]==0,"HTTP CLI retry skips simulated and issued documents")

        cli("-r",'require "src/bootstrap.php";$db=new Zion\\Database();$db->run("INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)",[str_repeat("a",32),"admin-http@example.test",password_hash("isolated-admin-password",PASSWORD_ARGON2ID),time()]);')
        _,admin_page=request('/admin?section=immich-general')
        check('name="action" value="login"' in admin_page and 'name="revision"' not in admin_page and Backend.config_reads==0,'anonymous admin page never fetches or reveals Immich configuration')
        status,_=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_defaults',**{'defaults[cast]':'1','defaults[people]':'1','defaults[memories]':'0'}))
        check(status==422 and Backend.config_writes==0,'unauthenticated actions cannot change defaults or global settings')
        _,admin_page=request('/admin?section=immich-general')
        status,_=request('/admin',dict(_csrf=csrf(admin_page),action='login',email='admin-http@example.test',password='isolated-admin-password'))
        check(status==200,'isolated administrator authentication')
        status,admin_page=request('/admin?section=immich-general')
        check(status==200 and 'Funcțiile serverului Immich' in admin_page and 'Preferințe implicite' in admin_page,'dedicated Immich configuration page renders both scopes')
        check('fixture-admin-config-secret' not in admin_page and 'preserved-model' not in admin_page and 'fixture-storage-key' not in admin_page,'general settings HTML never contains unfiltered server configuration or credentials')
        revision=lambda page: re.search(r'name="revision" value="([a-f0-9]{64})"',page).group(1)
        old_revision=revision(admin_page)
        flags={'features[machine_learning]':'1','features[facial_recognition]':'1','features[smart_search]':'0','features[map]':'1'}
        status,_=request('/admin?section=immich-general',dict(action='immich_general_server',revision=old_revision,**flags))
        check(status==419 and Backend.config_writes==0,'global Immich changes require CSRF')
        status,admin_page=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_server',revision=old_revision,**flags))
        check(status==200 and Backend.config_writes==1 and Backend.config['machineLearning']['facialRecognition']['enabled'] is True,'administrator enables global recognition via actual adapter')
        check(Backend.config['oauth']['clientSecret']=='fixture-admin-config-secret' and Backend.config['map']['enabled'] is True and Backend.config['passwordLogin']['enabled'] is True,'global update preserves secrets, maps and authentication')
        status,admin_page=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_server',revision=old_revision,**flags))
        check(status==422 and Backend.config_writes==1 and 's-a schimbat' in admin_page,'stale general-settings form is rejected without writing Immich')
        flags['features[facial_recognition]']='0'
        status,admin_page=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_server',revision=revision(admin_page),**flags))
        check(status==200 and Backend.config_writes==2 and Backend.config['machineLearning']['facialRecognition']['enabled'] is False,'administrator can turn global recognition off again')
        status,admin_page=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_defaults',**{'defaults[cast]':'1','defaults[people]':'1','defaults[memories]':'0'}))
        profile=json.loads(connection.execute("SELECT value FROM integration_settings WHERE key='immich:account-defaults:v1'").fetchone()[0])
        check(status==200 and profile==dict(cast=True,people=True,memories=False) and Backend.config_writes==2,'administrator saves future-account defaults independently from global server state')
        Backend.deny_config=True
        status,admin_page=request('/admin?section=immich-general')
        check(status==200 and 'systemConfig.read' in admin_page and 'name="revision"' not in admin_page,'API permission failure shows safe guidance without a usable global save form')
        status,_=request('/admin?section=immich-general',dict(_csrf=csrf(admin_page),action='immich_general_defaults',**{'defaults[cast]':'0','defaults[people]':'0','defaults[memories]':'1'}))
        check(status==200 and Backend.config_writes==2,'local defaults remain configurable during an Immich API outage')
        Backend.deny_config=False
        print(str(checks)+" HTTP assertions passed.")
    finally:
        server.terminate();server.wait(timeout=5);backend.shutdown();log.close()
