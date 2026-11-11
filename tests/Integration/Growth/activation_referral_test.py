#!/usr/bin/env python3
"""YOLOTASK V2 integration test: tasks-page stats + referral bonus on activation (migration 0020).

Covers: migration 0020 (kind 'activation', one bonus per referral, settings
₦100 and the since-date); the activation card shows the tasks available and
the total they pay (equal to the feed once activated) and the invite line;
a referred earner who activates by AfrStar transfer pays the referrer ₦100
once (Dr system:referral_bonus / Cr referrer wallet, key
ref-activation:<referral>, inbox note), replays pay nothing; a flagged
referral pays only after an admin approves it; an admin-verified activation
pays; bonus 0 pays nothing and the catch-up pays once it is set again;
activations before referrals.activation_bonus_since never pay; a referral
with no activation never pays; admin setting (validation, Activations
filter), referrer page, payout reversal back to system:referral_bonus;
reconciliation and database/verify/referral_award_reconciliation.sql clean.

Needs the Phase 3 servers and migrations through 0020.
"""
import hashlib, io, json, os, re, shlex, subprocess, sys, threading, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
M, E, A, R, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8404', 'http://127.0.0.1:8405'
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

    def upload(self, p, fields, files):
        boundary = 'yt' + uuid.uuid4().hex
        out = io.BytesIO()
        for k, v in fields.items():
            for vv in (v if isinstance(v, list) else [v]):
                out.write(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{vv}\r\n'.encode())
        for k, (name, data, mime) in files.items():
            out.write(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode())
            out.write(data)
            out.write(b'\r\n')
        out.write(f'--{boundary}--\r\n'.encode())
        s, h, b = self.req(p, raw=out.getvalue(), ctype='multipart/form-data; boundary=' + boundary)
        return s, h.get('Location'), b

    @staticmethod
    def field(html, name):
        m = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)
        return m.group(1) if m else ''

    def csrf(self, html=None, path='/dashboard.php'):
        if html is None:
            html = self.get(path)[2]
        return self.field(html, '_csrf')

    def login(self, email, pw):
        s, l, h = self.get('/login.php')
        s, l, _ = self.post('/login.php', {'_csrf': self.field(h, '_csrf'), 'email': email, 'password': pw})
        return s == 303 and l == 'dashboard.php'

    def flash(self, path):
        s, l, h = self.get(path)
        m = re.search(r'class="toast-msg">([^<]*)<', h)
        return (m.group(1) if m else ''), h


DBNAME = re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)


def db(sql):
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    out = subprocess.run(cli + ['-N', '-B', DBNAME, '-e', sql], capture_output=True, text=True)
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
    r = db(f"SELECT available_kobo FROM wallets WHERE user_id = {u}")
    return int(r[0][0]) if r else 0


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


def verify_sql(name):
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    out = subprocess.run(cli + ['-N', '-B', DBNAME], stdin=open(ROOT + '/database/verify/' + name), capture_output=True, text=True)
    return out.returncode == 0 and out.stdout.strip() == '', out.stdout + out.stderr


def notes(u):
    return db(f"SELECT category, title, body, COALESCE(url, '') FROM notifications WHERE user_id = {u} ORDER BY id")


_img_n = [0]


def image():
    _img_n[0] += 1
    im = Image.new('RGB', (400, 700), ((_img_n[0] * 61) % 255, (_img_n[0] * 37) % 255, (_img_n[0] * 83) % 255))
    rnd = os.urandom(400)
    for x in range(0, 400, 5):
        im.putpixel((x, (x * _img_n[0] + 13) % 700), (rnd[x], 255, 255))
    b = io.BytesIO()
    im.save(b, 'PNG')
    return b.getvalue()


hooks = Client(M)


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


def collection_cb(order_sn, osn, order_amount, paid=None, status=1, sign=None):
    paid = paid or order_amount
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'message': 'Paid Success', 'amount': paid,
               'utr': 'UTR' + osn, 'session_id': 'S' + osn, 'amount_need': order_amount, 'amount_paid': paid, 'time_paid': '2026-11-08 10:30:00',
               'sign': sign or md5(AK, osn, status, order_amount)}
    out = hooks.req('/hooks/afrstar/collection.php', raw=json.dumps(payload).encode(), ctype='application/json')
    time.sleep(0.5)  # the activation notification runs after the response is sent
    return out[0], out[2]


def start_activation(client, phone='08031234567'):
    s, l, h = client.get('/activate.php')
    return client.post('/activate.php', {'_csrf': client.csrf(h), 'phone': phone})


def act_rows(u):
    return db(f"SELECT order_sn, COALESCE(platform_osn, ''), id, status, amount_kobo FROM activation_payments WHERE user_id = {u} ORDER BY id")


def feed_ids(client, path='/tasks.php'):
    """Task ids across every page of the earner feed, in order, plus the first page's HTML (all pages in feed_ids.html)."""
    out, first, page = [], None, 1
    feed_ids.html = ''
    while page < 60:
        s, l, h = client.get(path + (('&' if '?' in path else '?') + f'page={page}' if page > 1 else ''))
        first = first if first is not None else h
        feed_ids.html += h
        feed = h.split('data-feed', 1)[1].split('</section>', 1)[0] if 'data-feed' in h else ''
        out += [int(x) for x in re.findall(r'<a href="task\.php\?id=(\d+)"', feed)]
        if f'page={page + 1}' not in h:
            break
        page += 1
    return out, first


def task_row(h, cid):
    m = re.search(r'<li class="tx-row" data-platform-task="' + str(cid) + r'">.*?</li>', h, re.S)
    return m.group(0) if m else ''


def activated(u):
    return one(f"SELECT COUNT(*) FROM account_activations WHERE user_id = {u}") == '1'


def cli_import(path, dry=False):
    args = ['php', ROOT + '/scripts/import-platform-tasks.php', '--file=' + path, '--config-dir=' + CFG] + (['--dry-run'] if dry else [])
    r = subprocess.run(args, capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


# ---------- AfrStar config pointing at the fake gateway (same as phase3/phase6/cashback)
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')

tag = str(int(time.time()))
PW = 'TestPass!2026abc'
RESTORE = {k: sval(k) for k in ['activation.required', 'activation.fee_kobo', 'referrals.enabled', 'referrals.activation_bonus_kobo', 'referrals.activation_bonus_since']}

try:
    # 1. Migration
    check('migration 0020 recorded', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0020_referral_activation_bonus.sql'") == '1')
    check("referral_payouts.kind has 'activation'; one activation bonus per referral (unique key)",
          'activation' in (one("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'referral_payouts' AND column_name = 'kind'") or '')
          and one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'referral_payouts' AND index_name = 'uq_referral_payouts_activation'") == '1')
    check('settings seeded: bonus ₦100, since 2026-11-11 11:00:00 UTC', sval('referrals.activation_bonus_kobo') == '10000' and sval('referrals.activation_bonus_since') == '2026-11-11 11:00:00')

    # 2. People, tasks
    em = {k: f'ar{k}{tag}@gmail.com' for k in ['admin', 'ref', 'x', 'y', 'v', 'z', 'u', 'w']}
    admid = make_user('admin', em['admin'], PW, 'Grace Admin')
    ids = {k: make_user('earner', em[k], PW, f'Earner {k.upper()} Bello') for k in ['ref', 'x', 'y', 'v', 'z', 'u', 'w']}
    for k, st in [('x', 'active'), ('y', 'flagged'), ('v', 'active'), ('u', 'active'), ('w', 'active')]:
        db(f"INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status, created_at) VALUES ({ids['ref']}, {ids[k]}, 'earner', 'AR{tag[-6:]}', '{st}', UTC_TIMESTAMP() - INTERVAL 1 HOUR)")
    rid = {k: int(one(f"SELECT id FROM referrals WHERE referee_user_id = {ids[k]}")) for k in ['x', 'y', 'v', 'u', 'w']}
    csvp = f'/tmp/ar_tasks_{tag}.csv'
    with open(csvp, 'w') as f:
        f.write('bundle_key,platform,account_name,task_type,url,instructions\n')
        for i in range(3):
            f.write(f'ar{tag}a,instagram,aracct{tag},follow,https://instagram.com/arac{tag}x{i},Follow this account {i}\n')
        f.write(f'ar{tag}b,facebook,arpage{tag},like,https://facebook.com/arpg{tag}/posts/1,Like this post\n')
    rc, out = cli_import(csvp)
    check('4 YOLOTASK tasks imported for the test', rc == 0, out[-300:])
    setting('activation.required', '1'); setting('activation.fee_kobo', '50000'); setting('referrals.enabled', '1'); setting('referrals.activation_bonus_kobo', '10000')
    setting('referrals.activation_bonus_since', '2026-11-11 11:00:00')
    admin = Client(ADM)
    C = {k: Client(E) for k in ids}
    check('everyone signs in', admin.login(em['admin'], PW) and all(C[k].login(em[k], PW) for k in C))
    for k in ids:
        C[k].get('/wallet.php')

    # 3. Tasks page stat boxes (beside Earned/Waiting); the activation card stays plain
    s, l, h = C['x'].get('/tasks.php')
    m = re.search(r'Tasks available</span><span class="stat-value">([\d,]+)</span>.*?Amount to earn</span><span class="stat-value">₦([\d,]+\.\d\d)</span>', h, re.S)
    check('tasks page: "Tasks available" and "Amount to earn" stat boxes', m is not None and 'stat-label">Earned' in h and 'stat-label">Waiting' in h, h[h.find('money-stats'):][:900])
    n_card = int(m.group(1).replace(',', '')) if m else -1
    k_card = int(round(float(m.group(2).replace(',', '')) * 100)) if m else -1
    check('stat boxes count the imported tasks', n_card >= 4 and k_card > 0, (n_card, k_card))
    check('activation card has no stats or invite line', 'data-activation-card' in h and 'Activate your account with ₦500.00 to access tasks.' in h and 'Earn up to' not in h and 'each one who activates' not in h)
    s, l, h = C['ref'].get('/referrals.php')
    check('referrals page: "You earn ₦100.00 as soon as each one activates"', 'You earn <strong>₦100.00</strong> as soon as each one activates' in h, h[:300])
    setting('referrals.activation_bonus_kobo', '0')
    s, l, h = C['ref'].get('/referrals.php')
    check('bonus 0: no activation line on referrals page', 'as soon as each one activates' not in h)
    setting('referrals.activation_bonus_kobo', '10000')

    def activate(k, paid=None):
        start_activation(C[k])
        r = act_rows(ids[k])[-1]
        return collection_cb(r[0], r[1], '500.00', paid=paid), r

    def payouts(k):
        return db(f"SELECT id, amount_kobo, status FROM referral_payouts WHERE referral_id = {rid[k]} AND kind = 'activation'")

    # 4. Referred earner X activates by transfer
    w0, b0, n0 = balance(ids['ref']), sysbal('system:referral_bonus'), len(notes(ids['ref']))
    (s, b), r = activate('x')
    check('X pays: activated', s == 200 and activated(ids['x']), (s, b))
    p = payouts('x')
    check('referrer paid ₦100 once (activation payout row, paid)', len(p) == 1 and p[0][1:] == ['10000', 'paid'] and balance(ids['ref']) - w0 == 10000, (p, balance(ids['ref']) - w0))
    tx = db(f"SELECT id, type, amount_kobo FROM ledger_transactions WHERE idempotency_key = 'ref-activation:{rid['x']}'")
    legs = db(f"SELECT a.code, e.direction, e.amount_kobo FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE e.transaction_id = {tx[0][0]}") if tx else []
    check('ledger: Dr system:referral_bonus / Cr referrer wallet ₦100 (type referral_bonus)', tx and tx[0][1:] == ['referral_bonus', '10000'] and ['system:referral_bonus', 'debit', '10000'] in legs and sysbal('system:referral_bonus') - b0 == 10000, (tx, legs))
    nn = notes(ids['ref'])
    check('referrer gets "Referral bonus: ₦100.00 … activated their account"', len(nn) > n0 and any(t.startswith('Referral bonus: ₦100.00') and 'activated their account' in bd for _, t, bd, _ in nn[n0:]), nn[n0:])
    s, b = collection_cb(r[0], r[1], '500.00')
    sw = cron('hourly.php')
    check('callback replay + catch-up: nothing more paid', len(payouts('x')) == 1 and balance(ids['ref']) - w0 == 10000 and 'activation bonus(es)' in sw.stdout, sw.stdout[-300:])
    s, l, h = C['x'].get('/tasks.php')
    fids, fh = feed_ids(C['x'])
    rewards = sum(int(one(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {i}")) for i in fids)
    check('after activation: no card; the feed has exactly the tasks and total the stat boxes showed', 'data-activation-card' not in h and len(fids) == n_card and rewards == k_card, (len(fids), n_card, rewards, k_card))

    # 5. Unreferred Z activates: nothing paid
    w1 = balance(ids['ref'])
    activate('z')
    check('earner without a referral activates: no bonus anywhere', activated(ids['z']) and balance(ids['ref']) == w1 and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {ids['z']}") == '0')

    # 6. Flagged referral Y: nothing until an admin approves
    activate('y')
    check('flagged referral: activation pays nothing', activated(ids['y']) and payouts('y') == [] and balance(ids['ref']) == w1)
    s, l, h = admin.get('/referrals.php')
    admin.post('/referrals.php', {'_csrf': admin.csrf(h), 'action': 'referral_review', 'id': rid['y'], 'decision': 'approve', 'note': 'Checked, different person'})
    check('admin approves the flagged referral: ₦100 paid at once', len(payouts('y')) == 1 and balance(ids['ref']) - w1 == 10000, (payouts('y'), balance(ids['ref']) - w1))

    # 7. Admin-verified activation (V)
    w2 = balance(ids['ref'])
    start_activation(C['v'])
    pidv = int(act_rows(ids['v'])[-1][2])
    s, l, h = admin.get('/activation.php')
    admin.post('/activation.php', {'_csrf': admin.csrf(h), 'action': 'verify', 'payment_id': pidv, 'reference': 'UTRV' + tag, 'note': 'Found in AfrStar portal'})
    time.sleep(0.3)
    check('admin-verified activation pays the referrer ₦100', activated(ids['v']) and len(payouts('v')) == 1 and balance(ids['ref']) - w2 == 10000, (activated(ids['v']), payouts('v')))

    # 8. Bonus switched off, then catch-up
    setting('referrals.activation_bonus_kobo', '0')
    w3 = balance(ids['ref'])
    activate('u')
    check('bonus 0: activation pays nothing', activated(ids['u']) and payouts('u') == [] and balance(ids['ref']) == w3)
    setting('referrals.activation_bonus_kobo', '10000')
    sw = cron('hourly.php')
    check('bonus back on: hourly catch-up pays the missed activation once', len(payouts('u')) == 1 and balance(ids['ref']) - w3 == 10000 and '1 activation bonus(es)' in sw.stdout, sw.stdout[-300:])
    cron('hourly.php')
    check('…and a second catch-up pays nothing more', len(payouts('u')) == 1 and balance(ids['ref']) - w3 == 10000)

    # 9. Activations before the since-date never pay
    setting('referrals.activation_bonus_since', '2099-01-01 00:00:00')
    w4 = balance(ids['ref'])
    activate('w')
    cron('hourly.php')
    check('activation before referrals.activation_bonus_since: no bonus, not even by catch-up', activated(ids['w']) and payouts('w') == [] and balance(ids['ref']) == w4)
    setting('referrals.activation_bonus_since', '2026-11-11 11:00:00')

    # 10. Referrer page
    s, l, h = C['ref'].get('/referrals.php')
    check('referrer page: "You earn ₦100.00 as soon as each one activates", Activations total, "activated their account" rows',
          'You earn <strong>₦100.00</strong> as soon as each one activates their account.' in h and 'Activations <strong>' in h and h.count('activated their account') >= 4, re.findall(r'.{60}activated their account', h)[:3])

    # 11. Admin settings + filter + reversal
    s, l, h = admin.get('/referrals.php')
    check('admin: activation bonus field (₦100)', 'name="activation_bonus"' in h and 'value="100"' in h)
    base = {'_csrf': admin.csrf(h), 'action': 'settings', 'enabled': '1', 'commission_percent': str(int(sval('referrals.commission_bps')) / 100), 'ip_allowance': sval('referrals.ip_allowance')}
    admin.post('/referrals.php', dict(base, activation_bonus='abc'))
    msg, _ = admin.flash('/referrals.php')
    check('invalid bonus refused, unchanged', 'activation bonus' in msg and sval('referrals.activation_bonus_kobo') == '10000', msg)
    admin.post('/referrals.php', dict(base, activation_bonus='150'))
    check('admin sets the bonus to ₦150', sval('referrals.activation_bonus_kobo') == '15000')
    admin.post('/referrals.php', dict(base, activation_bonus='100'))
    s, l, h = admin.get('/referrals.php?f=activation')
    check('admin Activations filter lists the activation bonuses', 'Activation: ' in h and 'activated their account' in h)
    px = payouts('x')[0]
    b5, w5 = sysbal('system:referral_bonus'), balance(ids['ref'])
    s, l, h = admin.get('/referrals.php')
    admin.post('/referrals.php', {'_csrf': admin.csrf(h), 'action': 'payout_reverse', 'id': px[0], 'reason': 'Test reversal of activation bonus'})
    check('admin reverses an activation bonus: wallet -₦100, back to system:referral_bonus', payouts('x')[0][2] == 'reversed' and w5 - balance(ids['ref']) == 10000 and b5 - sysbal('system:referral_bonus') == 10000)

    ok, out = reconcile_ok()
    check('reconciliation OK', ok, out[-400:])
    ok, out = verify_sql('referral_award_reconciliation.sql')
    check('database/verify/referral_award_reconciliation.sql: no rows', ok, out[-400:])
finally:
    db("UPDATE campaigns SET status = 'ended', closed_at = UTC_TIMESTAMP() WHERE is_platform = 1 AND status IN ('live', 'paused')")
    db("UPDATE task_reservations r JOIN campaigns c ON c.id = r.campaign_id SET r.status = 'released' WHERE c.is_platform = 1 AND r.status = 'active'")
    for k, v in RESTORE.items():
        if v is not None:
            setting(k, v)

passed = sum(1 for _, ok in results if ok)
print(f'\nactivation referral: {passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
