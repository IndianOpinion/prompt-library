<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

use IndianOpinion\PromptLibrary\Exception\ValidationException;

/**
 * A validated template definition (section 5.4). Immutable.
 *
 * Build one with fromArray(), which throws ValidationException with every
 * error. Use validate() to get errors and warnings without throwing, for an
 * editor that may save broken work.
 */
final class Definition
{
    public const FORMAT = 'prompt-library/1';
    public const DEFAULT_MAX_PART_BYTES = 200000;

    /** @var array<string, ParsedPart> parsed parts, kept so repeated renders do not re-scan */
    private array $parsed = [];

    private ?string $hash = null;

    /**
     * @param list<VariableSpec> $variables
     * @param array{temperature: ?float, token_cap: ?int} $settings
     * @param list<Issue> $warnings
     */
    private function __construct(
        private readonly string $slug,
        private readonly string $title,
        private readonly string $description,
        private readonly string $system,
        private readonly string $user,
        private readonly array $variables,
        private readonly OutputSpec $output,
        private readonly array $settings,
        private readonly string $note,
        private readonly array $warnings,
    ) {
    }

    /**
     * Validate and build. Line endings in the parts are normalised to LF.
     *
     * @param array<mixed> $raw
     * @param array{max_part_bytes?: int, max_token_cap?: int, require_slug?: bool} $limits
     * @throws ValidationException
     */
    public static function fromArray(array $raw, array $limits = []): self
    {
        $issues = self::validate($raw, $limits);
        $errors = Issue::errorsOf($issues);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $variables = [];
        foreach ((array) ($raw['variables'] ?? []) as $index => $item) {
            [$spec] = VariableSpec::parse($item, (int) $index);
            if ($spec !== null) {
                $variables[] = $spec;
            }
        }
        [$output] = OutputSpec::parse($raw['output'] ?? null);

        return new self(
            Slug::normalise((string) ($raw['slug'] ?? '')),
            (string) ($raw['title'] ?? ''),
            (string) ($raw['description'] ?? ''),
            Text::normaliseNewlines((string) ($raw['system'] ?? '')),
            Text::normaliseNewlines((string) ($raw['user'] ?? '')),
            $variables,
            $output,
            self::settingsOf($raw['settings'] ?? null),
            (string) ($raw['note'] ?? ''),
            Issue::warningsOf($issues),
        );
    }

    /**
     * Every error and warning in a raw definition.
     *
     * Errors with code utf8, part_size, part_type and slug block even saving
     * a draft (see blocksSave()); every other error blocks publishing only.
     *
     * @param array<mixed> $raw
     * @param array{max_part_bytes?: int, max_token_cap?: int, require_slug?: bool} $limits
     * @return list<Issue>
     */
    public static function validate(array $raw, array $limits = []): array
    {
        $issues = [];
        $maxBytes = $limits['max_part_bytes'] ?? self::DEFAULT_MAX_PART_BYTES;
        if (isset($raw['format']) && $raw['format'] !== self::FORMAT) {
            $issues[] = Issue::error('format', sprintf('Unknown format "%s"; expected %s.', is_string($raw['format']) ? $raw['format'] : '?', self::FORMAT));
        }
        if (($limits['require_slug'] ?? true) || isset($raw['slug'])) {
            $slug = $raw['slug'] ?? '';
            $problem = is_string($slug) ? Slug::problem(Slug::normalise($slug)) : 'The slug must be text.';
            if ($problem !== null) {
                $issues[] = Issue::error('slug', $problem, 'slug');
            }
        }
        foreach (['title', 'description', 'note'] as $key) {
            if (isset($raw[$key]) && !is_string($raw[$key])) {
                $issues[] = Issue::error('field_type', sprintf('"%s" must be text.', $key), $key);
            }
        }

        $parsed = [];
        foreach (['system', 'user'] as $part) {
            $text = $raw[$part] ?? '';
            if (!is_string($text)) {
                $issues[] = Issue::error('part_type', 'The part must be text.', $part);
                continue;
            }
            $offset = Text::invalidUtf8Offset($text);
            if ($offset !== null) {
                $issues[] = Issue::error('utf8', sprintf('The text is not valid UTF-8 at byte %d.', $offset), $part);
                continue;
            }
            $text = Text::normaliseNewlines($text);
            if (strlen($text) > $maxBytes) {
                $issues[] = Issue::error('part_size', sprintf('The part is %d bytes; the limit is %d.', strlen($text), $maxBytes), $part);
                continue;
            }
            $parsed[$part] = Parser::analyse($text, $part);
            array_push($issues, ...$parsed[$part]->issues);
        }
        if (isset($parsed['user']) && trim((string) ($raw['user'] ?? '')) === '') {
            $issues[] = Issue::error('user_empty', 'The user part may not be empty.', 'user');
        }

        $declared = [];
        $variables = $raw['variables'] ?? [];
        if (!is_array($variables) || !array_is_list($variables)) {
            $issues[] = Issue::error('variables_shape', 'Variables must be a list.', 'variables');
            $variables = [];
        }
        foreach ($variables as $index => $item) {
            [$spec, $found] = VariableSpec::parse($item, $index);
            array_push($issues, ...$found);
            if ($spec === null || $spec->name() === '') {
                continue;
            }
            if (isset($declared[$spec->name()])) {
                $issues[] = Issue::error('variable_duplicate', 'The name is declared twice.', 'variable ' . $spec->name());
                continue;
            }
            $declared[$spec->name()] = $spec;
            if ($spec->hasDefault()) {
                $problem = self::defaultProblem($spec);
                if ($problem !== null) {
                    $issues[] = Issue::error('variable_default', 'The default ' . $problem . '.', 'variable ' . $spec->name());
                }
            }
        }

        $used = [];
        foreach ($parsed as $part => $parsedPart) {
            foreach ($parsedPart->variables() as $token) {
                $used[$token->value] = true;
                if (!isset($declared[$token->value])) {
                    [$line, $column] = $parsedPart->position($token);
                    $issues[] = Issue::error('undeclared', sprintf('The placeholder "%s" is not a declared variable.', $token->value), $part, $line, $column);
                }
            }
        }
        foreach ($declared as $name => $spec) {
            if (!isset($used[$name])) {
                $issues[] = Issue::warning('unused', 'The variable is declared but used in neither part.', 'variable ' . $name);
            }
        }

        [, $outputIssues] = OutputSpec::parse($raw['output'] ?? null);
        array_push($issues, ...$outputIssues);
        array_push($issues, ...self::settingsIssues($raw['settings'] ?? null, $limits['max_token_cap'] ?? null));

        return $issues;
    }

    /**
     * True when an issue stops a draft from being saved at all.
     */
    public static function blocksSave(Issue $issue): bool
    {
        return $issue->isError() && in_array($issue->code, ['utf8', 'part_size', 'part_type', 'slug', 'field_type'], true);
    }

    private static function defaultProblem(VariableSpec $spec): ?string
    {
        $typed = ValueFormatter::typed($spec, $spec->default());
        if (!$typed['ok']) {
            return $typed['problem'];
        }
        $max = $spec->maxLength();
        if ($max !== null) {
            $cleaned = ValueFormatter::clean($spec, $typed['text']);
            if (Text::length(ValueFormatter::measured($spec, $cleaned, $typed['value'])) > $max) {
                return sprintf('is longer than max_length %d', $max);
            }
        }

        return null;
    }

    /**
     * @param mixed $raw
     * @return list<Issue>
     */
    private static function settingsIssues($raw, ?int $maxTokenCap): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            return [Issue::error('settings_shape', 'Settings must be an object.', 'settings')];
        }
        $issues = [];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['temperature', 'token_cap'], true)) {
                $issues[] = Issue::error('settings_key', sprintf('Unknown setting "%s".', (string) $key), 'settings');
            }
        }
        $temperature = $raw['temperature'] ?? null;
        if ($temperature !== null && (!(is_int($temperature) || is_float($temperature)) || $temperature < 0 || $temperature > 2)) {
            $issues[] = Issue::error('settings_temperature', 'Temperature must be a number from 0 to 2, or empty.', 'settings');
        }
        $cap = $raw['token_cap'] ?? null;
        if ($cap !== null && (!is_int($cap) || $cap < 1)) {
            $issues[] = Issue::error('settings_token_cap', 'The output token cap must be a positive whole number, or empty.', 'settings');
        } elseif (is_int($cap) && $maxTokenCap !== null && $cap > $maxTokenCap) {
            $issues[] = Issue::error('settings_token_cap', sprintf('The output token cap may not exceed the site maximum of %d.', $maxTokenCap), 'settings');
        }

        return $issues;
    }

    /**
     * @param mixed $raw
     * @return array{temperature: ?float, token_cap: ?int}
     */
    private static function settingsOf($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $temperature = $raw['temperature'] ?? null;

        return [
            'temperature' => is_int($temperature) || is_float($temperature) ? (float) $temperature : null,
            'token_cap' => is_int($raw['token_cap'] ?? null) ? $raw['token_cap'] : null,
        ];
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function system(): string
    {
        return $this->system;
    }

    /**
     * The parsed system or user part. Throws TemplateSyntaxException.
     */
    public function parsedPart(string $part): ParsedPart
    {
        return $this->parsed[$part] ??= Parser::parse($part === 'system' ? $this->system : $this->user, $part);
    }

    public function user(): string
    {
        return $this->user;
    }

    /** @return list<VariableSpec> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function variable(string $name): ?VariableSpec
    {
        foreach ($this->variables as $spec) {
            if ($spec->name() === $name) {
                return $spec;
            }
        }

        return null;
    }

    public function output(): OutputSpec
    {
        return $this->output;
    }

    /** @return array{temperature: ?float, token_cap: ?int} */
    public function settings(): array
    {
        return $this->settings;
    }

    public function note(): string
    {
        return $this->note;
    }

    /** @return list<Issue> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The hashed content: system, user, variables, output and settings.
     *
     * @return array{system: string, user: string, variables: list<array<string, mixed>>, output: array<string, mixed>, settings: array{temperature: ?float, token_cap: ?int}}
     */
    public function hashedContent(): array
    {
        return [
            'system' => $this->system,
            'user' => $this->user,
            'variables' => array_map(static fn (VariableSpec $v): array => $v->toArray(), $this->variables),
            'output' => $this->output->toArray(),
            'settings' => $this->settings,
        ];
    }

    /** SHA-256 of the canonical JSON of the hashed content. */
    public function contentHash(): string
    {
        return $this->hash ??= CanonicalJson::hash($this->hashedContent());
    }

    /** @return list<string> */
    public function requiredNames(): array
    {
        $names = [];
        foreach ($this->variables as $spec) {
            if ($spec->required()) {
                $names[] = $spec->name();
            }
        }

        return $names;
    }

    /**
     * The full definition array (section 5.4).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'slug' => $this->slug, 'title' => $this->title, 'description' => $this->description]
            + $this->hashedContent()
            + ['note' => $this->note];
    }

    /** A copy with another note. */
    public function withNote(string $note): self
    {
        return new self($this->slug, $this->title, $this->description, $this->system, $this->user, $this->variables, $this->output, $this->settings, $note, $this->warnings);
    }
}
