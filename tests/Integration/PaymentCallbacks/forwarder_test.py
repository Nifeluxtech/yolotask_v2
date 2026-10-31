#!/usr/bin/env python3
"""YOLOTASK V2 AfrStar callback hub (forwarder) integration test.

SPLUG (and later other apps) share YOLOTASK's AfrStar merchant, so AfrStar
sends their callbacks to YOLOTASK's hooks. Callbacks whose order_sn matches a
route in config-private/afrstar-forward.php are relayed untouched.

Needs the Phase 3 environment (PHP 8.1+ with pdo_mysql + curl, MariaDB with
migrations 0001-0007, private config dir, servers from run-servers.sh) plus
openssl (for a self-signed cert). Starts its own fake downstream HTTPS server
on 127.0.0.1:8496.

Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_DB_CLI.
"""
import hashlib, http.client, http.cookiejar, json, os, re, shlex, ssl, subprocess, sys, threading, time
import urllib.error, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
M, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8405'
DOWN_PORT = 8496
DOWN = f'https://127.0.0.1:{DOWN_PORT}'
SN, AK, SK = 'TESTSN001', 'testak123', 'testsk456'
FWD = os.path.join(CFG, 'afrstar-forward.php')
CERT_DIR = '/tmp/ytfwd-cert'
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:300] + ']') if (not ok and extra) else ''))


# ---------------- fake downstream (SPLUG /api/gateway stand-in) over HTTPS
RECEIVED = []
LOCK = threading.Lock()


class Down(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def answer(self, code, body):
        b = body.encode()
        self.send_response(code)
        self.send_header('Content-Type', 'text/plain')
        if code in (301, 302):
            self.send_header('Location', 'https://example.com/')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def do_GET(self):
        self.answer(405, 'method not allowed')

    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n)
        with LOCK:
            RECEIVED.append({'path': self.path, 'ctype': self.headers.get('Content-Type'), 'body': body, 'ua': self.headers.get('User-Agent')})
        if self.path == '/slow':
            time.sleep(4)
            return self.answer(200, 'success')
        m = re.match(r'^/status/(\d{3})$', self.path)
        if m:
            code = int(m.group(1))
            return self.answer(code, {404: 'unknown order', 401: 'invalid signature', 500: 'error', 302: 'moved'}.get(code, 'x'))
        return self.answer(200, 'success')


os.makedirs(CERT_DIR, exist_ok=True)
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=127.0.0.1',
                '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', CERT_DIR + '/key.pem', '-out', CERT_DIR + '/cert.pem'],
               check=True, capture_output=True)
srv = ThreadingHTTPServer(('127.0.0.1', DOWN_PORT), Down)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(CERT_DIR + '/cert.pem', CERT_DIR + '/key.pem')
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
threading.Thread(target=srv.serve_forever, daemon=True).start()


def received():
    with LOCK:
        return list(RECEIVED)


def clear_received():
    with LOCK:
        RECEIVED.clear()


# ---------------- helpers
def db(sql):
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    dbname = re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)
    out = subprocess.run(cli + ['-N', '-B', dbname, '-e', sql], capture_output=True, text=True)
    if out.returncode != 0:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().split('\n') if line != '']


def one(sql):
    rows = db(sql)
    return rows[0][0] if rows else None


def count(table, where='1'):
    return int(one(f'SELECT COUNT(*) FROM {table} WHERE {where}'))


def post(path, body, ctype='application/json', method='POST'):
    """Raw HTTP so we control Content-Type exactly (or send none)."""
    c = http.client.HTTPConnection('127.0.0.1', 8401, timeout=30)
    headers = {'Content-Length': str(len(body))}
    if ctype is not None:
        headers['Content-Type'] = ctype
    t = time.time()
    c.request(method, path, body=body, headers=headers)
    r = c.getresponse()
    out = (r.status, r.read().decode(), time.time() - t)
    c.close()
    return out


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + 'splug-own-secret').encode()).hexdigest()


def splug_payload(order_sn, osn, amount='1500.00', status=1):
    # Signed with SPLUG's keys (not YOLOTASK's): YOLOTASK must not care.
    return {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'message': 'Paid Success',
            'amount': amount, 'amount_paid': amount, 'utr': 'UTR' + osn, 'sign': md5('splugak', osn, status, amount)}


def write_cfg(routes, testing=True):
    lines = ['<?php', 'return [', "    'routes' => ["]
    for r in routes:
        lines.append('        ' + '[' + ', '.join(f"'{k}' => " + (str(v) if isinstance(v, int) else "'" + v.replace("'", "\\'") + "'") for k, v in r.items()) + '],')
    lines.append('    ],')
    if testing:
        lines.append(f"    'testing' => ['allow_private_hosts' => true, 'ca_file' => '{CERT_DIR}/cert.pem'],")
    lines.append('];')
    open(FWD, 'w').write('\n'.join(lines) + '\n')


def remove_cfg():
    try:
        os.remove(FWD)
    except FileNotFoundError:
        pass


def php(code):
    env = dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)
    boot = ("define('YOLOTASK_APP_ROOT', '" + ROOT + "'); define('YOLOTASK_NO_SESSION', true); require '" + ROOT + "/shared/bootstrap.php'; ")
    return subprocess.run(['php', '-r', boot + code], capture_output=True, text=True, env=env).stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(self.base + path, data=body, headers={'User-Agent': 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'})
        try:
            resp = self.op.open(r, timeout=20)
            return resp.status, resp.headers.get('Location'), resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get('Location'), e.read().decode()

    def login(self, email, pw):
        s, l, h = self.req('/login.php')
        m = re.search(r'name="_csrf" value="([^"]*)"', h)
        s, l, _ = self.req('/login.php', {'_csrf': m.group(1) if m else '', 'email': email, 'password': pw})
        return s == 303 and l == 'dashboard.php'


# ---------------- setup: YOLOTASK payments configured with its own (fake) keys
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')
remove_cfg()
tag = str(int(time.time() * 1000))
check('migration 0007 table exists', one("SHOW TABLES LIKE 'afrstar_callback_forwards'") == 'afrstar_callback_forwards')

# ---------- 1. no config file: forwarding off, current behaviour
p = splug_payload(f'DEP-off{tag[-5:]}-{tag}', 'OSNOFF' + tag)
cb0, rv0 = count('payment_callbacks'), count('payment_reviews')
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('config absent: SPLUG ref handled as YOLOTASK unknown order (404)', s == 404 and b == 'UNKNOWN_ORDER', (s, b))
check('config absent: logged in payment_callbacks as before', count('payment_callbacks') == cb0 + 1)
check('config absent: unmatched review opened as before', count('payment_reviews') == rv0 + 1)
check('config absent: nothing forwarded', received() == [] and count('afrstar_callback_forwards', f"order_sn = '{p['order_sn']}'") == 0)

# ---------- 2. route validation
out = php(r"""
$c = fn ($r, $p = false) => \YoloTask\Payments\CallbackForwarder::validateRoute($r, $p, $why) ? 'ok' : $why;
$base = ['name' => 'splug', 'pattern' => '/^(DEP|WDL)-/', 'timeout' => 10];
echo implode(',', [
  $c($base + ['url' => 'https://splug-theta.vercel.app/api/gateway']),
  $c($base + ['url' => 'http://splug-theta.vercel.app/api/gateway']),
  $c($base + ['url' => 'https://127.0.0.1/api/gateway']),
  $c($base + ['url' => 'https://10.1.2.3/api/gateway']),
  $c($base + ['url' => 'https://192.168.0.5/x']),
  $c($base + ['url' => 'https://localhost/x']),
  $c($base + ['url' => 'https://user:pw@splug-theta.vercel.app/x']),
  $c($base + ['url' => 'ftp://splug-theta.vercel.app/x']),
  $c(['name' => 'Bad Name!', 'pattern' => '/x/', 'url' => 'https://splug-theta.vercel.app/x']),
  $c(['name' => 'splug', 'pattern' => '/(unclosed', 'url' => 'https://splug-theta.vercel.app/x']),
  $c($base + ['url' => 'https://127.0.0.1:8496/ok'], true),
]);
""")
check('valid https public route accepted', out.split(',')[0] == 'ok', out)
check('http:// route rejected', out.split(',')[1] == 'not_https', out)
check('loopback IP route rejected', out.split(',')[2] == 'private_host', out)
check('10.x private route rejected', out.split(',')[3] == 'private_host', out)
check('192.168.x private route rejected', out.split(',')[4] == 'private_host', out)
check('localhost route rejected', out.split(',')[5] == 'private_host', out)
check('credentials in URL rejected', out.split(',')[6] == 'bad_url', out)
check('non-https scheme rejected', out.split(',')[7] == 'not_https', out)
check('bad route name rejected', out.split(',')[8] == 'bad_name', out)
check('invalid regex rejected', out.split(',')[9] == 'bad_pattern', out)
check('loopback allowed only with the local testing flag', out.split(',')[10] == 'ok', out)
out = php(r"""$r = \YoloTask\Payments\CallbackForwarder::resolve(['host' => 'localhost', 'allow_private' => false]); echo $r === null ? 'blocked' : 'open';""")
check('hostname resolving to loopback is blocked at send time', out == 'blocked', out)
out = php(r"""$m = fn ($s) => (\YoloTask\Payments\CallbackForwarder::match($s, [['name' => 'all', 'pattern' => '/./']])['name'] ?? '-');
echo implode(',', [$m('YTP2610301200AB12CD34EF'), $m('YTW00000012A1'), $m('DEP-ab12cd34-1761800000000'), $m('')]);""")
check('match() never returns a route for YTP/YTW, even with a catch-all pattern', out == '-,-,all,-', out)

# non-https route in the real file: route dropped, SPLUG ref falls back to current behaviour
write_cfg([{'name': 'splug', 'pattern': '/^(DEP|WDL)-/', 'url': f'http://127.0.0.1:{DOWN_PORT}/ok', 'timeout': 2}])
p = splug_payload(f'DEP-http{tag[-4:]}-{tag}', 'OSNHTTP' + tag)
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('non-https route in config is ignored (no forwarding, YOLOTASK 404)', s == 404 and received() == [], (s, b, received()))
# private host without the testing flag: dropped too
write_cfg([{'name': 'splug', 'pattern': '/^(DEP|WDL)-/', 'url': f'{DOWN}/ok', 'timeout': 2}], testing=False)
p = splug_payload(f'DEP-priv{tag[-4:]}-{tag}', 'OSNPRIV' + tag)
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('private-host route without testing flag is ignored', s == 404 and received() == [], (s, b))

# ---------- 3. working config
write_cfg([
    {'name': 'e404', 'pattern': '/^DEP-E404/', 'url': f'{DOWN}/status/404', 'timeout': 2},
    {'name': 'e401', 'pattern': '/^DEP-E401/', 'url': f'{DOWN}/status/401', 'timeout': 2},
    {'name': 'e500', 'pattern': '/^DEP-E500/', 'url': f'{DOWN}/status/500', 'timeout': 2},
    {'name': 'redir', 'pattern': '/^DEP-REDIR/', 'url': f'{DOWN}/status/302', 'timeout': 2},
    {'name': 'slow', 'pattern': '/^DEP-SLOW/', 'url': f'{DOWN}/slow', 'timeout': 2},
    {'name': 'v1', 'pattern': '/^(TOP-|YTP|YTW)/', 'url': f'{DOWN}/v1', 'timeout': 2},
    {'name': 'splug', 'pattern': '/^(DEP|WDL)-/', 'url': f'{DOWN}/ok', 'timeout': 2},
])
cb0, rv0, led0 = count('payment_callbacks'), count('payment_reviews'), count('ledger_transactions')
po0, wd0 = one('SELECT CONCAT(COUNT(*), SUM(status = \'paid\')) FROM payment_orders'), one('SELECT COUNT(*) FROM withdrawals')

# JSON collection
p = splug_payload(f'DEP-ab12cd34-{tag}', 'OSNJ' + tag)
raw = json.dumps(p, separators=(',', ':')).encode()
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', raw, 'application/json')
r = received()
check('JSON collection for DEP- forwarded and "success" relayed (200)', s == 200 and b == 'success', (s, b))
check('downstream got the exact raw JSON body', len(r) == 1 and r[0]['body'] == raw and r[0]['path'] == '/ok', r)
check('downstream got the original Content-Type', len(r) == 1 and r[0]['ctype'] == 'application/json', r)
row = db(f"SELECT kind, route, downstream_status, outcome, latency_ms, body_bytes, body_sha256 FROM afrstar_callback_forwards WHERE order_sn = '{p['order_sn']}'")
check('forward logged: route, kind, status, outcome, latency', len(row) == 1 and row[0][:4] == ['collection', 'splug', '200', 'delivered'] and int(row[0][4]) >= 0, row)
check('log stores only size + SHA-256 of the body', len(row) == 1 and int(row[0][5]) == len(raw) and row[0][6] == hashlib.sha256(raw).hexdigest(), row)
cols = [c[0] for c in db("SHOW COLUMNS FROM afrstar_callback_forwards")]
check('log table has no raw body/signature column', not any(c in cols for c in ('raw_body', 'sign', 'signature', 'body')), cols)

# form-encoded payout
p = splug_payload(f'WDL-ef56gh78-{tag}', 'OSNF' + tag, amount='2000.00')
raw = urllib.parse.urlencode(p).encode()
clear_received()
s, b, _ = post('/hooks/afrstar/payout.php', raw, 'application/x-www-form-urlencoded')
r = received()
check('form-encoded payout for WDL- forwarded (200 success)', s == 200 and b == 'success', (s, b))
check('downstream got the exact form body + form Content-Type', len(r) == 1 and r[0]['body'] == raw and r[0]['ctype'] == 'application/x-www-form-urlencoded', r)
check('payout forward logged with kind payout', one(f"SELECT CONCAT(kind, ':', outcome) FROM afrstar_callback_forwards WHERE order_sn = '{p['order_sn']}'") == 'payout:delivered')

# no Content-Type -> application/json
p = splug_payload(f'DEP-noct0000-{tag}', 'OSNN' + tag)
raw = json.dumps(p).encode()
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', raw, None)
r = received()
check('missing Content-Type forwarded as application/json', s == 200 and len(r) == 1 and r[0]['ctype'] == 'application/json' and r[0]['body'] == raw, (s, r))

# status passthrough
for code, prefix in ((404, 'E404'), (401, 'E401')):
    p = splug_payload(f'DEP-{prefix}xxxx-{tag}', 'OSN' + prefix + tag)
    s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
    expect_body = {404: 'unknown order', 401: 'invalid signature'}[code]
    check(f'downstream {code} relayed as {code} with its short body', s == code and b == expect_body, (s, b))
    check(f'downstream {code} logged as rejected', one(f"SELECT CONCAT(downstream_status, ':', outcome) FROM afrstar_callback_forwards WHERE order_sn = '{p['order_sn']}'") == f'{code}:rejected')
p = splug_payload(f'DEP-E500xxxx-{tag}', 'OSNE500' + tag)
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('downstream 500 -> 502 so AfrStar retries', s == 502 and b != 'success', (s, b))
check('downstream 500 logged as failed', one(f"SELECT CONCAT(downstream_status, ':', outcome) FROM afrstar_callback_forwards WHERE order_sn = '{p['order_sn']}'") == '500:failed')
p = splug_payload(f'DEP-REDIRxxx-{tag}', 'OSNR' + tag)
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('downstream redirect is not followed -> 502', s == 502 and len(received()) == 1, (s, b, received()))

# timeout
p = splug_payload(f'DEP-SLOWxxxx-{tag}', 'OSNS' + tag)
s, b, took = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
row = db(f"SELECT outcome, latency_ms, downstream_status FROM afrstar_callback_forwards WHERE order_sn = '{p['order_sn']}'")
check('downstream timeout -> 502', s == 502 and b == 'FORWARD_TIMEOUT', (s, b))
check('timeout respects route timeout (~2 s)', took < 3.8, took)
check('timeout logged with outcome timeout and latency', len(row) == 1 and row[0][0] == 'timeout' and 1500 <= int(row[0][1]) < 3800 and row[0][2] == 'NULL', row)

# YOLOTASK refs never forwarded, even when a route pattern names them
clear_received()
p = splug_payload('YTP261030120000ABCDEF1234', 'OSNYTP' + tag)
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('YTP ref goes to YOLOTASK processing (not forwarded)', s == 404 and b == 'UNKNOWN_ORDER' and received() == [], (s, b, received()))
p = splug_payload('YTW99999999A1', 'OSNYTW' + tag)
s, b, _ = post('/hooks/afrstar/payout.php', json.dumps(p).encode())
check('YTW ref goes to YOLOTASK processing (not forwarded)', s == 404 and b == 'UNKNOWN_ORDER' and received() == [], (s, b, received()))
check('no forward rows for YTP/YTW', count('afrstar_callback_forwards', "order_sn LIKE 'YT%'") == 0)

# another route (future V1 TOP- refs) works the same
p = splug_payload(f'TOP-{tag}', 'OSNTOP' + tag)
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('second route (TOP- -> v1) forwarded to its own URL', s == 200 and len(received()) == 1 and received()[0]['path'] == '/v1', (s, received()))

# unknown non-matching order: current behaviour
cbx = count('payment_callbacks')
p = splug_payload(f'ZZZ-{tag}', 'OSNZ' + tag)
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('non-matching unknown order keeps current behaviour (404, logged)', s == 404 and b == 'UNKNOWN_ORDER' and count('payment_callbacks') == cbx + 1 and received() == [])

# forwarded callbacks never touched YOLOTASK money or the callback log
fwd_rows = count('afrstar_callback_forwards', f"order_sn LIKE '%{tag}'")
check('forwarded callbacks wrote no payment_callbacks rows', count('payment_callbacks') == cb0 + 3, (count('payment_callbacks'), cb0))  # only YTP, YTW and ZZZ were logged
check('forwarded callbacks opened no payment reviews (only YT*/ZZZ did)', count('payment_reviews') - rv0 == 3, count('payment_reviews') - rv0)
check('forwarded callbacks posted nothing to the ledger', count('ledger_transactions') == led0)
check('payment orders and withdrawals untouched', one('SELECT CONCAT(COUNT(*), SUM(status = \'paid\')) FROM payment_orders') == po0 and one('SELECT COUNT(*) FROM withdrawals') == wd0)
check('no payment_callbacks row for any forwarded order', count('payment_callbacks', f"order_sn IN (SELECT order_sn FROM afrstar_callback_forwards)") == 0)

# guards
big = json.dumps({'order_sn': f'DEP-big00000-{tag}', 'pad': 'x' * 70000}).encode()
clear_received()
s, b, _ = post('/hooks/afrstar/collection.php', big)
check('body over 64 KB rejected with 413, not forwarded', s == 413 and received() == [], (s, b))
s, b, _ = post('/hooks/afrstar/collection.php', b'', None, method='GET')
check('GET is refused (405), never forwarded', s == 405 and received() == [], (s, b))

# retry cron ignores forwarded rows (they are not in payment_callbacks; nothing re-sent)
before = db('SELECT id, outcome FROM afrstar_callback_forwards ORDER BY id')
clear_received()
env = dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)
r5 = subprocess.run(['php', ROOT + '/cron/every-five-minutes.php'], capture_output=True, text=True, env=env)
check('five-minute cron runs', r5.returncode == 0 and 'unfinished callbacks retried' in r5.stdout, r5.stdout + r5.stderr)
check('retry cron did not re-send any forwarded callback', received() == [])
check('retry cron left the forward log unchanged', db('SELECT id, outcome FROM afrstar_callback_forwards ORDER BY id') == before)
check('payment_callbacks has no unfinished rows for SPLUG refs', count('payment_callbacks', "(order_sn LIKE 'DEP-%' OR order_sn LIKE 'WDL-%') AND outcome = 'received'") == 0)
rd = subprocess.run(['php', ROOT + '/cron/daily.php'], capture_output=True, text=True, env=env)
check('daily cron purges old forward rows and reconciliation stays OK', rd.returncode == 0 and 'forwarded AfrStar callbacks:' in rd.stdout and 'reconciliation: OK' in rd.stdout, rd.stdout[-600:] + rd.stderr)

# admin visibility (read-only)
PW = 'TestPass!2026abc'
admin_email = f'fwdadmin{tag}@gmail.com'
h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PW], capture_output=True, text=True).stdout
db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('Fwd Admin', '{admin_email}', '{h}', 'admin', 'active', UTC_TIMESTAMP())")
admin = Client(ADM)
check('admin signs in', admin.login(admin_email, PW))
s, l, page = admin.req('/payments.php')
check('admin payments page shows Forwarded callbacks section', s == 200 and 'Forwarded callbacks' in page, s)
check('forwarded row shows route, order and status', f'DEP-ab12cd34-{tag}' in page and 'splug' in page and 'delivered' in page and 'HTTP 200' in page)
check('timeout row visible as timeout', f'DEP-SLOWxxxx-{tag}' in page and '>timeout<' in page)
fwd_section = page[page.find('id="forwarded"'):page.find('</section>', page.find('id="forwarded"'))]
check('forwarded section is read-only (no forms or buttons)', '<form' not in fwd_section and '<button' not in fwd_section)

remove_cfg()
clear_received()
p = splug_payload(f'DEP-after000-{tag}', 'OSNAFTER' + tag)
s, b, _ = post('/hooks/afrstar/collection.php', json.dumps(p).encode())
check('removing the config file turns forwarding off again', s == 404 and received() == [], (s, b))

srv.shutdown()
fails = [r for r in results if not r[1]]
print(f'\n{len(results) - len(fails)}/{len(results)} passed')
sys.exit(1 if fails else 0)
