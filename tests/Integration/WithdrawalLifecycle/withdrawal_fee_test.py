#!/usr/bin/env python3
"""YOLOTASK V2 integration test: earner withdrawal fee (migration 0014).

Covers the migration (columns, defaults, backfill, accounts, settings), the
fee math (floor to the kobo, in the earner's favour; 50 % cap), the
server-computed preview before confirming (gross / fee / net, nothing
created), per-level rates (admin override lower than base, refused above it,
capped at runtime when the base drops below it, levels off = base), the rate
and fee locked on the withdrawal at request time (later admin changes do not
affect queued withdrawals; a change between preview and confirm is refused
and re-previewed; a tampered quote is refused), pre-migration withdrawals
staying free, the full gross refund on reject and on a failed payout (fee
never booked), the paid posting (Dr held gross / Cr clearing net / Cr
system:withdrawal_fees fee), the minimum withdrawal on the gross amount,
CSRF and authorization on the earner and admin forms, admin settings
validation and audit, display (wallet, admin list, payment settings) and
reconciliation (ledger + tamper detection +
database/verify/withdrawal_fee_checkin_reconciliation.sql). Settings and
levels are restored at the end (fee 10 %, no level overrides).

Needs the Phase 3 environment (servers from
tests/Integration/PaymentCallbacks/run-servers.sh incl. the fake AfrStar on
8499) and migration 0014. Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg),
YT_DB_CLI.
"""
import hashlib, json, os, re, shlex, subprocess, sys, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
M, E, A, ADM, FAKE = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405', 'http://127.0.0.1:8499'
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


def make_user(role, email, pw, name, verified=True):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name}', '{email}', '{h}', '{role}', 'active', {'UTC_TIMESTAMP()' if verified else 'NULL'})")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(u):
    r = db(f"SELECT available_kobo, held_kobo FROM wallets WHERE user_id = {u}")
    return (int(r[0][0]), int(r[0][1])) if r else (0, 0)


def sysbal(code):
    return int(one(f"SELECT balance_kobo FROM ledger_accounts WHERE code = '{code}'"))


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


def sval(key):
    return one(f"SELECT setting_value FROM settings WHERE setting_key = '{key}'")


def cron(name):
    return subprocess.run(['php', ROOT + '/cron/' + name], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))


def reconcile_ok():
    r = cron('daily.php')
    return r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


def fake_requests():
    return json.loads(urllib.request.build_opener(urllib.request.ProxyHandler({})).open(FAKE + '/_requests', timeout=5).read())


hooks = Client(M)
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')


def mails_to(to):
    try:
        return [m for m in (json.loads(l) for l in open(MAIL) if l.strip()) if m.get('to') == to]
    except FileNotFoundError:
        return []


def payout_cb(order_sn, osn, amount, status=1):
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'utr': 'TRF' + osn, 'name': 'Fola Fee',
               'message': 'Transfered Success' if status == 1 else 'Transfer failed', 'amount': amount, 'time_transfered': '2026-11-01 10:35:00',
               'sign': md5(AK, osn, status, amount)}
    out = hooks.req('/hooks/afrstar/payout.php', raw=json.dumps(payload).encode(), ctype='application/json')
    time.sleep(0.3)
    return out


def preview(client, naira):
    s, l, h = client.get('/withdraw.php')
    s, l, h = client.post('/withdraw.php', {'_csrf': client.csrf(h), 'idem': client.field(h, 'idem'), 'action': 'preview', 'amount': str(naira)})
    return s, l, h


def confirm(client, h, quoted=None):
    return client.post('/withdraw.php', {'_csrf': client.csrf(h), 'idem': client.field(h, 'idem'), 'action': 'confirm', 'amount': client.field(h, 'amount'),
                                         'quoted_fee': client.field(h, 'quoted_fee') if quoted is None else quoted})


def preview_numbers(h):
    def grab(attr):
        m = re.search(attr + r'>(?:− )?([^<]+)<', h)
        return m.group(1).strip() if m else None
    return grab('data-preview-amount'), grab('data-preview-fee'), grab('data-preview-net')


def last_wd(u):
    r = db(f"SELECT id, amount_kobo, fee_kobo, fee_bps, status FROM withdrawals WHERE user_id = {u} ORDER BY id DESC LIMIT 1")
    return r[0] if r else None


def wd_count(u):
    return int(one(f"SELECT COUNT(*) FROM withdrawals WHERE user_id = {u}"))


def admin_reject(wid, reason='Test: declined'):
    s, l, h = admin.get('/withdrawals.php')
    admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'withdrawal_id': wid, 'reason': reason})


def admin_approve(wid):
    s, l, h = admin.get('/withdrawals.php')
    admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': wid})
    return db(f"SELECT status, order_sn, platform_osn FROM withdrawals WHERE id = {wid}")[0]


MONEY_FORM = {'money_topup_min_kobo': '100', 'money_topup_max_kobo': '500000', 'money_withdraw_min_kobo': '1000', 'money_withdraw_max_kobo': '500000',
              'money_payin_expiry_hours': '24', 'money_withdraw_daily_max_kobo': '500000', 'money_withdraw_daily_count': '50',
              'money_bank_change_cooldown_hours': '24', 'money_payout_stuck_hours': '6'}


def admin_fee(percent, csrf=True):
    s, l, h = admin.get('/payment-settings.php')
    data = dict(MONEY_FORM, money_withdraw_fee_percent=percent)
    if csrf:
        data['_csrf'] = admin.csrf(h)
    admin.post('/payment-settings.php', data)
    return admin.flash('/payment-settings.php')


def level_form(client, level, **over):
    data = {'action': 'save', 'id': level[0], 'name': level[1], 'min_approved': level[2], 'min_rate': level[3], 'max_active': level[4], 'premium': '1', 'active': '1'}
    data.update(over)
    s, l, h = client.get('/levels.php')
    data['_csrf'] = client.csrf(h)
    client.post('/levels.php', data)
    return client.flash('/levels.php')


# ---------- AfrStar config pointing at the fake gateway (same as phase3/phase6/cashback)
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')

tag = str(int(time.time()))
PW = 'TestPass!2026abc'
setting('money.withdraw_fee_bps', '1000')
setting('levels.enabled', '1')
setting('money.withdraw_daily_count', '50')
db("UPDATE earner_levels SET withdrawal_fee_bps = NULL")
starter = db("SELECT id, name, min_approved, min_approval_rate, max_active_reservations FROM earner_levels WHERE min_approved = 0 AND min_approval_rate = 0 ORDER BY id LIMIT 1")[0]

# ======================================================================
# 1. Migration and defaults
# ======================================================================
check('migration 0014 recorded once', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0014_withdrawal_fee_daily_checkin.sql'") == '1'
      and len([f for f in os.listdir(ROOT + '/database/migrations') if f.startswith('0014_')]) == 1)
cols = {r[0]: r[1:] for r in db("SELECT column_name, column_default, is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'withdrawals' AND column_name IN ('fee_kobo', 'fee_bps', 'fee_level_id')")}
check('withdrawals.fee_bps added (default 0, NOT NULL) next to fee_kobo (default 0)', cols.get('fee_bps') == ['0', 'NO'] and cols.get('fee_kobo') == ['0', 'NO'] and 'fee_level_id' in cols, cols)
check('pre-0014 withdrawals are free (no row with a fee and no rate)', one("SELECT COUNT(*) FROM withdrawals WHERE fee_kobo > 0 AND fee_bps = 0") == '0')
check('migration backfills existing withdrawals to fee 0 / 0 bps', 'UPDATE withdrawals SET fee_kobo = 0, fee_bps = 0' in open(ROOT + '/database/migrations/0014_withdrawal_fee_daily_checkin.sql').read())
lcols = {r[0]: r[1:] for r in db("SELECT column_name, column_default, is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'earner_levels' AND column_name IN ('withdrawal_fee_bps', 'checkin_reward_kobo')")}
check('earner_levels.withdrawal_fee_bps NULL = base, checkin_reward_kobo default 0', lcols.get('withdrawal_fee_bps', [''])[1] == 'YES' and lcols.get('checkin_reward_kobo') == ['0', 'NO'], lcols)
check('system:withdrawal_fees exists (system, credit-normal revenue, no negative)', one("SELECT COUNT(*) FROM ledger_accounts WHERE code = 'system:withdrawal_fees' AND kind = 'system' AND normal_side = 'credit' AND allow_negative = 0") == '1')
check('migration seeds money.withdraw_fee_bps = 1000 (10 %)', "('money.withdraw_fee_bps', '1000')" in open(ROOT + '/database/migrations/0014_withdrawal_fee_daily_checkin.sql').read())
php_def = subprocess.run(['php', '-r', 'require $argv[1]; echo YoloTask\\Core\\Settings::DEFAULTS["money.withdraw_fee_bps"];', ROOT + '/shared/lib/Core/Settings.php'], capture_output=True, text=True).stdout
check('code default fee 10 % (1000 bps)', php_def == '1000', php_def)

# unit: rounding down, cap, percent parsing/formatting
unit = subprocess.run(['php', '-r', '''
require $argv[1];
use YoloTask\\Money\\Fees as F;
echo json_encode([F::withdrawal(123456, 1000), F::withdrawal(100001, 1000), F::withdrawal(199, 1000), F::withdrawal(100000, 0), F::withdrawal(100000, 9000),
  F::withdrawal(333, 333), F::withdrawal(100000, 250), F::withdrawal(0, 1000), F::percent(1000), F::percent(750), F::percent(25), F::parsePercent('7.5'),
  F::parsePercent('50'), F::parsePercent('50.01'), F::parsePercent('abc'), F::parsePercent('10%'), F::parsePercent('0')]);
''', ROOT + '/shared/autoload.php'], capture_output=True, text=True)
check('fee = floor(amount × rate): ₦1,234.56 @10% = ₦123.45; ₦1,000.01 @10% = ₦100.00; 199 kobo → 19; 0 % → 0; cap 50 %; percent parse/format',
      unit.returncode == 0 and json.loads(unit.stdout or 'null') == [12345, 10000, 19, 0, 50000, 11, 2500, 0, '10%', '7.5%', '0.25%', 750, 5000, None, None, 1000, 0], unit.stdout + unit.stderr)

# ======================================================================
# 2. Users, balance, bank account
# ======================================================================
em = {k: f'wf{k}{tag}@gmail.com' for k in ['e1', 'e2', 'adv', 'admin']}
e1 = make_user('earner', em['e1'], PW, 'Fola Fee')
e2 = make_user('earner', em['e2'], PW, 'Gbenga Legacy')
advid = make_user('advertiser', em['adv'], PW, 'Ade Advertiser')
admid = make_user('admin', em['admin'], PW, 'Wale Admin')
earner, earner2, adv, admin = Client(E), Client(E), Client(A), Client(ADM)
check('earners, advertiser and admin sign in', earner.login(em['e1'], PW) and earner2.login(em['e2'], PW) and adv.login(em['adv'], PW) and admin.login(em['admin'], PW))
for uid in (e1, e2):
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': uid, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': '50000', 'reason': 'Test funds for fees'})
for c, name in ((earner, 'Fola Fee'), (earner2, 'Gbenga Legacy')):
    s, l, h = c.get('/bank-account.php')
    c.post('/bank-account.php', {'_csrf': c.csrf(h), 'bank_code': '80000009', 'account_number': '0123456789', 'account_name': name, 'password': PW})
check('earners funded (₦50,000) with a bank account', balance(e1) == (5000000, 0) and balance(e2) == (5000000, 0)
      and one(f"SELECT COUNT(*) FROM bank_accounts WHERE user_id IN ({e1}, {e2})") == '2')
fees0 = sysbal('system:withdrawal_fees')

# ======================================================================
# 3. Preview before submitting (server-computed), base rate
# ======================================================================
s, l, h = earner.get('/withdraw.php')
check('withdraw form states the fee before anything is submitted', s == 200 and 'data-fee-rate="1000"' in h and '10% withdrawal fee' in h and 'No fees' not in h, re.findall(r'.{80}withdrawal fee.{40}', h))
n0 = wd_count(e1)
s, l, h = preview(earner, '1234.56')
check('preview shows gross, fee (10%, rounded down) and net: ₦1,234.56 / ₦123.45 / ₦1,111.11', s == 200 and preview_numbers(h) == ('₦1,234.56', '₦123.45', '₦1,111.11') and 'Fee (10%' in h, preview_numbers(h))
check('preview creates nothing and holds nothing', wd_count(e1) == n0 and balance(e1) == (5000000, 0))
check('preview carries the quoted fee for the confirm step', earner.field(h, 'quoted_fee') == '12345' and earner.field(h, 'action') == 'confirm')
s, l, _ = confirm(earner, h)
w = last_wd(e1)
check('confirm creates the withdrawal with the previewed fee (gross held, rate + fee locked on the row)', s == 303 and l == 'wallet.php' and w[1:] == ['123456', '12345', '1000', 'pending_review'] and balance(e1) == (5000000 - 123456, 123456), (w, balance(e1)))
s, l, h = earner.get('/wallet.php')
check('wallet shows the fee and what the earner receives', 'fee ₦123.45 (10%)' in h and 'you receive ₦1,111.11' in h)
s, l, h = earner.get('/withdraw.php')
check('open-withdrawal notice shows fee and net', 'data-open-fee' in h and '₦1,111.11' in h)
s, l, h = admin.get('/withdrawals.php')
check('admin list shows fee and payout amount', f'data-wd-fee="12345"' in h and 'pays ₦1,111.11' in h)

# ======================================================================
# 4. Full refund on reject (fee included, never booked)
# ======================================================================
wid_r = int(w[0])
admin_reject(wid_r, 'Please confirm your bank details')
check('reject returns the FULL gross amount (₦1,234.56 incl. fee) to the wallet', balance(e1) == (5000000, 0) and last_wd(e1)[4] == 'rejected', balance(e1))
check('reject posts the gross (wd-release) and books no fee', one(f"SELECT amount_kobo FROM ledger_transactions WHERE idempotency_key = 'wd-release:{wid_r}'") == '123456' and sysbal('system:withdrawal_fees') == fees0)
check('decline email says the fee came back too', any('including the ₦123.45 fee' in json.dumps(m, ensure_ascii=False) for m in mails_to(em['e1'])), [m.get('subject') for m in mails_to(em['e1'])])

# ======================================================================
# 5. Minimum withdrawal applies to the gross amount
# ======================================================================
s, l, h = preview(earner, '999.99')
f, _ = earner.flash('/withdraw.php') if s == 303 else ('', '')
check('₦999.99 refused at preview: minimum ₦1,000.00 (gross)', s == 303 and 'minimum withdrawal is ₦1,000.00' in f and wd_count(e1) == n0 + 1, (s, f))
s, l, h = preview(earner, '1000')
check('exactly ₦1,000 gross allowed even though the net is ₦900', s == 200 and preview_numbers(h) == ('₦1,000.00', '₦100.00', '₦900.00'), preview_numbers(h))
confirm(earner, h)
w = last_wd(e1)
check('₦1,000 request stored with fee ₦100', w[1:] == ['100000', '10000', '1000', 'pending_review'], w)
admin_reject(int(w[0]))

# ======================================================================
# 6. CSRF and tampering
# ======================================================================
n = wd_count(e1)
s, l, h = earner.get('/withdraw.php')
s, l, h2 = earner.post('/withdraw.php', {'_csrf': 'bad', 'idem': earner.field(h, 'idem'), 'action': 'preview', 'amount': '2000'})
check('preview without a valid CSRF token shows nothing', s == 303 and 'data-withdraw-preview' not in h2)
s, l, h = preview(earner, '2000')
earner.post('/withdraw.php', {'_csrf': 'bad', 'idem': earner.field(h, 'idem'), 'action': 'confirm', 'amount': '2000.00', 'quoted_fee': '20000'})
check('confirm without a valid CSRF token creates nothing', wd_count(e1) == n and balance(e1) == (5000000, 0))
s, l, h3 = confirm(earner, h, quoted='100')
check('confirm with a tampered (lower) fee is refused and re-previewed with the real fee', s == 200 and 'data-fee-changed' in h3 and wd_count(e1) == n
      and preview_numbers(h3) == ('₦2,000.00', '₦200.00', '₦1,800.00'), preview_numbers(h3))
s, l, h4 = confirm(earner, h, quoted='abc')
check('confirm without a numeric quote only previews (nothing created)', s == 200 and 'data-withdraw-preview' in h4 and wd_count(e1) == n)
s, l, _ = earner.post('/withdraw.php', {'_csrf': earner.csrf(h), 'idem': earner.field(h, 'idem'), 'amount': '2000'})
check('a bare POST (old single-step form) only previews; nothing is created without the confirm step', wd_count(e1) == n)

# ======================================================================
# 7. Admin fee setting: validation, CSRF, audit, authorization
# ======================================================================
s, l, h = admin.get('/payment-settings.php')
check('Money settings shows the withdrawal fee next to the minimum withdrawal', s == 200 and re.search(r'name="money_withdraw_fee_percent"[^>]*value="10"', h)
      and 'Minimum withdrawal' in h and 'data-fee-badge' in h)
for bad in ('60', '50.5', 'abc', '-1', '10.123'):
    f, _ = admin_fee(bad)
    check(f'admin fee "{bad}" refused', 'withdrawal fee must be a percentage from 0 to 50' in f and sval('money.withdraw_fee_bps') == '1000', f)
admin_fee('25', csrf=False)
check('admin fee change without CSRF refused', sval('money.withdraw_fee_bps') == '1000')
s, l, h = earner.get(ADM + '/payment-settings.php')
check('earner session cannot open admin Money settings', s in (303, 403, 404) and 'money_withdraw_fee_percent' not in h)
s, l, h = adv.get(E + '/withdraw.php')
check('advertiser session cannot use the earner withdraw page', s in (303, 403, 404) and 'data-withdraw-preview' not in h)
s, l, h = adv.get('/withdraw.php')
check('advertiser portal has no withdraw page', s in (403, 404) or ('Withdraw to your bank' not in h and 'name="quoted_fee"' not in h))

# ======================================================================
# 8. Per-level rate (lower than base, refused above, levels off = base)
# ======================================================================
f, _ = level_form(admin, starter, withdrawal_fee='12')
check('level fee above the base (12% > 10%) refused', 'between 0% and the base fee (10%)' in f and one(f"SELECT withdrawal_fee_bps FROM earner_levels WHERE id = {starter[0]}") == 'NULL', f)
f, _ = level_form(earner, starter, withdrawal_fee='5')
check('earner cannot save levels', one(f"SELECT withdrawal_fee_bps FROM earner_levels WHERE id = {starter[0]}") == 'NULL')
f, _ = level_form(admin, starter, withdrawal_fee='5')
check('admin sets Starter withdrawal fee 5% (audited)', 'Level updated' in f and one(f"SELECT withdrawal_fee_bps FROM earner_levels WHERE id = {starter[0]}") == '500'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.level_withdrawal_fee_changed' AND target_id = {starter[0]} AND actor_user_id = {admid}") == '1', f)
s, l, h = earner.get('/withdraw.php')
check('form shows the level rate and the standard rate', 'data-fee-rate="500"' in h and 'level rate; standard 10%' in h, re.findall(r'data-fee-rate[^<]+<', h))
s, l, h = preview(earner, '1234.56')
check('preview at the level rate: ₦1,234.56 @5% → fee ₦61.72 (61.728 rounded down), net ₦1,172.84', preview_numbers(h) == ('₦1,234.56', '₦61.72', '₦1,172.84') and 'level)' in h, preview_numbers(h))
db("INSERT INTO earner_levels (name, min_approved, min_approval_rate, max_active_reservations, premium_access, is_active, withdrawal_fee_bps) VALUES ('FeeTest', 0, 0, 3, 1, 1, 250)")
feetest = one("SELECT id FROM earner_levels WHERE name = 'FeeTest'")
s, l, h = preview(earner, '1234.56')
check('a different level gets its own rate (FeeTest 2.5%: fee ₦30.86)', preview_numbers(h) == ('₦1,234.56', '₦30.86', '₦1,203.70'), preview_numbers(h))
confirm(earner, h)
w = last_wd(e1)
check('request stores the level rate, fee and level id', w[1:] == ['123456', '3086', '250', 'pending_review'] and one(f"SELECT fee_level_id FROM withdrawals WHERE id = {w[0]}") == feetest, w)
admin_reject(int(w[0]))
setting('levels.enabled', '0')
s, l, h = preview(earner, '1234.56')
check('levels switched off → base rate 10%', preview_numbers(h)[1] == '₦123.45', preview_numbers(h))
setting('levels.enabled', '1')
db(f"DELETE FROM earner_levels WHERE id = {feetest}")
admin_fee('4')
s, l, h = preview(earner, '1000')
check('a level rate above a lowered base is capped at the base (Starter 5% → 4%)', preview_numbers(h)[1] == '₦40.00', preview_numbers(h))
s, l, h = admin.get('/levels.php')
check('admin levels page shows the effective fee and the cap note', 'Above the current base, so 4% applies.' in h and f'data-level-fee="400"' in h)
db(f"UPDATE earner_levels SET withdrawal_fee_bps = NULL WHERE id = {starter[0]}")

# ======================================================================
# 9. Admin change between preview and confirm; rate locked at request
# ======================================================================
f, _ = admin_fee('20')
check('admin sets the base fee to 20% (audited with the rate)', 'Money settings saved' in f and sval('money.withdraw_fee_bps') == '2000'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.withdrawal_fee_changed' AND target_id = 2000 AND actor_user_id = {admid}") == '1', f)
s, l, h = preview(earner, '5000')
check('preview at 20%: fee ₦1,000, net ₦4,000', preview_numbers(h) == ('₦5,000.00', '₦1,000.00', '₦4,000.00'), preview_numbers(h))
admin_fee('15')
n = wd_count(e1)
s, l, h2 = confirm(earner, h)
check('confirm after an admin change is refused (nothing created) and shows the new fee', s == 200 and wd_count(e1) == n and 'data-fee-changed' in h2
      and 'changed to 15%' in h2 and preview_numbers(h2) == ('₦5,000.00', '₦750.00', '₦4,250.00'), preview_numbers(h2))
confirm(earner, h2)
w = last_wd(e1)
check('confirming the new preview creates it at 15%', w[1:] == ['500000', '75000', '1500', 'pending_review'], w)
wid_q = int(w[0])
admin_fee('30')
admin_fee('0')
check('later admin changes do not touch the queued withdrawal', last_wd(e1)[1:] == ['500000', '75000', '1500', 'pending_review'])
s, l, h = earner.get('/withdraw.php')
check('fee 0% shows "No withdrawal fee"', 'No withdrawal fee' in h and 'data-fee-rate="0"' in h)
admin_fee('10')
w2 = admin_approve(wid_q)
req = [r for r in fake_requests() if r['path'] == '/gateway/payout/' and r['body']['order_sn'] == w2[1]]
check('approve sends the net amount locked at request (₦5,000 − 15% = ₦4,250)', w2[0] == 'processing' and req and req[-1]['body']['amount'] == '4250.00' and req[-1]['sign_ok'], req[-1:] if req else w2)
s, _, b = payout_cb(w2[1], w2[2], '5000.00')
check('payout callback for the gross amount refused (amount mismatch)', s == 400 and last_wd(e1)[4] == 'processing')
s, _, b = payout_cb(w2[1], w2[2], '4250.00')
check('payout success: status paid, held cleared, gross gone from the wallet', s == 200 and b == 'SUCCESS' and last_wd(e1)[4] == 'paid' and balance(e1) == (4500000, 0), balance(e1))
legs = sorted(db(f"SELECT IF(a.kind = 'system', a.code, a.kind), e.direction, e.amount_kobo FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id JOIN ledger_transactions t ON t.id = e.transaction_id WHERE t.idempotency_key = 'wd-paid:{wid_q}'"))
check('paid posting: Dr held ₦5,000 / Cr clearing ₦4,250 / Cr system:withdrawal_fees ₦750', legs == sorted([['wallet_held', 'debit', '500000'], ['system:afrstar_clearing', 'credit', '425000'], ['system:withdrawal_fees', 'credit', '75000']]), legs)
check('fee revenue account grew by exactly the fee', sysbal('system:withdrawal_fees') == fees0 + 75000)
payout_cb(w2[1], w2[2], '4250.00')
check('replayed paid callback books the fee once', sysbal('system:withdrawal_fees') == fees0 + 75000)

# ======================================================================
# 10. Full refund on a failed payout (fee included, never booked)
# ======================================================================
s, l, h = preview(earner, '3000')
confirm(earner, h)
w = last_wd(e1)
check('₦3,000 request at 10% (fee ₦300)', w[1:] == ['300000', '30000', '1000', 'pending_review'], w)
before = balance(e1)
w3 = admin_approve(int(w[0]))
s, _, b = payout_cb(w3[1], w3[2], '2700.00', 9)
check('failed payout returns the FULL gross ₦3,000 (fee included)', s == 200 and last_wd(e1)[4] == 'failed' and balance(e1) == (before[0] + 300000, 0), (before, balance(e1)))
check('failed payout books no fee and refunds via wd-release (gross)', sysbal('system:withdrawal_fees') == fees0 + 75000
      and one(f"SELECT amount_kobo FROM ledger_transactions WHERE idempotency_key = 'wd-release:{w[0]}'") == '300000')
payout_cb(w3[1], w3[2], '2700.00', 9)
check('replayed failure refunds once', balance(e1) == (before[0] + 300000, 0))

# ======================================================================
# 11. Pre-migration withdrawals stay free
# ======================================================================
s, l, h = preview(earner2, '2000')
confirm(earner2, h)
wl = last_wd(e2)
db(f"UPDATE withdrawals SET fee_kobo = 0, fee_bps = 0, fee_level_id = NULL WHERE id = {wl[0]}")  # what a pre-0014 row looks like after the backfill
w4 = admin_approve(int(wl[0]))
req = [r for r in fake_requests() if r['path'] == '/gateway/payout/' and r['body']['order_sn'] == w4[1]]
check('legacy (fee 0) withdrawal pays the full amount', req and req[-1]['body']['amount'] == '2000.00', req[-1:])
fb = sysbal('system:withdrawal_fees')
s, _, b = payout_cb(w4[1], w4[2], '2000.00')
legs = sorted(db(f"SELECT IF(a.kind = 'system', a.code, a.kind), e.amount_kobo FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id JOIN ledger_transactions t ON t.id = e.transaction_id WHERE t.idempotency_key = 'wd-paid:{wl[0]}'"))
check('legacy withdrawal paid with no fee leg (held → clearing in full)', last_wd(e2)[4] == 'paid' and sysbal('system:withdrawal_fees') == fb and legs == sorted([['wallet_held', '200000'], ['system:afrstar_clearing', '200000']]), legs)
s, l, h = earner2.get('/wallet.php')
check('legacy withdrawal shows no fee in the wallet', 'fee ₦' not in h)

# ======================================================================
# 12. Reconciliation
# ======================================================================
ok, out = reconcile_ok()
check('reconciliation OK (fees = paid withdrawals, full refunds, postings)', ok, out[-400:])
vf = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)],
                    stdin=open(ROOT + '/database/verify/withdrawal_fee_checkin_reconciliation.sql'), capture_output=True, text=True)
check('database/verify/withdrawal_fee_checkin_reconciliation.sql returns no problems', vf.returncode == 0 and vf.stdout.strip() == '', vf.stdout[:300] + vf.stderr[:200])
db(f"UPDATE withdrawals SET fee_kobo = fee_kobo + 1 WHERE id = {wid_q}")
ok, out = reconcile_ok()
check('tampered fee detected by reconciliation (cron exit 2)', not ok and 'fee' in out.lower(), out[-300:])
db(f"UPDATE withdrawals SET fee_kobo = fee_kobo - 1 WHERE id = {wid_q}")
ok, out = reconcile_ok()
check('reconciliation OK again after restoring', ok, out[-300:])

# ---------- restore
setting('money.withdraw_fee_bps', '1000')
setting('money.withdraw_daily_count', '3')
setting('levels.enabled', '1')
db("UPDATE earner_levels SET withdrawal_fee_bps = NULL")

passed = sum(1 for _, ok in results if ok)
print(f"\n{passed}/{len(results)} passed")
sys.exit(0 if passed == len(results) else 1)
