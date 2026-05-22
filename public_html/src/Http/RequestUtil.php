<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestUtil
{
    public static function clientIp(array $config, Request $request): string
    {
        $server = $request->getServerParams();
        $trustedIp = $config['security']['trusted_proxy_ip'] ?? '';
        $trustedHeader = $config['security']['trusted_proxy_header'] ?? '';

        if ($trustedIp && $trustedHeader && ($server['REMOTE_ADDR'] ?? '') === $trustedIp) {
            $forwarded = $server[$trustedHeader] ?? '';
            if ($forwarded !== '') {
                $parts = explode(',', (string) $forwarded);
                return trim($parts[0]);
            }
        }

        return (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

