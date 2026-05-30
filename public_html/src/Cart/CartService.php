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
use App\Stock\StockService;
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
        private StockService $stock,
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

        $modsById = [];
        foreach ($eventModifiers as $m) {
            $mid = (int) ($m['id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }

            $opts = [];
            if (is_string($m['options_json'] ?? null) && trim((string) $m['options_json']) !== '') {
                $decoded = json_decode((string) $m['options_json'], true);
                if (is_array($decoded)) {
                    $opts = array_values(array_filter(array_map('strval', $decoded), static fn (string $v): bool => trim($v) !== ''));
                }
            }

            $m['options'] = $opts;
            $modsById[$mid] = $m;
        }

        $filteredModifiers = [];
        foreach ($modsById as $modifierId => $m) {
            $type = (string) ($m['modifier_type'] ?? 'text');
            $isRequired = !empty($m['is_required']);
            $raw = $modifierValues[$modifierId] ?? null;

            if ($type === 'checkbox') {
                $present = array_key_exists($modifierId, $modifierValues);
                if ($isRequired && !$present) {
                    throw new \RuntimeException('Missing required field: ' . (string) ($m['name'] ?? ''));
                }
                if ($present) {
                    $filteredModifiers[$modifierId] = '1';
                }
                continue;
            }

            if ($type === 'select') {
                $val = trim((string) ($raw ?? ''));
                if ($isRequired && $val === '') {
                    throw new \RuntimeException('Missing required field: ' . (string) ($m['name'] ?? ''));
                }
                if ($val === '') {
                    continue;
                }

                $options = (array) ($m['options'] ?? []);
                if (!in_array($val, $options, true)) {
                    throw new \RuntimeException('Invalid selection for: ' . (string) ($m['name'] ?? ''));
                }

                $filteredModifiers[$modifierId] = $val;
                continue;
            }

            if ($type === 'multiselect') {
                $vals = [];
                if ($raw !== null) {
                    if (is_array($raw)) {
                        $vals = $raw;
                    } else {
                        $vals = [$raw];
                    }
                }

                $vals = array_map(static fn ($v): string => trim((string) $v), $vals);
                $vals = array_values(array_filter($vals, static fn (string $v): bool => $v !== ''));

                $options = (array) ($m['options'] ?? []);
                if (count($options) > 0) {
                    $vals = array_values(array_filter($vals, static fn (string $v): bool => in_array($v, $options, true)));
                }

                $vals = array_values(array_unique($vals));

                $minSelected = null;
                if (array_key_exists('min_selected', $m) && $m['min_selected'] !== null && (string) $m['min_selected'] !== '') {
                    $minSelected = max(0, (int) $m['min_selected']);
                }

                $maxSelected = null;
                if (array_key_exists('max_selected', $m) && $m['max_selected'] !== null && (string) $m['max_selected'] !== '') {
                    $maxSelected = (int) $m['max_selected'];
                }

                if ($isRequired && ($minSelected === null || $minSelected < 1)) {
                    $minSelected = 1;
                }

                if ($maxSelected !== null && $minSelected !== null && $maxSelected < $minSelected) {
                    throw new \RuntimeException('Invalid selection limits for: ' . (string) ($m['name'] ?? ''));
                }

                if ($minSelected !== null && count($vals) < $minSelected) {
                    throw new \RuntimeException('Select at least ' . $minSelected . ' option(s) for: ' . (string) ($m['name'] ?? ''));
                }

                if ($maxSelected !== null && $maxSelected > 0 && count($vals) > $maxSelected) {
                    throw new \RuntimeException('Too many selections for: ' . (string) ($m['name'] ?? ''));
                }

                if ($isRequired && count($vals) == 0) {
                    throw new \RuntimeException('Missing required field: ' . (string) ($m['name'] ?? ''));
                }

                if (count($vals) > 0) {
                    $filteredModifiers[$modifierId] = json_encode($vals, JSON_UNESCAPED_SLASHES);
                }

                continue;
            }

            // Default: free text.
            $val = trim((string) ($raw ?? ''));
            if ($isRequired && $val === '') {
                throw new \RuntimeException('Missing required field: ' . (string) ($m['name'] ?? ''));
            }
            if ($val !== '') {
                $filteredModifiers[$modifierId] = $val;
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
                $newQty = (int) $existing['quantity'] + $quantity;
                $this->stock->assertCanReserveTickets($cartId, $eventId, $variationId, $newQty, $itemId);
                $this->items->setQuantity($itemId, $newQty);
            }
        }

        if ($itemId === null) {
            // New cart line: enforce ticket stock across all lines in this cart for this event/variation.
            $this->stock->assertCanReserveTickets($cartId, $eventId, $variationId, $quantity, null);
            $this->stock->assertCanReserveAddons($cartId, $qtyByAddonId, null);
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
        $this->stock->assertCanReserveTickets((int) $cart['id'], (int) $item['event_id'], $item['variation_id'] !== null ? (int) $item['variation_id'] : null, $quantity, $cartItemId);
        $this->items->setQuantity($cartItemId, $quantity);
        $this->carts->touch((int) $cart['id']);
    }
}
