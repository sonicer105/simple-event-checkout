<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class PurchaseTicketModifierRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByPurchaseTicketId(int $purchaseTicketId): array
    {
        if ($purchaseTicketId <= 0) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            'SELECT
                ptm.purchase_ticket_id,
                ptm.modifier_id,
                ptm.value,
                em.name AS modifier_name,
                em.modifier_type
             FROM purchase_ticket_modifiers ptm
             JOIN event_modifiers em ON em.id = ptm.modifier_id
             WHERE ptm.purchase_ticket_id = ?
             ORDER BY ptm.id ASC',
            [$purchaseTicketId]
        );
    }

    public function listByPurchaseId(int $purchaseId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT
                ptm.purchase_ticket_id,
                ptm.modifier_id,
                ptm.value,
                em.name AS modifier_name,
                em.modifier_type
             FROM purchase_ticket_modifiers ptm
             JOIN purchase_tickets pt ON pt.id = ptm.purchase_ticket_id
             JOIN event_modifiers em ON em.id = ptm.modifier_id
             WHERE pt.purchase_id = ?
             ORDER BY ptm.purchase_ticket_id ASC, ptm.id ASC',
            [$purchaseId]
        );
    }

    public function create(int $purchaseTicketId, int $modifierId, ?string $value): void
    {
        $this->db->insert('purchase_ticket_modifiers', [
            'purchase_ticket_id' => $purchaseTicketId,
            'modifier_id' => $modifierId,
            'value' => $value,
        ]);
    }
}
