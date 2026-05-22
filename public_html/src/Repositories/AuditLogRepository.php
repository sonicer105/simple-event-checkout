<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class AuditLogRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listRecent(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAllAssociative(
            'SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
    }

    public function log(?int $adminId, string $eventType, string $message, ?string $ip = null, ?string $userAgent = null, ?string $referrer = null): void
    {
        $this->db->insert('audit_logs', [
            'admin_id' => $adminId,
            'event_type' => $eventType,
            'message' => $message,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referrer' => $referrer,
        ]);
    }
}
