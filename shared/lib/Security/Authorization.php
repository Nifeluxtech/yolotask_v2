<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;

final class Authorization
{
    public static function allowsSelfRegistration(string $role): bool
    {
        return in_array($role, Auth::registerableRoles(), true);
    }

    public static function userHasRole(?array $user, array $roles): bool
    {
        return is_array($user) && isset($user['role']) && in_array((string) $user['role'], $roles, true);
    }

    public static function requireRole(PDO $pdo, array $roles): array
    {
        $user = Auth::currentUser($pdo);
        if (!self::userHasRole($user, $roles)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Access denied.');
        }
        return $user;
    }
}
