<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\ProjectAccess;
use Crab\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Hides private chat artifacts from users who cannot see their project. */
final class ProjectFileAccess implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = $request->getAttribute('user');
        $chatId = $request->getAttribute('chatId');
        if (!is_array($actor) || !is_string($chatId) || !ProjectAccess::canChat($actor, $chatId)) {
            return Response::text('Not found', 404);
        }

        return $handler->handle($request);
    }
}
