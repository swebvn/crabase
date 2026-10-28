<?php

declare(strict_types=1);

namespace app\action;

use app\service\OAuth;
use Crab\Contracts\Log\Logger;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final class OAuthCallback
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function handle(Request $request): Response
    {
        return OAuth::callback($request, $this->logger);
    }
}
