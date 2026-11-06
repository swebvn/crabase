<?php
require dirname(__DIR__).'/vendor/autoload.php';
try {
    if ($argc !== 3) throw new InvalidArgumentException('Usage: php publish-artifact.php CHAT_ID ABSOLUTE_FILE_PATH');
    $file = \App\Service\Artifacts::publish($argv[1], $argv[2]);
    \App\Service\Store::notify();
    echo json_encode($file, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
