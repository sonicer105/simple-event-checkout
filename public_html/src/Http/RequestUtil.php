<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestUtil
{
    private static function normalizeHeaderKey(string $header): string
    {
        $h = trim($header);
        if ($h === '') {
            return '';
        }

        // PHP exposes headers as HTTP_FOO_BAR, not Foo-Bar.
        $h = strtoupper(str_replace('-', '_', $h));
        if (!str_starts_with($h, 'HTTP_')) {
            $h = 'HTTP_' . $h;
        }
        return $h;
    }

    private static function serverHeaderValue(array $server, string $header): string
    {
        if ($header === '') {
            return '';
        }

        // Try exact match first (in case caller already provided HTTP_*).
        if (isset($server[$header]) && $server[$header] !== '') {
            return (string) $server[$header];
        }

        // Then try normalized HTTP_* form.
        $httpKey = self::normalizeHeaderKey($header);
        if ($httpKey !== '' && isset($server[$httpKey]) && $server[$httpKey] !== '') {
            return (string) $server[$httpKey];
        }

        // As a last resort, try the raw header name as-is.
        return (string) ($server[$header] ?? '');
    }

    public static function clientIp(array $config, Request $request): string
    {
        $server = $request->getServerParams();
        $trustedIp = $config['security']['trusted_proxy_ip'] ?? '';
        $trustedHeader = $config['security']['trusted_proxy_header'] ?? '';

        if ($trustedIp && $trustedHeader && ($server['REMOTE_ADDR'] ?? '') === $trustedIp) {
            $forwarded = self::serverHeaderValue($server, (string) $trustedHeader);
            if ($forwarded !== '') {
                $parts = explode(',', (string) $forwarded);
                return trim($parts[0]);
            }
        }

        return (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

