<?php
declare(strict_types=1);
namespace YoloTask\Support;

use PDO;

/** Small offset paginator returning the same shape the Phase 3 pages use. */
final class Pagination
{
    /**
     * @param array<string,mixed> $args
     * @return array{rows:list<array>,total:int,page:int,pages:int}
     */
    public static function query(PDO $pdo, string $countSql, string $rowsSql, array $args, int $page, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $count = $pdo->prepare($countSql);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare($rowsSql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }
}
