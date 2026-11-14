<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Time source. Timestamps are always stored in UTC; the daily cap window
 * follows the site timezone (see docs/ARCHITECTURE.md, decision D2).
 */
class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** MySQL DATETIME in UTC. */
    public function nowUtc(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /** The site-timezone date that names today's counter window. */
    public function siteDay(): string
    {
        return $this->now()->setTimezone(wp_timezone())->format('Y-m-d');
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }
}
