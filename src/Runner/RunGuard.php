<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

use IndianOpinion\PromptLibrary\WordPress\Clock;

/**
 * One run in flight per user, and a per-user rate (case 62).
 *
 * The lock is an option added with add_option(), which is atomic on the
 * unique option name, holding its expiry time. An expired lock is taken over,
 * so a crashed request blocks a user for at most the deadline plus 30 seconds.
 */
class RunGuard
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function acquire(int $userId, int $ttl): bool
    {
        $name = self::lockName($userId);
        $now = $this->clock->timestamp();
        if (add_option($name, (string) ($now + $ttl), '', false)) {
            return true;
        }
        $expires = (int) get_option($name, '0');
        if ($expires > $now) {
            return false;
        }
        // Expired: take it over, but only if nobody else did first.
        delete_option($name);

        return add_option($name, (string) ($now + $ttl), '', false);
    }

    public function release(int $userId): void
    {
        delete_option(self::lockName($userId));
    }

    /** Count one run against the per-minute rate; false when over it. */
    public function rate(int $userId, int $perMinute): bool
    {
        $bucket = (int) floor($this->clock->timestamp() / 60);
        $name = 'promptlib_rate_' . $userId . '_' . $bucket;
        $count = (int) get_transient($name);
        if ($count >= $perMinute) {
            return false;
        }
        set_transient($name, $count + 1, 120);

        return true;
    }

    public static function lockName(int $userId): string
    {
        return 'promptlib_lock_' . $userId;
    }
}
