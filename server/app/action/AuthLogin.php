<?php

declare(strict_types=1);

namespace app\action;

use app\service\AuthHttp;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class AuthLogin
{
    public function handle(Request $request): Response
    {
        return AuthHttp::handle($request, 'login');
    }
}
