<?php

declare(strict_types=1);

namespace App\Public;

use App\Http\RequestUtil;

use App\Cart\CartService;
use App\Payments\SquareCheckoutClient;
use App\Mail\EmailRenderer;
use App\Mail\Mailer;
use App\Repositories\AddonProductRepository;
use App\Repositories\CartItemAddonRepository;
use App\Repositories\CartItemModifierRepository;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\EventModifierRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventVariationRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseTicketAddonRepository;
use App\Repositories\PurchaseTicketModifierRepository;
use App\Repositories\PurchaseTicketRepository;
use App\Stock\StockService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

final class PublicRoutes
{
    public static function register(
        App $app,
        Twig $twig,
        array $config,
        EventRepository $events,
        EventVariationRepository $variations,
        EventModifierRepository $modifiers,
        AddonProductRepository $addons,
        CartRepository $carts,
        CartItemRepository $cartItems,
        CartItemAddonRepository $cartItemAddons,
        CartItemModifierRepository $cartItemModifiers,
        PurchaseRepository $purchases,
        PurchaseTicketRepository $purchaseTickets,
        PurchaseTicketModifierRepository $purchaseTicketModifiers,
        PurchaseTicketAddonRepository $purchaseTicketAddons,
        SquareCheckoutClient $square,
        Mailer $mailer,
        EmailRenderer $emailRenderer,
        CartService $cartService,
        StockService $stock,
    ): void {
        $loadCart = function () use ($carts, $cartItems, $cartItemAddons, $cartItemModifiers): array {
            $cart = $carts->findBySessionId(hash('sha256', session_id()));
            $items = [];
            $subtotalCents = 0;

            if ($cart) {
                $items = $cartItems->listByCartId((int) $cart['id']);

                $addonRows = $cartItemAddons->listByCartId((int) $cart['id']);
                $addonsByItemId = [];
                foreach ($addonRows as $ar) {
                    $itemId = (int) $ar['cart_item_id'];
                    $addonsByItemId[$itemId] ??= [];
                    $addonsByItemId[$itemId][] = $ar;
                }

                $modifierRows = $cartItemModifiers->listByCartId((int) $cart['id']);
                $modifiersByItemId = [];
                foreach ($modifierRows as $mr) {
                    $itemId = (int) $mr['cart_item_id'];
                    $modifiersByItemId[$itemId] ??= [];
                    $modifiersByItemId[$itemId][] = $mr;
                }

                foreach ($items as &$row) {
                    $itemId = (int) $row['id'];
                    $rowAddons = $addonsByItemId[$itemId] ?? [];
                    $rowModifiers = $modifiersByItemId[$itemId] ?? [];
                    foreach ($rowModifiers as &$mr) {
                        $type = (string) ($mr['modifier_type'] ?? '');
                        $raw = $mr['value'] ?? null;
                        $mr['value_display'] = $raw;
                        if ($type === 'multiselect' && is_string($raw) && trim($raw) !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $vals = array_values(array_filter(array_map('strval', $decoded), static fn (string $v): bool => trim($v) !== ''));
                                $mr['value_display'] = implode(', ', $vals);
                            }
                        }
                    }
                    unset($mr);
                    $addonTotal = 0;
                    foreach ($rowAddons as $a) {
                        $addonTotal += ((int) ($a['quantity'] ?? 0)) * ((int) ($a['unit_price_cents'] ?? 0));
                    }

                    $baseTotal = ((int) $row['quantity']) * ((int) $row['unit_price_cents']);
                    $row['addons'] = $rowAddons;
                    $row['modifiers'] = $rowModifiers;
                    $row['addon_total_cents'] = $addonTotal;
                    $row['line_total_cents'] = $baseTotal + $addonTotal;
                    $subtotalCents += (int) $row['line_total_cents'];
                }
                unset($row);
            }

            return [$cart, $items, $subtotalCents];
        };

        $app->get('/', function (Request $request, Response $response) use ($config, $events) {
            $list = $events->listPublicIndex();
            $clientIp = RequestUtil::clientIp($config, $request);
            return Twig::fromRequest($request)->render($response, 'public/events.twig', [
                'app' => $config,
                'events' => $list,
                'client_ip' => $clientIp,
            ]);
        });

        $app->get('/events/{slug}', function (Request $request, Response $response, array $args) use ($config, $events, $variations, $modifiers, $addons, $carts, $stock) {
            $slug = (string) ($args['slug'] ?? '');
            $event = $events->findPublicBySlug($slug);
            if (!$event) {
                return $response->withStatus(404);
            }

            $eventId = (int) $event['id'];
            $cart = $carts->findBySessionId(hash('sha256', session_id()));
            $cartId = $cart ? (int) ($cart['id'] ?? 0) : 0;

            $modifierList = $modifiers->listByEventId($eventId);
            foreach ($modifierList as &$m) {
                $m['options'] = [];
                if (is_string($m['options_json'] ?? null) && trim((string) $m['options_json']) !== '') {
                    $decoded = json_decode((string) $m['options_json'], true);
                    if (is_array($decoded)) {
                        $m['options'] = array_values(array_filter(array_map('strval', $decoded), static fn (string $v): bool => trim($v) !== ''));
                    }
                }
                if (array_key_exists('min_selected', $m) && $m['min_selected'] !== null && (string) $m['min_selected'] !== '') {
                    $m['min_selected'] = max(0, (int) $m['min_selected']);
                } else {
                    $m['min_selected'] = null;
                }

                if (array_key_exists('max_selected', $m) && $m['max_selected'] !== null && (string) $m['max_selected'] !== '') {
                    $m['max_selected'] = (int) $m['max_selected'];
                } else {
                    $m['max_selected'] = null;
                }
            }
            unset($m);

            return Twig::fromRequest($request)->render($response, 'public/event-detail.twig', [
                'app' => $config,
                'event' => $event,
                'variations' => $variations->listByEventId($eventId),
                'modifiers' => $modifierList,
                'addons' => $addons->listByEventId($eventId),
                'ticket_availability' => $stock->getTicketAvailabilityForEvent($eventId, $cartId),
                'addon_availability' => $stock->getAddonAvailabilityForEvent($eventId, $cartId),
            ]);
        });

        $app->get('/cart', function (Request $request, Response $response) use ($config, $loadCart) {
            [$cart, $items, $subtotalCents] = $loadCart();
            $cartError = (string) ($_SESSION['cart_error'] ?? '');
            unset($_SESSION['cart_error']);
            return Twig::fromRequest($request)->render($response, 'public/cart.twig', [
                'app' => $config,
                'items' => $items,
                'subtotal_cents' => $subtotalCents,
                'cart_error' => $cartError,
            ]);
        });

        $app->get('/checkout', function (Request $request, Response $response) use ($config, $loadCart) {
            [, $items, $subtotalCents] = $loadCart();
            $email = (string) ($_SESSION['checkout_email'] ?? '');

            return Twig::fromRequest($request)->render($response, 'public/checkout.twig', [
                'app' => $config,
                'items' => $items,
                'subtotal_cents' => $subtotalCents,
                'email' => $email,
                'error' => '',
            ]);
        });

        $app->post('/checkout/start', function (Request $request, Response $response) use ($config, $loadCart, $square, $purchases, $stock) {
            [$cart, $items, $subtotalCents] = $loadCart();
            if (count($items) === 0) {
                return $response->withHeader('Location', '/cart')->withStatus(302);
            }

            // Stock guard: don't start checkout if we can't fulfill the cart.
            if ($cart && isset($cart['id'])) {
                try {
                    $stock->assertCheckoutItemsHaveStock((int) $cart['id'], $items);
                } catch (\RuntimeException $e) {
                    $_SESSION['cart_error'] = $e->getMessage();
                    return $response->withHeader('Location', '/cart')->withStatus(302);
                }
            }

            $data = (array) $request->getParsedBody();
            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Twig::fromRequest($request)->render($response, 'public/checkout.twig', [
                    'app' => $config,
                    'items' => $items,
                    'subtotal_cents' => $subtotalCents,
                    'email' => $email,
                    'error' => 'Please enter a valid email address.',
                ]);
            }

            $_SESSION['checkout_email'] = $email;
            $_SESSION['checkout_expected_total'] = (int) $subtotalCents;

            $squareCfg = (array) ($config['square'] ?? []);
            $locationId = (string) ($squareCfg['location_id'] ?? '');
            if ($locationId === '') {
                return Twig::fromRequest($request)->render($response, 'public/checkout.twig', [
                    'app' => $config,
                    'items' => $items,
                    'subtotal_cents' => $subtotalCents,
                    'email' => $email,
                    'error' => 'Square is not configured (missing location id).',
                ]);
            }

            $uri = $request->getUri();
            $origin = rtrim($uri->getScheme() . '://' . $uri->getAuthority(), '/');
            $redirectUrl = $origin . '/checkout/complete';

            $currency = (string) ($config['store_currency'] ?? 'CAD');
            $sessionHash = hash('sha256', session_id());

            // Create a pending purchase record *before* redirecting to Square, so we always use our collected email
            // even if Square doesn't return it or the session is lost.
            // Note: we fill provider_reference after payment verification.
            $purchaseId = $purchases->create([
                'email' => $email,
                'total_cents' => (int) $subtotalCents,
                'currency' => $currency,
                'payment_status' => 'pending',
                'payment_provider' => 'square',
                'provider_reference' => null,
            ]);
            $_SESSION['pending_purchase_id'] = $purchaseId;

            // Build Square order line items so receipts/dashboard are itemized.
            $lineItems = [];
            foreach ($items as $row) {
                $qty = (int) ($row['quantity'] ?? 1);
                $qty = max(1, min(20, $qty));

                $modifierSummaryParts = [];
                foreach ((array) ($row['modifiers'] ?? []) as $m) {
                    $namePart = (string) ($m['modifier_name'] ?? '');
                    if ($namePart === '') {
                        continue;
                    }
                    if (($m['modifier_type'] ?? '') === 'checkbox') {
                        $modifierSummaryParts[] = $namePart;
                    } else {
                        $val = trim((string) ($m['value'] ?? ''));
                        $modifierSummaryParts[] = $val !== '' ? ($namePart . ': ' . $val) : $namePart;
                    }
                }
                $modifierSummary = implode('; ', $modifierSummaryParts);

                $ticketName = (string) ($row['event_name'] ?? 'Ticket');
                $variation = trim((string) ($row['variation_name'] ?? ''));
                if ($variation !== '') {
                    $ticketName .= ' — ' . $variation;
                }

                $ticketLine = [
                    'name' => $ticketName,
                    'quantity' => (string) $qty,
                    'base_price_money' => [
                        'amount' => (int) ($row['unit_price_cents'] ?? 0),
                        'currency' => $currency,
                    ],
                ];
                if ($modifierSummary !== '') {
                    $ticketLine['note'] = $modifierSummary;
                }
                $lineItems[] = $ticketLine;

                foreach ((array) ($row['addons'] ?? []) as $a) {
                    $aQty = (int) ($a['quantity'] ?? 0);
                    if ($aQty <= 0) {
                        continue;
                    }
                    $lineItems[] = [
                        'name' => 'Add-on: ' . (string) ($a['addon_name'] ?? 'Add-on'),
                        'quantity' => (string) $aQty,
                        'base_price_money' => [
                            'amount' => (int) ($a['unit_price_cents'] ?? 0),
                            'currency' => $currency,
                        ],
                    ];
                }
            }

            $_SESSION['checkout_session_hash'] = $sessionHash;
            $_SESSION['square_payment_note'] = '';

            try {
                $resp = $square->createOrderPaymentLink(
                    locationId: $locationId,
                    lineItems: $lineItems,
                    referenceId: (string) $purchaseId,
                    redirectUrl: $redirectUrl,
                    buyerEmail: $email,
                    paymentNote: null,
                );
            } catch (\Throwable $e) {
                return Twig::fromRequest($request)->render($response, 'public/checkout.twig', [
                    'app' => $config,
                    'items' => $items,
                    'subtotal_cents' => $subtotalCents,
                    'email' => $email,
                    'error' => 'Failed to start Square checkout: ' . $e->getMessage(),
                ]);
            }

            $url = (string) (($resp['payment_link']['long_url'] ?? '') ?: ($resp['payment_link']['url'] ?? ''));
            if ($url === '') {
                throw new \RuntimeException('Square did not return a payment link URL.');
            }

            // Store for sanity checks when Square redirects back.
            $_SESSION['square_payment_link_id'] = (string) ($resp['payment_link']['id'] ?? '');
            $_SESSION['square_order_id'] = (string) ($resp['payment_link']['order_id'] ?? '');

            return $response->withHeader('Location', $url)->withStatus(302);
        });

        $app->get('/checkout/complete', function (Request $request, Response $response) use ($config, $loadCart, $square, $purchases, $purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $carts, $mailer, $emailRenderer) {
            $params = $request->getQueryParams();
            $paymentId = (string) ($params['transactionId'] ?? ($params['transaction_id'] ?? ($params['paymentId'] ?? ($params['payment_id'] ?? ''))));
            $orderId = (string) ($params['orderId'] ?? '');

            $expected = (int) ($_SESSION['checkout_expected_total'] ?? 0);
            $email = (string) ($_SESSION['checkout_email'] ?? '');
            $sessionOrderId = (string) ($_SESSION['square_order_id'] ?? '');
            $sessionEmail = (string) ($_SESSION['checkout_email'] ?? '');
            $sessionHash = (string) ($_SESSION['checkout_session_hash'] ?? '');
            $sessionNote = (string) ($_SESSION['square_payment_note'] ?? '');

            // Sandbox limitation: Square may not append params to redirect_url for payment links.
            // If paymentId isn't present, attempt to locate the payment by:
            // - order_id + amount (preferred when we have order_id)
            // - otherwise buyer email + amount (best-effort)
            if ($paymentId === '') {
                $squareCfg = (array) ($config['square'] ?? []);
                $locationId = (string) ($squareCfg['location_id'] ?? '');

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $begin = $now->sub(new \DateInterval('PT2H'))->format(DATE_RFC3339);
                $end = $now->add(new \DateInterval('PT10M'))->format(DATE_RFC3339);

                try {
                    $list = $square->listPayments([
                        'location_id' => $locationId !== '' ? $locationId : null,
                        'begin_time' => $begin,
                        'end_time' => $end,
                        'sort_order' => 'DESC',
                        'limit' => 100,
                    ]);
                } catch (\Throwable $e) {
                    return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                        'app' => $config,
                        'ok' => false,
                        'message' => 'Missing transaction id from Square redirect, and lookup failed: ' . $e->getMessage(),
                    ]);
                }

                $candidates = (array) ($list['payments'] ?? []);
                foreach ($candidates as $p) {
                    $p = (array) $p;
                    if ((string) ($p['status'] ?? '') !== 'COMPLETED') {
                        continue;
                    }
                    $amt = (int) ($p['total_money']['amount'] ?? 0);
                    if ($expected > 0 && $amt !== $expected) {
                        continue;
                    }

                    $pOrderId = (string) ($p['order_id'] ?? '');
                    $pEmail = (string) ($p['buyer_email_address'] ?? '');
                    $pNote = (string) ($p['note'] ?? '');

                    // Prefer exact order_id match if we have it.
                    if ($sessionOrderId !== '' && $pOrderId !== $sessionOrderId) {
                        continue;
                    }

                    // If we don't have order_id, try to match the buyer email when available.
                    if ($sessionOrderId === '' && $sessionEmail !== '' && $pEmail !== '' && strcasecmp($pEmail, $sessionEmail) !== 0) {
                        continue;
                    }

                    // If Square includes a payment note (we try not to), and we have one, require it to match.
                    if ($sessionNote !== '' && $pNote !== '' && $pNote !== $sessionNote) {
                        continue;
                    }

                    $paymentId = (string) ($p['id'] ?? '');
                    if ($paymentId === '') {
                        continue;
                    }
                    $orderId = $pOrderId !== '' ? $pOrderId : $orderId;
                    break;
                }
            }

            if ($paymentId === '') {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => false,
                    'message' => 'Missing transaction id from Square redirect. If you used the sandbox testing panel, try starting checkout again in the same browser session (cookies must be enabled).',
                ]);
            }

            try {
                $paymentResp = $square->getPayment($paymentId);
            } catch (\Throwable $e) {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => false,
                    'message' => 'Failed to verify payment with Square: ' . $e->getMessage(),
                ]);
            }
            $payment = (array) ($paymentResp['payment'] ?? []);
            $status = (string) ($payment['status'] ?? '');
            $paidAmount = (int) ($payment['total_money']['amount'] ?? 0);
            $paidOrderId = (string) ($payment['order_id'] ?? '');
            $paidNote = (string) ($payment['note'] ?? '');

            if ($status !== 'COMPLETED') {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => false,
                    'message' => 'Payment is not completed (status: ' . $status . ').',
                ]);
            }

            if ($expected > 0 && $paidAmount !== $expected) {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => false,
                    'message' => 'Payment total did not match the expected cart total.',
                ]);
            }

            if ($orderId !== '' && $paidOrderId !== '' && $orderId !== $paidOrderId) {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => false,
                    'message' => 'Order id mismatch.',
                ]);
            }

            // If we've already finalized this Square payment, don't duplicate purchases.
            $existingPurchase = $purchases->findByProviderReference($paymentId);
            if ($existingPurchase) {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => true,
                    'message' => 'Payment completed. Thanks!',
                    'purchase_id' => (int) $existingPurchase['id'],
                    'receipt_email' => (string) ($existingPurchase['email'] ?? ''),
                ]);
            }

            [$cart, $items, ] = $loadCart();
            if (!$cart || count($items) === 0) {
                return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                    'app' => $config,
                    'ok' => true,
                    'message' => 'Payment completed. Cart already cleared.',
                ]);
            }

            // Prefer the email we collected at checkout (stored in the pending purchase record).
            $purchaseId = (int) ($_SESSION['pending_purchase_id'] ?? 0);
            if ($purchaseId <= 0 && $paidOrderId !== '') {
                try {
                    $orderResp = $square->getOrder($paidOrderId);
                    $order = (array) ($orderResp['order'] ?? []);
                    $ref = (string) ($order['reference_id'] ?? '');
                    if ($ref !== '' && ctype_digit($ref)) {
                        $purchaseId = (int) $ref;
                    }
                } catch (\Throwable) {
                    // If order lookup fails, we'll fall back to creating a paid record below.
                }
            }

            $purchase = $purchaseId > 0 ? $purchases->findById($purchaseId) : null;
            if (!$purchase) {
                // Session loss should be rare since we embed purchase_id in the Square payment note.
                // Still, do not block fulfillment: create a paid record (email intentionally blank).
                $purchaseId = $purchases->create([
                    'email' => '',
                    'total_cents' => $paidAmount,
                    'currency' => (string) ($config['store_currency'] ?? 'CAD'),
                    'payment_status' => 'paid',
                    'payment_provider' => 'square',
                    'provider_reference' => $paymentId,
                ]);
                $purchase = $purchases->findById($purchaseId);
            } else {
                $purchases->update($purchaseId, [
                    'total_cents' => $paidAmount,
                    'currency' => (string) ($config['store_currency'] ?? 'CAD'),
                    'payment_status' => 'paid',
                    'payment_provider' => 'square',
                    'provider_reference' => $paymentId,
                ]);
            }

            $ticketsForEmail = [];
            $embeddedImages = [];
            foreach ($items as $row) {
                $qty = (int) ($row['quantity'] ?? 1);
                $qty = max(1, min(20, $qty));

                for ($i = 0; $i < $qty; $i++) {
                    $qrToken = hash('sha256', random_bytes(32));
                    $ticketId = $purchaseTickets->create(
                        $purchaseId,
                        (int) $row['event_id'],
                        ($row['variation_id'] ?? null) !== null ? (int) $row['variation_id'] : null,
                        (int) $row['unit_price_cents'],
                        $qrToken,
                    );

                    foreach ((array) ($row['modifiers'] ?? []) as $m) {
                        $purchaseTicketModifiers->create(
                            $ticketId,
                            (int) ($m['modifier_id'] ?? 0),
                            isset($m['value']) ? (string) $m['value'] : null,
                        );
                    }

                    foreach ((array) ($row['addons'] ?? []) as $a) {
                        $purchaseTicketAddons->create(
                            $ticketId,
                            (int) ($a['addon_id'] ?? 0),
                            (int) ($a['quantity'] ?? 0),
                            (int) ($a['unit_price_cents'] ?? 0),
                        );
                    }

                    // Embed a QR code for check-in (payload is the qr_token for now).
                    // Use PNG for best email client compatibility (requires php-gd).
                    $qr = new QrCode(data: $qrToken, size: 280, margin: 12);
                    $qrPng = (new PngWriter())->write($qr)->getString();
                    $cid = 'ticket-' . $ticketId . '@qrcode';
                    $embeddedImages[] = [
                        'cid' => $cid,
                        'data' => $qrPng,
                        'filename' => 'ticket-' . $ticketId . '.png',
                        'mime' => 'image/png',
                    ];

                    $ticketsForEmail[] = [
                        'purchase_ticket_id' => $ticketId,
                        'event_name' => (string) ($row['event_name'] ?? ''),
                        'variation_name' => (string) ($row['variation_name'] ?? ''),
                        'unit_price_cents' => (int) ($row['unit_price_cents'] ?? 0),
                        'modifiers' => (array) ($row['modifiers'] ?? []),
                        'addons' => (array) ($row['addons'] ?? []),
                        'qr_token' => $qrToken,
                        'qr_cid' => $cid,
                    ];
                }
            }

            $carts->delete((int) $cart['id']);
            unset(
                $_SESSION['checkout_expected_total'],
                $_SESSION['square_payment_link_id'],
                $_SESSION['square_order_id'],
                $_SESSION['pending_purchase_id']
            );

            // Send receipt + QR codes email (best-effort; never guess the email from Square).
            $toEmail = (string) ($purchase['email'] ?? '');
            $emailSent = false;
            if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $subject = (string) (($config['app_name'] ?? 'Simple Event Checkout') . ' — Order Confirmation');
                    $html = $emailRenderer->render('email/order-confirmation.twig', [
                        'app' => $config,
                        'purchase_id' => $purchaseId,
                        'total_cents' => $paidAmount,
                        'currency' => (string) ($config['store_currency'] ?? 'CAD'),
                        'tickets' => $ticketsForEmail,
                    ]);
                    $mailer->send($toEmail, $toEmail, $subject, $html, null, $embeddedImages);
                    $_SESSION['last_receipt_email'] = $toEmail;
                    $emailSent = true;
                    $purchases->markReceiptSent($purchaseId);
                } catch (\Throwable $e) {
                    // Ignore for MVP; user can still view purchase in admin.
                    error_log('Receipt email send failed: ' . $e->getMessage());
                    $purchases->markReceiptFailed($purchaseId, $e->getMessage());
                }
            } else {
                if ($toEmail !== '') {
                    error_log('Receipt email skipped due to invalid email on purchase #' . $purchaseId . ': ' . $toEmail);
                    $purchases->markReceiptFailed($purchaseId, 'Invalid email on purchase: ' . $toEmail);
                } else {
                    error_log('Receipt email skipped because purchase #' . $purchaseId . ' has no email.');
                    $purchases->markReceiptFailed($purchaseId, 'Missing email on purchase.');
                }
            }

            return Twig::fromRequest($request)->render($response, 'public/checkout-complete.twig', [
                'app' => $config,
                'ok' => true,
                'message' => 'Payment completed. Thanks!',
                'purchase_id' => $purchaseId,
                'receipt_email' => $toEmail,
                'email_sent' => $emailSent,
            ]);
        });

        $app->post('/cart/add', function (Request $request, Response $response) use ($cartService) {
            $data = (array) $request->getParsedBody();

            $eventId = (int) ($data['event_id'] ?? 0);
            $variationId = ($data['variation_id'] ?? '') !== '' ? (int) $data['variation_id'] : null;
            $qty = (int) ($data['quantity'] ?? 1);

            $modifierValues = (array) ($data['modifiers'] ?? []);
            $addonQty = (array) ($data['addons'] ?? []);

            $sessionKey = hash('sha256', session_id());
            try {
                $cartService->addEventToCart($sessionKey, $eventId, $variationId, $qty, $modifierValues, $addonQty);
            } catch (\RuntimeException $e) {
                $_SESSION['cart_error'] = $e->getMessage();
            }

            return $response->withHeader('Location', '/cart')->withStatus(302);
        });

        $app->post('/cart/item/{id}/qty', function (Request $request, Response $response, array $args) use ($cartService) {
            $id = (int) ($args['id'] ?? 0);
            $data = (array) $request->getParsedBody();
            $qty = (int) ($data['quantity'] ?? 1);

            $sessionKey = hash('sha256', session_id());
            try {
                $cartService->updateCartItemQuantity($sessionKey, $id, $qty);
            } catch (\RuntimeException $e) {
                $_SESSION['cart_error'] = $e->getMessage();
            }

            return $response->withHeader('Location', '/cart')->withStatus(302);
        });

        $app->post('/cart/item/{id}/remove', function (Request $request, Response $response, array $args) use ($cartService) {
            $id = (int) ($args['id'] ?? 0);
            $sessionKey = hash('sha256', session_id());
            $cartService->removeCartItem($sessionKey, $id);

            return $response->withHeader('Location', '/cart')->withStatus(302);
        });
    }
}
