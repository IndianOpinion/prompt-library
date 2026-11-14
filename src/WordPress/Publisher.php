<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Exception\ConfirmationRequiredException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Text;

/**
 * Publishing immutable versions and moving the live pointer (cases 38 to 42, 49).
 */
class Publisher
{
    public const NOTE_MAX = 500;

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly Consumers $consumers,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Publish the current draft as the next version.
     *
     * @return array{version: int, live: bool, content_hash: string}
     */
    public function publish(int $id, string $note, bool $makeLive, bool $confirmed, int $userId): array
    {
        $post = $this->templates->post($id);
        if ($post === null || $post->post_status === 'trash') {
            throw new RequestException('promptlib_not_found', 'The template does not exist or is in the trash.', 404);
        }
        $slug = $this->templates->slugOf($id);
        $draft = $this->templates->draft($id);
        $draft['slug'] = $slug;

        return $this->publishDefinition($id, $slug, $draft, $note !== '' ? $note : (string) ($draft['note'] ?? ''), $makeLive, $confirmed, $userId);
    }

    /**
     * Publish a given definition (used by publish, register-default and CLI publish-from-file).
     *
     * @param array<mixed> $raw
     * @return array{version: int, live: bool, content_hash: string}
     */
    public function publishDefinition(int $id, string $slug, array $raw, string $note, bool $makeLive, bool $confirmed, int $userId): array
    {
        $note = self::checkNote($note);
        $raw['slug'] = $slug;
        $definition = Definition::fromArray($raw, Drafts::limits())->withNote($note);
        $hash = $definition->contentHash();
        $highest = $this->versions->highestNumber($id);
        if ($highest > 0) {
            $latest = $this->versions->find($id, $highest);
            if ($latest !== null && hash_equals((string) $latest['content_hash'], $hash)) {
                throw new RequestException('promptlib_no_changes', sprintf('No changes since version %d.', $highest), 409);
            }
        }
        if ($makeLive && !$confirmed) {
            $failing = $this->consumers->failing($slug, $definition);
            if ($failing !== []) {
                throw new ConfirmationRequiredException($failing);
            }
        }
        $json = Text::json($definition->toArray());
        $now = $this->clock->nowUtc();
        $number = $highest + 1;
        if (!$this->versions->insertVersion($id, $slug, $number, $json, $hash, $note, $userId, $now)) {
            // Someone published at the same moment: retry once with the next free number.
            $number = $this->versions->highestNumber($id) + 1;
            if (!$this->versions->insertVersion($id, $slug, $number, $json, $hash, $note, $userId, $now)) {
                throw new RequestException('promptlib_conflict', 'Another version was published at the same moment. Reload and try again.', 409);
            }
        }
        do_action('promptlib_version_published', $id, $slug, $number, $userId);
        $this->logger->info('Version published.', ['template_id' => $id, 'slug' => $slug, 'version' => $number, 'user_id' => $userId]);
        if ($makeLive) {
            $this->movePointer($id, $slug, $number, 'Published as version ' . $number . '.', $userId);
        }

        return ['version' => $number, 'live' => $makeLive, 'content_hash' => $hash];
    }

    /**
     * Make an existing version live: the rollback (case 42). Creates no version.
     */
    public function setLive(int $id, int $number, string $reason, bool $confirmed, int $userId): void
    {
        $post = $this->templates->post($id);
        if ($post === null || $post->post_status === 'trash') {
            throw new RequestException('promptlib_not_found', 'The template does not exist or is in the trash.', 404);
        }
        $slug = $this->templates->slugOf($id);
        $row = $this->versions->find($id, $number);
        if ($row === null) {
            throw new RequestException('promptlib_version_not_found', sprintf('Version %d does not exist; the highest is %d.', $number, $this->versions->highestNumber($id)), 404);
        }
        if ($this->templates->live($id) === $number) {
            throw new RequestException('promptlib_already_live', sprintf('Version %d is already live.', $number), 409);
        }
        if (!$confirmed) {
            $definition = json_decode((string) $row['definition'], true);
            if (is_array($definition)) {
                $failing = $this->consumers->failing($slug, Definition::fromArray($definition, ['max_part_bytes' => PHP_INT_MAX]));
                if ($failing !== []) {
                    throw new ConfirmationRequiredException($failing);
                }
            }
        }
        $reason = trim(Text::line($reason));
        $this->movePointer($id, $slug, $number, $reason, $userId);
    }

    private function movePointer(int $id, string $slug, int $to, string $reason, int $userId): void
    {
        $from = $this->templates->live($id);
        $this->templates->setLive($id, $to);
        $this->versions->insertEvent($id, $slug, 'live', $from > 0 ? $from : null, $to, $reason, $userId, $this->clock->nowUtc());
        do_action('promptlib_live_changed', $id, $slug, $from > 0 ? $from : null, $to, $userId);
        $this->logger->info('Live version changed.', ['template_id' => $id, 'slug' => $slug, 'from' => $from, 'to' => $to, 'user_id' => $userId]);
    }

    /** One line, required, at most 500 characters (case 38). */
    public static function checkNote(string $note): string
    {
        $note = Text::normaliseNewlines($note);
        $trimmed = trim($note);
        if ($trimmed === '') {
            throw new ValidationException([Issue::error('note_required', 'A version note is required: one line that says what changed.', 'note')], 'promptlib_note');
        }
        if (str_contains($trimmed, "\n")) {
            throw new ValidationException([Issue::error('note_line', 'The version note must be one line.', 'note')], 'promptlib_note');
        }
        if (Text::length($trimmed) > self::NOTE_MAX) {
            throw new ValidationException([Issue::error('note_length', sprintf('The version note may be at most %d characters.', self::NOTE_MAX), 'note')], 'promptlib_note');
        }

        return Text::plain($trimmed);
    }
}
