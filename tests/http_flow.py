"""Isolated HTTP signup/verification/provisioning test with a local storage API fixture."""
import http.server, threading, subprocess, tempfile, os, time, json, urllib.request, urllib.parse, http.cookiejar, re, sqlite3
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
    def log_message(self,*args): pass
    def respond(self,obj,status=200):
        self.send_response(status);self.send_header("Content-Type","application/json");self.end_headers();self.wfile.write(json.dumps(obj).encode())
    def do_GET(self):
        if self.headers.get("x-api-key")!="fixture-storage-key": return self.respond({},403)
        if "?" in self.path: return self.respond(self.records)
        return self.respond(self.records[0] if self.records else {})
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.headers.get("x-api-key")!="fixture-storage-key": return self.respond({},403)
        assert body.pop("password")=="secure-http-test-password"
        assert body["isAdmin"] is False and body["notify"] is False
        Backend.creates+=1
        body.update(id="11111111-1111-4111-8111-111111111111",quotaUsageInBytes=0)
        self.records.append(body);self.respond(body,201)
    def do_PUT(self):
        body=json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        self.records[0].update(body);self.respond(self.records[0])
with tempfile.TemporaryDirectory(prefix="zion-http-") as temp:
    backend=http.server.ThreadingHTTPServer(("127.0.0.1",0),Backend)
    threading.Thread(target=backend.serve_forever,daemon=True).start()
    env=os.environ.copy()
    env.update(APP_ENV="test",APP_KEY="http-fixture-key-"*4,APP_URL="http://127.0.0.1:8092",DB_PATH=temp+"/test.sqlite",MAIL_TRANSPORT="log",STRIPE_SECRET_KEY="sk_test_fixture",STRIPE_WEBHOOK_SECRET="whsec_fixture",STORAGE_API_URL="http://127.0.0.1:"+str(backend.server_port)+"/api",STORAGE_API_KEY="fixture-storage-key",CAPTCHA_ENABLED="false")
    def cli(*args):
        result=subprocess.run(["php",*args],cwd=ROOT,env=env,text=True,capture_output=True)
        if result.returncode: raise RuntimeError(result.stderr)
        return result.stdout
    cli("bin/console.php","migrate")
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
        check(status==200 and "Your space is ready to activate." in html,"HTTP email verification and free gate")
        check(Backend.creates==0,"no provision until secure password activation")
        status,html=request("/en/complete",dict(_csrf=csrf(html),password="secure-http-test-password"))
        check(status==200 and "Your Zion Storage account is ready." in html,"HTTP secure provisioning through actual cURL adapter")
        check(Backend.creates==1 and Backend.records[0]["quotaSizeInBytes"]==10_000_000_000,"HTTP free quota and single account")
        status,html=request("/en/complete",dict(_csrf=csrf(request("/en/register")[1]),password="secure-http-test-password"))
        check(Backend.creates==1,"HTTP activation replay idempotent")
        status,_=request("/en/verify-email",dict(_csrf=csrf(request("/en/register")[1]),token=verification))
        check(status==422,"HTTP token replay rejected")
        _,html=request("/en/resend")
        request("/en/resend",dict(_csrf=csrf(html),email="http-fixture@example.com"))
        link=mail_link();_,html=request(link)
        verification=re.search(r'name="token" value="([^"]+)"',html).group(1)
        status,html=request("/en/verify-email",dict(_csrf=csrf(html),token=verification))
        check("Your Zion Storage account is ready." in html,"HTTP account recovery preserves active state")
        check("fixture-storage-key" not in html and "immich" not in html.lower(),"HTTP no internal API details")
        connection=sqlite3.connect(temp+"/test.sqlite")
        row=connection.execute("SELECT password_hash,status,storage_user_id FROM users").fetchone()
        check(row[0].startswith("$argon2id$") and row[1]=="active" and row[2] is not None,"HTTP persisted security and provisioning state")
        check(b"secure-http-test-password" not in Path(temp+"/test.sqlite").read_bytes(),"HTTP no plaintext password in database")
        _,html=request("/fr/contact")
        status,html=request("/fr/contact",dict(_csrf=csrf(html),name="Test",email="http@example.com",subject="Bonjour",message="Un message de test local."))
        check(status==200 and "Votre message a bien" in html,"HTTP localized contact")
        cli("bin/console.php","mail:work")
        check(connection.execute("SELECT COUNT(*) FROM outbox WHERE sent_at IS NULL").fetchone()[0]==0,"HTTP worker delivers local email previews")
        check("secure-http-test-password" not in Path(temp+"/server.log").read_text(),"HTTP server logs exclude passwords")
        print(str(checks)+" HTTP assertions passed.")
    finally:
        server.terminate();server.wait(timeout=5);backend.shutdown();log.close()
