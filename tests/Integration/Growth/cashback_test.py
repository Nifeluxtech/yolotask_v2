#!/usr/bin/env python3
"""YOLOTASK V2 integration test: advertiser deposit cashback (migration 0013).

Covers the migration (table, system:deposit_cashback, default settings 0 / 0),
cashback off by default (skipped row, nothing posted, offer hidden), the
calculation and rounding down to the kobo, the minimum deposit (below = no
cashback, exactly the minimum = cashback), idempotency (a redelivered
callback and three simultaneous callbacks pay once; ledger key
cashback:<orderId>), the admin review-credit path (cashback on the credited
amount), admin wallet credits and bonuses excluded, no referral commission
or further cashback on cashback, referral commission still paid on the
deposit alongside cashback, a failing cashback never breaking deposit
confirmation (and the hourly catch-up paying it later), an inactive account
skipped, the advertiser wallet history / receipt line ("Deposit cashback X%
on top-up <order_sn>"), the in-app notification, the top-up page offer
(percentage and minimum; hidden at 0%), admin settings validation, CSRF,
authorization and audit, admin reversal, reconciliation (ledger + tampering
detected + database/verify/deposit_cashback_reconciliation.sql). Settings
are restored to 0 / 0 at the end so the other suites see cashback off.

Needs the Phase 6 environment (servers from
tests/Integration/PaymentCallbacks/run-servers.sh incl. the fake AfrStar on
8499) and migration 0013. Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg),
YT_DB_CLI.
"""
import hashlib, json, os, re, shlex, subprocess, sys, threading, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
M, E, A, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405'
SN, AK, SK = 'TESTSN001', 'testak123', 'testsk456'
UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:400] + ']') if (not ok and extra != '') else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None, raw=None, ctype=None):
        url = path if path.startswith('http') else self.base + path
        body = raw if raw is not None else (urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
        headers = {'User-Agent': UA}
        if ctype:
            headers['Content-Type'] = ctype
        try:
            resp = self.op.open(urllib.request.Request(url, data=body, headers=headers), timeout=30)
            return resp.status, resp.headers, resp.read().decode(errors='replace')
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode(errors='replace')

    def get(self, p):
        s, h, b = self.req(p)
        return s, h.get('Location'), b

    def post(self, p, d):
        s, h, b = self.req(p, d)
        return s, h.get('Location'), b

    @staticmethod
    def field(html, name):
        m = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)
        return m.group(1) if m else ''

    def csrf(self, html):
        return self.field(html, '_csrf')

    def login(self, email, pw):
        s, l, h = self.get('/login.php')
        s, l, _ = self.post('/login.php', {'_csrf': self.field(h, '_csrf'), 'email': email, 'password': pw})
        return s == 303 and l == 'dashboard.php'

    def flash(self, path):
        s, l, h = self.get(path)
        m = re.search(r'class="toast-msg">([^<]*)<', h)
        return (m.group(1) if m else ''), h


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


def make_user(role, email, pw, name):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name}', '{email}', '{h}', '{role}', 'active', UTC_TIMESTAMP())")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(u):
    r = db(f"SELECT available_kobo FROM wallets WHERE user_id = {u}")
    return int(r[0][0]) if r else 0


def sysbal(code):
    return int(one(f"SELECT balance_kobo FROM ledger_accounts WHERE code = '{code}'"))


def cron(name):
    return subprocess.run(['php', ROOT + '/cron/' + name], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))


def reconcile_ok():
    r = cron('daily.php')
    return r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


def sval(key):
    return one(f"SELECT setting_value FROM settings WHERE setting_key = '{key}'")


def cb_row(order_id):
    return db(f"SELECT status, base_kobo, rate_bps, amount_kobo, COALESCE(skip_reason, ''), ledger_transaction_id IS NULL FROM deposit_cashbacks WHERE payment_order_id = {order_id}")


def notes(u):
    return db(f"SELECT category, title, body, url FROM notifications WHERE user_id = {u} ORDER BY id")


hooks = Client(M)


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


def collection_cb(order_sn, osn, order_amount, paid=None, status=1):
    paid = paid or order_amount
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'message': 'Paid Success', 'amount': paid,
               'utr': 'UTR' + osn, 'session_id': 'S' + osn, 'amount_need': order_amount, 'amount_paid': paid, 'time_paid': '2026-11-01 10:30:00',
               'sign': md5(AK, osn, status, order_amount)}
    out = hooks.req('/hooks/afrstar/collection.php', raw=json.dumps(payload).encode(), ctype='application/json')
    time.sleep(0.6)  # notifications, commission and cashback run after the response is sent
    return out


def topup(client, user_id, naira):
    s, l, h = client.get('/topup.php')
    client.post('/topup.php', {'_csrf': client.csrf(h), 'amount': str(naira), 'phone': '08031234567'})
    return db(f"SELECT order_sn, platform_osn, id FROM payment_orders WHERE user_id = {user_id} ORDER BY id DESC LIMIT 1")[0]


def deposit(client, user_id, naira):
    o = topup(client, user_id, naira)
    s, l, b = collection_cb(o[0], o[1], '%.2f' % float(naira))
    return o, s, b


# ---------- AfrStar config pointing at the fake gateway (same as phase3/phase6)
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')

tag = str(int(time.time()))
PW = 'TestPass!2026abc'
setting('referrals.enabled', '1')
setting('referrals.commission_bps', '500')
setting('cashback.rate_bps', '0')
setting('cashback.min_deposit_kobo', '0')

# ======================================================================
# 1. Migration, defaults
# ======================================================================
mig = sorted(os.listdir(ROOT + '/database/migrations'))
check('migration 0013_deposit_cashback recorded (one 0013 file)', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0013_deposit_cashback.sql'") == '1'
      and len([f for f in mig if f.startswith('0013_')]) == 1, mig)
check('0010/0011 still skipped placeholders', one("SELECT COUNT(*) FROM schema_migrations WHERE filename LIKE '0010_%' OR filename LIKE '0011_%'") == '0')
check('system:deposit_cashback exists (system, debit-normal, no negative)', one("SELECT COUNT(*) FROM ledger_accounts WHERE code = 'system:deposit_cashback' AND kind = 'system' AND normal_side = 'debit' AND allow_negative = 0") == '1')
check('deposit_cashbacks has one row per order (unique payment_order_id)', one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'deposit_cashbacks' AND index_name = 'uq_deposit_cashbacks_order' AND non_unique = 0") == '1')
seeded = subprocess.run(['grep', '-c', "('cashback.rate_bps', '0')\\|('cashback.min_deposit_kobo', '0')", ROOT + '/database/migrations/0013_deposit_cashback.sql'], capture_output=True, text=True).stdout.strip()
check('migration seeds cashback.rate_bps = 0 and cashback.min_deposit_kobo = 0', seeded == '2', seeded)
php_defaults = subprocess.run(['php', '-r', 'require $argv[1]; echo YoloTask\\Core\\Settings::DEFAULTS["cashback.rate_bps"], "/", YoloTask\\Core\\Settings::DEFAULTS["cashback.min_deposit_kobo"];', ROOT + '/shared/lib/Core/Settings.php'], capture_output=True, text=True).stdout
check('code defaults: off (0) and no minimum (0)', php_defaults == '0/0', php_defaults)

# unit: rounding (floor to the kobo)
unit = subprocess.run(['php', '-r', '''
require $argv[1]; require $argv[2]; require $argv[3]; require $argv[4];
use YoloTask\\Growth\\DepositCashback as C;
echo json_encode([C::amountFor(33333, 250), C::amountFor(10000, 1), C::amountFor(99, 100), C::amountFor(100000, 5000), C::amountFor(100000, 9000), C::amountFor(100000, 0), C::amountFor(0, 500), C::amountFor(1999999, 333)]);
''', ROOT + '/shared/lib/Core/Settings.php', ROOT + '/shared/lib/Money/Money.php', ROOT + '/shared/lib/Growth/Referrals.php', ROOT + '/shared/lib/Growth/DepositCashback.php'],
    capture_output=True, text=True)
check('rounding: floor to the kobo (₦333.33 × 2.5% = 833 kobo; 0.01% of ₦100 = 1 kobo; 1% of 99 kobo = 0; cap 50%; 0 → 0)',
      unit.returncode == 0 and json.loads(unit.stdout.strip() or 'null') == [833, 1, 0, 50000, 50000, 0, 0, 66599], unit.stdout + unit.stderr)

# ======================================================================
# 2. Users
# ======================================================================
em = {k: f'cb{k}{tag}@gmail.com' for k in ['adv', 'adv2', 'ref', 'admin', 'earner', 'adv3']}
advid = make_user('advertiser', em['adv'], PW, 'Chika Okafor')
adv2id = make_user('advertiser', em['adv2'], PW, 'Dayo Referred')
refid = make_user('advertiser', em['ref'], PW, 'Remi Referrer')
admid = make_user('admin', em['admin'], PW, 'Grace Admin')
earnid = make_user('earner', em['earner'], PW, 'Esther Earner')
adv3id = make_user('advertiser', em['adv3'], PW, 'Femi Suspended')
# adv2 was referred by refid before any deposit (direct row; capture is covered by phase6_test)
db(f"INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status, created_at) VALUES ({refid}, {adv2id}, 'advertiser', 'CBTEST{tag[-6:]}', 'active', UTC_TIMESTAMP() - INTERVAL 1 HOUR)")
adv, adv2, adv3, admin, earner = Client(A), Client(A), Client(A), Client(ADM), Client(E)
check('advertisers, admin and earner sign in', adv.login(em['adv'], PW) and adv2.login(em['adv2'], PW) and adv3.login(em['adv3'], PW)
      and admin.login(em['admin'], PW) and earner.login(em['earner'], PW))
cbacct0 = sysbal('system:deposit_cashback')

# ======================================================================
# 3. Off by default
# ======================================================================
s, l, h = adv.get('/topup.php')
check('top-up page: no cashback offer while cashback is 0%', s == 200 and 'cashback-offer' not in h and 'cashback on deposits' not in h.lower(), re.findall(r'.{60}cashback.{60}', h, re.I | re.S))
o0, s, b = deposit(adv, advid, 1000)
check('deposit at 0%: credited ₦1,000, callback SUCCESS', s == 200 and b == 'SUCCESS' and balance(advid) == 100000, (s, b, balance(advid)))
check('0%: cashback row "skipped" (off), nothing posted', cb_row(o0[2]) == [['skipped', '100000', '0', '0', 'off', '1']]
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'cashback:{o0[2]}'") == '0' and sysbal('system:deposit_cashback') == cbacct0, cb_row(o0[2]))

# ======================================================================
# 4. Admin settings: page, validation, CSRF, audit, authorization
# ======================================================================
s, l, h = admin.get('/referrals.php')
tok = admin.csrf(h)
check('admin referrals page shows the cashback card next to the commission settings', s == 200 and 'id="cashback"' in h and 'name="cashback_percent"' in h
      and 'name="cashback_min"' in h and 'name="commission_percent"' in h and 'Off' in h)


def save_cb(pct, mn, csrf=None, client=None, path='/referrals.php'):
    c = client or admin
    return c.post(path, {'_csrf': tok if csrf is None else csrf, 'action': 'cashback_settings', 'cashback_percent': pct, 'cashback_min': mn})


audit0 = int(one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key LIKE 'admin.cashback%'"))
save_cb('60', '0')
msg, _ = admin.flash('/referrals.php')
check('cashback above 50% refused with a message', sval('cashback.rate_bps') == '0' and 'from 0 to 50' in msg, msg)
for bad in ('-1', 'abc', '2.555', '5%', ''):
    save_cb(bad, '0')
check('non-numeric / negative / 3-decimal / empty percentages refused', sval('cashback.rate_bps') == '0')
save_cb('5', '-100')
save_cb('5', 'ten')
check('negative or non-numeric minimum refused (rate not saved either)', sval('cashback.rate_bps') == '0' and sval('cashback.min_deposit_kobo') == '0')
save_cb('5', '600000')
msg, _ = admin.flash('/referrals.php')
check('minimum above ₦500,000 refused', sval('cashback.rate_bps') == '0' and 'at most' in msg, msg)
save_cb('10', '0', csrf='bad')
check('settings change without a valid CSRF token refused', sval('cashback.rate_bps') == '0')
s, l, h = Client(ADM).post('/referrals.php', {'_csrf': 'x', 'action': 'cashback_settings', 'cashback_percent': '10', 'cashback_min': '0'})
check('signed-out POST to the admin page refused (redirect to login)', sval('cashback.rate_bps') == '0' and s == 303)
s, l, h = adv.get('/referrals.php')
save_cb('10', '0', csrf=adv.csrf(h), client=adv)
s, l, h = earner.get('/referrals.php')
save_cb('10', '0', csrf=earner.csrf(h), client=earner)
check('advertiser and earner cannot change cashback (own portals ignore the action)', sval('cashback.rate_bps') == '0')
check('an advertiser cannot sign in to the admin portal', not Client(ADM).login(em['adv'], PW))
check('no audit entries for refused changes', int(one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key LIKE 'admin.cashback%'")) == audit0)
save_cb('2.5', '0')
msg, _ = admin.flash('/referrals.php')
check('decimal rate stored in basis points (2.5% = 250)', sval('cashback.rate_bps') == '250' and 'saved' in msg.lower(), msg)
check('audit: admin.cashback_rate_changed (target = new bps) + admin.settings_updated',
      one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key = 'admin.cashback_rate_changed' AND target_id = 250") == '1'
      and int(one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key = 'admin.settings_updated'")) >= 1)
s, l, h = admin.get('/referrals.php')
check('admin card shows the saved rate', 'value="2.5"' in h and '2.5% on' in h)

# ======================================================================
# 5. Top-up page offer
# ======================================================================
s, l, h = adv.get('/topup.php')
check('top-up page: "Get 2.5% cashback on deposits", no minimum line', 'Get 2.5% cashback on deposits' in h and 'Minimum top-up for cashback' not in h)

# ======================================================================
# 6. Calculation, rounding, notification, history
# ======================================================================
b0, acct0 = balance(advid), sysbal('system:deposit_cashback')
o1, s, b = deposit(adv, advid, '333.33')
check('₦333.33 deposit at 2.5%: ₦333.33 + 833 kobo (₦8.33, rounded down from ₦8.33325)', balance(advid) - b0 == 33333 + 833, balance(advid) - b0)
check('cashback row paid: base 33333, 250 bps, 833', cb_row(o1[2]) == [['paid', '33333', '250', '833', '', '0']], cb_row(o1[2]))
check('ledger: Dr system:deposit_cashback / Cr advertiser available, key cashback:<orderId>',
      sysbal('system:deposit_cashback') - acct0 == 833
      and db(f"SELECT t.type, e.direction, a.code LIKE 'wallet:%' FROM ledger_transactions t JOIN ledger_entries e ON e.transaction_id = t.id JOIN ledger_accounts a ON a.id = e.account_id WHERE t.idempotency_key = 'cashback:{o1[2]}' ORDER BY e.direction = 'credit'")
      == [['deposit_cashback', 'debit', '0'], ['deposit_cashback', 'credit', '1']])
check('ledger description "Deposit cashback 2.5% on top-up <order_sn>"', one(f"SELECT description FROM ledger_transactions WHERE idempotency_key = 'cashback:{o1[2]}'") == f'Deposit cashback 2.5% on top-up {o1[0]}')
n = [x for x in notes(advid) if x[1].startswith('Deposit cashback')]
check('in-app notification: "Deposit cashback: ₦8.33" linking to the wallet', len(n) == 1 and n[0][0] == 'rewards' and '₦8.33' in n[0][1] and o1[0] in n[0][2] and n[0][3] == 'wallet.php', n)
s, l, h = adv.get('/wallet.php')
check('wallet history shows "Deposit cashback 2.5% on top-up <order_sn>" +₦8.33', f'Deposit cashback 2.5% on top-up {o1[0]}' in h and '+₦8.33' in h)
eid = one(f"SELECT e.id FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id JOIN ledger_accounts a ON a.id = e.account_id WHERE t.idempotency_key = 'cashback:{o1[2]}' AND a.kind = 'wallet_available'")
s, l, h = adv.get(f'/receipt.php?id={eid}')
check('receipt shows the cashback line and the top-up order', s == 200 and f'Deposit cashback 2.5% on top-up {o1[0]}' in h and o1[0] in h)
s, l, h = adv2.get(f'/receipt.php?id={eid}')
check("another advertiser cannot open the cashback receipt", s == 404)

# ======================================================================
# 7. Idempotency
# ======================================================================
b1 = balance(advid)
s, l, b = collection_cb(o1[0], o1[1], '333.33')
check('redelivered callback: SUCCESS, no second credit and no second cashback', s == 200 and b == 'SUCCESS' and balance(advid) == b1
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'cashback:{o1[2]}'") == '1' and one(f"SELECT COUNT(*) FROM deposit_cashbacks WHERE payment_order_id = {o1[2]}") == '1')
oc = topup(adv, advid, 400)
b2 = balance(advid)
ths = [threading.Thread(target=lambda: collection_cb(oc[0], oc[1], '400.00')) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
time.sleep(0.5)
check('three simultaneous callbacks: one deposit (₦400) and one cashback (₦10)', balance(advid) - b2 == 40000 + 1000
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'cashback:{oc[2]}'") == '1'
      and len([x for x in notes(advid) if x[1].startswith('Deposit cashback') and '₦10.00' in x[1]]) == 1, balance(advid) - b2)
r = cron('hourly.php')
check('hourly catch-up after the fact pays nothing twice', balance(advid) - b2 == 41000 and 'cashback: catch-up paid 0' in r.stdout, r.stdout[-300:])

# ======================================================================
# 8. Minimum deposit
# ======================================================================
save_cb('5', '1,000')
check('5% with a ₦1,000 minimum stored (500 bps, 100000 kobo)', sval('cashback.rate_bps') == '500' and sval('cashback.min_deposit_kobo') == '100000')
check('audit: admin.cashback_min_deposit_changed', one(f"SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {admid} AND event_key = 'admin.cashback_min_deposit_changed' AND target_id = 100000") == '1')
s, l, h = adv.get('/topup.php')
check('top-up page shows "Get 5% cashback on deposits" and "Minimum top-up for cashback: ₦1,000.00"', 'Get 5% cashback on deposits' in h and 'Minimum top-up for cashback: ₦1,000.00' in h)
b3 = balance(advid)
ob, s, b = deposit(adv, advid, '999.99')
check('below the minimum (₦999.99): deposit credited, no cashback, row skipped below_minimum', balance(advid) - b3 == 99999
      and cb_row(ob[2]) == [['skipped', '99999', '500', '0', 'below_minimum', '1']], (balance(advid) - b3, cb_row(ob[2])))
om, s, b = deposit(adv, advid, 1000)
check('exactly the minimum (₦1,000): 5% cashback ₦50', balance(advid) - b3 == 99999 + 100000 + 5000 and cb_row(om[2])[0][0] == 'paid', balance(advid) - b3)
save_cb('5', '0')
check('minimum back to 0 (none)', sval('cashback.min_deposit_kobo') == '0')

# ======================================================================
# 9. Admin review-credit path (real deposit, credited amount)
# ======================================================================
b4 = balance(advid)
orv = topup(adv, advid, 1000)
collection_cb(orv[0], orv[1], '1000.00', paid='800.00')
check('mismatched payment held for review: no credit, no cashback yet', one(f"SELECT status FROM payment_orders WHERE id = {orv[2]}") == 'review' and balance(advid) == b4 and cb_row(orv[2]) == [])
rvid = int(one(f"SELECT id FROM payment_reviews WHERE payment_order_id = {orv[2]}"))
s, l, h = admin.get('/payments.php')
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': rvid, 'action': 'credit', 'note': 'Customer paid 800; credit what was paid'})
check('admin review-credit: ₦800 credited + 5% cashback on ₦800 (₦40)', balance(advid) - b4 == 80000 + 4000 and cb_row(orv[2]) == [['paid', '80000', '500', '4000', '', '0']], (balance(advid) - b4, cb_row(orv[2])))

# ======================================================================
# 10. Admin credits and bonuses never earn cashback
# ======================================================================
b5, acct5, rows5 = balance(advid), sysbal('system:deposit_cashback'), int(one('SELECT COUNT(*) FROM deposit_cashbacks'))
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': advid, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': '5000', 'reason': 'Goodwill credit'})
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': advid, 'idem': uuid.uuid4().hex, 'direction': 'bonus', 'amount': '300', 'reason': 'Launch week bonus'})
cron('hourly.php')
check('admin wallet credit (₦5,000) and admin bonus (₦300) credited without cashback', balance(advid) - b5 == 530000
      and sysbal('system:deposit_cashback') == acct5 and int(one('SELECT COUNT(*) FROM deposit_cashbacks')) == rows5, balance(advid) - b5)

# ======================================================================
# 11. Referral commission on the deposit alongside cashback; none on cashback
# ======================================================================
rb, ab, comm0 = balance(refid), balance(adv2id), sysbal('system:referral_commission')
orf, s, b = deposit(adv2, adv2id, 2000)
check('referred advertiser: deposit ₦2,000 + own 5% cashback ₦100', balance(adv2id) - ab == 200000 + 10000, balance(adv2id) - ab)
check('referrer still earns 5% commission (₦100) on the deposit', balance(refid) - rb == 10000 and sysbal('system:referral_commission') - comm0 == 10000, balance(refid) - rb)
check('commission based on the deposit only (base ₦2,000, not deposit + cashback)', db(f"SELECT base_kobo, amount_kobo FROM referral_payouts WHERE payment_order_id = {orf[2]}") == [['200000', '10000']])
check('cashback is not a payment order and earns no commission or cashback', one("SELECT COUNT(*) FROM referral_payouts p LEFT JOIN payment_orders o ON o.id = p.payment_order_id WHERE p.kind = 'commission' AND o.id IS NULL") == '0'
      and one(f"SELECT COUNT(*) FROM payment_orders WHERE user_id = {adv2id}") == one(f"SELECT COUNT(*) FROM deposit_cashbacks WHERE user_id = {adv2id}")
      and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {adv2id}") == '1')
r = cron('hourly.php')
check('after the catch-ups: still one commission and one cashback for that deposit', balance(refid) - rb == 10000 and balance(adv2id) - ab == 210000
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key IN ('cashback:{orf[2]}', 'ref-commission:{orf[2]}')") == '2')
check("referrer is not paid cashback on someone else's deposit", one(f"SELECT COUNT(*) FROM deposit_cashbacks WHERE user_id = {refid}") == '0')

# ======================================================================
# 12. Failure never breaks the deposit; catch-up pays later
# ======================================================================
b6 = balance(advid)
db("UPDATE ledger_accounts SET code = 'system:deposit_cashback_broken' WHERE code = 'system:deposit_cashback'")
try:
    of, s, b = deposit(adv, advid, 600)
finally:
    db("UPDATE ledger_accounts SET code = 'system:deposit_cashback' WHERE code = 'system:deposit_cashback_broken'")
check('cashback failure: deposit still confirmed (SUCCESS, paid, ₦600 credited), no cashback', s == 200 and b == 'SUCCESS'
      and one(f"SELECT status FROM payment_orders WHERE id = {of[2]}") == 'paid' and balance(advid) - b6 == 60000 and cb_row(of[2]) == [], (s, b, balance(advid) - b6))
logtxt = ''
for f in os.listdir('/tmp/ytrt/logs') if os.path.isdir('/tmp/ytrt/logs') else []:
    if f.startswith('php-'):
        logtxt += open('/tmp/ytrt/logs/' + f, errors='replace').read()
applog = ''
for root_, _, files in os.walk('/tmp/ytrt'):
    for f in files:
        if f.endswith('.log'):
            try:
                applog += open(os.path.join(root_, f), errors='replace').read()[-200000:]
            except OSError:
                pass
check('failure logged as cashback.failed', 'cashback.failed' in applog)
r = cron('hourly.php')
check('hourly catch-up pays the missed cashback (₦30)', balance(advid) - b6 == 63000 and cb_row(of[2])[0][0] == 'paid' and 'cashback: catch-up paid 1' in r.stdout, (r.stdout[-300:], balance(advid) - b6))

# old deposits are never paid retroactively
db(f"UPDATE payment_orders SET paid_at = paid_at - INTERVAL 3 DAY WHERE id = {o0[2]}")
db(f"DELETE FROM deposit_cashbacks WHERE payment_order_id = {o0[2]}")
b7 = balance(advid)
cron('hourly.php')
check('switching cashback on does not pay deposits older than 48 h (catch-up window)', balance(advid) == b7 and cb_row(o0[2]) == [])
db(f"INSERT INTO deposit_cashbacks (payment_order_id, user_id, base_kobo, rate_bps, amount_kobo, status, skip_reason) VALUES ({o0[2]}, {advid}, 100000, 0, 0, 'skipped', 'off')")

# inactive account at confirmation -> skipped
o3 = topup(adv3, adv3id, 500)
db(f"UPDATE users SET status = 'suspended' WHERE id = {adv3id}")
collection_cb(o3[0], o3[1], '500.00')
check('account not active at confirmation: deposit credited, cashback skipped', balance(adv3id) == 50000 and cb_row(o3[2]) == [['skipped', '50000', '500', '0', 'account_not_active', '1']], (balance(adv3id), cb_row(o3[2])))
db(f"UPDATE users SET status = 'active' WHERE id = {adv3id}")

# ======================================================================
# 13. Reversal (same rules as a referral payout reversal)
# ======================================================================
cid = int(one(f"SELECT id FROM deposit_cashbacks WHERE payment_order_id = {om[2]}"))
s, l, h = admin.get('/referrals.php')
check('admin card lists paid cashback with a reverse form', f'value="{cid}"' in h and 'cashback_reverse' in h and om[0] in h)
admin.post('/referrals.php', {'_csrf': tok, 'action': 'cashback_reverse', 'id': cid, 'reason': 'no'})
check('reversal needs a reason of 5+ characters', one(f"SELECT status FROM deposit_cashbacks WHERE id = {cid}") == 'paid')
admin.post('/referrals.php', {'_csrf': 'bad', 'action': 'cashback_reverse', 'id': cid, 'reason': 'Chargeback on the deposit'})
check('reversal without CSRF refused', one(f"SELECT status FROM deposit_cashbacks WHERE id = {cid}") == 'paid')
b8, acct8 = balance(advid), sysbal('system:deposit_cashback')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'cashback_reverse', 'id': cid, 'reason': 'Chargeback on the deposit'})
check('admin reversal: ₦50 taken back, account credited, row reversed, audited', balance(advid) == b8 - 5000 and sysbal('system:deposit_cashback') == acct8 - 5000
      and one(f"SELECT status FROM deposit_cashbacks WHERE id = {cid}") == 'reversed'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.cashback_reversed' AND target_id = {cid} AND actor_user_id = {admid}") == '1')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'cashback_reverse', 'id': cid, 'reason': 'Chargeback on the deposit'})
check('reversing twice does nothing', balance(advid) == b8 - 5000 and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'cashback-reverse:{cid}'") == '1')
s, l, h = adv.get('/wallet.php')
check('advertiser sees "Deposit cashback reversed" in history and inbox', 'Deposit cashback reversed' in h and any(x[1] == 'Deposit cashback reversed' for x in notes(advid)))

# ======================================================================
# 14. Reconciliation
# ======================================================================
ok, out = reconcile_ok()
check('reconciliation OK (ledger + cashback rows)', ok, out[-400:])
vf = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', 'yt'], stdin=open(ROOT + '/database/verify/deposit_cashback_reconciliation.sql'), capture_output=True, text=True)
check('database/verify/deposit_cashback_reconciliation.sql returns no problems', vf.returncode == 0 and vf.stdout.strip() == '', vf.stdout[:300] + vf.stderr[:200])
pid = int(one(f"SELECT id FROM deposit_cashbacks WHERE payment_order_id = {o1[2]}"))
db(f"UPDATE deposit_cashbacks SET amount_kobo = amount_kobo + 1 WHERE id = {pid}")
ok, out = reconcile_ok()
check('tampered cashback row detected by reconciliation (cron exit 2)', not ok and 'cashback' in out.lower(), out[-300:])
db(f"UPDATE deposit_cashbacks SET amount_kobo = amount_kobo - 1 WHERE id = {pid}")

# ======================================================================
# 15. Switched off again: offer hidden, nothing paid
# ======================================================================
save_cb('0', '0')
s, l, h = adv.get('/topup.php')
check('0% again: offer hidden on the top-up page', 'cashback-offer' not in h and 'cashback on deposits' not in h.lower(), re.findall(r'.{60}cashback.{60}', h, re.I | re.S))
b9 = balance(advid)
oz, s, b = deposit(adv, advid, 200)
check('0% again: deposit credited, cashback skipped', balance(advid) - b9 == 20000 and cb_row(oz[2])[0][0] == 'skipped')
s, l, h = admin.get('/referrals.php')
check('admin card shows cashback Off', re.search(r'cashback-title">Advertiser deposit cashback</h2><span class="badge badge-warning">Off', h) is not None)

ok, out = reconcile_ok()
check('final reconciliation OK', ok, out[-400:])

# restore defaults for other suites / re-runs
setting('cashback.rate_bps', '0')
setting('cashback.min_deposit_kobo', '0')
setting('referrals.commission_bps', '500')

passed = sum(1 for _, ok_ in results if ok_)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
