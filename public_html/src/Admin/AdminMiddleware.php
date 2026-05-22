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

        return $handler->handle($request->withAttribute('admin', $admin));
    }
}

