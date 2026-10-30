<?php
declare(strict_types=1);
namespace YoloTask\Support;

/**
 * Private runtime storage root (outside every document root):
 * config storage.private_root, else env YOLOTASK_STORAGE_DIR, else
 * <deployment>/runtime next to config-private.
 */
final class Storage
{
    public static function root(): string
    {
        $cfg = function_exists('yolotask_config') ? (yolotask_config()['storage']['private_root'] ?? null) : null;
        $root = is_string($cfg) && $cfg !== '' ? $cfg : (getenv('YOLOTASK_STORAGE_DIR') ?: '');
        if ($root === '') {
            $app = defined('YOLOTASK_APP_ROOT') ? (realpath(YOLOTASK_APP_ROOT) ?: YOLOTASK_APP_ROOT) : dirname(__DIR__, 3);
            $root = dirname($app, 2) . '/runtime';
        }
        return rtrim($root, '/');
    }

    /** A private subdirectory, created 0700 when missing; null if it cannot be created. */
    public static function dir(string $name): ?string
    {
        $dir = self::root() . '/' . trim($name, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        return $dir;
    }
}
