#!/usr/bin/env python3
"""Custom tasks: advertisers name their own app or site and task, and set their
own price per task (minimum from settings, default N100). The earner reward is
campaigns.custom_reward_percent of the price (default 80%), rounded down. The
duplicate-link check matches custom tasks on link + task wording. Covers the
advertiser form, validation, the admin switch, catalog pages and the earner
feed labels. Fixture rows are removed at the end.

Needs the integration environment and servers from
tests/Integration/PaymentCallbacks/run-servers.sh.
"""
import datetime as dt
import html as htmllib
import os, re, shlex, subprocess, sys, time
import http.cookiejar, urllib.error, urllib.parse, urllib.request

CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
E, A, R, ADM = 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8404', 'http://127.0.0.1:8405'
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:300] + ']') if (not ok and extra != '') else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)

    def req(self, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(self.base + path, data=body, headers={'User-Agent': 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile'})
        try:
            resp = self.op.open(r, timeout=30)
            return resp.status, resp.headers.get('Location'), resp.read().decode(errors='replace')
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get('Location'), e.read().decode(errors='replace')

    def login(self, email, pw):
        s, l, h = self.req('/login.php')
        m = re.search(r'name="_csrf" value="([^"]*)"', h)
        s, l, _ = self.req('/login.php', {'_csrf': m.group(1) if m else '', 'email': email, 'password': pw})
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


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
created_users = []


def make_user(role, key):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PW], capture_output=True, text=True).stdout
    email = f'ct{key}{tag}@example.test'
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('CT {key}', '{email}', '{h}', '{role}', 'active', UTC_TIMESTAMP())")
    uid = int(one(f"SELECT id FROM users WHERE email = '{email}'"))
    created_users.append(uid)
    return uid, email


adv, adv_e = make_user('advertiser', 'adv')
admin, admin_e = make_user('admin', 'admin')
earner, earner_e = make_user('earner', 'e')
CP = int(one("SELECT id FROM platforms WHERE slug = 'custom'"))
CT = int(one("SELECT id FROM task_types WHERE slug = 'custom'"))
IG = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))
FOLLOW = int(one("SELECT id FROM task_types WHERE slug = 'follow'"))
INTEREST = one("SELECT id FROM interests WHERE is_active = 1 ORDER BY id LIMIT 1")
LINK = f'https://nifelux-{tag}.example.com/app?ref=1'


def form(**kw):
    d = {'title': f'CT campaign {tag}', 'platform_id': CP, 'task_type_id': CT, 'tier': 'basic', 'link_url': LINK,
         'instructions': 'Download the app and create an account.', 'proof_requirements': 'Screenshot of your profile page',
         'quantity': '10', 'ends_at': '', 'custom_platform_name': 'Nifelux App', 'custom_task_name': 'Download and sign up', 'custom_price': '150'}
    d.update({k: str(v) for k, v in kw.items()})
    return d


def create(c, data):
    s, l, h = c.req('/campaign-new.php')
    m = re.search(r'name="_csrf" value="([^"]*)"', h)
    body = dict(data); body['_csrf'] = m.group(1) if m else ''
    body = list(body.items()) + ([('interests[]', INTEREST)] if INTEREST else [])
    r = urllib.request.Request(c.base + '/campaign-new.php', data=urllib.parse.urlencode(body).encode(), headers={'User-Agent': 'Mozilla/5.0'})
    try:
        resp = c.op.open(r, timeout=30); s, l = resp.status, resp.headers.get('Location')
    except urllib.error.HTTPError as e:
        s, l = e.code, e.headers.get('Location')
    mid = re.search(r'campaign\.php\?id=(\d+)', l or '')
    if mid:
        return int(mid.group(1)), ''
    _, _, h2 = c.req('/campaign-new.php')
    msg = re.search(r'class="toast-msg">(.*?)</', h2, re.S)
    return None, htmllib.unescape(msg.group(1)).strip() if msg else h2[:200]


def setting(k, v):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{k}', '{v}') ON DUPLICATE KEY UPDATE setting_value = '{v}'")


made = []
try:
    setting('campaigns.custom_enabled', '1'); setting('campaigns.custom_min_price_naira', '100'); setting('campaigns.custom_reward_percent', '80')
    ca, cad, ce = Client(A), Client(ADM), Client(E)
    check('advertiser signs in', ca.login(adv_e, PW))
    check('admin signs in', cad.login(admin_e, PW))
    check('earner signs in', ce.login(earner_e, PW))

    _, _, h = ca.req('/campaign-new.php')
    check('form lists Other app / website', 'Other app / website' in h and 'data-custom="1"' in h)
    check('form has app name, task and price fields', all(x in h for x in ('name="custom_platform_name"', 'name="custom_task_name"', 'name="custom_price"')))
    check('form shows min price and 80% share', '\u20a6100' in h and '80%' in h, re.findall(r'At least[^<]*', h))

    cid, err = create(ca, form())
    check('custom campaign saved as draft', cid is not None, err)
    if cid:
        made.append(cid)
        row = db(f"SELECT custom_platform_name, custom_task_name, unit_price_kobo, unit_reward_kobo, budget_kobo, status FROM campaigns WHERE id = {cid}")[0]
        check('stores app name and task', row[0] == 'Nifelux App' and row[1] == 'Download and sign up', row)
        check('price N150, reward N120 (80%), budget N1,500', row[2:5] == ['15000', '12000', '150000'], row)
        check('status draft', row[5] == 'draft', row)
        _, _, hp = ca.req(f'/campaign.php?id={cid}')
        check('advertiser campaign page shows custom names', 'NIFELUX APP' in hp.upper() and 'DOWNLOAD AND SIGN UP' in hp.upper())

    cid2, err = create(ca, form(title=f'CT odd {tag}', custom_price='101', custom_task_name='Rate us 5 stars', link_url=LINK + 'x'))
    if cid2: made.append(cid2)
    check('N101 -> reward N80.80 (rounded down to kobo)', cid2 and db(f"SELECT unit_price_kobo, unit_reward_kobo FROM campaigns WHERE id = {cid2}")[0] == ['10100', '8080'], err)
    setting('campaigns.custom_reward_percent', '33')
    cid3, err = create(ca, form(title=f'CT pct {tag}', custom_price='101', custom_task_name='Leave a review', link_url=LINK + 'y'))
    if cid3: made.append(cid3)
    check('33% of N101 = 3333 kobo (floor)', cid3 and db(f"SELECT unit_reward_kobo FROM campaigns WHERE id = {cid3}")[0] == ['3333'], err)
    setting('campaigns.custom_reward_percent', '80')

    for label, kw, needle in [
        ('price below N100 rejected', {'custom_price': '99'}, 'between'),
        ('non-whole price rejected', {'custom_price': '150.5'}, 'whole naira'),
        ('empty price rejected', {'custom_price': ''}, 'whole naira'),
        ('missing app name rejected', {'custom_platform_name': ' '}, 'app or website'),
        ('missing task rejected', {'custom_task_name': 'ab'}, 'Describe the task'),
        ('http link rejected', {'link_url': 'http://nifelux.example.com/'}, 'https'),
    ]:
        x, err = create(ca, form(title=f'CT bad {label[:20]} {tag}', **kw))
        if x: made.append(x)
        check(label, x is None and needle.lower() in err.lower(), err)

    setting('campaigns.custom_min_price_naira', '200')
    x, err = create(ca, form(title=f'CT min {tag}', custom_price='150', link_url=LINK + 'm'))
    if x: made.append(x)
    check('admin minimum N200 enforced', x is None and '200' in err, err)
    setting('campaigns.custom_min_price_naira', '100')

    # Catalog platform with a custom task, own price; tier does not change price.
    x, err = create(ca, form(title=f'CT ig {tag}', platform_id=IG, custom_platform_name='', link_url=f'https://instagram.com/ct{tag}', tier='targeted', custom_price='250'))
    if x: made.append(x)
    check('Instagram + custom task keeps own price under Premium', x and db(f"SELECT unit_price_kobo, unit_reward_kobo, custom_platform_name FROM campaigns WHERE id = {x}")[0] == ['25000', '20000', 'NULL'], err)
    x, err = create(ca, form(title=f'CT igbad {tag}', platform_id=IG, link_url='https://example.com/notig'))
    if x: made.append(x)
    check('Instagram still checks its domains', x is None and 'instagram' in err.lower(), err)

    # Custom platform + catalog task: catalog price, any https link.
    x, err = create(ca, form(title=f'CT web follow {tag}', task_type_id=FOLLOW, custom_task_name='', custom_price='', link_url=LINK + 'f'))
    if x: made.append(x)
    fp = one(f"SELECT base_price_kobo FROM task_types WHERE id = {FOLLOW}")
    check('Other website + Follow uses catalog price', x and db(f"SELECT unit_price_kobo, custom_task_name FROM campaigns WHERE id = {x}")[0] == [fp, 'NULL'], err)

    # Duplicate check: open campaign with same link + same task wording blocks; other wording passes.
    db(f"""INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, custom_platform_name, custom_task_name, title, link_url, link_normalized, link_hash, instructions, proof_requirements,
        unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, status, approved_at)
        SELECT {adv}, {CP}, {CT}, 'Nifelux App', 'Download and sign up', 'CT live {tag}', link_url, link_normalized, link_hash, instructions, proof_requirements, 15000, 12000, 10, 150000, 'live', UTC_TIMESTAMP()
        FROM campaigns WHERE id = {cid}""")
    live = int(one(f"SELECT id FROM campaigns WHERE title = 'CT live {tag}'")); made.append(live)
    if INTEREST: db(f"INSERT INTO campaign_interests (campaign_id, interest_id) VALUES ({live}, {INTEREST})")
    x, err = create(ca, form(title=f'CT dup {tag}', custom_task_name='download AND sign up'))
    if x: made.append(x)
    check('duplicate link + same task (any case) blocked', x is None and 'already running' in err, err)
    x, err = create(ca, form(title=f'CT dup2 {tag}', custom_task_name='Subscribe to newsletter'))
    if x: made.append(x)
    check('same link, different task allowed', x is not None, err)

    # Earner feed shows the custom names and the 80% reward.
    _, _, hf = ce.req('/tasks.php')
    check('earner feed shows app name and task', 'Nifelux App' in hf and 'Download and sign up' in hf, re.findall(r'CT live[^<]*', hf))
    check('earner sees N120 reward', '120.00' in hf)
    # Admin views
    _, _, hc = cad.req('/catalog.php')
    check('admin catalog hides custom rows', 'Other app / website' not in hc and 'Custom task (set your own price)' not in hc)
    _, _, hs = cad.req('/task-settings.php')
    check('admin task settings has custom fields', all(x in hs for x in ('campaigns_custom_enabled', 'campaigns_custom_min_price_naira', 'campaigns_custom_reward_percent')))
    _, _, hl = cad.req('/campaigns.php?status=all')
    check('admin campaign list marks Custom', 'Custom' in hl and 'Nifelux App' in hl)

    # Switch off
    setting('campaigns.custom_enabled', '0')
    _, _, h = ca.req('/campaign-new.php')
    check('switched off: form hides custom options', 'Other app / website' not in h and 'data-custom="1"' not in h)
    x, err = create(ca, form(title=f'CT off {tag}', link_url=LINK + 'off'))
    if x: made.append(x)
    check('switched off: custom post rejected', x is None and 'switched off' in err, err)
    setting('campaigns.custom_enabled', '1')

    # Catalog task types still behave as before.
    x, err = create(ca, form(title=f'CT plain {tag}', platform_id=IG, task_type_id=FOLLOW, custom_platform_name='', custom_task_name='', custom_price='9999', link_url=f'https://instagram.com/plain{tag}'))
    if x: made.append(x)
    check('catalog task ignores custom price', x and db(f"SELECT unit_price_kobo, custom_task_name FROM campaigns WHERE id = {x}")[0] == [fp, 'NULL'], err)
finally:
    setting('campaigns.custom_enabled', '1'); setting('campaigns.custom_min_price_naira', '100'); setting('campaigns.custom_reward_percent', '80')
    ids = ','.join(str(i) for i in made) or '0'
    for sql in [f"DELETE FROM campaign_interests WHERE campaign_id IN ({ids})", f"DELETE FROM campaign_instructions WHERE campaign_id IN ({ids})",
                f"DELETE FROM campaigns WHERE id IN ({ids}) OR advertiser_user_id IN ({','.join(map(str, created_users))})"]:
        try: db(sql)
        except Exception as e: print('cleanup:', e)

passed = sum(1 for _, ok in results if ok)
print(f'{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
