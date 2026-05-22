<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class CartItemRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByCartId(int $cartId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT ci.*, e.name AS event_name, e.slug AS event_slug, e.image_path, ev.name AS variation_name
             FROM cart_items ci
             JOIN events e ON e.id = ci.event_id
             LEFT JOIN event_variations ev ON ev.id = ci.variation_id
             WHERE ci.cart_id = ?
             ORDER BY ci.id ASC',
            [$cartId]
        );
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM cart_items WHERE id = ? LIMIT 1', [$id]);
        return $row ?: null;
    }

    public function findLine(int $cartId, int $eventId, ?int $variationId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cart_items WHERE cart_id = ? AND event_id = ? AND ((variation_id IS NULL AND ? IS NULL) OR variation_id = ?) LIMIT 1',
            [$cartId, $eventId, $variationId, $variationId]
        );

        return $row ?: null;
    }

    public function create(int $cartId, int $eventId, ?int $variationId, int $quantity, int $unitPriceCents): int
    {
        $this->db->insert('cart_items', [
            'cart_id' => $cartId,
            'event_id' => $eventId,
            'variation_id' => $variationId,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setQuantity(int $id, int $quantity): void
    {
        $this->db->update('cart_items', ['quantity' => $quantity], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('cart_items', ['id' => $id]);
    }
}
