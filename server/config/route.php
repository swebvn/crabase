<?php
use Webman\Route;
Route::get('/auth/oauth/start', fn (\support\Request $request) => \app\service\OAuth::start($request));
Route::get('/auth/oauth/callback', fn (\support\Request $request) => \app\service\OAuth::callback($request));
Route::get('/auth/session', fn (\support\Request $request) => \app\service\AuthHttp::handle($request, 'session'));
Route::get('/auth/avatars/{name}', function (\support\Request $request, string $name) {
    if (!\app\service\Auth::user($request->cookie(\app\service\Auth::COOKIE))) return response('Authentication required', 401);
    if (!\app\service\Auth::allowedHost(explode(':', $request->host())[0]) || $request->header('sec-fetch-site') === 'cross-site') return response('Forbidden', 403);
    $path = \app\service\Avatars::resolve($name);
    if (!$path) return response('Avatar not found', 404);
    return response()->file($path)->withHeaders(['Content-Type'=>(new \finfo(FILEINFO_MIME_TYPE))->file($path), 'X-Content-Type-Options'=>'nosniff', 'Content-Security-Policy'=>"sandbox; default-src 'none'", 'Cache-Control'=>'private, no-store', 'Cross-Origin-Resource-Policy'=>'same-origin']);
});
Route::post('/auth/login', fn (\support\Request $request) => \app\service\AuthHttp::handle($request, 'login'));
Route::post('/auth/logout', fn (\support\Request $request) => \app\service\AuthHttp::handle($request, 'logout'));
Route::get('/', function () {
    $index = public_path() . '/index.html';
    return is_file($index) ? response(file_get_contents($index))->header('Content-Type', 'text/html; charset=utf-8') : response('Build the frontend with npm run build, or open http://127.0.0.1:5173 during development.');
});
foreach (['/settings', '/chat/{id}', '/new/{id}', '/project/{id}', '/project/{id}/new', '/project/{projectId}/chat/{id}'] as $path) {
    Route::get($path, function () {
        $index = public_path() . '/index.html';
        return is_file($index) ? response(file_get_contents($index))->header('Content-Type', 'text/html; charset=utf-8') : response('Build the frontend first.');
    });
}
Route::get('/files/{chatId}/{name}', function (\support\Request $request, string $chatId, string $name) {
    if (!$actor = \app\service\Auth::user($request->cookie(\app\service\Auth::COOKIE))) return response('Authentication required', 401);
    if (!\app\service\ProjectAccess::canChat($actor, $chatId)) return response('Not found', 404);
    if (!\app\service\Auth::allowedHost(explode(':', $request->host())[0]) || $request->header('sec-fetch-site') === 'cross-site') return response('Forbidden', 403);
    $file = \app\service\Artifacts::resolve($chatId, $name);
    if (!$file) return response('Output file not found.', 404);
    $inline = !$request->get('download') && in_array($file['mime'], ['image/png','image/jpeg','image/gif','image/webp','image/avif'], true);
    return response()->file($file['path'])->withHeaders([
        'Content-Type'=>$file['mime'],
        'Content-Disposition'=>($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['name']),
        'X-Content-Type-Options'=>'nosniff',
        'Content-Security-Policy'=>"sandbox; default-src 'none'",
        'Cache-Control'=>'private, no-store',
        'Cross-Origin-Resource-Policy'=>'same-origin',
    ]);
});
Route::disableDefaultRoute();
