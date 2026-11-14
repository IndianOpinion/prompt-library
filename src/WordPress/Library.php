<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\CheckResult;
use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Exception\IntegrityException;
use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Exception\StorageException;
use IndianOpinion\PromptLibrary\Exception\TemplateNotFoundException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Exception\VersionNotFoundException;
use IndianOpinion\PromptLibrary\RenderedPrompt;
use IndianOpinion\PromptLibrary\Renderer;
use IndianOpinion\PromptLibrary\TemplateVersion;

/**
 * The consumer contract (section 5.1): resolve, fetch, check and render.
 *
 * An uncached render costs at most two queries: locate (template, status and
 * live pointer in one) and the version row. Versions are immutable and cached
 * for 12 hours; the pointer for 60 seconds.
 */
class Library
{
    public const VERSION_TTL = 43200;
    public const INTEGRITY_OPTION = 'promptlib_integrity_errors';

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly Consumers $consumers,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @param array{strict?: bool, fallback?: array<mixed>, consumer?: string} $options
     */
    public function render(string $slug, array $values, int|string $version = 'live', array $options = []): RenderedPrompt
    {
        try {
            $resolved = $this->get($slug, $version);
        } catch (TemplateNotFoundException $e) {
            $fallback = $options['fallback'] ?? null;
            if ($fallback === null && $e->reason() === TemplateNotFoundException::MISSING) {
                // A default registered by a consumer stands in until admin_init installs it.
                $fallback = $this->consumers->registeredDefault($slug);
            }
            if (!is_array($fallback)) {
                throw $e;
            }
            $definition = Renderer::parseOrThrow(['slug' => $slug] + $fallback);

            return (new Renderer(self::maxRenderBytes()))->render($definition, $values, $options, [
                'slug' => $slug,
                'version' => 0,
                'from_fallback' => true,
            ]);
        }
        $rendered = (new Renderer(self::maxRenderBytes()))->render($resolved->definition(), $values, $options, [
            'slug' => $slug,
            'version' => $resolved->version(),
            'content_hash' => $resolved->contentHash(),
        ]);
        $this->noteRender($resolved, (string) ($options['consumer'] ?? ''));

        return $rendered;
    }

    public function get(string $slug, int|string $version = 'live'): TemplateVersion
    {
        $found = $this->templates->locate($slug);
        if ($found === null) {
            throw new TemplateNotFoundException($slug, TemplateNotFoundException::MISSING);
        }
        if ($found['status'] === 'trash') {
            throw new TemplateNotFoundException($slug, TemplateNotFoundException::TRASHED);
        }
        $number = $this->selectorToNumber($slug, $found, $version);

        return $this->load($found['id'], $slug, $number, $found['live']);
    }

    public function exists(string $slug): bool
    {
        $found = $this->templates->locate($slug);

        return $found !== null && $found['status'] !== 'trash' && $found['live'] > 0;
    }

    /** @param list<string> $supplied */
    public function check(string $slug, array $supplied, int|string $version = 'live'): CheckResult
    {
        return CheckResult::of($this->get($slug, $version)->definition(), array_values(array_map('strval', $supplied)));
    }

    /**
     * Load one version by template id and number, from cache or storage,
     * with the integrity check (case 89).
     */
    public function load(int $templateId, string $slug, int $number, int $live): TemplateVersion
    {
        $key = 'v:' . $templateId . ':' . $number;
        $cached = wp_cache_get($key, TemplateRepository::CACHE_GROUP);
        if ($cached instanceof TemplateVersion) {
            return $cached->withLive($number === $live);
        }
        $row = $this->versions->find($templateId, $number);
        if ($row === null) {
            throw new VersionNotFoundException($slug, (string) $number, $this->versions->highestNumber($templateId));
        }
        $version = $this->fromRow($row, $slug, $live);
        wp_cache_set($key, $version, TemplateRepository::CACHE_GROUP, self::VERSION_TTL);

        return $version;
    }

    /** @param array<string, mixed> $row */
    public function fromRow(array $row, string $slug, int $live): TemplateVersion
    {
        $number = (int) $row['version_no'];
        $decoded = json_decode((string) $row['definition'], true);
        if (!is_array($decoded)) {
            $this->integrityFailure($slug, $number, 'the stored definition does not decode');
        }
        try {
            $definition = Definition::fromArray($decoded, ['max_part_bytes' => PHP_INT_MAX]);
        } catch (ValidationException $e) {
            $this->integrityFailure($slug, $number, 'the stored definition no longer validates');
        }
        if (!hash_equals((string) $row['content_hash'], $definition->contentHash())) {
            $this->integrityFailure($slug, $number, 'the content hash does not match');
        }

        return new TemplateVersion(
            (int) $row['template_id'],
            $number,
            $definition,
            (string) $row['content_hash'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            (int) $row['user_id'],
            (string) ($row['note'] ?? ''),
            $number === $live,
        );
    }

    /**
     * @param array{id: int, status: string, live: int} $found
     */
    private function selectorToNumber(string $slug, array $found, int|string $version): int
    {
        if (is_string($version) && preg_match('/^[1-9][0-9]*$/', $version) === 1) {
            $version = (int) $version;
        }
        if ($version === 'live') {
            if ($found['live'] > 0) {
                return $found['live'];
            }
            if ($this->versions->highestNumber($found['id']) === 0) {
                throw new TemplateNotFoundException($slug, TemplateNotFoundException::UNPUBLISHED);
            }
            throw new StorageException(sprintf('Prompt template "%s" has versions but no live pointer.', $slug));
        }
        if ($version === 'latest') {
            $highest = $this->versions->highestNumber($found['id']);
            if ($highest === 0) {
                throw new TemplateNotFoundException($slug, TemplateNotFoundException::UNPUBLISHED);
            }
            return $highest;
        }
        if (is_int($version) && $version > 0) {
            if ($found['live'] === 0 && $this->versions->highestNumber($found['id']) === 0) {
                throw new TemplateNotFoundException($slug, TemplateNotFoundException::UNPUBLISHED);
            }
            return $version;
        }

        throw new VersionNotFoundException($slug, (string) $version, $this->versions->highestNumber($found['id']));
    }

    /**
     * @return never
     */
    private function integrityFailure(string $slug, int $number, string $detail): void
    {
        $this->logger->throttled('integrity:' . $slug . ':' . $number, 3600, 'error', 'Prompt template version failed its integrity check.', [
            'slug' => $slug,
            'version' => $number,
            'detail' => $detail,
        ]);
        $errors = get_option(self::INTEGRITY_OPTION, []);
        $errors = is_array($errors) ? $errors : [];
        $key = $slug . '#' . $number;
        if (!isset($errors[$key])) {
            $errors[$key] = ['slug' => $slug, 'version' => $number, 'detail' => $detail, 'time' => $this->clock->timestamp()];
            update_option(self::INTEGRITY_OPTION, array_slice($errors, -20, null, true), false);
        }
        throw new IntegrityException($slug, $number, $detail);
    }

    /**
     * Case 99: remember when a version was last rendered for a consumer, at
     * most once an hour per version and consumer id (transient throttle).
     */
    private function noteRender(TemplateVersion $version, string $consumer): void
    {
        $consumer = substr(sanitize_key($consumer), 0, 64);
        $throttle = 'promptlib_seen_' . md5($version->templateId() . ':' . $version->version() . ':' . $consumer);
        if (get_transient($throttle) !== false) {
            return;
        }
        set_transient($throttle, 1, HOUR_IN_SECONDS);
        $data = $this->templates->lastRendered($version->templateId());
        $now = $this->clock->timestamp();
        $entry = $data[$version->version()] ?? ['time' => 0, 'consumers' => []];
        $entry['time'] = $now;
        $entry['consumers'][$consumer === '' ? '(none)' : $consumer] = $now;
        arsort($entry['consumers']);
        $entry['consumers'] = array_slice($entry['consumers'], 0, 20, true);
        $data[$version->version()] = $entry;
        $this->templates->saveLastRendered($version->templateId(), $data);
    }

    public static function maxRenderBytes(): int
    {
        return max(1, (int) apply_filters('promptlib_max_render_bytes', Renderer::DEFAULT_MAX_RENDER_BYTES));
    }

    public static function maxPartBytes(): int
    {
        return max(1, (int) apply_filters('promptlib_max_part_bytes', Definition::DEFAULT_MAX_PART_BYTES));
    }

    /**
     * The soft-dependency filter callback (section 5.3).
     *
     * @param mixed $previous
     * @param mixed $slug
     * @param mixed $values
     * @param mixed $version
     * @param mixed $options
     * @return mixed
     */
    public function filterRender($previous, $slug = '', $values = [], $version = 'live', $options = [])
    {
        if ($previous !== null) {
            return $previous;
        }
        try {
            if (!is_string($slug) || !is_array($values) || !(is_int($version) || is_string($version)) || !is_array($options)) {
                throw new \InvalidArgumentException('promptlib_render expects a slug, a values array, a version and an options array.');
            }
            return $this->render($slug, $values, $version, $options)->toArray();
        } catch (PromptLibraryException $e) {
            return new \WP_Error($e->errorCode(), $e->getMessage(), $e->data());
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('promptlib_invalid_call', $e->getMessage());
        }
    }
}
