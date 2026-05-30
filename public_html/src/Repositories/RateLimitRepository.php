<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class RateLimitRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Increments and returns the current hit count for the given (ip, route_key, window) bucket.
     *
     * Uses a single atomic INSERT..ON DUPLICATE KEY UPDATE.
     */
    public function hit(string $ip, string $routeKey, int $windowSeconds): int
    {
        $ip = trim($ip);
        $routeKey = trim($routeKey);
        if ($ip === '' || $routeKey === '' || $windowSeconds <= 0) {
            return 0;
        }

        $now = time();
        $windowStart = (int) (intdiv($now, $windowSeconds) * $windowSeconds);

        // Two-step (upsert + select) keeps the logic simple and avoids LAST_INSERT_ID edge cases.
        $this->db->executeStatement(
            'INSERT INTO rate_limits (ip, route_key, window_start, hits) VALUES (?, ?, ?, 1)
'
            . 'ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$ip, $routeKey, $windowStart]
        );

        $hits = $this->db->fetchOne(
            'SELECT hits FROM rate_limits WHERE ip = ? AND route_key = ? AND window_start = ?',
            [$ip, $routeKey, $windowStart]
        );

        return (int) ($hits ?? 0);
    }
}
