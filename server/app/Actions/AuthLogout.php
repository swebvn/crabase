<?php

declare(strict_types=1);

namespace App\Actions;

use App\Service\AuthHttp;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class AuthLogout
{
    public function handle(Request $request): Response
    {
        return AuthHttp::handle($request, 'logout');
    }
}
