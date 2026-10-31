#!/usr/bin/env python3
"""Security\\Crypto seal/reveal without ext-sodium (cPanel ea-php84 ships
without it). Needs only the php CLI with openssl; no database or servers.

ext-sodium is often compiled in statically, so "no sodium" is simulated with
-d disable_functions=sodium_* (PHP 8 removes disabled functions, so
function_exists() is false exactly as on a host without the extension). When
the CLI really has no sodium, the enc1-with-sodium checks are skipped.

Usage: python3 tests/Unit/Crypto/crypto_nosodium_test.py
"""
import json, os, re, subprocess, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..'))
CRYPTO = os.path.join(ROOT, 'shared/lib/Security/Crypto.php')
KEY = 'unit-test-security-key-0123456789abcdef'
NO_SODIUM = ['-d', 'disable_functions=' + ','.join([
    'sodium_crypto_secretbox', 'sodium_crypto_secretbox_open', 'sodium_crypto_secretbox_keygen',
    'sodium_crypto_box', 'sodium_crypto_box_open', 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
    'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt', 'sodium_memzero', 'sodium_bin2hex'])]
results = []


def check(label, ok, extra=''):
    results.append((label, bool(ok)))
    print(('PASS' if ok else 'FAIL') + ': ' + label + (('  [' + str(extra)[:400] + ']') if (not ok and extra) else ''))


def php(code, args=(), key=KEY, ini=()):
    """Run PHP code with Crypto loaded; code must echo JSON."""
    prelude = "error_reporting(E_ALL); require getenv('CRYPTO'); use YoloTask\\Security\\Crypto;\n" \
              "function attempt(callable $f) { try { return ['ok' => $f()]; } catch (Throwable $e) { return ['err' => get_class($e), 'msg' => $e->getMessage()]; } }\n"
    env = dict(os.environ, CRYPTO=CRYPTO, YOLOTASK_SECURITY_KEY=key)
    out = subprocess.run(['php', *ini, '-r', prelude + code, '--', *args], capture_output=True, text=True, env=env)
    try:
        return json.loads(out.stdout)
    except ValueError:
        return {'_raw': out.stdout, '_stderr': out.stderr, '_rc': out.returncode}


env_ns = php("echo json_encode(['sodium' => Crypto::sodiumAvailable(), 'fx' => function_exists('sodium_crypto_secretbox'), 'gcm' => Crypto::gcmAvailable()]);", ini=NO_SODIUM)
check('sodium disabled in the no-sodium runtime', env_ns.get('sodium') is False and env_ns.get('fx') is False, env_ns)
check('openssl aes-256-gcm available in the no-sodium runtime', env_ns.get('gcm') is True, env_ns)
env_s = php("echo json_encode(['sodium' => Crypto::sodiumAvailable()]);")
have_sodium = env_s.get('sodium') is True
print('info: this php CLI ' + ('has' if have_sodium else 'does NOT have') + ' ext-sodium natively')

# --- enc2 round trips without sodium -------------------------------------
samples = ['0123456789', '', '9', 'Ọlá Bánkọ́ — ₦ 1,000.00 \u00e9\u4e2d', 'x' * 5000, "nul\x00byte"]
r = php("""$out = [];
foreach (json_decode($argv[1], true) as $p) { $s = Crypto::seal($p); $out[] = ['sealed' => $s, 'back' => Crypto::reveal($s), 'isSealed' => Crypto::isSealed($s)]; }
echo json_encode($out);""", [json.dumps(samples)], ini=NO_SODIUM)
ok = isinstance(r, list) and len(r) == len(samples)
check('seal() works without sodium (no fatal)', ok, r)
if ok:
    check('seal() writes the enc2: prefix', all(x['sealed'].startswith('enc2:') for x in r))
    check('enc2 round-trips every sample (empty, unicode, long, NUL)', [x['back'] for x in r] == samples)
    check('isSealed() true for enc2 values', all(x['isSealed'] for x in r))
    check('ciphertext never contains the plaintext', '0123456789' not in r[0]['sealed'])
    import base64
    raw = base64.b64decode(r[0]['sealed'][5:])
    check('enc2 layout is iv(12) || tag(16) || ciphertext (GCM: ct len == pt len)', len(raw) == 12 + 16 + len('0123456789'), len(raw))

r = php("$a = Crypto::seal('0123456789'); $b = Crypto::seal('0123456789'); echo json_encode([$a !== $b, substr(base64_decode(substr($a,5)),0,12) !== substr(base64_decode(substr($b,5)),0,12)]);", ini=NO_SODIUM)
check('random IV: sealing the same value twice gives different output', r == [True, True], r)

# --- tamper detection ----------------------------------------------------
tamper = php(r"""
$s = Crypto::seal('0123456789'); $raw = base64_decode(substr($s, 5));
$flip = function (string $raw, int $i) { $raw[$i] = chr(ord($raw[$i]) ^ 0x01); return 'enc2:' . base64_encode($raw); };
echo json_encode([
  'iv' => attempt(fn () => Crypto::reveal($flip($raw, 0))),
  'tag' => attempt(fn () => Crypto::reveal($flip($raw, 12))),
  'ct_first' => attempt(fn () => Crypto::reveal($flip($raw, 28))),
  'ct_last' => attempt(fn () => Crypto::reveal($flip($raw, strlen($raw) - 1))),
  'truncated_tag' => attempt(fn () => Crypto::reveal('enc2:' . base64_encode(substr($raw, 0, 20)))),
  'dropped_byte' => attempt(fn () => Crypto::reveal('enc2:' . base64_encode(substr($raw, 0, -1)))),
  'extra_byte' => attempt(fn () => Crypto::reveal('enc2:' . base64_encode($raw . 'A'))),
  'bad_base64' => attempt(fn () => Crypto::reveal('enc2:!!!not base64!!!')),
  'empty_body' => attempt(fn () => Crypto::reveal('enc2:')),
  'prefix_swapped_to_enc1' => attempt(fn () => Crypto::reveal('enc1:' . substr($s, 5))),
  'sealed' => $s,
]);""", ini=NO_SODIUM)
for k in ('iv', 'tag', 'ct_first', 'ct_last', 'truncated_tag', 'dropped_byte', 'extra_byte', 'bad_base64', 'empty_body'):
    v = tamper.get(k, {})
    check(f'tamper/corrupt enc2 ({k}) is rejected with RuntimeException', v.get('err') == 'RuntimeException', v)
v = tamper.get('prefix_swapped_to_enc1', {})
check('enc2 body relabelled enc1: rejected (no sodium -> clear RuntimeException)', v.get('err') == 'RuntimeException' and 'sodium' in v.get('msg', ''), v)

r = php("echo json_encode(attempt(fn () => Crypto::reveal($argv[1])));", [tamper.get('sealed', '')], key=KEY[::-1], ini=NO_SODIUM)
check('enc2 sealed under another security key is rejected', r.get('err') == 'RuntimeException' and 'security key' in r.get('msg', ''), r)

# --- legacy plain text, null, isSealed -----------------------------------
r = php("""echo json_encode([
  Crypto::reveal('0123456789'), Crypto::reveal(null), Crypto::reveal(''), Crypto::reveal('ENC2:abc'), Crypto::reveal('enc3:abc'),
  Crypto::isSealed('enc1:abc'), Crypto::isSealed('enc2:abc'), Crypto::isSealed('0123456789'), Crypto::isSealed(null), Crypto::isSealed('ENC2:x'),
  Crypto::SEAL_PREFIXES]);""", ini=NO_SODIUM)
check('legacy plain text is returned unchanged', isinstance(r, list) and r[:5] == ['0123456789', '', '', 'ENC2:abc', 'enc3:abc'], r)
check('isSealed() recognises enc1: and enc2: only', isinstance(r, list) and r[5:10] == [True, True, False, False, False], r)
check('SEAL_PREFIXES lists both prefixes', isinstance(r, list) and r[10] == ['enc1:', 'enc2:'], r)

# --- key separation ------------------------------------------------------
r = php("""$m = fn ($n) => (new ReflectionMethod(Crypto::class, $n))->invoke(null);
$g = $m('gcmKey'); $s = $m('sealKey');
echo json_encode([strlen($g), $g !== $s, $g !== substr(Crypto::key(), 0, 32), bin2hex($g) !== Crypto::hmac(''), $g === hash_hkdf('sha256', Crypto::key(), 32, 'yolotask-sensitive-fields-v2-aes-256-gcm'), $s === hash_hkdf('sha256', Crypto::key(), 32, 'yolotask-sensitive-fields-v1')]);""", ini=NO_SODIUM)
check('enc2 key is 32 bytes, distinct from the enc1 key, the raw key and the HMAC key', r == [32, True, True, True, True, True], r)

# --- enc1 (libsodium) interop -------------------------------------------
if have_sodium:
    made = php(r"""$k = hash_hkdf('sha256', Crypto::key(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'yolotask-sensitive-fields-v1');
$n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$enc1 = 'enc1:' . base64_encode($n . sodium_crypto_secretbox('0123456789', $n, $k));
$enc2 = Crypto::seal('2222222222');
$raw = base64_decode(substr($enc1, 5)); $raw[30] = chr(ord($raw[30]) ^ 1);
echo json_encode(['enc1' => $enc1, 'enc2' => $enc2, 'back' => Crypto::reveal($enc1), 'sealedNow' => substr($enc2, 0, 5),
  'tampered' => attempt(fn () => Crypto::reveal('enc1:' . base64_encode($raw))), 'corrupt' => attempt(fn () => Crypto::reveal('enc1:AAAA'))]);""")
    check('enc1 (pre-existing libsodium) value is read when sodium is loaded', made.get('back') == '0123456789', made)
    check('seal() writes enc2 even when sodium is loaded (portable format)', made.get('sealedNow') == 'enc2:', made)
    check('tampered enc1 is rejected', made.get('tampered', {}).get('err') == 'RuntimeException', made.get('tampered'))
    check('corrupt enc1 is rejected', made.get('corrupt', {}).get('err') == 'RuntimeException', made.get('corrupt'))
    r = php("echo json_encode(attempt(fn () => Crypto::reveal($argv[1])));", [made.get('enc1', '')], key=KEY[::-1])
    check('enc1 under another security key is rejected', r.get('err') == 'RuntimeException', r)
    r = php("echo json_encode(['enc1' => attempt(fn () => Crypto::reveal($argv[1])), 'enc2' => attempt(fn () => Crypto::reveal($argv[2]))]);",
            [made.get('enc1', ''), made.get('enc2', '')], ini=NO_SODIUM)
    check('enc1 without sodium -> clear RuntimeException naming the sodium extension (no fatal)',
          r.get('enc1', {}).get('err') == 'RuntimeException' and 'sodium extension is not loaded' in r.get('enc1', {}).get('msg', ''), r)
    check('enc2 written on a sodium host is readable on a no-sodium host', r.get('enc2', {}).get('ok') == '2222222222', r)
    r = php("echo json_encode([Crypto::revealOr($argv[1], '••••6789'), Crypto::revealOr($argv[2], 'x'), Crypto::revealOr('plain', 'x')]);",
            [made.get('enc1', ''), made.get('enc2', '')], ini=NO_SODIUM)
    check('revealOr() (display paths) falls back for unreadable enc1 and still decrypts enc2/plain', r == ['••••6789', '2222222222', 'plain'], r)
    r = php("echo json_encode(attempt(fn () => Crypto::reveal($argv[1])));", [tamper.get('sealed', '')])
    check('enc2 written on a no-sodium host is readable on a sodium host', r.get('ok') == '0123456789', r)
else:
    print('SKIP: enc1 read with sodium present (this php has no ext-sodium)')

# --- no other unguarded sodium use in the codebase -----------------------
hits = []
for base in ('apps', 'shared', 'scripts', 'cron', 'hooks', 'config'):
    for dp, _, fs in os.walk(os.path.join(ROOT, base)):
        for f in fs:
            if f.endswith('.php'):
                p = os.path.join(dp, f)
                for i, line in enumerate(open(p, encoding='utf-8', errors='replace'), 1):
                    code = re.sub(r"//.*$|'[^']*'", '', line)  # drop comments and string literals
                    if re.search(r'(?<![\w:$>])sodium_\w+\s*\(|(?<![\w:$>])SODIUM_\w+', code):
                        hits.append(os.path.relpath(p, ROOT) + ':' + str(i) + ': ' + line.strip())
allowed = [h for h in hits if h.startswith('shared/lib/Security/Crypto.php') and 'sodium_crypto_secretbox_open(' in h]
check('no ext-sodium call or constant outside the guarded Crypto::revealSodium()', len(hits) == len(allowed) == 1, hits)

passed = sum(1 for _, ok in results if ok)
print(f'\n{passed}/{len(results)} passed')
sys.exit(0 if passed == len(results) else 1)
