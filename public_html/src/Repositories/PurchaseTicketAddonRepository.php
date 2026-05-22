<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class PurchaseTicketAddonRepository
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
                pta.purchase_ticket_id,
                pta.addon_id,
                pta.quantity,
                pta.unit_price_cents,
                ap.name AS addon_name
             FROM purchase_ticket_addons pta
             JOIN addon_products ap ON ap.id = pta.addon_id
             WHERE pta.purchase_ticket_id = ?
             ORDER BY pta.id ASC',
            [$purchaseTicketId]
        );
    }

    public function listByPurchaseId(int $purchaseId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT
                pta.purchase_ticket_id,
                pta.addon_id,
                pta.quantity,
                pta.unit_price_cents,
                ap.name AS addon_name
             FROM purchase_ticket_addons pta
             JOIN purchase_tickets pt ON pt.id = pta.purchase_ticket_id
             JOIN addon_products ap ON ap.id = pta.addon_id
             WHERE pt.purchase_id = ?
             ORDER BY pta.purchase_ticket_id ASC, pta.id ASC',
            [$purchaseId]
        );
    }

    public function create(int $purchaseTicketId, int $addonId, int $quantity, int $unitPriceCents): void
    {
        $this->db->insert('purchase_ticket_addons', [
            'purchase_ticket_id' => $purchaseTicketId,
            'addon_id' => $addonId,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
        ]);
    }
}
