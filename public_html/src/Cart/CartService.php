<?php

declare(strict_types=1);

namespace App\Cart;

use App\Repositories\AddonProductRepository;
use App\Repositories\CartItemAddonRepository;
use App\Repositories\CartItemModifierRepository;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\EventModifierRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventVariationRepository;
use DateTimeImmutable;

final class CartService
{
    public function __construct(
        private CartRepository $carts,
        private CartItemRepository $items,
        private CartItemModifierRepository $itemModifiers,
        private CartItemAddonRepository $itemAddons,
        private EventRepository $events,
        private EventVariationRepository $variations,
        private EventModifierRepository $modifiers,
        private AddonProductRepository $addons,
    ) {
    }

    public function getOrCreateCartId(string $sessionId): int
    {
        $cart = $this->carts->findBySessionId($sessionId);
        if ($cart) {
            return (int) $cart['id'];
        }

        return $this->carts->create($sessionId);
    }

    public function refreshCartForSession(string $sessionId): void
    {
        $cart = $this->carts->findBySessionId($sessionId);
        if (!$cart) {
            return;
        }

        $expiresAt = new DateTimeImmutable((string) $cart['expires_at']);
        if ($expiresAt <= new DateTimeImmutable()) {
            $this->carts->delete((int) $cart['id']);
            return;
        }

        $this->carts->touch((int) $cart['id']);
    }

    public function addEventToCart(string $sessionId, int $eventId, ?int $variationId, int $quantity, array $modifierValues, array $addonQty): void
    {
        $quantity = max(1, min(20, $quantity));

        $event = $this->events->findById($eventId);
        if (!$event) {
            throw new \RuntimeException('Event not found.');
        }

        // Price snapshot for cart line.
        $unitPriceCents = 0;
        if ($variationId !== null) {
            $variation = $this->variations->findById($variationId);
            if (!$variation || (int) $variation['event_id'] !== $eventId) {
                throw new \RuntimeException('Invalid variation.');
            }
            $unitPriceCents = (int) $variation['price_cents'];
        } else {
            $unitPriceCents = (int) ($event['price_cents'] ?? 0);
        }

        $cartId = $this->getOrCreateCartId($sessionId);

        // If the user specified any modifiers/add-ons, we treat it as a distinct line item.
        // Otherwise, merge identical event+variation lines to reduce clutter.
        $eventModifiers = $this->modifiers->listByEventId($eventId);
        $allowedModifierIds = array_map(static fn(array $m) => (int) $m['id'], $eventModifiers);
        $filteredModifiers = [];
        foreach ($modifierValues as $modifierId => $value) {
            $modifierId = (int) $modifierId;
            if (in_array($modifierId, $allowedModifierIds, true)) {
                $filteredModifiers[$modifierId] = $value;
            }
        }

        $eventAddons = $this->addons->listByEventId($eventId);
        $allowedAddonIds = array_map(static fn(array $a) => (int) $a['id'], $eventAddons);
        $qtyByAddonId = [];
        $unitPriceByAddonId = [];
        foreach ($eventAddons as $addon) {
            $addonId = (int) $addon['id'];
            $unitPriceByAddonId[$addonId] = (int) ($addon['price_cents'] ?? 0);
        }
        foreach ($addonQty as $addonId => $qty) {
            $addonId = (int) $addonId;
            if (!in_array($addonId, $allowedAddonIds, true)) {
                continue;
            }
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $qtyByAddonId[$addonId] = $qty;
        }

        $shouldMerge = (count($filteredModifiers) === 0) && (count($qtyByAddonId) === 0);
        $itemId = null;

        if ($shouldMerge) {
            $existing = $this->items->findLine($cartId, $eventId, $variationId);
            if ($existing) {
                $itemId = (int) $existing['id'];
                $this->items->setQuantity($itemId, (int) $existing['quantity'] + $quantity);
            }
        }

        if ($itemId === null) {
            $itemId = $this->items->create($cartId, $eventId, $variationId, $quantity, $unitPriceCents);
        }

        $this->itemModifiers->replaceForCartItem($itemId, $filteredModifiers);
        $this->itemAddons->replaceForCartItem($itemId, $qtyByAddonId, $unitPriceByAddonId);

        $this->carts->touch($cartId);
    }

    public function removeCartItem(string $sessionId, int $cartItemId): void
    {
        $cart = $this->carts->findBySessionId($sessionId);
        if (!$cart) {
            return;
        }

        $item = $this->items->findById($cartItemId);
        if (!$item || (int) $item['cart_id'] !== (int) $cart['id']) {
            return;
        }

        $this->items->delete($cartItemId);
        $this->carts->touch((int) $cart['id']);
    }

    public function updateCartItemQuantity(string $sessionId, int $cartItemId, int $quantity): void
    {
        $cart = $this->carts->findBySessionId($sessionId);
        if (!$cart) {
            return;
        }

        $item = $this->items->findById($cartItemId);
        if (!$item || (int) $item['cart_id'] !== (int) $cart['id']) {
            return;
        }

        $quantity = max(1, min(20, $quantity));
        $this->items->setQuantity($cartItemId, $quantity);
        $this->carts->touch((int) $cart['id']);
    }
}
