<?php

declare(strict_types=1);

namespace app\action;

use Crab\Http\Response;

final class Asset
{
    public function handle(string $path): Response
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return Response::text('Not found', 404);
        }

        $root = realpath(dirname(__DIR__, 2) . '/public/assets');
        $file = $root === false ? false : realpath($root . '/' . $path);
        if ($file === false || !str_starts_with($file, $root . '/') || !is_file($file) || !is_readable($file)) {
            return Response::text('Not found', 404);
        }

        return Response::download($file, basename($file), mime_content_type($file) ?: 'application/octet-stream')
            ->withHeader('Content-Disposition', 'inline; filename*=UTF-8\'\'' . rawurlencode(basename($file)))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
