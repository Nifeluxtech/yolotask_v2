<?php
declare(strict_types=1);
namespace YoloTask\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) return self::$connection;
        $cfgDir = getenv('YOLOTASK_CONFIG_DIR');
        if (!$cfgDir) {
            $root = defined('YOLOTASK_APP_ROOT') ? (realpath(YOLOTASK_APP_ROOT) ?: YOLOTASK_APP_ROOT) : dirname(__DIR__, 4);
            $cfgDir = dirname($root, 2) . '/config-private';
        }
        $file = rtrim($cfgDir, '/') . '/database.local.php';
        if (!is_file($file)) throw new RuntimeException('Private database configuration is missing.');
        $cfg = require $file;
        if (!is_array($cfg)) throw new RuntimeException('Database configuration must return an array.');
        $host=(string)($cfg['host']??''); $db=(string)($cfg['database']??''); $user=(string)($cfg['username']??''); $pass=(string)($cfg['password']??'');
        if ($host==='' || $db==='' || $user==='') throw new RuntimeException('Database configuration is incomplete.');
        $charset=(string)($cfg['charset']??'utf8mb4');
        self::$connection = new PDO("mysql:host={$host};dbname={$db};charset={$charset}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        return self::$connection;
    }
}
