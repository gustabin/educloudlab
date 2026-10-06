<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\RateLimitedException;

/**
 * Fixed-window rate limiter backed by the rate_limits table.
 * Keys are HMAC(APP_HASH_KEY, policy|subject): no raw IPs or emails are stored.
 * Policies are configured in config/security.php under 'rate_limits'.
 */
final class RateLimiter
{
    public function __construct(private readonly Db $db, private readonly Config $config)
    {
    }

    /**
     * Counts one hit and throws RateLimitedException when the policy limit is exceeded.
     */
    public function hit(string $policy, string $subject): void
    {
        [$limit, $window] = $this->policy($policy);
        $count = $this->increment($policy, $subject, $window);
        if ($count > $limit) {
            throw new RateLimitedException($this->secondsLeft($window));
        }
    }

    /** True when the subject is already over the limit (does not count a hit). */
    public function tooMany(string $policy, string $subject): bool
    {
        [$limit, $window] = $this->policy($policy);
        $hits = $this->db->scalar(
            'SELECT hits FROM rate_limits WHERE key_hash = ? AND window_start = ?',
            [$this->key($policy, $subject), $this->windowStart($window)]
        );
        return (int) $hits >= $limit;
    }

    public function clear(string $policy, string $subject): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE key_hash = ?', [$this->key($policy, $subject)]);
    }

    /** Removes expired windows (called by the scheduler and opportunistically). */
    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE window_start < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    }

    private function increment(string $policy, string $subject, int $window): int
    {
        $key = $this->key($policy, $subject);
        $start = $this->windowStart($window);
        $this->db->execute(
            'INSERT INTO rate_limits (key_hash, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $start]
        );
        if (random_int(1, 200) === 1) {
            $this->purgeExpired();
        }
        return (int) $this->db->scalar('SELECT hits FROM rate_limits WHERE key_hash = ? AND window_start = ?', [$key, $start]);
    }

    /** @return array{0: int, 1: int} [limit, window seconds] */
    private function policy(string $policy): array
    {
        $cfg = $this->config->get('security.rate_limits.' . $policy);
        if (!is_array($cfg) || !isset($cfg['limit'], $cfg['window'])) {
            throw new \LogicException("Unknown rate limit policy '$policy'");
        }
        return [(int) $cfg['limit'], (int) $cfg['window']];
    }

    private function key(string $policy, string $subject): string
    {
        return hash_hmac('sha256', $policy . '|' . mb_strtolower($subject), (string) $this->config->get('app.hash_key'), true);
    }

    private function windowStart(int $window): string
    {
        return gmdate('Y-m-d H:i:s', intdiv(time(), $window) * $window);
    }

    private function secondsLeft(int $window): int
    {
        return $window - (time() % $window);
    }
}
