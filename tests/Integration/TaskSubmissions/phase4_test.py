#!/usr/bin/env python3
"""YOLOTASK V2 Phase 4 integration test: catalog and prices, campaigns,
escrow, task feed, reservations, proofs, approvals, auto-approval, reward
hold, cancellation, completion, admin tools, security and reconciliation.

Needs the same environment as tests/Integration/PaymentCallbacks/phase3_test.py
(PHP 8.1+ with pdo_mysql + gd, MariaDB with migrations 0001-0006, private
config dir with app.local.php containing mail driver "log" and
'storage' => ['private_root' => '/tmp/ytrt'], servers from run-servers.sh)
plus Pillow for test images.

Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_STORAGE (default /tmp/ytrt),
YT_MAIL_LOG, YT_DB_CLI.
"""
import io, json, os, re, shlex, subprocess, sys, threading, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
STORAGE = os.environ.get('YT_STORAGE', '/tmp/ytrt')
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')
E, A, ADM = 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405'
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

    def req(self, path, data=None, raw=None, ctype=None, binary=False):
        url = path if path.startswith('http') else self.base + path
        body = raw if raw is not None else (urllib.parse.urlencode(data).encode() if data is not None else None)
        headers = {'User-Agent': 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'}
        if ctype:
            headers['Content-Type'] = ctype
        r = urllib.request.Request(url, data=body, headers=headers)
        try:
            resp = self.op.open(r, timeout=30)
            b = resp.read()
            return resp.status, resp.headers, (b if binary else b.decode(errors='replace'))
        except urllib.error.HTTPError as e:
            b = e.read()
            return e.code, e.headers, (b if binary else b.decode(errors='replace'))

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

    def csrf(self, html):
        return self.field(html, '_csrf')

    def login(self, email, pw):
        s, l, h = self.get('/login.php')
        s, l, _ = self.post('/login.php', {'_csrf': self.csrf(h), 'email': email, 'password': pw})
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


def mails_to(to):
    try:
        return [json.loads(l) for l in open(MAIL) if json.loads(l)['to'] == to]
    except FileNotFoundError:
        return []


def make_user(role, email, pw, verified=True, name=None):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name or role.title() + ' Tester'}', '{email}', '{h}', '{role}', 'active', {'UTC_TIMESTAMP()' if verified else 'NULL'})")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(uid):
    r = db(f"SELECT available_kobo, held_kobo FROM wallets WHERE user_id = {uid}")
    return (int(r[0][0]), int(r[0][1])) if r else (0, 0)


def cron(name):
    return subprocess.run(['php', ROOT + '/cron/' + name], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))


def reconcile_ok():
    r = cron('daily.php')
    return r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr


_img_n = [0]


def image(fmt='PNG', size=(400, 700)):
    _img_n[0] += 1
    im = Image.new('RGB', size, ((_img_n[0] * 37) % 255, (_img_n[0] * 91) % 255, (_img_n[0] * 13) % 255))
    rnd = os.urandom(size[0])
    for x in range(0, size[0], 7):
        im.putpixel((x, (x * _img_n[0]) % size[1]), (rnd[x], 255, 255))  # unique per run (duplicate-proof guard)
    b = io.BytesIO()
    im.save(b, fmt)
    return b.getvalue()


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
adv_email, adv2_email, admin_email = f'p4adv{tag}@gmail.com', f'p4adv2{tag}@gmail.com', f'p4admin{tag}@gmail.com'
e_emails = [f'p4earner{i}{tag}@gmail.com' for i in range(1, 6)]
unv_email = f'p4unv{tag}@gmail.com'
aid, a2id, admid = make_user('advertiser', adv_email, PW, name='Bola Ads'), make_user('advertiser', adv2_email, PW), make_user('admin', admin_email, PW)
eids = [make_user('earner', em, PW, name=f'Ada{i} Earner') for i, em in enumerate(e_emails, 1)]
unvid = make_user('earner', unv_email, PW, verified=False)
setting('tasks.reward_hold_hours', '0')
setting('tasks.resubmission_cap', '1')
setting('tasks.max_active_reservations', '3')

adv, adv2, admin = Client(A), Client(A), Client(ADM)
earners = [Client(E) for _ in eids]
unv = Client(E)
check('advertiser signs in', adv.login(adv_email, PW))
check('second advertiser signs in', adv2.login(adv2_email, PW))
check('admin signs in', admin.login(admin_email, PW))
check('earners sign in', all(c.login(em, PW) for c, em in zip(earners, e_emails)))
check('unverified earner signs in', unv.login(unv_email, PW))
e1, e2, e3, e4, e5 = earners

# ---------- catalog seed and admin pricing
check('8 platforms seeded', one("SELECT COUNT(*) FROM platforms WHERE slug IN ('instagram','tiktok','x','facebook','youtube','whatsapp','telegram','threads')") == '8')
check('follow priced ₦25 / ₦15, premium ₦38 / ₦23', db("SELECT base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo FROM task_types WHERE slug = 'follow'")[0] == ['2500', '1500', '3800', '2300'])
check('watch priced ₦20 / ₦12, premium ₦30 / ₦18', db("SELECT base_price_kobo, base_reward_kobo, premium_price_kobo, premium_reward_kobo FROM task_types WHERE slug = 'watch'")[0] == ['2000', '1200', '3000', '1800'])
s, l, h = admin.get('/catalog.php')
check('admin catalog page lists task types and platforms', s == 200 and 'data-task-type="follow"' in h and 'data-platform="telegram"' in h)
follow_id = int(one("SELECT id FROM task_types WHERE slug = 'follow'"))
like_id = int(one("SELECT id FROM task_types WHERE slug = 'like'"))
comment_id = int(one("SELECT id FROM task_types WHERE slug = 'comment'"))
ig_id = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))
tt_id = int(one("SELECT id FROM platforms WHERE slug = 'tiktok'"))
admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'save_type', 'id': like_id, 'name': 'Like', 'proof_hint': 'x', 'base_price': '10', 'base_reward': '12', 'premium_price': '23', 'premium_reward': '15', 'is_active': '1'})
check('admin cannot set reward above price', one(f"SELECT base_price_kobo FROM task_types WHERE id = {like_id}") == '1500')
admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'save_type', 'id': like_id, 'name': 'Like', 'proof_hint': 'Screenshot of the post showing your like.', 'base_price': '16', 'base_reward': '10', 'premium_price': '24', 'premium_reward': '15', 'is_active': '1'})
check('admin edits like price', one(f"SELECT base_price_kobo FROM task_types WHERE id = {like_id}") == '1600')
admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'save_type', 'id': like_id, 'name': 'Like', 'proof_hint': 'Screenshot of the post showing your like.', 'base_price': '15', 'base_reward': '10', 'premium_price': '23', 'premium_reward': '15', 'is_active': '1'})
admin.post('/catalog.php', {'_csrf': 'bad', 'action': 'save_type', 'id': like_id, 'name': 'Hacked', 'proof_hint': '', 'base_price': '99', 'base_reward': '1', 'premium_price': '99', 'premium_reward': '1', 'is_active': '1'})
check('catalog change without CSRF refused', one(f"SELECT name FROM task_types WHERE id = {like_id}") == 'Like')
s, l, h = admin.get('/catalog.php')
admin.post('/catalog.php', {'_csrf': admin.csrf(h), 'action': 'add_type', 'name': f'Save post {tag}', 'base_price': '30', 'base_reward': '20'})
r = db(f"SELECT premium_price_kobo, premium_reward_kobo, is_active FROM task_types WHERE name = 'Save post {tag}'")
check('admin adds task type with premium default 1.5x', r and r[0] == ['4500', '3000', '1'], r)
# (php -S falls back to index.php for unknown paths; Apache returns 404. Either way no catalog.)
s, l, x = e1.get('/catalog.php')
check('earner cannot open admin catalog', 'data-task-type=' not in x)
s, l, x = adv.get('/catalog.php')
check('advertiser has no catalog page', 'data-task-type=' not in x)

# ---------- fund advertiser via admin adjustment (Phase 3 tool)
s, l, h = admin.get('/wallets.php?q=' + urllib.parse.quote(adv_email))
admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': aid, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': '1000', 'reason': 'Phase 4 test funding'})
check('advertiser wallet funded ₦1,000', balance(aid) == (100000, 0), balance(aid))

# ---------- campaign create validation
s, l, h = adv.get('/campaign-new.php')
check('create page loads with platform + task selects and cost preview', s == 200 and 'name="platform_id"' in h and 'data-cost' in h)
tok = adv.csrf(h)
base = {'_csrf': tok, 'title': 'Follow Bola on Instagram', 'platform_id': ig_id, 'task_type_id': follow_id, 'tier': 'basic',
        'link_url': f'https://www.instagram.com/bola_{tag}/?utm_source=x&igshid=abc', 'instructions': 'Follow the page with your own account.',
        'proof_requirements': 'Screenshot showing you follow the page.', 'quantity': '3', 'ends_at': ''}


def create(client, overrides):
    s, l, h = client.get('/campaign-new.php')
    data = dict(base, _csrf=client.csrf(h))
    data.update(overrides)
    s, l, b = client.post('/campaign-new.php', data)
    return s, l


before = int(one('SELECT COUNT(*) FROM campaigns'))
for label, ov in [('http link rejected', {'link_url': f'http://instagram.com/bola_{tag}'}),
                  ('link on wrong platform rejected', {'link_url': f'https://tiktok.com/@bola_{tag}'}),
                  ('IP address link rejected', {'link_url': 'https://127.0.0.1/x'}),
                  ('link with credentials rejected', {'link_url': f'https://user:pw@instagram.com/bola_{tag}'}),
                  ('javascript link rejected', {'link_url': 'javascript:alert(1)'}),
                  ('quantity 0 rejected', {'quantity': '0'}),
                  ('quantity above 100,000 rejected', {'quantity': '100001'}),
                  ('past end date rejected', {'ends_at': '2020-01-01'}),
                  ('short instructions rejected', {'instructions': 'do it'})]:
    s, l = create(adv, ov)
    check(label, s == 303 and l.startswith('campaign-new.php') and int(one('SELECT COUNT(*) FROM campaigns')) == before)
s, l, h = adv.get('/campaign-new.php')
adv.post('/campaign-new.php', dict(base, _csrf='nope'))
check('create without CSRF refused', int(one('SELECT COUNT(*) FROM campaigns')) == before)
s, l, x = unv.post('/campaign-new.php', dict(base, _csrf='x'))
check('earner cannot create campaigns (no route)', s != 303 and int(one('SELECT COUNT(*) FROM campaigns')) == before)

s, l = create(adv, {})
check('valid draft created', s == 303 and l.startswith('campaign.php?id='), l)
c1 = int(l.split('=')[1])
r = db(f"SELECT status, unit_price_kobo, unit_reward_kobo, budget_kobo, link_normalized, funded_kobo FROM campaigns WHERE id = {c1}")[0]
check('draft priced 3 x ₦25 = ₦75, no money moved', r[:4] == ['draft', '2500', '1500', '7500'] and r[5] == '0' and balance(aid) == (100000, 0), r)
check('link normalised (no www, tracking, slash)', r[4] == f'instagram.com/bola_{tag}', r[4])
check('instructions version 1 recorded', one(f"SELECT COUNT(*) FROM campaign_instructions WHERE campaign_id = {c1} AND version = 1") == '1')
s, l, h = adv2.get(f'/campaign.php?id={c1}')
check("other advertiser cannot see the campaign", s == 404)
s, l, h = adv2.post(f'/campaign.php?id={c1}', {'_csrf': adv2.csrf(adv2.get('/campaigns.php')[2]), 'campaign_id': c1, 'action': 'submit'})
check("other advertiser cannot fund/submit it", one(f"SELECT status FROM campaigns WHERE id = {c1}") == 'draft' and s == 404)

# edit draft then submit (fund)
s, l, h = adv.get(f'/campaign-new.php?id={c1}')
check('draft edit form prefilled', s == 200 and 'Follow Bola on Instagram' in h)
adv.post(f'/campaign-new.php?id={c1}', dict(base, _csrf=adv.csrf(h), quantity='4'))
check('draft edited to 4 slots (budget ₦100)', db(f"SELECT quantity, budget_kobo FROM campaigns WHERE id = {c1}")[0] == ['4', '10000'])
s, l, h = adv.get(f'/campaign.php?id={c1}')
check('draft page offers pay-and-submit', 'Pay ₦100.00 and submit' in h)
tok = adv.csrf(h)
# double submit in parallel: only one funding
outs = []
ths = [threading.Thread(target=lambda: outs.append(adv.post(f'/campaign.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'submit'}))) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
check('funding moved budget to escrow exactly once', balance(aid) == (90000, 0) and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'camp-fund:{c1}'") == '1', balance(aid))
check('campaign pending approval with escrow = budget', db(f"SELECT c.status, c.funded_kobo, a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}")[0] == ['pending_approval', '10000', '10000'])
ok, out = reconcile_ok()
check('reconciliation OK after funding', ok, out)

# duplicate link + task type while open
s, l = create(adv, {'link_url': f'https://instagram.com/BOLA_{tag}'})
check('duplicate open campaign for same link + task refused', s == 303 and l.startswith('campaign-new.php'))
msg, _ = adv.flash('/campaign-new.php')
check('duplicate message explains why', 'already running' in msg, msg)

# insufficient funds
s, l = create(adv, {'title': 'Big one', 'task_type_id': comment_id, 'quantity': '10000', 'link_url': f'https://instagram.com/p/big{tag}'})
big = int(l.split('=')[1])
s, l, h = adv.get(f'/campaign.php?id={big}')
check('draft shows top-up notice when wallet is short', 'to fund this campaign' in h)
adv.post(f'/campaign.php?id={big}', {'_csrf': adv.csrf(h), 'campaign_id': big, 'action': 'submit'})
check('funding refused when wallet is short', one(f"SELECT status FROM campaigns WHERE id = {big}") == 'draft' and balance(aid) == (90000, 0))
s, l, h = adv.get(f'/campaign.php?id={big}')
adv.post(f'/campaign.php?id={big}', {'_csrf': adv.csrf(h), 'campaign_id': big, 'action': 'delete'})
check('draft deleted', one(f"SELECT COUNT(*) FROM campaigns WHERE id = {big}") == '0')

# ---------- feed before approval
s, l, h = e1.get('/tasks.php')
check('earner feed loads (Tasks dock)', s == 200 and 'href="tasks.php"' in h and 'Available tasks' in h)
check('pending-approval campaign not in feed', f'task.php?id={c1}"' not in h)
s, l, h = e1.get(f'/task.php?id={c1}')
check('earner cannot open a campaign that is not live', s == 404)

# ---------- admin approval queue
s, l, h = admin.get('/campaigns.php')
check('admin approval queue lists it', s == 200 and f'data-campaign="{c1}"' in h)
s, l, x = e1.get('/campaigns.php')
check('earner has no campaigns page', 'data-campaign=' not in x and 'Approval queue' not in x)
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c1, 'action': 'approve', 'back': 'campaigns.php'})
check('admin approves -> live', one(f"SELECT status FROM campaigns WHERE id = {c1}") == 'live')
check('advertiser emailed on approval', any('live' in m['subject'] for m in mails_to(adv_email)))

# a second campaign that admin rejects (refund)
s, l = create(adv, {'title': 'TikTok follow', 'platform_id': tt_id, 'link_url': f'https://www.tiktok.com/@bola_{tag}', 'quantity': '2', 'tier': 'targeted'})
c2 = int(l.split('=')[1])
check('targeted campaign uses premium price ₦38', one(f"SELECT unit_price_kobo FROM campaigns WHERE id = {c2}") == '3800')
s, l, h = adv.get(f'/campaign.php?id={c2}')
adv.post(f'/campaign.php?id={c2}', {'_csrf': adv.csrf(h), 'campaign_id': c2, 'action': 'submit'})
check('second campaign funded (₦76)', balance(aid) == (90000 - 7600, 0), balance(aid))
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c2, 'action': 'reject', 'reason': '', 'back': 'campaigns.php'})
check('reject without reason refused', one(f"SELECT status FROM campaigns WHERE id = {c2}") == 'pending_approval')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c2, 'action': 'reject', 'reason': 'Link is a private account', 'back': 'campaigns.php'})
check('admin reject refunds escrow fully', one(f"SELECT status FROM campaigns WHERE id = {c2}") == 'rejected' and balance(aid) == (90000, 0), balance(aid))
check('rejection reason emailed', any('Link is a private account' in m['text'] for m in mails_to(adv_email)))
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c2, 'action': 'reject', 'reason': 'again', 'back': 'campaigns.php'})
check('second reject does not refund twice', balance(aid) == (90000, 0))

# ---------- feed and reservations
s, l, h = e1.get('/tasks.php')
check('live campaign in earner feed with reward ₦15', f'task.php?id={c1}"' in h and '+₦15.00' in h)
s, l, h = e1.get(f'/task.php?id={c1}')
check('task page hides link until started', s == 200 and 'Start task' in h and f'bola_{tag}' not in h.split('What to do')[1])
s, l, h = unv.get(f'/task.php?id={c1}')
unv.post(f'/task.php?id={c1}', {'_csrf': unv.csrf(h), 'campaign_id': c1, 'action': 'start'})
check('unverified earner cannot start', one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {unvid}") == '0')
s, l, h = e1.get(f'/task.php?id={c1}')
e1.post(f'/task.php?id={c1}', {'_csrf': 'bad', 'campaign_id': c1, 'action': 'start'})
check('start without CSRF refused', one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {eids[0]}") == '0')
e1.post(f'/task.php?id={c1}', {'_csrf': e1.csrf(h), 'campaign_id': c1, 'action': 'start'})
check('earner starts task -> active reservation', one(f"SELECT status FROM task_reservations WHERE earner_user_id = {eids[0]} AND campaign_id = {c1}") == 'active')
s, l, h = e1.get(f'/task.php?id={c1}')
check('started task shows link and upload form', f'href="https://www.instagram.com/bola_{tag}/' in h and 'name="proof"' in h and 'enctype="multipart/form-data"' in h)
e1.post(f'/task.php?id={c1}', {'_csrf': e1.csrf(h), 'campaign_id': c1, 'action': 'start'})
check('starting twice keeps one reservation', one(f"SELECT COUNT(*) FROM task_reservations WHERE earner_user_id = {eids[0]}") == '1')

# slot race: 4 slots, e1 holds one; e2..e5 race for the remaining 3
tokens = {}
for c in (e2, e3, e4, e5):
    tokens[c] = c.csrf(c.get(f'/task.php?id={c1}')[2])
ths = [threading.Thread(target=lambda c=c: c.post(f'/task.php?id={c1}', {'_csrf': tokens[c], 'campaign_id': c1, 'action': 'start'})) for c in (e2, e3, e4, e5)]
[t.start() for t in ths]
[t.join() for t in ths]
active = int(one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c1} AND status = 'active'"))
check('slot race: never more reservations than slots (4)', active == 4, active)
loser = [c for c, uid in zip((e2, e3, e4, e5), eids[1:]) if one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {uid} AND status = 'active'") == '0']
check('exactly one earner lost the race', len(loser) == 1, len(loser))
s, l, h = loser[0].get('/tasks.php')
check('full campaign disappears from the loser feed', f'task.php?id={c1}"' not in h)
loser_c = loser[0]
loser_id = eids[[e2, e3, e4, e5].index(loser_c) + 1]
winners = [c for c in (e2, e3, e4, e5) if c is not loser_c]
win_ids = [eids[[e2, e3, e4, e5].index(c) + 1] for c in winners]

# release frees a slot
wc = winners[2]
s, l, h = wc.get(f'/task.php?id={c1}')
wc.post(f'/task.php?id={c1}', {'_csrf': wc.csrf(h), 'campaign_id': c1, 'action': 'release'})
check('release frees the slot', one(f"SELECT status FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {win_ids[2]}") == 'released')
s, l, h = loser_c.get(f'/task.php?id={c1}')
loser_c.post(f'/task.php?id={c1}', {'_csrf': loser_c.csrf(h), 'campaign_id': c1, 'action': 'start'})
check('freed slot can be taken by another earner', one(f"SELECT status FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {loser_id}") == 'active')
# now holders: e1, winners[0], winners[1], loser
holders = [(e1, eids[0]), (winners[0], win_ids[0]), (winners[1], win_ids[1]), (loser_c, loser_id)]

# expiry: force one reservation expired -> slot counts free, cron marks expired
db(f"UPDATE task_reservations SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE campaign_id = {c1} AND earner_user_id = {loser_id}")
s, l, h = loser_c.get(f'/task.php?id={c1}')
check('expired reservation no longer shows upload form', 'name="proof"' not in h and 'Start task' in h)
r = cron('every-minute.php')
check('every-minute cron marks expired reservations', r.returncode == 0 and one(f"SELECT status FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {loser_id}") == 'expired', r.stdout + r.stderr)
s, l, h = loser_c.get(f'/task.php?id={c1}')
tok = loser_c.csrf(h)
s, l, b = loser_c.upload(f'/task.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'submit'}, {'proof': ('p.png', image(), 'image/png')})
check('submit after expiry refused', one(f"SELECT COUNT(*) FROM submissions WHERE earner_user_id = {loser_id}") == '0')
loser_c.post(f'/task.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'start'})
check('earner can restart after expiry when slots free', one(f"SELECT status FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {loser_id}") == 'active')

# ---------- proof upload validation
s, l, h = e1.get(f'/task.php?id={c1}')
tok = e1.csrf(h)
n0 = int(one('SELECT COUNT(*) FROM submissions'))
for label, f in [('text file disguised as png rejected', ('x.png', b'hello world, not an image' * 10, 'image/png')),
                 ('GIF rejected', ('x.gif', image('GIF'), 'image/gif')),
                 ('PHP script named .jpg rejected', ('shell.jpg', b'<?php system($_GET["c"]); ?>' + b' ' * 100, 'image/jpeg')),
                 ('file over 2 MB rejected', ('big.png', b'\x89PNG\r\n\x1a\n' + os.urandom(2 * 1024 * 1024 + 10), 'image/png')),
                 ('tiny image rejected', ('t.png', image('PNG', (20, 20)), 'image/png'))]:
    e1.upload(f'/task.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'submit'}, {'proof': f})
    check(label, int(one('SELECT COUNT(*) FROM submissions')) == n0)
e1.upload(f'/task.php?id={c1}', {'_csrf': 'bad', 'campaign_id': c1, 'action': 'submit'}, {'proof': ('p.png', image(), 'image/png')})
check('upload without CSRF refused', int(one('SELECT COUNT(*) FROM submissions')) == n0)

# polyglot: valid PNG with PHP appended -> accepted but re-encoded (payload stripped)
poly = image() + b'<?php echo "pwned"; ?>'
s, l, b = e1.upload(f'/task.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'submit', 'comment': 'My handle is @ada1'}, {'proof': ('proof.png', poly, 'image/png')})
check('valid proof submitted -> redirect to submission', s == 303 and l and l.startswith('submission.php?id='), (s, l))
s1 = int(l.split('=')[1])
r = db(f"SELECT status, instructions_version, unit_reward_kobo, comment FROM submissions WHERE id = {s1}")[0]
check('submission pending with snapshot reward and instructions version', r == ['pending', '1', '1500', 'My handle is @ada1'], r)
check('campaign pending count 1, reservation submitted', one(f"SELECT pending_count FROM campaigns WHERE id = {c1}") == '1' and one(f"SELECT status FROM task_reservations WHERE campaign_id = {c1} AND earner_user_id = {eids[0]}") == 'submitted')
path = one(f"SELECT storage_path FROM submission_proofs WHERE submission_id = {s1}")
full = os.path.join(STORAGE, 'proofs', path)
check('proof stored under private storage dir with random name', os.path.isfile(full) and re.match(r'^\d{4}/\d{2}/[a-f0-9]{32}\.png$', path), path)
check('stored proof is re-encoded (appended PHP stripped)', b'<?php' not in open(full, 'rb').read())
check('proof file mode 0600', oct(os.stat(full).st_mode & 0o777) == '0o600', oct(os.stat(full).st_mode & 0o777))
check('proof dir is not inside any public root', not os.path.abspath(full).startswith(os.path.join(ROOT, 'apps')))
proof1 = int(one(f"SELECT id FROM submission_proofs WHERE submission_id = {s1}"))

# double submit
e1.upload(f'/task.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'submit'}, {'proof': ('p2.png', image(), 'image/png')})
check('second submission for same campaign refused', one(f"SELECT COUNT(*) FROM submissions WHERE campaign_id = {c1} AND earner_user_id = {eids[0]}") == '1')

# reused screenshot by another earner
cw, cw_id = holders[1]
s, l, h = cw.get(f'/task.php?id={c1}')
cw.upload(f'/task.php?id={c1}', {'_csrf': cw.csrf(h), 'campaign_id': c1, 'action': 'submit'}, {'proof': ('same.png', poly, 'image/png')})
check('same screenshot reused by another earner refused', one(f"SELECT COUNT(*) FROM submissions WHERE earner_user_id = {cw_id}") == '0')
msg, _ = cw.flash(f'/task.php?id={c1}')
check('reuse message shown', 'already been used' in msg, msg)

# ---------- proof access control
s, hd, b = e1.req(f'/proof.php?id={proof1}', binary=True)
check('owner earner can view proof image', s == 200 and hd.get('Content-Type') == 'image/png' and b[:4] == b'\x89PNG' and 'nosniff' in (hd.get('X-Content-Type-Options') or ''))
check('proof response is private + sandboxed', 'private' in (hd.get('Cache-Control') or '') and 'sandbox' in (hd.get('Content-Security-Policy') or ''))
s, hd, b = cw.req(f'/proof.php?id={proof1}', binary=True)
check('another earner cannot view proof (404)', s == 404)
s, hd, b = adv.req(f'/proof.php?id={proof1}', binary=True)
check('campaign advertiser can view proof', s == 200 and b[:4] == b'\x89PNG')
s, hd, b = adv2.req(f'/proof.php?id={proof1}', binary=True)
check('other advertiser cannot view proof', s == 404)
s, hd, b = admin.req(f'/proof.php?id={proof1}', binary=True)
check('admin can view proof', s == 200 and b[:4] == b'\x89PNG')
s, hd, b = Client(E).req(f'/proof.php?id={proof1}', binary=True)
check('signed-out visitor cannot view proof', s == 403)
s, hd, b = e1.req(f'/proof.php?id=999999', binary=True)
check('unknown proof id 404', s == 404)
for p in ('/../../runtime/proofs/' + path, '/proofs/' + path):
    s, hd, b = e1.req(p, binary=True)
    check('proof not reachable by direct URL ' + p[:24], s == 404 or b[:4] != b'\x89PNG')

# earner submission page
s, l, h = e1.get(f'/submission.php?id={s1}')
check('earner submission page shows status and proof', s == 200 and 'Waiting for review' in h and f'proof.php?id={proof1}' in h)
s, l, h = cw.get(f'/submission.php?id={s1}')
check("other earner cannot open someone's submission", s == 404)

# ---------- more submissions from holders
sub_ids = {eids[0]: s1}
for c, uid in holders[1:]:
    s, l, h = c.get(f'/task.php?id={c1}')
    s, l, b = c.upload(f'/task.php?id={c1}', {'_csrf': c.csrf(h), 'campaign_id': c1, 'action': 'submit'}, {'proof': ('p.jpg', image('JPEG'), 'image/jpeg')})
    sub_ids[uid] = int(l.split('=')[1]) if l and 'submission.php' in l else None
check('all 4 holders submitted (JPEG accepted)', all(sub_ids.values()) and one(f"SELECT pending_count FROM campaigns WHERE id = {c1}") == '4', sub_ids)

# ---------- advertiser review
s, l, h = adv.get(f'/campaign.php?id={c1}')
check('advertiser sees 4 to review with proof thumbnails', '4 to review' in h and h.count('class="proof-thumb"') >= 4)
check('advertiser sees first names only (no earner emails)', e_emails[0] not in h and 'Ada1' in h)
tok = adv.csrf(h)
s, l, h2 = adv2.post(f'/campaign.php?id={c1}', {'_csrf': adv2.csrf(adv2.get('/campaigns.php')[2]), 'campaign_id': c1, 'action': 'approve', 'submission_id': s1})
check('other advertiser cannot approve', s == 404 and one(f"SELECT status FROM submissions WHERE id = {s1}") == 'pending')
e1_before = balance(eids[0])
rev_before = int(one("SELECT balance_kobo FROM ledger_accounts WHERE code = 'system:revenue'"))
outs = []
ths = [threading.Thread(target=lambda: outs.append(adv.post(f'/campaign.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'approve', 'submission_id': s1}))) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
check('approve (x3 parallel) pays earner ₦15 once', balance(eids[0])[0] - e1_before[0] == 1500, balance(eids[0]))
check('margin ₦10 posted to revenue once', int(one("SELECT balance_kobo FROM ledger_accounts WHERE code = 'system:revenue'")) - rev_before == 1000)
r = db(f"SELECT spent_kobo, approved_count, pending_count FROM campaigns WHERE id = {c1}")[0]
check('campaign spent ₦25, approved 1, pending 3', r == ['2500', '1', '3'], r)
check('escrow now ₦75', one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}") == '7500')
s, l, h = e1.get('/wallet.php')
check('earner wallet history shows Task reward', 'Task reward' in h)

# reject with reason -> earner emailed, slot returns
s2 = sub_ids[holders[1][1]]
adv.post(f'/campaign.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'reject', 'submission_id': s2, 'reason': ''})
check('reject without reason refused', one(f"SELECT status FROM submissions WHERE id = {s2}") == 'pending')
adv.post(f'/campaign.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'reject', 'submission_id': s2, 'reason': 'Screenshot does not show the follow'})
check('reject stores reason and frees slot', db(f"SELECT status, reject_reason FROM submissions WHERE id = {s2}")[0] == ['rejected', 'Screenshot does not show the follow'] and one(f"SELECT pending_count FROM campaigns WHERE id = {c1}") == '2')
check('escrow unchanged after rejection', one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}") == '7500')
rw, rw_id = holders[1]
check('rejected earner emailed with reason', any('Screenshot does not show the follow' in m['text'] for m in mails_to(e_emails[eids.index(rw_id)])))

# resubmission (cap 1)
s, l, h = rw.get(f'/submission.php?id={s2}')
check('rejected submission offers resubmit', 'Resubmit proof' in h)
rw.upload(f'/submission.php?id={s2}', {'_csrf': rw.csrf(h), 'submission_id': s2}, {'proof': ('r.png', image(), 'image/png')})
r = db(f"SELECT status, attempts FROM submissions WHERE id = {s2}")[0]
check('resubmitted -> pending, attempt 2, second proof stored', r == ['pending', '2'] and one(f"SELECT COUNT(*) FROM submission_proofs WHERE submission_id = {s2}") == '2', r)
adv.post(f'/campaign.php?id={c1}', {'_csrf': tok, 'campaign_id': c1, 'action': 'reject', 'submission_id': s2, 'reason': 'Still wrong account'})
s, l, h = rw.get(f'/submission.php?id={s2}')
check('resubmission cap reached: no resubmit form', 'Resubmit proof' not in h)
rw.upload(f'/submission.php?id={s2}', {'_csrf': rw.csrf(h), 'submission_id': s2}, {'proof': ('r2.png', image(), 'image/png')})
check('resubmission beyond cap refused', one(f"SELECT attempts FROM submissions WHERE id = {s2}") == '2')

# ---------- auto-approve
s3 = sub_ids[holders[2][1]]
db(f"UPDATE submissions SET auto_approve_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {s3}")
w_before = balance(holders[2][1])
r = cron('every-fifteen-minutes.php')
check('15-minute cron auto-approves due submission', r.returncode == 0 and 'auto-approved: 1' in r.stdout and db(f"SELECT status, decision_source FROM submissions WHERE id = {s3}")[0] == ['approved', 'auto'], r.stdout + r.stderr)
check('auto-approval paid the earner', balance(holders[2][1])[0] - w_before[0] == 1500)
r = cron('every-fifteen-minutes.php')
check('second cron run approves nothing more', 'auto-approved: 0' in r.stdout and balance(holders[2][1])[0] - w_before[0] == 1500)
check('auto-approve not before due time', one(f"SELECT status FROM submissions WHERE id = {sub_ids[holders[3][1]]}") == 'pending')
ok, out = reconcile_ok()
check('reconciliation OK mid-campaign', ok, out)

# advertiser settings
s, l, h = adv.get('/campaign-settings.php')
check('advertiser approval settings page shows 48 h default', s == 200 and 'value="48"' in h)
adv.post('/campaign-settings.php', {'_csrf': adv.csrf(h), 'auto_approve_hours': '6'})
check('auto-approve below 12 h refused', one(f"SELECT COUNT(*) FROM advertiser_settings WHERE user_id = {aid}") == '0')
adv.post('/campaign-settings.php', {'_csrf': adv.csrf(h), 'auto_approve_hours': '400'})
check('auto-approve above 336 h refused', one(f"SELECT COUNT(*) FROM advertiser_settings WHERE user_id = {aid}") == '0')
adv.post('/campaign-settings.php', {'_csrf': adv.csrf(h), 'auto_approve_hours': '24'})
check('auto-approve 24 h saved and applied to open campaigns', one(f"SELECT auto_approve_hours FROM advertiser_settings WHERE user_id = {aid}") == '24' and one(f"SELECT auto_approve_hours FROM campaigns WHERE id = {c1}") == '24')

# ---------- instructions versioning
s, l, h = adv.get(f'/campaign.php?id={c1}')
adv.post(f'/campaign.php?id={c1}', {'_csrf': adv.csrf(h), 'campaign_id': c1, 'action': 'instructions', 'instructions': 'Follow the page and turn on notifications.', 'proof_requirements': 'Screenshot showing follow + bell.'})
check('instructions edit creates version 2', one(f"SELECT instructions_version FROM campaigns WHERE id = {c1}") == '2' and one(f"SELECT COUNT(*) FROM campaign_instructions WHERE campaign_id = {c1}") == '2')
check('earlier submissions keep version 1', one(f"SELECT instructions_version FROM submissions WHERE id = {s1}") == '1')

# ---------- pause / resume
s, l, h = adv.get(f'/campaign.php?id={c1}')
adv.post(f'/campaign.php?id={c1}', {'_csrf': adv.csrf(h), 'campaign_id': c1, 'action': 'pause'})
check('advertiser pauses', one(f"SELECT status FROM campaigns WHERE id = {c1}") == 'paused')
s, l, h = e5.get('/tasks.php')
check('paused campaign leaves the feed', f'task.php?id={c1}"' not in h)
s, l, h = adv.get(f'/campaign.php?id={c1}')
adv.post(f'/campaign.php?id={c1}', {'_csrf': adv.csrf(h), 'campaign_id': c1, 'action': 'resume'})
check('advertiser resumes', one(f"SELECT status FROM campaigns WHERE id = {c1}") == 'live')

# ---------- completion: approve remaining pending and fill last slot
s4 = sub_ids[holders[3][1]]
s, l, h = adv.get(f'/campaign.php?id={c1}')
adv.post(f'/campaign.php?id={c1}', {'_csrf': adv.csrf(h), 'campaign_id': c1, 'action': 'approve', 'submission_id': s4})
# now approved 3 (s1, s3, s4); 1 slot left (s2 rejected twice). e5 takes it.
fresh = e5 if e5 not in [c for c, _ in holders] else None
cand = [(c, uid) for c, uid in zip(earners, eids) if uid not in sub_ids]
check('one earner without a submission remains', len(cand) >= 1, cand)
fc, fid = cand[0]
s, l, h = fc.get(f'/task.php?id={c1}')
fc.post(f'/task.php?id={c1}', {'_csrf': fc.csrf(h), 'campaign_id': c1, 'action': 'start'})
s, l, h = fc.get(f'/task.php?id={c1}')
s, l, b = fc.upload(f'/task.php?id={c1}', {'_csrf': fc.csrf(h), 'campaign_id': c1, 'action': 'submit'}, {'proof': ('f.webp', image('WEBP'), 'image/webp')})
s5 = int(l.split('=')[1]) if l and 'submission.php' in l else None
check('last slot filled with a WebP proof', s5 is not None, (s, l))
adv_before = balance(aid)
s, l, h = admin.get('/submissions.php')
check('admin submissions page lists pending with proof', s == 200 and f'data-submission="{s5}"' in h and 'proof.php?id=' in h)
admin.post('/submissions.php', {'_csrf': admin.csrf(h), 'submission_id': s5, 'action': 'approve', 'back': '?status=pending'})
r = db(f"SELECT status, approved_count, spent_kobo, refunded_kobo FROM campaigns WHERE id = {c1}")[0]
check('admin approval of last slot completes campaign (4/4, ₦100 spent)', r == ['completed', '4', '10000', '0'], r)
check('completed campaign escrow is zero', one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}") == '0')
check('no extra refund on exact completion', balance(aid) == adv_before)
s, l, h = rw.get(f'/submission.php?id={s2}')
ok, out = reconcile_ok()
check('reconciliation OK after completion', ok, out)

# ---------- cancellation with pending submission stays reserved
s, l = create(adv, {'title': 'Like my post', 'task_type_id': like_id, 'link_url': f'https://instagram.com/p/like{tag}', 'quantity': '5'})
c3 = int(l.split('=')[1])
s, l, h = adv.get(f'/campaign.php?id={c3}')
adv.post(f'/campaign.php?id={c3}', {'_csrf': adv.csrf(h), 'campaign_id': c3, 'action': 'submit'})
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c3, 'action': 'approve', 'back': 'campaigns.php'})
check('like campaign live, ₦75 escrow (5 x ₦15)', one(f"SELECT CONCAT(status, ':', funded_kobo) FROM campaigns WHERE id = {c3}") == 'live:7500')
for c in (e1, e2):
    s, l, h = c.get(f'/task.php?id={c3}')
    c.post(f'/task.php?id={c3}', {'_csrf': c.csrf(h), 'campaign_id': c3, 'action': 'start'})
s, l, h = e1.get(f'/task.php?id={c3}')
s, l, b = e1.upload(f'/task.php?id={c3}', {'_csrf': e1.csrf(h), 'campaign_id': c3, 'action': 'submit'}, {'proof': ('l.png', image(), 'image/png')})
sp = int(l.split('=')[1])
adv_before = balance(aid)[0]
s, l, h = adv.get(f'/campaign.php?id={c3}')
adv.post(f'/campaign.php?id={c3}', {'_csrf': adv.csrf(h), 'campaign_id': c3, 'action': 'cancel'})
check('cancel refunds ₦60 now (5 slots - 1 pending)', one(f"SELECT status FROM campaigns WHERE id = {c3}") == 'cancelled' and balance(aid)[0] - adv_before == 6000, balance(aid)[0] - adv_before)
check('cancel releases active reservations', one(f"SELECT status FROM task_reservations WHERE campaign_id = {c3} AND earner_user_id = {eids[1]}") == 'released')
check('escrow keeps ₦15 for the pending proof', one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c3}") == '1500')
s, l, h = e2.get(f'/task.php?id={c3}')
check('cancelled campaign no longer open to earners', s == 404)
ok, out = reconcile_ok()
check('reconciliation OK with a cancelled campaign holding pending escrow', ok, out)
s, l, h = adv.get(f'/campaign.php?id={c3}')
adv.post(f'/campaign.php?id={c3}', {'_csrf': adv.csrf(h), 'campaign_id': c3, 'action': 'reject', 'submission_id': sp, 'reason': 'Wrong post liked'})
check('rejecting the last pending proof on a cancelled campaign refunds its ₦15', balance(aid)[0] - adv_before == 7500 and one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c3}") == '0')
check('no resubmit on a cancelled campaign', 'Resubmit proof' not in e1.get(f'/submission.php?id={sp}')[2])
check('refund events recorded', one(f"SELECT COUNT(*) FROM campaign_funding_events WHERE campaign_id = {c3} AND event = 'refund'") == '2')

# ---------- reward hold window + hourly release
setting('tasks.reward_hold_hours', '24')
s, l = create(adv, {'title': 'Comment please', 'task_type_id': comment_id, 'link_url': f'https://instagram.com/p/cmt{tag}', 'quantity': '1'})
c4 = int(l.split('=')[1])
s, l, h = adv.get(f'/campaign.php?id={c4}')
adv.post(f'/campaign.php?id={c4}', {'_csrf': adv.csrf(h), 'campaign_id': c4, 'action': 'submit'})
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c4, 'action': 'approve', 'back': 'campaigns.php'})
s, l, h = e3.get(f'/task.php?id={c4}')
e3.post(f'/task.php?id={c4}', {'_csrf': e3.csrf(h), 'campaign_id': c4, 'action': 'start'})
s, l, h = e3.get(f'/task.php?id={c4}')
s, l, b = e3.upload(f'/task.php?id={c4}', {'_csrf': e3.csrf(h), 'campaign_id': c4, 'action': 'submit'}, {'proof': ('c.png', image(), 'image/png')})
sh = int(l.split('=')[1])
w3 = balance(eids[2])[0]
s, l, h = adv.get(f'/campaign.php?id={c4}')
adv.post(f'/campaign.php?id={c4}', {'_csrf': adv.csrf(h), 'campaign_id': c4, 'action': 'approve', 'submission_id': sh})
check('with 24 h hold, reward goes to reward_hold not wallet', balance(eids[2])[0] == w3 and one(f"SELECT reward_status FROM submissions WHERE id = {sh}") == 'held' and one("SELECT balance_kobo FROM ledger_accounts WHERE code = 'system:reward_hold'") == '2500')
s, l, h = e3.get(f'/submission.php?id={sh}')
check('earner sees reward on hold with release time', 'short hold' in h)
r = cron('hourly.php')
check('hourly before window releases nothing', 'rewards released: 0' in r.stdout and balance(eids[2])[0] == w3)
db(f"UPDATE submissions SET reward_release_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {sh}")
r = cron('hourly.php')
check('hourly releases due reward to wallet', r.returncode == 0 and 'rewards released: 1' in r.stdout and balance(eids[2])[0] - w3 == 2500 and 'reconciliation OK' in r.stdout, r.stdout + r.stderr)
r = cron('hourly.php')
check('second hourly run releases nothing twice', 'rewards released: 0' in r.stdout and balance(eids[2])[0] - w3 == 2500)
setting('tasks.reward_hold_hours', '0')
check('campaign with quantity 1 completed', one(f"SELECT status FROM campaigns WHERE id = {c4}") == 'completed')

# ---------- duplicate target across campaigns hidden for earners who did it
s, l = create(adv, {'title': 'Follow Bola again', 'link_url': f'https://instagram.com/bola_{tag}', 'quantity': '2'})
c5 = int(l.split('=')[1])
s, l, h = adv.get(f'/campaign.php?id={c5}')
adv.post(f'/campaign.php?id={c5}', {'_csrf': adv.csrf(h), 'campaign_id': c5, 'action': 'submit'})
check('same link allowed again once the first campaign closed', one(f"SELECT status FROM campaigns WHERE id = {c5}") == 'pending_approval')
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c5, 'action': 'approve', 'back': 'campaigns.php'})
s, l, h = e1.get('/tasks.php')
check('earner who already followed does not see the repeat campaign', f'task.php?id={c5}"' not in h)
s, l, h = e1.get(f'/task.php?id={c5}')
e1.post(f'/task.php?id={c5}', {'_csrf': e1.csrf(h), 'campaign_id': c5, 'action': 'start'})
check('and cannot start it directly', one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c5} AND earner_user_id = {eids[0]}") == '0')
s, l, h = rw.get('/tasks.php')
check('earner whose proof was rejected can still see it', f'task.php?id={c5}"' in h)

# ---------- end date passes -> ended + refund
db(f"UPDATE campaigns SET ends_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {c5}")
adv_before = balance(aid)[0]
r = cron('every-fifteen-minutes.php')
check('cron ends campaign past its end date and refunds ₦50', 'ended (end date reached): 1' in r.stdout and one(f"SELECT status FROM campaigns WHERE id = {c5}") == 'ended' and balance(aid)[0] - adv_before == 5000, r.stdout)

# ---------- admin cancel of a live campaign
s, l = create(adv, {'title': 'Admin stops this', 'task_type_id': like_id, 'link_url': f'https://instagram.com/p/stop{tag}', 'quantity': '2'})
c6 = int(l.split('=')[1])
s, l, h = adv.get(f'/campaign.php?id={c6}')
adv.post(f'/campaign.php?id={c6}', {'_csrf': adv.csrf(h), 'campaign_id': c6, 'action': 'submit'})
s, l, h = admin.get('/campaigns.php')
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c6, 'action': 'approve', 'back': 'campaigns.php'})
adv_before = balance(aid)[0]
s, l, h = admin.get(f'/campaign.php?id={c6}')
check('admin campaign detail page loads', s == 200 and 'Stop this campaign' in h)
admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': c6, 'action': 'cancel', 'reason': 'Policy violation', 'back': f'campaign.php?id={c6}'})
check('admin cancel refunds unspent ₦30', one(f"SELECT status FROM campaigns WHERE id = {c6}") == 'cancelled' and balance(aid)[0] - adv_before == 3000)

# ---------- admin task settings
s, l, h = admin.get('/task-settings.php')
check('admin task settings page loads', s == 200 and 'tasks_reservation_minutes' in h)
form = {'_csrf': admin.csrf(h)}
for k, v in re.findall(r'name="((?:tasks|campaigns)_[a-z_]+)"[^>]*?value="([^"]*)"', h):
    form[k] = v
form['tasks_reservation_minutes'] = '45'
admin.post('/task-settings.php', form)
check('admin changes slot hold to 45 minutes', one("SELECT setting_value FROM settings WHERE setting_key = 'tasks.reservation_minutes'") == '45')
form['tasks_auto_approve_min_hours'] = '400'
admin.post('/task-settings.php', form)
check('invalid settings refused', one("SELECT setting_value FROM settings WHERE setting_key = 'tasks.auto_approve_min_hours'") == '12')
s, l, x = e1.get('/task-settings.php')
check('earner cannot open task settings', 'tasks_reservation_minutes' not in x)

# ---------- pages, dashboards, docks
s, l, h = adv.get('/campaigns.php?status=all')
check('advertiser campaigns list loads with progress bars', s == 200 and 'class="progress"' in h and 'href="campaigns.php"' in h)
s, l, h = adv.get('/dashboard.php')
check('advertiser dashboard shows campaign stats', 'Live campaigns' in h and 'Proofs to review' in h and 'New campaign' in h)
s, l, h = e1.get('/dashboard.php')
check('earner dashboard shows task stats', 'Tasks available' in h and 'Earned' in h and 'Find tasks' in h)
s, l, h = e1.get('/submissions.php')
check('earner my-work page lists submissions', s == 200 and f'submission.php?id={s1}' in h)
s, l, h = admin.get('/dashboard.php')
check('admin dashboard shows marketplace stats and queue link', 'Awaiting approval' in h and 'Task revenue' in h and 'href="campaigns.php"' in h)
s, l, h = admin.get('/submissions.php?status=stale')
check('admin stale filter loads', s == 200)
s, l, h = admin.get('/campaigns.php?status=all&q=' + urllib.parse.quote(adv_email))
check('admin searches campaigns by advertiser email', s == 200 and f'data-campaign="{c1}"' in h)
for c, p in ((e1, '/tasks.php'), (adv, '/campaigns.php'), (admin, '/campaigns.php')):
    s, hd, b = c.req(p)
    check('private no-store headers on ' + p, 'no-store' in (hd.get('Cache-Control') or ''))
s, l, h = Client(E).get('/tasks.php')
check('signed-out earner redirected to sign in', s == 303 and l == 'login.php')
s, l, h = Client(A).get('/campaign.php?id=' + str(c1))
check('signed-out advertiser redirected to sign in', s == 303 and l == 'login.php')

# ---------- final reconciliation and tamper detection
ok, out = reconcile_ok()
check('final daily reconciliation OK (wallets, escrow, revenue, reward hold)', ok, out)
esc = int(one("SELECT COALESCE(SUM(balance_kobo),0) FROM ledger_accounts WHERE kind = 'campaign_escrow'"))
uns = int(one('SELECT COALESCE(SUM(funded_kobo - spent_kobo - refunded_kobo),0) FROM campaigns'))
check('total escrow equals total unspent budgets', esc == uns, (esc, uns))
db(f"UPDATE ledger_accounts SET balance_kobo = balance_kobo + 1 WHERE campaign_id = {c1}")
r = cron('daily.php')
check('reconciliation detects tampered escrow', r.returncode == 2 and 'escrow' in r.stdout, r.stdout)
db(f"UPDATE ledger_accounts SET balance_kobo = balance_kobo - 1 WHERE campaign_id = {c1}")
db(f"UPDATE campaigns SET approved_count = approved_count + 1 WHERE id = {c1}")
r = cron('hourly.php')
check('hourly detects campaign counter mismatch', r.returncode == 2, r.stdout)
db(f"UPDATE campaigns SET approved_count = approved_count - 1 WHERE id = {c1}")
ok, out = reconcile_ok()
check('reconciliation clean again after repair', ok, out)

fails = [l for l, ok in results if not ok]
print(f'\n{len(results) - len(fails)}/{len(results)} passed')
sys.exit(1 if fails else 0)
