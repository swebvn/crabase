<?php

namespace App\Service;

use InvalidArgumentException;

final class ModelCatalog
{
    public const TTL = 1800;

    private static array $models = [];
    private static float $expiresAt = 0;
    private static bool $refreshing = false;

    public static function all(): array { return self::$models; }
    public static function replace(array $models): void
    {
        self::$models = array_values($models);
        self::$expiresAt = microtime(true) + self::TTL;
    }
    public static function expired(): bool { return self::$expiresAt <= microtime(true); }
    public static function beginRefresh(): bool
    {
        if (self::$refreshing) return false;
        self::$refreshing = true;
        return true;
    }
    public static function finishRefresh(): void { self::$refreshing = false; }

    /** Validates a requested model and reasoning level against the live catalog; returns nulls for the Codex default. */
    public static function options(array $data): array
    {
        $model = $data['model'] ?? null;
        $effort = $data['effort'] ?? null;
        if ($effort === 'ultra') throw new InvalidArgumentException('Ultra reasoning is disabled.');
        if ($model === null) {
            if ($effort !== null) {
                throw new InvalidArgumentException('Select a model before choosing reasoning.');
            }
            return ['model' => null,'effort' => null];
        }
        $model = Input::text($model, 200);
        $selected = array_values(array_filter(self::$models, fn ($entry) => $entry['model'] === $model))[0] ?? null;
        if (!$selected) {
            throw new InvalidArgumentException('This model is not in the current Codex catalog. Refresh the model list.');
        }
        $efforts = array_values(array_filter(array_column($selected['supportedReasoningEfforts'], 'reasoningEffort'), fn ($level) => $level !== 'ultra'));
        if (!$efforts) {
            if ($effort !== null) {
                throw new InvalidArgumentException('This model does not offer reasoning levels.');
            }
            return ['model' => $model,'effort' => null];
        }
        $default = $selected['defaultReasoningEffort'] ?? null;
        $effort ??= in_array($default, $efforts, true) ? $default : ($efforts[0] ?? null);
        if ($effort !== null && !in_array($effort, $efforts, true)) {
            throw new InvalidArgumentException('This reasoning level is not supported by the selected model.');
        }
        return ['model' => $model,'effort' => $effort];
    }
}
