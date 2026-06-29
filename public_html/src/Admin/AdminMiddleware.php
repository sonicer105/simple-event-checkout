<?php

declare(strict_types=1);

namespace App\Admin;

use App\Auth\AdminAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(private AdminAuth $adminAuth)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $admin = $this->adminAuth->getCurrentAdmin();
        if (!$admin) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $path = $request->getUri()->getPath();
        $role = (string) ($admin['role'] ?? 'full');
        if ($role === 'checkin') {
            $isAllowed = $path === '/admin/logout'
                || $path === '/admin/account'
                || $path === '/admin/checkin'
                || str_starts_with($path, '/admin/checkin/')
                || $path === '/admin/2fa/enroll'
                || $path === '/admin/2fa/enroll/reset';

            if (!$isAllowed) {
                $response = new SlimResponse();
                return $response->withHeader('Location', '/admin/checkin')->withStatus(302);
            }
        }

        return $handler->handle($request->withAttribute('admin', $admin));
    }
}
