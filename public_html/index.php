<?php

declare(strict_types=1);

use App\Admin\AdminRoutes;
use App\Auth\AdminAuth;
use App\Cart\CartService;
use App\Config;
use App\Database;
use App\Mail\EmailRenderer;
use App\Mail\Mailer;
use App\Payments\SquareCheckoutClient;
use App\Repositories\AdminRepository;
use App\Repositories\AdminSessionRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\AddonProductRepository;
use App\Repositories\CartItemAddonRepository;
use App\Repositories\CartItemModifierRepository;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventModifierRepository;
use App\Repositories\EventVariationRepository;
use App\Repositories\LoginLockoutRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseTicketAddonRepository;
use App\Repositories\PurchaseTicketModifierRepository;
use App\Repositories\PurchaseTicketRepository;
use App\Repositories\RateLimitRepository;
use App\Public\PublicRoutes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\Markup;
use Twig\TwigFilter;
use League\CommonMark\CommonMarkConverter;

require __DIR__ . '/../vendor/autoload.php';


define('ABS_PATH', dirname(__DIR__));

$config = Config::load();
$db = Database::connect($config['db']);

// Session cookie hardening (must run before session_start()).
$forceHttps = (bool) (($config['security']['force_https'] ?? false) ?: false);
$secure = $forceHttps || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

ini_set('session.use_strict_mode', '1');
session_start();

// CSRF token (session-scoped).
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$rateLimits = new RateLimitRepository($db);

$app = AppFactory::create();
$app->addRoutingMiddleware();

// Strip trailing slashes (prevents accidental 404s like /checkout/).
$app->add(function (Request $request, $handler) {
    $m = strtoupper($request->getMethod());
    if ($m === 'GET' || $m === 'HEAD') {
        $path = $request->getUri()->getPath();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
            $uri = $request->getUri()->withPath($path);
            return (new \Slim\Psr7\Response(302))->withHeader('Location', (string) $uri);
        }
    }

    return $handler->handle($request);
});

// Lightweight, IP-based rate limiting for a few high-value endpoints.
$app->add(function (Request $request, $handler) use ($rateLimits, $config) {
    $method = strtoupper($request->getMethod());
    $path = $request->getUri()->getPath();

    $rule = null;

    // Public checkout start is the most abusable (email + payment init).
    if ($method === 'POST' && $path === '/checkout/start') {
        $rule = ['key' => 'checkout_start', 'limit' => 10, 'window' => 300];
    }

    // Prevent spam refresh / probing.
    if ($method === 'GET' && $path === '/checkout/complete') {
        $rule = ['key' => 'checkout_complete', 'limit' => 60, 'window' => 60];
    }

    // Scanner AJAX can be hammered; keep generous limit.
    if ($method === 'POST' && $path === '/admin/checkin/ajax') {
        $rule = ['key' => 'admin_checkin_ajax', 'limit' => 120, 'window' => 60];
    }

    if ($rule) {
        $ip = \App\Http\RequestUtil::clientIp($config, $request);
        $hits = $rateLimits->hit($ip, $rule['key'], (int) $rule['window']);
        if ($hits > (int) $rule['limit']) {
            $response = new \Slim\Psr7\Response(429);
            $response->getBody()->write('429 Too Many Requests');
            $response = $response->withHeader('Retry-After', (string) $rule['window']);
            return $response;
        }
    }

    return $handler->handle($request);
});

$app->add(function (Request $request, $handler) use ($config) {
    $method = strtoupper($request->getMethod());
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return $handler->handle($request);
    }

    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    $token = $request->getHeaderLine('X-CSRF-Token');

    if ($token === '') {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $token = (string) ($body['csrf_token'] ?? '');
        }
    }

    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        $response = new \Slim\Psr7\Response(400);
        $response->getBody()->write('Bad Request');
        return $response;
    }

    return $handler->handle($request);
});


$app->addBodyParsingMiddleware();

$isDev = ($config['app_env'] ?? 'dev') === 'dev';
$errorMiddleware = $app->addErrorMiddleware($isDev, true, true);

// Ensure routing errors return the correct HTTP status code (even in dev).
$errorMiddleware->setErrorHandler(\Slim\Exception\HttpNotFoundException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails
) use ($isDev): Response {
    $response = new \Slim\Psr7\Response(404);
    if ($isDev) {
        $response->getBody()->write('<h1>404 Not Found</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    $response->getBody()->write('Not Found');
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
}, true);

$errorMiddleware->setErrorHandler(\Slim\Exception\HttpMethodNotAllowedException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails
) use ($isDev): Response {
    $response = new \Slim\Psr7\Response(405);
    if ($isDev) {
        $response->getBody()->write('<h1>405 Method Not Allowed</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    $response->getBody()->write('Method Not Allowed');
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
}, true);

if ($isDev && class_exists(\Whoops\Run::class)) {
    $whoops = new \Whoops\Run();
    $pretty = new \Whoops\Handler\PrettyPageHandler();
    // Reduce the chance of leaking secrets during local debugging.
    foreach ([
        'DB_PASS',
        'DB_PASSWORD',
        'SMTP_PASSWORD',
        'SMTP_USERNAME',
        'SMTP_HOST',
        'SQUARE_ACCESS_TOKEN',
        'SQUARE_APPLICATION_ID',
        'SQUARE_LOCATION_ID',
        'TURNSTILE_SECRET_KEY',
        'TURNSTILE_SITE_KEY',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'HTTP_COOKIE',
        'PHP_AUTH_PW',
    ] as $key) {
        $pretty->blacklist('_SERVER', $key);
        $pretty->blacklist('_ENV', $key);
    }

    $whoops->pushHandler($pretty);

    $errorMiddleware->setDefaultErrorHandler(function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails
    ) use ($whoops): Response {
        // Whoops expects to write to output; capture it and return as the Slim response body.
        ob_start();
        $whoops->handleException($exception);
        $html = (string) ob_get_clean();

        $status = 500;
        if ($exception instanceof \Slim\Exception\HttpException) {
            $code = (int) $exception->getCode();
            if ($code >= 400 && $code <= 599) {
                $status = $code;
            }
        }

        $response = new \Slim\Psr7\Response($status);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
}

$twig = Twig::create(ABS_PATH . '/public_html/templates', [
    'cache' => false,
]);
$twig->getEnvironment()->addGlobal('csrf_token', (string) ($_SESSION['csrf_token'] ?? ''));
$twig->getEnvironment()->addFilter(new TwigFilter('format_dt', function (mixed $value, string $format = 'F j, Y') use ($config): string {
    if (!is_string($value) || trim($value) === '') {
        return '';
    }

    try {
        $tz = $config['app_timezone'] ?? date_default_timezone_get();
        $dt = new DateTimeImmutable($value, new DateTimeZone((string) $tz));
        return $dt->format($format);
    } catch (Throwable) {
        return (string) $value;
    }
}));

// Formats a UTC timestamp string into app timezone for display (useful for DB timestamps).
$twig->getEnvironment()->addFilter(new TwigFilter('format_dt_utc', function (mixed $value, string $format = 'Y-m-d H:i') use ($config): string {
    if (!is_string($value) || trim($value) === '') {
        return '';
    }

    try {
        $tz = new DateTimeZone((string) ($config['app_timezone'] ?? date_default_timezone_get()));
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone($tz)->format($format);
    } catch (Throwable) {
        return (string) $value;
    }
}));

// Money formatting helper (cents -> "$0.00"). Assumes a single global currency for MVP.
$twig->getEnvironment()->addFilter(new TwigFilter('format_money', function (mixed $cents): string {
    if ($cents === null || $cents === '') {
        return '';
    }

    if (!is_int($cents) && !is_numeric($cents)) {
        return (string) $cents;
    }

    $amount = ((int) $cents) / 100;
    return '$' . number_format($amount, 2, '.', ',');
}));

// Server-side Markdown rendering for event descriptions, etc.
// Keep it conservative: strip raw HTML and disallow unsafe links.
$md = new CommonMarkConverter([
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
]);
$twig->getEnvironment()->addFilter(new TwigFilter('format_md', function (mixed $value) use ($md): Markup {
    if (!is_string($value) || trim($value) === '') {
        return new Markup('', 'UTF-8');
    }

    $rendered = $md->convert($value);
    $html = is_object($rendered) && method_exists($rendered, 'getContent')
        ? (string) $rendered->getContent()
        : (string) $rendered;

    return new Markup($html, 'UTF-8');
}, ['is_safe' => ['html']]));
$app->add(TwigMiddleware::create($app, $twig));

$adminRepo = new AdminRepository($db);
$sessionRepo = new AdminSessionRepository($db);
$lockoutRepo = new LoginLockoutRepository($db);
$auditRepo = new AuditLogRepository($db);
$eventRepo = new EventRepository($db);
$variationRepo = new EventVariationRepository($db);
$modifierRepo = new EventModifierRepository($db);
$addonRepo = new AddonProductRepository($db);
$cartRepo = new CartRepository($db);
$cartItemRepo = new CartItemRepository($db);
$cartItemModifierRepo = new CartItemModifierRepository($db);
$cartItemAddonRepo = new CartItemAddonRepository($db);
$cartService = new CartService(
    $cartRepo,
    $cartItemRepo,
    $cartItemModifierRepo,
    $cartItemAddonRepo,
    $eventRepo,
    $variationRepo,
    $modifierRepo,
    $addonRepo,
);
$purchaseRepo = new PurchaseRepository($db);
$purchaseTicketRepo = new PurchaseTicketRepository($db);
$purchaseTicketModifierRepo = new PurchaseTicketModifierRepository($db);
$purchaseTicketAddonRepo = new PurchaseTicketAddonRepository($db);
$adminAuth = new AdminAuth($adminRepo, $sessionRepo, $lockoutRepo, $auditRepo, $config);
$mailer = new Mailer($config);
$emailRenderer = new EmailRenderer($twig);
$square = new SquareCheckoutClient((array) ($config['square'] ?? []));

// Refresh cart timers on any page view (MVP behavior).
$app->add(function (Request $request, $handler) use ($cartService) {
    $cartService->refreshCartForSession(hash('sha256', session_id()));
    return $handler->handle($request);
});

PublicRoutes::register(
    $app,
    $twig,
    $config,
    $eventRepo,
    $variationRepo,
    $modifierRepo,
    $addonRepo,
    $cartRepo,
    $cartItemRepo,
    $cartItemAddonRepo,
    $cartItemModifierRepo,
    $purchaseRepo,
    $purchaseTicketRepo,
    $purchaseTicketModifierRepo,
    $purchaseTicketAddonRepo,
    $square,
    $mailer,
    $emailRenderer,
    $cartService
);

AdminRoutes::register(
    $app,
    $twig,
    $config,
    $adminAuth,
    $adminRepo,
    $auditRepo,
    $lockoutRepo,
    $eventRepo,
    $variationRepo,
    $modifierRepo,
    $addonRepo,
    $purchaseRepo,
    $purchaseTicketRepo,
    $purchaseTicketModifierRepo,
    $purchaseTicketAddonRepo,
    $mailer,
    $emailRenderer
);

$app->get('/health', function (Request $request, Response $response) use ($db) {
    try {
        $db->executeQuery('SELECT 1');
        $status = 'ok';
    } catch (Throwable $error) {
        $status = 'db_error';
    }

    $payload = json_encode(['status' => $status], JSON_PRETTY_PRINT);
    $response->getBody()->write($payload ?: '{}');
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/debug/boom', function (Request $request, Response $response) use ($config) {
    $isDev = ($config['app_env'] ?? 'dev') === 'dev';
    if (!$isDev) {
        return $response->withStatus(404);
    }

    throw new RuntimeException('Intentional debug exception for Whoops testing.');
});

$app->run();
