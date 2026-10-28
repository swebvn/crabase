<?php

declare(strict_types=1);

namespace app\action;

use app\service\Artifacts;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class Artifact
{
    public function handle(Request $request, string $chatId, string $name): Response
    {
        $file = Artifacts::resolve($chatId, $name);
        if ($file === null) {
            return Response::text('Output file not found.', 404);
        }

        $inline = !$request->query('download')
            && in_array($file['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true);
        return Response::download($file['path'], $file['name'], $file['mime'])
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['name']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }
}
