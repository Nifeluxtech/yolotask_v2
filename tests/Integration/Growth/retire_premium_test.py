#!/usr/bin/env python3
"""YOLOTASK V2 retire-premium test (migration 0017 + scripts/retire-premium.php).

Sets up premium ("targeted") campaigns the way the previous release left
them (tier = 'targeted', premium price/reward, budget = premium x quantity,
funded and approved through the normal flow, interest rows attached) in
every unfinished state (draft, pending_approval, live with work in flight,
paused with every slot held) plus a finished one, then runs the script.

Covers: dry run changes nothing; the refund rule (premium - base for every
untaken slot, Dr campaign escrow / Cr advertiser wallet, ledger key
retire-premium:<id>, refund funding event, refunded_kobo); campaigns become
standard at the base price/reward (repriced_at set, budget kept as funded);
drafts re-quoted with no money moved; finished campaigns untouched;
in-app notification with the refunded amount (draft and no-refund wording);
idempotency (second run: nothing selected, no second posting or
notification, balances unchanged); in-flight work keeps the premium reward
(pending proof, and a reservation made before migration 0017 that is
submitted afterwards); new reservations and submissions pay the base;
a rejected premium proof resubmitted afterwards takes a fresh slot at the
base; escrow covers everything to the last slot (campaign completes with
escrow 0); a level without premium access and an earner without interests
see and reserve a former premium campaign; interest routes are 404 on the
earner and admin portals; no premium copy on the pages involved; the ledger
and campaign reconciliation pass (cron/daily.php).
Custom tasks (migration 0016): a premium campaign on the custom task type is
switched to standard at its own price (no refund, custom wording); a
standard custom-task campaign is never touched; a premium campaign on the
custom platform with a listed task type is repriced like any other; the
custom-task flow (create, fund, approve, reserve, submit, approve) still
works afterwards.

Needs the Phase 6 environment (servers from
tests/Integration/PaymentCallbacks/run-servers.sh, migrations through 0017,
Pillow). Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_DB_CLI.
"""
import io, os, re, shlex, subprocess, sys, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
M, E, A, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405'
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


def escrow(cid):
    return int(one(f"SELECT COALESCE(a.balance_kobo, 0) FROM campaigns c LEFT JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {cid}") or 0)


def crow(cid, cols='tier, status, unit_price_kobo, unit_reward_kobo, budget_kobo, funded_kobo, refunded_kobo, repriced_at IS NOT NULL'):
    return db(f"SELECT {cols} FROM campaigns WHERE id = {cid}")[0]


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


def reconcile_ok():
    r = subprocess.run(['php', ROOT + '/cron/daily.php'], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
    return r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr


def retire(*args):
    return subprocess.run(['php', ROOT + '/scripts/retire-premium.php', '--config-dir=' + CFG, *args], capture_output=True, text=True)


def notes(u):
    return db(f"SELECT category, title, body, url, COALESCE(dedupe_key, '') FROM notifications WHERE user_id = {u} ORDER BY id")


_img_n = [0]


def image():
    _img_n[0] += 1
    im = Image.new('RGB', (400, 700), ((_img_n[0] * 61) % 255, (_img_n[0] * 37) % 255, (_img_n[0] * 83) % 255))
    rnd = os.urandom(400)
    for x in range(0, 400, 5):
        im.putpixel((x, (x * _img_n[0] + 11) % 700), (rnd[x], 255, 255))
    b = io.BytesIO()
    im.save(b, 'PNG')
    return b.getvalue()


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
for k, v in [('tasks.reward_hold_hours', '0'), ('tasks.resubmission_cap', '1'), ('tasks.max_active_reservations', '3'), ('levels.enabled', '1')]:
    setting(k, v)
db("DELETE FROM rate_limits")

# ---------- people
em = {k: f'rp{k}{tag}@gmail.com' for k in ['adv', 'adv2', 'admin', 'e1', 'e2', 'e3', 'e4', 'e5', 'e6']}
advid = make_user('advertiser', em['adv'], PW, 'Bola Legacy Ads')
adv2id = make_user('advertiser', em['adv2'], PW, 'Nifelux Custom Ads')
admid = make_user('admin', em['admin'], PW, 'Grace Admin')
ids = {k: make_user('earner', em[k], PW, f'Earner {k.upper()}') for k in ['e1', 'e2', 'e3', 'e4', 'e5', 'e6']}
adv, admin = Client(A), Client(ADM)
adv2 = Client(A)
X = {k: Client(E) for k in ids}
check('everyone signs in', adv.login(em['adv'], PW) and adv2.login(em['adv2'], PW) and admin.login(em['admin'], PW) and all(X[k].login(em[k], PW) for k in X))

follow_id = int(one("SELECT id FROM task_types WHERE slug = 'follow'"))
ig_id = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))
db(f"UPDATE task_types SET base_price_kobo = 2500, base_reward_kobo = 1500, is_active = 1 WHERE id = {follow_id}")
P, PR, B, BR = 3800, 2300, 2500, 1500  # the follow task's premium (1.5x) and base price / reward
INT1 = one("SELECT id FROM interests ORDER BY id LIMIT 1")
starter = one("SELECT id FROM earner_levels WHERE is_active = 1 ORDER BY min_approved, min_approval_rate, id LIMIT 1")
starter_prem = one(f"SELECT premium_access FROM earner_levels WHERE id = {starter}")
db(f"UPDATE earner_levels SET premium_access = 0 WHERE id = {starter}")  # the old level gate must no longer matter


def fund(u, naira):
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': u, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': str(naira), 'reason': 'Retire premium test funding'})


def legacy_premium(title, slug, qty, state):
    """A campaign as the previous release made it: premium tier, price, reward and budget, interests attached."""
    s, l, h = adv.get('/campaign-new.php')
    s, l, _ = adv.post('/campaign-new.php', {'_csrf': adv.csrf(h), 'title': title, 'platform_id': ig_id, 'task_type_id': follow_id,
                                            'link_url': f'https://instagram.com/{slug}_{tag}', 'instructions': f'Follow {slug} with your own account and keep following.',
                                            'proof_requirements': 'Screenshot showing you follow the page.', 'quantity': str(qty), 'ends_at': ''})
    cid = int(l.split('=')[1])
    db(f"UPDATE campaigns SET tier = 'targeted', unit_price_kobo = {P}, unit_reward_kobo = {PR}, budget_kobo = {P * qty} WHERE id = {cid}")
    db(f"INSERT INTO campaign_interests (campaign_id, interest_id) VALUES ({cid}, {INT1})")
    if state == 'draft':
        return cid
    s, l, h = adv.get(f'/campaign.php?id={cid}')
    adv.post(f'/campaign.php?id={cid}', {'_csrf': adv.csrf(h), 'campaign_id': cid, 'action': 'submit'})
    if state != 'pending_approval':
        s, l, h = admin.get('/campaigns.php')
        admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cid, 'action': 'approve', 'back': 'campaigns.php'})
    return cid


def start(ec, cid):
    s, l, h = ec.get(f'/task.php?id={cid}')
    ec.post(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'start'})


def submit(ec, cid, do_start=True):
    if do_start:
        start(ec, cid)
    s, l, h = ec.get(f'/task.php?id={cid}')
    s, l, b = ec.upload(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'submit', 'comment': 'Done'}, {'proof': ('p.png', image(), 'image/png')})
    return int(l.split('=')[1]) if l and 'submission.php?id=' in l else None


def decide(cid, sid, action, reason=''):
    s, l, h = adv.get(f'/campaign.php?id={cid}')
    return adv.post(f'/campaign.php?id={cid}', {'_csrf': adv.csrf(h), 'campaign_id': cid, 'action': action, 'submission_id': sid, 'reason': reason})


def sub(sid):
    return db(f"SELECT status, unit_price_kobo, unit_reward_kobo FROM submissions WHERE id = {sid}")[0]


fund(advid, 1000)
# A: live, quantity 5: one approved, one pending, one reserved (before 0017, no price on the row), one rejected, one untaken... -> 2 untaken
ca = legacy_premium('Legacy live RP', 'rplive', 5, 'live')
s_e2 = submit(X['e2'], ca)
decide(ca, s_e2, 'approve')
s_e1 = submit(X['e1'], ca)
s_e4 = submit(X['e4'], ca)
decide(ca, s_e4, 'reject', 'Wrong account in the screenshot')
start(X['e3'], ca)
db(f"UPDATE task_reservations SET unit_price_kobo = NULL, unit_reward_kobo = NULL WHERE campaign_id = {ca} AND earner_user_id = {ids['e3']}")  # made by the old release
check('setup A: live premium, 1 approved, 1 pending, 1 rejected, 1 reserved', crow(ca, 'tier, status, approved_count, pending_count, rejected_count')
      == ['targeted', 'live', '1', '1', '1'] and one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {ca} AND status = 'active'") == '1'
      and sub(s_e2) == ['approved', str(P), str(PR)] and sub(s_e1) == ['pending', str(P), str(PR)])
# B: funded, waiting for approval, quantity 2
cb = legacy_premium('Legacy pending RP', 'rppend', 2, 'pending_approval')
# C: draft, quantity 3
cc = legacy_premium('Legacy draft RP', 'rpdraft', 3, 'draft')
# D: paused with its only slot reserved -> nothing untaken, no refund
cd = legacy_premium('Legacy paused RP', 'rppaused', 1, 'live')
start(X['e6'], cd)
s, l, h = adv.get(f'/campaign.php?id={cd}')
adv.post(f'/campaign.php?id={cd}', {'_csrf': adv.csrf(h), 'campaign_id': cd, 'action': 'pause'})
# E: finished (cancelled) premium campaign: left alone
ce = legacy_premium('Legacy cancelled RP', 'rpcancel', 2, 'live')
s, l, h = adv.get(f'/campaign.php?id={ce}')
adv.post(f'/campaign.php?id={ce}', {'_csrf': adv.csrf(h), 'campaign_id': ce, 'action': 'cancel'})
# ---------- custom tasks (migration 0016) alongside, from a second advertiser
CP = int(one("SELECT id FROM platforms WHERE slug = 'custom'"))
CT = int(one("SELECT id FROM task_types WHERE slug = 'custom'"))
for k_, v_ in [('campaigns.custom_enabled', '1'), ('campaigns.custom_min_price_naira', '100'), ('campaigns.custom_reward_percent', '80')]:
    setting(k_, v_)


def custom_campaign(title, slug, qty, extra, premium):
    """Created through the advertiser form, funded and approved; premium ones get tier = 'targeted' like the old release left them."""
    s, l, h = adv2.get('/campaign-new.php')
    data = {'_csrf': adv2.csrf(h), 'title': title, 'platform_id': CP, 'custom_platform_name': 'Nifelux App', 'link_url': f'https://nifelux-{slug}-{tag}.example.com/app',
            'instructions': 'Download the app, create an account and verify your email.', 'proof_requirements': 'Screenshot of your profile page in the app.',
            'quantity': str(qty), 'ends_at': ''}
    data.update(extra)
    s, l, _ = adv2.post('/campaign-new.php', data)
    cid = int(l.split('=')[1])
    if premium:
        if extra.get('task_type_id') == follow_id:
            db(f"UPDATE campaigns SET tier = 'targeted', unit_price_kobo = {P}, unit_reward_kobo = {PR}, budget_kobo = {P * qty} WHERE id = {cid}")
        else:
            db(f"UPDATE campaigns SET tier = 'targeted' WHERE id = {cid}")  # premium never changed a custom task's own price
    s, l, h = adv2.get(f'/campaign.php?id={cid}')
    adv2.post(f'/campaign.php?id={cid}', {'_csrf': adv2.csrf(h), 'campaign_id': cid, 'action': 'submit'})
    s, l, h = admin.get('/campaigns.php')
    admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cid, 'action': 'approve', 'back': 'campaigns.php'})
    return cid


fund(adv2id, 3000)
CUSTOM = {'task_type_id': CT, 'custom_task_name': 'Download and sign up', 'custom_price': '150'}
cf = custom_campaign('Custom legacy RP', 'clegacy', 3, CUSTOM, True)
cg = custom_campaign('Custom standard RP', 'cstd', 3, dict(CUSTOM, custom_task_name='Sign up and verify', custom_price='200'), False)
ch = custom_campaign('Custom site follow RP', 'cfollow', 2, {'task_type_id': follow_id}, True)
s_f = submit(X['e2'], cf)
check('setup: custom-task campaigns live at their own price (₦150 / ₦120, ₦200 / ₦160), premium one tiered, custom site + Follow premium',
      crow(cf, 'tier, status, unit_price_kobo, unit_reward_kobo') == ['targeted', 'live', '15000', '12000']
      and crow(cg, 'tier, status, unit_price_kobo, unit_reward_kobo') == ['basic', 'live', '20000', '16000']
      and crow(ch, 'tier, status, unit_price_kobo, unit_reward_kobo') == ['targeted', 'live', str(P), str(PR)]
      and s_f and sub(s_f) == ['pending', '15000', '12000'], [crow(cf), crow(cg), crow(ch)])
cg_before = crow(cg, '*')
check('setup: pending, draft, paused and cancelled premium campaigns', [crow(c_, 'status')[0] for c_ in (cb, cc, cd, ce)] == ['pending_approval', 'draft', 'paused', 'cancelled'])
ok, out = reconcile_ok()
check('reconciliation OK before the retirement', ok, out[-400:])

# ---------- dry run changes nothing
w0 = balance(advid)
r = retire('--dry-run')
mine = (ca, cb, cc, cd)
keys_sql = "idempotency_key IN (" + ','.join(f"'retire-premium:{c_}'" for c_ in (ca, cb, cc, cd, ce)) + ")"


def line_for(out, cid):
    m_ = re.search(r'^campaign #%d \(([\w, ]+)\): .*$' % cid, out, re.M)
    return m_.group(0) if m_ else ''


check('dry run: exit 0, lists our 4 unfinished campaigns with their refunds (₦26, ₦26, draft, ₦0), not the finished one', r.returncode == 0 and 'dry run, would convert' in r.stdout
      and 'refund ₦26.00' in line_for(r.stdout, ca) and 'refund ₦26.00' in line_for(r.stdout, cb) and '(draft): would convert ₦38.00 -> ₦25.00' in line_for(r.stdout, cc)
      and '0 untaken slot(s), refund ₦0.00' in line_for(r.stdout, cd) and line_for(r.stdout, ce) == '', r.stdout + r.stderr)
check('dry run: nothing changed', balance(advid) == w0 and one(f"SELECT COUNT(*) FROM campaigns WHERE id IN ({ca}, {cb}, {cc}, {cd}) AND tier = 'targeted'") == '4'
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE {keys_sql}") == '0' and [n for n in notes(advid) if n[4].startswith('retire-premium:')] == [])

# ---------- the real run
esc_a = escrow(ca)
r = retire()
check('script: exit 0, converts our 4 campaigns (₦26 + ₦26 refunded), leaves the finished one', r.returncode == 0 and re.search(r'converted \d+ campaign\(s\)', r.stdout)
      and all(line_for(r.stdout, c_) for c_ in mine) and line_for(r.stdout, ce) == '' and 'refund ₦26.00' in line_for(r.stdout, ca) and 'refund ₦26.00' in line_for(r.stdout, cb), r.stdout + r.stderr)
# refund rule: (premium - base) x untaken slots
check('A: 2 untaken slots x (₦38 - ₦25) = ₦26 refunded, escrow -> wallet', balance(advid) - w0 == 5200 and esc_a - escrow(ca) == 2600, (balance(advid) - w0, esc_a - escrow(ca)))
check('A: standard at the base price/reward, budget kept as funded, refunded_kobo, repriced_at', crow(ca) == ['basic', 'live', str(B), str(BR), str(P * 5), str(P * 5), '2600', '1'], crow(ca))
tx = db(f"SELECT t.type, t.amount_kobo, t.reference_type, t.reference_id FROM ledger_transactions t WHERE t.idempotency_key = 'retire-premium:{ca}'")
legs = db(f"""SELECT a.code, a.kind, e.direction, e.amount_kobo FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id JOIN ledger_accounts a ON a.id = e.account_id
              WHERE t.idempotency_key = 'retire-premium:{ca}' ORDER BY e.direction""")
check('A: one balanced campaign_refund posting under retire-premium:<id> (Dr escrow, Cr advertiser wallet)', tx == [['campaign_refund', '2600', 'campaign', str(ca)]]
      and sorted((l_[1], l_[2], l_[3]) for l_ in legs) == [('campaign_escrow', 'debit', '2600'), ('wallet_available', 'credit', '2600')], (tx, legs))
check('A: refund funding event recorded', db(f"SELECT event, amount_kobo FROM campaign_funding_events WHERE campaign_id = {ca} AND event = 'refund'") == [['refund', '2600']])
check('A: the old reservation was stamped with the premium price', db(f"SELECT unit_price_kobo, unit_reward_kobo FROM task_reservations WHERE campaign_id = {ca} AND earner_user_id = {ids['e3']}")[0] == [str(P), str(PR)])
check('A: approved and pending proofs keep the premium price', sub(s_e2) == ['approved', str(P), str(PR)] and sub(s_e1) == ['pending', str(P), str(PR)])
check('B: pending approval: 2 x ₦13 = ₦26 refunded, still waiting for approval at the base price', crow(cb) == ['basic', 'pending_approval', str(B), str(BR), str(P * 2), str(P * 2), '2600', '1']
      and escrow(cb) == P * 2 - 2600, crow(cb))
check('C: draft re-quoted at the base price, no money moved', crow(cc) == ['basic', 'draft', str(B), str(BR), str(B * 3), '0', '0', '0'], crow(cc))
check('D: paused with every slot held: standard, no refund', crow(cd) == ['basic', 'paused', str(B), str(BR), str(P), str(P), '0', '1']
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'retire-premium:{cd}'") == '0', crow(cd))
check('E: finished campaign untouched', crow(ce, 'tier, status, unit_price_kobo, repriced_at IS NULL') == ['targeted', 'cancelled', str(P), '1'])
check('interest rows left dormant (not deleted)', one(f"SELECT COUNT(*) FROM campaign_interests WHERE campaign_id IN ({ca}, {cb}, {cc}, {cd}, {ce})") == '5')
nn = {n[4]: n for n in notes(advid) if n[4].startswith('retire-premium:')}
check('A notification: in-app, campaigns, links the campaign, says standard + ₦26.00 refunded', nn.get(f'retire-premium:{ca}') is not None
      and nn[f'retire-premium:{ca}'][0] == 'campaigns' and nn[f'retire-premium:{ca}'][3] == f'campaign.php?id={ca}'
      and nn[f'retire-premium:{ca}'][2] == 'Premium targeting has been retired; your campaign now runs as standard and ₦26.00 was refunded to your wallet.', nn.get(f'retire-premium:{ca}'))
check('B notification: ₦26.00 refunded', 'standard and ₦26.00 was refunded to your wallet' in nn.get(f'retire-premium:{cb}', ['', '', ''])[2])
check('C notification: draft now standard at ₦25.00 per task', 'your draft now runs as standard at ₦25.00 per task' in nn.get(f'retire-premium:{cc}', ['', '', ''])[2])
check('D notification: no refund (every slot taken or in progress)', 'No refund was due because every slot was already taken or in progress' in nn.get(f'retire-premium:{cd}', ['', '', ''])[2])
check('no notification for the finished campaign; 4 in total', f'retire-premium:{ce}' not in nn and len(nn) == 4)
def mails_to(to):
    try:
        import json
        return [json.loads(l_) for l_ in open('/tmp/ytrt/logs/mail.log') if json.loads(l_)['to'] == to]
    except FileNotFoundError:
        return []


# ---------- custom tasks after the retirement
w2_after = balance(adv2id)
check('custom task (premium): standard at its own price, nothing repriced, no refund, no ledger posting', crow(cf) == ['basic', 'live', '15000', '12000', '45000', '45000', '0', '0']
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'retire-premium:{cf}'") == '0' and 'custom task' in line_for(r.stdout, cf) and 'refund ₦0.00' in line_for(r.stdout, cf), (crow(cf), line_for(r.stdout, cf)))
check('custom task (premium): its pending proof keeps ₦150 / ₦120', sub(s_f) == ['pending', '15000', '12000'])
check('custom task (standard): never selected, row unchanged, no notification', line_for(r.stdout, cg) == '' and crow(cg, '*') == cg_before
      and [n for n in notes(adv2id) if n[4] == f'retire-premium:{cg}'] == [])
check('custom site + Follow (premium): repriced to the Follow base, 2 x ₦13 refunded to the second advertiser',
      crow(ch) == ['basic', 'live', str(B), str(BR), str(P * 2), str(P * 2), '2600', '1'] and db(f"SELECT type, amount_kobo FROM ledger_transactions WHERE idempotency_key = 'retire-premium:{ch}'") == [['campaign_refund', '2600']], crow(ch))
nn2 = {n[4]: n for n in notes(adv2id) if n[4].startswith('retire-premium:')}
check('custom task notification: standard at your own price, nothing else changes', nn2.get(f'retire-premium:{cf}', ['', '', ''])[2]
      == 'Premium targeting has been retired; your campaign now runs as standard at your own price of ₦150.00 per task. Nothing else changes.', nn2.get(f'retire-premium:{cf}'))
check('custom site + Follow notification: ₦26.00 refunded; 2 notifications for the second advertiser', 'standard and ₦26.00 was refunded' in nn2.get(f'retire-premium:{ch}', ['', '', ''])[2] and len(nn2) == 2)
check('no email for the retirement (in-app only)', not any('retired' in (m_['subject'] + m_['text']).lower() for m_ in mails_to(em['adv'])))
ok, out = reconcile_ok()
check('reconciliation OK right after the retirement', ok, out[-400:])

# ---------- idempotency
w1 = balance(advid)
r2 = retire()
check('second run: exit 0, nothing to convert', r2.returncode == 0 and 'converted 0 campaign(s), refunded ₦0.00 in total.' in r2.stdout, r2.stdout + r2.stderr)
check('second run: no second posting, refund or notification', balance(advid) == w1 and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE {keys_sql}") == '2'
      and len([n for n in notes(advid) if n[4].startswith('retire-premium:')]) == 4 and crow(ca, 'refunded_kobo')[0] == '2600')
# even if someone flips A back to premium by hand, a rerun cannot refund twice: the escrow no longer holds the difference
db(f"UPDATE campaigns SET tier = 'targeted', unit_price_kobo = {P}, unit_reward_kobo = {PR} WHERE id = {ca}")
r3 = retire()
check('a manual revert + rerun refunds nothing more (escrow cap) and adds no notification', r3.returncode == 0 and 'refund ₦0.00' in line_for(r3.stdout, ca) and balance(advid) == w1
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE {keys_sql}") == '2' and crow(ca, 'tier, unit_price_kobo, refunded_kobo') == ['basic', str(B), '2600']
      and len([n for n in notes(advid) if n[4].startswith('retire-premium:')]) == 4, r3.stdout + r3.stderr)
check('nothing left to convert', 'converted 0 campaign(s)' in retire().stdout)

# ---------- after the retirement: feed, reservations, rewards
s, l, h = X['e5'].get('/tasks.php')
check('former premium campaign in the feed of a Starter earner (premium access off) with no interests, base reward shown', f'task.php?id={ca}"' in h and '+₦15.00' in h
      and 'premium' not in h.lower() and one(f"SELECT COUNT(*) FROM user_interests WHERE user_id = {ids['e5']}") == '0')
s, l, h = X['e3'].get(f'/task.php?id={ca}')
check('the earner holding a pre-retirement slot still sees the premium reward (₦23)', '+₦23.00' in h and 'premium' not in h.lower())
s, l, h = X['e3'].get('/tasks.php')
check('…also in their In progress list', '+₦23.00' in h)
s_e3 = submit(X['e3'], ca, do_start=False)
check('reserved before the retirement, submitted after: premium price and reward kept', s_e3 and sub(s_e3) == ['pending', str(P), str(PR)], sub(s_e3) if s_e3 else None)
start(X['e5'], ca)
check('new reservation: base price recorded', db(f"SELECT unit_price_kobo, unit_reward_kobo FROM task_reservations WHERE campaign_id = {ca} AND earner_user_id = {ids['e5']}")[0] == [str(B), str(BR)])
s_e5 = submit(X['e5'], ca, do_start=False)
check('new submission pays the base', s_e5 and sub(s_e5) == ['pending', str(B), str(BR)])
s, l, h = X['e4'].get(f'/submission.php?id={s_e4}')
X['e4'].upload(f'/submission.php?id={s_e4}', {'_csrf': X['e4'].csrf(h), 'submission_id': s_e4}, {'proof': ('r.png', image(), 'image/png')})
check('rejected premium proof resubmitted after the retirement takes a fresh slot at the base', sub(s_e4) == ['pending', str(B), str(BR)], sub(s_e4))
check('every slot now taken', crow(ca, 'approved_count, pending_count')[0:2] == ['1', '4'])
bal = {k: balance(ids[k]) for k in ids}
for sid in (s_e1, s_e3, s_e4, s_e5):
    decide(ca, sid, 'approve')
check('approvals pay what each proof was made at (₦23, ₦23, ₦15, ₦15)', [balance(ids[k]) - bal[k] for k in ('e1', 'e3', 'e4', 'e5')] == [PR, PR, BR, BR],
      [balance(ids[k]) - bal[k] for k in ('e1', 'e3', 'e4', 'e5')])
check('campaign A completes with its escrow exactly used up', crow(ca, 'status, approved_count')[0:2] == ['completed', '5'] and escrow(ca) == 0, (crow(ca, 'status, approved_count'), escrow(ca)))
check('A spent = 3 x ₦38 + 2 x ₦25; funded = spent + refunded', crow(ca, 'spent_kobo, funded_kobo, refunded_kobo') == [str(3 * P + 2 * B), str(5 * P), '2600'])
# B approved later runs at the base for every slot
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cb, 'action': 'approve', 'back': 'campaigns.php'})
s_b = submit(X['e1'], cb)
check('B (approved after the retirement): proofs at the base', s_b and sub(s_b) == ['pending', str(B), str(BR)])
# D: the held slot keeps the premium reward after resuming
s, l, h = adv.get(f'/campaign.php?id={cd}')
adv.post(f'/campaign.php?id={cd}', {'_csrf': adv.csrf(h), 'campaign_id': cd, 'action': 'resume'})
s_d = submit(X['e6'], cd, do_start=False)
check('D: slot held through the retirement keeps the premium reward', s_d and sub(s_d) == ['pending', str(P), str(PR)])
decide(cd, s_d, 'approve')
check('D completes with escrow 0', crow(cd, 'status')[0] == 'completed' and escrow(cd) == 0)

# ---------- pages: no premium or interests anywhere involved
s, l, h = adv.get(f'/campaign.php?id={ca}')
check('advertiser campaign page: no premium or interest info, refund in the funding history', s == 200 and 'premium' not in h.lower() and 'Audience interests' not in h and '₦26.00' in h)
s, l, h = admin.get(f'/campaign.php?id={ca}')
check('admin campaign page: no premium badge or interests', s == 200 and 'badge-premium' not in h and 'Audience interests' not in h and 'Interests:' not in h)
s, l, h = admin.get('/campaigns.php?status=all')
check('admin campaign list: no premium badge or interest line', 'badge-premium' not in h and 'data-interests' not in h)
for who_, cl_, path_ in [('earner', X['e5'], '/interests.php'), ('earner', X['e5'], '/interests.php?welcome=1'), ('admin', admin, '/interests.php'), ('advertiser', adv, '/interests.php')]:
    s, l, h = cl_.get(path_)
    check(f'{who_} {path_} is 404', s == 404, s)
s, l, h = X['e5'].post('/interests.php', {'_csrf': X['e5'].csrf(), 'interests[]': INT1})
check('POST to the old interests page is 404 and saves nothing', s == 404 and one(f"SELECT COUNT(*) FROM user_interests WHERE user_id = {ids['e5']}") == '0')

# ---------- the custom-task flow still works after the retirement
s, l, h = adv2.get('/campaign-new.php')
check('campaign form: custom fields present, no tier choice or interest picker', s == 200 and 'name="custom_price"' in h and 'name="custom_task_name"' in h
      and 'name="tier"' not in h and 'interests[]' not in h and 'premium' not in h.lower())
cn = custom_campaign('Custom after RP', 'cafter', 2, dict(CUSTOM, custom_task_name='Install and rate', custom_price='300'), False)
check('new custom-task campaign after the retirement: own price ₦300, earner ₦240, standard, live', crow(cn, 'tier, status, unit_price_kobo, unit_reward_kobo') == ['basic', 'live', '30000', '24000'])
s, l, h = X['e5'].get('/tasks.php')
check('custom-task campaigns in the feed with their own names and rewards', f'task.php?id={cn}"' in h and 'Install and rate' in h and '+₦240.00' in h and f'task.php?id={cf}"' in h)
b5 = balance(ids['e5'])
s_n = submit(X['e5'], cn)
check('custom task: reserved and submitted at its own price', s_n and sub(s_n) == ['pending', '30000', '24000'])
s, l, h = adv2.get(f'/campaign.php?id={cn}')
adv2.post(f'/campaign.php?id={cn}', {'_csrf': adv2.csrf(h), 'campaign_id': cn, 'action': 'approve', 'submission_id': s_n, 'reason': ''})
check('custom task: approval pays the earner ₦240', balance(ids['e5']) - b5 == 24000 and sub(s_n)[0] == 'approved', balance(ids['e5']) - b5)
s, l, h = adv2.get(f'/campaign.php?id={cf}')
adv2.post(f'/campaign.php?id={cf}', {'_csrf': adv2.csrf(h), 'campaign_id': cf, 'action': 'approve', 'submission_id': s_f, 'reason': ''})
check('former premium custom task: pending proof approved at ₦120', sub(s_f)[0] == 'approved')
for c_ in (cf, cg, ch, cn):
    s, l, h = adv2.get(f'/campaign.php?id={c_}')
    adv2.post(f'/campaign.php?id={c_}', {'_csrf': adv2.csrf(h), 'campaign_id': c_, 'action': 'cancel'})
check('custom campaigns cancelled cleanly (escrow back to 0)', all(escrow(c_) == 0 for c_ in (cf, cg, ch, cn)), [escrow(c_) for c_ in (cf, cg, ch, cn)])

# ---------- tidy and final checks
s, l, h = adv.get(f'/campaign.php?id={cb}')
adv.post(f'/campaign.php?id={cb}', {'_csrf': adv.csrf(h), 'campaign_id': cb, 'action': 'cancel'})
decide(cb, s_b, 'approve')
s, l, h = adv.get(f'/campaign.php?id={cc}')
adv.post(f'/campaign.php?id={cc}', {'_csrf': adv.csrf(h), 'campaign_id': cc, 'action': 'delete'})
db(f"UPDATE earner_levels SET premium_access = {starter_prem} WHERE id = {starter}")
ok, out = reconcile_ok()
check('final reconciliation OK (ledger + campaign escrow)', ok, out[-400:])
vr = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', '-B', 'yt'], input=open(ROOT + '/database/verify/campaign_funding_reconciliation.sql').read(), capture_output=True, text=True)
check('database/verify/campaign_funding_reconciliation.sql returns no problem rows', vr.returncode == 0 and vr.stdout.strip() == '', vr.stdout[:400] + vr.stderr[:200])

passed = sum(1 for _, ok_ in results if ok_)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
