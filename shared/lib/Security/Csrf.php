<?php
declare(strict_types=1);
namespace YoloTask\Security;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('Session is not active.');
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
    public static function verify(?string $candidate): bool
    {
        return is_string($candidate) && isset($_SESSION['_csrf']) && is_string($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $candidate);
    }
}
