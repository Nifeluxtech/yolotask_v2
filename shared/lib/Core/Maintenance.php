<?php
declare(strict_types=1);
namespace YoloTask\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Site-wide maintenance mode (settings maintenance.enabled / .message / .until).
 *
 * While ON, every request to the main site and the earner, advertiser and
 * reviewer portals gets the maintenance page (HTTP 503, Retry-After, no-cache;
 * a JSON body for fetch/XHR/JSON requests). Never gated: the admin portal,
 * payment callbacks (role "hook", YOLOTASK_NO_SESSION), cron and other CLI,
 * any front controller that defines YOLOTASK_MAINTENANCE_EXEMPT (health
 * checks), and static files (served by Apache, never through PHP).
 *
 * Fails open: if the settings cannot be read (database down, missing config)
 * the site stays up and the failure is logged.
 *
 * Tester link: an admin can create a secret link (?tester=<64 hex token>) that
 * lets its holder use the gated portals while maintenance is ON. Only the
 * token's sha256 (maintenance.preview_hash) and its expiry
 * (maintenance.preview_expires_at, Unix seconds) are stored. A valid token in
 * the query sets the yt_tester cookie (shared across the portal subdomains);
 * afterwards the cookie alone lets requests through. Revoking clears the hash.
 * With maintenance OFF the token does nothing.
 */
final class Maintenance
{
    public const DEFAULT_MESSAGE = "YOLOTASK is getting an upgrade. We'll be back shortly.";
    public const MESSAGE_MAX = 255;   // settings.setting_value is VARCHAR(255)
    public const UNTIL_FORMAT = 'Y-m-d H:i'; // Africa/Lagos wall-clock time
    public const GATED_ROLES = ['main', 'earner', 'advertiser', 'reviewer'];
    private const RETRY_DEFAULT_SECONDS = 600;
    private const RETRY_MAX_SECONDS = 86400;
    public const TESTER_PARAM = 'tester';
    public const TESTER_COOKIE = 'yt_tester';
    public const TESTER_DEFAULT_DAYS = 7;

    /** True once gate() let this request through on the tester pass. */
    private static bool $testerPass = false;

    /** @var array{enabled: bool, message: string, until: ?DateTimeImmutable, preview_hash: string, preview_expires_at: int}|null */
    private static ?array $override = null;

    /** Tests only: replace the settings lookup with a fixed state (null = read settings again). */
    public static function useState(?array $state): void
    {
        self::$override = $state === null ? null : self::normalise($state);
    }

    /**
     * Current state. Never throws: a failed lookup returns OFF (fail open) and logs.
     * @return array{enabled: bool, message: string, until: ?DateTimeImmutable, preview_hash: string, preview_expires_at: int}
     */
    public static function state(?PDO $pdo = null): array
    {
        if (self::$override !== null) return self::$override;
        try {
            $pdo ??= Database::connection();
            $all = Settings::all($pdo);
            return self::normalise([
                'enabled' => $all['maintenance.enabled'] ?? '0',
                'message' => $all['maintenance.message'] ?? '',
                'until' => $all['maintenance.until'] ?? '',
                'preview_hash' => $all['maintenance.preview_hash'] ?? '',
                'preview_expires_at' => $all['maintenance.preview_expires_at'] ?? '',
            ]);
        } catch (Throwable $e) {
            Logger::error('maintenance.lookup_failed', ['exception' => get_class($e)]);
            return ['enabled' => false, 'message' => self::DEFAULT_MESSAGE, 'until' => null, 'preview_hash' => '', 'preview_expires_at' => 0];
        }
    }

    public static function enabled(?PDO $pdo = null): bool
    {
        return self::state($pdo)['enabled'];
    }

    /** Parse a stored or submitted Lagos time ("Y-m-d H:i" or "Y-m-d\TH:i"); null when empty or invalid. */
    public static function parseUntil(string $value): ?DateTimeImmutable
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') return null;
        $tz = new DateTimeZone('Africa/Lagos');
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, $tz);
            if ($dt instanceof DateTimeImmutable && $dt->format($format) === $value) return $dt;
        }
        return null;
    }

    /** Seconds for Retry-After: until the ETA when it is in the future, otherwise a default. */
    public static function retryAfter(array $state, ?DateTimeImmutable $now = null): int
    {
        $until = $state['until'] ?? null;
        if ($until instanceof DateTimeImmutable) {
            $secs = $until->getTimestamp() - ($now ?? Clock::now())->getTimestamp();
            if ($secs > 0) return max(60, min(self::RETRY_MAX_SECONDS, $secs));
        }
        return self::RETRY_DEFAULT_SECONDS;
    }

    /** Whether this request is exempt from the gate (admin portal, hooks, cron/CLI, health check). */
    public static function exempt(string $role): bool
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') return true;
        if (defined('YOLOTASK_NO_SESSION') || defined('YOLOTASK_MAINTENANCE_EXEMPT')) return true;
        return !in_array($role, self::GATED_ROLES, true);
    }

    /** Called from shared/bootstrap.php on every web request. Ends the request with 503 when ON. */
    public static function gate(string $role, string $appRoot): void
    {
        if (self::exempt($role)) return;
        $state = self::state();
        if (!$state['enabled']) return;
        if (self::testerAllowed($state)) {
            self::$testerPass = true;
            if (!headers_sent()) {
                header('X-Robots-Tag: noindex');
                header('Cache-Control: no-store, private');
            }
            return;
        }
        self::respond($state, $appRoot);
    }

    /** A new tester token: 64 hex characters (32 random bytes). Store only testerHash() of it. */
    public static function newTesterToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function testerHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether $token matches the stored tester hash and has not expired (or been revoked). */
    public static function testerTokenValid(string $token, array $state, ?int $now = null): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return false;
        $hash = (string) ($state['preview_hash'] ?? '');
        $expires = (int) ($state['preview_expires_at'] ?? 0);
        if (!preg_match('/^[0-9a-f]{64}$/', $hash) || $expires <= ($now ?? time())) return false;
        return hash_equals($hash, self::testerHash($token));
    }

    /** Tester pass for this request: ?tester=<token> (sets the cookie) or the yt_tester cookie. */
    private static function testerAllowed(array $state): bool
    {
        $query = $_GET[self::TESTER_PARAM] ?? null;
        if (is_string($query) && self::testerTokenValid($query, $state)) {
            self::setTesterCookie($query, (int) $state['preview_expires_at']);
            return true;
        }
        $cookie = $_COOKIE[self::TESTER_COOKIE] ?? null;
        return is_string($cookie) && self::testerTokenValid($cookie, $state);
    }

    private static function setTesterCookie(string $token, int $expires): void
    {
        if (headers_sent()) return;
        $session = function_exists('yolotask_config') ? (yolotask_config()['session'] ?? []) : [];
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $options = [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool) (is_array($session) && array_key_exists('secure', $session) ? $session['secure'] : $https),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $domain = self::cookieDomain();
        if ($domain !== null) $options['domain'] = $domain;
        setcookie(self::TESTER_COOKIE, $token, $options);
    }

    /**
     * Cookie domain shared by the main site and every portal: "." + the main
     * site's host from yolotask_portal_url('main') (config base_urls or the
     * current host), minus a leading "staging.". Null (host-only cookie) for
     * localhost and IP addresses.
     */
    public static function cookieDomain(?string $mainUrl = null): ?string
    {
        if ($mainUrl === null) {
            $mainUrl = function_exists('yolotask_portal_url') ? yolotask_portal_url('main') : 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? '');
        }
        $host = strtolower((string) parse_url($mainUrl, PHP_URL_HOST));
        $current = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? $host)));
        if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || filter_var($current, FILTER_VALIDATE_IP) || $current === 'localhost') return null;
        $host = (string) preg_replace('/^(?:www|staging)\./', '', $host);
        // A Domain the browser would reject (current host outside it) falls back to host-only.
        if ($current !== '' && $current !== $host && !str_ends_with($current, '.' . $host)) return null;
        return '.' . $host;
    }

    /** True when this request was let through on the tester pass (maintenance ON). */
    public static function viaTesterPass(): bool
    {
        return self::$testerPass;
    }

    /** Small fixed banner for pages served on the tester pass; '' otherwise. */
    public static function testerBanner(): string
    {
        if (!self::$testerPass) return '';
        return '<div data-tester-banner role="status" style="position:fixed;left:50%;bottom:12px;transform:translateX(-50%);z-index:9999;'
            . 'padding:6px 12px;border-radius:999px;background:#b45309;color:#fff;font:600 12px/1.3 system-ui,sans-serif;'
            . 'box-shadow:0 2px 8px rgba(0,0,0,.25);pointer-events:none;max-width:92vw;text-align:center">'
            . 'Tester mode — site is under maintenance for everyone else</div>';
    }

    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== '') return true;
        if (str_contains($type, 'application/json')) return true;
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    /** @param array{enabled: bool, message: string, until: ?DateTimeImmutable} $state */
    public static function respond(array $state, string $appRoot): never
    {
        $retry = self::retryAfter($state);
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: ' . $retry);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
            header('X-Robots-Tag: noindex');
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (self::wantsJson()) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            if ($method !== 'HEAD') {
                echo json_encode([
                    'ok' => false,
                    'error' => 'maintenance',
                    'message' => $state['message'],
                    'until' => $state['until'] instanceof DateTimeImmutable ? $state['until']->format(DATE_ATOM) : null,
                    'retry_after' => $retry,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            }
            exit;
        }
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        if ($method !== 'HEAD') {
            $maintenanceMessage = $state['message'];
            $maintenanceUntil = $state['until'];
            require $appRoot . '/shared/views/errors/maintenance.php';
        }
        exit;
    }

    /** @return array{enabled: bool, message: string, until: ?DateTimeImmutable, preview_hash: string, preview_expires_at: int} */
    private static function normalise(array $raw): array
    {
        $message = trim((string) ($raw['message'] ?? ''));
        $until = $raw['until'] ?? null;
        if (!$until instanceof DateTimeImmutable) $until = self::parseUntil((string) ($until ?? ''));
        $enabled = $raw['enabled'] ?? false;
        $hash = strtolower(trim((string) ($raw['preview_hash'] ?? '')));
        return [
            'enabled' => $enabled === true || (string) $enabled === '1',
            'message' => $message !== '' ? $message : self::DEFAULT_MESSAGE,
            'until' => $until,
            'preview_hash' => preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : '',
            'preview_expires_at' => (int) ($raw['preview_expires_at'] ?? 0),
        ];
    }
}
