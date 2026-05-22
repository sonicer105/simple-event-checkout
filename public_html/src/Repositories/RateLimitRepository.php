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

        // LAST_INSERT_ID trick lets us read the updated hit count without a separate SELECT.
        $this->db->executeStatement(
            'INSERT INTO rate_limits (ip, route_key, window_start, hits) VALUES (?, ?, ?, 1)
'
            . 'ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
            [$ip, $routeKey, $windowStart]
        );

        return (int) $this->db->fetchOne('SELECT LAST_INSERT_ID()');
    }
}
