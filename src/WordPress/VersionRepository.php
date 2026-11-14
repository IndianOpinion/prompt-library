<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Exception\StorageException;

/**
 * Immutable versions and history events, in one table with a type column
 * (decision D3). There is deliberately no update method: a version row never
 * changes after insert (case 44).
 */
class VersionRepository
{
    public const TYPE_VERSION = 'version';
    public const EVENTS = ['live', 'import', 'trash', 'restore', 'install', 'offer'];

    private function table(): string
    {
        return Schema::table('promptlib_versions');
    }

    /**
     * Insert a version row. Returns false when the (template, number) pair is
     * already taken, so the caller can retry with the next number (case 40).
     */
    public function insertVersion(int $templateId, string $slug, int $number, string $definitionJson, string $hash, string $note, int $userId, string $createdAt): bool
    {
        global $wpdb;
        $table = $this->table();
        $sql = $wpdb->prepare(
            "/*promptlib:version.insert*/ INSERT IGNORE INTO {$table} (template_id, slug, type, version_no, definition, content_hash, note, user_id, created_at) VALUES (%d, %s, 'version', %d, %s, %s, %s, %d, %s)",
            $templateId,
            $slug,
            $number,
            $definitionJson,
            $hash,
            $note,
            $userId,
            $createdAt
        );
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new StorageException('Could not store the version: ' . (string) $wpdb->last_error);
        }

        return (int) $result === 1;
    }

    public function highestNumber(int $templateId): int
    {
        global $wpdb;
        $table = $this->table();
        $value = $wpdb->get_var($wpdb->prepare(
            "/*promptlib:version.highest*/ SELECT MAX(version_no) FROM {$table} WHERE template_id = %d AND type = 'version'",
            $templateId
        ));
        $this->guard();

        return (int) $value;
    }

    /** @return array<string, mixed>|null */
    public function find(int $templateId, int $number): ?array
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "/*promptlib:version.find*/ SELECT * FROM {$table} WHERE template_id = %d AND type = 'version' AND version_no = %d LIMIT 1",
            $templateId,
            $number
        ), ARRAY_A);
        $this->guard();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function versions(int $templateId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "/*promptlib:version.list*/ SELECT * FROM {$table} WHERE template_id = %d AND type = 'version' ORDER BY version_no DESC",
            $templateId
        ), ARRAY_A);
        $this->guard();

        return is_array($rows) ? array_values($rows) : [];
    }

    public function insertEvent(int $templateId, string $slug, string $type, ?int $from, ?int $to, string $reason, int $userId, string $createdAt): void
    {
        global $wpdb;
        $ok = $wpdb->insert(
            $this->table(),
            [
                'template_id' => $templateId,
                'slug' => $slug,
                'type' => $type,
                'version_no' => null,
                'from_version' => $from,
                'to_version' => $to,
                'reason' => $reason,
                'user_id' => $userId,
                'created_at' => $createdAt,
            ],
            ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s']
        );
        if ($ok === false) {
            throw new StorageException('Could not store the history entry: ' . (string) $wpdb->last_error);
        }
    }

    /** @return list<array<string, mixed>> every row of the template, newest first */
    public function history(int $templateId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "/*promptlib:version.history*/ SELECT id, template_id, slug, type, version_no, content_hash, note, user_id, from_version, to_version, reason, created_at FROM {$table} WHERE template_id = %d ORDER BY id DESC",
            $templateId
        ), ARRAY_A);
        $this->guard();

        return is_array($rows) ? array_values($rows) : [];
    }

    public function deleteTemplate(int $templateId): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['template_id' => $templateId], ['%d']);
    }

    private function guard(): void
    {
        global $wpdb;
        if ((string) $wpdb->last_error !== '') {
            throw new StorageException('Prompt library storage read failed: ' . (string) $wpdb->last_error);
        }
    }
}
