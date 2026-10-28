<?php

declare(strict_types=1);

use app\action\{Artifact, AuthLogin, AuthLogout, AuthSession, Avatar, Frontend, OAuthCallback, OAuthStart};
use app\middleware\{BrowserRequest, CookieAuth, ProjectFileAccess};
use Crab\Route;

Route::get('/auth/session', AuthSession::class)
    ->middleware(BrowserRequest::class, new CookieAuth(false));
Route::post('/auth/login', AuthLogin::class)->middleware(BrowserRequest::class);
Route::post('/auth/logout', AuthLogout::class)->middleware(BrowserRequest::class);
Route::get('/auth/oauth/start', OAuthStart::class);
Route::get('/auth/oauth/callback', OAuthCallback::class);
Route::get('/auth/avatars/{name}', Avatar::class)
    ->middleware(CookieAuth::class, new BrowserRequest(false));
Route::get('/files/{chatId}/{name}', Artifact::class)
    ->middleware(CookieAuth::class, ProjectFileAccess::class, new BrowserRequest(false));

$frontend = Frontend::class;
Route::get('/', $frontend);
foreach (['/settings', '/chat/{id}', '/new/{id}', '/project/{id}', '/project/{id}/new', '/project/{projectId}/chat/{id}'] as $path) {
    Route::get($path, $frontend);
}
