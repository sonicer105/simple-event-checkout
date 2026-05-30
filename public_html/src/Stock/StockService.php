<?php

declare(strict_types=1);

namespace App\Stock;

use App\Repositories\AddonProductRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventVariationRepository;
use Doctrine\DBAL\Connection;

final class StockService
{
    public function __construct(
        private Connection $db,
        private EventRepository $events,
        private EventVariationRepository $variations,
        private AddonProductRepository $addons,
    ) {
    }

    /**
     * Enforce ticket stock for a given event+variation in the context of a cart.
     *
     * $desiredQty is the quantity you want this specific cart line to have; the service will add any
     * *other* matching tickets already in the same cart, and compare against sold + reserved in other carts.
     *
     * A stock_limit of 0 or NULL is treated as unlimited.
     */
    public function assertCanReserveTickets(int $cartId, int $eventId, ?int $variationId, int $desiredQty, ?int $excludeCartItemId = null): void
    {
        $desiredQty = max(0, $desiredQty);

        $stockLimit = $this->ticketStockLimit($eventId, $variationId);
        if ($stockLimit <= 0) {
            return; // unlimited
        }

        $cartOtherQty = (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(ci.quantity), 0)
             FROM cart_items ci
             WHERE ci.cart_id = ?
               AND ci.event_id = ?
               AND ((ci.variation_id IS NULL AND ? IS NULL) OR ci.variation_id = ?)
               AND (? IS NULL OR ci.id <> ?)',
            [$cartId, $eventId, $variationId, $variationId, $excludeCartItemId, $excludeCartItemId]
        );

        $sold = (int) $this->db->fetchOne(
            "SELECT COALESCE(COUNT(pt.id), 0)
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             WHERE pt.event_id = ?
               AND ((pt.variation_id IS NULL AND ? IS NULL) OR pt.variation_id = ?)
               AND pt.refunded_at IS NULL",
            [$eventId, $variationId, $variationId]
        );

        $reservedOther = (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(ci.quantity), 0)
             FROM carts c
             JOIN cart_items ci ON ci.cart_id = c.id
             WHERE c.id <> ?
               AND c.reserved_until > CURRENT_TIMESTAMP
               AND c.expires_at > CURRENT_TIMESTAMP
               AND ci.event_id = ?
               AND ((ci.variation_id IS NULL AND ? IS NULL) OR ci.variation_id = ?)',
            [$cartId, $eventId, $variationId, $variationId]
        );

        $wantedTotal = $cartOtherQty + $desiredQty;
        $available = $stockLimit - $sold - $reservedOther;
        if ($wantedTotal > $available) {
            $label = $variationId !== null ? 'this ticket option' : 'this ticket';
            throw new \RuntimeException("Not enough stock remaining for {$label}. Available: {$available}.");
        }
    }

    /**
     * Enforce addon stock limits for a set of add-ons being added/updated on a cart item.
     *
     * A stock_limit of 0 or NULL is treated as unlimited.
     */
    public function assertCanReserveAddons(int $cartId, array $qtyByAddonId, ?int $excludeCartItemId = null): void
    {
        foreach ($qtyByAddonId as $addonId => $qty) {
            $addonId = (int) $addonId;
            $qty = (int) $qty;
            if ($addonId <= 0 || $qty <= 0) {
                continue;
            }

            $addon = $this->addons->findById($addonId);
            if (!$addon) {
                continue;
            }

            $stockLimit = (int) ($addon['stock_limit'] ?? 0);
            if ($stockLimit <= 0) {
                continue; // unlimited
            }

            $cartOtherQty = (int) $this->db->fetchOne(
                'SELECT COALESCE(SUM(cia.quantity), 0)
                 FROM cart_item_addons cia
                 JOIN cart_items ci ON ci.id = cia.cart_item_id
                 WHERE ci.cart_id = ?
                   AND cia.addon_id = ?
                   AND (? IS NULL OR cia.cart_item_id <> ?)',
                [$cartId, $addonId, $excludeCartItemId, $excludeCartItemId]
            );

            $sold = (int) $this->db->fetchOne(
                "SELECT COALESCE(SUM(pia.quantity), 0)
                 FROM purchase_ticket_addons pia
                 JOIN purchase_tickets pt ON pt.id = pia.purchase_ticket_id AND pt.refunded_at IS NULL
                 JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
                 WHERE pia.addon_id = ?",
                [$addonId]
            );

            $reservedOther = (int) $this->db->fetchOne(
                'SELECT COALESCE(SUM(cia.quantity), 0)
                 FROM carts c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 JOIN cart_item_addons cia ON cia.cart_item_id = ci.id
                 WHERE c.id <> ?
                   AND c.reserved_until > CURRENT_TIMESTAMP
                   AND c.expires_at > CURRENT_TIMESTAMP
                   AND cia.addon_id = ?',
                [$cartId, $addonId]
            );

            $wantedTotal = $cartOtherQty + $qty;
            $available = $stockLimit - $sold - $reservedOther;
            if ($wantedTotal > $available) {
                $name = trim((string) ($addon['name'] ?? 'this add-on'));
                throw new \RuntimeException("Not enough stock remaining for add-on \"{$name}\". Available: {$available}.");
            }
        }
    }

    /**
     * Best-effort stock validation for a whole cart right before checkout starts.
     * Prevents paying for something that can't be fulfilled.
     */
    public function assertCheckoutItemsHaveStock(int $cartId, array $items): void
    {
        $ticketsByKey = [];
        $addonsById = [];

        foreach ($items as $row) {
            $eventId = (int) ($row['event_id'] ?? 0);
            $variationId = ($row['variation_id'] ?? null) !== null ? (int) $row['variation_id'] : null;
            $qty = max(0, (int) ($row['quantity'] ?? 0));

            $key = $eventId . ':' . ($variationId !== null ? (string) $variationId : 'null');
            $ticketsByKey[$key] ??= ['event_id' => $eventId, 'variation_id' => $variationId, 'qty' => 0];
            $ticketsByKey[$key]['qty'] += $qty;

            foreach ((array) ($row['addons'] ?? []) as $a) {
                $addonId = (int) ($a['addon_id'] ?? 0);
                $aQty = (int) ($a['quantity'] ?? 0);
                if ($addonId <= 0 || $aQty <= 0) {
                    continue;
                }
                $addonsById[$addonId] = ($addonsById[$addonId] ?? 0) + $aQty;
            }
        }

        foreach ($ticketsByKey as $t) {
            $this->assertCanReserveTickets($cartId, (int) $t['event_id'], $t['variation_id'] !== null ? (int) $t['variation_id'] : null, (int) $t['qty'], null);
        }

        if (count($addonsById) > 0) {
            $this->assertCanReserveAddons($cartId, $addonsById, null);
        }
    }

    /**
     * Returns a stock summary for the base event ticket (variation_id NULL) and all variations.
     *
     * Rows are shaped for admin display:
     * - label
     * - stock_limit (int|null; null means unlimited)
     * - sold
     * - held
     * - available (int|null; null means unlimited)
     */
    public function getTicketStockOverview(int $eventId): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $event = $this->events->findById($eventId);
        if (!$event) {
            return [];
        }

        $variations = $this->variations->listByEventId($eventId);

        $soldRows = $this->db->fetchAllAssociative(
            "SELECT pt.variation_id, COUNT(pt.id) AS sold
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             WHERE pt.event_id = ?
               AND pt.refunded_at IS NULL
             GROUP BY pt.variation_id",
            [$eventId]
        );
        $soldByKey = [];
        foreach ($soldRows as $r) {
            $key = array_key_exists('variation_id', $r) && $r['variation_id'] !== null ? (string) (int) $r['variation_id'] : 'null';
            $soldByKey[$key] = (int) ($r['sold'] ?? 0);
        }

        $heldRows = $this->db->fetchAllAssociative(
            "SELECT ci.variation_id, COALESCE(SUM(ci.quantity), 0) AS held
             FROM carts c
             JOIN cart_items ci ON ci.cart_id = c.id
             WHERE ci.event_id = ?
               AND c.reserved_until > CURRENT_TIMESTAMP
               AND c.expires_at > CURRENT_TIMESTAMP
             GROUP BY ci.variation_id",
            [$eventId]
        );
        $heldByKey = [];
        foreach ($heldRows as $r) {
            $key = array_key_exists('variation_id', $r) && $r['variation_id'] !== null ? (string) (int) $r['variation_id'] : 'null';
            $heldByKey[$key] = (int) ($r['held'] ?? 0);
        }

        $rows = [];

        // If variations exist, base stock isn't relevant for per-variation availability and is ignored by enforcement.
        if (count($variations) === 0) {
            $baseLimit = (int) ($event['stock_limit'] ?? 0);
            $baseUnlimited = $baseLimit <= 0;
            $baseSold = (int) ($soldByKey['null'] ?? 0);
            $baseHeld = (int) ($heldByKey['null'] ?? 0);
            $rows[] = [
                'label' => 'Base ticket',
                'variation_id' => null,
                'stock_limit' => $baseUnlimited ? null : $baseLimit,
                'sold' => $baseSold,
                'held' => $baseHeld,
                'available' => $baseUnlimited ? null : max(0, $baseLimit - $baseSold - $baseHeld),
            ];
        }

        foreach ($variations as $v) {
            $vid = (int) ($v['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }

            $limit = (int) ($v['stock_limit'] ?? 0);
            $unlimited = $limit <= 0;
            $sold = (int) ($soldByKey[(string) $vid] ?? 0);
            $held = (int) ($heldByKey[(string) $vid] ?? 0);

            $rows[] = [
                'label' => (string) ($v['name'] ?? ('Variation #' . $vid)),
                'variation_id' => $vid,
                'stock_limit' => $unlimited ? null : $limit,
                'sold' => $sold,
                'held' => $held,
                'available' => $unlimited ? null : max(0, $limit - $sold - $held),
            ];
        }

        return $rows;
    }

    /**
     * Returns a stock summary for all add-ons on an event.
     *
     * Rows:
     * - label
     * - stock_limit (int|null; null means unlimited)
     * - sold
     * - held
     * - available (int|null; null means unlimited)
     */
    public function getAddonStockOverview(int $eventId): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $addons = $this->addons->listByEventId($eventId);
        if (count($addons) === 0) {
            return [];
        }

        $soldRows = $this->db->fetchAllAssociative(
            "SELECT pia.addon_id, COALESCE(SUM(pia.quantity), 0) AS sold
             FROM purchase_ticket_addons pia
             JOIN purchase_tickets pt ON pt.id = pia.purchase_ticket_id AND pt.refunded_at IS NULL
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             WHERE pt.event_id = ?
             GROUP BY pia.addon_id",
            [$eventId]
        );
        $soldById = [];
        foreach ($soldRows as $r) {
            $aid = (int) ($r['addon_id'] ?? 0);
            if ($aid > 0) {
                $soldById[$aid] = (int) ($r['sold'] ?? 0);
            }
        }

        $heldRows = $this->db->fetchAllAssociative(
            "SELECT cia.addon_id, COALESCE(SUM(cia.quantity), 0) AS held
             FROM carts c
             JOIN cart_items ci ON ci.cart_id = c.id
             JOIN cart_item_addons cia ON cia.cart_item_id = ci.id
             WHERE ci.event_id = ?
               AND c.reserved_until > CURRENT_TIMESTAMP
               AND c.expires_at > CURRENT_TIMESTAMP
             GROUP BY cia.addon_id",
            [$eventId]
        );
        $heldById = [];
        foreach ($heldRows as $r) {
            $aid = (int) ($r['addon_id'] ?? 0);
            if ($aid > 0) {
                $heldById[$aid] = (int) ($r['held'] ?? 0);
            }
        }

        $rows = [];
        foreach ($addons as $a) {
            $aid = (int) ($a['id'] ?? 0);
            if ($aid <= 0) {
                continue;
            }

            $limit = (int) ($a['stock_limit'] ?? 0);
            $unlimited = $limit <= 0;
            $sold = (int) ($soldById[$aid] ?? 0);
            $held = (int) ($heldById[$aid] ?? 0);

            $rows[] = [
                'label' => (string) ($a['name'] ?? ('Add-on #' . $aid)),
                'addon_id' => $aid,
                'stock_limit' => $unlimited ? null : $limit,
                'sold' => $sold,
                'held' => $held,
                'available' => $unlimited ? null : max(0, $limit - $sold - $held),
            ];
        }

        return $rows;
    }

    /**
     * Availability for ticket options on the public product page.
     *
     * Returns a map of variation_id => available (int|null). Uses 'base' for the base ticket when no variations exist.
     * Availability excludes the current cart's reservations so a user can still adjust their own cart.
     * stock_limit of 0/NULL means unlimited (null available).
     */
    public function getTicketAvailabilityForEvent(int $eventId, ?int $cartId): array
    {
        $eventId = (int) $eventId;
        $cartId = $cartId !== null ? (int) $cartId : 0;
        if ($eventId <= 0) {
            return [];
        }

        $variations = $this->variations->listByEventId($eventId);

        $soldRows = $this->db->fetchAllAssociative(
            "SELECT pt.variation_id, COUNT(pt.id) AS sold
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             WHERE pt.event_id = ?
               AND pt.refunded_at IS NULL
             GROUP BY pt.variation_id",
            [$eventId]
        );
        $soldByKey = [];
        foreach ($soldRows as $r) {
            $key = array_key_exists('variation_id', $r) && $r['variation_id'] !== null ? (string) (int) $r['variation_id'] : 'null';
            $soldByKey[$key] = (int) ($r['sold'] ?? 0);
        }

        $heldRows = $this->db->fetchAllAssociative(
            'SELECT ci.variation_id, COALESCE(SUM(ci.quantity), 0) AS held
             FROM carts c
             JOIN cart_items ci ON ci.cart_id = c.id
             WHERE ci.event_id = ?
               AND c.reserved_until > CURRENT_TIMESTAMP
               AND c.expires_at > CURRENT_TIMESTAMP
               AND (? = 0 OR c.id <> ?)
             GROUP BY ci.variation_id',
            [$eventId, $cartId, $cartId]
        );
        $heldByKey = [];
        foreach ($heldRows as $r) {
            $key = array_key_exists('variation_id', $r) && $r['variation_id'] !== null ? (string) (int) $r['variation_id'] : 'null';
            $heldByKey[$key] = (int) ($r['held'] ?? 0);
        }

        $out = [];

        if (count($variations) === 0) {
            $event = $this->events->findById($eventId);
            if (!$event) {
                return [];
            }
            $limit = (int) ($event['stock_limit'] ?? 0);
            if ($limit <= 0) {
                $out['base'] = null;
                return $out;
            }
            $sold = (int) ($soldByKey['null'] ?? 0);
            $held = (int) ($heldByKey['null'] ?? 0);
            $out['base'] = max(0, $limit - $sold - $held);
            return $out;
        }

        foreach ($variations as $v) {
            $vid = (int) ($v['id'] ?? 0);
            if ($vid <= 0) continue;
            $limit = (int) ($v['stock_limit'] ?? 0);
            if ($limit <= 0) {
                $out[(string) $vid] = null;
                continue;
            }
            $sold = (int) ($soldByKey[(string) $vid] ?? 0);
            $held = (int) ($heldByKey[(string) $vid] ?? 0);
            $out[(string) $vid] = max(0, $limit - $sold - $held);
        }

        return $out;
    }

    /**
     * Availability for add-ons on the public product page.
     *
     * Returns a map of addon_id => available (int|null). Availability excludes the current cart.
     * stock_limit of 0/NULL means unlimited (null available).
     */
    public function getAddonAvailabilityForEvent(int $eventId, ?int $cartId): array
    {
        $eventId = (int) $eventId;
        $cartId = $cartId !== null ? (int) $cartId : 0;
        if ($eventId <= 0) {
            return [];
        }

        $addons = $this->addons->listByEventId($eventId);
        if (count($addons) === 0) {
            return [];
        }

        $soldRows = $this->db->fetchAllAssociative(
            "SELECT pia.addon_id, COALESCE(SUM(pia.quantity), 0) AS sold
             FROM purchase_ticket_addons pia
             JOIN purchase_tickets pt ON pt.id = pia.purchase_ticket_id AND pt.refunded_at IS NULL
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             WHERE pt.event_id = ?
             GROUP BY pia.addon_id",
            [$eventId]
        );
        $soldById = [];
        foreach ($soldRows as $r) {
            $aid = (int) ($r['addon_id'] ?? 0);
            if ($aid > 0) {
                $soldById[$aid] = (int) ($r['sold'] ?? 0);
            }
        }

        $heldRows = $this->db->fetchAllAssociative(
            'SELECT cia.addon_id, COALESCE(SUM(cia.quantity), 0) AS held
             FROM carts c
             JOIN cart_items ci ON ci.cart_id = c.id
             JOIN cart_item_addons cia ON cia.cart_item_id = ci.id
             WHERE ci.event_id = ?
               AND c.reserved_until > CURRENT_TIMESTAMP
               AND c.expires_at > CURRENT_TIMESTAMP
               AND (? = 0 OR c.id <> ?)
             GROUP BY cia.addon_id',
            [$eventId, $cartId, $cartId]
        );
        $heldById = [];
        foreach ($heldRows as $r) {
            $aid = (int) ($r['addon_id'] ?? 0);
            if ($aid > 0) {
                $heldById[$aid] = (int) ($r['held'] ?? 0);
            }
        }

        $out = [];
        foreach ($addons as $a) {
            $aid = (int) ($a['id'] ?? 0);
            if ($aid <= 0) continue;
            $limit = (int) ($a['stock_limit'] ?? 0);
            if ($limit <= 0) {
                $out[(string) $aid] = null;
                continue;
            }
            $sold = (int) ($soldById[$aid] ?? 0);
            $held = (int) ($heldById[$aid] ?? 0);
            $out[(string) $aid] = max(0, $limit - $sold - $held);
        }

        return $out;
    }

    private function ticketStockLimit(int $eventId, ?int $variationId): int
    {
        if ($variationId !== null) {
            $variation = $this->variations->findById($variationId);
            if ($variation && (int) ($variation['event_id'] ?? 0) === $eventId) {
                // Variations supersede the base event stock limit. A 0/NULL limit means unlimited.
                return (int) ($variation['stock_limit'] ?? 0);
            }
        }

        $event = $this->events->findById($eventId);
        if (!$event) {
            return 0;
        }

        return (int) ($event['stock_limit'] ?? 0);
    }
}
