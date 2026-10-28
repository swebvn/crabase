<?php

declare(strict_types=1);

namespace app\action;

use app\service\Avatars;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class Avatar
{
    public function handle(Request $request, string $name): Response
    {
        $path = Avatars::resolve($name);
        if ($path === null) {
            return Response::text('Avatar not found', 404);
        }

        return Response::download($path, basename($path), mime_content_type($path) ?: 'application/octet-stream')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }
}
