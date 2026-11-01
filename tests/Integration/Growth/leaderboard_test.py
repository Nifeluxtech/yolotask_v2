#!/usr/bin/env python3
"""YOLOTASK V2 Phase 7 integration test: leaderboard + levels page.

Covers period boundaries in Lagos time (unit checks through lb_query.php and
live rows either side of Monday 00:00 / the 1st 00:00 WAT), ranking and ties
(earlier achiever first), staff / suspended accounts left out, rejected and
pending work not counted, reversed and skipped referral payouts left out, the
viewer's own rank row outside the top 50, name masking (first name + last
initial, avatar initials, no surname on the earner page), medals and "You",
the admin leaders page (full names + ids, period labels), leadership rewards
(ledger via system:bonuses, idempotency, audit, notification + email,
validation limits, history), authorization (earner cannot reward, advertiser
cannot open the admin page, sessions required), CSRF, the earner levels page
(all active levels in order, current level, progress and what is missing,
admin edits reflected, levels paused), dashboard links, and the public levels
page (no user data). Fixture rows (campaigns, submissions, referrals,
payouts) are removed at the end and the ledger reconciliation must pass.

Needs the Phase 6 environment, migration 0012 and the servers from
tests/Integration/PaymentCallbacks/run-servers.sh. Env: YOLOTASK_CONFIG_DIR
(default /tmp/ytcfg), YT_MAIL_LOG, YT_DB_CLI.
"""
import datetime as dt
import json, os, re, shlex, subprocess, sys, time
import http.cookiejar, urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
MAIL = os.environ.get('YT_MAIL_LOG', '/tmp/ytrt/logs/mail.log')
M, E, A, R, ADM = 'http://127.0.0.1:8401', 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8404', 'http://127.0.0.1:8405'
UA_PHONE = 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'
WAT = dt.timezone(dt.timedelta(hours=1))  # Africa/Lagos: UTC+1 all year
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

    def req(self, path, data=None):
        url = path if path.startswith('http') else self.base + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(url, data=body, headers={'User-Agent': self.ua})
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

    @staticmethod
    def field(html, name):
        m = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)
        return m.group(1) if m else ''

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


def make_user(role, email, pw, name, status='active'):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name}', '{email}', '{h}', '{role}', '{status}', UTC_TIMESTAMP())")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def balance(u):
    r = db(f"SELECT available_kobo FROM wallets WHERE user_id = {u}")
    return int(r[0][0]) if r else 0


def sysbal(code):
    return int(one(f"SELECT balance_kobo FROM ledger_accounts WHERE code = '{code}'") or 0)


def setting(key, value):
    db(f"INSERT INTO settings (setting_key, setting_value) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE setting_value = '{value}'")


def mails_to(to):
    try:
        return [json.loads(l) for l in open(MAIL) if json.loads(l)['to'] == to]
    except FileNotFoundError:
        return []


def lbq(*args):
    out = subprocess.run(['php', os.path.join(ROOT, 'tests/Integration/Growth/lb_query.php')] + [str(a) for a in args],
                         capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
    return json.loads(out.stdout) if out.returncode == 0 and out.stdout else {'error': out.stderr}


def find(board, uid_):
    for r in board['rows']:
        if r['user_id'] == uid_:
            return r
    return None


def rows_html(html):
    return re.findall(r'<li class="lb-row[^"]*"[^>]*>.*?</li>', html, re.S)


def fmt(dtv):
    return dtv.strftime('%Y-%m-%d %H:%M:%S')


tag = str(int(time.time()))
lt = ''.join(chr(97 + int(d)) for d in tag)  # letters-only tag for names
PW = 'TestPass!2026abc'
POOL = f'LB{tag}'
my_refs = []

# ======================================================================
# 1. Period boundaries (unit, fixed clock)
# ======================================================================
b = lbq('bounds', 'week', '2026-11-01T08:16:00+01:00')
check('week of Sun 1 Nov 08:16 WAT starts Mon 26 Oct 00:00 WAT (25 Oct 23:00 UTC)', b.get('from') == '2026-10-25 23:00:00' and b.get('label') == 'Week of 2026-10-26' and b.get('to') == '2026-11-01 07:16:00', b)
b = lbq('bounds', 'week', '2026-10-26T00:00:00+01:00')
check('Monday 00:00:00 WAT is the first second of its own week', b.get('from') == '2026-10-25 23:00:00', b)
b = lbq('bounds', 'week', '2026-10-25T23:59:59+01:00')
check('Sunday 23:59:59 WAT still belongs to the previous week', b.get('from') == '2026-10-18 23:00:00' and b.get('label') == 'Week of 2026-10-19', b)
b = lbq('bounds', 'week', '2026-10-25T23:30:00Z')
check('23:30 UTC on Sunday is already Monday in Lagos (new week)', b.get('from') == '2026-10-25 23:00:00', b)
b = lbq('bounds', 'month', '2026-11-01T00:00:00+01:00')
check('month starts on the 1st 00:00 WAT (31 Oct 23:00 UTC), label "November 2026"', b.get('from') == '2026-10-31 23:00:00' and b.get('label') == 'November 2026', b)
b = lbq('bounds', 'month', '2026-10-31T22:59:59Z')
check('31 Oct 22:59:59 UTC (23:59:59 WAT) is still October', b.get('from') == '2026-09-30 23:00:00' and b.get('label') == 'October 2026', b)
b = lbq('bounds', 'month', '2026-03-15T12:00:00+01:00')
check('mid-month start is the 1st of that month', b.get('from') == '2026-02-28 23:00:00', b)
mk = lbq('mask', '  adaeze   chukwu okafor ')
check('mask: first name + last initial, capitalised, avatar initials', mk.get('masked') == 'Adaeze O.' and mk.get('initials') == 'AO', mk)
mk = lbq('mask', 'Madonna')
check('mask: a one-word name stays as it is', mk.get('masked') == 'Madonna' and mk.get('initials') == 'M', mk)

# ======================================================================
# 2. Fixtures
# ======================================================================
now = dt.datetime.now(dt.timezone.utc).replace(tzinfo=None, microsecond=0)
now_wat = dt.datetime.now(WAT)
week_start_wat = (now_wat - dt.timedelta(days=now_wat.weekday())).replace(hour=0, minute=0, second=0, microsecond=0)
month_start_wat = now_wat.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
W = week_start_wat.astimezone(dt.timezone.utc).replace(tzinfo=None)
MO = month_start_wat.astimezone(dt.timezone.utc).replace(tzinfo=None)
latest_start = max(W, MO)
earliest_start = min(W, MO)
if now - latest_start < dt.timedelta(minutes=3):
    print('waiting: the period started less than 3 minutes ago')
    time.sleep(200)
    now = dt.datetime.now(dt.timezone.utc).replace(tzinfo=None, microsecond=0)
inside = latest_start + dt.timedelta(seconds=60)  # inside both periods
week_label = 'Week of ' + week_start_wat.strftime('%Y-%m-%d')
month_label = month_start_wat.strftime('%B %Y')

base_w = int(one(f"""SELECT COALESCE(MAX(c), 0) FROM (SELECT COUNT(*) c FROM submissions s JOIN users u ON u.id = s.earner_user_id
    WHERE s.status = 'approved' AND s.decided_at >= '{fmt(earliest_start)}' AND u.role = 'earner' AND u.status = 'active' GROUP BY s.earner_user_id) x"""))
base_r = int(one(f"""SELECT COALESCE(MAX(c), 0) FROM (SELECT COUNT(*) c FROM referral_payouts p JOIN users u ON u.id = p.referrer_user_id
    WHERE p.status = 'paid' AND p.amount_kobo > 0 AND p.created_at >= '{fmt(earliest_start)}' AND u.status = 'active' GROUP BY p.referrer_user_id) x"""))
B = max(base_w, 2) + 10
B2 = base_r + 3
POOLN = max(B + 12, 75)

em = {k: f'lb{k}{tag}@gmail.com' for k in ['adv', 'admin', 'rev', 'w1', 'w2', 'w3', 'wstaff', 'wsusp', 'v', 'nv', 'bw', 'bm', 'lv',
                                            'ra', 'rb', 'rc', 'rd', 'rstaff', 'rsusp', 'ref']}
name = {
    'w1': f'Wone{lt} Surname{lt}a', 'w2': f'Wtwo{lt} Surname{lt}b', 'w3': f'Wthree{lt} Surname{lt}c',
    'wstaff': f'Staffw{lt} Reviewer', 'wsusp': f'Suspw{lt} Banned', 'v': f'Viewer{lt} Lowrank', 'nv': f'Nobody{lt} Idle',
    'bw': f'Bweek{lt} Edge', 'bm': f'Bmonth{lt} Edge', 'lv': f'Level{lt} Climber',
    'ra': f'Refa{lt} Okonkwo{lt}', 'rb': f'Refb{lt} Advert', 'rc': f'Refc{lt} Later', 'rd': f'Refd{lt} Reversed',
    'rstaff': f'Staffr{lt} Admin', 'rsusp': f'Suspr{lt} Banned',
}
adv = make_user('advertiser', em['adv'], PW, 'Ade Advertiser')
admid = make_user('admin', em['admin'], PW, 'Grace Admin')
U = {}
for k in ['w1', 'w2', 'w3', 'v', 'nv', 'bw', 'bm', 'lv', 'ra', 'rc', 'rd']:
    U[k] = make_user('earner', em[k], PW, name[k])
U['rb'] = make_user('advertiser', em['rb'], PW, name['rb'])
U['wstaff'] = make_user('reviewer', em['wstaff'], PW, name['wstaff'])
U['rstaff'] = make_user('reviewer', em['rstaff'], PW, name['rstaff'])
U['wsusp'] = make_user('earner', em['wsusp'], PW, name['wsusp'], 'suspended')
U['rsusp'] = make_user('earner', em['rsusp'], PW, name['rsusp'], 'suspended')
fillers = [make_user('earner', f'lbf{i}x{tag}@gmail.com', PW, f'Filler{lt} Number{i}') for i in range(50)]

db(f"""INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements,
    unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, status)
    SELECT {adv}, (SELECT MIN(id) FROM platforms), (SELECT MIN(id) FROM task_types), '{POOL}', 'https://example.com/lb', 'example.com/lb',
    SHA2(CONCAT('{POOL}', seq), 256), 'Leaderboard fixture', 'Screenshot', 300, 250, 1, 300, 'completed' FROM seq_1_to_{POOLN}""")


def subs(user, n, at, status='approved', offset=0):
    decided = f"'{fmt(at)}'" if status != 'pending' else 'NULL'
    db(f"""INSERT INTO submissions (campaign_id, earner_user_id, status, instructions_version, unit_price_kobo, unit_reward_kobo, submitted_at, auto_approve_at, decided_at, decision_source)
        SELECT id, {user}, '{status}', 1, 300, 250, '{fmt(at)}', '{fmt(at + dt.timedelta(days=2))}', {decided}, {"'advertiser'" if status != 'pending' else 'NULL'}
        FROM campaigns WHERE title = '{POOL}' ORDER BY id LIMIT {n} OFFSET {offset}""")


def payouts(referrer, n, at, amount, status='paid'):
    ref = one(f"SELECT id FROM referrals WHERE referrer_user_id = {referrer} AND code_used = 'LBTEST'")
    if ref is None:
        referee = make_user('earner', f'lbref{referrer}x{tag}@gmail.com', PW, f'Referee{lt} Person')
        db(f"INSERT INTO referrals (referrer_user_id, referee_user_id, referee_role, code_used, status) VALUES ({referrer}, {referee}, 'earner', 'LBTEST', 'active')")
        ref = one(f"SELECT id FROM referrals WHERE referrer_user_id = {referrer} AND code_used = 'LBTEST'")
        my_refs.append(int(ref))
    referee = one(f"SELECT referee_user_id FROM referrals WHERE id = {ref}")
    db(f"""INSERT INTO referral_payouts (referral_id, referrer_user_id, referee_user_id, kind, base_kobo, amount_kobo, status, created_at)
        SELECT {ref}, {referrer}, {referee}, 'commission', 0, {amount}, '{status}', '{fmt(at)}' FROM seq_1_to_{n}""")


def cleanup():
    db(f"DELETE FROM submissions WHERE campaign_id IN (SELECT id FROM campaigns WHERE title = '{POOL}')")
    db(f"DELETE FROM campaigns WHERE title = '{POOL}'")
    if my_refs:
        ids = ','.join(str(i) for i in my_refs)
        db(f"DELETE FROM referral_payouts WHERE referral_id IN ({ids})")
        db(f"DELETE FROM referrals WHERE id IN ({ids})")
    db(f"DELETE FROM earner_levels WHERE name IN ('Zen{lt}', 'Zenb{lt}', 'Hid{lt}')")
    setting('levels.enabled', '1')


try:
    # Workers: W1 > W2 = W3 (W2 earlier) ; staff + suspended would win but must not show.
    subs(U['w1'], B + 5, inside + dt.timedelta(seconds=30))
    subs(U['w1'], 3, inside, 'rejected', offset=B + 5)
    subs(U['w1'], 2, inside, 'pending', offset=B + 8)
    subs(U['w2'], B + 3, inside + dt.timedelta(seconds=10))
    subs(U['w3'], B + 3, inside + dt.timedelta(seconds=20))
    subs(U['wstaff'], B + 10, inside)
    subs(U['wsusp'], B + 10, inside)
    for f in fillers:
        subs(f, 2, inside)
    subs(U['v'], 1, inside + dt.timedelta(seconds=40))
    # boundary users
    subs(U['bw'], 7, W - dt.timedelta(seconds=1))
    subs(U['bw'], 2, W, offset=7)
    subs(U['bm'], 5, MO - dt.timedelta(seconds=1))
    subs(U['bm'], 3, MO, offset=5)
    # levels user: old history (outside every period)
    old = dt.datetime(2020, 1, 1, 12, 0, 0)
    subs(U['lv'], 60, old)
    subs(U['lv'], 10, old, 'rejected', offset=60)
    # Referrers
    payouts(U['ra'], B2 + 4, inside + dt.timedelta(seconds=30), 500)
    payouts(U['rb'], B2 + 3, inside + dt.timedelta(seconds=10), 400)
    payouts(U['rc'], B2 + 3, inside + dt.timedelta(seconds=20), 400)
    payouts(U['rd'], B2 + 1, inside, 300)
    payouts(U['rd'], 6, inside, 300, 'reversed')
    payouts(U['rd'], 3, inside, 0, 'skipped')
    payouts(U['rstaff'], B2 + 10, inside, 900)
    payouts(U['rsusp'], B2 + 10, inside, 900)
    payouts(U['ra'], 4, earliest_start - dt.timedelta(seconds=1), 500)  # before both periods

    # ======================================================================
    # 3. Ranking, ties, exclusions (library)
    # ======================================================================
    for period in ('week', 'month'):
        g = lbq('get', 'workers', period)
        r1, r2, r3 = find(g, U['w1']), find(g, U['w2']), find(g, U['w3'])
        check(f'[{period}] workers: W1 ranks 1st with {B + 5} approved tasks and their rewards', r1 and r1['rank'] == 1 and r1['count'] == B + 5 and r1['total_kobo'] == (B + 5) * 250, r1)
        check(f'[{period}] workers tie: W2 (earlier) 2nd, W3 3rd', r2 and r3 and r2['rank'] == 2 and r3['rank'] == 3, (r2, r3))
        check(f'[{period}] rejected and pending work never counts', r1 and r1['count'] == B + 5)
        check(f'[{period}] staff and suspended earners are left out', find(g, U['wstaff']) is None and find(g, U['wsusp']) is None)
        check(f'[{period}] at most 50 rows, ranks 1..n', len(g['rows']) <= 50 and [r['rank'] for r in g['rows']] == list(range(1, len(g['rows']) + 1)))
        rr = lbq('get', 'referrers', period)
        a_, b_, c_, d_ = find(rr, U['ra']), find(rr, U['rb']), find(rr, U['rc']), find(rr, U['rd'])
        check(f'[{period}] referrers: RA 1st, counts only payouts inside the period', a_ and a_['rank'] == 1 and a_['count'] == B2 + 4 and a_['total_kobo'] == (B2 + 4) * 500, a_)
        check(f'[{period}] referrers tie: advertiser RB (earlier) 2nd, RC 3rd', b_ and c_ and b_['rank'] == 2 and c_['rank'] == 3, (b_, c_))
        check(f'[{period}] reversed and skipped payouts are left out', d_ is not None and d_['count'] == B2 + 1 and d_['total_kobo'] == (B2 + 1) * 300, d_)
        check(f'[{period}] staff and suspended referrers are left out', find(rr, U['rstaff']) is None and find(rr, U['rsusp']) is None)

    # boundaries with live rows
    for key, times in (('bw', [W - dt.timedelta(seconds=1)] * 7 + [W] * 2), ('bm', [MO - dt.timedelta(seconds=1)] * 5 + [MO] * 3)):
        for period, start in (('week', W), ('month', MO)):
            exp = sum(1 for t in times if t >= start)
            g = lbq('get', 'workers', period, U[key])
            row = find(g, U[key]) or g.get('viewer') or {}
            got = row.get('count', 0)
            check(f'boundary {key} [{period}]: {exp} task(s) at/after {fmt(start)} UTC counted, earlier ones not', got == exp, row)

    # viewer outside the top 50
    g = lbq('get', 'workers', 'week', U['v'])
    v = g.get('viewer')
    exp_rank = 1 + int(one(f"""SELECT COUNT(*) FROM (SELECT s.earner_user_id uid, COUNT(*) c, MAX(s.decided_at) l FROM submissions s JOIN users u ON u.id = s.earner_user_id
        WHERE s.status = 'approved' AND s.decided_at >= '{fmt(W)}' AND u.role = 'earner' AND u.status = 'active' GROUP BY s.earner_user_id) x
        WHERE c > 1 OR (c = 1 AND (l < '{fmt(inside + dt.timedelta(seconds=40))}' OR (l = '{fmt(inside + dt.timedelta(seconds=40))}' AND uid < {U['v']})))"""))
    check('viewer outside the top 50 gets their own rank row', v is not None and find(g, U['v']) is None and v['rank'] == exp_rank and v['rank'] > 50 and v['count'] == 1 and v['is_viewer'], (v, exp_rank))
    g = lbq('get', 'workers', 'week', U['nv'])
    check('viewer with nothing this period: own row, no rank', g.get('viewer') is not None and g['viewer']['rank'] is None and g['viewer']['count'] == 0)
    g = lbq('get', 'workers', 'week', U['w2'])
    check('viewer inside the top 50 gets no extra row', g.get('viewer') is None and find(g, U['w2'])['is_viewer'])

    # ======================================================================
    # 4. Earner page (HTTP)
    # ======================================================================
    C = {k: Client(E) for k in ['w1', 'w2', 'v', 'nv', 'bw', 'lv', 'ra']}
    check('earners sign in', all(C[k].login(em[k], PW) for k in C))
    s, l, h = C['w2'].get('/leaderboard.php')
    rows = rows_html(h)
    check('leaderboard page loads with toggles (This Week / This Month, Top Workers / Top Referrers)', s == 200 and 'This Week' in h and 'This Month' in h and 'Top Workers' in h and 'Top Referrers' in h)
    check('default board is Top Workers, This Week, label shown', 'Top Workers · This Week' in h and week_label in h)
    w1_mask, w2_mask = f'Wone{lt} S.', f'Wtwo{lt} S.'
    check('rank 1 row has the gold medal and the masked name', rows and 'lb-medal lb-gold' in rows[0] and 'gold medal' in rows[0] and w1_mask in rows[0], rows[:1])
    check('ranks 2 and 3 have silver and bronze medals', len(rows) > 3 and 'lb-medal lb-silver' in rows[1] and 'lb-medal lb-bronze' in rows[2] and 'lb-medal' not in rows[3])
    check('avatar initials come from the masked name', f'lb-avatar" aria-hidden="true">WS<' in rows[0])
    check('viewer row highlighted with "You"', any('lb-you' in r and w2_mask in r and '>You<' in r for r in rows))
    check("other earners' surnames never reach the earner page", f'Surname{lt}a' not in h and f'Surname{lt}c' not in h)
    check('staff and suspended names are not on the page', f'Staffw{lt}' not in h and f'Suspw{lt}' not in h)
    check('count and amount shown', f'{B + 5} tasks' in rows[0] and '₦' in rows[0])
    s, l, h = C['v'].get('/leaderboard.php?board=workers&period=week')
    check('viewer outside the top 50 sees "Your position" with their rank', 'data-viewer-rank' in h and 'Your position' in h and f'aria-label="Rank {exp_rank}"' in h and f'Viewer{lt} L.' in h.split('data-viewer-rank')[1])
    s, l, h = C['nv'].get('/leaderboard.php?period=month')
    check('idle viewer sees "not on the board yet"', 'not on the board yet this month' in h and f'Nobody{lt} I.' in h)
    s, l, h = C['ra'].get('/leaderboard.php?board=referrers&period=month')
    rows = rows_html(h)
    check('Top Referrers board: RA first with masked name and payout count', s == 200 and 'Top Referrers · This Month' in h and rows and f'Refa{lt} O.' in rows[0] and f'{B2 + 4} payouts' in rows[0] and 'lb-you' in rows[0])
    s, l, hw = C['w1'].get('/leaderboard.php?board=referrers&period=month')
    check("referrer's surname is masked for other earners", f'Refa{lt} O.' in hw and f'Okonkwo{lt}' not in hw)
    check('month label shown', month_label in h)
    s, l, h = C['bw'].get('/leaderboard.php?period=week')
    check('boundary user sees exactly their in-week count', '2 tasks' in h.split('data-viewer-rank')[1] if 'data-viewer-rank' in h else any(f'Bweek{lt}' in r and '2 tasks' in r for r in rows_html(h)))
    s, l, h = C['w1'].get('/leaderboard.php?board=bogus&period=year')
    check('unknown toggles fall back to Top Workers / This Week', s == 200 and 'Top Workers · This Week' in h)

    # ======================================================================
    # 5. Admin leaders page + rewards
    # ======================================================================
    admin = Client(ADM)
    check('admin signs in', admin.login(em['admin'], PW))
    s, l, h = admin.get('/leaders.php?board=workers&period=week')
    check('admin leaders page: full names and user ids', s == 200 and name['w1'] in h and f"User #{U['w1']}" in h)
    check('admin page prefills the week label', f'value="{week_label}"' in h)
    check('admin page leaves out staff and suspended', name['wstaff'] not in h and name['wsusp'] not in h)
    s, l, h2 = admin.get('/leaders.php?board=referrers&period=month')
    check('admin referrers board + month label', name['ra'] in h2 and f'value="{month_label}"' in h2 and f"User #{U['rb']}" in h2)
    s, l, hd = admin.get('/dashboard.php')
    check('admin dashboard links to the leaderboard', 'href="leaders.php"' in hd)
    s, l, hg = admin.get('/referrals.php')
    check('growth tabs include Leaderboard', 'href="leaders.php"' in hg)

    def reward_form(html, user):
        m = re.search(r'<form method="post" action="([^"]+)" class="stack">(?:(?!</form>).)*?name="user_id" value="' + str(user) + r'"(?:(?!</form>).)*</form>', html, re.S)
        if not m:
            return None, {}
        f = m.group(0)
        fields = dict(re.findall(r'name="([a-z_]+)" value="([^"]*)"', f))
        return m.group(1).replace('&amp;', '&'), fields

    action, f = reward_form(h, U['w1'])
    check('each row has a reward form with idempotency key, rank and period', action and f.get('rank') == '1' and re.fullmatch(r'[a-f0-9]{32}', f.get('idem', '')) and f.get('period_label') == week_label, f)
    bal0, sys0 = balance(U['w1']), sysbal('system:bonuses')
    post = dict(f, amount='1500', note='Top worker of the week')
    s, l, _ = admin.post('/' + action, post)
    msg, hh = admin.flash('/leaders.php')
    check('reward paid (₦1,500)', s == 303 and 'Leadership bonus of ₦1,500.00 paid' in msg, msg)
    check('wallet credited 150000 kobo', balance(U['w1']) - bal0 == 150000, balance(U['w1']) - bal0)
    check('system:bonuses moved by the same amount', abs(sysbal('system:bonuses') - sys0) == 150000)
    tx = db(f"SELECT id, type, description FROM ledger_transactions WHERE idempotency_key = 'bonus:{admid}:{f['idem']}'")
    check('ledger transaction uses the admin bonus key and type', len(tx) == 1 and tx[0][1] == 'bonus' and week_label in tx[0][2], tx)
    rw = db(f"SELECT board, period, period_label, rank_at_reward, amount_kobo, note, admin_user_id FROM leaderboard_rewards WHERE user_id = {U['w1']}")
    check('reward history row recorded', rw == [['workers', 'week', week_label, '1', '150000', 'Top worker of the week', str(admid)]], rw)
    check('audit: admin.leader_rewarded', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.leader_rewarded' AND actor_user_id = {admid} AND target_id = {U['w1']}") == '1')
    nt = db(f"SELECT category, title, url FROM notifications WHERE user_id = {U['w1']} AND title LIKE 'Leadership bonus%'")
    check('notification "Leadership bonus" in the rewards category', nt == [['rewards', 'Leadership bonus: ₦1,500.00', 'wallet.php']], nt)
    time.sleep(0.3)
    check('email sent to the winner', any('Leadership bonus' in m.get('subject', '') for m in mails_to(em['w1'])))
    s, l, _ = admin.post('/' + action, post)
    check('repeat of the same form pays nothing more', balance(U['w1']) - bal0 == 150000 and one(f"SELECT COUNT(*) FROM leaderboard_rewards WHERE user_id = {U['w1']}") == '1'
          and one(f"SELECT COUNT(*) FROM notifications WHERE user_id = {U['w1']} AND title LIKE 'Leadership bonus%'") == '1')
    msg, hh = admin.flash('/leaders.php')
    check('history lists the reward with note and period', 'Reward history' in hh and 'Top worker of the week' in hh and name['w1'] in hh.split('Reward history')[1])

    def fresh(user, **over):
        s, l, h = admin.get('/leaders.php?board=workers&period=week')
        action, f = reward_form(h, user)
        if f == {}:
            f = {'_csrf': Client.field(h, '_csrf'), 'action': 'reward', 'user_id': str(user), 'rank': '0', 'board': 'workers', 'period': 'week',
                 'idem': os.urandom(16).hex(), 'period_label': week_label}
            action = 'leaders.php?board=workers&period=week'
        f.update({'amount': '100', 'note': 'x'})
        f.update(over)
        return action, f

    n_before = int(one('SELECT COUNT(*) FROM leaderboard_rewards'))
    cases = [
        ('amount ₦0 refused', U['w2'], {'amount': '0'}, 'between ₦1 and ₦100,000'),
        ('amount ₦0.50 refused', U['w2'], {'amount': '0.50'}, 'between ₦1 and ₦100,000'),
        ('amount above ₦100,000 refused', U['w2'], {'amount': '100000.01'}, 'between ₦1 and ₦100,000'),
        ('empty amount refused', U['w2'], {'amount': ''}, 'Enter the reward'),
        ('note over 140 characters refused', U['w2'], {'note': 'n' * 141}, '140 characters'),
        ('empty period label refused', U['w2'], {'period_label': ''}, 'label'),
        ('period label over 40 characters refused', U['w2'], {'period_label': 'p' * 41}, 'label'),
        ('bad idempotency key refused', U['w2'], {'idem': 'nothex'}, 'form expired'),
        ('suspended user cannot be rewarded', U['wsusp'], {}, 'not active'),
        ('staff cannot be rewarded', U['wstaff'], {}, 'earner and advertiser'),
        ('unknown user refused', 99999999, {}, 'earner and advertiser'),
    ]
    for label, user, over, needle in cases:
        action, f = fresh(user, **over)
        admin.post('/' + action, f)
        msg, _ = admin.flash('/leaders.php')
        check(label, needle in msg and int(one('SELECT COUNT(*) FROM leaderboard_rewards')) == n_before, msg)
    b2 = balance(U['w2'])
    action, f = fresh(U['w2'], amount='1')
    admin.post('/' + action, f)
    action, f = fresh(U['w2'], amount='100000', note='')
    admin.post('/' + action, f)
    check('₦1 and ₦100,000 accepted (limits inclusive); empty note becomes "Leadership bonus"', balance(U['w2']) - b2 == 100 + 10000000
          and one(f"SELECT note FROM leaderboard_rewards WHERE user_id = {U['w2']} ORDER BY id DESC LIMIT 1") == 'Leadership bonus')
    action, f = fresh(U['rb'], amount='250')
    f.update({'board': 'referrers', 'period': 'month', 'period_label': month_label})
    admin.post('/' + action, f)
    check('advertiser referrers can be rewarded too (referrers board recorded)', one(f"SELECT CONCAT(board, '/', period, '/', period_label) FROM leaderboard_rewards WHERE user_id = {U['rb']}") == f'referrers/month/{month_label}')

    # CSRF
    n_before = int(one('SELECT COUNT(*) FROM leaderboard_rewards'))
    action, f = fresh(U['w3'])
    f['_csrf'] = 'wrong'
    s, l, _ = admin.post('/' + action, f)
    msg, _ = admin.flash('/leaders.php')
    check('CSRF: wrong token pays nothing', 'form expired' in msg and int(one('SELECT COUNT(*) FROM leaderboard_rewards')) == n_before, msg)
    del f['_csrf']
    admin.post('/' + action, f)
    check('CSRF: missing token pays nothing', int(one('SELECT COUNT(*) FROM leaderboard_rewards')) == n_before)
    s, l, hh = admin.get('/leaders.php')
    check('history shows at most 50 rewards', len(re.findall(r'<li class="user-row">', hh.split('Reward history')[1])) <= 50)

    # ======================================================================
    # 6. Authorization
    # ======================================================================
    n_before = int(one('SELECT COUNT(*) FROM leaderboard_rewards'))
    s, l, hx = C['w1'].post('/leaders.php', {'action': 'reward', 'user_id': U['w1'], 'amount': '5000', 'period_label': 'x', 'idem': os.urandom(16).hex(), 'board': 'workers', 'period': 'week'})
    check('earner portal has no admin leaders page or reward endpoint', not os.path.exists(ROOT + '/apps/earner/public/leaders.php') and 'Reward history' not in hx
          and int(one('SELECT COUNT(*) FROM leaderboard_rewards')) == n_before)
    s, l, _ = C['w1'].post('/leaderboard.php', {'action': 'reward', 'user_id': U['w1'], 'amount': '5000', 'period_label': 'x', 'idem': os.urandom(16).hex(), 'board': 'workers', 'period': 'week'})
    check('posting a reward to the earner leaderboard pays nothing', int(one('SELECT COUNT(*) FROM leaderboard_rewards')) == n_before)
    check('an earner cannot sign in to the admin portal', not Client(ADM).login(em['w1'], PW))
    advc = Client(ADM)
    check('an advertiser cannot sign in to the admin portal', not advc.login(em['adv'], PW))
    s, l, _ = advc.get('/leaders.php')
    check('admin leaders page needs an admin session', s == 303 and l == 'login.php')
    revc = Client(ADM)
    revc.login(em['wstaff'], PW)
    s, l, _ = revc.get('/leaders.php')
    check('a reviewer cannot open the admin leaders page', s in (303, 403))
    advp = Client(A)
    check('advertiser signs in to their portal', advp.login(em['adv'], PW))
    s, l, hx = advp.get('/leaderboard.php')
    check('advertiser portal has no leaderboard page', not os.path.exists(ROOT + '/apps/advertiser/public/leaderboard.php') and 'Top Workers' not in hx)
    s, l, _ = Client(E).get('/leaderboard.php')
    check('earner leaderboard needs a session', s == 303 and l == 'login.php')
    s, l, _ = Client(E).get('/levels.php')
    check('earner levels page needs a session', s == 303 and l == 'login.php')

    # ======================================================================
    # 7. Levels page
    # ======================================================================
    levels = [dict(zip(['id', 'name', 'min_approved', 'rate', 'max', 'premium'], [int(r[0]), r[1], int(r[2]), int(r[3]), int(r[4]), int(r[5])]))
              for r in db("SELECT id, name, min_approved, min_approval_rate, max_active_reservations, premium_access FROM earner_levels WHERE is_active = 1 ORDER BY min_approved, min_approval_rate, id")]
    rate = round(60 * 100 / 70, 1)
    cur_i = -1
    for i, lv in enumerate(levels):
        if 60 >= lv['min_approved'] and rate >= lv['rate']:
            cur_i = i
    cur = levels[cur_i] if cur_i >= 0 else None
    nxt = levels[cur_i + 1] if cur_i + 1 < len(levels) else None
    s, l, h = C['lv'].get('/levels.php')
    names_pos = [h.find('<span>' + lv['name'] + '</span>') for lv in levels]
    check('levels page lists every active level in order', s == 200 and all(p > 0 for p in names_pos) and names_pos == sorted(names_pos), names_pos)
    check('stats line: approved, rejected, approval rate', '60 approved · 10 rejected · ' + ('%g' % rate) + '% approval rate' in h)
    cur_block = re.search(r'<li class="level-item is-current"[^>]*>.*?</li>', h, re.S)
    check('current level highlighted', cur is not None and cur_block and '<span>' + cur['name'] + '</span>' in cur_block.group(0) and 'Your level' in cur_block.group(0), cur)
    check('benefits shown per level (tasks held, premium)', all(f"Up to {lv['max']}" in h for lv in levels) and 'Premium tasks' in h)
    if nxt is not None:
        need = max(0, nxt['min_approved'] - 60)
        span = max(1, nxt['min_approved'] - (cur['min_approved'] if cur else 0))
        prog = int(max(0, min(100, (60 - (cur['min_approved'] if cur else 0)) * 100 // span)))
        check('progress bar to the next level', f'aria-valuenow="{prog}"' in h and f"Progress to {nxt['name']}" in h, prog)
        miss = re.search(r'data-level-missing>(.*?)</p>', h, re.S)
        check("what's missing for the next level", miss and (f'{need:,} more approved task' in miss.group(1) if need else True) and nxt['name'] in miss.group(1)
              and ((f"{nxt['rate']}%" in miss.group(1)) == (rate < nxt['rate'])), miss.group(1) if miss else h[-500:])

    # admin edits show up straight away
    s, l, ha = admin.get('/levels.php')
    tok = Client.field(ha, '_csrf')
    admin.post('/levels.php', {'_csrf': tok, 'action': 'save', 'id': '0', 'name': f'Zen{lt}', 'min_approved': '999999', 'min_rate': '99', 'max_active': '9', 'active': '1'})
    admin.post('/levels.php', {'_csrf': tok, 'action': 'save', 'id': '0', 'name': f'Hid{lt}', 'min_approved': '999998', 'min_rate': '0', 'max_active': '8', 'premium': '1'})
    zen = one(f"SELECT id FROM earner_levels WHERE name = 'Zen{lt}'")
    hid = one(f"SELECT id FROM earner_levels WHERE name = 'Hid{lt}'")
    check('admin added an active and an inactive level', zen and hid and one(f'SELECT is_active FROM earner_levels WHERE id = {hid}') == '0')
    s, l, h = C['lv'].get('/levels.php')
    zb = re.search(r'<li class="level-item[^"]*"[^>]*>(?:(?!</li>).)*Zen' + lt + r'(?:(?!</li>).)*</li>', h, re.S)
    check('new active level appears on the earner page with its benefits', zb and 'Up to 9' in zb.group(0) and 'Not included' in zb.group(0) and '999,999+ approved tasks · 99%+ approval rate' in zb.group(0))
    check('inactive level is hidden', f'Hid{lt}' not in h)
    s, l, hp = Client(M).get('/levels.php')
    check('public page reflects the new level and hides the inactive one', s == 200 and f'Zen{lt}' in hp and f'Hid{lt}' not in hp)
    admin.post('/levels.php', {'_csrf': tok, 'action': 'save', 'id': zen, 'name': f'Zenb{lt}', 'min_approved': '999999', 'min_rate': '99', 'max_active': '9', 'active': '1'})
    s, l, h = C['lv'].get('/levels.php')
    s, l, hp = Client(M).get('/levels.php')
    check('renamed level shows on both pages', f'Zenb{lt}' in h and f'Zenb{lt}' in hp)
    for i in (zen, hid):
        admin.post('/levels.php', {'_csrf': tok, 'action': 'delete', 'id': i})
    check('test levels deleted', one(f"SELECT COUNT(*) FROM earner_levels WHERE name IN ('Zenb{lt}', 'Hid{lt}')") == '0')
    setting('levels.enabled', '0')
    s, l, h = C['lv'].get('/levels.php')
    s, l, hp = Client(M).get('/levels.php')
    check('levels paused: both pages say so', 'Levels are paused' in h and 'Levels are paused' in hp and 'level-item' not in hp)
    setting('levels.enabled', '1')

    # dashboard links
    s, l, h = C['lv'].get('/dashboard.php')
    check('dashboard level badge links to levels.php', re.search(r'<a class="level-badge level-link[^"]*" href="levels.php"', h) is not None)
    check('dashboard quick link card: Leaderboard', 'data-quick="leaderboard"' in h and 'href="leaderboard.php"' in h)
    check('dashboard quick link card: Levels', 'data-quick="levels"' in h)

    # public page: no personal data
    pc = Client(M)
    s, l, hp = pc.get('/levels.php')
    check('public levels page lists levels and benefits', s == 200 and all('<span>' + lv['name'] + '</span>' in hp for lv in levels) and 'Tasks held at once' in hp)
    check('public levels page shows no user data', name['lv'] not in hp and em['lv'] not in hp and '>Your level<' not in hp and 'data-current-level' not in hp
          and 'data-level-stats' not in hp and not re.search(r'[\w.+-]+@[\w-]+\.[\w.]+', hp))
    s, l, hi = pc.get('/')
    check('home page links to the levels page', 'href="levels.php"' in hi)
    s, l, h = C['lv'].get('/levels.php')
    check('earner levels page links to leaderboard and interests', 'href="leaderboard.php"' in h and 'href="interests.php"' in h)
finally:
    cleanup()

r = subprocess.run(['php', ROOT + '/cron/daily.php'], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
check('reconciliation OK after rewards (fixtures removed)', r.returncode == 0 and 'reconciliation: OK' in r.stdout, r.stdout + r.stderr)

passed = sum(1 for _, ok_ in results if ok_)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
