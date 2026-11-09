#!/usr/bin/env python3
"""YOLOTASK V2 integration test: YOLOTASK tasks, bundles and optional account activation (migration 0019).

Covers: the migration (columns, CHECK, bundles table, the closed "YOLOTASK"
system account, ledger accounts, activation tables, settings seeded with
account activation OFF, ₦500 fee, 50 % reward) and the reward rounding (half
of the normal reward, rounded down to the kobo); activation OFF by default
(every earner sees and can do every task, advertisers' and YOLOTASK's, and
nothing mentions activation); admin bundles (create, edit, add several
links at once, enable / disable a bundle or single tasks, remove and restore,
edit with a new instructions version, completion counts); bulk import (admin
paste and CSV upload with dry run, bad rows reported, re-import adds nothing)
and scripts/import-platform-tasks.php (dry run, real run, idempotent re-run,
the seed file database/seeds/platform_tasks_seed.csv); the earner feed
(advertisers' tasks first, then YOLOTASK tasks grouped under their account,
no Premium / YOLOTASK label for earners); reward = half the normal reward,
reserved and submitted at that price; proofs go straight to the reviewer
queue with a subtle YOLOTASK label; the platform-funded payout (Dr
system:platform_task_payouts, no escrow, no margin); once per earner; the
level's held-task limit; reject -> appeal -> overturn paid by the platform and
an admin override reversal; auto-approve; approved YOLOTASK tasks count
toward a referral milestone, the leaderboard and earner levels; reward
percent and catalog changes reprice (started slots keep their reward); an
advertiser can still run a campaign for a link in a bundle; activation ON
(a non-activated earner sees no task, the activation card on feed, dashboard
and task page, starting is refused, a slot held before the switch can still
be submitted); activation by AfrStar transfer (YTA reference, order reused,
bad signature / non-success ignored, paid once, replays idempotent, revenue
Dr system:afrstar_clearing / Cr system:activation_revenue, wallet untouched,
YTA never forwarded); wrong amount -> payment review (activate or reject);
second payment -> revenue + refund review; admin manual verify of an expired
order; activated earners keep access and switching OFF opens everything again;
ledger reconciliation and database/verify/platform_tasks_reconciliation.sql.
Everything the test creates as YOLOTASK tasks is ended at the end and the
settings are restored, so other suites see no live YOLOTASK task.

Needs the Phase 3 servers (tests/Integration/PaymentCallbacks/run-servers.sh,
fake AfrStar on 8499), migrations through 0019, Pillow.
Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_DB_CLI.
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
REQUIRED0 = sval('activation.required')
RESTORE = {k: sval(k) for k in ('tasks.reward_hold_hours', 'tasks.resubmission_cap', 'tasks.max_active_reservations', 'levels.enabled', 'referrals.enabled')}
for k, v in [('tasks.reward_hold_hours', '0'), ('tasks.resubmission_cap', '1'), ('tasks.max_active_reservations', '3'), ('levels.enabled', '0'),
             ('activation.fee_kobo', '50000'), ('platform_tasks.reward_percent', '50'), ('referrals.enabled', '1')]:
    setting(k, v)
db("DELETE FROM rate_limits")
# A previous failed run could leave live YOLOTASK tasks behind: end them so counts start at zero.
db("UPDATE campaigns SET status = 'ended', closed_at = UTC_TIMESTAMP() WHERE is_platform = 1 AND status IN ('live', 'paused')")
created = []


def pt_ids(where):
    return [int(r[0]) for r in db(f"SELECT c.id FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id WHERE c.is_platform = 1 AND {where} ORDER BY c.id")]


try:
    # ======================================================================
    # 1. Migration and rules
    # ======================================================================
    mig = sorted(os.listdir(ROOT + '/database/migrations'))
    MIGF = ROOT + '/database/migrations/0019_platform_tasks_activation.sql'
    migsql = open(MIGF).read()
    check('migration 0019_platform_tasks_activation recorded (one 0019 file)', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0019_platform_tasks_activation.sql'") == '1'
          and len([f for f in mig if f.startswith('0019_')]) == 1, mig)
    check('campaigns.is_platform (default 0) and campaigns.bundle_id; platform_task_bundles with a unique bundle_key',
          one("SELECT COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND column_name = 'is_platform'") == '0'
          and one("SHOW COLUMNS FROM campaigns LIKE 'bundle_id'") == 'bundle_id'
          and one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'platform_task_bundles' AND index_name = 'uq_platform_task_bundles_key'") == '1'
          and one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND index_name = 'uq_campaigns_bundle_task'") == '3')
    check('system:activation_revenue (credit-normal) and system:platform_task_payouts (debit-normal), no negatives',
          one("SELECT COUNT(*) FROM ledger_accounts WHERE (code = 'system:activation_revenue' AND normal_side = 'credit' AND allow_negative = 0) OR (code = 'system:platform_task_payouts' AND normal_side = 'debit' AND allow_negative = 0)") == '2')
    owner = int(sval('platform_tasks.owner_user_id'))
    check('owner: the closed system account "YOLOTASK" that cannot sign in', db(f"SELECT display_name, role, status, password_hash FROM users WHERE id = {owner}")[0] == ['YOLOTASK', 'advertiser', 'closed', '!'])
    check("migration seeds account activation OFF, fee ₦500 (50000 kobo), reward 50%",
          "('activation.required', '0')" in migsql and "('activation.fee_kobo', '50000')" in migsql and "('platform_tasks.reward_percent', '50')" in migsql)
    check('account activation is OFF in this database (as shipped)', REQUIRED0 == '0', REQUIRED0)
    defaults = subprocess.run(['php', '-r', 'require $argv[1]; $d = YoloTask\\Core\\Settings::DEFAULTS; echo $d["activation.required"], "/", $d["activation.fee_kobo"], "/", $d["platform_tasks.reward_percent"];', ROOT + '/shared/lib/Core/Settings.php'], capture_output=True, text=True).stdout
    check('code defaults match (0 / 50000 / 50)', defaults == '0/50000/50', defaults)
    check('tables: activation_payments (unique order_sn), account_activations (one per user), payment_reviews.activation_payment_id',
          one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND ((table_name = 'activation_payments' AND index_name = 'uq_activation_payments_sn') OR (table_name = 'account_activations' AND index_name = 'PRIMARY'))") == '2'
          and one("SHOW COLUMNS FROM payment_reviews LIKE 'activation_payment_id'") == 'activation_payment_id')
    unit = subprocess.run(['php', '-r', '''require $argv[1]; use YoloTask\\PlatformTasks\\PlatformTasks as P;
echo json_encode([P::rewardFor(1500, 50), P::rewardFor(1000, 50), P::rewardFor(2500, 50), P::rewardFor(1200, 50), P::rewardFor(1, 50), P::rewardFor(2501, 50), P::rewardFor(1000, 33), P::rewardFor(999, 100), P::rewardFor(0, 50)]);''',
                           ROOT + '/shared/autoload.php'], capture_output=True, text=True)
    check('reward rounding: half, rounded DOWN to the kobo, at least 1 kobo (₦15 → ₦7.50, ₦25.01 → ₦12.50, 33% of ₦10 = ₦3.30)',
          unit.returncode == 0 and json.loads(unit.stdout or 'null') == [750, 500, 1250, 600, 1, 1250, 330, 999, 1], unit.stdout + unit.stderr)
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    bad = subprocess.run(cli + [DBNAME, '-e',
        f"INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements, is_platform, unit_price_kobo, unit_reward_kobo, quantity, budget_kobo) VALUES ({owner}, 1, 1, 'x', 'https://instagram.com/x', 'instagram.com/x', REPEAT('a', 64), 'i', 'p', 1, 1000, 500, 1, 0)"], capture_output=True, text=True)
    check('CHECK constraint: a YOLOTASK row with a margin (or without a bundle) is refused by the database', bad.returncode != 0 and 'chk_campaigns_money' in bad.stderr, bad.stderr[:200])

    # ======================================================================
    # 2. People
    # ======================================================================
    em = {k: f'pt{k}{tag}@gmail.com' for k in ['admin', 'adv', 'r1', 'r2', 'e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'ref', 'eu']}
    admid = make_user('admin', em['admin'], PW, 'Grace Admin')
    advid = make_user('advertiser', em['adv'], PW, 'Bola Ads')
    make_user('reviewer', em['r1'], PW, 'Rita Reviewer')
    make_user('reviewer', em['r2'], PW, 'Remi Reviewer')
    ids = {k: make_user('earner', em[k], PW, f'Earner {k.upper()} Okoro') for k in ['e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'ref']}
    euid = make_user('earner', em['eu'], PW, 'Unverified Earner', verified=False)
    db(f"INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status, created_at) VALUES ({ids['ref']}, {ids['e4']}, 'earner', 'PT{tag[-6:]}', 'active', UTC_TIMESTAMP() - INTERVAL 1 HOUR)")
    admin, adv, rev1, rev2 = Client(ADM), Client(A), Client(R), Client(R)
    X = {k: Client(E) for k in ids}
    EU = Client(E)
    check('everyone signs in', admin.login(em['admin'], PW) and adv.login(em['adv'], PW) and rev1.login(em['r1'], PW) and rev2.login(em['r2'], PW)
          and all(X[k].login(em[k], PW) for k in X) and EU.login(em['eu'], PW))
    for k in ids:
        X[k].get('/wallet.php')
    ig = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))
    fb = int(one("SELECT id FROM platforms WHERE slug = 'facebook'"))
    tt = {r[0]: int(r[1]) for r in db("SELECT slug, id FROM task_types")}
    base = {r[0]: int(r[1]) for r in db("SELECT slug, base_reward_kobo FROM task_types")}
    db("UPDATE task_types SET base_price_kobo = 2500, base_reward_kobo = 1500, is_active = 1 WHERE slug = 'follow'")
    base['follow'] = 1500
    rev0, act0, out0 = sysbal('system:revenue'), sysbal('system:activation_revenue'), sysbal('system:platform_task_payouts')

    # ======================================================================
    # 3. Admin: bundles (create, add several links at once, edit)
    # ======================================================================
    s, l, h = admin.get('/platform-tasks.php')
    check('admin YOLOTASK tasks page: bundles, new bundle, bulk import, reward, market tabs', s == 200 and 'name="account_name"' in h and 'name="csv_text"' in h and 'name="csv"' in h
          and 'name="reward_percent"' in h and 'href="platform-tasks.php"' in h and 'href="activation.php"' in h and 'premium' not in h.lower())
    tok = admin.csrf(h)
    s, l, h2 = rev1.get('/platform-tasks.php')
    check('reviewer portal has no YOLOTASK tasks admin page (404)', s == 404, s)
    admin.post('/platform-tasks.php', {'_csrf': 'bad', 'action': 'create_bundle', 'platform_id': fb, 'account_name': f'Nope{tag}', 'profile_url': '', 'bundle_key': ''})
    check('creating a bundle without CSRF does nothing', one(f"SELECT COUNT(*) FROM platform_task_bundles WHERE account_name = 'Nope{tag}'") == '0')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'create_bundle', 'platform_id': fb, 'account_name': 'Nifelux', 'profile_url': f'https://instagram.com/nl{tag}', 'bundle_key': ''})
    msg, _ = admin.flash('/platform-tasks.php')
    check('profile link on the wrong platform refused', 'not a Facebook link' in msg, msg)
    s, l, b = admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'create_bundle', 'platform_id': fb, 'account_name': 'Nifelux', 'profile_url': f'https://facebook.com/nifelux{tag}', 'bundle_key': f'nl-{tag}'})
    B1 = int(one(f"SELECT id FROM platform_task_bundles WHERE bundle_key = 'nl-{tag}'") or 0)
    check('bundle created (Facebook · Nifelux, profile link, key) and the admin lands on it', B1 > 0 and l and f'bundle={B1}' in l
          and db(f"SELECT platform_id, account_name, profile_url, status FROM platform_task_bundles WHERE id = {B1}")[0] == [str(fb), 'Nifelux', f'https://facebook.com/nifelux{tag}', 'live'], (l, B1))
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'create_bundle', 'platform_id': fb, 'account_name': 'Other', 'profile_url': '', 'bundle_key': f'nl-{tag}'})
    msg, _ = admin.flash('/platform-tasks.php')
    check('a second bundle with the same key is refused', 'already exists' in msg, msg)
    BP = f'platform-tasks.php?bundle={B1}'
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'add_tasks', 'bundle_id': B1, 'task_type_id': tt['follow'], 'links': f'https://facebook.com/nifelux{tag}', 'instructions': '', 'back': f'bundle={B1}'})
    links_like = f'https://facebook.com/nifelux{tag}/posts/1\nhttps://www.facebook.com/nifelux{tag}/posts/2 https://facebook.com/nifelux{tag}/posts/3\nhttps://instagram.com/wrong{tag}\nhttps://facebook.com/nifelux{tag}/posts/1?fbclid=zz'
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'add_tasks', 'bundle_id': B1, 'task_type_id': tt['like'], 'links': links_like, 'instructions': '', 'back': f'bundle={B1}'})
    msg, h = admin.flash('/' + BP)
    check('add tasks: several links pasted at once (lines and spaces), wrong-platform link reported, repeated link skipped',
          '3 tasks added' in msg and '1 duplicate skipped' in msg and '1 link not added' in msg and 'not a Facebook link' in msg, msg)
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'add_tasks', 'bundle_id': B1, 'task_type_id': tt['comment'], 'links': f'https://facebook.com/nifelux{tag}/posts/4',
                                      'instructions': 'Write a short, genuine comment about the post, then screenshot it.', 'back': f'bundle={B1}'})
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'add_tasks', 'bundle_id': B1, 'task_type_id': tt['share'], 'links': f'https://facebook.com/nifelux{tag}/posts/5', 'instructions': '', 'back': f'bundle={B1}'})
    rows = db(f"SELECT c.id, t.slug, c.unit_price_kobo, c.unit_reward_kobo, c.status, c.title, c.advertiser_user_id, c.budget_kobo, c.funded_kobo, COALESCE(c.escrow_account_id, 0), c.quantity, c.platform_id FROM campaigns c JOIN task_types t ON t.id = c.task_type_id WHERE c.bundle_id = {B1} ORDER BY c.id")
    created += [int(r[0]) for r in rows]
    check('bundle has 6 tasks, each its own row (follow, 3 likes, comment, share)', [r[1] for r in rows] == ['follow', 'like', 'like', 'like', 'comment', 'share'], [r[1] for r in rows])
    check('each task: reward = half of its type\'s normal reward, price = reward (follow ₦7.50, like ₦5.00, comment ₦12.50)',
          all(int(r[2]) == int(r[3]) == base[r[1]] // 2 for r in rows) and int(rows[0][3]) == 750, [(r[1], r[3]) for r in rows])
    check('live, owned by YOLOTASK, no budget / funding / escrow, on the bundle\'s platform',
          all(r[4] == 'live' and int(r[6]) == owner and r[7] == '0' and r[8] == '0' and r[9] == '0' and r[10] == '100000' and int(r[11]) == fb for r in rows))
    check('titles name the account: "Follow Nifelux on Facebook", "Like Nifelux\'s post on Facebook"', rows[0][5] == 'Follow Nifelux on Facebook' and rows[1][5] == "Like Nifelux's post on Facebook", [r[5] for r in rows[:2]])
    T = {'follow': int(rows[0][0]), 'like1': int(rows[1][0]), 'like2': int(rows[2][0]), 'like3': int(rows[3][0]), 'comment': int(rows[4][0]), 'share': int(rows[5][0])}
    cm = db(f"SELECT instructions, proof_requirements, instructions_version FROM campaigns WHERE id = {T['comment']}")[0]
    check('given instructions kept; proof = task type hint; version 1 recorded', cm[0].startswith('Write a short, genuine comment') and cm[1] == one("SELECT proof_hint FROM task_types WHERE slug = 'comment'")
          and cm[2] == '1' and one(f"SELECT COUNT(*) FROM campaign_instructions WHERE campaign_id = {T['comment']}") == '1')
    s, l, h = admin.get('/' + BP)
    check('bundle page: tasks with completion counts, add-tasks form', f'data-bundle-detail="{B1}"' in h and 'data-add-tasks' in h and 'Completed 0 · waiting 0 · rejected 0' in task_row(h, T['follow']))
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'update_bundle', 'bundle_id': B1, 'account_name': 'Nifelux Tech', 'profile_url': f'https://facebook.com/nifelux{tag}', 'back': f'bundle={B1}'})
    check('bundle account name edited', one(f"SELECT account_name FROM platform_task_bundles WHERE id = {B1}") == 'Nifelux Tech')

    # ======================================================================
    # 4. Bulk import: admin paste / upload / dry run, CLI, idempotency, seed file
    # ======================================================================
    csv_text = ('bundle_key,platform,account_name,task_type,url,instructions\n'
                f'ig-{tag},instagram,Insta {tag},follow,https://instagram.com/ig{tag},\n'
                f'ig-{tag},Instagram,Insta {tag},likes,https://www.instagram.com/p/ig{tag}a/,"Open the post, tap the heart, then screenshot it"\n'
                f'ig-{tag},instagram,Insta {tag},comment,https://instagram.com/p/ig{tag}b,\n'
                f'yt-{tag},youtube,Tube {tag},subscribe,https://youtube.com/@yt{tag},\n'
                f'yt-{tag},YouTube,Tube {tag},watch,https://youtu.be/yt{tag}v,\n'
                f'x-{tag},x,Ex {tag},follow,https://x.com/ex{tag},\n'
                f'th-{tag},threads,Thr {tag},like,https://www.threads.com/@th{tag}/post/abc,\n'
                f'bad-{tag},myspace,Bad,follow,https://myspace.com/b{tag},\n'
                f'bad-{tag},instagram,Bad,dance,https://instagram.com/b{tag},\n'
                f'bad-{tag},instagram,Bad,follow,https://facebook.com/b{tag},\n'
                f'ig-{tag},tiktok,Insta {tag},like,https://tiktok.com/@ig{tag}/video/1,\n')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'import', 'csv_text': csv_text, 'dry_run': '1'})
    msg, _ = admin.flash('/platform-tasks.php')
    check('admin import dry run: reports 7 tasks in 4 new bundles and 4 bad rows, saves nothing',
          msg.startswith('Dry run') and '7 tasks would be added in 4 new bundles' in msg and '4 rows not added' in msg and one(f"SELECT COUNT(*) FROM platform_task_bundles WHERE bundle_key LIKE '%-{tag}' AND bundle_key <> 'nl-{tag}'") == '0', msg)
    check('bad rows name the line and the reason (unknown platform / task type, wrong link, bundle on another platform)',
          'Line 9: unknown platform' in msg and 'Line 10: unknown task type' in msg and 'Line 11:' in msg and 'not a Instagram link' in msg, msg)
    admin.upload('/platform-tasks.php', {'_csrf': tok, 'action': 'import', 'csv_text': ''}, {'csv': ('tasks.csv', csv_text.encode(), 'text/csv')})
    msg, _ = admin.flash('/platform-tasks.php')
    imp = pt_ids(f"b.bundle_key IN ('ig-{tag}', 'yt-{tag}', 'x-{tag}', 'th-{tag}')")
    created += imp
    check('CSV upload: 7 tasks in 4 bundles (rows with the same bundle_key form one bundle); the tiktok row for an instagram bundle refused',
          len(imp) == 7 and '7 tasks added in 4 new bundles' in msg and one(f"SELECT COUNT(*) FROM platform_task_bundles WHERE bundle_key = 'bad-{tag}'") == '0'
          and 'is on Instagram, not TikTok' in msg, msg)
    check('aliases: "likes" → Like, "x" → X (Twitter); threads.com and x.com links accepted; quoted commas kept',
          one(f"SELECT COUNT(*) FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id WHERE b.bundle_key = 'th-{tag}' AND c.link_url LIKE 'https://www.threads.com/%'") == '1'
          and one(f"SELECT COUNT(*) FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id WHERE b.bundle_key = 'x-{tag}'") == '1'
          and one(f"SELECT instructions FROM campaigns WHERE link_url = 'https://www.instagram.com/p/ig{tag}a/'") == 'Open the post, tap the heart, then screenshot it')
    check('import fills a bundle\'s profile link from its follow / subscribe row', one(f"SELECT profile_url FROM platform_task_bundles WHERE bundle_key = 'ig-{tag}'") == f'https://instagram.com/ig{tag}')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'import', 'csv_text': csv_text})
    msg, _ = admin.flash('/platform-tasks.php')
    check('importing the same rows again adds nothing (7 duplicates, bundles reused)', '0 tasks added' in msg and '4 existing bundles reused' in msg and '7 duplicates skipped' in msg, msg)
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'import', 'csv_text': 'platform,url\ninstagram,https://instagram.com/x'})
    msg, _ = admin.flash('/platform-tasks.php')
    check('a file without the required header is refused with the expected header', 'bundle_key,platform,account_name,task_type,url,instructions' in msg, msg)
    # CLI one-shot
    fx = f'/tmp/pt_fixture_{tag}.csv'
    open(fx, 'w').write('bundle_key,platform,account_name,task_type,url,instructions\n'
                        f'cli-{tag},tiktok,Tok {tag},follow,https://www.tiktok.com/@tok{tag},\n'
                        f'cli-{tag},tiktok,Tok {tag},like,https://www.tiktok.com/@tok{tag}/video/1,\n'
                        f'cli-{tag},tiktok,Tok {tag},share,https://www.tiktok.com/@tok{tag}/video/2,\n'
                        f'cli-{tag},tiktok,Tok {tag},share,https://tiktok.com/@tok{tag}/video/2?is_from_webapp=1,\n'
                        f'cli2-{tag},facebook,Book {tag},like,https://fb.com/book{tag}/posts/1,\n'
                        f'cli2-{tag},facebook,Book {tag},dance,https://fb.com/book{tag}/posts/2,\n')
    rc, out = cli_import(fx, dry=True)
    check('CLI --dry-run: counts reported, nothing saved', rc == 0 and 'dry run, nothing saved' in out and 'bundles created: 2' in out and 'tasks added: 4' in out and 'already there (skipped): 1' in out
          and 'rows not imported: 1' in out and 'Line 7: unknown task type' in out and one(f"SELECT COUNT(*) FROM platform_task_bundles WHERE bundle_key LIKE 'cli%-{tag}'") == '0', out)
    rc, out = cli_import(fx)
    cli_ids = pt_ids(f"b.bundle_key IN ('cli-{tag}', 'cli2-{tag}')")
    created += cli_ids
    check('CLI import: 2 bundles, 4 tasks (normalised duplicate link skipped), bad row listed', rc == 0 and 'bundles created: 2' in out and 'tasks added: 4' in out and len(cli_ids) == 4, out)
    rc, out = cli_import(fx)
    check('CLI re-run is idempotent: nothing added, everything already there', rc == 0 and 'bundles created: 0' in out and 'existing bundles reused: 2' in out and 'tasks added: 0' in out
          and 'already there (skipped): 5' in out and len(pt_ids(f"b.bundle_key IN ('cli-{tag}', 'cli2-{tag}')")) == 4, out)
    rc, out = cli_import('/nonexistent.csv')
    check('CLI without a readable file exits 1', rc == 1 and '--file' in out, out)
    seed = ROOT + '/database/seeds/platform_tasks_seed.csv'
    if os.path.exists(seed):
        nrows = sum(1 for line in open(seed, encoding='utf-8') if line.strip()) - 1
        rc, out = cli_import(seed, dry=True)
        added = re.search(r'tasks added: (\d+), already there \(skipped\): (\d+)', out)
        check(f'seed file database/seeds/platform_tasks_seed.csv ({nrows} rows) imports cleanly (dry run): every row usable',
              rc == 0 and added and int(added.group(1)) + int(added.group(2)) == nrows and 'rows not imported: 0' in out, out[:600])
    else:
        check('seed file database/seeds/platform_tasks_seed.csv present', False, 'missing')

    # ======================================================================
    # 5. Advertiser campaign for a link that is in a bundle
    # ======================================================================
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': advid, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': '500', 'reason': 'Platform task test funding'})
    s, l, h = adv.get('/campaign-new.php')
    s, l, b = adv.post('/campaign-new.php', {'_csrf': adv.csrf(h), 'title': 'Advertiser follow PT', 'platform_id': ig, 'task_type_id': tt['follow'],
                                              'link_url': f'https://instagram.com/ig{tag}', 'instructions': 'Follow this page with your own account and keep following.',
                                              'proof_requirements': 'Screenshot showing you follow the page.', 'quantity': '3', 'ends_at': ''})
    check('advertiser can create a campaign for the same link + task type as a YOLOTASK task', s == 303 and l and 'campaign.php?id=' in l, (s, l))
    cadv = int(l.split('=')[1]) if l and '=' in l else 0
    s, l, h = adv.get(f'/campaign.php?id={cadv}')
    adv.post(f'/campaign.php?id={cadv}', {'_csrf': adv.csrf(h), 'campaign_id': cadv, 'action': 'submit'})
    s, l, h = admin.get('/campaigns.php')
    admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cadv, 'action': 'approve', 'back': 'campaigns.php'})
    check('advertiser campaign live', one(f"SELECT status FROM campaigns WHERE id = {cadv}") == 'live')
    s, l, h = admin.get('/campaigns.php?status=all')
    check('YOLOTASK tasks stay out of the advertiser campaign list', not any(f'campaign.php?id={c}"' in h for c in created))
    LIVE = int(one("SELECT COUNT(*) FROM campaigns c JOIN platform_task_bundles b ON b.id = c.bundle_id WHERE c.is_platform = 1 AND c.status = 'live' AND b.status = 'live'"))
    check('17 live YOLOTASK tasks in 7 bundles', LIVE == 17 and len(created) == 17, LIVE)

    # ======================================================================
    # 6. Activation OFF (default): everyone sees everything, nothing mentions activation
    # ======================================================================
    e1, e1id = X['e1'], ids['e1']
    fids, h = feed_ids(e1)
    allh = feed_ids.html
    check('activation OFF: the feed has the advertiser task and every YOLOTASK task', cadv in fids and sorted(c for c in fids if c in created) == sorted(created), (len([c for c in fids if c in created]), len(created)))
    check("advertisers' tasks come before YOLOTASK tasks", fids.index(cadv) < min(fids.index(c) for c in created))
    check('no activation card or prompt, no Premium, no YOLOTASK label for earners', 'data-activation-card' not in allh and 'activate.php' not in allh and 'premium' not in allh.lower()
          and 'data-source' not in allh and 'badge-source' not in allh)
    grp = re.search(r'<li class="task-bundle" data-bundle="' + str(B1) + r'">.*?</details>', allh, re.S)
    check('bundle grouped under one account card: "Nifelux Tech · Facebook · 6 tasks", expanding into its 6 tasks',
          grp is not None and 'Nifelux Tech · Facebook · 6 tasks' in grp.group(0) and len(re.findall(r'<a href="task\.php\?id=(\d+)"', grp.group(0))) == 6
          and '+₦' + f'{sum(base[r[1]] // 2 for r in rows) / 100:,.2f}' in grp.group(0), grp.group(0)[:400] if grp else '')
    check('YOLOTASK task rows show no slot count', 'slots left' not in grp.group(0) if grp else False)
    cnt = re.search(r'data-feed-count="(\d+)"', h)
    check('feed count badge = tasks (advertiser + YOLOTASK)', cnt and int(cnt.group(1)) == len(fids), (cnt.group(1) if cnt else None, len(fids)))
    s, l, h = e1.get('/dashboard.php')
    check('dashboard: no activation card; tasks available counts YOLOTASK tasks', 'data-activation-card' not in h and 'activate.php' not in h and 'premium' not in h.lower()
          and re.search(r'Tasks available</span>\s*<span class="stat-value">' + str(len(fids)) + '<', h) is not None)
    s, l, h = e1.get('/activate.php')
    check('activate.php (OFF): "No activation needed", no pay form', s == 200 and 'data-activation="not-needed"' in h and 'name="phone"' not in h)
    e1.post('/activate.php', {'_csrf': e1.csrf(h), 'phone': '08031234567'})
    msg, _ = e1.flash('/activate.php')
    check('…and no activation payment can be started', 'does not need activation' in msg and act_rows(e1id) == [], msg)
    s, l, h = admin.get('/activation.php')
    check('admin Account activation page: switch OFF, fee ₦500', s == 200 and 'data-activation-switch="off"' in h and 'value="500"' in h and 'name="required"' in h)

    # ======================================================================
    # 7. Work a YOLOTASK task: half reward, reviewer queue, platform-funded payout
    # ======================================================================
    def start(ec, cid):
        s, l, h = ec.get(f'/task.php?id={cid}')
        ec.post(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'start'})

    def submit(ec, cid, do_start=True):
        if do_start:
            start(ec, cid)
        s, l, h = ec.get(f'/task.php?id={cid}')
        s, l, b = ec.upload(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'submit', 'comment': 'Done with my account'}, {'proof': ('p.png', image(), 'image/png')})
        return int(l.split('=')[1]) if l and 'submission.php?id=' in l else None

    def stale_decide(rc, sid, action, reason=''):
        s, l, h = rc.get('/stale.php')
        rc.post('/stale.php', {'_csrf': rc.csrf(h), 'submission_id': sid, 'action': 'claim'})
        s, l, h = rc.get('/stale.php')
        return rc.post('/stale.php', {'_csrf': rc.csrf(h), 'submission_id': sid, 'action': action, 'reason': reason})

    w1 = balance(e1id)
    cf = T['follow']
    s, l, h = e1.get(f'/task.php?id={cf}')
    check('task page: no label, reward ₦7.50, "One per account", Start enabled', s == 200 and 'premium' not in h.lower() and 'badge-source' not in h and '+₦7.50' in h and 'One per account' in h
          and re.search(r'value="start" disabled', h) is None)
    start(e1, cf)
    check('reservation at price = reward (₦7.50)', db(f"SELECT status, unit_price_kobo, unit_reward_kobo FROM task_reservations WHERE campaign_id = {cf} AND earner_user_id = {e1id}")[0] == ['active', '750', '750'])
    s, l, h = e1.get(f'/task.php?id={cf}')
    check('slot form: note for the reviewer, proof seen only by the earner and staff', 'Note for the reviewer' in h and 'Only you and YOLOTASK staff can see it' in h)
    s1 = submit(e1, cf, do_start=False)
    check('proof submitted at ₦7.50 / ₦7.50', s1 and db(f"SELECT status, unit_price_kobo, unit_reward_kobo FROM submissions WHERE id = {s1}")[0] == ['pending', '750', '750'])
    s, l, h = e1.get(f'/submission.php?id={s1}')
    check('earner submission page: no source label', 'badge-source' not in h and 'premium' not in h.lower())
    s, l, h = rev1.get('/stale.php')
    row = re.search(r'<li class="user-row submission-row" data-stale="' + str(s1) + r'".*?</li>', h, re.S)
    check('reviewer queue: the proof is there at once with a subtle YOLOTASK label', row is not None and 'data-source="yolotask"' in row.group(0) and '>YOLOTASK<' in row.group(0) and 'Stale &amp; YOLOTASK proofs' in h)
    s, l, h = admin.get('/submissions.php?status=yolotask')
    check('admin submissions: "YOLOTASK (pending)" filter lists it with the label', f'data-submission="{s1}"' in h and 'data-source="yolotask"' in h)
    s, l, h = admin.get('/submissions.php?status=stale')
    check('…but not under Stale (advertisers late)', f'data-submission="{s1}"' not in h)
    revb = sysbal('system:revenue')
    stale_decide(rev1, s1, 'approve')
    sub1 = db(f"SELECT status, decision_source, approve_transaction_id FROM submissions WHERE id = {s1}")[0]
    legs = db(f"SELECT a.code, e.direction, e.amount_kobo FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE e.transaction_id = {sub1[2]} ORDER BY e.id") if sub1[2] != 'NULL' else []
    check('reviewer approves: earner +₦7.50', sub1[:2] == ['approved', 'reviewer'] and balance(e1id) - w1 == 750, (sub1, balance(e1id) - w1))
    check('ledger: Dr system:platform_task_payouts ₦7.50 / Cr earner wallet ₦7.50 (no escrow, no margin)', len(legs) == 2 and legs[0][0] == 'system:platform_task_payouts' and legs[0][1:] == ['debit', '750']
          and legs[1][0].startswith('wallet:') and legs[1][1:] == ['credit', '750'], legs)
    check('platform payouts +₦7.50, task revenue unchanged, task counters updated', sysbal('system:platform_task_payouts') - out0 == 750 and sysbal('system:revenue') == revb
          and db(f"SELECT approved_count, pending_count, spent_kobo, status FROM campaigns WHERE id = {cf}")[0] == ['1', '0', '750', 'live'])
    check('earner inbox: proof approved, ₦7.50 added', any(n[1].startswith('Proof approved') and '₦7.50' in n[2] for n in notes(e1id)))
    check('no notification for the YOLOTASK system account', one(f"SELECT COUNT(*) FROM notifications WHERE user_id = {owner}") == '0')

    # ======================================================================
    # 8. Once per earner; held-task limit
    # ======================================================================
    fids, h = feed_ids(e1)
    check('a done task leaves the feed; the bundle card now shows 5 tasks', cf not in fids and 'Nifelux Tech · Facebook · 5 tasks' in feed_ids.html)
    start(e1, cf)
    msg, _ = e1.flash(f'/task.php?id={cf}')
    check('starting it again is refused (once per earner)', 'already submitted' in msg and one(f"SELECT COUNT(*) FROM submissions WHERE campaign_id = {cf} AND earner_user_id = {e1id}") == '1', msg)
    setting('tasks.max_active_reservations', '2')
    start(e1, T['like1'])
    start(e1, T['comment'])
    start(e1, T['share'])
    msg, _ = e1.flash(f"/task.php?id={T['share']}")
    check('the held-task limit applies (2 held, third refused)', 'You can work on 2 tasks at a time' in msg
          and one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {e1id} AND status = 'active' AND expires_at > UTC_TIMESTAMP()") == '2', msg)
    setting('tasks.max_active_reservations', '3')

    # ======================================================================
    # 9. Reject -> appeal -> overturn (platform pays) -> admin override reversal
    # ======================================================================
    s2 = submit(e1, T['like1'], do_start=False)
    stale_decide(rev1, s2, 'reject', 'The like is not visible in the screenshot')
    check('reviewer rejects; no money moves', one(f"SELECT status FROM submissions WHERE id = {s2}") == 'rejected' and sysbal('system:platform_task_payouts') - out0 == 750)
    s, l, h = e1.get(f'/submission.php?id={s2}')
    e1.upload(f'/submission.php?id={s2}', {'_csrf': e1.csrf(h), 'submission_id': s2, 'action': 'appeal', 'reason': 'The like is there; the icon is blue at the bottom.'}, {})
    ap = one(f"SELECT id FROM submission_appeals WHERE submission_id = {s2}")
    check('earner appeals the rejection', ap is not None)
    s, l, h = rev1.get(f'/appeal.php?id={ap}')
    check('reviewer appeal page shows the YOLOTASK label', 'data-source="yolotask"' in h)
    s, l, h = rev2.get(f'/appeal.php?id={ap}')
    rev2.post(f'/appeal.php?id={ap}', {'_csrf': rev2.csrf(h), 'appeal_id': ap, 'action': 'claim', 'note': ''})
    s, l, h = rev2.get(f'/appeal.php?id={ap}')
    b2 = balance(e1id)
    rev2.post(f'/appeal.php?id={ap}', {'_csrf': rev2.csrf(h), 'appeal_id': ap, 'action': 'overturn', 'note': 'The like is visible.'})
    apr = db(f"SELECT status, escrow_kobo, wallet_kobo, platform_kobo, over_budget FROM submission_appeals WHERE id = {ap}")[0]
    check('another reviewer overturns: earner +₦5.00 from system:platform_task_payouts, nothing charged to an advertiser',
          apr == ['overturned', '0', '0', '0', '0'] and balance(e1id) - b2 == 500 and sysbal('system:platform_task_payouts') - out0 == 1250, (apr, balance(e1id) - b2))
    check('no advertiser debt for the YOLOTASK account', one(f"SELECT COUNT(*) FROM advertiser_debts WHERE advertiser_user_id = {owner}") == '0')
    s, l, h = admin.get(f'/appeals.php?id={ap}')
    check('admin appeal page: YOLOTASK label, "paid by YOLOTASK", link to the bundle', 'data-source="yolotask"' in h and 'YOLOTASK task: paid by YOLOTASK' in h and f'platform-tasks.php?bundle={B1}' in h)
    admin.post(f'/appeals.php?id={ap}', {'_csrf': admin.csrf(h), 'appeal_id': ap, 'action': 'uphold', 'note': 'Admin second look: the like is not visible', 'back': f'?id={ap}'})
    check('admin override to uphold reverses the reward back to the platform account', one(f"SELECT status FROM submission_appeals WHERE id = {ap}") == 'upheld'
          and balance(e1id) == b2 and sysbal('system:platform_task_payouts') - out0 == 750 and one(f"SELECT status FROM submissions WHERE id = {s2}") == 'rejected')

    # ======================================================================
    # 10. Auto-approve
    # ======================================================================
    s3 = submit(e1, T['comment'], do_start=False)
    db(f"UPDATE submissions SET auto_approve_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {s3}")
    b3 = balance(e1id)
    r = cron('every-fifteen-minutes.php')
    check('auto-approve pays from the platform (₦12.50 comment)', one(f"SELECT CONCAT(status, '/', decision_source) FROM submissions WHERE id = {s3}") == 'approved/auto'
          and balance(e1id) - b3 == base['comment'] // 2 and sysbal('system:platform_task_payouts') - out0 == 750 + base['comment'] // 2, r.stdout[-300:] + r.stderr[-300:])

    # ======================================================================
    # 11. Referral milestone, leaderboard, levels
    # ======================================================================
    ms = db("SELECT id, task_count FROM referral_milestones WHERE is_active = 1 ORDER BY task_count LIMIT 1")
    own_ms = None
    if not ms or int(ms[0][1]) > 3:
        db("INSERT INTO referral_milestones (task_count, reward_kobo, is_active) VALUES (2, 5000, 1)")
        own_ms = int(one("SELECT MAX(id) FROM referral_milestones"))
        ms = [[str(own_ms), '2']]
    need = int(ms[0][1])
    refb = balance(ids['ref'])
    e4c = X['e4']
    for cid in [T['like2'], T['like3'], imp[0]][:need]:
        sid = submit(e4c, cid)
        s, l, h = admin.get('/submissions.php?status=yolotask')
        admin.post('/submissions.php', {'_csrf': admin.csrf(h), 'submission_id': sid, 'action': 'approve', 'back': '?status=yolotask'})
    pays = db(f"SELECT kind, milestone_task_count, status FROM referral_payouts WHERE referee_user_id = {ids['e4']} ORDER BY id")
    check(f'approved YOLOTASK tasks reach the referral milestone ({need} tasks): the referrer is paid', any(p[0] == 'milestone' and p[1] == str(need) and p[2] == 'paid' for p in pays)
          and balance(ids['ref']) > refb, (pays, balance(ids['ref']) - refb))
    lb = subprocess.run(['php', ROOT + '/tests/Integration/Growth/lb_query.php', 'get', 'workers', 'week', str(e1id)], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
    lbj = json.loads(lb.stdout or '{}')
    mine = [r for r in lbj.get('rows', []) if r.get('user_id') == e1id] or ([lbj['viewer']] if lbj.get('viewer') else [])
    check('leaderboard (Top workers, this week) counts approved YOLOTASK tasks and their rewards', mine and int(mine[0]['count']) == 2 and int(mine[0]['total_kobo']) == 750 + base['comment'] // 2, lb.stdout[:300] + lb.stderr[:200])
    lv = subprocess.run(['php', '-r', 'define("YOLOTASK_APP_ROLE","main"); define("YOLOTASK_APP_ROOT",$argv[1]); define("YOLOTASK_NO_SESSION",true); require $argv[1]."/shared/bootstrap.php"; echo json_encode(YoloTask\\Growth\\Levels::stats(YoloTask\\Core\\Database::connection(), (int) $argv[2]));', ROOT, str(e1id)],
                        capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
    lvj = json.loads(lv.stdout or '{}')
    check('earner levels count approved YOLOTASK tasks (2 approved, 1 rejected)', lvj.get('approved') == 2 and lvj.get('rejected') == 1, lv.stdout + lv.stderr[:200])
    setting('levels.enabled', '1')
    s, l, h = e1.get('/levels.php')
    check('levels page shows them', '2 approved · 1 rejected' in h)
    setting('levels.enabled', '0')

    # ======================================================================
    # 12. Enable / disable (task and bundle), remove / restore, edit, bulk
    # ======================================================================
    s, l, h = admin.get('/' + BP)
    tok = admin.csrf(h)
    c5 = T['share']
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'disable', 'id': c5, 'back': f'bundle={B1}'})
    check('admin disables one task (paused); it leaves the feed', one(f"SELECT status FROM campaigns WHERE id = {c5}") == 'paused' and c5 not in feed_ids(X['e2'])[0])
    start(X['e2'], c5)
    check('…and cannot be started', one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c5} AND earner_user_id = {ids['e2']}") == '0')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'enable', 'id': c5, 'back': f'bundle={B1}'})
    check('enabled again: back in the feed', one(f"SELECT status FROM campaigns WHERE id = {c5}") == 'live' and c5 in feed_ids(X['e2'])[0])
    start(X['e5'], T['like2'])  # e5 holds a slot in the bundle before it is disabled
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'disable_bundle', 'bundle_id': B1, 'back': f'bundle={B1}'})
    fids2, _ = feed_ids(X['e2'])
    check('disabling the bundle hides all its tasks from the feed', one(f"SELECT status FROM platform_task_bundles WHERE id = {B1}") == 'paused' and not any(T[k] in fids2 for k in T))
    start(X['e2'], T['like3'])
    msg, _ = X['e2'].flash(f"/task.php?id={T['like3']}")
    check('…and none can be started', 'no longer available' in msg or one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {T['like3']} AND earner_user_id = {ids['e2']}") == '0', msg)
    s5 = submit(X['e5'], T['like2'], do_start=False)
    check('a slot held before the bundle was disabled can still be submitted', s5 is not None and one(f"SELECT status FROM submissions WHERE id = {s5}") == 'pending')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'enable_bundle', 'bundle_id': B1, 'back': f'bundle={B1}'})
    check('bundle enabled again: its tasks are back', T['like3'] in feed_ids(X['e2'])[0])
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'disable_bundle', 'bundle_ids[]': [str(B1)], 'back': ''})
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'enable_bundle', 'bundle_ids[]': [str(B1)], 'back': ''})
    check('bulk bundle disable / enable (selected)', one(f"SELECT status FROM platform_task_bundles WHERE id = {B1}") == 'live')
    yt_ids = pt_ids(f"b.bundle_key = 'yt-{tag}'")
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'disable', 'ids[]': [str(i) for i in yt_ids], 'back': ''})
    check('bulk task disable (selected)', one(f"SELECT COUNT(*) FROM campaigns WHERE id IN ({','.join(map(str, yt_ids))}) AND status = 'paused'") == '2')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'enable', 'ids[]': [str(i) for i in yt_ids], 'back': ''})
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'disable', 'ids[]': [str(cadv)], 'back': ''})
    check('task actions never touch advertiser campaigns', one(f"SELECT status FROM campaigns WHERE id = {cadv}") == 'live')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'remove', 'id': T['like3'], 'back': f'bundle={B1}'})
    check('remove: the task is ended and leaves the feed', one(f"SELECT status FROM campaigns WHERE id = {T['like3']}") == 'ended' and T['like3'] not in feed_ids(X['e2'])[0])
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'add_tasks', 'bundle_id': B1, 'task_type_id': tt['like'], 'links': f'https://facebook.com/nifelux{tag}/posts/3', 'instructions': '', 'back': f'bundle={B1}'})
    msg, _ = admin.flash('/' + BP)
    check('adding the removed link again restores the same task (no duplicate row)', '1 removed task restored' in msg and one(f"SELECT status FROM campaigns WHERE id = {T['like3']}") == 'live'
          and one(f"SELECT COUNT(*) FROM campaigns WHERE bundle_id = {B1}") == '6', msg)
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'remove', 'id': cli_ids[-1], 'back': ''})
    rc, out = cli_import(fx)
    check('re-running the CLI import never revives a task an admin removed', rc == 0 and 'tasks added: 0' in out and one(f"SELECT status FROM campaigns WHERE id = {cli_ids[-1]}") == 'ended', out)
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'edit', 'id': c5, 'title': 'Share our launch post', 'instructions': 'Share the post to your timeline with your own account.', 'proof': 'Screenshot of the shared post on your timeline.', 'back': f'bundle={B1}'})
    check('edit: title, instructions and proof saved as instructions version 2', db(f"SELECT title, instructions_version FROM campaigns WHERE id = {c5}")[0] == ['Share our launch post', '2']
          and one(f"SELECT COUNT(*) FROM campaign_instructions WHERE campaign_id = {c5}") == '2')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'edit', 'id': cadv, 'title': 'Hijack', 'instructions': 'Open the link and follow with your own account.', 'proof': 'Screenshot please.', 'back': ''})
    check('edit cannot touch an advertiser campaign', one(f"SELECT title FROM campaigns WHERE id = {cadv}") == 'Advertiser follow PT')
    s, l, h = admin.get('/' + BP)
    check('completion counts per task', 'Completed 1 · waiting 0 · rejected 0' in task_row(h, T['follow']) and 'Completed 0 · waiting 0 · rejected 1' in task_row(h, T['like1']), (task_row(h, T['follow'])[:300], task_row(h, T['like1'])[:300]))
    s, l, h = admin.get('/platform-tasks.php?q=Nifelux')
    brow = re.search(r'<li class="tx-row" data-bundle-row="' + str(B1) + r'">.*?</li>', h, re.S)
    exp_b1 = int(one(f"SELECT COALESCE(SUM(approved_count), 0) FROM campaigns WHERE bundle_id = {B1} AND status IN ('live', 'paused')"))
    check('bundle list: completion counts per bundle', brow is not None and exp_b1 >= 3 and f'Completed {exp_b1} · waiting 1' in brow.group(0), brow.group(0)[:400] if brow else '')

    # ======================================================================
    # 13. Reward percent; catalog change
    # ======================================================================
    start(X['e6'], T['like3'])  # a held slot at ₦5.00 before the reprice
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'settings', 'reward_percent': '0', 'back': ''})
    check('reward percent 0 refused', sval('platform_tasks.reward_percent') == '50')
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'settings', 'reward_percent': '40', 'back': ''})
    msg, _ = admin.flash('/platform-tasks.php')
    check('40% saved; every YOLOTASK task repriced (follow ₦6.00)', sval('platform_tasks.reward_percent') == '40' and one(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {cf}") == '600'
          and one(f"SELECT unit_price_kobo FROM campaigns WHERE id = {cf}") == '600' and 'updated' in msg, msg)
    s6 = submit(X['e6'], T['like3'], do_start=False)
    check('a slot started before the reprice keeps its reward (₦5.00)', s6 and db(f"SELECT unit_price_kobo, unit_reward_kobo FROM submissions WHERE id = {s6}")[0] == [str(base['like'] // 2)] * 2)
    admin.post('/platform-tasks.php', {'_csrf': tok, 'action': 'settings', 'reward_percent': '50', 'back': ''})
    check('back to 50%', one(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {cf}") == '750')
    s, l, h = admin.get('/catalog.php')
    admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'save_type', 'id': tt['follow'], 'name': 'Follow', 'proof_hint': one("SELECT proof_hint FROM task_types WHERE slug = 'follow'"),
                                'base_price': '30', 'base_reward': '18', 'is_active': '1'})
    check('catalog reward change reprices YOLOTASK follow tasks (₦18 → ₦9.00)', one(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {cf}") == '900')
    s, l, h = admin.get('/catalog.php')
    admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'save_type', 'id': tt['follow'], 'name': 'Follow', 'proof_hint': one("SELECT proof_hint FROM task_types WHERE slug = 'follow'"),
                                'base_price': '25', 'base_reward': '15', 'is_active': '1'})
    check('catalog restored (₦7.50 again)', one(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {cf}") == '750')

    # ======================================================================
    # 14. Account activation ON: gating every task; in-flight work can finish
    # ======================================================================
    e7, e7id = X['e7'], ids['e7']
    start(e7, imp[1])  # e7 holds a YOLOTASK slot before the switch
    start(e7, cadv)    # …and an advertiser slot
    s, l, h = admin.get('/activation.php')
    tokA = admin.csrf(h)
    admin.post('/activation.php', {'_csrf': tokA, 'action': 'settings', 'fee': '50', 'required': '1'})
    msg, _ = admin.flash('/activation.php')
    check('fee below ₦100 refused (switch unchanged)', 'between ₦100.00' in msg and sval('activation.required') == '0', msg)
    admin.post('/activation.php', {'_csrf': tokA, 'action': 'settings', 'fee': '500', 'required': '1'})
    msg, h = admin.flash('/activation.php')
    check('admin switches account activation ON at ₦500', sval('activation.required') == '1' and sval('activation.fee_kobo') == '50000' and 'ON' in msg and 'data-activation-switch="on"' in h, msg)
    e3 = X['e3']
    fids, h = feed_ids(e3)
    check('ON, not activated: no task at all on the feed (neither advertiser nor YOLOTASK)', fids == [] and 'data-feed' not in h, fids[:5])
    check('feed shows "Activate your account with ₦500.00 to access tasks." linking to activate.php', 'data-activation-card' in h and 'Activate your account with ₦500.00 to access tasks.' in h and 'href="activate.php"' in h)
    s, l, h = e3.get('/dashboard.php')
    check('dashboard: the activation card, 0 tasks available', 'data-activation-card' in h and re.search(r'Tasks available</span>\s*<span class="stat-value">0<', h) is not None)
    for cid in (cadv, T['like3']):
        s, l, h = e3.get(f'/task.php?id={cid}')
        check(f'task page #{cid} (not activated): the activation card instead of the task', s == 200 and 'data-activation-card' in h and 'name="action" value="start"' not in h and 'Advertiser follow PT' not in h)
        e3.post(f'/task.php?id={cid}', {'_csrf': e3.csrf(None, '/dashboard.php'), 'campaign_id': cid, 'action': 'start'})
        msg, _ = e3.flash('/tasks.php')
        check(f'starting #{cid} is refused with the activation message', 'Activate your account with ₦500.00 to access tasks.' in msg and one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {cid} AND earner_user_id = {ids['e3']}") == '0', msg)
    s, l, h = e7.get('/tasks.php')
    check('in-flight: a non-activated earner still sees the tasks they hold under In progress', 'data-activation-card' in h and f'task.php?id={imp[1]}' in h and f'task.php?id={cadv}' in h)
    s7a = submit(e7, imp[1], do_start=False)
    s7b = submit(e7, cadv, do_start=False)
    check('in-flight: slots held before the switch can still be submitted (YOLOTASK and advertiser)', s7a is not None and s7b is not None
          and one(f"SELECT COUNT(*) FROM submissions WHERE earner_user_id = {e7id} AND status = 'pending'") == '2', (s7a, s7b))
    s, l, h = e7.get(f'/submission.php?id={s7a}')
    check('…and their submission pages stay open', s == 200)
    s, l, h = EU.get('/activate.php')
    check('unverified earner: activation needs a confirmed email (button disabled)', s == 200 and 'Confirm your email first' in h and 'disabled' in h)
    EU.post('/activate.php', {'_csrf': EU.csrf(h), 'phone': '08031234567'})
    check('unverified earner cannot create an activation payment', one(f"SELECT COUNT(*) FROM activation_payments WHERE user_id = {euid}") == '0')

    # ======================================================================
    # 15. Activation by bank transfer, confirmed by the callback (e3)
    # ======================================================================
    w3 = balance(ids['e3'])
    s, l, h = e3.get('/activate.php')
    check('Activation page: fee, "to access tasks", transfer notice, phone form', s == 200 and 'One-time ₦500.00' in h and 'Activate your account with ₦500.00 to access tasks.' in h and 'name="phone"' in h
          and 'not added to your wallet' in h and 'Pay ₦500.00 and activate' in h and 'premium' not in h.lower())
    e3.post('/activate.php', {'_csrf': e3.csrf(h), 'phone': '12'})
    msg, _ = e3.flash('/activate.php')
    check('a bad phone number is refused before AfrStar', 'phone number' in msg and act_rows(ids['e3']) == [], msg)
    s, l, _ = start_activation(e3)
    rows3 = act_rows(ids['e3'])
    check('activation POST redirects to the AfrStar payment page; one YTA order of ₦500 waiting', s == 303 and l and l.startswith('https://pay.fake.test/') and len(rows3) == 1
          and rows3[0][0].startswith('YTA') and rows3[0][3] == 'pending' and rows3[0][4] == '50000', (s, l, rows3))
    sn3, osn3, pid3 = rows3[0][0], rows3[0][1], int(rows3[0][2])
    reqs = json.loads(urllib.request.build_opener(urllib.request.ProxyHandler({})).open('http://127.0.0.1:8499/_requests').read())
    payin = [r for r in reqs if r['path'] == '/gateway/payin/' and r['body'].get('order_sn') == sn3]
    check('payin sent to AfrStar with the fee and a return to activate.php', payin and payin[-1]['sign_ok'] and str(payin[-1]['body'].get('amount')) in ('500.00', '500')
          and 'activate.php?o=' + sn3 in json.dumps(payin[-1]['body']), payin[-1]['body'] if payin else reqs[-1:])
    s, l, _ = start_activation(e3)
    check('a second tap reuses the waiting order (no second transfer)', l and l.startswith('https://pay.fake.test/') and len(act_rows(ids['e3'])) == 1)
    s, l, h = e3.get('/activate.php')
    check('Activation page shows the waiting state with Refresh and Open payment page', 'data-activation="pending"' in h and 'Refresh status' in h and 'Open payment page' in h)
    s, b = collection_cb(sn3, osn3, '500.00', sign='0' * 32)
    check('callback with a bad signature: 400, nothing changes', s == 400 and act_rows(ids['e3'])[0][3] == 'pending' and not activated(ids['e3']), (s, b))
    s, b = collection_cb(sn3, osn3, '500.00', status=2)
    check('callback with a non-success status: acknowledged, nothing changes', s == 200 and b == 'SUCCESS' and act_rows(ids['e3'])[0][3] == 'pending' and not activated(ids['e3']), (s, b))
    s, b = collection_cb(sn3, osn3, '500.00')
    check('paid callback: SUCCESS, payment paid, earner activated', s == 200 and b == 'SUCCESS' and act_rows(ids['e3'])[0][3] == 'paid' and activated(ids['e3']), (s, b, act_rows(ids['e3'])))
    tx = db(f"SELECT t.id, t.type, t.amount_kobo FROM ledger_transactions t WHERE t.idempotency_key = 'activation:{sn3}'")
    legs = db(f"SELECT a.code, e.direction, e.amount_kobo FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE e.transaction_id = {tx[0][0]} ORDER BY e.id") if tx else []
    check('ledger: Dr system:afrstar_clearing / Cr system:activation_revenue ₦500 (key activation:<order>)', tx and tx[0][1:] == ['activation_fee', '50000']
          and sorted(legs) == sorted([['system:afrstar_clearing', 'debit', '50000'], ['system:activation_revenue', 'credit', '50000']]), (tx, legs))
    check('activation revenue +₦500; the earner wallet is not touched', sysbal('system:activation_revenue') - act0 == 50000 and balance(ids['e3']) == w3)
    check('callback outcome logged as activated; YTA never forwarded', one(f"SELECT outcome FROM payment_callbacks WHERE order_sn = '{sn3}' ORDER BY id DESC LIMIT 1") == 'activated'
          and one("SELECT COUNT(*) FROM afrstar_callback_forwards WHERE order_sn LIKE 'YTA%'") == '0')
    check('activation row: source payment, ₦500, linked to the payment', db(f"SELECT source, amount_kobo, activation_payment_id FROM account_activations WHERE user_id = {ids['e3']}")[0] == ['payment', '50000', str(pid3)])
    check('inbox: Account activated', any(n[1] == 'Account activated' and n[3] == 'tasks.php' for n in notes(ids['e3'])), notes(ids['e3'])[-2:])
    s, b = collection_cb(sn3, osn3, '500.00')
    check('replayed callback: SUCCESS (duplicate), nothing posted twice', s == 200 and b == 'SUCCESS' and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key LIKE 'activation%{sn3}'") == '1'
          and sysbal('system:activation_revenue') - act0 == 50000 and one(f"SELECT outcome FROM payment_callbacks WHERE order_sn = '{sn3}' ORDER BY id DESC LIMIT 1") == 'duplicate')
    outs = []
    ths = [threading.Thread(target=lambda: outs.append(collection_cb(sn3, osn3, '500.00'))) for _ in range(5)]
    [t.start() for t in ths]
    [t.join() for t in ths]
    check('five concurrent replays: all acknowledged, still one posting and one activation', all(o == (200, 'SUCCESS') for o in outs) and sysbal('system:activation_revenue') - act0 == 50000
          and one(f"SELECT COUNT(*) FROM account_activations WHERE user_id = {ids['e3']}") == '1', outs)
    s, l, h = e3.get('/activate.php?o=' + sn3)
    check('AfrStar return page: account activated', s == 200 and 'data-activation="active"' in h and 'Your account is activated' in h)
    s, l, h = X['e2'].get('/activate.php?o=' + sn3)
    check("another earner cannot open someone else's activation order", s == 404, s)
    start_activation(e3)
    msg, _ = e3.flash('/activate.php')
    check('an activated earner cannot pay again', 'already activated' in msg and len(act_rows(ids['e3'])) == 1, msg)
    fids, h = feed_ids(e3)
    check('activated (switch ON): every task is back in the feed, advertisers first, no activation card', cadv in fids and T['follow'] in fids and fids.index(cadv) < fids.index(T['follow'])
          and 'data-activation-card' not in h)
    start(e3, T['follow'])
    check('…and the activated earner can start tasks', one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {T['follow']} AND earner_user_id = {ids['e3']} AND status = 'active'") == '1')

    # ======================================================================
    # 16. Wrong amount -> review queue (activate with the paid amount, or reject)
    # ======================================================================
    e2 = X['e2']
    start_activation(e2)
    sn2, osn2, pid2 = act_rows(ids['e2'])[0][:3]
    s, b = collection_cb(sn2, osn2, '500.00', paid='250.00')
    rv = db(f"SELECT id, kind, expected_kobo, paid_kobo, COALESCE(payment_order_id, 0) FROM payment_reviews WHERE activation_payment_id = {pid2}")
    check('underpaid: payment in review, review item for the activation, not activated, no revenue',
          s == 200 and b == 'SUCCESS' and act_rows(ids['e2'])[0][3] == 'review' and rv and rv[0][1:] == ['amount_mismatch', '50000', '25000', '0'] and not activated(ids['e2'])
          and sysbal('system:activation_revenue') - act0 == 50000, (s, b, rv))
    s, l, h = e2.get('/activate.php')
    check('Activation page shows "checking your payment" and no pay form', 'data-activation="review"' in h and 'checking your payment' in h and 'name="phone"' not in h)
    e2.post('/activate.php', {'_csrf': e2.csrf(h), 'phone': '08031234567'})
    msg, _ = e2.flash('/activate.php')
    check('no new payment while one is in review', 'being checked' in msg and len(act_rows(ids['e2'])) == 1, msg)
    s, l, h = admin.get('/payments.php')
    check('admin payment review queue: labelled Account activation with an Activate button', f'data-review="{rv[0][0]}"' in h and 'Account activation' in h and 'Activate (₦250.00 paid)' in h)
    admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': rv[0][0], 'action': 'credit', 'note': 'Customer confirmed partial transfer; accepted'})
    msg, _ = admin.flash('/payments.php')
    check('admin activates: earner activated (source review), ₦250 revenue, review credited, wallet untouched',
          activated(ids['e2']) and one(f"SELECT source FROM account_activations WHERE user_id = {ids['e2']}") == 'review' and sysbal('system:activation_revenue') - act0 == 75000
          and one(f"SELECT status FROM payment_reviews WHERE id = {rv[0][0]}") == 'credited' and act_rows(ids['e2'])[0][3] == 'paid' and 'Account activated' in msg, msg)
    start_activation(X['e6'])
    sn6, osn6, pid6 = act_rows(ids['e6'])[0][:3]
    collection_cb(sn6, osn6, '500.00', paid='100.00')
    rv6 = one(f"SELECT id FROM payment_reviews WHERE activation_payment_id = {pid6}")
    s, l, h = admin.get('/payments.php')
    admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': rv6, 'action': 'reject', 'note': 'Refunded through AfrStar'})
    check('admin rejects a mismatch: payment rejected, not activated, no revenue', act_rows(ids['e6'])[0][3] == 'rejected' and not activated(ids['e6'])
          and one(f"SELECT status FROM payment_reviews WHERE id = {rv6}") == 'rejected' and sysbal('system:activation_revenue') - act0 == 75000)
    s, b = collection_cb(sn6, osn6, '500.00')
    check('a later full callback for a rejected payment changes nothing', s == 200 and act_rows(ids['e6'])[0][3] == 'rejected' and not activated(ids['e6']))

    # ======================================================================
    # 17. Second payment by an activated earner (revenue + refund review)
    # ======================================================================
    start_activation(X['e5'])
    setting('activation.fee_kobo', '60000')
    start_activation(X['e5'])
    setting('activation.fee_kobo', '50000')
    r5 = act_rows(ids['e5'])
    check('a fee change starts a new order at the new amount (old order kept)', len(r5) == 2 and [x[4] for x in r5] == ['50000', '60000'], r5)
    collection_cb(r5[0][0], r5[0][1], '500.00')
    s, b = collection_cb(r5[1][0], r5[1][1], '600.00')
    dup = db(f"SELECT id, kind, status FROM payment_reviews WHERE activation_payment_id = {r5[1][2]}")
    check('second payment: paid (revenue), activation unchanged, refund review opened',
          s == 200 and b == 'SUCCESS' and [x[3] for x in act_rows(ids['e5'])] == ['paid', 'paid'] and one(f"SELECT activation_payment_id FROM account_activations WHERE user_id = {ids['e5']}") == r5[0][2]
          and dup and dup[0][1:] == ['activation_duplicate', 'open'] and sysbal('system:activation_revenue') - act0 == 75000 + 50000 + 60000, (s, b, dup))
    s, l, h = admin.get('/payments.php')
    check('admin queue: "Second activation payment"', 'Second activation payment' in h and f'data-review="{dup[0][0]}"' in h)
    admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': dup[0][0], 'action': 'credit', 'note': 'try to credit'})
    check('a duplicate item cannot be "credited"', one(f"SELECT status FROM payment_reviews WHERE id = {dup[0][0]}") == 'open')
    s, l, h = admin.get('/payments.php')
    admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': dup[0][0], 'action': 'reject', 'note': 'Refunded ₦600 to the earner via AfrStar'})
    check('dismissed after the refund; the payment stays paid', one(f"SELECT status FROM payment_reviews WHERE id = {dup[0][0]}") == 'dismissed' and act_rows(ids['e5'])[1][3] == 'paid')

    # ======================================================================
    # 18. Admin manual verify of an expired order (e4); a late callback is a duplicate
    # ======================================================================
    start_activation(X['e4'])
    sn4, osn4, pid4 = act_rows(ids['e4'])[0][:3]
    db(f"UPDATE activation_payments SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = {pid4}")
    r = cron('every-five-minutes.php')
    check('cron expires a stale activation payment', 'stale activation payments expired: ' in r.stdout and act_rows(ids['e4'])[0][3] == 'expired', r.stdout + r.stderr)
    s, l, h = X['e4'].get('/activate.php?o=' + sn4)
    check('Activation page: expired state with support advice and a fresh pay form', 'data-activation="expired"' in h and 'contact support' in h and 'name="phone"' in h)
    s, l, h = admin.get('/activation.php?pay=expired')
    check('admin activation payments list offers manual verify for the expired order', f'data-activation-payment="{sn4}"' in h and 'Verify and activate' in h)
    tokp = admin.csrf(h)
    admin.post('/activation.php', {'_csrf': tokp, 'action': 'verify', 'payment_id': pid4, 'reference': 'x', 'note': 'checked'})
    msg, _ = admin.flash('/activation.php')
    check('verify needs a real AfrStar reference', 'AfrStar reference' in msg and not activated(ids['e4']), msg)
    admin.post('/activation.php', {'_csrf': tokp, 'action': 'verify', 'payment_id': pid4, 'reference': 'UTR998877' + tag, 'note': 'Found the ₦500 transfer in the AfrStar portal'})
    check('admin verify: paid, activated (source admin), revenue +₦500 under the same key as the callback',
          act_rows(ids['e4'])[0][3] == 'paid' and one(f"SELECT source FROM account_activations WHERE user_id = {ids['e4']}") == 'admin'
          and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'activation:{sn4}'") == '1' and sysbal('system:activation_revenue') - act0 == 235000
          and one(f"SELECT verified_by_user_id FROM activation_payments WHERE id = {pid4}") == str(admid))
    s, b = collection_cb(sn4, osn4, '500.00')
    check('a late callback after manual verify: duplicate, no second posting', s == 200 and b == 'SUCCESS' and sysbal('system:activation_revenue') - act0 == 235000)
    admin.post('/activation.php', {'_csrf': tokp, 'action': 'verify', 'payment_id': pid4, 'reference': 'UTR998877', 'note': 'again'})
    msg, _ = admin.flash('/activation.php')
    check('verifying a paid payment again is refused', 'already paid' in msg, msg)
    s, l, h = admin.get('/activation.php')
    check('activated earners list shows the four activated earners with how they activated',
          all(f'data-activated="{ids[k]}"' in h for k in ('e2', 'e3', 'e4', 'e5')) and f'data-activated="{ids["e6"]}"' not in h and 'verified by an admin' in h and 'after review' in h)

    # ======================================================================
    # 19. Switch OFF again: everyone sees every task; activated stay activated
    # ======================================================================
    admin.post('/activation.php', {'_csrf': tokp, 'action': 'settings', 'fee': '500', 'required': ''})
    fids, h = feed_ids(X['e6'])
    check('OFF again: a never-activated earner sees every task, no activation card', sval('activation.required') == '0' and cadv in fids and T['follow'] in fids and 'data-activation-card' not in h)
    check('earners who activated stay activated', all(activated(ids[k]) for k in ('e2', 'e3', 'e4', 'e5')))
    s, l, h = X['e3'].get('/activate.php')
    check('an activated earner still sees "Your account is activated"', 'data-activation="active"' in h)

    # ======================================================================
    # 20. Reconciliation
    # ======================================================================
    ok, out = reconcile_ok()
    check('ledger + campaign reconciliation OK (cron/daily.php)', ok, out[-600:])
    ok, out = verify_sql('platform_tasks_reconciliation.sql')
    check('database/verify/platform_tasks_reconciliation.sql: no rows', ok, out[:400])
    ok, out = verify_sql('campaign_funding_reconciliation.sql')
    check('database/verify/campaign_funding_reconciliation.sql: no rows (YOLOTASK tasks have no escrow)', ok, out[:400])
    r = cron('hourly.php')
    check('hourly campaign reconciliation OK', r.returncode == 0 and 'escrow reconciliation OK' in r.stdout, r.stdout[-300:])
    db("UPDATE ledger_accounts SET balance_kobo = balance_kobo + 1 WHERE code = 'system:activation_revenue'")
    ok, out = reconcile_ok()
    ok2, out2 = verify_sql('platform_tasks_reconciliation.sql')
    db("UPDATE ledger_accounts SET balance_kobo = balance_kobo - 1 WHERE code = 'system:activation_revenue'")
    check('a tampered activation revenue balance is caught by both checks', not ok and 'Activation revenue' in out and not ok2 and 'activation_revenue' in out2, (out[-300:], out2[:200]))
    db("UPDATE ledger_accounts SET balance_kobo = balance_kobo + 7 WHERE code = 'system:platform_task_payouts'")
    ok, out = reconcile_ok()
    db("UPDATE ledger_accounts SET balance_kobo = balance_kobo - 7 WHERE code = 'system:platform_task_payouts'")
    check('a tampered YOLOTASK task payouts balance is caught', not ok and 'YOLOTASK task payouts' in out, out[-300:])
    ok, out = reconcile_ok()
    check('reconciliation OK again', ok, out[-300:])
    if own_ms:
        db(f"UPDATE referral_milestones SET is_active = 0 WHERE id = {own_ms}")
finally:
    # Decide (reject) every YOLOTASK proof still pending, so other suites' admin and reviewer queues stay short.
    try:
        pend = [r[0] for r in db("SELECT s.id FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.status = 'pending'")]
        if pend:
            ac = Client(ADM)
            if ac.login(em['admin'], PW):
                for sid in pend:
                    s_, l_, h_ = ac.get('/submissions.php?status=yolotask')
                    ac.post('/submissions.php', {'_csrf': ac.csrf(h_), 'submission_id': sid, 'action': 'reject', 'reason': 'Test cleanup: not needed', 'back': '?status=yolotask'})
    except Exception as ex:  # noqa: BLE001 - cleanup must not hide the real result
        print('cleanup warning:', ex)
    # Leave no live YOLOTASK task behind for the other suites; activation back OFF.
    db("UPDATE campaigns SET status = 'ended', closed_at = UTC_TIMESTAMP() WHERE is_platform = 1 AND status IN ('live', 'paused')")
    db("UPDATE task_reservations r JOIN campaigns c ON c.id = r.campaign_id SET r.status = 'released' WHERE c.is_platform = 1 AND r.status = 'active'")
    for k, v in [('activation.required', '0'), ('activation.fee_kobo', '50000'), ('platform_tasks.reward_percent', '50')] + [(k, v) for k, v in RESTORE.items() if v is not None]:
        setting(k, v)
    db("UPDATE task_types SET base_price_kobo = 2500, base_reward_kobo = 1500 WHERE slug = 'follow'")

check('cleanup: no live YOLOTASK task or pending YOLOTASK proof left, activation OFF', one("SELECT COUNT(*) FROM campaigns WHERE is_platform = 1 AND status IN ('live', 'paused')") == '0'
      and one("SELECT COUNT(*) FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.status = 'pending'") == '0' and sval('activation.required') == '0')
ok, out = reconcile_ok()
check('reconciliation OK after cleanup', ok, out[-300:])

passed = sum(1 for _, ok in results if ok)
print(f'\nplatform tasks: {passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
