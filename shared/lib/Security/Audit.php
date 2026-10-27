<?php
declare(strict_types=1);
namespace YoloTask\Security;

use PDO;

final class Audit
{
    public static function record(PDO $pdo, ?int $actorId, string $event, ?string $targetType = null, ?int $targetId = null): void
    {
        $requestId = $_SERVER['YOLOTASK_REQUEST_ID'] ?? bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO audit_log (actor_user_id, event_key, target_type, target_id, request_id) VALUES (:actor, :event, :target_type, :target_id, :request_id)');
        $stmt->execute(['actor' => $actorId, 'event' => $event, 'target_type' => $targetType, 'target_id' => $targetId, 'request_id' => substr((string) $requestId, 0, 32)]);
    }
}
