<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Exception\DraftConflictException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Slug;
use IndianOpinion\PromptLibrary\Text;
use IndianOpinion\PromptLibrary\ValueFormatter;
use IndianOpinion\PromptLibrary\VariableSpec;

/**
 * Creating templates, saving drafts, duplicating, restoring a version to
 * the draft, and fixtures (cases 2 to 13, 43, 61).
 */
class Drafts
{
    public const DRAFT_KEYS = ['slug', 'title', 'description', 'system', 'user', 'variables', 'output', 'settings', 'note'];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Keep only draft keys and normalise line endings in the parts (case 9).
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $draft = [];
        foreach (self::DRAFT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $draft[$key] = $input[$key];
            }
        }
        foreach (['system', 'user'] as $part) {
            if (isset($draft[$part]) && is_string($draft[$part])) {
                $draft[$part] = Text::normaliseNewlines($draft[$part]);
            }
        }
        if (isset($draft['slug']) && is_string($draft['slug'])) {
            $draft['slug'] = Slug::normalise(trim($draft['slug']));
        }
        $draft += ['slug' => '', 'title' => '', 'description' => '', 'system' => '', 'user' => '', 'variables' => [], 'output' => ['mode' => 'text'], 'settings' => ['temperature' => null, 'token_cap' => null], 'note' => ''];

        return $draft;
    }

    /** @return array{max_part_bytes: int, max_token_cap: int} */
    public static function limits(): array
    {
        return ['max_part_bytes' => Library::maxPartBytes(), 'max_token_cap' => Settings::load()->maxTokenCap()];
    }

    /**
     * Create a template from a draft.
     *
     * @param array<mixed> $input
     */
    public function create(array $input, int $userId): int
    {
        $draft = self::normalise($input);
        $this->assertSavable($draft);
        $slug = (string) $draft['slug'];
        $this->assertSlugFree($slug, 0);
        $id = $this->templates->create($slug, $draft, $userId);
        $this->templates->forget($slug);
        $found = $this->templates->locate($slug);
        if ($found !== null && $found['id'] !== $id) {
            wp_delete_post($id, true);
            throw new RequestException('promptlib_slug_taken', sprintf('The slug "%s" was taken by another template at the same moment.', $slug), 409);
        }
        $this->logger->info('Template created.', ['slug' => $slug, 'template_id' => $id, 'user_id' => $userId]);

        return $id;
    }

    /**
     * Save the draft. Errors that make a draft unreadable (invalid UTF-8,
     * oversize parts, a bad slug) refuse the save; every other problem is
     * returned and blocks only publishing (case 8).
     *
     * @param array<mixed> $input
     * @return array{hash: string, issues: list<Issue>}
     */
    public function save(int $id, array $input, ?string $expectedHash, int $userId): array
    {
        $post = $this->templates->post($id);
        if ($post === null || $post->post_status === 'trash') {
            throw new RequestException('promptlib_not_found', 'The template does not exist or is in the trash.', 404);
        }
        $stored = $this->templates->draft($id);
        if ($expectedHash !== null && !hash_equals(TemplateRepository::draftHash($stored), $expectedHash)) {
            throw new DraftConflictException($stored, $input);
        }
        $draft = self::normalise($input);
        $current = $this->templates->slugOf($id);
        if ($draft['slug'] !== $current) {
            if ($this->versions->highestNumber($id) > 0) {
                throw new RequestException('promptlib_slug_locked', 'The slug is locked once version 1 is published. Use "duplicate as new slug" to rename.', 409);
            }
        }
        $issues = $this->assertSavable($draft);
        if ($draft['slug'] !== $current) {
            $this->assertSlugFree((string) $draft['slug'], $id);
            update_post_meta($id, PostType::META_SLUG, $draft['slug']);
            wp_update_post(['ID' => $id, 'post_name' => $draft['slug']]);
            $this->templates->forget($current);
            $this->templates->forget((string) $draft['slug']);
        }
        $this->templates->saveDraft($id, $draft);

        return ['hash' => TemplateRepository::draftHash($this->templates->draft($id)), 'issues' => $issues];
    }

    /**
     * Store an autosave of the draft on WordPress's autosave revision
     * (case 46). Consumers never read revisions.
     *
     * @param array<mixed> $input
     */
    public function autosave(int $id, array $input): int
    {
        $draft = self::normalise($input);
        $autosaveId = wp_create_post_autosave(wp_slash([
            'post_ID' => $id,
            'post_title' => (string) $draft['title'],
            'post_excerpt' => (string) $draft['description'],
            'post_content' => '',
        ]));
        if (is_wp_error($autosaveId) || !is_int($autosaveId) || $autosaveId === 0) {
            throw new RequestException('promptlib_autosave_failed', 'The autosave could not be stored.', 500);
        }
        update_metadata('post', $autosaveId, PostType::META_DRAFT, wp_slash($draft));

        return $autosaveId;
    }

    /** Duplicate a template's draft under a new slug (case 5). */
    public function duplicate(int $id, string $newSlug, int $userId): int
    {
        $draft = $this->templates->draft($id);
        $draft['slug'] = $newSlug;
        $draft['note'] = '';

        return $this->create($draft, $userId);
    }

    /** Copy a version into the draft. Does not publish (case 43). */
    public function restoreVersion(int $id, int $number): void
    {
        $row = $this->versions->find($id, $number);
        if ($row === null) {
            throw new RequestException('promptlib_version_not_found', sprintf('Version %d does not exist.', $number), 404);
        }
        $definition = json_decode((string) $row['definition'], true);
        if (!is_array($definition)) {
            throw new RequestException('promptlib_integrity', sprintf('Version %d does not decode.', $number), 500);
        }
        $draft = self::normalise($definition);
        $draft['slug'] = $this->templates->slugOf($id);
        $draft['note'] = sprintf('Restored from version %d.', $number);
        $this->templates->saveDraft($id, $draft);
    }

    /**
     * Save the current values as a named fixture (case 61). Fixtures are
     * draft data and never change a version hash.
     *
     * @param array<mixed> $values
     */
    public function saveFixture(int $id, string $name, array $values): void
    {
        $name = trim(Text::line($name));
        if ($name === '' || Text::length($name) > 100) {
            throw new RequestException('promptlib_fixture_name', 'A fixture needs a name of 1 to 100 characters.');
        }
        $fixtures = $this->templates->fixtures($id);
        $limit = max(0, (int) apply_filters('promptlib_fixture_limit', 50));
        if (!isset($fixtures[$name]) && count($fixtures) >= $limit) {
            throw new RequestException('promptlib_fixture_limit', sprintf('A template may hold at most %d fixtures.', $limit));
        }
        $fixtures[$name] = $this->checkedValues($id, $values);
        $this->templates->saveFixtures($id, $fixtures);
    }

    public function deleteFixture(int $id, string $name): void
    {
        $fixtures = $this->templates->fixtures($id);
        unset($fixtures[$name]);
        $this->templates->saveFixtures($id, $fixtures);
    }

    /**
     * Values filtered to declared names and checked against their types
     * and lengths.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function checkedValues(int $id, array $values): array
    {
        $draft = $this->templates->draft($id);
        $problems = [];
        $out = [];
        foreach ((array) ($draft['variables'] ?? []) as $index => $raw) {
            [$spec] = VariableSpec::parse($raw, (int) $index);
            if ($spec === null || $spec->name() === '' || !array_key_exists($spec->name(), $values) || $values[$spec->name()] === null || $values[$spec->name()] === '') {
                continue;
            }
            $typed = ValueFormatter::typed($spec, $values[$spec->name()]);
            if (!$typed['ok']) {
                $problems[] = Issue::error('fixture_value', $typed['problem'], 'variable ' . $spec->name());
                continue;
            }
            $max = $spec->maxLength();
            $measured = ValueFormatter::measured($spec, ValueFormatter::clean($spec, $typed['text']), $typed['value']);
            if ($max !== null && Text::length($measured) > $max) {
                $problems[] = Issue::error('fixture_value', sprintf('is longer than max_length %d', $max), 'variable ' . $spec->name());
                continue;
            }
            $out[$spec->name()] = $values[$spec->name()];
        }
        if ($problems !== []) {
            throw new ValidationException($problems, 'promptlib_fixture_invalid');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<Issue>
     */
    private function assertSavable(array $draft): array
    {
        $issues = Definition::validate($draft, self::limits());
        $blocking = array_values(array_filter($issues, [Definition::class, 'blocksSave']));
        if ($blocking !== []) {
            throw new ValidationException($blocking, 'promptlib_unsavable');
        }

        return $issues;
    }

    private function assertSlugFree(string $slug, int $exceptId): void
    {
        $found = $this->templates->locate($slug);
        if ($found !== null && $found['id'] !== $exceptId) {
            $other = $this->templates->post($found['id']);
            $title = $other !== null ? $other->post_title : '#' . $found['id'];
            $where = $found['status'] === 'trash' ? ' (in the trash)' : '';
            throw new RequestException('promptlib_slug_taken', sprintf('The slug "%s" is already used by the template "%s"%s.', $slug, $title, $where), 409, ['template_id' => $found['id']]);
        }
    }
}
