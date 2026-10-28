<?php

declare(strict_types=1);

namespace app\action;

use app\service\OAuth;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class OAuthStart
{
    public function handle(Request $request): Response
    {
        return OAuth::start($request);
    }
}
