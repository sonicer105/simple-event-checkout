<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class CartItemModifierRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByCartId(int $cartId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT
                cim.cart_item_id,
                cim.modifier_id,
                cim.value,
                em.name AS modifier_name,
                em.modifier_type
             FROM cart_item_modifiers cim
             JOIN cart_items ci ON ci.id = cim.cart_item_id
             JOIN event_modifiers em ON em.id = cim.modifier_id
             WHERE ci.cart_id = ?
             ORDER BY cim.cart_item_id ASC, cim.modifier_id ASC',
            [$cartId]
        );
    }

    public function replaceForCartItem(int $cartItemId, array $valuesByModifierId): void
    {
        $this->db->delete('cart_item_modifiers', ['cart_item_id' => $cartItemId]);

        foreach ($valuesByModifierId as $modifierId => $value) {
            $this->db->insert('cart_item_modifiers', [
                'cart_item_id' => $cartItemId,
                'modifier_id' => (int) $modifierId,
                'value' => $value !== null ? (string) $value : null,
            ]);
        }
    }
}
