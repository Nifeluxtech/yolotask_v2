#!/usr/bin/env python3
"""Task link beside every proof on the submission/appeal review pages.

Checks that campaigns.link_url appears as a new-tab link (target=_blank,
rel noopener noreferrer) next to each proof on: advertiser campaign page,
admin submissions list, admin campaign detail, admin appeals list + detail,
reviewer stale queue, reviewer appeals queue + detail, and the earner's own
submission detail. Links that are not http(s) (javascript:, data:, ftp:) are
never rendered as links, and HTML in a link is escaped. Fixture rows are
removed at the end.

Needs the integration environment and the servers from
tests/Integration/PaymentCallbacks/run-servers.sh. Env: YOLOTASK_CONFIG_DIR
(default /tmp/ytcfg), YT_DB_CLI.
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
GOOD = f'https://example.com/post/{tag}?a=1&b=2'          # & must come out as &amp;
BAD = 'javascript:alert(document.cookie)//<x>'
created_users = []


def make_user(role, key):
    h = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PW], capture_output=True, text=True).stdout
    email = f'tl{key}{tag}@example.test'
    db(f"INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at) VALUES ('TL {key}', '{email}', '{h}', '{role}', 'active', UTC_TIMESTAMP())")
    uid = int(one(f"SELECT id FROM users WHERE email = '{email}'"))
    created_users.append(uid)
    return uid, email


def esc(s):
    return s.replace("'", "''").replace('\\', '\\\\')


adv, adv_e = make_user('advertiser', 'adv')
admin, admin_e = make_user('admin', 'admin')
rev, rev_e = make_user('reviewer', 'rev')
earners = [make_user('earner', f'e{i}') for i in range(4)]
old = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=72)).strftime('%Y-%m-%d %H:%M:%S')
camps = {}
for key, link in (('good', GOOD), ('bad', BAD)):
    db(f"""INSERT INTO campaigns (advertiser_user_id, platform_id, task_type_id, title, link_url, link_normalized, link_hash, instructions, proof_requirements,
        unit_price_kobo, unit_reward_kobo, quantity, budget_kobo, status, approved_at)
        VALUES ({adv}, (SELECT MIN(id) FROM platforms), (SELECT MIN(id) FROM task_types), 'TL {key} {tag}', '{esc(link)}', 'n/{key}/{tag}', SHA2('tl{key}{tag}', 256),
        'Do it', 'Screenshot', 300, 250, 10, 3000, 'live', UTC_TIMESTAMP())""")
    camps[key] = int(one(f"SELECT id FROM campaigns WHERE title = 'TL {key} {tag}'"))


def submission(camp, earner, status, at):
    decided = 'NULL' if status == 'pending' else f"'{at}'"
    db(f"""INSERT INTO submissions (campaign_id, earner_user_id, status, instructions_version, unit_price_kobo, unit_reward_kobo, submitted_at, auto_approve_at, decided_at, decision_source, reject_reason)
        VALUES ({camp}, {earner}, '{status}', 1, 300, 250, '{at}', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY), {decided}, {"NULL" if status == 'pending' else "'advertiser'"}, {"'Wrong'" if status == 'rejected' else 'NULL'})""")
    sid = int(one(f"SELECT id FROM submissions WHERE campaign_id = {camp} AND earner_user_id = {earner}"))
    db(f"""INSERT INTO submission_proofs (submission_id, attempt, is_appeal, storage_path, mime, bytes, width, height, upload_sha256, stored_sha256)
        VALUES ({sid}, 1, 0, 'tl/{tag}/{sid}.png', 'image/png', 10, 1, 1, SHA2('u{sid}{tag}', 256), SHA2('s{sid}{tag}', 256))""")
    return sid


s_good_pending = submission(camps['good'], earners[0][0], 'pending', old)   # stale (72 h)
s_bad_pending = submission(camps['bad'], earners[1][0], 'pending', old)
s_good_rej = submission(camps['good'], earners[2][0], 'rejected', old)
db(f"""INSERT INTO submission_appeals (submission_id, campaign_id, earner_user_id, advertiser_user_id, status, message, instructions_version, rejection_reason, rejection_source, rejected_at)
    VALUES ({s_good_rej}, {camps['good']}, {earners[2][0]}, {adv}, 'open', 'Please check again', 1, 'Wrong', 'advertiser', '{old}')""")
appeal = int(one(f"SELECT id FROM submission_appeals WHERE submission_id = {s_good_rej}"))

GOOD_HREF = 'href="' + htmllib.escape(GOOD, quote=True) + '"'


def has_good(h):
    # new-tab task link with the escaped href and safe rel
    return bool(re.search(r'<a class="task-link[^"]*" ' + re.escape(GOOD_HREF) + r' target="_blank" rel="noopener noreferrer nofollow"', h))


try:
    ca, cad, cr, ce = Client(A), Client(ADM), Client(R), Client(E)
    check('advertiser signs in', ca.login(adv_e, PW))
    check('admin signs in', cad.login(admin_e, PW))
    check('reviewer signs in', cr.login(rev_e, PW))
    check('earner signs in', ce.login(earners[2][1], PW))

    pages = [
        ('advertiser campaign page', ca, f'/campaign.php?id={camps["good"]}'),
        ('admin submissions list', cad, '/submissions.php?status=pending'),
        ('admin campaign detail', cad, f'/campaign.php?id={camps["good"]}'),
        ('admin appeals list', cad, '/appeals.php'),
        ('admin appeal detail', cad, f'/appeals.php?id={appeal}'),
        ('reviewer stale queue', cr, '/stale.php'),
        ('reviewer appeals queue', cr, '/appeals.php'),
        ('reviewer appeal detail', cr, f'/appeals.php?id={appeal}'),
        ('earner submission detail', ce, f'/submission.php?id={s_good_rej}'),
    ]
    for label, c, path in pages:
        s, l, h = c.req(path)
        check(f'{label}: 200', s == 200, (s, l))
        check(f'{label}: task link beside the proof, new tab, escaped', has_good(h) and 'proof-thumb' in h, re.findall(r'.{80}task-link.{200}', h)[:2])
        check(f'{label}: no unescaped "<x>" from the link', '<x>' not in h)

    # non-http(s) links are never clickable
    for label, c, path in [('advertiser bad campaign', ca, f'/campaign.php?id={camps["bad"]}'), ('admin submissions list', cad, '/submissions.php?status=pending'),
                           ('reviewer stale queue', cr, '/stale.php'), ('admin bad campaign', cad, f'/campaign.php?id={camps["bad"]}')]:
        s, l, h = c.req(path)
        check(f'{label}: javascript: link not rendered as a link', 'href="javascript:' not in h.lower() and "href='javascript:" not in h.lower())
    s, l, h = ca.req(f'/campaign.php?id={camps["bad"]}')
    check('bad campaign shows "No task link" beside the proof', 'No task link' in h)

    # helper unit checks
    php = r'''
define("YOLOTASK_APP_ROOT", getcwd()); define("YOLOTASK_NO_SESSION", true);
require "shared/autoload.php"; function h($s){return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, "UTF-8");}
require "shared/routes/tasks-helpers.php";
$out = [];
foreach (["https://x.com/a", "http://x.com/a", "javascript:alert(1)", "JAVASCRIPT:alert(1)", "data:text/html,hi", "ftp://x.com", "//x.com/a", "https://", " https://x.com/a b", "https://x.com/\"onmouseover=\"x"] as $u) $out[] = yolotask_safe_task_url($u) !== null ? 1 : 0;
echo json_encode($out);
'''
    root = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
    out = subprocess.run(['php', '-r', php], capture_output=True, text=True, cwd=root)
    check('only http(s) URLs with a host are allowed', out.stdout.strip() == '[1,1,0,0,0,0,0,0,0,0]', out.stdout + out.stderr)
finally:
    ids = ','.join(str(i) for i in (s_good_pending, s_bad_pending, s_good_rej))
    db(f"DELETE FROM submission_appeals WHERE submission_id IN ({ids})")
    db(f"DELETE FROM submission_proofs WHERE submission_id IN ({ids})")
    db(f"DELETE FROM submissions WHERE id IN ({ids})")
    db(f"DELETE FROM campaign_instructions WHERE campaign_id IN ({camps['good']},{camps['bad']})")
    db(f"DELETE FROM campaigns WHERE id IN ({camps['good']},{camps['bad']})")
    # fixture users (tl*@example.test) are left in place, like the other integration suites

passed = sum(1 for _, ok in results if ok)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
