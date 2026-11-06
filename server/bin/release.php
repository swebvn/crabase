#!/usr/bin/env php
<?php

declare(strict_types=1);

// Run by the Codex worker so the GitHub request never blocks the WebSocket event loop.
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    echo json_encode(['release' => App\Service\Updater::latestRelease()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    echo json_encode(['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Could not check for releases.']);
}
