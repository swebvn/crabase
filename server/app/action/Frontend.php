<?php

declare(strict_types=1);

namespace app\action;

use Crab\Http\Response;

final class Frontend
{
    public function handle(): Response
    {
        $index = dirname(__DIR__, 2) . '/public/index.html';
        return is_file($index) ? Response::html((string) file_get_contents($index)) : Response::text('Build the frontend first.');
    }
}
