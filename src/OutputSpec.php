<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * What the template expects back from a model (case 59).
 */
final class OutputSpec
{
    /** @param array<string, mixed>|null $schema */
    private function __construct(private readonly string $mode, private readonly ?array $schema)
    {
    }

    public static function text(): self
    {
        return new self('text', null);
    }

    /** @param array<string, mixed> $schema */
    public static function json(array $schema): self
    {
        return new self('json', $schema);
    }

    /**
     * @param mixed $raw
     * @return array{0: OutputSpec, 1: list<Issue>}
     */
    public static function parse($raw): array
    {
        if ($raw === null || $raw === []) {
            return [self::text(), []];
        }
        if (!is_array($raw)) {
            return [self::text(), [Issue::error('output_shape', 'The output declaration must be an object.', 'output')]];
        }
        $mode = $raw['mode'] ?? 'text';
        if ($mode === 'text') {
            $extra = array_diff(array_keys($raw), ['mode']);
            return [self::text(), $extra === [] ? [] : [Issue::error('output_shape', 'Text output takes no other keys.', 'output')]];
        }
        if ($mode !== 'json') {
            return [self::text(), [Issue::error('output_mode', 'Output mode must be text or json.', 'output')]];
        }
        $schema = $raw['schema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return [self::text(), [Issue::error('output_schema', 'JSON output needs a JSON schema with an object root ("type": "object").', 'output')]];
        }

        return [self::json($schema), []];
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return array<string, mixed>|null */
    public function schema(): ?array
    {
        return $this->schema;
    }

    /** @return array{mode: string, schema?: array<string, mixed>} */
    public function toArray(): array
    {
        return $this->schema === null ? ['mode' => $this->mode] : ['mode' => $this->mode, 'schema' => $this->schema];
    }
}
