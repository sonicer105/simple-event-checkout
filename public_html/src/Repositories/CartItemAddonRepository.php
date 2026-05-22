<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class CartItemAddonRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByCartId(int $cartId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT
                cia.cart_item_id,
                cia.addon_id,
                cia.quantity,
                cia.unit_price_cents,
                ap.name AS addon_name
             FROM cart_item_addons cia
             JOIN cart_items ci ON ci.id = cia.cart_item_id
             JOIN addon_products ap ON ap.id = cia.addon_id
             WHERE ci.cart_id = ?
             ORDER BY cia.cart_item_id ASC, cia.id ASC',
            [$cartId]
        );
    }

    public function replaceForCartItem(int $cartItemId, array $qtyByAddonId, array $unitPriceByAddonId): void
    {
        $this->db->delete('cart_item_addons', ['cart_item_id' => $cartItemId]);

        foreach ($qtyByAddonId as $addonId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }

            $this->db->insert('cart_item_addons', [
                'cart_item_id' => $cartItemId,
                'addon_id' => (int) $addonId,
                'quantity' => $qty,
                'unit_price_cents' => (int) ($unitPriceByAddonId[(int) $addonId] ?? 0),
            ]);
        }
    }
}
