<?php

declare(strict_types=1);

namespace app\service;

use Crab\HttpClient\Client;

/**
 * Per-user AI token usage for Console Cultivation, using the same `ai.tokens.used` contract as AG.
 * Each Codex model call becomes one outbox row credited, by email, to the person whose message drove the turn.
 */
final class TokenUsage
{
    private const EVENT = 'ai.tokens.used';
    private const NEVER = '9999-12-31T00:00:00Z';

    public static function enabled(): bool
    {
        return self::config()['usage_relay_url'] !== '';
    }

    /**
     * Credits the growth of a thread's running fresh-token total (total minus cached input) to the requester;
     * cached input is re-read conversation history, so it measures chat length rather than work. Codex repeats its previous report when a thread
     * resumes, so `last` alone would double count. A resumed thread seen for the first time only sets its baseline;
     * new threads (fresh chats and sub-agents) start from zero. Usage is always kept for the admin report; rows recorded
     * while the relay is off are marked never-send so enabling it later does not flush an old backlog to Console.
     *
     * @param array{total?: array{totalTokens?: mixed, cachedInputTokens?: mixed}} $usage
     */
    public static function record(?int $requesterMessageId, string $threadId, bool $freshThread, array $usage): bool
    {
        if ($threadId === '') return false;
        $total = $usage['total']['totalTokens'] ?? null;
        $cached = $usage['total']['cachedInputTokens'] ?? 0;
        if (!is_int($total) || $total < 0 || !is_int($cached) || $cached < 0) return false;
        $running = max(0, $total - $cached);
        $email = $requesterMessageId === null ? null : Store::connection()->table('messages as m')->join('accounts as a', 'a.user_id', '=', 'm.user_id')
            ->where('m.id', $requesterMessageId)->value('a.email');
        $email = is_string($email) ? strtolower(trim($email)) : '';
        return Store::transaction(function () use ($threadId, $freshThread, $running, $email): bool {
            $threads = Store::connection()->table('token_usage_threads');
            $baseline = (clone $threads)->where('thread_id', $threadId)->value('total_tokens');
            if ($baseline === null) {
                (clone $threads)->insert(['thread_id' => $threadId, 'total_tokens' => $freshThread ? 0 : $running]);
                if (!$freshThread) return false;
                $baseline = 0;
            }
            $tokens = $running - (int) $baseline;
            if ($tokens <= 0) return false;
            Store::connection()->table('token_usage_threads')->where('thread_id', $threadId)->update(['total_tokens' => $running]);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
            // The running total identifies the credited growth, so a replayed report maps to the same ID.
            $instance = (string) (parse_url((string) Auth::appOrigin(), PHP_URL_HOST) ?: 'local');
            Store::connection()->table('token_usages')->upsert([[
                'usage_id' => 'crabase_' . substr(hash('sha256', "fresh|$instance|$threadId|$running"), 0, 40),
                'email' => $email, 'total_tokens' => $tokens, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ] + (self::enabled() ? [] : ['next_attempt_at' => self::NEVER, 'last_error' => 'Relay disabled'])], ['usage_id'], []);
            return true;
        });
    }

    /** Admin report of fresh tokens per signed-up user for the last $days days (0 = all time), largest first. */
    public static function report(mixed $days): array
    {
        if (!is_int($days) || !in_array($days, [0, 1, 7, 30, 90], true)) throw new \InvalidArgumentException('Choose a valid usage period.');
        $since = $days ? gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400) : null;
        $usage = Store::connection()->table('token_usages')->when($since, fn ($query) => $query->where('occurred_at', '>=', $since))
            ->groupBy('email')->selectRaw('email, SUM(total_tokens) AS tokens, COUNT(*) AS calls, MAX(occurred_at) AS last_used')->get();
        $byEmail = array_column($usage, null, 'email');
        $users = Store::connection()->table('accounts as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->select('u.id', 'u.name', 'u.avatar_url', 'a.email', 'a.enabled')->get();
        $rows = [];
        foreach ($users as $user) {
            $row = $byEmail[strtolower(trim($user['email']))] ?? null;
            unset($byEmail[strtolower(trim($user['email']))]);
            $rows[] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'enabled' => (bool) $user['enabled'],
                'avatar_url' => $user['avatar_url'], 'avatar_fallback' => Auth::avatar($user['email']),
                'tokens' => (int) ($row['tokens'] ?? 0), 'calls' => (int) ($row['calls'] ?? 0), 'last_used' => $row['last_used'] ?? null];
        }
        // Usage credited to an email whose account was since removed or renamed still counts toward the total.
        foreach ($byEmail as $email => $row) $rows[] = ['id' => null, 'name' => $email, 'email' => $email, 'enabled' => false,
            'avatar_url' => '', 'avatar_fallback' => Auth::avatar($email), 'tokens' => (int) $row['tokens'], 'calls' => (int) $row['calls'], 'last_used' => $row['last_used']];
        usort($rows, fn (array $a, array $b) => [$b['tokens'], $a['name']] <=> [$a['tokens'], $b['name']]);
        $relay = Store::connection()->table('token_usages')->whereNull('sent_at')->selectRaw("SUM(next_attempt_at IS NULL OR next_attempt_at < ?) AS pending, SUM(next_attempt_at = ? AND last_error <> 'Relay disabled') AS failed", [self::NEVER, self::NEVER])->first();
        return ['days' => $days, 'total' => array_sum(array_column($rows, 'tokens')), 'users' => $rows,
            'relay' => ['enabled' => self::enabled(), 'pending' => (int) ($relay['pending'] ?? 0), 'failed' => (int) ($relay['failed'] ?? 0)]];
    }

    public static function hasDue(): bool
    {
        return self::due()->exists();
    }

    /** Sends due rows once each; retries keep the same ID and payload. @return array{sent: int, retry: int, failed: int} */
    public static function publishDue(int $limit = 100): array
    {
        $result = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        $config = self::config();
        if ($config['usage_relay_url'] === '') return $result;
        $headers = ['Accept' => 'application/json'] + ($config['usage_relay_token'] !== '' ? ['Authorization' => 'Bearer ' . $config['usage_relay_token']] : []);
        $client = new Client(timeout: 10.0, redirects: 0, maxBytes: 100_000);
        foreach (self::due()->orderBy('id')->limit($limit)->get() as $row) {
            $event = ['id' => $row['usage_id'], 'event' => self::EVENT, 'payload' => [
                'email' => $row['email'], 'total_tokens' => (int) $row['total_tokens'], 'occurred_at' => $row['occurred_at'],
            ]];
            try {
                $status = $client->request('POST', $config['usage_relay_url'], headers: $headers, json: $event)->getStatusCode();
            } catch (\Throwable $error) {
                // Console or the network is down: retry this row later and leave the rest for the next run.
                self::retry($row, 'Transport: ' . substr($error->getMessage(), 0, 200));
                $result['retry']++;
                break;
            }
            if ($status >= 200 && $status < 300) {
                Store::connection()->table('token_usages')->where('id', $row['id'])->update(['sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'last_error' => null]);
                $result['sent']++;
            } elseif ($status === 408 || $status === 429 || $status >= 500) {
                self::retry($row, "HTTP $status");
                $result['retry']++;
            } else {
                // 409 (same ID, different content) and other 4xx need investigation; resending would not help.
                Store::connection()->table('token_usages')->where('id', $row['id'])->update([
                    'attempts' => (int) $row['attempts'] + 1, 'next_attempt_at' => self::NEVER, 'last_error' => "HTTP $status",
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private static function due()
    {
        return Store::connection()->table('token_usages')->whereNull('sent_at')->where(function ($query) {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', gmdate('Y-m-d\TH:i:s\Z'));
        });
    }

    private static function retry(array $row, string $error): void
    {
        $attempts = (int) $row['attempts'] + 1;
        $delayMinutes = min(2 ** min($attempts, 11), 1440);
        Store::connection()->table('token_usages')->where('id', $row['id'])->update([
            'attempts' => $attempts, 'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delayMinutes * 60), 'last_error' => $error,
        ]);
    }

    /** @return array{usage_relay_url: string, usage_relay_token: string} */
    private static function config(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/crabase.php';
        return ['usage_relay_url' => (string) $config['usage_relay_url'], 'usage_relay_token' => (string) $config['usage_relay_token']];
    }
}
