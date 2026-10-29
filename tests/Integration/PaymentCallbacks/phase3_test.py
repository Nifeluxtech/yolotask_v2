#!/usr/bin/env python3
"""YOLOTASK V2 Phase 3 integration test: wallets, ledger, AfrStar top-ups,
withdrawals, callbacks and admin money tools.

Needs: PHP 8.1+ with pdo_mysql + curl, MariaDB/MySQL with migrations
0001-0004 applied, a private config dir (database.local.php,
app.local.php with mail driver "log" and base_urls pointing at the local
servers), and the servers from run-servers.sh. Credentials are fake:
TESTSN001 / testak123 / testsk456.

Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_MAIL_LOG
(default /tmp/ytrt/logs/mail.log), YT_DB_CLI (default "mariadb -uyt -pytpass").
"""
import hashlib, http.cookiejar, json, os, re, shlex, subprocess, sys, time
import urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')
M, E, A, ADM, FAKE = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405', 'http://127.0.0.1:8499'
SN, AK, SK = 'TESTSN001', 'testak123', 'testsk456'
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:300] + ']') if (not ok and extra) else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None, raw=None, ctype=None, method=None):
        url = path if path.startswith('http') else self.base + path
        body = raw if raw is not None else (urllib.parse.urlencode(data).encode() if data is not None else None)
        headers = {'User-Agent': 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'}
        if ctype:
            headers['Content-Type'] = ctype
        r = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            resp = self.op.open(r, timeout=20)
            return resp.status, resp.headers.get('Location'), resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get('Location'), e.read().decode()

    def get(self, p):
        return self.req(p)

    def post(self, p, d):
        return self.req(p, d)

    @staticmethod
    def field(html, name):
        m = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)
        return m.group(1) if m else ''

    def csrf(self, html):
        return self.field(html, '_csrf')

    def login(self, email, pw):
        s, l, h = self.get('/login.php')
        s, l, _ = self.post('/login.php', {'_csrf': self.csrf(h), 'email': email, 'password': pw})
        return s == 303 and l == 'dashboard.php'


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


def mails():
    try:
        return [json.loads(l) for l in open(MAIL)]
    except FileNotFoundError:
        return []


def mails_to(to):
    return [m for m in mails() if m['to'] == to]


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


def fake_requests():
    return json.loads(urllib.request.build_opener(urllib.request.ProxyHandler({})).open(FAKE + '/_requests', timeout=5).read())


hooks = Client(M)


def collection_cb(order_sn, osn, order_amount, paid=None, status=1, sign=None, merchant=SN, form=False):
    paid = paid or order_amount
    payload = {'merchant_sn': merchant, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'message': 'Paid Success',
               'amount': paid, 'utr': 'UTR' + osn, 'session_id': 'S' + osn, 'amount_need': order_amount, 'amount_paid': paid,
               'time_paid': '2026-10-29 10:30:00', 'sign': sign or md5(AK, osn, status, order_amount)}
    if form:
        return hooks.req('/hooks/afrstar/collection.php', raw=urllib.parse.urlencode(payload).encode(), ctype='application/x-www-form-urlencoded')
    return hooks.req('/hooks/afrstar/collection.php', raw=json.dumps(payload).encode(), ctype='application/json')


def payout_cb(order_sn, osn, amount, status=1, sign=None):
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'utr': 'TRF' + osn, 'name': 'Ada Obi',
               'message': 'Transfered Success' if status == 1 else 'Transfer failed', 'amount': amount, 'time_transfered': '2026-10-29 10:35:00',
               'sign': sign or md5(AK, osn, status, amount)}
    return hooks.req('/hooks/afrstar/payout.php', raw=json.dumps(payload).encode(), ctype='application/json')


def make_user(role, email, pw, verified=True):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{role.title()} Tester', '{email}', '{h}', '{role}', 'active', {'UTC_TIMESTAMP()' if verified else 'NULL'})")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(uid):
    r = db(f"SELECT available_kobo, held_kobo FROM wallets WHERE user_id = {uid}")
    return (int(r[0][0]), int(r[0][1])) if r else None


def run_cron():
    env = dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)
    return subprocess.run(['php', ROOT + '/cron/daily.php'], capture_output=True, text=True, env=env)


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
earner_email, earner2_email = f'p3earner{tag}@gmail.com', f'p3earner2{tag}@gmail.com'
adv_email, adv2_email, admin_email = f'p3adv{tag}@gmail.com', f'p3adv2{tag}@gmail.com', f'p3admin{tag}@gmail.com'
eid, e2id = make_user('earner', earner_email, PW), make_user('earner', earner2_email, PW)
aid, a2id = make_user('advertiser', adv_email, PW), make_user('advertiser', adv2_email, PW)
admid = make_user('admin', admin_email, PW)
unverified_email = f'p3unv{tag}@gmail.com'
make_user('advertiser', unverified_email, PW, verified=False)

for f in ('payments.local.php', 'afrstar.key'):
    try:
        os.remove(os.path.join(CFG, f))
    except FileNotFoundError:
        pass

earner, earner2, adv, adv2, admin = Client(E), Client(E), Client(A), Client(A), Client(ADM)
check('earner signs in', earner.login(earner_email, PW))
check('second earner signs in', earner2.login(earner2_email, PW))
check('advertiser signs in', adv.login(adv_email, PW))
check('second advertiser signs in', adv2.login(adv2_email, PW))
check('admin signs in', admin.login(admin_email, PW))

# ---------- not configured
s, l, h = adv.get('/topup.php')
check('top-up page says payments not available when unconfigured', s == 200 and 'Payments are not available yet' in h)
s, l, h = earner.get('/withdraw.php')
check('withdraw page says payments not available when unconfigured', s == 200 and 'Payments are not available yet' in h)
s, l, h = collection_cb('NOPE', 'X1', '100.00')
check('callback without credentials is not acknowledged (503)', s == 503 and h != 'SUCCESS')

# ---------- configure: real example file reading a one-line key file, base URL -> fake server
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'  {SN} , {AK},{SK}  \n')
s, l, h = adv.get('/topup.php')
check('top-up page available once afrstar.key is present', s == 200 and 'Payments are not available yet' not in h and 'Continue to payment' in h)

# key file alone (no payments.local.php) is also read
tmpcfg = '/tmp/ytcfg_keyonly'
os.makedirs(tmpcfg, exist_ok=True)
open(tmpcfg + '/afrstar.key', 'w').write(f'{SN},{AK},{SK}\n')
snippet = ("define('YOLOTASK_APP_ROLE','cron');define('YOLOTASK_APP_ROOT',$argv[1]);define('YOLOTASK_NO_SESSION',true);"
           "require $argv[1].'/shared/bootstrap.php';$c=YoloTask\\Payments\\AfrstarClient::config();"
           "echo YoloTask\\Payments\\AfrstarClient::isConfigured()?'yes':'no',' ',$c['merchant_sn'],' ',$c['base_url'];")
out = subprocess.run(['php', '-r', snippet, ROOT], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=tmpcfg)).stdout
check('afrstar.key alone configures the client (default base URL)', out == 'yes TESTSN001 https://ngn.afrstar.com', out)

# ---------- wallets
s, l, h = earner.get('/wallet.php')
check('earner wallet page loads', s == 200 and 'AVAILABLE TO WITHDRAW' in h and '₦0.00' in h)
check('wallet created lazily with two ledger accounts', balance(eid) == (0, 0) and one(f"SELECT COUNT(*) FROM ledger_accounts a JOIN wallets w ON w.id = a.wallet_id WHERE w.user_id = {eid}") == '2')
s, l, h = earner.get('/dashboard.php')
check('earner dashboard shows wallet balance and dock link', 'Wallet balance' in h and 'href="wallet.php"' in h)
s, l, h = adv.get('/wallet.php')
check('advertiser wallet page loads with top-up action', s == 200 and 'Top up wallet' in h)

# ---------- top-up
s, l, h = adv.get('/topup.php')
tok = adv.csrf(h)
s, l, _ = adv.post('/topup.php', {'_csrf': tok, 'amount': '50', 'phone': '0803 123 4567'})
s, l, h = adv.get('/topup.php')
check('top-up below minimum rejected', 'minimum top-up is ₦100.00' in h)
s, l, _ = adv.post('/topup.php', {'_csrf': tok, 'amount': '600000', 'phone': '08031234567'})
s, l, h = adv.get('/topup.php')
check('top-up above maximum rejected', 'maximum top-up is ₦500,000.00' in h)
s, l, _ = adv.post('/topup.php', {'amount': '5000', 'phone': '08031234567'})
s, l, h = adv.get('/topup.php')
check('top-up without CSRF token rejected', 'form expired' in h and one(f"SELECT COUNT(*) FROM payment_orders WHERE user_id = {aid}") == '0')
s, l, _ = adv.post('/topup.php', {'_csrf': tok, 'amount': '5,000', 'phone': '0803 123 4567'})
check('valid top-up redirects to AfrStar pay_url', s == 303 and (l or '').startswith('https://pay.fake.test/p/'), (s, l))
order = db(f"SELECT order_sn, platform_osn, amount_kobo, status FROM payment_orders WHERE user_id = {aid} ORDER BY id DESC LIMIT 1")[0]
order_sn, osn = order[0], order[1]
req = [r for r in fake_requests() if r['path'] == '/gateway/payin/'][-1]
check('payin sent with correct signature, amount, phone and redirect', req['sign_ok'] and req['body']['amount'] == '5000.00' and req['body']['phone'] == '08031234567'
      and req['body']['order_sn'] == order_sn and 'topup-return.php?o=' + order_sn in req['body']['redirect_url'], req)
check('order pending with platform_osn and amount in kobo', order[2] == '500000' and order[3] == 'pending' and osn.startswith('PI'))
s, l, h = adv.get('/topup-return.php?o=' + order_sn)
check("return page says we'll credit once AfrStar confirms", s == 200 and 'credit you once AfrStar confirms' in h)
s, l, h = adv2.get('/topup-return.php?o=' + order_sn)
check("another advertiser cannot see someone else's order (404)", s == 404)

s, l, h = collection_cb(order_sn, osn, '5000.00', sign='0' * 32)
check('collection callback with bad signature rejected (non-200)', s == 400 and h != 'SUCCESS' and balance(aid) == (0, 0))
s, l, h = collection_cb(order_sn, osn, '5000.00', merchant='OTHER')
check('collection callback for another merchant rejected', s == 400 and balance(aid) == (0, 0))
s, l, h = collection_cb(order_sn, 'PIWRONG', '5000.00')
check('collection callback with wrong platform_osn rejected', s == 400 and balance(aid) == (0, 0))
s, l, h = collection_cb(order_sn, osn, '5000.00', status=2)
check('non-paid status acknowledged but not credited', s == 200 and h == 'SUCCESS' and balance(aid) == (0, 0))
before = len(mails_to(adv_email))
s, l, h = collection_cb(order_sn, osn, '5000.00')
check('valid signed callback answers HTTP 200 with exact body SUCCESS', s == 200 and h == 'SUCCESS', (s, h))
check('wallet credited ₦5,000', balance(aid) == (500000, 0), balance(aid))
check('funded email sent', len(mails_to(adv_email)) == before + 1 and 'funded' in mails_to(adv_email)[-1]['subject'])
s, l, h = collection_cb(order_sn, osn, '5000.00')
check('replayed callback acknowledged without double credit', s == 200 and h == 'SUCCESS' and balance(aid) == (500000, 0))
check('exactly one ledger posting for the order', one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'payin:{order_sn}'") == '1')
s, l, h = adv.get('/topup-return.php?o=' + order_sn)
check('return page shows credited', 'Your wallet has been credited' in h)
check('raw callbacks logged', int(one(f"SELECT COUNT(*) FROM payment_callbacks WHERE order_sn = '{order_sn}'")) >= 6)

# form-encoded callback tolerated
s, l, h = adv.get('/topup.php')
adv.post('/topup.php', {'_csrf': adv.csrf(h), 'amount': '1000', 'phone': '08031234567'})
o2 = db(f"SELECT order_sn, platform_osn FROM payment_orders WHERE user_id = {aid} ORDER BY id DESC LIMIT 1")[0]
s, l, h = collection_cb(o2[0], o2[1], '1000.00', form=True)
check('form-encoded callback accepted and credited', s == 200 and h == 'SUCCESS' and balance(aid) == (600000, 0), (s, h, balance(aid)))

# unknown order -> review queue, not acknowledged
s, l, h = collection_cb('YTPUNKNOWN123', 'PIZZZ', '700.00')
check('callback for unknown order is not acknowledged and is queued for review', s == 404 and one("SELECT COUNT(*) FROM payment_reviews WHERE order_sn = 'YTPUNKNOWN123' AND kind = 'unmatched_callback'") == '1')

# mismatched amount -> review, never auto-credited
s, l, h = adv.get('/topup.php')
adv.post('/topup.php', {'_csrf': adv.csrf(h), 'amount': '2000', 'phone': '08031234567'})
o3 = db(f"SELECT order_sn, platform_osn, id FROM payment_orders WHERE user_id = {aid} ORDER BY id DESC LIMIT 1")[0]
s, l, h = collection_cb(o3[0], o3[1], '2000.00', paid='1500.00')
check('mismatched amount acknowledged but NOT credited', s == 200 and h == 'SUCCESS' and balance(aid) == (600000, 0))
check('order moved to review with a review item', one(f"SELECT status FROM payment_orders WHERE id = {o3[2]}") == 'review'
      and one(f"SELECT COUNT(*) FROM payment_reviews WHERE payment_order_id = {o3[2]} AND status = 'open'") == '1')
collection_cb(o3[0], o3[1], '2000.00', paid='1500.00')
check('replayed mismatch does not duplicate the review', one(f"SELECT COUNT(*) FROM payment_reviews WHERE payment_order_id = {o3[2]}") == '1' and balance(aid) == (600000, 0))
s, l, h = adv.get('/topup-return.php?o=' + o3[0])
check('return page explains the payment is being checked', 'We are checking your payment' in h)

# admin review queue
s, l, h = admin.get('/payments.php')
check('admin payments page lists the mismatch and the unknown order', s == 200 and o3[0] in h and 'YTPUNKNOWN123' in h and 'Credit ₦1,500.00' in h)
rid = one(f"SELECT id FROM payment_reviews WHERE payment_order_id = {o3[2]}")
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'action': 'credit', 'review_id': rid, 'note': ''})
check('review credit needs a note', balance(aid) == (600000, 0))
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'action': 'credit', 'review_id': rid, 'note': 'Customer underpaid; credit actual amount'})
check('admin credits the actual paid amount from review', balance(aid) == (750000, 0) and one(f"SELECT status FROM payment_orders WHERE id = {o3[2]}") == 'paid', balance(aid))
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'action': 'credit', 'review_id': rid, 'note': 'again'})
check('review cannot be credited twice', balance(aid) == (750000, 0))
uid_review = one("SELECT id FROM payment_reviews WHERE order_sn = 'YTPUNKNOWN123'")
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'review_id': uid_review, 'note': 'Not ours; checked AfrStar portal'})
check('admin dismisses unmatched callback', one(f"SELECT status FROM payment_reviews WHERE id = {uid_review}") == 'dismissed')
s, l, h = admin.get('/wallets.php')
check('admin AfrStar balance card shows live balance', '₦105.00' in h)

# unverified advertiser cannot top up
unv = Client(A)
unv.login(unverified_email, PW)
s, l, h = unv.get('/topup.php')
unv.post('/topup.php', {'_csrf': unv.csrf(h), 'amount': '1000', 'phone': '08031234567'})
s, l, h = unv.get('/topup.php')
check('unverified advertiser cannot top up', 'Confirm your email' in h)

# ---------- admin manual adjustment
s, l, h = admin.get('/wallets.php?q=' + urllib.parse.quote(earner_email))
check('admin wallet search finds earner', earner_email in h)
idem = re.search(r'name="user_id" value="%d"><input type="hidden" name="idem" value="([a-f0-9]{32})"' % eid, h).group(1)
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': eid, 'idem': idem, 'direction': 'credit', 'amount': '10000', 'reason': ''})
check('adjustment without reason rejected', balance(eid) == (0, 0))
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': eid, 'idem': idem, 'direction': 'credit', 'amount': '10000', 'reason': 'Test task earnings'})
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': eid, 'idem': idem, 'direction': 'credit', 'amount': '10000', 'reason': 'Test task earnings'})
check('admin credit adjustment posted once (double submit ignored)', balance(eid) == (1000000, 0), balance(eid))
s, l, h = admin.get('/wallets.php?q=' + urllib.parse.quote(earner_email))
idem2 = re.search(r'name="user_id" value="%d"><input type="hidden" name="idem" value="([a-f0-9]{32})"' % eid, h).group(1)
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': eid, 'idem': idem2, 'direction': 'debit', 'amount': '20000', 'reason': 'Too much'})
check('debit larger than balance refused', balance(eid) == (1000000, 0))
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': eid, 'idem': idem2, 'direction': 'debit', 'amount': '100', 'reason': 'Correction'})
check('admin debit adjustment posted', balance(eid) == (990000, 0))
check('adjustments audited', one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key IN ('admin.wallet_credited','admin.wallet_debited')") == '2')
admin.post('/wallets.php', {'action': 'adjust', 'user_id': eid, 'idem': 'a' * 32, 'direction': 'credit', 'amount': '50', 'reason': 'no csrf here'})
check('adjustment without CSRF rejected', balance(eid) == (990000, 0))

# ---------- bank account
s, l, h = earner.get('/withdraw.php')
check('withdraw page asks for a bank account first', 'Add your bank account' in h or 'Add bank account' in h)
s, l, h = earner.get('/bank-account.php')
check('bank list has all AfrStar codes', h.count('<option value="8000') == 142)
earner.post('/bank-account.php', {'_csrf': earner.csrf(h), 'bank_code': '80000009', 'account_number': '0123456789', 'account_name': 'Ada Obi', 'password': 'wrong-password!!'})
s, l, h = earner.get('/bank-account.php')
check('bank account needs the correct password', 'password is incorrect' in h and one(f"SELECT COUNT(*) FROM bank_accounts WHERE user_id = {eid}") == '0')
earner.post('/bank-account.php', {'_csrf': earner.csrf(h), 'bank_code': '80000009', 'account_number': '01234', 'account_name': 'Ada Obi', 'password': PW})
s, l, h = earner.get('/bank-account.php')
check('short account number rejected', '10-digit' in h)
before = len(mails_to(earner_email))
s, l, _ = earner.post('/bank-account.php', {'_csrf': earner.csrf(h), 'bank_code': '80000009', 'account_number': '0123 456 789', 'account_name': 'Ada Obi', 'password': PW})
check('bank account saved', s == 303 and one(f"SELECT CONCAT(bank_code, ':', account_number) FROM bank_accounts WHERE user_id = {eid}") == '80000009:0123456789')
check('security email sent for bank account', len(mails_to(earner_email)) == before + 1 and 'bank account' in mails_to(earner_email)[-1]['subject'])

# ---------- withdrawal request, double submit, one-open rule, reject
s, l, h = earner.get('/withdraw.php')
wtok, widem = earner.csrf(h), earner.field(h, 'idem')
earner.post('/withdraw.php', {'_csrf': wtok, 'idem': widem, 'amount': '500'})
s, l, h = earner.get('/withdraw.php')
check('withdrawal below ₦1,000 rejected', 'minimum withdrawal is ₦1,000.00' in h)
earner.post('/withdraw.php', {'_csrf': wtok, 'idem': widem, 'amount': '50000'})
s, l, h = earner.get('/withdraw.php')
check('withdrawal above balance rejected', 'available balance is ₦9,900.00' in h)
earner.post('/withdraw.php', {'_csrf': 'bad', 'idem': widem, 'amount': '3000'})
check('withdrawal without valid CSRF rejected', balance(eid) == (990000, 0))
s, l, _ = earner.post('/withdraw.php', {'_csrf': wtok, 'idem': widem, 'amount': '3000'})
s2, l2, _ = earner.post('/withdraw.php', {'_csrf': wtok, 'idem': widem, 'amount': '3000'})
check('withdrawal request holds funds (double submit ignored)', balance(eid) == (690000, 300000) and one(f"SELECT COUNT(*) FROM withdrawals WHERE user_id = {eid}") == '1', balance(eid))
s, l, h = earner.get('/withdraw.php')
earner.post('/withdraw.php', {'_csrf': wtok, 'idem': earner.field(h, 'idem'), 'amount': '1000'})
s, l, h = earner.get('/withdraw.php')
check('second open withdrawal refused', 'already have a withdrawal in progress' in h and balance(eid) == (690000, 300000))
wid = one(f"SELECT id FROM withdrawals WHERE user_id = {eid} ORDER BY id DESC LIMIT 1")
s, l, h = admin.get('/withdrawals.php')
check('admin sees the withdrawal to review', s == 200 and f'data-withdrawal="{wid}"' in h)
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'withdrawal_id': wid, 'reason': ''})
check('reject needs a reason', balance(eid) == (690000, 300000))
before = len(mails_to(earner_email))
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'withdrawal_id': wid, 'reason': 'Please verify your account name'})
check('reject releases the hold', balance(eid) == (990000, 0) and one(f"SELECT status FROM withdrawals WHERE id = {wid}") == 'rejected')
check('rejection email sent', len(mails_to(earner_email)) == before + 1 and 'declined' in mails_to(earner_email)[-1]['subject'])
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid})
check('rejected withdrawal cannot be approved', one(f"SELECT status FROM withdrawals WHERE id = {wid}") == 'rejected')

# ---------- approve -> AfrStar payout -> success callback
s, l, h = earner.get('/withdraw.php')
earner.post('/withdraw.php', {'_csrf': earner.csrf(h), 'idem': earner.field(h, 'idem'), 'amount': '2000'})
wid2 = one(f"SELECT id FROM withdrawals WHERE user_id = {eid} ORDER BY id DESC LIMIT 1")
check('second withdrawal holds ₦2,000', balance(eid) == (790000, 200000))
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'action': 'approve', 'withdrawal_id': wid2})
check('approve without CSRF rejected', one(f"SELECT status FROM withdrawals WHERE id = {wid2}") == 'pending_review')
s, l, _ = admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid2})
w2 = db(f"SELECT status, order_sn, platform_osn FROM withdrawals WHERE id = {wid2}")[0]
req = [r for r in fake_requests() if r['path'] == '/gateway/payout/'][-1]
check('approve sends a correctly signed AfrStar bank payout', req['sign_ok'] and req['body']['amount'] == '2000.00' and req['body']['ifsc'] == '80000009'
      and req['body']['account'] == '0123456789' and req['body']['type'] == 'bank' and req['body']['upi_handle'] == '' and req['body']['order_sn'] == w2[1], req)
check('withdrawal is processing with platform_osn stored', w2[0] == 'processing' and w2[2].startswith('PO'), w2)
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid2})
check('double approve sends nothing more', len([r for r in fake_requests() if r['path'] == '/gateway/payout/' and r['body']['order_sn'] == w2[1]]) == 1)
s, l, h = payout_cb(w2[1], w2[2], '2000.00', sign='f' * 32)
check('payout callback with bad signature rejected', s == 400 and one(f"SELECT status FROM withdrawals WHERE id = {wid2}") == 'processing')
s, l, h = payout_cb(w2[1], w2[2], '1999.00', sign=md5(AK, w2[2], 1, '1999.00'))
check('payout callback with a different amount rejected', s == 400 and one(f"SELECT status FROM withdrawals WHERE id = {wid2}") == 'processing')
before = len(mails_to(earner_email))
s, l, h = payout_cb(w2[1], w2[2], '2000.00')
check('payout success callback answers SUCCESS', s == 200 and h == 'SUCCESS')
check('payout success finalises: held cleared, status paid', balance(eid) == (790000, 0) and one(f"SELECT status FROM withdrawals WHERE id = {wid2}") == 'paid', balance(eid))
check('paid email sent', len(mails_to(earner_email)) == before + 1 and 'paid' in mails_to(earner_email)[-1]['subject'])
s, l, h = payout_cb(w2[1], w2[2], '2000.00')
check('replayed payout success is a no-op', s == 200 and h == 'SUCCESS' and balance(eid) == (790000, 0))
s, l, h = payout_cb(w2[1], w2[2], '2000.00', status=9)
check('contradictory failure after paid goes to review, no money moves', s == 200 and balance(eid) == (790000, 0)
      and one(f"SELECT COUNT(*) FROM payment_reviews WHERE withdrawal_id = {wid2} AND kind = 'payout_conflict'") == '1')

# ---------- approve -> failure callback refunds
s, l, h = earner.get('/withdraw.php')
earner.post('/withdraw.php', {'_csrf': earner.csrf(h), 'idem': earner.field(h, 'idem'), 'amount': '1000'})
wid3 = one(f"SELECT id FROM withdrawals WHERE user_id = {eid} ORDER BY id DESC LIMIT 1")
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid3})
w3 = db(f"SELECT status, order_sn, platform_osn FROM withdrawals WHERE id = {wid3}")[0]
before = len(mails_to(earner_email))
s, l, h = payout_cb(w3[1], w3[2], '1000.00', status=9)
check('payout failure refunds the wallet', s == 200 and h == 'SUCCESS' and balance(eid) == (790000, 0) and one(f"SELECT status FROM withdrawals WHERE id = {wid3}") == 'failed', balance(eid))
check('failure email sent to the earner', len(mails_to(earner_email)) == before + 1 and 'could not be paid' in mails_to(earner_email)[-1]['subject'])
payout_cb(w3[1], w3[2], '1000.00', status=9)
check('replayed failure does not refund twice', balance(eid) == (790000, 0))

# ---------- AfrStar refuses the payout -> back to review, nothing sent twice
s, l, h = earner.get('/bank-account.php')
earner.post('/bank-account.php', {'_csrf': earner.csrf(h), 'bank_code': '80000030', 'account_number': '0000000000', 'account_name': 'Ada Obi', 'password': PW})
check('bank account change emails an alert', 'changed' in mails_to(earner_email)[-1]['subject'])
s, l, h = earner.get('/withdraw.php')
earner.post('/withdraw.php', {'_csrf': earner.csrf(h), 'idem': earner.field(h, 'idem'), 'amount': '1500'})
wid4 = one(f"SELECT id FROM withdrawals WHERE user_id = {eid} ORDER BY id DESC LIMIT 1")
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid4})
s, l, h = admin.get('/withdrawals.php')
check('AfrStar refusal returns the withdrawal to review with the reason', one(f"SELECT status FROM withdrawals WHERE id = {wid4}") == 'pending_review' and 'Invalid beneficiary account' in h)
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'withdrawal_id': wid4, 'reason': 'Bank refused the account'})
check('funds back after rejecting the refused payout', balance(eid) == (790000, 0))

# ---------- money settings
s, l, h = admin.get('/payment-settings.php')
admin.post('/payment-settings.php', {'_csrf': admin.csrf(h), 'money_topup_min_kobo': '200', 'money_topup_max_kobo': '500000', 'money_withdraw_min_kobo': '1000', 'money_withdraw_max_kobo': '500000', 'money_payin_expiry_hours': '24'})
s, l, h = adv.get('/topup.php')
check('admin can change the minimum top-up', 'Minimum ₦200.00' in h)
adv.post('/topup.php', {'_csrf': adv.csrf(h), 'amount': '150', 'phone': '08031234567'})
s, l, h = adv.get('/topup.php')
check('new minimum enforced', 'minimum top-up is ₦200.00' in h)
s, l, h = admin.get('/payment-settings.php')
admin.post('/payment-settings.php', {'_csrf': admin.csrf(h), 'money_topup_min_kobo': '100', 'money_topup_max_kobo': '500000', 'money_withdraw_min_kobo': '1000', 'money_withdraw_max_kobo': '500000', 'money_payin_expiry_hours': '24'})

# ---------- role and ownership
s, l, h = earner.get(ADM + '/wallets.php')
check('earner session does not open admin wallets', s in (303, 403) and 'User wallets' not in h)
s, l, h = adv.get(ADM + '/withdrawals.php')
check('advertiser session does not open admin withdrawals', s in (303, 403))
fake_admin = Client(ADM)
check('earner credentials cannot sign in to admin', not fake_admin.login(earner_email, PW))
s, l, h = fake_admin.post('/withdrawals.php', {'_csrf': 'x', 'action': 'approve', 'withdrawal_id': wid4})
check('anonymous admin POST bounced to sign-in', s == 303 and l == 'login.php')
# (php -S serves index.php for unknown paths; Apache answers 404)
s, l, h = earner.get('/topup.php')
check('earner portal has no top-up page', 'Continue to payment' not in h and one(f"SELECT COUNT(*) FROM payment_orders WHERE user_id = {eid}") == '0')
s, l, h = adv.get('/withdraw.php')
check('advertiser portal has no withdraw page', 'Request withdrawal' not in h)
s, l, h = earner2.get('/wallet.php')
check("another earner sees only their own wallet", '₦0.00' in h and '₦7,900.00' not in h)
s, l, h = adv2.get('/wallet.php')
check("another advertiser sees only their own wallet", '₦7,500.00' not in h and 'No top-ups yet' in h)
s, l, h = Client(E).get('/wallet.php')
check('signed-out wallet page redirects to sign in', s == 303 and l == 'login.php')
s, l, h = hooks.get('/hooks/afrstar/collection.php')
check('callback endpoints refuse GET', s == 405)

# ---------- ledger + reconciliation
check('every ledger transaction balances', one("SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries GROUP BY transaction_id HAVING SUM(CASE WHEN direction='debit' THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END) <> 0) x") == '0')
check('total debits equal total credits', one("SELECT SUM(CASE WHEN direction='debit' THEN amount_kobo ELSE 0 END) = SUM(CASE WHEN direction='credit' THEN amount_kobo ELSE 0 END) FROM ledger_entries") == '1')
clearing = int(one("SELECT balance_kobo FROM ledger_accounts WHERE code = 'system:afrstar_clearing'"))
check('AfrStar clearing equals top-ups in minus payouts out', clearing == int(one("SELECT COALESCE(SUM(credited_kobo),0) FROM payment_orders WHERE status='paid'")) - int(one("SELECT COALESCE(SUM(amount_kobo),0) FROM withdrawals WHERE status='paid'")), clearing)
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'reconcile'})
s, l, h = admin.get('/wallets.php')
check('admin reconciliation passes', 'Reconciliation passed' in h)
db("UPDATE payment_orders SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 HOUR) WHERE status = 'pending'")
stale = int(one("SELECT COUNT(*) FROM payment_orders WHERE status = 'pending'"))
r = run_cron()
check('daily cron reconciliation OK', r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr)
check('daily cron expires stale pending top-ups without moving money', f'stale top-up orders expired: {stale}' in r.stdout and one("SELECT COUNT(*) FROM payment_orders WHERE status = 'pending'") == '0')
db(f"UPDATE wallets SET available_kobo = available_kobo + 1 WHERE user_id = {eid}")
r = run_cron()
check('daily cron detects a tampered wallet cache', r.returncode == 2 and 'MISMATCH' in r.stdout)
db(f"UPDATE wallets SET available_kobo = available_kobo - 1 WHERE user_id = {eid}")
r = run_cron()
check('reconciliation clean again after repair', r.returncode == 0)
s, l, h = admin.get('/transactions.php')
check('admin ledger page lists transactions', s == 200 and 'Ledger transactions' in h and 'Adjustments' in h)
s, l, h = admin.get('/dashboard.php')
check('admin dashboard links withdrawals and payment review', 'withdrawals.php' in h and 'payments.php' in h and 'wallets.php' in h)

fails = [r for r in results if not r[1]]
print(f'\n{len(results) - len(fails)}/{len(results)} passed')
sys.exit(1 if fails else 0)
