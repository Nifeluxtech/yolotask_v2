#!/usr/bin/env python3
"""YOLOTASK V2 integration test: daily check-in (migration 0014).

Covers the migration (table, unique user + Lagos date, account, settings),
off by default (switch 0, every level ₦0: no card, POST refused), the admin
switch and per-level amounts (Admin → Growth → Earner levels: validation,
CSRF, authorization, audit), once per Lagos day (second POST refused, five
simultaneous POSTs from separate sessions pay once, DB unique key), the
midnight WAT boundary with a fixed clock (tests/Integration/Growth/
checkin_cli.php: 23:59:59 WAT and 00:00:00 WAT are different days; 00:30
WAT is the new Lagos day although UTC is still the previous date), streak
count and reset after a missed day (and the dashboard streak), per-level
amount, ₦0 = off, switch off blocks, unverified email blocked, ledger credit
idempotent (key checkin:<userId>:<date>; a replayed posting moves nothing,
a lost row never double-pays), wallet history label, and reconciliation
(ledger + tamper detection + database/verify/withdrawal_fee_checkin_
reconciliation.sql). Settings are restored (switch off, all levels ₦0).

Needs the Phase 3 servers (tests/Integration/PaymentCallbacks/run-servers.sh)
and migration 0014. Env: YOLOTASK_CONFIG_DIR (default /tmp/ytcfg), YT_DB_CLI.
"""
import json, os, re, shlex, subprocess, sys, threading, time
import http.cookiejar, urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CFG = os.environ.get('YOLOTASK_CONFIG_DIR', '/tmp/ytcfg')
E, A, ADM = 'http://127.0.0.1:8402', 'http://127.0.0.1:8403', 'http://127.0.0.1:8405'
UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537.36'
CLI = ROOT + '/tests/Integration/Growth/checkin_cli.php'
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

    def req(self, path, data=None):
        url = path if path.startswith('http') else self.base + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            resp = self.op.open(urllib.request.Request(url, data=body, headers={'User-Agent': UA}), timeout=30)
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
    out = subprocess.run(cli + ['-N', '-B', DBNAME, '-e', sql], capture_output=True, text=True)
    if out.returncode != 0:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().split('\n') if line != '']


DBNAME = re.search(r"'database'\s*=>\s*'([^']+)'", open(CFG + '/database.local.php').read()).group(1)


def one(sql):
    rows = db(sql)
    return rows[0][0] if rows else None


def make_user(role, email, pw, name, verified=True):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('{name}', '{email}', '{h}', '{role}', 'active', {'UTC_TIMESTAMP()' if verified else 'NULL'})")
    return int(one(f"SELECT id FROM users WHERE email = '{email}'"))


def avail(u):
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


def cli(*args):
    r = subprocess.run(['php', CLI] + [str(a) for a in args], capture_output=True, text=True, env=dict(os.environ, YOLOTASK_CONFIG_DIR=CFG))
    try:
        return json.loads(r.stdout)
    except ValueError:
        return {'raw': r.stdout + r.stderr}


def rows(u):
    return db(f"SELECT DATE_FORMAT(lagos_date, '%Y-%m-%d'), amount_kobo, streak FROM daily_checkins WHERE user_id = {u} ORDER BY lagos_date")


def lagos_today():
    return cli('lagos-date', 'now')['date']


def checkin(client, csrf=True):
    s, l, h = client.get('/dashboard.php')
    data = {'_csrf': client.csrf(h)} if csrf else {}
    s, l, _ = client.post('/checkin.php', data)
    f, h = client.flash('/dashboard.php')
    return s, l, f, h


def level_form(client, level, **over):
    data = {'action': 'save', 'id': level[0], 'name': level[1], 'min_approved': level[2], 'min_rate': level[3], 'max_active': level[4], 'active': '1'}
    data.update(over)
    s, l, h = client.get('/levels.php')
    data['_csrf'] = client.csrf(h)
    client.post('/levels.php', data)
    return client.flash('/levels.php')


def admin_switch(on, csrf=True):
    s, l, h = admin.get('/levels.php')
    data = {'action': 'checkin_toggle'}
    if on:
        data['enabled'] = '1'
    if csrf:
        data['_csrf'] = admin.csrf(h)
    admin.post('/levels.php', data)
    return admin.flash('/levels.php')


tag = str(int(time.time()))
PW = 'TestPass!2026abc'
setting('checkin.enabled', '0')
setting('levels.enabled', '1')
db("UPDATE earner_levels SET checkin_reward_kobo = 0")
starter = db("SELECT id, name, min_approved, min_approval_rate, max_active_reservations FROM earner_levels WHERE min_approved = 0 AND min_approval_rate = 0 ORDER BY id LIMIT 1")[0]

# ======================================================================
# 1. Migration and defaults
# ======================================================================
check('daily_checkins unique on (user_id, lagos_date)', one("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'daily_checkins' AND index_name = 'uq_daily_checkins_user_day' AND non_unique = 0") == 'user_id,lagos_date')
check('system:checkin_rewards exists (system, debit-normal expense, no negative)', one("SELECT COUNT(*) FROM ledger_accounts WHERE code = 'system:checkin_rewards' AND kind = 'system' AND normal_side = 'debit' AND allow_negative = 0") == '1')
mig = open(ROOT + '/database/migrations/0014_withdrawal_fee_daily_checkin.sql').read()
check('migration seeds checkin.enabled = 0 and levels default to ₦0', "('checkin.enabled', '0')" in mig and 'checkin_reward_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0' in mig)
check('clock helper: 23:59:59 WAT and 00:00 WAT are different Lagos days; 00:30 WAT is the next day while UTC is still the day before',
      cli('lagos-date', '2026-11-02T23:59:59+01:00')['date'] == '2026-11-02' and cli('lagos-date', '2026-11-02T23:00:00Z')['date'] == '2026-11-03'
      and cli('lagos-date', '2026-11-02T23:30:00Z')['date'] == '2026-11-03' and cli('lagos-date', '2026-11-02T22:59:59Z')['date'] == '2026-11-02')

# ======================================================================
# 2. Users
# ======================================================================
em = {k: f'ck{k}{tag}@gmail.com' for k in ['e1', 'e2', 'e3', 'unv', 'clk', 'adv', 'admin']}
e1 = make_user('earner', em['e1'], PW, 'Kemi Checkin')
e2 = make_user('earner', em['e2'], PW, 'Lanre Race')
e3 = make_user('earner', em['e3'], PW, 'Musa Level')
unv = make_user('earner', em['unv'], PW, 'Ngozi Unverified', verified=False)
clk = make_user('earner', em['clk'], PW, 'Obi Clock')
advid = make_user('advertiser', em['adv'], PW, 'Ade Advertiser')
admid = make_user('admin', em['admin'], PW, 'Tola Admin')
c1, c3, cu, adv, admin = Client(E), Client(E), Client(E), Client(A), Client(ADM)
check('users sign in', c1.login(em['e1'], PW) and c3.login(em['e3'], PW) and cu.login(em['unv'], PW) and adv.login(em['adv'], PW) and admin.login(em['admin'], PW))
acct0 = sysbal('system:checkin_rewards')

# ======================================================================
# 3. Off by default (switch off, every level ₦0)
# ======================================================================
s, l, h = c1.get('/dashboard.php')
check('dashboard: no check-in card while off', s == 200 and 'data-checkin=' not in h)
s, l, f, h = checkin(c1)
check('check-in refused while switched off (nothing paid)', s == 303 and 'not available' in f and rows(e1) == [] and avail(e1) == 0, f)
f, h = admin_switch(True)
check('admin switches the daily check-in on (audited)', 'switched on' in f and sval('checkin.enabled') == '1'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.checkin_enabled' AND actor_user_id = {admid}") == '1', f)
s, l, h = c1.get('/dashboard.php')
check('switch on but every level ₦0: still no card (₦0 = off)', 'data-checkin=' not in h)
s, l, f, h = checkin(c1)
check('₦0 level: check-in refused', 'not available for your level' in f and rows(e1) == [], f)

# ======================================================================
# 4. Admin per-level amount: validation, CSRF, authorization, audit
# ======================================================================
for bad, msg in (('abc', 'in naira'), ('-5', 'in naira'), ('10000.01', 'between ₦0 and ₦10,000.00')):
    f, _ = level_form(admin, starter, checkin_reward=bad)
    check(f'check-in reward "{bad}" refused', msg in f and one(f"SELECT checkin_reward_kobo FROM earner_levels WHERE id = {starter[0]}") == '0', f)
s, l, h = admin.get('/levels.php')
admin.post('/levels.php', {'action': 'save', 'id': starter[0], 'name': starter[1], 'min_approved': starter[2], 'min_rate': starter[3], 'max_active': starter[4], 'active': '1', 'checkin_reward': '20'})
check('level save without CSRF refused', one(f"SELECT checkin_reward_kobo FROM earner_levels WHERE id = {starter[0]}") == '0')
level_form(c1, starter, checkin_reward='999')
s, l, h = c1.get(ADM + '/levels.php')
check('earner cannot reach or save admin levels', s in (303, 403, 404) and one(f"SELECT checkin_reward_kobo FROM earner_levels WHERE id = {starter[0]}") == '0')
f, _ = level_form(admin, starter, checkin_reward='20')
check('admin sets Starter check-in reward ₦20 (audited)', 'Level updated' in f and one(f"SELECT checkin_reward_kobo FROM earner_levels WHERE id = {starter[0]}") == '2000'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.level_checkin_reward_changed' AND target_id = {starter[0]} AND actor_user_id = {admid}") == '1', f)
s, l, h = admin.get('/levels.php')
check('admin levels page shows the switch and the level reward', 'data-checkin-admin' in h and 'data-level-checkin="2000"' in h)
s, l, h = c1.get('/levels.php')
check('earner levels page lists the daily check-in benefit', 'Daily check-in' in h and '₦20.00 a day' in h)

# ======================================================================
# 5. Once per Lagos day (web), streak 1, ledger
# ======================================================================
today = lagos_today()
s, l, h = c1.get('/dashboard.php')
check('dashboard card: open, ₦20, streak 0, Check in button', 'data-checkin="open"' in h and 'data-checkin-button' in h and '₦20.00' in h and 'data-streak="0"' in h)
s, l, f, h = checkin(c1, csrf=False)
check('check-in without CSRF refused', rows(e1) == [] and avail(e1) == 0, f)
s, l, h0 = c1.get('/checkin.php')
check('GET checkin.php just goes back to the dashboard', s == 303 and l == 'dashboard.php' and rows(e1) == [])
s, l, f, h = checkin(c1)
check('check-in pays ₦20 for today (Lagos date), streak 1', s == 303 and l == 'dashboard.php' and 'Checked in: ₦20.00' in f and rows(e1) == [[today, '2000', '1']] and avail(e1) == 2000, (f, rows(e1)))
tx = db(f"SELECT t.idempotency_key, t.type, t.amount_kobo FROM daily_checkins c JOIN ledger_transactions t ON t.id = c.ledger_transaction_id WHERE c.user_id = {e1}")
check('ledger: key checkin:<user>:<Lagos date>, type checkin_reward, Dr system:checkin_rewards', tx == [[f'checkin:{e1}:{today}', 'checkin_reward', '2000']] and sysbal('system:checkin_rewards') == acct0 + 2000, tx)
check('audit: checkin.claimed', one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'checkin.claimed' AND actor_user_id = {e1}") == '1')
s, l, f, h = checkin(c1)
check('second check-in the same Lagos day refused, nothing more paid', 'already checked in today' in f and len(rows(e1)) == 1 and avail(e1) == 2000, f)
check('dashboard after check-in: done, streak 1', 'data-checkin="done"' in h and 'data-streak="1"' in h and 'Checked in today' in h)
s, l, h = c1.get('/wallet.php')
check('wallet history shows "Daily check-in reward"', 'Daily check-in reward' in h)
try:
    db(f"INSERT INTO daily_checkins (user_id, lagos_date, amount_kobo, streak, ledger_transaction_id) SELECT user_id, lagos_date, amount_kobo, streak, ledger_transaction_id FROM daily_checkins WHERE user_id = {e1}")
    dup = 'inserted'
except RuntimeError as e:
    dup = str(e)
check('database refuses a second row for the same user and Lagos day', 'Duplicate' in dup, dup)

# simultaneous check-ins from five separate sessions pay once
e2c = [Client(E) for _ in range(5)]
check('five sessions for one earner', all(c.login(em['e2'], PW) for c in e2c))
toks = []
for c in e2c:
    s, l, h = c.get('/dashboard.php')
    toks.append(c.csrf(h))
before = avail(e2)
threads = [threading.Thread(target=lambda c=c, t=t: c.post('/checkin.php', {'_csrf': t})) for c, t in zip(e2c, toks)]
[t.start() for t in threads]
[t.join() for t in threads]
check('five simultaneous check-ins: one row, one ₦20 credit', len(rows(e2)) == 1 and avail(e2) == before + 2000
      and one(f"SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key LIKE 'checkin:{e2}:%'") == '1', (rows(e2), avail(e2)))

# ======================================================================
# 6. Unverified email blocked
# ======================================================================
s, l, h = cu.get('/dashboard.php')
check('unverified earner sees "Confirm email" on the card, no button', 'data-checkin="unverified"' in h and 'data-checkin-button' not in h)
s, l, f, h = checkin(cu)
check('unverified earner cannot check in', 'Confirm your email' in f and rows(unv) == [] and avail(unv) == 0, f)

# ======================================================================
# 7. Per-level amount; levels off = no reward
# ======================================================================
db("INSERT INTO earner_levels (name, min_approved, min_approval_rate, max_active_reservations, is_active, checkin_reward_kobo) VALUES ('CheckinTest', 0, 0, 3, 1, 3500)")
cklevel = one("SELECT id FROM earner_levels WHERE name = 'CheckinTest'")
s, l, h = c3.get('/dashboard.php')
check('a different level shows its own amount (₦35, CheckinTest level)', '₦35.00' in h and 'CheckinTest level' in h)
s, l, f, h = checkin(c3)
check('check-in pays the level amount ₦35 and records the level', rows(e3) == [[today, '3500', '1']] and one(f"SELECT level_id FROM daily_checkins WHERE user_id = {e3}") == cklevel, rows(e3))
db(f"UPDATE earner_levels SET checkin_reward_kobo = 0 WHERE id = {cklevel}")
r = cli('checkin', clk, '2026-12-01T10:00:00+01:00')
check('level set to ₦0 → check-in off for that level', r.get('ok') is False and 'not available for your level' in r.get('error', ''), r)
db(f"DELETE FROM earner_levels WHERE id = {cklevel}")
setting('levels.enabled', '0')
r = cli('checkin', clk, '2026-12-01T10:00:00+01:00')
check('levels switched off → no level → no check-in', r.get('ok') is False and 'not available' in r.get('error', ''), r)
setting('levels.enabled', '1')

# ======================================================================
# 8. Midnight WAT boundary, streak count and reset (fixed clock)
# ======================================================================
r1 = cli('checkin', clk, '2026-12-02T23:59:59+01:00')
check('23:59:59 WAT 2 Dec → day 2026-12-02, streak 1', r1.get('ok') and r1['date'] == '2026-12-02' and r1['streak'] == 1 and r1['amount'] == 2000, r1)
r2 = cli('checkin', clk, '2026-12-03T00:00:00+01:00')
check('one second later, 00:00:00 WAT 3 Dec → new Lagos day allowed, streak 2', r2.get('ok') and r2['date'] == '2026-12-03' and r2['streak'] == 2, r2)
r3 = cli('checkin', clk, '2026-12-03T23:30:00Z')
check('00:30 WAT 4 Dec (still 3 Dec in UTC) → Lagos day 2026-12-04, streak 3', r3.get('ok') and r3['date'] == '2026-12-04' and r3['streak'] == 3, r3)
r4 = cli('checkin', clk, '2026-12-04T22:59:59Z')
check('23:59:59 WAT 4 Dec → same Lagos day, refused', r4.get('ok') is False and 'already checked in' in r4.get('error', ''), r4)
st = cli('status', clk, '2026-12-05T12:00:00+01:00')
check('next day before checking in: streak still 3, button available', st.get('streak') == 3 and st.get('available') is True and st.get('checked_today') is False, st)
r5 = cli('checkin', clk, '2026-12-06T08:00:00+01:00')
check('a missed day (5 Dec) resets the streak to 1', r5.get('ok') and r5['date'] == '2026-12-06' and r5['streak'] == 1, r5)
st = cli('status', clk, '2026-12-08T09:00:00+01:00')
check('two days later with no check-in: streak shown as 0', st.get('streak') == 0 and st.get('available') is True, st)
check('rows: 02, 03, 04 (1, 2, 3) then 06 (1)', rows(clk) == [['2026-12-02', '2000', '1'], ['2026-12-03', '2000', '2'], ['2026-12-04', '2000', '3'], ['2026-12-06', '2000', '1']], rows(clk))

# ======================================================================
# 9. Ledger idempotency
# ======================================================================
bal = avail(clk)
entries = one("SELECT COUNT(*) FROM ledger_entries")
rp = cli('repost', clk, '2026-12-03', 2000)
check('replaying the ledger posting with the same key moves nothing', rp.get('created') is False and avail(clk) == bal and one("SELECT COUNT(*) FROM ledger_entries") == entries, rp)
saved = db(f"SELECT id, user_id, lagos_date, level_id, amount_kobo, streak, ledger_transaction_id, created_at FROM daily_checkins WHERE user_id = {clk} AND lagos_date = '2026-12-06'")[0]
db(f"DELETE FROM daily_checkins WHERE id = {saved[0]}")
r6 = cli('checkin', clk, '2026-12-06T20:00:00+01:00')
check('a check-in whose row went missing never pays twice (ledger key wins)', r6.get('ok') is False and avail(clk) == bal, r6)
db(f"INSERT INTO daily_checkins (id, user_id, lagos_date, level_id, amount_kobo, streak, ledger_transaction_id, created_at) VALUES ({saved[0]}, {saved[1]}, '{saved[2]}', {saved[3] if saved[3] != 'NULL' else 'NULL'}, {saved[4]}, {saved[5]}, {saved[6]}, '{saved[7]}')")

# ======================================================================
# 10. Switch off blocks; advertiser cannot check in; admin toggle CSRF
# ======================================================================
admin_switch(False, csrf=False)
check('admin switch without CSRF refused', sval('checkin.enabled') == '1')
s, l, h = adv.get(E + '/dashboard.php')
check('advertiser session does not reach the earner portal', s in (303, 403, 404) or 'data-checkin' not in h)
s, l, h = adv.get('/dashboard.php')
check('advertiser dashboard has no check-in card', 'data-checkin=' not in h)
f, h = admin_switch(False)
check('admin switches the daily check-in off (audited)', 'switched off' in f and sval('checkin.enabled') == '0'
      and one(f"SELECT COUNT(*) FROM audit_log WHERE event_key = 'admin.checkin_disabled' AND actor_user_id = {admid}") == '1', f)
r = cli('checkin', clk, '2026-12-07T10:00:00+01:00')
check('switch off blocks check-in', r.get('ok') is False and 'not available right now' in r.get('error', ''), r)
s, l, h = c1.get('/dashboard.php')
check('switch off: an earner who already checked in today still sees "done"; others see no card', 'data-checkin="done"' in h)
s, l, h = c3.get('/dashboard.php')
s2, l2, h2 = cu.get('/dashboard.php')
check('switch off: earner who has not checked in sees no card', 'data-checkin=' not in h2)

# ======================================================================
# 11. Reconciliation
# ======================================================================
ok, out = reconcile_ok()
check('reconciliation OK (check-in account = rewards paid, rows match postings)', ok, out[-400:])
vf = subprocess.run(shlex.split(os.environ.get('YT_DB_CLI', 'mariadb -uyt -pytpass -h127.0.0.1')) + ['-N', DBNAME],
                    stdin=open(ROOT + '/database/verify/withdrawal_fee_checkin_reconciliation.sql'), capture_output=True, text=True)
check('database/verify/withdrawal_fee_checkin_reconciliation.sql returns no problems', vf.returncode == 0 and vf.stdout.strip() == '', vf.stdout[:300] + vf.stderr[:200])
db(f"UPDATE daily_checkins SET amount_kobo = amount_kobo + 1 WHERE user_id = {e1}")
ok, out = reconcile_ok()
check('tampered check-in detected by reconciliation (cron exit 2)', not ok and 'check-in' in out.lower(), out[-300:])
db(f"UPDATE daily_checkins SET amount_kobo = amount_kobo - 1 WHERE user_id = {e1}")
ok, out = reconcile_ok()
check('reconciliation OK again after restoring', ok, out[-300:])

# ---------- restore
setting('checkin.enabled', '0')
setting('levels.enabled', '1')
db("UPDATE earner_levels SET checkin_reward_kobo = 0")

passed = sum(1 for _, ok in results if ok)
print(f"\n{passed}/{len(results)} passed")
sys.exit(0 if passed == len(results) else 1)
