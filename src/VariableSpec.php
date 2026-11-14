<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * One declared variable of a template (case 14). Immutable.
 */
final class VariableSpec
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    public const TYPES = ['string', 'text', 'integer', 'number', 'boolean', 'enum', 'list', 'json'];
    public const ESCAPES = ['plain', 'raw', 'line', 'json', 'fenced'];
    public const DEFAULT_MAX_LENGTH = ['string' => 2000, 'text' => 50000];
    private const KEYS = ['name', 'type', 'required', 'default', 'max_length', 'escape', 'enum', 'description'];

    /**
     * @param list<string> $enum
     * @param mixed $default
     */
    private function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly bool $required,
        private readonly mixed $default,
        private readonly bool $hasDefault,
        private readonly ?int $maxLength,
        private readonly string $escape,
        private readonly array $enum,
        private readonly string $description,
    ) {
    }

    /**
     * Parse one declaration. Returns the spec (null when it cannot be built)
     * and every problem found. Default-value checks are left to the validator,
     * which has the formatter.
     *
     * @param mixed $raw
     * @return array{0: ?VariableSpec, 1: list<Issue>}
     */
    public static function parse($raw, int $index): array
    {
        $where = sprintf('variable %d', $index + 1);
        if (!is_array($raw)) {
            return [null, [Issue::error('variable_shape', 'Each variable must be an object.', $where)]];
        }
        $issues = [];
        $name = $raw['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $issues[] = Issue::error('variable_name', 'Variable names start with a lower-case letter and use only a to z, 0 to 9 and underscores, up to 64 characters.', $where);
            $name = is_string($name) ? $name : '';
        } else {
            $where = 'variable ' . $name;
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $issues[] = Issue::error('variable_key', sprintf('Unknown key "%s". Escaping is declared once per variable with "escape".', (string) $key), $where);
            }
        }
        $type = $raw['type'] ?? 'string';
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $issues[] = Issue::error('variable_type', 'Unknown type. Use one of: ' . implode(', ', self::TYPES) . '.', $where);
            $type = 'string';
        }
        $required = $raw['required'] ?? true;
        if (!is_bool($required)) {
            $issues[] = Issue::error('variable_required', '"required" must be true or false.', $where);
            $required = true;
        }
        $escape = $raw['escape'] ?? 'plain';
        if (!is_string($escape) || !in_array($escape, self::ESCAPES, true)) {
            $issues[] = Issue::error('variable_escape', 'Unknown escape mode. Use one of: ' . implode(', ', self::ESCAPES) . '.', $where);
            $escape = 'plain';
        }
        $maxLength = $raw['max_length'] ?? null;
        if ($maxLength !== null && (!is_int($maxLength) || $maxLength < 1)) {
            $issues[] = Issue::error('variable_max_length', '"max_length" must be a positive whole number.', $where);
            $maxLength = null;
        }
        $enum = $raw['enum'] ?? [];
        if (!is_array($enum) || !array_is_list($enum)) {
            $issues[] = Issue::error('variable_enum', '"enum" must be a list of strings.', $where);
            $enum = [];
        }
        $cleanEnum = [];
        foreach ($enum as $option) {
            if (!is_string($option) || $option === '') {
                $issues[] = Issue::error('variable_enum', '"enum" must be a list of non-empty strings.', $where);
                continue;
            }
            $cleanEnum[] = $option;
        }
        if ($type === 'enum' && $cleanEnum === []) {
            $issues[] = Issue::error('variable_enum', 'An enum variable needs at least one value.', $where);
        }
        if ($type !== 'enum' && $cleanEnum !== []) {
            $issues[] = Issue::error('variable_enum', '"enum" is only allowed on enum variables.', $where);
        }
        if (count($cleanEnum) !== count(array_unique($cleanEnum))) {
            $issues[] = Issue::error('variable_enum', 'Enum values must be unique.', $where);
        }
        $description = $raw['description'] ?? '';
        if (!is_string($description)) {
            $issues[] = Issue::error('variable_description', '"description" must be text.', $where);
            $description = '';
        }
        $hasDefault = array_key_exists('default', $raw) && $raw['default'] !== null;
        if ($required && $hasDefault) {
            $issues[] = Issue::error('variable_default_required', 'A required variable cannot have a default; it would never be used.', $where);
        }
        if ($escape === 'raw') {
            $issues[] = trim($description) === ''
                ? Issue::error('variable_raw', 'The raw escape mode needs a description that says why.', $where)
                : Issue::warning('variable_raw', 'Raw values reach the model unchanged. Prefer plain, line or fenced.', $where);
        }

        $spec = new self(
            $name,
            $type,
            $required,
            $hasDefault ? $raw['default'] : null,
            $hasDefault,
            $maxLength ?? (self::DEFAULT_MAX_LENGTH[$type] ?? null),
            $escape,
            $cleanEnum,
            $description,
        );

        return [$spec, $issues];
    }

    /**
     * Build a spec or throw.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        [$spec, $issues] = self::parse($raw, 0);
        $errors = Issue::errorsOf($issues);
        if ($spec === null || $errors !== []) {
            throw new Exception\ValidationException($errors !== [] ? $errors : [Issue::error('variable_shape', 'Invalid variable.')]);
        }

        return $spec;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function required(): bool
    {
        return $this->required;
    }

    /** @return mixed */
    public function default()
    {
        return $this->default;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    public function escape(): string
    {
        return $this->escape;
    }

    /** @return list<string> */
    public function enum(): array
    {
        return $this->enum;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * Canonical form, every key present, used for hashing and storage.
     *
     * @return array{name: string, type: string, required: bool, default: mixed, max_length: ?int, escape: string, enum: list<string>, description: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'max_length' => $this->maxLength,
            'escape' => $this->escape,
            'enum' => $this->enum,
            'description' => $this->description,
        ];
    }
}
