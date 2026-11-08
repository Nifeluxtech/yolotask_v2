#!/usr/bin/env python3
"""YOLOTASK V2 Phase 6 integration test: referral capture (?ref= on both
register pages), self-referral / alias abuse, the per-referrer network and
device allowance (referrals.ip_allowance: sign-ups 1-3 accepted and paid,
4th flagged, admin limit change, referrer's own device always flagged) with
admin review, interests and premium targeting retired (migration 0017: no
routes, prompts or targeting; every campaign open to every earner), earner milestones (defaults, stacking, admin
add/edit/disable, catch-up sweep, only work after the referral), every
approval path paying milestones (advertiser, auto, admin, reviewer stale
queue, appeal overturn), idempotency, approval reversal flagging (no
claw-back) and admin payout reversal, advertiser deposit commission on the
AfrStar callback and the admin review-credit path (double callback, rate
changes, 0% skipped, programme off then catch-up), no commission on admin
credits or bonuses, admin bonuses, earner levels (held-task limits,
approval rate), notifications (inbox, bell, read / read all, email
switches, security emails always on, every category), announcements
(audience, schedule, banner, withdraw), pruning, authorization, CSRF and
reconciliation (ledger + database/verify/referral_award_reconciliation.sql).

Needs the Phase 5 environment (servers from
tests/Integration/PaymentCallbacks/run-servers.sh incl. the fake AfrStar on
8499, migration 0009 applied, Pillow). Env: YOLOTASK_CONFIG_DIR (default
/tmp/ytcfg), YT_MAIL_LOG, YT_DB_CLI.
"""
import hashlib, io, json, os, re, shlex, subprocess, sys, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')
M, E, A, R, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8404', 'http://127.0.0.1:8405'
SN, AK, SK = 'TESTSN001', 'testak123', 'testsk456'
UA_PHONE = 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:400] + ']') if (not ok and extra != '') else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base, ua=UA_PHONE):
        self.base = base
        self.ua = ua
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None, raw=None, ctype=None):
        url = path if path.startswith('http') else self.base + path
        body = raw if raw is not None else (urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
        headers = {'User-Agent': self.ua}
        if ctype:
            headers['Content-Type'] = ctype
        r = urllib.request.Request(url, data=body, headers=headers)
        try:
            resp = self.op.open(r, timeout=30)
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
            out.write(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
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


def db(sql):
    cli = shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1'))
    dbname = re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)
    out = subprocess.run(cli + ['-N', '-B', dbname, '-e', sql], capture_output=True, text=True)
    if out.returncode != 0:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().split('\n') if line != '']


def db_try(sql):
    try:
        db(sql)
        return True
    except RuntimeError:
        return False


def one(sql):
    rows = db(sql)
    return rows[0][0] if rows else None


def mails_to(to):
    try:
        return [json.loads(l) for l in open(MAIL) if json.loads(l)['to'] == to]
    except FileNotFoundError:
        return []


def make_user(role, email, pw, name):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name}', '{email}', '{h}', '{role}', 'active', UTC_TIMESTAMP())")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def uid(email):
    v = one(f"SELECT id FROM users WHERE email = '{email}'")
    return int(v) if v else None


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


def payouts(referrer, status='paid', kind=None):
    return int(one(f"SELECT COUNT(*) FROM referral_payouts WHERE referrer_user_id = {referrer} AND status = '{status}'" + (f" AND kind = '{kind}'" if kind else '')))


def notes(u, cat=None):
    return db(f"SELECT category, title, body, url, read_at IS NULL FROM notifications WHERE user_id = {u}" + (f" AND category = '{cat}'" if cat else '') + " ORDER BY id")


_img_n = [0]


def image():
    _img_n[0] += 1
    im = Image.new('RGB', (400, 700), ((_img_n[0] * 53) % 255, (_img_n[0] * 29) % 255, (_img_n[0] * 71) % 255))
    rnd = os.urandom(400)
    for x in range(0, 400, 5):
        im.putpixel((x, (x * _img_n[0] + 7) % 700), (rnd[x], 255, 255))
    b = io.BytesIO()
    im.save(b, 'PNG')
    return b.getvalue()


hooks = Client(M)


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


def collection_cb(order_sn, osn, order_amount, paid=None, status=1):
    paid = paid or order_amount
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'message': 'Paid Success', 'amount': paid,
               'utr': 'UTR' + osn, 'session_id': 'S' + osn, 'amount_need': order_amount, 'amount_paid': paid, 'time_paid': '2026-10-31 10:30:00',
               'sign': md5(AK, osn, status, order_amount)}
    out = hooks.req('/hooks/afrstar/collection.php', raw=json.dumps(payload).encode(), ctype='application/json')
    time.sleep(0.5)  # notifications and referral commission run after the response is sent
    return out


def payout_cb(order_sn, osn, amount, status=1):
    payload = {'merchant_sn': SN, 'order_sn': order_sn, 'platform_osn': osn, 'status': status, 'utr': 'TRF' + osn, 'name': 'Ada Obi',
               'message': 'Transfered Success' if status == 1 else 'Transfer failed', 'amount': amount, 'time_transfered': '2026-10-31 10:35:00',
               'sign': md5(AK, osn, status, amount)}
    out = hooks.req('/hooks/afrstar/payout.php', raw=json.dumps(payload).encode(), ctype='application/json')
    time.sleep(0.5)
    return out


# ---------- AfrStar config pointing at the fake gateway (same as phase3_test)
example = open(ROOT + '/config/payments.example.php').read().replace("'https://ngn.afrstar.com'", "'http://127.0.0.1:8499'")
open(os.path.join(CFG, 'payments.local.php'), 'w').write(example)
open(os.path.join(CFG, 'afrstar.key'), 'w').write(f'{SN},{AK},{SK}\n')
try:
    os.remove(os.path.join(CFG, 'notifications.php'))
except FileNotFoundError:
    pass

tag = str(int(time.time()))
PW = 'TestPass!2026abc'
for k, v in [('tasks.reward_hold_hours', '0'), ('tasks.resubmission_cap', '1'), ('tasks.max_active_reservations', '3'), ('tasks.stale_hours', '24'),
             ('appeals.window_hours', '72'), ('referrals.enabled', '1'), ('referrals.commission_bps', '500'), ('levels.enabled', '1'),
             ('notifications.retention_days', '90'), ('money.withdraw_daily_count', '3')]:
    setting(k, v)
db("UPDATE earner_levels SET min_approved = 0, min_approval_rate = 0, max_active_reservations = 3, is_active = 1 WHERE name = 'Starter'")
db("UPDATE earner_levels SET min_approved = 50, min_approval_rate = 80, max_active_reservations = 4, is_active = 1 WHERE name = 'Bronze'")
db("UPDATE referral_milestones SET is_active = 0 WHERE NOT (task_count = 10 AND reward_kobo = 10000) AND NOT (task_count = 25 AND reward_kobo = 20000)")

# ======================================================================
# 1. Migration and schema
# ======================================================================
mig = sorted(os.listdir(ROOT + '/database/migrations'))
check('migration 0009_referrals_levels_notifications recorded', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0009_referrals_levels_notifications.sql'") == '1')
check('old placeholders 0007_growth_* and 0008_notifications_* removed', not any(f.startswith('0007_growth') or f.startswith('0008_notifications') for f in mig), mig)
check('later placeholders renumbered 0010/0011 and still skipped', '0010_virtual_number_orders.sql' in mig and '0011_indexes_constraints_finalization.sql' in mig
      and one("SELECT COUNT(*) FROM schema_migrations WHERE filename LIKE '0010_%' OR filename LIKE '0011_%'") == '0')
check('each migration number has exactly one executable file', len([f for f in mig if f.startswith('0009_')]) == 1 and all('NOT executable' not in open(ROOT + '/database/migrations/' + f).read() for f in mig if f[:4] in ('0007', '0008', '0009')))
check('referral and bonus expense accounts exist (debit-normal, no negative)', db("SELECT COUNT(*) FROM ledger_accounts WHERE code IN ('system:referral_bonus', 'system:referral_commission', 'system:bonuses') AND normal_side = 'debit' AND allow_negative = 0")[0][0] == '3')
check('default milestones 10 tasks = ₦100 and 25 tasks = ₦200', one("SELECT COUNT(*) FROM referral_milestones WHERE is_active = 1 AND ((task_count = 10 AND reward_kobo = 10000) OR (task_count = 25 AND reward_kobo = 20000))") == '2')
check('default levels Starter/Bronze/Silver/Gold', [r[0] for r in db("SELECT name FROM earner_levels WHERE name IN ('Starter', 'Bronze', 'Silver', 'Gold') ORDER BY min_approved")] == ['Starter', 'Bronze', 'Silver', 'Gold'])

# ======================================================================
# 2. Users, codes and the referral page
# ======================================================================
em = {k: f'p6{k}{tag}@gmail.com' for k in ['r1', 'r2', 'adv', 'admin', 'rev']}
r1 = make_user('earner', em['r1'], PW, 'Remi Referrer')
r2 = make_user('advertiser', em['r2'], PW, 'Rhoda Agency')
advid = make_user('advertiser', em['adv'], PW, 'Bola Ads')
admid = make_user('admin', em['admin'], PW, 'Grace Admin')
revid = make_user('reviewer', em['rev'], PW, 'Rita Reviewer')
R1, R2, adv, admin, rev = Client(E), Client(A), Client(A), Client(ADM), Client(R)
check('referrers, advertiser, admin and reviewer sign in', R1.login(em['r1'], PW) and R2.login(em['r2'], PW) and adv.login(em['adv'], PW) and admin.login(em['admin'], PW) and rev.login(em['rev'], PW))

s, l, h = R1.get('/referrals.php')
code1 = one(f"SELECT referral_code FROM users WHERE id = {r1}")
check('earner referral page loads and creates a code', s == 200 and code1 and re.fullmatch(r'[A-Z2-9]{8}', code1) and code1 in h, (s, code1))
check('referral page shows earner and advertiser sign-up links with copy buttons',
      f'127.0.0.1:8402/register.php?ref={code1}' in h and f'127.0.0.1:8403/register.php?ref={code1}' in h and h.count('data-copy=') == 2)
check('referral page shows the milestones and the commission rate', '10 tasks' in h and '₦100.00' in h and '5%' in h)
s, l, h = R2.get('/referrals.php')
code2 = one(f"SELECT referral_code FROM users WHERE id = {r2}")
check('advertiser referral page loads with its own code', s == 200 and code2 and code2 != code1 and code2 in h)
s, l, h = rev.get('/referrals.php')
check('reviewer portal has no referral page', not os.path.exists(ROOT + '/apps/reviewer/public/referrals.php') and 'People you invited' not in h)
s, l, h = R1.get('/dashboard.php')
check('earner dashboard links to Invite & earn and shows the bell', 'href="referrals.php"' in h and 'href="notifications.php"' in h and 'data-unread="0"' in h)

# ======================================================================
# 3. Registration with ?ref=: capture, abuse rules, flags
# ======================================================================
UA_OTHER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'


def scramble(referrer, others_too=True):
    """Make the referrer's sessions look like another network (all test traffic is 127.0.0.1),
    and (others_too) earlier referees look like other networks and devices."""
    db(f"UPDATE user_sessions SET ip_hash = SHA2(UUID(), 256) WHERE user_id = {referrer}")
    if others_too:
        db(f"UPDATE referrals SET signup_ip_hash = SHA2(UUID(), 256), signup_agent_hash = SHA2(UUID(), 256) WHERE referrer_user_id = {referrer}")


pending = []


def open_register(base, name, email, ref, ua=UA_PHONE, prep=None, plain_get=False):
    c = Client(base, ua)
    s, l, h = c.get('/register.php' + ('' if plain_get or ref is None else f'?ref={ref}'))
    pending.append((c, h, name, email, prep))
    return c, h


def finish_all():
    time.sleep(3.2)  # sign-up form timing guard
    out = []
    for c, h, name, email, prep in pending:
        if prep:
            prep()
        db("DELETE FROM rate_limits WHERE bucket = 'register_ip'")
        data = {'_csrf': c.field(h, '_csrf'), '_stamp': c.field(h, '_stamp'), 'website': '', 'display_name': name, 'email': email, 'password': PW, 'password_confirmation': PW}
        if c.field(h, 'ref'):
            data['ref'] = c.field(h, 'ref')
        s, l, _ = c.post('/register.php', data)
        out.append(s == 303 and l == 'verify.php')
        db(f"UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE email = '{email}'")
    pending.clear()
    return out


c, h = Client(E), None
s, l, h = c.get(f'/register.php?ref={code1}')
check('register page with a valid code shows who invited you (masked) and carries the code', 'You were invited by <strong>Re*** R.</strong>' in h and f'name="ref" value="{code1}"' in h)
s, l, h = c.get('/register.php')
check('code is remembered for the browser session', f'name="ref" value="{code1}"' in h)
s, l, h = Client(E).get('/register.php?ref=NOPE1234')
check('unknown code: no invite note and no hidden code', 'You were invited' not in h and 'name="ref"' not in h)
s, l, h = Client(A).get(f'/register.php?ref={code1}')
check('advertiser register page accepts the same code', 'You were invited by' in h and f'name="ref" value="{code1}"' in h)

ee = {k: f'p6{k}{tag}@gmail.com' for k in ['x0', 'x0b', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'v1', 'v2']}
setting('referrals.ip_allowance', '1')  # this section checks the flag paths with an allowance of 1; section 8b checks the default 3
self_alias = f'p.6.r.1{tag}+promo@googlemail.com'
other_alias = f'p6.adv{tag}+2@gmail.com'
open_register(E, 'Self Alias', self_alias, code1)
open_register(E, 'Other Alias', other_alias, code1)
open_register(E, 'Xena Zero', ee['x0'], code1)                       # same UA + IP as R1 -> same device
open_register(E, 'Xavi Zerob', ee['x0b'], code1, ua=UA_OTHER)        # same IP as R1 (+ x0), past an allowance of 1
for k, name in [('x1', 'Xola One'), ('x2', 'Xander Two'), ('x3', 'Xia Three'), ('x4', 'Xeno Four'), ('x5', 'Xuri Five')]:
    open_register(E, name, ee[k], code1, ua=UA_OTHER, prep=lambda: scramble(r1))
open_register(E, 'Xi Six', ee['x6'], code1, ua=UA_OTHER, prep=lambda: scramble(r1, others_too=False))  # same IP as another referee, past the allowance
open_register(A, 'Vee One Ventures', ee['v1'], code2, ua=UA_OTHER, prep=lambda: scramble(r2))
open_register(A, 'Vic Two Media', ee['v2'], code1, ua=UA_OTHER, prep=lambda: scramble(r1))
ok = finish_all()
check('all twelve sign-ups succeed (a referral problem never blocks registration)', all(ok), ok)
ids = {k: uid(v) for k, v in ee.items()}
ref = lambda k: db(f"SELECT referrer_user_id, referee_role, status, COALESCE(flag_reason, '') FROM referrals WHERE referee_user_id = {ids[k]}")
check('self-referral through a Gmail alias (dots, +tag, googlemail) is not recorded', one(f"SELECT COUNT(*) FROM referrals WHERE referee_user_id = {uid(self_alias)}") == '0')
check('alias of another existing account is not recorded', one(f"SELECT COUNT(*) FROM referrals WHERE referee_user_id = {uid(other_alias)}") == '0')
check('normalised email stored for new accounts', one(f"SELECT email_normalized FROM users WHERE id = {uid(self_alias)}") == f'p6r1{tag}@gmail.com')
check('same device and network as the referrer -> flagged (likely self-referral)', ref('x0') and ref('x0')[0][2] == 'flagged' and 'Same device' in ref('x0')[0][3] and 'self-referral' in ref('x0')[0][3], ref('x0'))
check("2nd sign-up on the referrer's network with allowance 1 -> flagged", ref('x0b') and ref('x0b')[0][2] == 'flagged' and "Sign-up #2 from the referrer's network" in ref('x0b')[0][3], ref('x0b'))
check('clean earner referrals recorded as active for R1', all(ref(k) and ref(k)[0][:3] == [str(r1), 'earner', 'active'] for k in ['x1', 'x2', 'x3', 'x4', 'x5']), [ref(k) for k in ['x1', 'x5']])
check('2nd sign-up on another referee\'s network with allowance 1 -> flagged', ref('x6') and ref('x6')[0][2] == 'flagged' and 'Sign-up #2 from one network' in ref('x6')[0][3], ref('x6'))
setting('referrals.ip_allowance', '3')
check('advertiser referred by an advertiser recorded', ref('v1') and ref('v1')[0][:3] == [str(r2), 'advertiser', 'active'], ref('v1'))
check('advertiser referred by an earner recorded', ref('v2') and ref('v2')[0][:3] == [str(r1), 'advertiser', 'active'], ref('v2'))
check('new users get their own referral code', one(f"SELECT referral_code IS NOT NULL FROM users WHERE id = {ids['x1']}") == '1')
check('referrer cannot be changed after sign-up (one referral per referee, enforced by the database)',
      not db_try(f"INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used) VALUES ({r2}, {ids['x1']}, 'earner', 'X')"))
check('sign-up audited as referral.recorded / referral.flagged', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'referral.recorded' AND actor_user_id = {ids['x1']}") == '1'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'referral.flagged' AND actor_user_id = {ids['x0']}") == '1')
s, l, h = R1.get('/referrals.php')
check("referrer's page lists referees with masked names only", 'Xo*** O.' in h and 'Xola One' not in h and ee['x1'] not in h and 'Under review' in h)

X = {}
for k in ['x0', 'x0b', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6']:
    X[k] = Client(E, UA_OTHER)
    X[k].login(ee[k], PW)
V1, V2 = Client(A, UA_OTHER), Client(A, UA_OTHER)
check('referred users sign in', V1.login(ee['v1'], PW) and V2.login(ee['v2'], PW) and all(X[k].get('/dashboard.php')[0] == 200 for k in X))

# ======================================================================
# 4. Admin milestones
# ======================================================================
s, l, h = admin.get('/referrals.php')
check('admin referrals page shows settings, milestones, flagged sign-ups and payouts', s == 200 and 'Programme settings' in h and 'Earner milestones' in h
      and 'Flagged sign-ups' in h and 'Referral payouts' in h and 'name="commission_percent"' in h and 'value="5"' in h)
check('admin sees the flag reasons', 'Same device and network as the referrer' in h and ee['x0'] in h)
tok = admin.csrf(h)


def ms_save(count, reward, mid=0, active=True, t=None):
    d = {'_csrf': t or tok, 'action': 'milestone_save', 'id': mid, 'task_count': str(count), 'reward': str(reward)}
    if active:
        d['active'] = '1'
    return admin.post('/referrals.php', d)


def ms_id(count, reward):
    v = one(f"SELECT id FROM referral_milestones WHERE task_count = {count} AND reward_kobo = {reward * 100} ORDER BY id DESC LIMIT 1")
    return int(v) if v else None


ms_save(1, 50, t='bad')
check('adding a milestone without CSRF refused', ms_id(1, 50) is None or one(f"SELECT COUNT(*) FROM referral_milestones WHERE task_count = 1 AND is_active = 1") == '0')
ms_save(1, 50)
ms_save(3, 80)
m1, m3 = ms_id(1, 50), ms_id(3, 80)
check('admin adds milestones 1 task = ₦50 and 3 tasks = ₦80', m1 and m3 and one(f"SELECT is_active FROM referral_milestones WHERE id = {m1}") == '1')
ms_save(1, 60)
msg, _ = admin.flash('/referrals.php')
check('second active milestone at the same count refused', 'already pays at 1' in msg and one("SELECT COUNT(*) FROM referral_milestones WHERE task_count = 1 AND is_active = 1") == '1', msg)
ms_save(0, 10)
msg, _ = admin.flash('/referrals.php')
check('milestone of 0 tasks refused', 'between 1 and 100,000' in msg, msg)
m25 = int(one("SELECT id FROM referral_milestones WHERE task_count = 25 AND reward_kobo = 20000 AND is_active = 1 LIMIT 1"))
ms_save(25, 250, mid=m25)
check('admin edits a milestone reward', one(f"SELECT reward_kobo FROM referral_milestones WHERE id = {m25}") == '25000')
ms_save(25, 200, mid=m25)
check('unlimited milestones: five or more can exist', int(one("SELECT COUNT(*) FROM referral_milestones")) >= 4)

# ======================================================================
# 5. Every approval path pays the milestone once
# ======================================================================
follow_id = int(one("SELECT id FROM task_types WHERE slug = 'follow'"))
ig_id = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))


def fund(u, naira, why='Phase 6 test funding'):
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': u, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': str(naira), 'reason': why})


def campaign(client, title, slug, qty, approve=True, extra=None):
    s, l, h = client.get('/campaign-new.php')
    data = {'_csrf': client.csrf(h), 'title': title, 'platform_id': ig_id, 'task_type_id': follow_id,
            'link_url': f'https://instagram.com/{slug}_{tag}', 'instructions': f'Follow {slug} with your own account and keep following.',
            'proof_requirements': 'Screenshot showing you follow the page.', 'quantity': str(qty), 'ends_at': ''}
    data.update(extra or {})
    s, l, b = client.post('/campaign-new.php', data)
    cid = int(l.split('=')[1])
    s, l, h = client.get(f'/campaign.php?id={cid}')
    client.post(f'/campaign.php?id={cid}', {'_csrf': client.csrf(h), 'campaign_id': cid, 'action': 'submit'})
    if approve:
        s, l, h = admin.get('/campaigns.php')
        admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cid, 'action': 'approve', 'back': 'campaigns.php'})
    return cid


def start(ec, cid):
    s, l, h = ec.get(f'/task.php?id={cid}')
    ec.post(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'start'})


def submit(ec, cid):
    start(ec, cid)
    s, l, h = ec.get(f'/task.php?id={cid}')
    s, l, b = ec.upload(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'submit', 'comment': 'Done'}, {'proof': ('p.png', image(), 'image/png')})
    return int(l.split('=')[1]) if l and 'submission.php?id=' in l else None


def adv_decide(client, cid, sid, action, reason=''):
    s, l, h = client.get(f'/campaign.php?id={cid}')
    return client.post(f'/campaign.php?id={cid}', {'_csrf': client.csrf(h), 'campaign_id': cid, 'action': action, 'submission_id': sid, 'reason': reason})


fund(advid, 3000)
c1 = campaign(adv, 'Follow Bola P6 one', 'bolap6a', 10)
c2 = campaign(adv, 'Follow Bola P6 two', 'bolap6b', 10)
c3 = campaign(adv, 'Follow Bola P6 three', 'bolap6c', 10)
check('three live campaigns', one(f"SELECT COUNT(*) FROM campaigns WHERE id IN ({c1}, {c2}, {c3}) AND status = 'live'") == '3')
check('advertiser inbox: campaign approved', any(n[0] == 'campaigns' and n[1].startswith('Campaign approved') and n[3] == f'campaign.php?id={c1}' for n in notes(advid)))
r1_start, bonus_start = balance(r1), sysbal('system:referral_bonus')

# advertiser approval
sx1 = submit(X['x1'], c1)
adv_decide(adv, c1, sx1, 'approve')
check('advertiser approval: referee X1 approved', one(f"SELECT status FROM submissions WHERE id = {sx1}") == 'approved')
check('advertiser approval pays R1 the 1-task milestone (₦50) from system:referral_bonus', balance(r1) - r1_start == 5000 and sysbal('system:referral_bonus') - bonus_start == 5000, (balance(r1) - r1_start))
rid1 = one(f"SELECT id FROM referrals WHERE referee_user_id = {ids['x1']}")
check('payout recorded once with its idempotency key', one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {ids['x1']} AND milestone_id = {m1} AND status = 'paid'") == '1'
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'ref-milestone:{rid1}:{m1}'") == '1')
check('X1 inbox: proof approved (inbox only, never emailed)', any(n[0] == 'tasks' and n[1].startswith('Proof approved') for n in notes(ids['x1']))
      and not any('approved' in m['subject'].lower() for m in mails_to(ee['x1'])))
check('R1 inbox + email: referral bonus', any(n[0] == 'rewards' and 'Referral bonus' in n[1] and n[3] == 'referrals.php' for n in notes(r1))
      and any('referral bonus' in m['subject'] for m in mails_to(em['r1'])))
adv_decide(adv, c1, sx1, 'approve')
cron('hourly.php')
php = ("define('YOLOTASK_APP_ROLE','cron');define('YOLOTASK_APP_ROOT',$argv[1]);define('YOLOTASK_NO_SESSION',true);require $argv[1].'/shared/bootstrap.php';"
       "echo YoloTask\\Growth\\Referrals::afterApproval(YoloTask\\Core\\Database::connection(), (int)$argv[2]);")
outp = subprocess.run(['php', '-r', php, ROOT, str(ids['x1'])], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)).stdout
check('double approve, cron sweep and a direct re-run pay nothing more (idempotent)', balance(r1) - r1_start == 5000 and outp == '0', (balance(r1) - r1_start, outp))

# auto-approval
sx2 = submit(X['x2'], c1)
db(f"UPDATE submissions SET auto_approve_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {sx2}")
r = cron('every-fifteen-minutes.php')
check('auto-approval (cron) pays the milestone for X2', one(f"SELECT decision_source FROM submissions WHERE id = {sx2}") == 'auto' and balance(r1) - r1_start == 10000, (r.stdout[-300:], balance(r1) - r1_start))

# admin approval
sx3 = submit(X['x3'], c1)
s, l, h = admin.get('/submissions.php')
admin.post('/submissions.php', {'_csrf': admin.csrf(h), 'submission_id': sx3, 'action': 'approve', 'back': '?status=pending'})
check('admin approval pays the milestone for X3', one(f"SELECT decision_source FROM submissions WHERE id = {sx3}") == 'admin' and balance(r1) - r1_start == 15000, balance(r1) - r1_start)

# reviewer (stale queue)
sx4 = submit(X['x4'], c1)
db(f"UPDATE submissions SET submitted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = {sx4}")
s, l, h = rev.get('/stale.php')
rev.post('/stale.php', {'_csrf': rev.csrf(h), 'submission_id': sx4, 'action': 'claim'})
s, l, h = rev.get('/stale.php')
rev.post('/stale.php', {'_csrf': rev.csrf(h), 'submission_id': sx4, 'action': 'approve'})
check('reviewer stale-queue approval pays the milestone for X4', one(f"SELECT decision_source FROM submissions WHERE id = {sx4}") == 'reviewer' and balance(r1) - r1_start == 20000, balance(r1) - r1_start)

# appeal overturn
sx5 = submit(X['x5'], c1)
adv_decide(adv, c1, sx5, 'reject', 'Screenshot does not show the follow')
check('X5 inbox + email: proof not accepted', any(n[0] == 'tasks' and n[1].startswith('Proof not accepted') for n in notes(ids['x5'])) and any('not accepted' in m['subject'] for m in mails_to(ee['x5'])))
s, l, h = X['x5'].get(f'/submission.php?id={sx5}')
X['x5'].upload(f'/submission.php?id={sx5}', {'_csrf': X['x5'].csrf(h), 'submission_id': sx5, 'action': 'appeal', 'reason': 'I followed the page exactly as asked; the screenshot shows it.'}, {})
ap5 = int(one(f"SELECT id FROM submission_appeals WHERE submission_id = {sx5}"))
check('X5 inbox: appeal received', any(n[0] == 'appeals' and n[1].startswith('Appeal received') for n in notes(ids['x5'])))
s, l, h = rev.get(f'/appeal.php?id={ap5}')
rev.post(f'/appeal.php?id={ap5}', {'_csrf': rev.csrf(h), 'appeal_id': ap5, 'action': 'claim', 'note': ''})
s, l, h = rev.get(f'/appeal.php?id={ap5}')
rev.post(f'/appeal.php?id={ap5}', {'_csrf': rev.csrf(h), 'appeal_id': ap5, 'action': 'overturn', 'note': 'Proof matches the instructions.'})
check('appeal overturn pays the milestone for X5', one(f"SELECT status FROM submission_appeals WHERE id = {ap5}") == 'overturned' and balance(r1) - r1_start == 25000, balance(r1) - r1_start)
check('X5 inbox + email: appeal successful; advertiser inbox: rejection overturned',
      any(n[0] == 'appeals' and n[1].startswith('Appeal successful') for n in notes(ids['x5'])) and any('successful' in m['subject'] for m in mails_to(ee['x5']))
      and any(n[0] == 'appeals' and n[1].startswith('Rejection overturned') for n in notes(advid)))

# flagged referrals pay nothing until approved; rejected never
sx0 = submit(X['x0'], c1)
adv_decide(adv, c1, sx0, 'approve')
sx0b = submit(X['x0b'], c1)
adv_decide(adv, c1, sx0b, 'approve')
cron('hourly.php')
check('flagged referees earn the referrer nothing (even after the sweep)', balance(r1) - r1_start == 25000 and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id IN ({ids['x0']}, {ids['x0b']})") == '0')
rid0 = int(one(f"SELECT id FROM referrals WHERE referee_user_id = {ids['x0']}"))
rid0b = int(one(f"SELECT id FROM referrals WHERE referee_user_id = {ids['x0b']}"))
s, l, h = admin.get('/referrals.php')
tok = admin.csrf(h)
admin.post('/referrals.php', {'_csrf': 'bad', 'action': 'referral_review', 'id': rid0, 'decision': 'approve', 'note': 'Checked: siblings'})
check('reviewing a flagged referral without CSRF refused', one(f"SELECT status FROM referrals WHERE id = {rid0}") == 'flagged')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'referral_review', 'id': rid0, 'decision': 'approve', 'note': ''})
check('review needs a note', one(f"SELECT status FROM referrals WHERE id = {rid0}") == 'flagged')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'referral_review', 'id': rid0, 'decision': 'approve', 'note': 'Checked: siblings in one house'})
check('admin approves a flagged referral: earned milestone paid at once', one(f"SELECT status FROM referrals WHERE id = {rid0}") == 'active' and balance(r1) - r1_start == 30000, balance(r1) - r1_start)
admin.post('/referrals.php', {'_csrf': tok, 'action': 'referral_review', 'id': rid0b, 'decision': 'reject', 'note': 'Same person, second account'})
cron('hourly.php')
check('rejected referral never pays', one(f"SELECT status FROM referrals WHERE id = {rid0b}") == 'rejected' and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referral_id = {rid0b}") == '0')
check('review decisions audited', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key IN ('admin.referral_approved', 'admin.referral_rejected') AND target_id IN ({rid0}, {rid0b})") == '2')

# stacking: X1 reaches 3 approved -> 3-task milestone, 1-task not paid twice
sx1b, sx1c = submit(X['x1'], c2), submit(X['x1'], c3)
adv_decide(adv, c2, sx1b, 'approve')
adv_decide(adv, c3, sx1c, 'approve')
check('milestones stack: X1 at 3 approvals pays the 3-task milestone (₦80) too', balance(r1) - r1_start == 38000 and
      one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {ids['x1']} AND status = 'paid'") == '2', balance(r1) - r1_start)
check('each milestone paid once per referee (unique)', one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {ids['x1']} AND milestone_id = {m1}") == '1')

# admin adds a milestone later -> sweep pays referees already past it
ms_save(2, 30)
m2 = ms_id(2, 30)
check('new 2-task milestone not paid until the sweep runs', balance(r1) - r1_start == 38000)
r = cron('hourly.php')
check('hourly sweep pays the new milestone to X1 only (others have 1 approval)', re.search(r'catch-up paid [1-9]\d* milestone', r.stdout) and balance(r1) - r1_start == 41000
      and one(f"SELECT COUNT(*) FROM referral_payouts WHERE milestone_id = {m2} AND referrer_user_id = {r1}") == '1', (r.stdout, balance(r1) - r1_start))

# disabled milestones are not paid; only approvals after the referral count
ms_save(2, 30, mid=m2, active=False)
sx3b = submit(X['x3'], c2)
adv_decide(adv, c2, sx3b, 'approve')
cron('hourly.php')
check('disabled milestone not paid (X3 at 2 approvals)', balance(r1) - r1_start == 41000 and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {ids['x3']}") == '1')
ms_save(2, 30, mid=m2, active=True)
db(f"UPDATE submissions SET decided_at = DATE_SUB((SELECT created_at FROM referrals WHERE referee_user_id = {ids['x3']}), INTERVAL 1 DAY) WHERE id = {sx3}")
cron('hourly.php')
check('approvals before the referral do not count toward milestones', balance(r1) - r1_start == 41000)
db(f"UPDATE submissions SET decided_at = UTC_TIMESTAMP() WHERE id = {sx3}")
cron('hourly.php')
check('re-enabled milestone caught up once X3 qualifies', balance(r1) - r1_start == 44000, balance(r1) - r1_start)
ok, out = reconcile_ok()
check('reconciliation OK after milestone payouts', ok, out)

# ======================================================================
# 6. Approval reversed (admin override) -> flag, no claw-back; admin reversal
# ======================================================================
s, l, h = admin.get(f'/appeals.php?id={ap5}')
admin.post(f'/appeals.php?id={ap5}', {'_csrf': admin.csrf(h), 'appeal_id': ap5, 'action': 'uphold', 'note': 'Admin review: the follow is from another account.', 'back': f'?id={ap5}'})
p5 = db(f"SELECT id, status, flagged_at IS NOT NULL, COALESCE(flag_note, '') FROM referral_payouts WHERE referee_user_id = {ids['x5']}")
check('admin reversal of the X5 approval: submission rejected again', one(f"SELECT status FROM submissions WHERE id = {sx5}") == 'rejected')
check('referral payout NOT clawed back automatically, but flagged with a note', balance(r1) - r1_start == 44000 and p5 and p5[0][1] == 'paid' and p5[0][2] == '1' and 'reversed' in p5[0][3], p5)
s, l, h = admin.get('/referrals.php?f=flagged')
check('admin sees the flagged payout in the Flagged filter', f'#{p5[0][0]} ·' in h and 'An approval was reversed' in h)
pid5 = int(p5[0][0])
tok = admin.csrf(h)
admin.post('/referrals.php?f=flagged', {'_csrf': 'bad', 'action': 'payout_reverse', 'id': pid5, 'reason': 'Referee approval was reversed'})
check('payout reversal without CSRF refused', one(f"SELECT status FROM referral_payouts WHERE id = {pid5}") == 'paid')
admin.post('/referrals.php?f=flagged', {'_csrf': tok, 'action': 'payout_reverse', 'id': pid5, 'reason': 'no'})
check('payout reversal needs a reason', one(f"SELECT status FROM referral_payouts WHERE id = {pid5}") == 'paid')
bonus_before = sysbal('system:referral_bonus')
admin.post('/referrals.php?f=flagged', {'_csrf': tok, 'action': 'payout_reverse', 'id': pid5, 'reason': 'Referee approval was reversed on appeal'})
check('admin reverses the payout in the ledger (referrer -₦50, expense credited back)', one(f"SELECT status FROM referral_payouts WHERE id = {pid5}") == 'reversed'
      and balance(r1) - r1_start == 39000 and bonus_before - sysbal('system:referral_bonus') == 5000
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'ref-reverse:{pid5}'") == '1')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'payout_reverse', 'id': pid5, 'reason': 'Second click on reverse'})
check('reversing twice does nothing', balance(r1) - r1_start == 39000)
check('referrer told about the reversal', any(n[1] == 'Referral payout reversed' for n in notes(r1)))
check('reversed milestone is not paid again by the sweep', cron('hourly.php').returncode == 0 and balance(r1) - r1_start == 39000)
# insufficient balance: referrer spent it
pid3 = int(one(f"SELECT id FROM referral_payouts WHERE referee_user_id = {ids['x3']} AND milestone_id = {m1}"))
keep = balance(r1)
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': r1, 'idem': uuid.uuid4().hex, 'direction': 'debit', 'amount': str(keep / 100), 'reason': 'Test: spend it all'})
s, l, h = admin.get('/referrals.php')
admin.post('/referrals.php', {'_csrf': admin.csrf(h), 'action': 'payout_reverse', 'id': pid3, 'reason': 'Testing an empty wallet'})
msg, _ = admin.flash('/referrals.php')
check('reversal refused when the referrer no longer has the money', one(f"SELECT status FROM referral_payouts WHERE id = {pid3}") == 'paid' and 'no longer has' in msg, msg)
fund(r1, keep / 100, 'Test: restore balance')
ok, out = reconcile_ok()
check('reconciliation OK after flag and reversal', ok, out)

# ======================================================================
# 7. Advertiser deposit commission
# ======================================================================
def topup(client, naira):
    s, l, h = client.get('/topup.php')
    client.post('/topup.php', {'_csrf': client.csrf(h), 'amount': str(naira), 'phone': '08031234567'})
    u = uid(ee['v1']) if client is V1 else (uid(ee['v2']) if client is V2 else r2)
    return db(f"SELECT order_sn, platform_osn, id FROM payment_orders WHERE user_id = {u} ORDER BY id DESC LIMIT 1")[0]


r2_start, comm_start = balance(r2), sysbal('system:referral_commission')
o1 = topup(V1, 2000)
s, l, b = collection_cb(o1[0], o1[1], '2000.00')
check('V1 top-up callback credited (HTTP 200 SUCCESS)', s == 200 and b == 'SUCCESS' and balance(ids['v1']) == 200000)
check('referrer R2 earns 5% (₦100) from system:referral_commission', balance(r2) - r2_start == 10000 and sysbal('system:referral_commission') - comm_start == 10000, balance(r2) - r2_start)
row = db(f"SELECT kind, base_kobo, rate_bps, amount_kobo, status FROM referral_payouts WHERE payment_order_id = {o1[2]}")
check('commission payout row: base ₦2,000, 500 bps, ₦100, paid', row == [['commission', '200000', '500', '10000', 'paid']], row)
s, l, b = collection_cb(o1[0], o1[1], '2000.00')
check('double callback: acknowledged, no second credit and no second commission', s == 200 and b == 'SUCCESS' and balance(ids['v1']) == 200000 and balance(r2) - r2_start == 10000
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'ref-commission:{o1[2]}'") == '1')
check('V1 inbox + email: top-up credited (once)', len([n for n in notes(ids['v1'], 'payments') if n[1].startswith('Top-up credited')]) == 1
      and len([m for m in mails_to(ee['v1']) if 'funded' in m['subject']]) == 1)
check('R2 inbox + email: referral commission', any(n[0] == 'rewards' and 'Referral commission' in n[1] for n in notes(r2)) and any('commission' in m['subject'] for m in mails_to(em['r2'])))
import threading
oc = topup(V1, 400)
r2_mid = balance(r2)
ths = [threading.Thread(target=lambda: collection_cb(oc[0], oc[1], '400.00')) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
check('three simultaneous callbacks: one credit, one commission (₦20)', balance(r2) - r2_mid == 2000 and one(f"SELECT COUNT(*) FROM referral_payouts WHERE payment_order_id = {oc[2]}") == '1'
      and len([n for n in notes(r2) if 'Referral commission' in n[1] and '₦20.00' in n[1]]) == 1, balance(r2) - r2_mid)
r2_start += 2000
fund(ids['v1'], 500, 'Goodwill credit')
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': ids['v1'], 'idem': uuid.uuid4().hex, 'direction': 'bonus', 'amount': '300', 'reason': 'Launch week bonus'})
cron('hourly.php')
check('admin wallet credit and admin bonus earn no commission', balance(r2) - r2_start == 10000 and payouts(r2, kind='commission') == 2)
check('admin bonus booked from system:bonuses, audited, user notified', one(f"SELECT COUNT(*) FROM ledger_transactions WHERE type = 'bonus' AND created_by_user_id = {admid}") == '1'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.bonus_granted' AND actor_user_id = {admid}") == '1'
      and any(n[0] == 'rewards' and n[1].startswith('Bonus:') for n in notes(ids['v1'])) and balance(ids['v1']) == 320000)

s, l, h = admin.get('/referrals.php')
tok = admin.csrf(h)
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '60'})
check('commission above 50% refused', one("SELECT setting_value FROM settings WHERE setting_key = 'referrals.commission_bps'") == '500')
admin.post('/referrals.php', {'_csrf': 'bad', 'action': 'settings', 'enabled': '1', 'commission_percent': '10'})
check('settings change without CSRF refused', one("SELECT setting_value FROM settings WHERE setting_key = 'referrals.commission_bps'") == '500')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '2.5'})
check('decimal rate stored in basis points (2.5% = 250)', one("SELECT setting_value FROM settings WHERE setting_key = 'referrals.commission_bps'") == '250')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '10'})
o2 = topup(V1, 1000)
collection_cb(o2[0], o2[1], '1000.00')
check('new rate (10%) applies to the next deposit: R2 +₦100', balance(r2) - r2_start == 20000 and one(f"SELECT rate_bps FROM referral_payouts WHERE payment_order_id = {o2[2]}") == '1000')

# reviewed (amount mismatch) deposit is still a real deposit
o3 = topup(V1, 1000)
collection_cb(o3[0], o3[1], '1000.00', paid='800.00')
check('mismatched payment held for review: no credit, no commission yet', one(f"SELECT status FROM payment_orders WHERE id = {o3[2]}") == 'review' and balance(r2) - r2_start == 20000)
rvid = int(one(f"SELECT id FROM payment_reviews WHERE payment_order_id = {o3[2]}"))
s, l, h = admin.get('/payments.php')
admin.post('/payments.php', {'_csrf': admin.csrf(h), 'review_id': rvid, 'action': 'credit', 'note': 'Customer paid 800; credit what was paid'})
check('admin review-credit of an AfrStar payment earns commission on the credited ₦800 (10% = ₦80)', balance(r2) - r2_start == 28000
      and one(f"SELECT base_kobo FROM referral_payouts WHERE payment_order_id = {o3[2]}") == '80000', balance(r2) - r2_start)

# earner referrer of an advertiser
r1_before = balance(r1)
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '5'})
o4 = topup(V2, 500)
collection_cb(o4[0], o4[1], '500.00')
check("an earner can refer an advertiser: R1's wallet +₦25 (5% of ₦500)", balance(r1) - r1_before == 2500, balance(r1) - r1_before)

# 0% -> skipped row, nothing moves
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '0'})
o5 = topup(V1, 200)
collection_cb(o5[0], o5[1], '200.00')
check('0% commission: payout row "skipped", no ledger posting', db(f"SELECT status, amount_kobo, ledger_transaction_id IS NULL FROM referral_payouts WHERE payment_order_id = {o5[2]}") == [['skipped', '0', '1']]
      and balance(r2) - r2_start == 28000)

# programme off -> nothing; on again -> catch-up pays at the then-current rate
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'commission_percent': '5'})
check('admin switches the programme off', one("SELECT setting_value FROM settings WHERE setting_key = 'referrals.enabled'") == '0')
o6 = topup(V1, 1000)
collection_cb(o6[0], o6[1], '1000.00')
cron('hourly.php')
check('while off: deposit credited but no commission', balance(r2) - r2_start == 28000 and one(f"SELECT COUNT(*) FROM referral_payouts WHERE payment_order_id = {o6[2]}") == '0')
s, l, h = Client(E).get(f'/register.php?ref={code1}')
check('while off: register page shows no invite', 'You were invited' not in h)
admin.post('/referrals.php', {'_csrf': tok, 'action': 'settings', 'enabled': '1', 'commission_percent': '5'})
r = cron('hourly.php')
check('switched back on: hourly catch-up pays the missed commission (₦50)', balance(r2) - r2_start == 33000 and 'commission' in r.stdout, (r.stdout, balance(r2) - r2_start))
ok, out = reconcile_ok()
check('reconciliation OK after commissions', ok, out)
vf = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', 'yt'], stdin=open(ROOT + '/database/verify/referral_award_reconciliation.sql'), capture_output=True, text=True)
check('database/verify/referral_award_reconciliation.sql returns no problems', vf.returncode == 0 and vf.stdout.strip() == '', vf.stdout[:300] + vf.stderr[:200])
pidc = int(one(f"SELECT id FROM referral_payouts WHERE payment_order_id = {o1[2]}"))
db(f"UPDATE referral_payouts SET amount_kobo = amount_kobo + 1 WHERE id = {pidc}")
ok, out = reconcile_ok()
check('tampered payout detected by reconciliation (cron exit 2)', not ok and 'referral' in out.lower(), out[-300:])
db(f"UPDATE referral_payouts SET amount_kobo = amount_kobo - 1 WHERE id = {pidc}")
s, l, h = R2.get('/referrals.php')
check("R2's page shows commission earnings and the referee's deposits", 'Ve*** V.' in h and '₦350.00' in h and 'confirmed top-up' in h, re.findall(r'class="amount">([^<]*)<', h))

# ======================================================================
# 8. Earner levels
# ======================================================================
s, l, h = X['x1'].get('/dashboard.php')
check('earner dashboard shows the level badge and progress', 'level-badge' in h and '>Starter<' in h and 'to reach <strong>Bronze</strong>' in h)
s, l, h = admin.get('/levels.php')
check('admin levels page lists the levels', s == 200 and 'Starter' in h and 'Gold' in h and 'name="max_active"' in h)
bronze = int(one("SELECT id FROM earner_levels WHERE name = 'Bronze' ORDER BY id LIMIT 1"))
starter = int(one("SELECT id FROM earner_levels WHERE name = 'Starter' ORDER BY id LIMIT 1"))
tok = admin.csrf(h)
check('admin levels page has no premium setting', 'name="premium"' not in h and 'premium' not in h.lower())
lv = lambda i, name, a, rate, mx: admin.post('/levels.php', {'_csrf': tok, 'action': 'save', 'id': i, 'name': name, 'min_approved': a, 'min_rate': rate, 'max_active': mx, 'active': '1'})
lv(bronze, 'Bronze', 3, 101, 4)
check('approval rate above 100 refused', one(f"SELECT min_approval_rate FROM earner_levels WHERE id = {bronze}") == '80')
admin.post('/levels.php', {'_csrf': 'bad', 'action': 'save', 'id': bronze, 'name': 'Bronze', 'min_approved': 3, 'min_rate': 80, 'max_active': 4, 'active': '1'})
check('level change without CSRF refused', one(f"SELECT min_approved FROM earner_levels WHERE id = {bronze}") == '50')
lv(bronze, 'Bronze', 3, 80, 4)
lv(starter, 'Starter', 0, 0, 3)
check('admin edits Bronze (3 tasks, 80%, hold 4) and Starter (hold 3)', db(f"SELECT min_approved, min_approval_rate, max_active_reservations FROM earner_levels WHERE id = {bronze}")[0] == ['3', '80', '4']
      and db(f"SELECT min_approved, max_active_reservations FROM earner_levels WHERE id = {starter}")[0] == ['0', '3'])
s, l, h = X['x1'].get('/dashboard.php')
check('X1 (3 approved, 100%) is Bronze now', '>Bronze<' in h and 'hold up to 4 tasks' in h)
cs = [campaign(adv, f'Follow Bola P6 lvl {i}', f'bolap6l{i}', 3) for i in range(5)]
cp = campaign(adv, 'Open follow P6', 'bolap6open', 3, extra={'tier': 'targeted'})
check('a posted tier is ignored: the campaign is standard at the base price', db(f"SELECT tier, status, unit_price_kobo FROM campaigns WHERE id = {cp}")[0] == ['basic', 'live', '2500'])
s, l, h = X['x4'].get('/tasks.php')
check('Starter feed shows every live campaign, no premium badge', 'Open follow P6' in h and 'Follow Bola P6 lvl 4' in h and 'premium' not in h.lower(), ('Open follow P6' in h, 'Follow Bola P6 lvl 4' in h, 'premium' in h.lower()))
s, l, h = X['x1'].get('/tasks.php')
check('Bronze feed shows the same campaigns', 'Open follow P6' in h and 'Follow Bola P6 lvl 4' in h)
for c_ in cs[:3]:
    start(X['x4'], c_)
start(X['x4'], cs[3])
msg, _ = X['x4'].flash('/tasks.php')
check('Starter holds at most 3 tasks', one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {ids['x4']} AND status = 'active' AND expires_at > UTC_TIMESTAMP()") == '3' and 'work on 3 tasks' in msg, msg)
for c_ in cs[:4]:
    start(X['x1'], c_)
check('Bronze holds a 4th task', one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {ids['x1']} AND status = 'active' AND expires_at > UTC_TIMESTAMP()") == '4')
start(X['x1'], cs[4])
msg, _ = X['x1'].flash('/tasks.php')
check('Bronze stops at 4', 'work on 4 tasks' in msg, msg)
# approval rate: a rejection drops X1 to 75% < 80%
sxr = None
s, l, h = X['x1'].get(f'/task.php?id={cs[0]}')
s, l, b = X['x1'].upload(f'/task.php?id={cs[0]}', {'_csrf': X['x1'].csrf(h), 'campaign_id': cs[0], 'action': 'submit', 'comment': 'Done'}, {'proof': ('p.png', image(), 'image/png')})
sxr = int(l.split('=')[1])
adv_decide(adv, cs[0], sxr, 'reject', 'Wrong account in the screenshot')
s, l, h = X['x1'].get('/dashboard.php')
check('approval rate below the level minimum drops X1 back to Starter (3 of 4 = 75%)', '>Starter<' in h and '75% approval rate' in h, re.findall(r'level-badge[^>]*>.*?<span[^>]*>([^<]*)<', h))
setting('levels.enabled', '0')
s, l, h = X['x4'].get('/tasks.php')
check('levels off: the feed is unchanged', 'Open follow P6' in h)
s, l, h = X['x4'].get('/dashboard.php')
check('levels off: no level badge', 'level-badge' not in h)
setting('levels.enabled', '1')
lv(bronze, 'Bronze', 50, 80, 4)
lv(starter, 'Starter', 0, 0, 3)
db("UPDATE task_reservations SET status = 'released' WHERE status = 'active' AND earner_user_id IN (" + ','.join(str(ids[k]) for k in X) + ")")

# ======================================================================
# 8b. Referral allowance per network / device (default 3)
# ======================================================================
setting('referrals.enabled', '1')
setting('referrals.commission_bps', '500')
setting('referrals.ip_allowance', '3')
check('referral IP allowance seeded at 3 by migration 0009', subprocess.run(['grep', '-c', "'referrals.ip_allowance', '3'", ROOT + '/database/migrations/0009_referrals_levels_notifications.sql'], capture_output=True, text=True).stdout.strip() == '1')
em3, em4, em5 = f'p6r3{tag}@gmail.com', f'p6r4{tag}@gmail.com', f'p6r5{tag}@gmail.com'
r3, r4, r5 = make_user('earner', em3, PW, 'Remi Three'), make_user('earner', em4, PW, 'Remi Four'), make_user('advertiser', em5, PW, 'Remi Five Media')
R3, R4, R5 = Client(E), Client(E), Client(A)
check('allowance referrers sign in', R3.login(em3, PW) and R4.login(em4, PW) and R5.login(em5, PW))
code3 = (R3.get('/referrals.php'), one(f"SELECT referral_code FROM users WHERE id = {r3}"))[1]
code4 = (R4.get('/referrals.php'), one(f"SELECT referral_code FROM users WHERE id = {r4}"))[1]
code5 = (R5.get('/referrals.php'), one(f"SELECT referral_code FROM users WHERE id = {r5}"))[1]
UAS = [f'Mozilla/5.0 (Linux; Android 13; TECNO KJ{i}) Chrome/129 Mobile Safari/537.36' for i in range(8)]
ia = {k: f'p6{k}{tag}@gmail.com' for k in ['ia1', 'ia2', 'ia3', 'ia4', 'ia5']}
ia_names = {'ia1': 'Ada Hostel Ventures', 'ia2': 'Bisi Hostel Media', 'ia3': 'Chidi Hostel Stores', 'ia4': 'Dayo Hostel Brands', 'ia5': 'Efe Hostel Foods'}
for i, k in enumerate(['ia1', 'ia2', 'ia3', 'ia4']):  # same network as R3 (127.0.0.1), each on its own phone
    open_register(A, ia_names[k], ia[k], code3, ua=UAS[i])
ok = finish_all()
check('four advertiser sign-ups on the referrer\'s network succeed', all(ok), ok)
iid = {k: uid(v) for k, v in ia.items()}
st = lambda k: (db(f"SELECT status, COALESCE(flag_reason, ''), id FROM referrals WHERE referee_user_id = {iid[k]}") or [['', '', '0']])[0]
check("referred sign-ups 1-3 from the referrer's own IP are accepted (active)", all(st(k)[0] == 'active' for k in ['ia1', 'ia2', 'ia3']), [st(k) for k in ['ia1', 'ia2', 'ia3']])
check('the 4th sign-up from that IP is flagged for admin review', st('ia4')[0] == 'flagged' and "Sign-up #4 from the referrer's network" in st('ia4')[1] and 'allowance is 3' in st('ia4')[1], st('ia4'))


def topup_as(email, naira):
    c = Client(A, UAS[7])
    c.login(email, PW)
    s, l, h = c.get('/topup.php')
    c.post('/topup.php', {'_csrf': c.csrf(h), 'amount': str(naira), 'phone': '08031234567'})
    return db(f"SELECT order_sn, platform_osn, id FROM payment_orders WHERE user_id = {uid(email)} ORDER BY id DESC LIMIT 1")[0]


r3_start = balance(r3)
orders = {}
for k in ['ia1', 'ia2', 'ia3', 'ia4']:
    orders[k] = topup_as(ia[k], 1000)
    collection_cb(orders[k][0], orders[k][1], '1000.00')
check('accepted referees 1-3 pay as usual: 3 x 5% of ₦1,000 = ₦150 to the referrer', balance(r3) - r3_start == 15000
      and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referrer_user_id = {r3} AND status = 'paid'") == '3', balance(r3) - r3_start)
check('the flagged 4th referee earns the referrer nothing yet', one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {iid['ia4']}") == '0')
Referrals_sweep = cron('hourly.php')
check('hourly catch-up does not pay the flagged referee either', one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {iid['ia4']}") == '0' and balance(r3) - r3_start == 15000)

s, l, h = admin.get('/referrals.php')
check('admin Referrals page has the per-network/device allowance setting (3)', 'name="ip_allowance"' in h and re.search(r'name="ip_allowance"[^>]*value="3"', h) is not None and 'allowance of 3 per network or device' in h)
tok = admin.csrf(h)
base_set = {'action': 'settings', 'enabled': '1', 'commission_percent': '5'}
allow = lambda: one("SELECT setting_value FROM settings WHERE setting_key = 'referrals.ip_allowance'")
admin.post('/referrals.php', dict(base_set, _csrf='bad', ip_allowance='5'))
check('allowance change without CSRF refused', allow() == '3')
for bad in ['0', '101', '2.5', 'x', '']:
    admin.post('/referrals.php', dict(base_set, _csrf=tok, ip_allowance=bad))
check('allowance must be a whole number 1-100', allow() == '3')
aud0 = int(one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.referral_ip_allowance_changed' AND actor_user_id = {admid}"))
admin.post('/referrals.php', dict(base_set, _csrf=tok, ip_allowance='5'))
msg, h = admin.flash('/referrals.php')
check('admin raises the allowance to 5 (saved and audited)', allow() == '5' and 'saved' in msg
      and int(one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.referral_ip_allowance_changed' AND actor_user_id = {admid}")) == aud0 + 1, (allow(), msg))
open_register(A, ia_names['ia5'], ia['ia5'], code3, ua=UAS[4])
finish_all()
iid['ia5'] = uid(ia['ia5'])
check('with the limit at 5, the 5th sign-up from that IP is accepted', st('ia5')[0] == 'active', st('ia5'))
check('raising the limit does not change sign-ups already flagged', st('ia4')[0] == 'flagged')
admin.post('/referrals.php', {'_csrf': tok, 'action': 'referral_review', 'id': st('ia4')[2], 'decision': 'approve', 'note': 'Same hostel network, real business'})
check('after admin approval the 4th referee pays the commission it earned (₦50)', st('ia4')[0] == 'active' and balance(r3) - r3_start == 20000
      and one(f"SELECT COUNT(*) FROM referral_payouts WHERE referee_user_id = {iid['ia4']} AND status = 'paid'") == '1', balance(r3) - r3_start)
admin.post('/referrals.php', dict(base_set, _csrf=tok, ip_allowance='3'))
check('admin sets the allowance back to 3', allow() == '3')

# device allowance: one phone, different networks
UA_DEV = 'Mozilla/5.0 (Linux; Android 12; itel A70) Chrome/120 Mobile Safari/537.36'
dv = {k: f'p6{k}{tag}@gmail.com' for k in ['dv1', 'dv2', 'dv3', 'dv4']}
dv_names = {'dv1': 'Femi Shared Phone', 'dv2': 'Gbenga Shared Phone', 'dv3': 'Halima Shared Phone', 'dv4': 'Ifeoma Shared Phone'}


def other_net():
    db(f"UPDATE user_sessions SET ip_hash = SHA2(UUID(), 256) WHERE user_id = {r4}")
    db(f"UPDATE referrals SET signup_ip_hash = SHA2(UUID(), 256) WHERE referrer_user_id = {r4}")


for k in ['dv1', 'dv2', 'dv3', 'dv4']:
    open_register(E, dv_names[k], dv[k], code4, ua=UA_DEV, prep=other_net)
finish_all()
dst = lambda k: (db(f"SELECT r.status, COALESCE(r.flag_reason, '') FROM referrals r JOIN users u ON u.id = r.referee_user_id WHERE u.email = '{dv[k]}'") or [['', '']])[0]
check('sign-ups 1-3 from one device (different networks) accepted', all(dst(k)[0] == 'active' for k in ['dv1', 'dv2', 'dv3']), [dst(k) for k in ['dv1', 'dv2', 'dv3']])
check('the 4th sign-up from that device is flagged', dst('dv4')[0] == 'flagged' and 'Sign-up #4 from one device' in dst('dv4')[1], dst('dv4'))
# the referrer's own device (same device AND network as their session) is flagged on the very first sign-up
own = f'p6own{tag}@gmail.com'
R5ua = R5.ua
open_register(A, 'Jide Own Phone', own, code5, ua=R5ua)
finish_all()
ow = db(f"SELECT r.status, COALESCE(r.flag_reason, '') FROM referrals r WHERE r.referee_user_id = {uid(own)}")
check("first sign-up on the referrer's own device is flagged (likely self-referral), allowance or not", ow and ow[0][0] == 'flagged' and 'self-referral' in ow[0][1], ow)
ok, out = reconcile_ok()
check('reconciliation OK after the allowance payouts', ok, out)

# ======================================================================
# 8c. Interests and premium targeting are retired (migration 0017)
# ======================================================================
check('interest tables left dormant in the database', [one(f"SHOW TABLES LIKE '{t_}'") for t_ in ('interests', 'user_interests', 'campaign_interests')] == ['interests', 'user_interests', 'campaign_interests'])
check('premium columns left dormant in the database', one("SHOW COLUMNS FROM task_types LIKE 'premium_price_kobo'") == 'premium_price_kobo' and one("SHOW COLUMNS FROM campaigns LIKE 'tier'") == 'tier'
      and one("SHOW COLUMNS FROM earner_levels LIKE 'premium_access'") == 'premium_access')
check('no interests page or Interests library in the code', not any(os.path.exists(ROOT + p_) for p_ in ('/apps/earner/public/interests.php', '/apps/admin/public/interests.php', '/apps/advertiser/public/interests.php',
      '/shared/routes/interests.php', '/shared/routes/admin-interests.php', '/shared/lib/Campaigns/Interests.php')))
for who_, cl_, path_ in [('earner', X['x1'], '/interests.php'), ('earner onboarding', X['x1'], '/interests.php?welcome=1'), ('admin', admin, '/interests.php'), ('advertiser', adv, '/interests.php')]:
    s, l, h = cl_.get(path_)
    check(f'{who_} {path_} is 404', s == 404 and 'data-chip-select' not in h, s)
s, l, h = X['x1'].post('/interests.php', {'_csrf': X['x1'].csrf(path='/tasks.php'), 'interests[]': '1'})
check('posting to the old earner interests page is 404 too', s == 404)
emi = {k: f'p6{k}{tag}@gmail.com' for k in ['nz']}
nz = make_user('earner', emi['nz'], PW, 'Zainab None')
NZ = Client(E)
check('earner with no interests signs in', NZ.login(emi['nz'], PW))
for page_ in ('/dashboard.php', '/tasks.php', '/account.php', '/levels.php'):
    s, l, h = NZ.get(page_)
    check(f'earner {page_}: no interest prompt or link, no premium', s == 200 and 'interests.php' not in h and 'data-interest' not in h and 'premium' not in h.lower(), page_)
for page_ in ('/levels.php', '/leaders.php', '/campaigns.php', '/catalog.php'):
    s, l, h = admin.get(page_)
    check(f'admin {page_}: no Interests tab or premium', s == 200 and 'interests.php' not in h and 'premium' not in h.lower(), page_)
s, l, h = adv.get('/campaign-new.php')
check('advertiser campaign form: no tier choice, no interest picker', s == 200 and 'name="tier"' not in h and 'interests[]' not in h and 'data-chip-select' not in h and 'premium' not in h.lower())

# onboarding: confirming the email goes straight to the dashboard
onb = f'p6onb{tag}@gmail.com'
c = Client(E)
s, l, h = c.get('/register.php')
time.sleep(3.2)
db("DELETE FROM rate_limits WHERE bucket = 'register_ip'")
s, l, _ = c.post('/register.php', {'_csrf': c.field(h, '_csrf'), '_stamp': c.field(h, '_stamp'), 'website': '', 'display_name': 'Obi Onboard', 'email': onb, 'password': PW, 'password_confirmation': PW})
m = [x for x in mails_to(onb) if re.search(r'Your code: (\d{6})', x['text'])]
vcode = re.search(r'Your code: (\d{6})', m[-1]['text']).group(1) if m else ''
s, l, h = c.get('/verify.php')
s, l, _ = c.post('/verify.php', {'_csrf': c.csrf(h), 'action': 'code', 'code': vcode})
check('after confirming the email a new earner goes to the dashboard (no interests step)', s == 303 and l == 'dashboard.php', (s, l))

# every campaign is open to every earner; nothing reads or writes interest rows any more
ci_rows = one("SELECT COUNT(*) FROM campaign_interests")
ui_rows = one("SELECT COUNT(*) FROM user_interests")
copen = campaign(adv, 'Everyone follow P6', 'everyone', 3, extra={'tier': 'targeted', 'interests[]': '1'})
check('new campaign: standard, base price, no interest rows written', db(f"SELECT tier, unit_price_kobo, unit_reward_kobo FROM campaigns WHERE id = {copen}")[0] == ['basic', '2500', '1500']
      and one("SELECT COUNT(*) FROM campaign_interests") == ci_rows)
s, l, h = NZ.get('/tasks.php')
check('earner with no interests sees the campaign in the feed', 'Everyone follow P6' in h)
s, l, h = NZ.get(f'/task.php?id={copen}')
check('task page: reward shown, Start enabled, no premium badge or interest block', '+₦15.00' in h and 'value="start"' in h and re.search(r'value="start" disabled', h) is None and 'data-interest' not in h and 'premium' not in h.lower())
held = lambda u, c_: one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c_} AND earner_user_id = {u} AND status = 'active'")
start(NZ, copen)
check('earner with no interests reserves it, at the campaign price', held(nz, copen) == '1'
      and db(f"SELECT unit_price_kobo, unit_reward_kobo FROM task_reservations WHERE campaign_id = {copen} AND earner_user_id = {nz}")[0] == ['2500', '1500'])
s, l, h = adv.get(f'/campaign.php?id={copen}')
check('advertiser campaign page: no audience interests card, no premium', s == 200 and 'Audience interests' not in h and 'value="interests"' not in h and 'premium' not in h.lower())
adv.post(f'/campaign.php?id={copen}', {'_csrf': adv.csrf(h), 'campaign_id': copen, 'action': 'interests', 'interests[]': '1'})
s, l, h = admin.get(f'/campaign.php?id={copen}')
check('admin campaign page: no interests card, no premium', s == 200 and 'Audience interests' not in h and 'premium' not in h.lower())
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': copen, 'action': 'interests', 'back': f'campaign.php?id={copen}', 'interests[]': '1'})
check('old interest actions on campaign pages do nothing', one("SELECT COUNT(*) FROM campaign_interests") == ci_rows and one("SELECT COUNT(*) FROM user_interests") == ui_rows)
db(f"UPDATE task_reservations SET status = 'released' WHERE status = 'active' AND earner_user_id = {nz}")
admin.post('/campaigns.php', {'_csrf': admin.csrf(path='/campaigns.php'), 'campaign_id': copen, 'action': 'cancel', 'reason': 'Retirement checks done', 'back': 'campaigns.php'})
ok, out = reconcile_ok()
check('reconciliation OK after the retirement checks', ok, out)

# ======================================================================
# 9. Notifications: inbox, bell, read, settings, every category
# ======================================================================
x5 = X['x5']
s, l, h = x5.get('/dashboard.php')
unread5 = int(one(f"SELECT COUNT(*) FROM notifications WHERE user_id = {ids['x5']} AND read_at IS NULL"))
check('bell shows the unread count in the header', unread5 > 0 and f'data-unread="{unread5}"' in h and 'class="bell-count"' in h, unread5)
s, l, h = x5.get('/notifications.php')
check('inbox lists notifications newest first with categories', s == 200 and 'Appeal decided: rejection upheld' in h and 'Proof not accepted' in h and h.index('Appeal decided') < h.index('Proof not accepted'))
nid = int(one(f"SELECT id FROM notifications WHERE user_id = {ids['x5']} AND title LIKE 'Proof not accepted%' ORDER BY id DESC LIMIT 1"))
s, l, _ = x5.post('/notifications.php', {'_csrf': 'bad', 'action': 'open', 'id': nid})
check('marking read without CSRF refused', one(f"SELECT read_at IS NULL FROM notifications WHERE id = {nid}") == '1')
s, l, _ = x5.post('/notifications.php', {'_csrf': x5.csrf(h), 'action': 'open', 'id': nid})
check('open marks read and goes to the linked page', one(f"SELECT read_at IS NULL FROM notifications WHERE id = {nid}") == '0' and l == f'submission.php?id={sx5}', l)
other = int(one(f"SELECT id FROM notifications WHERE user_id = {r1} AND read_at IS NULL ORDER BY id LIMIT 1"))
x5.post('/notifications.php', {'_csrf': x5.csrf(h), 'action': 'read', 'id': other})
check("cannot mark someone else's notification", one(f"SELECT read_at IS NULL FROM notifications WHERE id = {other}") == '1')
s, l, h = x5.get('/notifications.php?show=unread')
x5.post('/notifications.php', {'_csrf': x5.csrf(h), 'action': 'read_all'})
s, l, h = x5.get('/dashboard.php')
check('mark all read clears the bell', one(f"SELECT COUNT(*) FROM notifications WHERE user_id = {ids['x5']} AND read_at IS NULL") == '0' and 'data-unread="0"' in h and 'class="bell-count"' not in h)

# email switches
s, l, h = X['x2'].get('/notifications.php')
check('email settings list the earner categories (security always on)', 'name="email[]" value="tasks"' in h and 'value="rewards"' in h and 'value="campaigns"' not in h and 'Security' in h)
form = {'_csrf': X['x2'].csrf(h), 'action': 'settings', 'email[]': ['appeals', 'payments', 'rewards']}
X['x2'].post('/notifications.php', form)
check('earner turns off task-result emails', one(f"SELECT email_enabled FROM notification_preferences WHERE user_id = {ids['x2']} AND category = 'tasks'") == '0'
      and one(f"SELECT email_enabled FROM notification_preferences WHERE user_id = {ids['x2']} AND category = 'payments'") == '1')
sx2b = submit(X['x2'], c2)
before = len(mails_to(ee['x2']))
adv_decide(adv, c2, sx2b, 'reject', 'Screenshot is blurry')
check('with email off: rejection still in the inbox, but not emailed', notes(ids['x2'], 'tasks')[-1][1].startswith('Proof not accepted') and len(mails_to(ee['x2'])) == before)
s, l, h = X['x2'].get('/account.php')
X['x2'].post('/account.php', {'_csrf': X['x2'].csrf(h), 'action': 'password', 'current_password': PW, 'new_password': PW + 'X', 'new_password_confirmation': PW + 'X'})
check('security email (password changed) is always sent', len(mails_to(ee['x2'])) == before + 1 and 'password' in mails_to(ee['x2'])[-1]['subject'].lower())
s, l, h = adv.get('/notifications.php')
check('advertiser settings include campaign reviews, not task results', 'value="campaigns"' in h and 'value="tasks"' not in h)
s, l, h = rev.get('/notifications.php')
check('reviewer portal has no notification inbox', not os.path.exists(ROOT + '/apps/reviewer/public/notifications.php') and 'Email settings' not in h)

# campaign rejected
crj = campaign(adv, 'Rejected P6 campaign', 'bolap6rej', 2, approve=False)
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': crj, 'action': 'reject', 'reason': 'Link is not a public page', 'back': 'campaigns.php'})
check('advertiser inbox + email: campaign not approved', any(n[0] == 'campaigns' and n[1].startswith('Campaign not approved') for n in notes(advid))
      and any('not approved' in m['subject'] for m in mails_to(em['adv'])))

# withdrawals: declined, paid, failed (earner X1, funded by an admin bonus)
s, l, h = admin.get('/wallets.php')
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': ids['x1'], 'idem': uuid.uuid4().hex, 'direction': 'bonus', 'amount': '5000', 'reason': 'Top earner of the week'})
check('earner bonus credited from system:bonuses', any(n[1] == 'Bonus: ₦5,000.00' for n in notes(ids['x1'])))
x1 = X['x1']
s, l, h = x1.get('/bank-account.php')
x1.post('/bank-account.php', {'_csrf': x1.csrf(h), 'bank_code': '80000009', 'account_number': '0123456789', 'account_name': 'Xola One', 'password': PW})


def withdraw(naira):
    s, l, h = x1.get('/withdraw.php')
    s, l, h = x1.post('/withdraw.php', {'_csrf': x1.csrf(h), 'idem': x1.field(h, 'idem'), 'action': 'preview', 'amount': str(naira)})
    x1.post('/withdraw.php', {'_csrf': x1.csrf(h), 'idem': x1.field(h, 'idem'), 'action': 'confirm', 'amount': x1.field(h, 'amount'), 'quoted_fee': x1.field(h, 'quoted_fee')})
    return int(one(f"SELECT id FROM withdrawals WHERE user_id = {ids['x1']} ORDER BY id DESC LIMIT 1") or 0)


w1 = withdraw(1000)
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'reject', 'withdrawal_id': w1, 'reason': 'Please confirm your account name'})
check('earner inbox + email: withdrawal declined', w1 and any(n[0] == 'payments' and n[1] == 'Withdrawal declined' for n in notes(ids['x1'])) and any('declined' in m['subject'] for m in mails_to(ee['x1'])))
w2 = withdraw(1000)
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': w2})
wr = db(f"SELECT order_sn, platform_osn FROM withdrawals WHERE id = {w2}")[0]
payout_cb(wr[0], wr[1], '900.00', 1)  # ₦1,000 less the default 10% fee
check('earner inbox: withdrawal paid', any(n[0] == 'payments' and n[1].startswith('Withdrawal paid') for n in notes(ids['x1'])), db(f"SELECT status FROM withdrawals WHERE id = {w2}"))
w3 = withdraw(1000)
s, l, h = admin.get('/withdrawals.php')
admin.post('/withdrawals.php', {'_csrf': admin.csrf(h), 'action': 'approve', 'withdrawal_id': w3})
wr = db(f"SELECT order_sn, platform_osn FROM withdrawals WHERE id = {w3}")[0]
payout_cb(wr[0], wr[1], '900.00', 9)
payout_cb(wr[0], wr[1], '900.00', 9)
check('earner inbox: withdrawal failed (once, despite a repeated callback)', len([n for n in notes(ids['x1']) if n[1].startswith('Withdrawal failed')]) == 1)

# pruning
db(f"INSERT INTO notifications (user_id, category, title, body, read_at, created_at) VALUES ({ids['x1']}, 'tasks', 'Old read', 'x', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 95 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 100 DAY)),"
   f" ({ids['x1']}, 'tasks', 'Old unread', 'x', NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 100 DAY)), ({ids['x1']}, 'tasks', 'Recent read', 'x', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))")
r = cron('daily.php')
check('daily cron prunes read notifications older than 90 days only', 'old read notifications: ' in r.stdout
      and [x[0] for x in db(f"SELECT title FROM notifications WHERE user_id = {ids['x1']} AND title IN ('Old read', 'Old unread', 'Recent read') ORDER BY title")] == ['Old unread', 'Recent read'], r.stdout[-400:])

# push driver stays off without config
php = ("define('YOLOTASK_APP_ROLE','cron');define('YOLOTASK_APP_ROOT',$argv[1]);define('YOLOTASK_NO_SESSION',true);require $argv[1].'/shared/bootstrap.php';"
       "echo YoloTask\\Messaging\\Push::isEnabled() ? 'on' : 'off';")
check('OneSignal push disabled when config-private/notifications.php is absent', subprocess.run(['php', '-r', php, ROOT], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)).stdout == 'off')
open(os.path.join(CFG, 'notifications.php'), 'w').write("<?php return ['onesignal' => ['enabled' => true, 'app_id' => 'app', 'api_key' => 'key', 'api_url' => 'http://127.0.0.1:9/none']];")
out = subprocess.run(['php', '-r', php, ROOT], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG)).stdout
os.remove(os.path.join(CFG, 'notifications.php'))
check('push driver switches on only with a complete config file', out == 'on', out)

# ======================================================================
# 10. Announcements
# ======================================================================
s, l, h = admin.get('/announcements.php')
check('admin announcements page loads', s == 200 and 'New announcement' in h and 'name="audience"' in h)
tok = admin.csrf(h)
admin.post('/announcements.php', {'_csrf': 'bad', 'action': 'create', 'title': 'Nope', 'body': 'Nope', 'audience': 'all', 'starts_at': '', 'ends_at': ''})
check('announcement without CSRF refused', one("SELECT COUNT(*) FROM announcements WHERE title = 'Nope'") == '0')
t_all, t_earn, t_later = f'Maintenance {tag}', f'Earner bonus week {tag}', f'Scheduled {tag}'
admin.post('/announcements.php', {'_csrf': tok, 'action': 'create', 'title': t_all, 'body': 'Short maintenance on Sunday at 2 AM.', 'audience': 'all', 'starts_at': '', 'ends_at': ''})
admin.post('/announcements.php', {'_csrf': tok, 'action': 'create', 'title': t_earn, 'body': 'Double referral bonuses this week.', 'audience': 'earner', 'starts_at': '', 'ends_at': ''})
later = time.strftime('%Y-%m-%dT%H:%M', time.gmtime(time.time() + 3600 * 3))  # Lagos = UTC+1, so 2 h ahead
admin.post('/announcements.php', {'_csrf': tok, 'action': 'create', 'title': t_later, 'body': 'Coming soon.', 'audience': 'all', 'starts_at': later, 'ends_at': ''})
admin.post('/announcements.php', {'_csrf': tok, 'action': 'create', 'title': 'Bad window', 'body': 'x y z', 'audience': 'all', 'starts_at': later, 'ends_at': time.strftime('%Y-%m-%dT%H:%M', time.gmtime(time.time() + 3600))})
check('end before start refused', one("SELECT COUNT(*) FROM announcements WHERE title = 'Bad window'") == '0')
a_all = int(one(f"SELECT id FROM announcements WHERE title = '{t_all}'"))
a_earn = int(one(f"SELECT id FROM announcements WHERE title = '{t_earn}'"))
a_later = int(one(f"SELECT id FROM announcements WHERE title = '{t_later}'"))
check('scheduled start stored in UTC (Lagos input - 1 h)', one(f"SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), starts_at) FROM announcements WHERE id = {a_later}") in [str(m) for m in range(117, 121)])
s, l, h = X['x3'].get('/dashboard.php')
check('earner sees both live banners', t_all in h and t_earn in h and 'data-announcement-dismiss' in h and t_later not in h)
s, l, h = adv.get('/dashboard.php')
check('advertiser sees the all-users banner only', t_all in h and t_earn not in h)
check('inbox copies: everyone for "all", earners only for the earner one', one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_all}' AND user_id IN ({ids['x3']}, {advid})") == '2'
      and one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_earn}' AND user_id = {advid}") == '0'
      and one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_earn}' AND user_id = {ids['x3']}") == '1'
      and one(f"SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.dedupe_key = 'ann:{a_all}' AND u.role IN ('admin', 'reviewer')") == '0')
check('scheduled announcement not delivered yet', one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_later}'") == '0')
check('announcements are never emailed', not any(t_all in m['subject'] or t_all in m['text'] for m in mails_to(ee['x3'])))
db(f"UPDATE announcements SET starts_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {a_later}")
r = cron('every-five-minutes.php')
check('five-minute cron delivers it once it starts', 'announcements: inbox items delivered' in r.stdout and one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_later}' AND user_id = {ids['x3']}") == '1')
cron('every-five-minutes.php')
check('delivery happens once', one(f"SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'ann:{a_later}' AND user_id = {ids['x3']}") == '1')
s, l, h = admin.get('/announcements.php')
admin.post('/announcements.php', {'_csrf': admin.csrf(h), 'action': 'withdraw', 'id': a_earn})
s, l, h = X['x3'].get('/dashboard.php')
check('withdrawn announcement banner disappears', t_earn not in h and t_all in h)
for a_ in (a_all, a_later):
    s, l, h = admin.get('/announcements.php')
    admin.post('/announcements.php', {'_csrf': admin.csrf(h), 'action': 'withdraw', 'id': a_})

# ======================================================================
# 11. Authorization
# ======================================================================
s, l, h = Client(ADM).get('/referrals.php')
check('admin referral page needs an admin session', s == 303 and l == 'login.php')
check('an earner cannot sign in to the admin portal', not Client(ADM).login(ee['x1'], PW))
s, l, h = Client(E).get('/notifications.php')
check('inbox needs a session', s == 303 and l == 'login.php')
s, l, h = X['x1'].get('/levels.php')
# Phase 7: earner levels.php is the read-only earner levels page (no admin editor).
check('earner portal has no admin growth pages', not os.path.exists(ROOT + '/apps/earner/public/announcements.php') and 'Earner levels' not in h
      and 'name="min_approved"' not in h and 'name="action" value="save"' not in h)
s, l, h = R1.get('/referrals.php')
check("referrer page never shows another referrer's referees", 'Ve*** V.' not in h and 'Vi*** M.' in h)
s, l, h = R2.get('/referrals.php')
check("R2 does not see R1's referees", 'Xo*** O.' not in h)

ok, out = reconcile_ok()
check('final reconciliation OK', ok, out)

# restore defaults for other suites / re-runs
for mid in (m1, m2, m3):
    if mid:
        db(f"UPDATE referral_milestones SET is_active = 0 WHERE id = {mid}")
setting('referrals.commission_bps', '500')
setting('referrals.enabled', '1')
setting('levels.enabled', '1')

passed = sum(1 for _, ok_ in results if ok_)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
