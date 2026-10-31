#!/usr/bin/env python3
"""YOLOTASK V2 Phase 5 integration test: earner appeals, the reviewer appeal
queue with claim locking, uphold/overturn, overturn money (escrow, advertiser
wallet shortfall, platform fallback + advertiser debt), idempotency and
double clicks, admin decisions and overrides (including reversing an
overturn), the stale-submission reviewer queue, the advertiser quality flag,
notifications, cron claim expiry, authorization between portals, CSRF and
reconciliation.

Needs the Phase 4 environment (see tests/Integration/TaskSubmissions/phase4_test.py)
with migration 0008 applied and the reviewer server on 8404 (run-servers.sh
starts it). Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_STORAGE,
YT_MAIL_LOG, YT_DB_CLI.
"""
import io, json, os, re, shlex, subprocess, sys, threading, time, uuid
import http.cookiejar, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')
E, A, R, ADM = 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8404', 'http://127.0.0.1:8405'
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


def one(sql):
    rows = db(sql)
    return rows[0][0] if rows else None


def mails_to(to):
    try:
        return [json.loads(l) for l in open(MAIL) if json.loads(l)['to'] == to]
    except FileNotFoundError:
        return []


def make_user(role, email, pw, name=None):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name or role.title() + ' Tester'}', '{email}', '{h}', '{role}', 'active', UTC_TIMESTAMP())")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(uid):
    r = db(f"SELECT available_kobo FROM wallets WHERE user_id = {uid}")
    return int(r[0][0]) if r else 0


def sysbal(code):
    return int(one(f"SELECT balance_kobo FROM ledger_accounts WHERE code = '{code}'"))


def cron(name):
    return subprocess.run(['php', ROOT + '/cron/' + name], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))


def reconcile_ok():
    r = cron('daily.php')
    return r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr


_img_n = [0]


def image(fmt='PNG', size=(400, 700)):
    _img_n[0] += 1
    im = Image.new('RGB', size, ((_img_n[0] * 53) % 255, (_img_n[0] * 29) % 255, (_img_n[0] * 71) % 255))
    rnd = os.urandom(size[0])
    for x in range(0, size[0], 5):
        im.putpixel((x, (x * _img_n[0]) % size[1]), (rnd[x], 255, 255))
    b = io.BytesIO()
    im.save(b, fmt)
    return b.getvalue()


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
setting('tasks.reward_hold_hours', '0')
setting('tasks.resubmission_cap', '1')
setting('tasks.max_active_reservations', '3')
setting('appeals.window_hours', '72')
setting('reviews.claim_minutes', '30')
setting('appeals.flag_min_overturns', '3')
setting('appeals.flag_rate_percent', '30')
setting('appeals.flag_window_days', '30')

emails = {k: f'p5{k}{tag}@gmail.com' for k in ['adv', 'advb', 'advf', 'admin', 'rev1', 'rev2'] + [f'e{i}' for i in range(1, 7)]}
aid = make_user('advertiser', emails['adv'], PW, 'Bola Ads')
bid = make_user('advertiser', emails['advb'], PW, 'Broke Ads')
fid = make_user('advertiser', emails['advf'], PW, 'Fussy Ads')
admid = make_user('admin', emails['admin'], PW, 'Grace Admin')
r1id = make_user('reviewer', emails['rev1'], PW, 'Rita Reviewer')
r2id = make_user('reviewer', emails['rev2'], PW, 'Remi Reviewer')
eids = [make_user('earner', emails[f'e{i}'], PW, f'Ada{i} Earner') for i in range(1, 7)]

adv, advb, advf, admin = Client(A), Client(A), Client(A), Client(ADM)
rev1, rev2 = Client(R), Client(R)
earners = [Client(E) for _ in eids]
e1, e2, e3, e4, e5, e6 = earners
check('advertisers sign in', adv.login(emails['adv'], PW) and advb.login(emails['advb'], PW) and advf.login(emails['advf'], PW))
check('admin signs in', admin.login(emails['admin'], PW))
check('reviewers sign in on the reviewer portal', rev1.login(emails['rev1'], PW) and rev2.login(emails['rev2'], PW))
check('earners sign in', all(c.login(emails[f'e{i}'], PW) for i, c in enumerate(earners, 1)))
check('migration 0008 recorded', one("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0008_appeals_reviewer_queue.sql'") == '1')
check('placeholder 0008_notifications stays unapplied', one("SELECT COUNT(*) FROM schema_migrations WHERE filename LIKE '0008_notifications%'") == '0')
check('receivable system account exists (debit-normal)', db("SELECT normal_side, allow_negative FROM ledger_accounts WHERE code = 'system:advertiser_receivable'")[0] == ['debit', '0'])

follow_id = int(one("SELECT id FROM task_types WHERE slug = 'follow'"))
like_id = int(one("SELECT id FROM task_types WHERE slug = 'like'"))
ig_id = int(one("SELECT id FROM platforms WHERE slug = 'instagram'"))


def fund(uid, naira, why='Phase 5 test funding'):
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': uid, 'idem': uuid.uuid4().hex, 'direction': 'credit', 'amount': str(naira), 'reason': why})


def debit(uid, naira, why='Phase 5 test debit'):
    s, l, h = admin.get('/wallets.php')
    admin.post('/wallets.php', {'_csrf': admin.csrf(h), 'action': 'adjust', 'user_id': uid, 'idem': uuid.uuid4().hex, 'direction': 'debit', 'amount': str(naira), 'reason': why})


def campaign(client, title, slug, qty, task=None):
    s, l, h = client.get('/campaign-new.php')
    data = {'_csrf': client.csrf(h), 'title': title, 'platform_id': ig_id, 'task_type_id': task or follow_id, 'tier': 'basic',
            'link_url': f'https://instagram.com/{slug}_{tag}', 'instructions': f'Follow {slug} with your own account and keep following.',
            'proof_requirements': 'Screenshot showing you follow the page.', 'quantity': str(qty), 'ends_at': ''}
    s, l, b = client.post('/campaign-new.php', data)
    cid = int(l.split('=')[1])
    s, l, h = client.get(f'/campaign.php?id={cid}')
    client.post(f'/campaign.php?id={cid}', {'_csrf': client.csrf(h), 'campaign_id': cid, 'action': 'submit'})
    s, l, h = admin.get('/campaigns.php')
    admin.post('/campaigns.php', {'_csrf': admin.csrf(h), 'campaign_id': cid, 'action': 'approve', 'back': 'campaigns.php'})
    return cid


def start(ec, cid):
    s, l, h = ec.get(f'/task.php?id={cid}')
    ec.post(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'start'})


def submit(ec, cid, note='Done with my account'):
    start(ec, cid)
    s, l, h = ec.get(f'/task.php?id={cid}')
    s, l, b = ec.upload(f'/task.php?id={cid}', {'_csrf': ec.csrf(h), 'campaign_id': cid, 'action': 'submit', 'comment': note}, {'proof': ('p.png', image(), 'image/png')})
    return int(l.split('=')[1]) if l and 'submission.php?id=' in l else None


def adv_decide(client, cid, sid, action, reason=''):
    s, l, h = client.get(f'/campaign.php?id={cid}')
    client.post(f'/campaign.php?id={cid}', {'_csrf': client.csrf(h), 'campaign_id': cid, 'action': action, 'submission_id': sid, 'reason': reason})


def appeal(ec, sid, reason='I followed the page exactly as asked; the screenshot shows it.', shot=False, tok=None):
    if tok is None:
        tok = ec.csrf(ec.get(f'/submission.php?id={sid}')[2])
    files = {'appeal_proof': ('extra.png', image(), 'image/png')} if shot else {}
    return ec.upload(f'/submission.php?id={sid}', {'_csrf': tok, 'submission_id': sid, 'action': 'appeal', 'reason': reason}, files)


def appeal_id(sid):
    v = one(f"SELECT id FROM submission_appeals WHERE submission_id = {sid}")
    return int(v) if v else None


def rpost(rc, aid_, action, note='', tok=None):
    if tok is None:
        tok = rc.csrf(rc.get(f'/appeal.php?id={aid_}')[2])
    return rc.post(f'/appeal.php?id={aid_}', {'_csrf': tok, 'appeal_id': aid_, 'action': action, 'note': note})


def apost(aid_, action, note='', tok=None):
    if tok is None:
        tok = admin.csrf(admin.get(f'/appeals.php?id={aid_}')[2])
    return admin.post(f'/appeals.php?id={aid_}', {'_csrf': tok, 'appeal_id': aid_, 'action': action, 'note': note, 'back': f'?id={aid_}'})


fund(aid, 1000)
check('advertiser funded ₦1,000', balance(aid) == 100000, balance(aid))

# ======================================================================
# 1. Filing appeals: eligibility, window, single appeal, resubmit exclusion
# ======================================================================
c1 = campaign(adv, 'Follow Bola P5', 'bola', 3)
check('campaign 1 live (3 × ₦25)', one(f"SELECT status FROM campaigns WHERE id = {c1}") == 'live')
s1, s2, s3 = submit(e1, c1, 'My handle is @ada1'), submit(e2, c1), submit(e3, c1)
check('three proofs submitted', all([s1, s2, s3]), (s1, s2, s3))
s, l, h = e1.get(f'/submission.php?id={s1}')
check('pending submission has no appeal form', 'value="appeal"' not in h)
appeal(e1, s1)
check('cannot appeal a pending proof', appeal_id(s1) is None)
for sid in (s1, s2, s3):
    adv_decide(adv, c1, sid, 'reject', 'Screenshot does not show the follow')
check('advertiser rejected all three', one(f"SELECT COUNT(*) FROM submissions WHERE campaign_id = {c1} AND status = 'rejected'") == '3')
m = [x for x in mails_to(emails['e1']) if 'not accepted' in x['subject']]
check('rejection email mentions the appeal option and window', m and 'appeal' in m[-1]['text'] and '72 hours' in m[-1]['text'], m[-1]['text'] if m else '')
s, l, h = e1.get(f'/submission.php?id={s1}')
check('rejected proof page shows appeal form and resubmit form', 'value="appeal"' in h and 'name="appeal_proof"' in h and 'Resubmit proof' in h)
appeal(e1, s1, tok='bad-token')
check('appeal without CSRF refused', appeal_id(s1) is None)
appeal(e1, s1, reason='too short')
check('appeal with a too-short reason refused', appeal_id(s1) is None)
msg, _ = e1.flash(f'/submission.php?id={s1}')
check('short-reason message explains the rule', '10 to 1,000' in msg, msg)
s, l, x = e3.get(f'/submission.php?id={s1}')
appeal(e3, s1, tok=e3.csrf(e3.get('/submissions.php')[2]))
check("another earner cannot open or appeal someone else's proof", s == 404 and appeal_id(s1) is None)

# window
db(f"UPDATE submissions SET decided_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 73 HOUR) WHERE id = {s3}")
s, l, h = e3.get(f'/submission.php?id={s3}')
check('after 72 h the appeal form is gone', 'value="appeal"' not in h)
appeal(e3, s3, tok=e3.csrf(h))
check('appeal after the window refused', appeal_id(s3) is None)
msg, _ = e3.flash(f'/submission.php?id={s3}')
check('window message names 72 hours', '72-hour' in msg, msg)
s, l, h = admin.get('/task-settings.php')
form = {'_csrf': admin.csrf(h), 'section': 'appeals'}
for k, v in re.findall(r'name="((?:appeals|reviews)_[a-z_]+)"[^>]*?value="([^"]*)"', h):
    form[k] = v
check('task settings page has the appeal settings form', form.get('appeals_window_hours') == '72' and form.get('reviews_claim_minutes') == '30' and 'appeals_flag_rate_percent' in form, form)
admin.post('/task-settings.php', dict(form, appeals_window_hours='100'))
check('admin widens the appeal window to 100 h', one("SELECT setting_value FROM settings WHERE setting_key = 'appeals.window_hours'") == '100')
s, l, h = e3.get(f'/submission.php?id={s3}')
check('wider window re-opens the appeal form', 'value="appeal"' in h)
admin.post('/task-settings.php', dict(form, appeals_window_hours='0'))
check('invalid window (0) refused', one("SELECT setting_value FROM settings WHERE setting_key = 'appeals.window_hours'") == '100')
admin.post('/task-settings.php', dict(form, _csrf='bad', appeals_window_hours='5'))
check('settings change without CSRF refused', one("SELECT setting_value FROM settings WHERE setting_key = 'appeals.window_hours'") == '100')
admin.post('/task-settings.php', dict(form, appeals_window_hours='72'))
db(f"UPDATE submissions SET decided_at = UTC_TIMESTAMP() WHERE id = {s3}")

# resubmit exclusion
s, l, h = e2.get(f'/submission.php?id={s2}')
e2.upload(f'/submission.php?id={s2}', {'_csrf': e2.csrf(h), 'submission_id': s2}, {'proof': ('r.png', image(), 'image/png')})
check('earner 2 resubmits instead of appealing', db(f"SELECT status, attempts FROM submissions WHERE id = {s2}")[0] == ['pending', '2'])
adv_decide(adv, c1, s2, 'reject', 'Still the wrong account')
s, l, h = e2.get(f'/submission.php?id={s2}')
check('after a resubmission there is no appeal form', 'value="appeal"' not in h)
appeal(e2, s2, tok=e2.csrf(h))
check('appeal after a resubmission refused', appeal_id(s2) is None)
msg, _ = e2.flash(f'/submission.php?id={s2}')
check('resubmission message explains why', 'resubmitted' in msg, msg)

# file appeal 1 with an extra screenshot
n_mail = len(mails_to(emails['e1']))
s, l, b = appeal(e1, s1, reason='I followed @bola from my own account; see the second screenshot with the Following button.', shot=True)
a1 = appeal_id(s1)
check('earner files an appeal with an extra screenshot', a1 is not None and s == 303)
r = db(f"SELECT status, campaign_id, advertiser_user_id, rejection_source, rejection_reason, instructions_version, proof_id IS NOT NULL FROM submission_appeals WHERE id = {a1}")[0]
check('appeal snapshots the rejection (advertiser, reason, version)', r == ['open', str(c1), str(aid), 'advertiser', 'Screenshot does not show the follow', '1', '1'], r)
check('extra screenshot stored as an appeal proof', one(f"SELECT COUNT(*) FROM submission_proofs WHERE submission_id = {s1} AND is_appeal = 1") == '1')
check('earner emailed: appeal received', len(mails_to(emails['e1'])) > n_mail and 'We received your appeal' in mails_to(emails['e1'])[-1]['subject'])
s, l, h = e1.get(f'/submission.php?id={s1}')
check('submission page shows appeal status, not the form or resubmit', 'data-appeal-status="open"' in h and 'value="appeal"' not in h and 'Resubmit proof' not in h and 'Appeal screenshot' in h)
check('my-work list badge says appeal open', 'Rejected · appeal open' in e1.get('/submissions.php')[2])
appeal(e1, s1)
check('second appeal on the same rejection refused', one(f"SELECT COUNT(*) FROM submission_appeals WHERE submission_id = {s1}") == '1')
s, l, h = e1.get(f'/submission.php?id={s1}')
e1.upload(f'/submission.php?id={s1}', {'_csrf': e1.csrf(h), 'submission_id': s1}, {'proof': ('r.png', image(), 'image/png')})
check('appealed proof cannot be resubmitted', db(f"SELECT status, attempts FROM submissions WHERE id = {s1}")[0] == ['rejected', '1'])
msg, _ = e1.flash(f'/submission.php?id={s1}')
check('resubmit refusal mentions the appeal', 'appealed' in msg, msg)

# concurrent double appeal (double click)
tok3 = e3.csrf(e3.get(f'/submission.php?id={s3}')[2])
ths = [threading.Thread(target=lambda: appeal(e3, s3, tok=tok3)) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
check('three simultaneous appeal clicks create exactly one appeal', one(f"SELECT COUNT(*) FROM submission_appeals WHERE submission_id = {s3}") == '1')
a3 = appeal_id(s3)

# ======================================================================
# 2. Reviewer queue, claims and locking
# ======================================================================
s, l, h = rev1.get('/appeals.php')
check('reviewer queue lists open appeals, oldest first', s == 200 and f'data-appeal="{a1}"' in h and f'data-appeal="{a3}"' in h and h.index(f'data-appeal="{a1}"') < h.index(f'data-appeal="{a3}"'))
check('reviewer dock has Appeals and Stale', 'href="appeals.php"' in h and 'href="stale.php"' in h)
s, l, h = rev1.get(f'/appeal.php?id={a1}')
check('appeal page shows instructions version used, proofs, rejection and appeal text', s == 200 and 'Version 1' in h and 'Follow bola with your own account' in h and 'Screenshot does not show the follow' in h
      and 'Following button' in h and 'My handle is @ada1' in h and 'Added with the appeal' in h and 'value="claim"' in h)
proof_orig = int(one(f"SELECT id FROM submission_proofs WHERE submission_id = {s1} AND is_appeal = 0"))
st, hd, body = rev1.req(f'/proof.php?id={proof_orig}', binary=True)
check('reviewer can open the proof of an appealed submission', st == 200 and (hd.get('Content-Type') or '').startswith('image/'))
rpost(rev1, a1, 'claim', tok='bad')
check('claim without CSRF refused', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == '0')
rpost(rev1, a1, 'claim')
check('reviewer 1 claims appeal 1', one(f"SELECT reviewer_user_id FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == str(r1id))
check('claim lasts 30 minutes', 29 <= int(one(f"SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires_at) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}")) <= 30)
rpost(rev2, a1, 'claim')
check('reviewer 2 cannot take a claimed appeal', one(f"SELECT reviewer_user_id FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == str(r1id))
msg, h = rev2.flash(f'/appeal.php?id={a1}')
check('reviewer 2 told someone else is working on it', 'Another reviewer' in msg and 'data-claim="taken"' in h, msg)
rpost(rev2, a1, 'overturn', 'I think this is fine')
check('reviewer 2 cannot decide an appeal they did not claim', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'open')
s, l, h = rev2.get('/appeals.php')
check('queue shows who holds a claim', 'Claimed by Rita Reviewer' in h)
# race for appeal 3
tok_a = rev1.csrf(rev1.get(f'/appeal.php?id={a3}')[2])
tok_b = rev2.csrf(rev2.get(f'/appeal.php?id={a3}')[2])
ths = [threading.Thread(target=lambda: rpost(rev1, a3, 'claim', tok=tok_a)), threading.Thread(target=lambda: rpost(rev2, a3, 'claim', tok=tok_b))]
[t.start() for t in ths]
[t.join() for t in ths]
owner = one(f"SELECT reviewer_user_id FROM review_claims WHERE item_type = 'appeal' AND item_id = {a3}")
check('simultaneous claims: exactly one reviewer wins', owner in (str(r1id), str(r2id)) and one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a3}") == '1', owner)
win3 = rev1 if owner == str(r1id) else rev2
# release + expiry
rpost(rev1, a1, 'release')
check('reviewer releases a claim', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == '0')
rpost(rev1, a1, 'claim')
db(f"UPDATE review_claims SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE item_type = 'appeal' AND item_id = {a1}")
rpost(rev1, a1, 'uphold', 'Expired claim should not decide')
check('decision with an expired claim refused', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'open')
r = cron('every-five-minutes.php')
check('5-minute cron deletes expired claims', r.returncode == 0 and 'expired claims released: 1' in r.stdout and one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == '0', r.stdout + r.stderr)
rpost(rev1, a1, 'claim')
rpost(rev1, a1, 'uphold', '')
check('decision without a note refused', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'open')
rpost(rev1, a1, 'uphold', 'Valid note', tok='bad')
check('decision without CSRF refused', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'open')

# ======================================================================
# 3. Uphold
# ======================================================================
e1_before = balance(eids[0])
rpost(rev1, a1, 'uphold', 'The screenshot shows a different account than the one you submitted with.')
r = db(f"SELECT status, resolved_by_user_id, decided_by_role, resolution_note IS NOT NULL, admin_override FROM submission_appeals WHERE id = {a1}")[0]
check('reviewer upholds: appeal upheld, reviewer recorded', r == ['upheld', str(r1id), 'reviewer', '1', '0'], r)
check('upheld: submission stays rejected, no money moved', one(f"SELECT status FROM submissions WHERE id = {s1}") == 'rejected' and balance(eids[0]) == e1_before)
check('upheld: claim cleared', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a1}") == '0')
check('reviewer action recorded for pay (reviewer id + time)', db(f"SELECT reviewer_user_id, action, decided_at IS NOT NULL FROM reviewer_actions WHERE item_type = 'appeal' AND item_id = {a1}")[0] == [str(r1id), 'appeal_upheld', '1'])
check('audit log has the uphold', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'reviewer.appeal_upheld' AND target_id = {a1} AND actor_user_id = {r1id}") == '1')
m = mails_to(emails['e1'])[-1]
check('earner emailed: rejection upheld with the note', 'reviewed' in m['subject'] and 'different account' in m['text'] and 'final' in m['text'], m['subject'])
rpost(rev1, a1, 'uphold', 'Second click')
rpost(rev1, a1, 'overturn', 'Changed my mind')
check('double click / later overturn by reviewer refused (decision stands)', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'upheld' and one(f"SELECT COUNT(*) FROM reviewer_actions WHERE item_id = {a1} AND item_type = 'appeal'") == '1' and balance(eids[0]) == e1_before)
s, l, h = e1.get(f'/submission.php?id={s1}')
check('earner page shows upheld decision and note', 'data-appeal-status="upheld"' in h and 'Rejection upheld' in h and 'different account' in h and 'value="appeal"' not in h)
s, l, h = rev1.get('/history.php')
check('reviewer history lists the decision', 'data-action="appeal_upheld"' in h)

# ======================================================================
# 4. Overturn paid from escrow (with double click)
# ======================================================================
e3_before, rev_before = balance(eids[2]), sysbal('system:revenue')
esc_before = int(one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}"))
tok = win3.csrf(win3.get(f'/appeal.php?id={a3}')[2])
ths = [threading.Thread(target=lambda: rpost(win3, a3, 'overturn', 'Proof clearly shows the follow.', tok=tok)) for _ in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
check('overturn (3 simultaneous clicks) posts exactly one payment', one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'appeal-approve:{a3}'") == '1' and one(f"SELECT COUNT(*) FROM reviewer_actions WHERE item_id = {a3} AND item_type = 'appeal'") == '1')
check('earner paid the ₦15 reward once', balance(eids[2]) - e3_before == 1500, balance(eids[2]) - e3_before)
check('margin ₦10 to revenue', sysbal('system:revenue') - rev_before == 1000)
check('escrow paid the ₦25 price', esc_before - int(one(f"SELECT a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c1}")) == 2500)
r = db(f"SELECT status, decision_source, decided_by_user_id, reward_status FROM submissions WHERE id = {s3}")[0]
check('submission approved on appeal with the reviewer as decider', r == ['approved', 'appeal', owner, 'released'], r)
r = db(f"SELECT status, escrow_kobo, wallet_kobo, platform_kobo, over_budget FROM submission_appeals WHERE id = {a3}")[0]
check('appeal records escrow funding, not over budget', r == ['overturned', '2500', '0', '0', '0'], r)
check('campaign counters: 1 approved, no extra', db(f"SELECT approved_count, extra_approved_count, spent_kobo FROM campaigns WHERE id = {c1}")[0] == ['1', '0', '2500'])
check('earner emailed: appeal successful', 'successful' in mails_to(emails['e3'])[-1]['subject'])
m = [x for x in mails_to(emails['adv']) if 'overturned' in x['subject']]
check('advertiser emailed about the overturn with the reviewer note', m and 'Proof clearly shows the follow' in m[-1]['text'], m[-1]['text'] if m else '')
s, l, h = adv.get(f'/campaign.php?id={c1}')
check('advertiser campaign page shows appeal outcomes', 'id="appeals"' in h and f'data-appeal="{a3}" data-appeal-status="overturned"' in h and f'data-appeal="{a1}" data-appeal-status="upheld"' in h)
s, l, h = e3.get(f'/submission.php?id={s3}')
check('earner page: approved on appeal', 'Approved on appeal' in h and 'data-appeal-status="overturned"' in h)
ok, out = reconcile_ok()
check('reconciliation OK after overturn from escrow', ok, out)

# ======================================================================
# 5. Overturn after the budget is used: advertiser wallet + reward hold
# ======================================================================
c2 = campaign(adv, 'Like quick P5', 'likeit', 1, like_id)
s4 = submit(e4, c2)
adv_decide(adv, c2, s4, 'reject', 'Like not visible')
s5 = submit(e5, c2)
adv_decide(adv, c2, s5, 'approve')
check('campaign 2 completed by another earner (escrow empty)', db(f"SELECT c.status, a.balance_kobo FROM campaigns c JOIN ledger_accounts a ON a.id = c.escrow_account_id WHERE c.id = {c2}")[0] == ['completed', '0'])
appeal(e4, s4)
a4 = appeal_id(s4)
setting('tasks.reward_hold_hours', '24')
adv_before, e4_before, hold_before = balance(aid), balance(eids[3]), sysbal('system:reward_hold')
rpost(rev1, a4, 'claim')
rpost(rev1, a4, 'overturn', 'The like is visible at the top of the screenshot.')
check('over-budget overturn charges the advertiser wallet ₦15', adv_before - balance(aid) == 1500, adv_before - balance(aid))
check('reward goes to the hold (reward hold 24 h respected)', balance(eids[3]) == e4_before and sysbal('system:reward_hold') - hold_before == 1000 and one(f"SELECT reward_status FROM submissions WHERE id = {s4}") == 'held')
r = db(f"SELECT status, escrow_kobo, wallet_kobo, platform_kobo, over_budget FROM submission_appeals WHERE id = {a4}")[0]
check('appeal records wallet funding and over budget', r == ['overturned', '0', '1500', '0', '1'], r)
check('campaign records the extra approval (never silent)', db(f"SELECT approved_count, extra_approved_count, funded_kobo, status FROM campaigns WHERE id = {c2}")[0] == ['2', '1', '3000', 'completed'])
check('funding event appeal_topup recorded', one(f"SELECT amount_kobo FROM campaign_funding_events WHERE campaign_id = {c2} AND event = 'appeal_topup'") == '1500')
check('fund + approve keys used once each', one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key IN ('appeal-fund:{a4}', 'appeal-approve:{a4}')") == '2')
m = [x for x in mails_to(emails['adv']) if 'overturned' in x['subject']]
check('advertiser email explains the wallet charge', m and 'charged from your wallet' in m[-1]['text'])
s, l, h = adv.get(f'/campaign.php?id={c2}')
check('advertiser page shows the wallet charge and the top-up event', 'charged from your wallet' in h and 'Added for an appeal' in h)
s, l, h = adv.get('/wallet.php')
check('advertiser wallet history labels the appeal charge', 'Task paid on appeal' in h)
ok, out = reconcile_ok()
check('reconciliation OK after wallet-funded overturn (reward on hold)', ok, out)
setting('tasks.reward_hold_hours', '0')

# live campaign whose free slots are held by other earners: overturn never takes their slot
c3 = campaign(adv, 'Follow slots P5', 'slots', 2)
sa = submit(e1, c3)
adv_decide(adv, c3, sa, 'reject', 'Wrong profile')
start(e2, c3)
start(e3, c3)
check('both remaining slots held by other earners', one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c3} AND status = 'active'") == '2')
appeal(e1, sa)
aa = appeal_id(sa)
adv_before = balance(aid)
rpost(rev2, aa, 'claim')
rpost(rev2, aa, 'overturn', 'Correct profile followed.')
check('overturn with slots held: paid from advertiser wallet, over budget', adv_before - balance(aid) == 2500 and one(f"SELECT over_budget FROM submission_appeals WHERE id = {aa}") == '1')
check('held slots untouched and campaign still live', one(f"SELECT COUNT(*) FROM task_reservations WHERE campaign_id = {c3} AND status = 'active'") == '2' and one(f"SELECT status FROM campaigns WHERE id = {c3}") == 'live')
sb, sc = None, None
for ec in (e2, e3):
    s, l, h = ec.get(f'/task.php?id={c3}')
    s, l, b = ec.upload(f'/task.php?id={c3}', {'_csrf': ec.csrf(h), 'campaign_id': c3, 'action': 'submit'}, {'proof': ('p.png', image(), 'image/png')})
    if sb is None:
        sb = int(l.split('=')[1])
    else:
        sc = int(l.split('=')[1])
adv_decide(adv, c3, sb, 'approve')
adv_decide(adv, c3, sc, 'approve')
check('both slot holders submit and get paid; campaign completes at 3 = 2 + 1 extra', db(f"SELECT approved_count, extra_approved_count, status FROM campaigns WHERE id = {c3}")[0] == ['3', '1', 'completed'])
ok, out = reconcile_ok()
check('reconciliation OK after slot-protected overturn', ok, out)

# ======================================================================
# 6. Platform fallback + advertiser debt
# ======================================================================
fund(bid, 25)
c4 = campaign(advb, 'Broke follow P5', 'broke', 1)
check('advertiser B spent its whole wallet', balance(bid) == 0)
s6 = submit(e1, c4)
adv_decide(advb, c4, s6, 'reject', 'Not following')
s7 = submit(e2, c4)
adv_decide(advb, c4, s7, 'approve')
appeal(e1, s6)
a6 = appeal_id(s6)
fund(bid, 10)
recv_before, e1_before = sysbal('system:advertiser_receivable'), balance(eids[0])
rpost(rev2, a6, 'claim')
rpost(rev2, a6, 'overturn', 'Following is visible.')
r = db(f"SELECT status, escrow_kobo, wallet_kobo, platform_kobo, over_budget FROM submission_appeals WHERE id = {a6}")[0]
check('short wallet: ₦10 from wallet, ₦15 fronted by the platform', r == ['overturned', '0', '1000', '1500', '1'], r)
check('earner still paid in full', balance(eids[0]) - e1_before == 1500)
check('advertiser wallet emptied, receivable +₦15', balance(bid) == 0 and sysbal('system:advertiser_receivable') - recv_before == 1500)
debt = db(f"SELECT id, amount_kobo, status, advertiser_user_id FROM advertiser_debts WHERE appeal_id = {a6}")
check('advertiser debt opened for admin', debt and debt[0][1:] == ['1500', 'open', str(bid)], debt)
debt_id = int(debt[0][0])
m = [x for x in mails_to(emails['advb']) if 'overturned' in x['subject']]
check('advertiser email explains the platform-paid amount owed', m and 'YOLOTASK paid it for you' in m[-1]['text'])
s, l, h = admin.get('/appeals.php?view=debts')
check('admin debts page lists the open debt', f'data-debt="{debt_id}"' in h and 'value="debt_collect"' in h)
s, l, h = admin.get('/users.php?role=advertiser&q=' + urllib.parse.quote(emails['advb']))
check('admin user list shows what the advertiser owes', f'data-debt-owed="{bid}"' in h and 'Owes ₦15.00' in h)
ok, out = reconcile_ok()
check('reconciliation OK with an open debt (receivable = open debts)', ok, out)
tok = admin.csrf(admin.get('/appeals.php?view=debts')[2])
admin.post('/appeals.php?view=debts', {'_csrf': tok, 'debt_id': debt_id, 'action': 'debt_collect', 'back': '?view=debts'})
check('collect refused while the wallet is empty', one(f"SELECT status FROM advertiser_debts WHERE id = {debt_id}") == 'open')
admin.post('/appeals.php?view=debts', {'_csrf': 'bad', 'debt_id': debt_id, 'action': 'debt_writeoff', 'note': 'nope nope', 'back': '?view=debts'})
check('debt write-off without CSRF refused', one(f"SELECT status FROM advertiser_debts WHERE id = {debt_id}") == 'open')
fund(bid, 20)
admin.post('/appeals.php?view=debts', {'_csrf': tok, 'debt_id': debt_id, 'action': 'debt_collect', 'back': '?view=debts'})
check('admin collects the debt from the topped-up wallet', one(f"SELECT status FROM advertiser_debts WHERE id = {debt_id}") == 'collected' and balance(bid) == 500 and sysbal('system:advertiser_receivable') == recv_before)
admin.post('/appeals.php?view=debts', {'_csrf': tok, 'debt_id': debt_id, 'action': 'debt_collect', 'back': '?view=debts'})
check('collecting twice moves nothing', balance(bid) == 500 and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'debt-collect:{debt_id}'") == '1')
ok, out = reconcile_ok()
check('reconciliation OK after debt collected', ok, out)

# ======================================================================
# 7. Admin decisions and overrides
# ======================================================================
s, l, h = admin.get('/appeals.php?status=all')
check('admin appeals page lists every appeal', s == 200 and all(f'data-appeal="{x}"' in h for x in (a1, a3, a4, a6)))
s, l, h = admin.get(f'/appeals.php?id={a1}')
check('admin appeal detail offers override of an upheld appeal', 'Override the reviewer' in h and 'value="overturn"' in h)
apost(a1, 'overturn', 'Admin review: proof is acceptable.', tok='bad')
check('override without CSRF refused', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'upheld')
e1_before = balance(eids[0])
apost(a1, 'overturn', 'Admin review: proof is acceptable.')
r = db(f"SELECT status, admin_override, override_from_status, overridden_by_user_id, resolved_by_user_id FROM submission_appeals WHERE id = {a1}")[0]
check('admin overrides upheld → overturned (reviewer decision kept on record)', r == ['overturned', '1', 'upheld', str(admid), str(r1id)], r)
check('override pays the earner through the approve path (escrow had room)', balance(eids[0]) - e1_before == 1500 and one(f"SELECT escrow_kobo FROM submission_appeals WHERE id = {a1}") == '2500')
check('override audited and logged as admin action', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.appeal_override_overturned' AND target_id = {a1}") == '1'
      and one(f"SELECT reviewer_role FROM reviewer_actions WHERE item_id = {a1} AND action = 'appeal_override_overturned'") == 'admin')
apost(a1, 'uphold', 'Second thoughts after override')
check('override is final: a second override refused', one(f"SELECT status FROM submission_appeals WHERE id = {a1}") == 'overturned')
msg, h = admin.flash(f'/appeals.php?id={a1}')
check('final message shown, no more decision form', 'final decision' in msg and 'Override the reviewer' not in h, msg)
check('earner emailed about the admin decision', 'successful' in mails_to(emails['e1'])[-1]['subject'] and 'admin' in mails_to(emails['e1'])[-1]['text'])

# override overturned -> upheld reverses money (reward on hold, wallet top-up returned)
adv_before, hold_before, rev_before = balance(aid), sysbal('system:reward_hold'), sysbal('system:revenue')
apost(a4, 'uphold', 'Admin review: the like is from another account.')
r = db(f"SELECT status, admin_override, override_from_status, reverse_transaction_id IS NOT NULL FROM submission_appeals WHERE id = {a4}")[0]
check('admin overrides overturned → upheld', r == ['upheld', '1', 'overturned', '1'], r)
check('reversal takes the held reward back and the margin from revenue', hold_before - sysbal('system:reward_hold') == 1000 and rev_before - sysbal('system:revenue') == 500)
check('the ₦15 wallet top-up is returned to the advertiser', balance(aid) - adv_before == 1500, balance(aid) - adv_before)
check('submission rejected again, campaign extra count back to 0', one(f"SELECT status FROM submissions WHERE id = {s4}") == 'rejected' and db(f"SELECT approved_count, extra_approved_count FROM campaigns WHERE id = {c2}")[0] == ['1', '0'])
check('reverse + unfund keys used once', one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key IN ('appeal-reverse:{a4}', 'appeal-unfund:{a4}')") == '2')
r = cron('hourly.php')
check('reversed held reward is never released by the hourly cron', 'rewards released: 0' in r.stdout and one(f"SELECT reward_status FROM submissions WHERE id = {s4}") == 'none', r.stdout)
ok, out = reconcile_ok()
check('reconciliation OK after an overturn is reversed', ok, out)

# reversal refused when the earner already spent the reward
w3 = balance(eids[2])
debit(eids[2], w3 // 100)
apost(a3, 'uphold', 'Admin review: should have been upheld.')
check('reversal refused when the earner no longer has the reward', one(f"SELECT status FROM submission_appeals WHERE id = {a3}") == 'overturned' and one(f"SELECT status FROM submissions WHERE id = {s3}") == 'approved')
msg, _ = admin.flash(f'/appeals.php?id={a3}')
check('admin told to recover the reward first', 'wallet adjustment' in msg, msg)
fund(eids[2], w3 // 100)

# admin decides an open appeal directly (final)
c5 = campaign(adv, 'Admin decides P5', 'admindecides', 1)
s8 = submit(e6, c5)
adv_decide(adv, c5, s8, 'reject', 'Blurry screenshot')
appeal(e6, s8)
a8 = appeal_id(s8)
apost(a8, 'uphold', 'Screenshot is unreadable.')
check('admin decides an open appeal: upheld, final, decided by admin', db(f"SELECT status, decided_by_role, admin_override FROM submission_appeals WHERE id = {a8}")[0] == ['upheld', 'admin', '1'])
rpost(rev1, a8, 'claim')
check('reviewer cannot claim an appeal that is decided', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a8}") == '0')
apost(a8, 'overturn', 'Trying to override admin')
check('admin decision cannot be overridden', one(f"SELECT status FROM submission_appeals WHERE id = {a8}") == 'upheld')

# ======================================================================
# 8. Stale queue
# ======================================================================
c6 = campaign(adv, 'Stale follow P5', 'stale', 2)
s9, s10 = submit(e5, c6), submit(e6, c6)
s, l, h = rev1.get('/stale.php')
check('fresh pending proofs are not in the stale queue', f'data-stale="{s9}"' not in h)
rev1.post('/stale.php', {'_csrf': rev1.csrf(h), 'submission_id': s9, 'action': 'claim'})
check('cannot claim a proof that is not stale', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'submission' AND item_id = {s9}") == '0')
db(f"UPDATE submissions SET submitted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id IN ({s9}, {s10})")
s, l, h = rev1.get('/stale.php')
check('stale proofs (24 h+) appear oldest first', f'data-stale="{s9}"' in h and f'data-stale="{s10}"' in h)
tok1 = rev1.csrf(h)
rev1.post('/stale.php', {'_csrf': tok1, 'submission_id': s9, 'action': 'approve'})
check('approve without a claim refused', one(f"SELECT status FROM submissions WHERE id = {s9}") == 'pending')
rev1.post('/stale.php', {'_csrf': tok1, 'submission_id': s9, 'action': 'claim'})
tok2 = rev2.csrf(rev2.get('/stale.php')[2])
rev2.post('/stale.php', {'_csrf': tok2, 'submission_id': s9, 'action': 'claim'})
check('stale claim locks out the other reviewer', one(f"SELECT reviewer_user_id FROM review_claims WHERE item_type = 'submission' AND item_id = {s9}") == str(r1id))
s, l, h = rev1.get('/stale.php')
check('claimed stale item shows instructions and decision buttons', 'data-claim="mine"' in h and 'Instructions (version 1)' in h and 'value="approve"' in h)
e5_before = balance(eids[4])
rev1.post('/stale.php', {'_csrf': tok1, 'submission_id': s9, 'action': 'approve'})
r = db(f"SELECT status, decision_source, decided_by_user_id FROM submissions WHERE id = {s9}")[0]
check('reviewer approves a stale proof: same money path, reviewer as decider', r == ['approved', 'reviewer', str(r1id)] and balance(eids[4]) - e5_before == 1500, r)
check('stale approval logged for reviewer pay', one(f"SELECT action FROM reviewer_actions WHERE item_type = 'submission' AND item_id = {s9}") == 'submission_approved'
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key = 'sub-approve:{s9}'") == '1')
rev1.post('/stale.php', {'_csrf': tok1, 'submission_id': s9, 'action': 'approve'})
check('second approve click pays nothing more', balance(eids[4]) - e5_before == 1500)
rev2.post('/stale.php', {'_csrf': tok2, 'submission_id': s10, 'action': 'claim'})
rev2.post('/stale.php', {'_csrf': tok2, 'submission_id': s10, 'action': 'reject', 'reason': ''})
check('stale reject without reason refused', one(f"SELECT status FROM submissions WHERE id = {s10}") == 'pending')
rev2.post('/stale.php', {'_csrf': tok2, 'submission_id': s10, 'action': 'reject', 'reason': 'Profile in the screenshot is not the campaign profile'})
check('reviewer rejects a stale proof', db(f"SELECT status, decision_source, decided_by_user_id FROM submissions WHERE id = {s10}")[0] == ['rejected', 'reviewer', str(r2id)])
check('earner emailed with reason and appeal option', 'not the campaign profile' in mails_to(emails['e6'])[-1]['text'] and 'appeal' in mails_to(emails['e6'])[-1]['text'])
appeal(e6, s10)
a10 = appeal_id(s10)
check('a reviewer rejection can be appealed', a10 is not None and one(f"SELECT rejection_source FROM submission_appeals WHERE id = {a10}") == 'reviewer')
rpost(rev2, a10, 'claim')
check('the reviewer who rejected cannot handle the appeal', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a10}") == '0')
rpost(rev1, a10, 'claim')
check('another reviewer can', one(f"SELECT reviewer_user_id FROM review_claims WHERE item_type = 'appeal' AND item_id = {a10}") == str(r1id))
rpost(rev1, a10, 'release')
s, l, h = admin.get('/submissions.php?status=stale')
check('admin stale list still works', s == 200)

# ======================================================================
# 9. Advertiser quality flag
# ======================================================================
fund(fid, 200)
cf = campaign(advf, 'Fussy follow P5', 'fussy', 4)
fs = [submit(ec, cf) for ec in (e1, e2, e3, e4)]
for sid in fs:
    adv_decide(advf, cf, sid, 'reject', 'I just do not like it')
fa = []
for ec, sid in zip((e1, e2, e3, e4), fs):
    appeal(ec, sid)
    fa.append(appeal_id(sid))


def flagged_on_admin():
    return f'data-flagged="{fid}"' in admin.get('/appeals.php?view=flags')[2]


rpost(rev1, fa[0], 'claim'); rpost(rev1, fa[0], 'uphold', 'Fair rejection this time.')
rpost(rev1, fa[1], 'claim'); rpost(rev1, fa[1], 'overturn', 'Proof is fine.')
rpost(rev1, fa[2], 'claim'); rpost(rev1, fa[2], 'overturn', 'Proof is fine.')
check('2 overturned: not flagged yet', not flagged_on_admin())
rpost(rev1, fa[3], 'claim'); rpost(rev1, fa[3], 'overturn', 'Proof is fine.')
s, l, h = admin.get('/appeals.php?view=flags')
check('3 overturned of 4 decided (75%) → flagged for admin', f'data-flagged="{fid}"' in h and '3 overturned of 4 decided appeals (75.0%)' in h)
s, l, h = admin.get('/users.php?role=advertiser&q=' + urllib.parse.quote(emails['advf']))
check('flag shown on the admin user list', f'data-quality-flag="{fid}"' in h)
cfid = cf
s, l, h = admin.get(f'/campaign.php?id={cfid}')
check('flag shown on the admin campaign view', 'Quality flag' in h and '3 overturned of 4 decided' in h)
check('no automatic penalty: advertiser still active, campaign untouched', one(f"SELECT status FROM users WHERE id = {fid}") == 'active')
s, l, h = admin.get('/task-settings.php')
form = {'_csrf': admin.csrf(h), 'section': 'appeals'}
for k, v in re.findall(r'name="((?:appeals|reviews)_[a-z_]+)"[^>]*?value="([^"]*)"', h):
    form[k] = v
admin.post('/task-settings.php', dict(form, appeals_flag_rate_percent='80'))
check('rate threshold 80% clears the flag (75%)', not flagged_on_admin())
admin.post('/task-settings.php', dict(form, appeals_flag_rate_percent='30', appeals_flag_min_overturns='4'))
check('minimum 4 overturns clears the flag', not flagged_on_admin())
admin.post('/task-settings.php', dict(form, appeals_flag_min_overturns='3'))
check('defaults restored → flagged again', flagged_on_admin())
db(f"UPDATE submission_appeals SET resolved_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY) WHERE id IN ({fa[1]}, {fa[2]})")
check('overturns older than 30 days leave the window', not flagged_on_admin())
db(f"UPDATE submission_appeals SET resolved_at = UTC_TIMESTAMP() WHERE id IN ({fa[1]}, {fa[2]})")
check('advertiser with escrow-funded overturns is not flagged by auto/reviewer rejections', f'data-flagged="{bid}"' not in admin.get('/appeals.php?view=flags')[2])

# ======================================================================
# 10. Authorization between portals
# ======================================================================
for label, cl in (('earner', e1), ('advertiser', adv)):
    s, l, h = cl.get(R + '/appeals.php')
    check(f'{label} session cannot open the reviewer queue', s == 303 and l == 'login.php')
    s, l, h = cl.post(R + '/appeal.php?id=' + str(a10), {'_csrf': cl.csrf(), 'appeal_id': a10, 'action': 'claim'})
    check(f'{label} cannot claim via the reviewer portal', one(f"SELECT COUNT(*) FROM review_claims WHERE item_type = 'appeal' AND item_id = {a10}") == '0')
x = Client(R)
check('earner credentials cannot sign in to the reviewer portal', not x.login(emails['e1'], PW))
check('advertiser credentials cannot sign in to the reviewer portal', not Client(R).login(emails['adv'], PW))
check('admin credentials cannot sign in to the reviewer portal', not Client(R).login(emails['admin'], PW))
check('reviewer credentials cannot sign in to the admin portal', not Client(ADM).login(emails['rev1'], PW))
s, l, h = rev1.get(ADM + '/appeals.php')
check('reviewer session cannot open admin appeals', s == 303 and l == 'login.php')
s, l, h = rev1.post(ADM + '/appeals.php?id=' + str(a10), {'_csrf': rev1.csrf(), 'appeal_id': a10, 'action': 'overturn', 'note': 'reviewer pretending to be admin'})
check('reviewer cannot use admin override', one(f"SELECT status FROM submission_appeals WHERE id = {a10}") == 'open')
s, l, h = rev1.get(ADM + '/task-settings.php')
check('reviewer cannot open task settings', s == 303 and l == 'login.php')
s, l, h = e1.get('/appeals.php')
check('earner portal has no appeals queue', 'data-appeal=' not in h)
s, l, h = adv.get('/stale.php')
check('advertiser portal has no stale queue', 'data-stale=' not in h)
s, l, h = Client(R).get('/appeals.php')
check('signed-out visitor redirected to reviewer sign-in', s == 303 and l == 'login.php')
proof_other = int(one(f"SELECT pr.id FROM submission_proofs pr JOIN submissions s ON s.id = pr.submission_id WHERE s.id = {s7}"))
st, hd, body = rev1.req(f'/proof.php?id={proof_other}', binary=True)
check('reviewer cannot open proofs outside the queues', st == 404)
st, hd, body = adv.req(f'/proof.php?id={proof_orig}', binary=True)
check('campaign owner can still open the proof', st == 200)
st, hd, body = advb.req(f'/proof.php?id={proof_orig}', binary=True)
check("other advertiser cannot open the proof", st == 404)
for c, p in ((rev1, '/appeals.php'), (rev1, f'/appeal.php?id={a10}'), (rev1, '/stale.php'), (admin, '/appeals.php')):
    s, hd, b = c.req(p)
    check('private no-store headers on ' + p, 'no-store' in (hd.get('Cache-Control') or ''))

# dashboards
s, l, h = rev1.get('/dashboard.php')
check('reviewer dashboard shows queue numbers and links', 'Open appeals' in h and 'Stale proofs' in h and 'href="appeals.php"' in h and 'href="history.php"' in h)
s, l, h = admin.get('/dashboard.php')
check('admin dashboard links to appeals', 'href="appeals.php"' in h)
s, l, h = admin.get('/campaigns.php')
check('admin marketplace tabs include Appeals', 'href="appeals.php"' in h)

# ======================================================================
# Final reconciliation + tamper detection
# ======================================================================
ok, out = reconcile_ok()
check('final reconciliation OK (ledger, escrow, revenue, hold, receivable)', ok, out)
db("UPDATE ledger_accounts SET balance_kobo = balance_kobo + 1 WHERE code = 'system:advertiser_receivable'")
r = cron('daily.php')
check('reconciliation detects a receivable mismatch', r.returncode == 2 and 'receivable' in r.stdout.lower(), r.stdout)
db("UPDATE ledger_accounts SET balance_kobo = balance_kobo - 1 WHERE code = 'system:advertiser_receivable'")
ok, out = reconcile_ok()
check('reconciliation clean again', ok, out)
v = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', '-B', re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)],
                   input=open(ROOT + '/database/verify/campaign_funding_reconciliation.sql').read(), capture_output=True, text=True)
check('read-only funding verification SQL returns no problem rows', v.returncode == 0 and v.stdout.strip() == '', v.stdout + v.stderr)

fails = [l for l, ok in results if not ok]
print(f'\n{len(results) - len(fails)}/{len(results)} passed')
sys.exit(1 if fails else 0)
