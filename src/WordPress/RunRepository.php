<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Exception\StorageException;

/**
 * Test-runner rows (section 6). Kept 30 days and at most 5,000 per site.
 */
class RunRepository
{
    public const MAX_ROWS = 5000;

    private function table(): string
    {
        return Schema::table('promptlib_runs');
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        global $wpdb;
        $columns = [
            'template_id' => '%d', 'slug' => '%s', 'version_no' => '%d', 'user_id' => '%d', 'model' => '%s',
            'provider' => '%s', 'status' => '%s', 'error_code' => '%s', 'prompt_tokens' => '%d',
            'completion_tokens' => '%d', 'cost_nanos' => '%d', 'cost_source' => '%s', 'duration_ms' => '%d',
            'attempts' => '%d', 'system_text' => '%s', 'user_text' => '%s', 'answer' => '%s',
            'compare_group' => '%s', 'created_at' => '%s',
        ];
        $data = [];
        $formats = [];
        foreach ($columns as $column => $format) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $data[$column] = $row[$column];
            $formats[] = $format;
        }
        if ($wpdb->insert($this->table(), $data, $formats) === false) {
            throw new StorageException('Could not store the run: ' . (string) $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Recent runs without prompt text, for one user or (null) everyone.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?int $userId, int $limit = 50): array
    {
        global $wpdb;
        $table = $this->table();
        $columns = 'id, template_id, slug, version_no, user_id, model, provider, status, error_code, prompt_tokens, completion_tokens, cost_nanos, cost_source, duration_ms, attempts, compare_group, created_at';
        $sql = $userId === null
            ? $wpdb->prepare("/*promptlib:run.recent*/ SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT %d", $limit)
            : $wpdb->prepare("/*promptlib:run.recent_user*/ SELECT {$columns} FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $userId, $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Full rows of one user, for the personal data exporter.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, int $limit, int $offset): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "/*promptlib:run.for_user*/ SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ), ARRAY_A);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function deleteForUser(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->delete($this->table(), ['user_id' => $userId], ['%d']);
    }

    public function deleteForTemplate(int $templateId): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['template_id' => $templateId], ['%d']);
    }

    /** Drop rows older than the cutoff (UTC), then all but the newest $keep rows. */
    public function prune(string $cutoffUtc, int $keep = self::MAX_ROWS): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("/*promptlib:run.prune_age*/ DELETE FROM {$table} WHERE created_at < %s", $cutoffUtc));
        $threshold = $wpdb->get_var($wpdb->prepare(
            "/*promptlib:run.prune_threshold*/ SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            $keep
        ));
        if ($threshold !== null) {
            $wpdb->query($wpdb->prepare("/*promptlib:run.prune_count*/ DELETE FROM {$table} WHERE id <= %d", (int) $threshold));
        }
    }
}
