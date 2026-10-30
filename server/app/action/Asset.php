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

        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'map' => 'application/json',
            'wasm' => 'application/wasm',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $type = $types[$extension] ?? mime_content_type($file) ?: 'application/octet-stream';

        return Response::download($file, basename($file), $type)
            ->withHeader('Content-Disposition', 'inline; filename*=UTF-8\'\'' . rawurlencode(basename($file)))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
