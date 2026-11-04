#!/usr/bin/env php
<?php

declare(strict_types=1);

// Sends due per-user token usage to Console Event Relay. Started by the Codex worker; safe to run by hand.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/crabase.php';

$lock = fopen(dirname(__DIR__) . '/runtime/logs/usage.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
app\service\Store::db();
$result = app\service\TokenUsage::publishDue();
if ($result['retry'] || $result['failed']) {
    fwrite(STDERR, gmdate('c') . ' token usage: ' . json_encode($result) . "\n");
}
