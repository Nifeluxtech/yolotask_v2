#!/usr/bin/env python3
"""YOLOTASK V2 AfrStar hub order-registration test (hooks/afrstar/register.php).

Other sites on the same AfrStar merchant register order_sn + callback URL,
signed with their secret; the hub then relays that order's callbacks.
Needs the Phase 3 environment (servers from run-servers.sh, migrations through
0018, config dir) plus openssl. Fake downstream HTTPS server on 127.0.0.1:8495.
"""
import hashlib, hmac, http.client, json, os, re, shlex, ssl, subprocess, sys, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
FWD = os.path.join(CFG, 'afrstar-forward.php')
CERT_DIR = '/tmp/ytreg-cert'
PORT = 8495
DOWN = f'https://127.0.0.1:{PORT}'
SECRET = 'reg-test-secret-0123456789abcdef-XYZ'
SECRET2 = 'other-client-secret-0123456789abcdef'
SN, AK, SK = 'TESTSN001', 'testak123', 'testsk456'
results = []
RECEIVED = []


def check(label, ok, extra=''):
    results.append(bool(ok))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:300] + ']') if (not ok and extra) else ''))


class Down(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        RECEIVED.append({'path': self.path, 'body': body, 'hub': self.headers.get('X-Forwarded-By')})
        b = b'success'
        self.send_response(200); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)


os.makedirs(CERT_DIR, exist_ok=True)
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=127.0.0.1',
                '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', CERT_DIR + '/key.pem', '-out', CERT_DIR + '/cert.pem'], check=True, capture_output=True)
srv = ThreadingHTTPServer(('127.0.0.1', PORT), Down)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(CERT_DIR + '/cert.pem', CERT_DIR + '/key.pem')
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
threading.Thread(target=srv.serve_forever, daemon=True).start()


def one(sql):
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    dbname = re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)
    out = subprocess.run(cli + ['-N', '-B', dbname, '-e', sql], capture_output=True, text=True)
    if out.returncode != 0: raise RuntimeError(out.stderr)
    return out.stdout.strip().split('\n')[0] if out.stdout.strip() else None


def raw_post(path, body, headers):
    c = http.client.HTTPConnection('127.0.0.1', 8401, timeout=30)
    h = {'Content-Length': str(len(body)), 'Content-Type': 'application/json'}; h.update(headers)
    c.request('POST', path, body=body, headers=h); r = c.getresponse()
    out = (r.status, r.read().decode()); c.close(); return out


def register(order_sn, url, client='testsite', secret=SECRET, ts=None, sig=None):
    body = json.dumps({'order_sn': order_sn, 'callback_url': url}).encode()
    ts = str(int(time.time())) if ts is None else ts
    sig = hmac.new(secret.encode(), ts.encode() + b'.' + body, hashlib.sha256).hexdigest() if sig is None else sig
    s, b = raw_post('/hooks/afrstar/register.php', body, {'X-Hub-Client': client, 'X-Hub-Timestamp': ts, 'X-Hub-Signature': sig})
    try: return s, json.loads(b)
    except Exception: return s, {'raw': b}


def write_cfg(clients=True):
    lines = ['<?php', 'return [', "    'routes' => [",
             "        ['name' => 'splug', 'pattern' => '/^(DEP|WDL)-/', 'url' => 'https://127.0.0.1:%d/splug', 'timeout' => 5]," % PORT, '    ],']
    if clients:
        lines += ["    'clients' => [",
                  f"        ['name' => 'testsite', 'secret' => '{SECRET}', 'allowed_hosts' => ['127.0.0.1'], 'timeout' => 5],",
                  f"        ['name' => 'other', 'secret' => '{SECRET2}'],",
                  "        ['name' => 'weak', 'secret' => 'short'],",
                  '    ],']
    lines += [f"    'testing' => ['allow_private_hosts' => true, 'ca_file' => '{CERT_DIR}/cert.pem'],", '];']
    open(FWD, 'w').write('\n'.join(lines) + '\n')


example = open(os.path.join(os.path.dirname(__file__), '../../../config/payments.example.php')).read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')
write_cfg()
tag = str(int(time.time() * 1000))
OSN = f'ABC-{tag}'
URL = f'{DOWN}/cb/abc'

check('migration 0018 table exists', one("SHOW TABLES LIKE 'afrstar_forward_registrations'") == 'afrstar_forward_registrations')

# auth
c = http.client.HTTPConnection('127.0.0.1', 8401); c.request('GET', '/hooks/afrstar/register.php'); r = c.getresponse(); r.read()
check('GET -> 405', r.status == 405, r.status)
check('unknown client -> 401', register(OSN, URL, client='nobody')[0] == 401)
check('bad signature -> 401', register(OSN, URL, sig='00' * 32)[0] == 401)
check('wrong secret -> 401', register(OSN, URL, secret=SECRET2)[0] == 401)
check('stale timestamp -> 401', register(OSN, URL, ts=str(int(time.time()) - 400))[0] == 401)
check('client with short secret is ignored -> 401', register(OSN, URL, client='weak', secret='short')[0] == 401)

# validation
s, b = register('YTP12345', URL); check('YOLOTASK ref refused', s == 422 and b.get('error') == 'reserved_order_sn', (s, b))
s, b = register('DEP-123', URL); check('fixed-route ref refused', s == 422 and b.get('error') == 'reserved_order_sn', (s, b))
s, b = register('bad sn!', URL); check('bad order_sn refused', s == 422 and b.get('error') == 'bad_order_sn', (s, b))
s, b = register(OSN, 'http://127.0.0.1/cb'); check('http url refused', s == 422 and b.get('error') == 'bad_callback_url', (s, b))
s, b = register(OSN, 'https://example.com/cb'); check('host outside allowed_hosts refused', s == 422 and b.get('error') == 'host_not_allowed', (s, b))

# register / update / conflict
s, b = register(OSN, URL + '-old'); check('register -> 201', s == 201 and b.get('status') == 'registered', (s, b))
s, b = register(OSN, URL); check('re-register same client -> 200 updated', s == 200 and b.get('status') == 'updated', (s, b))
check('url updated in DB', one(f"SELECT callback_url FROM afrstar_forward_registrations WHERE order_sn='{OSN}'") == URL)
s, b = register(OSN, URL, client='other', secret=SECRET2); check('other client same order_sn -> 409', s == 409, (s, b))

# forwarding
payload = json.dumps({'merchant_sn': SN, 'order_sn': OSN, 'platform_osn': 'P' + tag, 'status': 1, 'amount': '2000.00', 'sign': 'abc'}).encode()
s, b = raw_post('/hooks/afrstar/collection.php', payload, {})
check('callback for registered order relayed, AfrStar gets success', s == 200 and b == 'success', (s, b))
got = [r for r in RECEIVED if r['path'] == '/cb/abc']
check('downstream got exact body + hub header', len(got) == 1 and got[0]['body'] == payload and got[0]['hub'] == 'yolotask-afrstar-hub', got)
check('forward logged under client name', one(f"SELECT route FROM afrstar_callback_forwards WHERE order_sn='{OSN}' ORDER BY id DESC LIMIT 1") == 'testsite')
check('not stored as a YOLOTASK callback', one(f"SELECT COUNT(*) FROM payment_callbacks WHERE order_sn='{OSN}'") == '0')
s, b = raw_post('/hooks/afrstar/payout.php', payload, {})
check('payout callback relayed too', s == 200 and len([r for r in RECEIVED if r['path'] == '/cb/abc']) == 2, (s, b))

# unregistered order stays YOLOTASK's
p2 = json.dumps({'merchant_sn': SN, 'order_sn': 'ZZZ-' + tag, 'platform_osn': 'Q' + tag, 'status': 1, 'amount': '1.00', 'sign': 'x'}).encode()
s, b = raw_post('/hooks/afrstar/collection.php', p2, {})
check('unregistered order handled by YOLOTASK (404 UNKNOWN_ORDER)', s == 404 and b == 'UNKNOWN_ORDER', (s, b))

# client removed from config -> no relay (fresh order, so the hub log and payment_callbacks never share an order_sn)
OSN2 = f'ABD-{tag}'
check('second order registered', register(OSN2, URL)[0] == 201)
write_cfg(clients=False)
n = len(RECEIVED)
payload2 = payload.replace(OSN.encode(), OSN2.encode())
s, b = raw_post('/hooks/afrstar/collection.php', payload2, {})
check('client removed: callback no longer relayed', len(RECEIVED) == n and s == 404, (s, b))
check('client removed: register -> 401', register('ABC-x' + tag, URL)[0] == 401)

os.remove(FWD)
print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
