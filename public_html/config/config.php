<?php

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

return [
    'app_env' => getenv('APP_ENV') ?: 'prod',
    'app_name' => getenv('APP_NAME') ?: 'Simple Event Checkout',
    'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost:7878',
    'app_timezone' => getenv('APP_TIMEZONE') ?: 'America/Vancouver',
    'store_currency' => getenv('STORE_CURRENCY') ?: 'CAD',
    'square' => [
        'environment' => getenv('SQUARE_ENV') ?: 'sandbox',
        'access_token' => getenv('SQUARE_ACCESS_TOKEN') ?: '',
        'location_id' => getenv('SQUARE_LOCATION_ID') ?: '',
        'version' => getenv('SQUARE_VERSION') ?: '2026-01-22',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'simple_event_checkout',
        'user' => getenv('DB_USER') ?: 'simple_event_checkout',
        'pass' => getenv('DB_PASS') ?: 'simple_event_checkout',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],
    'security' => [
        'trusted_proxy_ip' => getenv('TRUSTED_PROXY_IP') ?: '',
        'trusted_proxy_header' => getenv('TRUSTED_PROXY_HEADER') ?: '',
        'force_https' => filter_var(getenv('FORCE_HTTPS') ?: false, FILTER_VALIDATE_BOOL),
    ],
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: '',
        'port' => (int) (getenv('SMTP_PORT') ?: 587),
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'no-reply@example.com',
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'Simple Event Checkout',
    ],
];
