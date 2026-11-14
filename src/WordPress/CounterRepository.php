<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Per-site daily counters: runs started, known cost in nanos, refusals by
 * reason (case 66). Reservations are a single conditional increment, so two
 * concurrent requests at the cap minus one cannot both pass (lesson 9).
 */
class CounterRepository
{
    public const RUNS = 'runs';
    public const COST = 'cost_nanos';
    public const REFUSED_PREFIX = 'refused:';

    private function table(): string
    {
        return Schema::table('promptlib_counters');
    }

    /**
     * Atomically add $amount when the result stays within $cap. A cap of 0
     * or less means no cap. Returns true when reserved.
     */
    public function reserve(string $day, string $metric, int $amount, int $cap): bool
    {
        global $wpdb;
        $table = $this->table();
        $this->ensureRow($day, $metric);
        if ($cap <= 0) {
            $sql = $wpdb->prepare(
                "/*promptlib:counter.add*/ UPDATE {$table} SET value = value + %d WHERE day = %s AND metric = %s",
                $amount,
                $day,
                $metric
            );
        } else {
            $sql = $wpdb->prepare(
                "/*promptlib:counter.reserve*/ UPDATE {$table} SET value = value + %d WHERE day = %s AND metric = %s AND value + %d <= %d",
                $amount,
                $day,
                $metric,
                $amount,
                $cap
            );
        }

        return (int) $wpdb->query($sql) === 1;
    }

    public function add(string $day, string $metric, int $amount): void
    {
        $this->reserve($day, $metric, $amount, 0);
    }

    /** Give back a reservation; never goes below zero. */
    public function release(string $day, string $metric, int $amount): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare(
            "/*promptlib:counter.release*/ UPDATE {$table} SET value = GREATEST(value - %d, 0) WHERE day = %s AND metric = %s",
            $amount,
            $day,
            $metric
        ));
    }

    public function get(string $day, string $metric): int
    {
        global $wpdb;
        $table = $this->table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "/*promptlib:counter.get*/ SELECT value FROM {$table} WHERE day = %s AND metric = %s",
            $day,
            $metric
        ));
    }

    /** @return array<string, int> metric => value */
    public function day(string $day): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "/*promptlib:counter.day*/ SELECT metric, value FROM {$table} WHERE day = %s",
            $day
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['metric']] = (int) $row['value'];
        }

        return $out;
    }

    /** Remove rows older than $keepDays days before $today. */
    public function prune(string $today, int $keepDays = 7): void
    {
        global $wpdb;
        $table = $this->table();
        $cutoff = (new \DateTimeImmutable($today))->modify('-' . $keepDays . ' days')->format('Y-m-d');
        $wpdb->query($wpdb->prepare("/*promptlib:counter.prune*/ DELETE FROM {$table} WHERE day < %s", $cutoff));
    }

    private function ensureRow(string $day, string $metric): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare(
            "/*promptlib:counter.ensure*/ INSERT IGNORE INTO {$table} (day, metric, value) VALUES (%s, %s, 0)",
            $day,
            $metric
        ));
    }
}
