<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class PurchaseRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listRecent(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAllAssociative(
            'SELECT * FROM purchases ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
    }

    public function listRecentByEventId(int $eventId, int $limit = 200): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->db->fetchAllAssociative(
            'SELECT DISTINCT p.*
             FROM purchases p
             JOIN purchase_tickets pt ON pt.purchase_id = p.id
             WHERE pt.event_id = ?
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . $limit,
            [$eventId]
        );
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM purchases WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('purchases', $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('purchases', $data, ['id' => $id]);
    }

    public function markReceiptSent(int $id): void
    {
        $this->db->update('purchases', [
            'receipt_email_sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'receipt_email_error' => null,
        ], ['id' => $id]);
    }

    public function markReceiptFailed(int $id, string $error): void
    {
        $error = trim($error);
        if (strlen($error) > 255) {
            $error = substr($error, 0, 255);
        }

        $this->db->update('purchases', [
            'receipt_email_sent_at' => null,
            'receipt_email_error' => $error !== '' ? $error : 'Unknown error',
        ], ['id' => $id]);
    }

    public function findByProviderReference(string $providerReference): ?array
    {
        $providerReference = trim($providerReference);
        if ($providerReference === '') {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT * FROM purchases WHERE provider_reference = ? LIMIT 1',
            [$providerReference]
        );

        return $row ?: null;
    }
}
