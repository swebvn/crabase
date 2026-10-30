<?php

declare(strict_types=1);

namespace app\service;

use Crab\Contracts\Http\Request;
use Crab\Http\Cookie;
use Crab\Http\Response;

final class AuthHttp
{
    public static function handle(Request $request, string $action): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $host = explode(':', $request->getHeaderLine('Host'))[0];
        if (!Auth::allowedHost($host) || strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'cross-site') {
            return Response::json(['error' => 'Forbidden'], 403, $headers);
        }
        try {
            $token = $request->cookie(Auth::COOKIE);
            if ($action === 'login') {
                $raw = (string) $request->getBody();
                if (strlen($raw) > 4096) throw new \InvalidArgumentException('Request too large.');
                $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new \InvalidArgumentException('Invalid request.');
                $next = Auth::login($data, $request->ip());
                Auth::logout($token);
                return Response::json(['user' => Auth::user($next)], 200, $headers)
                    ->withCookie(new Cookie(Auth::COOKIE, $next, 604800, '/', '', false, true, 'Strict'));
            }
            if ($action === 'logout') {
                Auth::logout($token);
                return Response::json(['ok' => true], 200, $headers)->withCookie(Cookie::forget(Auth::COOKIE));
            }
            $user = Auth::user($token);
            return Response::json(['user' => $user, 'oauth' => OAuth::configured()], $user ? 200 : 401, $headers);
        } catch (\InvalidArgumentException|\JsonException $error) {
            return Response::json(['error' => $error->getMessage()], 400, $headers);
        }
    }
}
