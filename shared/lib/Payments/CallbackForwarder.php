<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;

/**
 * AfrStar callback hub.
 *
 * AfrStar allows one collection and one payout callback URL per merchant.
 * When another app shares the merchant (SPLUG today, V1 later), its callbacks
 * arrive at YOLOTASK's hooks. Before YOLOTASK looks at its own orders, the
 * hook asks this class whether the callback's order_sn belongs to a
 * configured route; if so the exact raw body is POSTed to that app and its
 * answer is relayed back to AfrStar. Nothing here touches the ledger,
 * payment_orders, withdrawals or payment_callbacks; the signature is checked
 * by the receiving app with its own keys.
 *
 * Config: config-private/afrstar-forward.php (see
 * config/afrstar-forward.example.php). No file = forwarding off.
 */
final class CallbackForwarder
{
    public const MAX_BODY = 65536;
    /** YOLOTASK V2 references (top-up YTP…, withdrawal YTW…) are never forwarded. */
    private const OWN_REF = '/^YT[PW]/';
    private const MAX_EXCERPT = 120;

    /** @var array|null cached validated config */
    private static ?array $cache = null;

    /** For tests: forget the cached config. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * Validated routes. Invalid routes are dropped (and logged), so a typo
     * can never send a callback somewhere unintended.
     * @return array{routes:list<array{name:string,pattern:string,url:string,timeout:int,host:string,port:int,allow_private:bool}>,ca_file:?string}
     */
    public static function config(?string $configDir = null): array
    {
        if ($configDir === null && self::$cache !== null) return self::$cache;
        $dir = $configDir ?? (string) (function_exists('yolotask_config') ? (yolotask_config()['_config_dir'] ?? '') : '');
        $out = ['routes' => [], 'ca_file' => null, 'clients' => []];
        $file = rtrim($dir, '/') . '/afrstar-forward.php';
        if ($dir === '' || !is_file($file)) {
            if ($configDir === null) self::$cache = $out;
            return $out;
        }
        try {
            $loaded = (static fn (string $f) => require $f)($file);
        } catch (\Throwable $e) {
            Logger::error('payments.forward_config_invalid', ['exception' => get_class($e)]);
            $loaded = [];
        }
        $loaded = is_array($loaded) ? $loaded : [];
        // Local test escape hatch: only honoured outside production/staging and
        // only when the operator writes it into the private config file.
        $env = (string) (function_exists('yolotask_config') ? (yolotask_config()['environment'] ?? 'production') : 'production');
        $testing = is_array($loaded['testing'] ?? null) && in_array($env, ['development', 'test', 'testing'], true) ? $loaded['testing'] : [];
        $allowPrivate = !empty($testing['allow_private_hosts']);
        if (isset($testing['ca_file']) && is_string($testing['ca_file']) && is_file($testing['ca_file'])) $out['ca_file'] = $testing['ca_file'];

        $seen = [];
        foreach ((array) ($loaded['routes'] ?? []) as $i => $route) {
            $reason = null;
            $r = self::validateRoute(is_array($route) ? $route : [], $allowPrivate, $reason);
            if ($r === null || isset($seen[$r['name']])) {
                Logger::error('payments.forward_route_rejected', ['index' => $i, 'reason' => $r === null ? $reason : 'duplicate_name']);
                continue;
            }
            $seen[$r['name']] = true;
            $out['routes'][] = $r;
        }
        // Registered clients: other sites that register each order (order_sn +
        // callback URL) through hooks/afrstar/register.php, signed with their
        // own secret. Invalid clients are dropped and logged.
        $out['clients'] = [];
        foreach ((array) ($loaded['clients'] ?? []) as $i => $client) {
            $c = self::validateClient(is_array($client) ? $client : []);
            if ($c === null || isset($out['clients'][$c['name']]) || isset($seen[$c['name']])) {
                Logger::error('payments.forward_client_rejected', ['index' => $i]);
                continue;
            }
            $c['allow_private'] = $allowPrivate;
            $out['clients'][$c['name']] = $c;
        }
        if ($configDir === null) self::$cache = $out;
        return $out;
    }

    /** @return array{name:string,secret:string,allowed_hosts:list<string>,timeout:int}|null */
    public static function validateClient(array $client): ?array
    {
        $name = (string) ($client['name'] ?? '');
        $secret = (string) ($client['secret'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $name)) return null;
        if (strlen($secret) < 32) return null;
        $hosts = [];
        foreach ((array) ($client['allowed_hosts'] ?? []) as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '') $hosts[] = $h;
        }
        return ['name' => $name, 'secret' => $secret, 'allowed_hosts' => $hosts, 'timeout' => max(1, min(30, (int) ($client['timeout'] ?? 10)))];
    }

    /** @return array|null the normalised route, or null with $reason set */
    public static function validateRoute(array $route, bool $allowPrivate = false, ?string &$reason = null): ?array
    {
        $name = (string) ($route['name'] ?? '');
        $pattern = (string) ($route['pattern'] ?? '');
        $url = trim((string) ($route['url'] ?? ''));
        $timeout = (int) ($route['timeout'] ?? 10);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $name)) { $reason = 'bad_name'; return null; }
        if ($pattern === '' || @preg_match($pattern, '') === false) { $reason = 'bad_pattern'; return null; }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) { $reason = 'not_https'; return null; }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { $reason = 'bad_url'; return null; }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            if (!$allowPrivate) { $reason = 'private_host'; return null; }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && !$allowPrivate && !self::publicIp($host)) { $reason = 'private_host'; return null; }
        return ['name' => $name, 'pattern' => $pattern, 'url' => $url, 'timeout' => max(1, min(30, $timeout)), 'host' => $host, 'port' => $port, 'allow_private' => $allowPrivate];
    }

    /**
     * Resolve the route's host at send time and refuse private/loopback
     * addresses. The result is pinned with CURLOPT_RESOLVE so DNS cannot
     * switch to an internal address between this check and the request.
     * @return list<string>|null
     */
    public static function resolve(array $route): ?array
    {
        $host = $route['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if ($ips === []) return null;
        foreach ($ips as $ip) {
            if (empty($route['allow_private']) && !self::publicIp($ip)) return null;
        }
        return array_values($ips);
    }

    private static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** True for references YOLOTASK V2 owns. */
    public static function isOwnReference(string $orderSn): bool
    {
        return (bool) preg_match(self::OWN_REF, $orderSn);
    }

    /** The first route whose pattern matches, never for YOLOTASK's own refs. */
    public static function match(string $orderSn, array $routes): ?array
    {
        if ($orderSn === '' || self::isOwnReference($orderSn)) return null;
        foreach ($routes as $route) {
            if (@preg_match($route['pattern'], $orderSn) === 1) return $route;
        }
        return null;
    }

    /**
     * Called by the hook before YOLOTASK's own processing.
     * Returns null when the callback is YOLOTASK's (or forwarding is off),
     * otherwise ['status' => int, 'body' => string] to send back to AfrStar.
     */
    public static function handle(string $kind, string $raw, array $post, string $contentType, string $ip): ?array
    {
        $cfg = self::config();
        $routes = $cfg['routes'];
        if ($routes === [] && $cfg['clients'] === []) return null;
        $payload = CallbackVerifier::parse($raw, $post);
        $orderSn = substr(trim(is_scalar($payload['order_sn'] ?? null) ? (string) $payload['order_sn'] : ''), 0, 64);
        $route = self::match($orderSn, $routes) ?? self::registeredRoute($orderSn, $cfg['clients']);
        if ($route === null) return null;
        if ($raw === '' && $post !== []) {
            // multipart/form-data: PHP consumed the body; re-encode the fields.
            $raw = http_build_query($post);
            $contentType = 'application/x-www-form-urlencoded';
        }
        return self::forward($kind, $route, $raw, $contentType, $orderSn, $ip);
    }

    /** Route for an order a registered client recorded, or null. */
    public static function registeredRoute(string $orderSn, array $clients): ?array
    {
        if ($orderSn === '' || $clients === [] || self::isOwnReference($orderSn)) return null;
        try {
            $stmt = Database::connection()->prepare('SELECT client, callback_url FROM afrstar_forward_registrations WHERE order_sn = :sn');
            $stmt->execute([':sn' => $orderSn]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('payments.forward_lookup_failed', ['exception' => get_class($e)]);
            return null;
        }
        if (!$row || !isset($clients[$row['client']])) return null;
        $client = $clients[$row['client']];
        return self::validateRoute(['name' => $client['name'], 'pattern' => '/^/', 'url' => (string) $row['callback_url'], 'timeout' => $client['timeout']], (bool) $client['allow_private']);
    }

    /**
     * Register (or update) one order for a client. Caller has already checked
     * the signature. Returns [http status, response array].
     * @return array{0:int,1:array<string,mixed>}
     */
    public static function register(array $client, string $orderSn, string $callbackUrl): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $orderSn)) return [422, ['ok' => false, 'error' => 'bad_order_sn']];
        if (self::isOwnReference($orderSn)) return [422, ['ok' => false, 'error' => 'reserved_order_sn']];
        if (self::match($orderSn, self::config()['routes']) !== null) return [422, ['ok' => false, 'error' => 'reserved_order_sn']];
        if (strlen($callbackUrl) > 500) return [422, ['ok' => false, 'error' => 'bad_callback_url']];
        $reason = null;
        $route = self::validateRoute(['name' => $client['name'], 'pattern' => '/^/', 'url' => $callbackUrl], (bool) ($client['allow_private'] ?? false), $reason);
        if ($route === null) return [422, ['ok' => false, 'error' => 'bad_callback_url', 'reason' => $reason]];
        if ($client['allowed_hosts'] !== [] && !in_array($route['host'], $client['allowed_hosts'], true)) return [422, ['ok' => false, 'error' => 'host_not_allowed']];
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT client FROM afrstar_forward_registrations WHERE order_sn = :sn');
        $stmt->execute([':sn' => $orderSn]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false && $owner !== $client['name']) return [409, ['ok' => false, 'error' => 'order_sn_taken']];
        if ($owner === false) {
            $pdo->prepare('INSERT INTO afrstar_forward_registrations (order_sn, client, callback_url) VALUES (:sn, :c, :u)')
                ->execute([':sn' => $orderSn, ':c' => $client['name'], ':u' => $callbackUrl]);
            return [201, ['ok' => true, 'order_sn' => $orderSn, 'status' => 'registered']];
        }
        $pdo->prepare('UPDATE afrstar_forward_registrations SET callback_url = :u, updated_at = CURRENT_TIMESTAMP WHERE order_sn = :sn')
            ->execute([':sn' => $orderSn, ':u' => $callbackUrl]);
        return [200, ['ok' => true, 'order_sn' => $orderSn, 'status' => 'updated']];
    }

    /** @return array{status:int,body:string} */
    public static function forward(string $kind, array $route, string $raw, string $contentType, string $orderSn, string $ip): array
    {
        $ct = self::cleanContentType($contentType);
        $ips = self::resolve($route);
        if ($ips === null) {
            self::log($kind, $route['name'], $orderSn, $ip, $ct, $raw, null, 0, 'failed', 'blocked_host', '');
            return ['status' => 502, 'body' => 'FORWARD_FAILED'];
        }
        $started = hrtime(true);
        $excerpt = '';
        $ch = curl_init($route['url']);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $ct, 'Accept: text/plain, */*', 'Expect:', 'X-Forwarded-By: yolotask-afrstar-hub'],
            CURLOPT_USERAGENT => 'YOLOTASK-AfrStar-Forwarder/1.0',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', // direct connection only: the pinned address must be the one we connect to
            CURLOPT_TIMEOUT => $route['timeout'],
            CURLOPT_CONNECTTIMEOUT => min(5, $route['timeout']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($h, string $chunk) use (&$excerpt): int {
                if (strlen($excerpt) < 1024) $excerpt .= substr($chunk, 0, 1024 - strlen($excerpt));
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (!filter_var($route['host'], FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = array_map(static fn (string $a): string => $route['host'] . ':' . $route['port'] . ':' . $a, $ips);
        }
        $ca = self::config()['ca_file'];
        if ($ca !== null) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $latency = (int) round((hrtime(true) - $started) / 1e6);

        $short = self::shortBody($excerpt);
        if ($ok === false || $errno !== 0 || $code === 0) {
            $outcome = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'failed';
            $result = ['status' => 502, 'body' => 'FORWARD_' . strtoupper($outcome)];
        } elseif ($code >= 500 || ($code >= 300 && $code < 400) || $code < 200) {
            $outcome = 'failed';
            $result = ['status' => 502, 'body' => 'FORWARD_FAILED'];
        } elseif ($code >= 200 && $code < 300) {
            $outcome = 'delivered';
            $result = ['status' => $code, 'body' => $short === '' ? 'success' : $short];
        } else {
            $outcome = 'rejected';
            $result = ['status' => $code, 'body' => $short === '' ? 'REJECTED' : $short];
        }
        self::log($kind, $route['name'], $orderSn, $ip, $ct, $raw, $code ?: null, $latency, $outcome, $errno ? 'curl_' . $errno : null, $short);
        return $result;
    }

    /** Latest forwarded callbacks for the admin page (empty before migration 0007). */
    public static function recent(PDO $pdo, int $limit = 20): array
    {
        try {
            return $pdo->query('SELECT id, kind, route, order_sn, downstream_status, latency_ms, outcome, error_code, received_at FROM afrstar_callback_forwards ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function cleanContentType(string $ct): string
    {
        $ct = trim(preg_replace('/[\r\n]+/', ' ', $ct) ?? '');
        if ($ct === '' || strlen($ct) > 120 || !preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+(\s*;.*)?$#', $ct)) return 'application/json';
        return $ct;
    }

    /** One line, printable, short: what AfrStar sees as the response body. */
    private static function shortBody(string $body): string
    {
        $line = trim((string) preg_replace('/[^\x20-\x7E]+/', ' ', $body));
        return substr($line, 0, self::MAX_EXCERPT);
    }

    /** Log without the body (only its size and hash) and never block the answer. */
    private static function log(string $kind, string $route, string $orderSn, string $ip, string $ct, string $raw, ?int $code, int $latency, string $outcome, ?string $error, string $excerpt): void
    {
        try {
            Database::connection()->prepare('INSERT INTO afrstar_callback_forwards (kind, route, order_sn, remote_ip, content_type, body_bytes, body_sha256, downstream_status, latency_ms, outcome, error_code, response_excerpt)
                VALUES (:k, :r, :o, :ip, :ct, :b, :h, :s, :l, :out, :e, :x)')
                ->execute(['k' => $kind, 'r' => $route, 'o' => $orderSn, 'ip' => substr($ip, 0, 45) ?: null, 'ct' => substr($ct, 0, 120), 'b' => strlen($raw),
                    'h' => hash('sha256', $raw), 's' => $code, 'l' => $latency, 'out' => $outcome, 'e' => $error, 'x' => substr($excerpt, 0, 60) ?: null]);
        } catch (\Throwable $e) {
            Logger::error('payments.forward_log_failed', ['route' => $route, 'outcome' => $outcome, 'exception' => get_class($e)]);
        }
        if ($outcome !== 'delivered') {
            Logger::error('payments.forward_' . $outcome, ['route' => $route, 'kind' => $kind, 'order_sn' => $orderSn, 'status' => $code, 'latency_ms' => $latency, 'error' => $error]);
        }
    }
}
