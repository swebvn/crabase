<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\Auth;
use Crab\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Resolves the signed-in browser session and carries its user on the request. */
final readonly class CookieAuth implements MiddlewareInterface
{
    public function __construct(private bool $required = true)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[Auth::COOKIE] ?? null;
        $user = is_string($cookie) ? Auth::user($cookie) : null;
        if ($user === null && $this->required) {
            return Response::text('Authentication required', 401);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
