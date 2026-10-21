"""Verify real cURL multipart encoding against an isolated local endpoint."""
import email.parser, email.policy, http.server, threading, subprocess, os, json
from pathlib import Path
class Backend(http.server.BaseHTTPRequestHandler):
    received = None
    def log_message(self,*args): pass
    def do_POST(self):
        raw=self.rfile.read(int(self.headers['Content-Length']))
        message=email.parser.BytesParser(policy=email.policy.default).parsebytes(('Content-Type: '+self.headers['Content-Type']+'\r\nMIME-Version: 1.0\r\n\r\n').encode()+raw)
        Backend.received={p.get_param('name',header='content-disposition'):p for p in message.iter_parts()}
        self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(b'{"data":true}')
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Backend)
threading.Thread(target=server.serve_forever,daemon=True).start()
try:
    env=os.environ.copy();env.update(APP_ENV='test',APP_KEY='isolated-multipart-fixture-key-'*3,FIXTURE_LISTMONK_URL='http://127.0.0.1:'+str(server.server_port))
    script='''require "src/bootstrap.php";$client=new Zion\\ListmonkClient(['enabled'=>true,'url'=>getenv('FIXTURE_LISTMONK_URL'),'api_user'=>'fixture','api_token'=>'fixture-multipart-token-123456','template_id'=>'7','from_email'=>'contact@example.test']);$client->sendWithAttachment('client@example.test','Facture ZS / 1','<p>Facture jointe</p>','Facture jointe','fr','invoice_copy',['bytes'=>"%PDF-1.4\\nfixture-private-pdf\\n%%EOF",'filename'=>'Zion-Storage-ZS-1.pdf']);'''
    subprocess.run(['php','-r',script],cwd=Path(__file__).resolve().parents[1],env=env,check=True,capture_output=True)
    parts=Backend.received
    assert set(parts)=={'data','file'}
    data=json.loads(parts['data'].get_payload(decode=True))
    assert data['subscriber_emails']==['client@example.test'] and data['data']['locale']=='fr' and data['template_id']==7
    assert parts['file'].get_filename()=='Zion-Storage-ZS-1.pdf' and parts['file'].get_content_type()=='application/pdf'
    assert parts['file'].get_payload(decode=True)==b'%PDF-1.4\nfixture-private-pdf\n%%EOF'
    print('4 real HTTP multipart assertions passed. No external email sent.')
finally:
    server.shutdown();server.server_close()
