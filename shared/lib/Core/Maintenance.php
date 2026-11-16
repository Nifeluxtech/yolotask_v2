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
 */
final class Maintenance
{
    public const DEFAULT_MESSAGE = "YOLOTASK is getting an upgrade. We'll be back shortly.";
    public const MESSAGE_MAX = 255;   // settings.setting_value is VARCHAR(255)
    public const UNTIL_FORMAT = 'Y-m-d H:i'; // Africa/Lagos wall-clock time
    public const GATED_ROLES = ['main', 'earner', 'advertiser', 'reviewer'];
    private const RETRY_DEFAULT_SECONDS = 600;
    private const RETRY_MAX_SECONDS = 86400;

    /** @var array{enabled: bool, message: string, until: ?DateTimeImmutable}|null */
    private static ?array $override = null;

    /** Tests only: replace the settings lookup with a fixed state (null = read settings again). */
    public static function useState(?array $state): void
    {
        self::$override = $state === null ? null : self::normalise($state);
    }

    /**
     * Current state. Never throws: a failed lookup returns OFF (fail open) and logs.
     * @return array{enabled: bool, message: string, until: ?DateTimeImmutable}
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
            ]);
        } catch (Throwable $e) {
            Logger::error('maintenance.lookup_failed', ['exception' => get_class($e)]);
            return ['enabled' => false, 'message' => self::DEFAULT_MESSAGE, 'until' => null];
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
        self::respond($state, $appRoot);
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

    /** @return array{enabled: bool, message: string, until: ?DateTimeImmutable} */
    private static function normalise(array $raw): array
    {
        $message = trim((string) ($raw['message'] ?? ''));
        $until = $raw['until'] ?? null;
        if (!$until instanceof DateTimeImmutable) $until = self::parseUntil((string) ($until ?? ''));
        $enabled = $raw['enabled'] ?? false;
        return [
            'enabled' => $enabled === true || (string) $enabled === '1',
            'message' => $message !== '' ? $message : self::DEFAULT_MESSAGE,
            'until' => $until,
        ];
    }
}
