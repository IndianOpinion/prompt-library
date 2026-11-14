<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Text;

/**
 * Export and import of whole templates (cases 76 to 79). Exports never hold
 * run rows, provider keys or settings.
 */
class ImportExport
{
    public const FORMAT = 'prompt-library-export/1';

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly Drafts $drafts,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(int $id, bool $withDraft = false): array
    {
        $post = $this->templates->post($id);
        if ($post === null) {
            throw new RequestException('promptlib_not_found', 'The template does not exist.', 404);
        }
        $versions = [];
        foreach (array_reverse($this->versions->versions($id)) as $row) {
            $user = get_userdata((int) $row['user_id']);
            $definition = json_decode((string) $row['definition'], true);
            $versions[] = [
                'number' => (int) $row['version_no'],
                'definition' => is_array($definition) ? $definition : null,
                'note' => (string) $row['note'],
                'author' => $user instanceof \WP_User ? $user->user_login : '',
                'time' => (string) $row['created_at'],
                'hash' => (string) $row['content_hash'],
            ];
        }
        $doc = [
            'format' => self::FORMAT,
            'slug' => $this->templates->slugOf($id),
            'title' => $post->post_title,
            'description' => $post->post_excerpt,
            'live' => $this->templates->live($id),
            'versions' => $versions,
        ];
        if ($withDraft) {
            $doc['draft'] = $this->templates->draft($id);
        }

        return $doc;
    }

    /**
     * Import one export document. Returns the mode: created or draft.
     *
     * @param array<mixed> $doc
     * @return array{mode: string, template_id: int, slug: string}
     */
    public function import(array $doc, int $userId): array
    {
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new RequestException('promptlib_import_format', sprintf('Not a prompt library export (expected format %s).', self::FORMAT));
        }
        $slug = is_string($doc['slug'] ?? null) ? $doc['slug'] : '';
        $versions = $doc['versions'] ?? null;
        if (!is_array($versions) || !array_is_list($versions)) {
            throw new RequestException('promptlib_import_shape', 'The export has no version list.');
        }
        $checked = $this->checkVersions($slug, $versions);
        $live = (int) ($doc['live'] ?? 0);
        if ($checked !== [] && !isset($checked[$live])) {
            throw new RequestException('promptlib_import_live', 'The live number does not name an exported version.');
        }
        if (isset($doc['draft']) && is_array($doc['draft'])) {
            $draftIssues = array_values(array_filter(Definition::validate(['slug' => $slug] + $doc['draft'], Drafts::limits()), [Definition::class, 'blocksSave']));
            if ($draftIssues !== []) {
                throw new ValidationException($draftIssues, 'promptlib_import_invalid');
            }
        }

        $found = $this->templates->locate($slug);
        if ($found !== null) {
            if ($checked === []) {
                throw new RequestException('promptlib_import_empty', 'The export has no versions to load into the draft.');
            }
            $definition = $checked[$live]['definition'];
            $definition['note'] = sprintf('Imported version %d; review and publish.', $live);
            $this->drafts->save($found['id'], $definition, null, $userId);
            $this->versions->insertEvent($found['id'], $slug, 'import', null, null, sprintf('Version %d loaded into the draft.', $live), $userId, $this->clock->nowUtc());
            do_action('promptlib_imported', $slug, 'draft');
            $this->logger->info('Template imported into the draft.', ['slug' => $slug, 'user_id' => $userId]);
            return ['mode' => 'draft', 'template_id' => $found['id'], 'slug' => $slug];
        }

        $draft = isset($doc['draft']) && is_array($doc['draft'])
            ? $doc['draft']
            : ($checked !== [] ? $checked[$live]['definition'] : ['slug' => $slug, 'title' => (string) ($doc['title'] ?? '')]);
        $draft['slug'] = $slug;
        $draft['title'] = (string) ($doc['title'] ?? ($draft['title'] ?? ''));
        $draft['description'] = (string) ($doc['description'] ?? ($draft['description'] ?? ''));
        $id = $this->drafts->create($draft, $userId);
        ksort($checked);
        foreach ($checked as $number => $item) {
            $author = get_user_by('login', $item['author']);
            $authorId = $author instanceof \WP_User ? (int) $author->ID : $userId;
            $time = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['time']) === 1 ? $item['time'] : $this->clock->nowUtc();
            if (!$this->versions->insertVersion($id, $slug, $number, $item['json'], $item['hash'], $item['note'], $authorId, $time)) {
                throw new RequestException('promptlib_conflict', 'A version number was taken during the import.', 409);
            }
        }
        // History before the pointer, as in Publisher: no live version without a record.
        $this->versions->insertEvent($id, $slug, 'import', null, $live > 0 ? $live : null, sprintf('Created by import with %d versions.', count($checked)), $userId, $this->clock->nowUtc());
        if ($live > 0) {
            $this->templates->setLive($id, $live);
        }
        do_action('promptlib_imported', $slug, 'created');
        $this->logger->info('Template created by import.', ['slug' => $slug, 'versions' => count($checked), 'user_id' => $userId]);

        return ['mode' => 'created', 'template_id' => $id, 'slug' => $slug];
    }

    /**
     * Validate every version; one bad version refuses the whole file (case 79).
     *
     * @param list<mixed> $versions
     * @return array<int, array{definition: array<string, mixed>, json: string, hash: string, note: string, author: string, time: string}>
     */
    private function checkVersions(string $slug, array $versions): array
    {
        $out = [];
        foreach ($versions as $index => $item) {
            $where = 'version ' . ($index + 1);
            if (!is_array($item) || !is_int($item['number'] ?? null) || $item['number'] < 1 || !is_array($item['definition'] ?? null)) {
                throw new ValidationException([Issue::error('import_version', 'Each version needs a number and a definition.', $where)], 'promptlib_import_invalid');
            }
            $number = $item['number'];
            if (isset($out[$number])) {
                throw new ValidationException([Issue::error('import_version', 'A version number appears twice.', $where)], 'promptlib_import_invalid');
            }
            $raw = $item['definition'];
            $raw['slug'] = $slug;
            $definition = Definition::fromArray($raw, Drafts::limits());
            $hash = $definition->contentHash();
            if (!is_string($item['hash'] ?? null) || !hash_equals($item['hash'], $hash)) {
                throw new RequestException('promptlib_import_hash', sprintf('Version %d: the recomputed content hash does not match the exported one. The file was changed.', $number));
            }
            $out[$number] = [
                'definition' => $definition->toArray(),
                'json' => Text::json($definition->withNote((string) ($item['note'] ?? ''))->toArray()),
                'hash' => $hash,
                'note' => (string) ($item['note'] ?? ''),
                'author' => (string) ($item['author'] ?? ''),
                'time' => (string) ($item['time'] ?? ''),
            ];
        }
        $numbers = array_keys($out);
        sort($numbers);
        if ($numbers !== [] && $numbers !== range(1, count($numbers))) {
            throw new ValidationException([Issue::error('import_numbers', 'Version numbers must run from 1 with no gaps.')], 'promptlib_import_invalid');
        }

        return $out;
    }
}
