<?php

declare(strict_types=1);

use Crab\Http\Response;
use Crab\Route;
use Crab\Contracts\Http\Request;
use app\service\{Auth, AuthHttp, OAuth, Avatars, Artifacts, ProjectAccess};

Route::get('/auth/session', static fn(Request $request): Response => AuthHttp::handle($request, 'session'));
Route::post('/auth/login', static fn(Request $request): Response => AuthHttp::handle($request, 'login'));
Route::post('/auth/logout', static fn(Request $request): Response => AuthHttp::handle($request, 'logout'));
Route::get('/auth/oauth/start', static fn(Request $request): Response => OAuth::start($request));
Route::get('/auth/oauth/callback', static fn(Request $request): Response => OAuth::callback($request));
Route::get('/auth/avatars/{name}', static function (Request $request, string $name): Response {
    if (!Auth::user($request->cookie(Auth::COOKIE))) return Response::text('Authentication required', 401);
    if (!Auth::allowedHost(explode(':', $request->getHeaderLine('Host'))[0]) || $request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') return Response::text('Forbidden', 403);
    $path = Avatars::resolve($name);
    if (!$path) return Response::text('Avatar not found', 404);
    return Response::download($path, basename($path), mime_content_type($path) ?: 'application/octet-stream')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
        ->withHeader('Cache-Control', 'private, no-store')
        ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
});
Route::get('/files/{chatId}/{name}', static function (Request $request, string $chatId, string $name): Response {
    $actor = Auth::user($request->cookie(Auth::COOKIE));
    if (!$actor) return Response::text('Authentication required', 401);
    if (!ProjectAccess::canChat($actor, $chatId)) return Response::text('Not found', 404);
    if (!Auth::allowedHost(explode(':', $request->getHeaderLine('Host'))[0]) || $request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') return Response::text('Forbidden', 403);
    $file = Artifacts::resolve($chatId, $name);
    if (!$file) return Response::text('Output file not found.', 404);
    $inline = !$request->query('download') && in_array($file['mime'], ['image/png','image/jpeg','image/gif','image/webp','image/avif'], true);
    return Response::download($file['path'], $file['name'], $file['mime'])
        ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['name']))
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
        ->withHeader('Cache-Control', 'private, no-store')
        ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
});
$frontend = static function (): Response {
    $index = __DIR__.'/public/index.html';
    return is_file($index) ? Response::html((string) file_get_contents($index)) : Response::text('Build the frontend first.');
};
Route::get('/', $frontend);
foreach (['/settings', '/chat/{id}', '/new/{id}', '/project/{id}', '/project/{id}/new', '/project/{projectId}/chat/{id}'] as $path) Route::get($path, $frontend);
