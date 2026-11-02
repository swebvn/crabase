<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\Updater;

$storage = dirname(__DIR__) . '/storage';
$fail = static fn (string $message) => throw new RuntimeException($message);
$rejects = static function (callable $call, string $expected) use ($fail): void {
    try { $call(); } catch (InvalidArgumentException $error) { if (!str_contains($error->getMessage(), $expected)) $fail('Unexpected error: ' . $error->getMessage()); return; }
    $fail('Expected rejection: ' . $expected);
};
try {
    if (!Updater::validTag('v1.0.0') || !Updater::validTag('v12.3.4-rc.1') || Updater::validTag('main') || Updater::validTag('v1.0') || Updater::validTag('v1.0.0;rm')) $fail('Tag validation failed.');

    Updater::writeStatus(['state' => 'testing', 'message' => 'test']);
    $status = Updater::status();
    if (($status['state'] ?? '') !== 'testing' || ($status['message'] ?? '') !== 'test') $fail('Status persistence failed.');

    // Release data is sanitized and checked locally; untrusted URLs fall back to the GitHub release page.
    $info = Updater::check(['tag' => 'v99.0.0', 'name' => ['x'], 'url' => 'javascript:alert(1)', 'body' => 'notes']);
    if ($info['latest']['url'] !== 'https://github.com/swebvn/crabase/releases/tag/v99.0.0' || $info['latest']['name'] !== 'v99.0.0') $fail('Release sanitizing failed.');
    if ($info['can_update'] || !is_string($info['reason'])) $fail('A non-release test checkout must not be updatable.');
    $rejects(fn () => Updater::check(['tag' => 'main']), 'invalid version tag');

    // Starting requires a fresh check and reports a concurrent update without blocking.
    @unlink("$storage/update-release.json");
    Updater::writeStatus(['state' => 'idle']);
    $rejects(fn () => Updater::start(), 'Check for updates again');
    $rejects(fn () => Updater::start(['active_jobs' => 1]), 'Finish queued jobs');
    $lock = fopen("$storage/update.lock", 'c');
    flock($lock, LOCK_EX);
    Updater::writeStatus(['state' => 'running', 'message' => 'busy']);
    $started = microtime(true);
    $status = Updater::start();
    flock($lock, LOCK_UN); fclose($lock);
    if (($status['state'] ?? '') !== 'running' || microtime(true) - $started > 1) $fail('A running update must be reported without blocking.');
    echo "PASS: release tags, sanitizing, local checks, fresh-check requirement, activity guard and non-blocking lock.\n";
} finally {
    @unlink("$storage/update.json");
    @unlink("$storage/update-release.json");
}
