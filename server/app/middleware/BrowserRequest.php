<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\Auth;
use Crab\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Applies the browser host and cross-site request policy used by auth/files. */
final readonly class BrowserRequest implements MiddlewareInterface
{
    public function __construct(private bool $json = true)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $host = explode(':', $request->getHeaderLine('Host'))[0];
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (!Auth::allowedHost($host) || strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'cross-site') {
            return $this->json
                ? Response::json(['error' => 'Forbidden'], 403, $headers)
                : Response::text('Forbidden', 403);
        }

        return $handler->handle($request);
    }
}
