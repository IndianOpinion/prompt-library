<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

use IndianOpinion\PromptLibrary\Exception\InvalidVariableException;
use IndianOpinion\PromptLibrary\Exception\MissingVariableException;
use IndianOpinion\PromptLibrary\Exception\RenderTooLargeException;
use IndianOpinion\PromptLibrary\Exception\UnknownVariableException;

/**
 * Renders a definition with values (cases 29 to 36). No WordPress, no
 * database: consumers can use it in their own unit tests.
 *
 * Order of checks: every supplied value's type; defaults; required values;
 * lengths; escape; substitute; total size. Each stage reports every problem
 * it finds, and nothing partial is ever returned.
 */
final class Renderer
{
    public const DEFAULT_MAX_RENDER_BYTES = 400000;

    public function __construct(private readonly int $maxRenderBytes = self::DEFAULT_MAX_RENDER_BYTES)
    {
    }

    /**
     * Render without a stored version, from a raw definition array.
     *
     * @param array<mixed> $definition
     * @param array<string, mixed> $values
     * @param array{strict?: bool} $options
     */
    public static function renderArray(array $definition, array $values, array $options = [], int $maxRenderBytes = self::DEFAULT_MAX_RENDER_BYTES): RenderedPrompt
    {
        $parsed = self::parseOrThrow($definition);

        return (new self($maxRenderBytes))->render($parsed, $values, $options);
    }

    /**
     * Build a Definition, throwing TemplateSyntaxException for syntax and
     * placeholder errors and ValidationException for everything else.
     *
     * @param array<mixed> $definition
     */
    public static function parseOrThrow(array $definition): Definition
    {
        $issues = Issue::errorsOf(Definition::validate($definition, ['require_slug' => false]));
        $syntax = array_values(array_filter($issues, static fn (Issue $i): bool => in_array($i->code, ['syntax_placeholder', 'syntax_unclosed', 'undeclared'], true)));
        if ($syntax !== []) {
            throw new Exception\TemplateSyntaxException($syntax);
        }

        return Definition::fromArray($definition, ['require_slug' => false]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array{strict?: bool} $options
     * @param array{slug?: string, version?: int, content_hash?: string, from_fallback?: bool} $meta
     */
    public function render(Definition $definition, array $values, array $options = [], array $meta = []): RenderedPrompt
    {
        $warnings = [];
        $declared = [];
        foreach ($definition->variables() as $spec) {
            $declared[$spec->name()] = $spec;
        }

        $unknown = [];
        foreach (array_keys($values) as $key) {
            if (!isset($declared[(string) $key])) {
                $unknown[] = (string) $key;
            }
        }
        if ($unknown !== []) {
            if (($options['strict'] ?? false) === true) {
                throw new UnknownVariableException($unknown);
            }
            $warnings[] = 'Unknown variables ignored: ' . implode(', ', $unknown) . '.';
        }

        // Stage 1: types of supplied values.
        $typed = [];
        $problems = [];
        foreach ($declared as $name => $spec) {
            if (!array_key_exists($name, $values) || $values[$name] === null) {
                continue;
            }
            $result = ValueFormatter::typed($spec, $values[$name]);
            if (!$result['ok']) {
                $problems[$name] = $result['problem'];
                continue;
            }
            $typed[$name] = $result;
        }
        if ($problems !== []) {
            throw new InvalidVariableException($problems);
        }

        // Stage 2 and 3: defaults, then required values.
        $missing = [];
        foreach ($declared as $name => $spec) {
            if (isset($typed[$name])) {
                continue;
            }
            if ($spec->hasDefault()) {
                $result = ValueFormatter::typed($spec, $spec->default());
                if (!$result['ok']) {
                    $problems[$name] = 'default ' . $result['problem'];
                    continue;
                }
                $typed[$name] = $result;
                continue;
            }
            if ($spec->required()) {
                $missing[] = $name;
                continue;
            }
            $typed[$name] = ['ok' => true, 'value' => null, 'text' => '', 'problem' => '', 'absent' => true];
        }
        if ($missing !== []) {
            throw new MissingVariableException($missing);
        }
        if ($problems !== []) {
            throw new InvalidVariableException($problems);
        }

        // Stage 4: lengths, on the cleaned text.
        $cleaned = [];
        foreach ($typed as $name => $result) {
            $spec = $declared[$name];
            if (isset($result['absent'])) {
                $cleaned[$name] = null;
                continue;
            }
            $clean = ValueFormatter::clean($spec, $result['text']);
            $max = $spec->maxLength();
            if ($max !== null) {
                $length = Text::length(ValueFormatter::measured($spec, $clean, $result['value']));
                if ($length > $max) {
                    $problems[$name] = sprintf('is %d characters; max_length is %d (values are never truncated)', $length, $max);
                    continue;
                }
            }
            $cleaned[$name] = $clean;
        }
        if ($problems !== []) {
            throw new InvalidVariableException($problems);
        }

        // Stage 5: escape (fence or JSON-encode). An absent optional value is an empty string.
        $final = [];
        foreach ($cleaned as $name => $clean) {
            $final[$name] = $clean === null ? '' : ValueFormatter::finish($declared[$name], $clean, $typed[$name]['value']);
        }

        // Stage 6: substitute, one pass. Values are never scanned again.
        $system = self::substitute($definition->parsedPart('system'), $final);
        $user = self::substitute($definition->parsedPart('user'), $final);

        // Stage 7: total size.
        $bytes = strlen($system) + strlen($user);
        if ($bytes > $this->maxRenderBytes) {
            throw new RenderTooLargeException($bytes, $this->maxRenderBytes);
        }

        return new RenderedPrompt(
            $meta['slug'] ?? $definition->slug(),
            $meta['version'] ?? 0,
            $meta['from_fallback'] ?? false,
            $system,
            $user,
            $meta['content_hash'] ?? $definition->contentHash(),
            $warnings,
            $definition->output(),
            $definition->settings(),
        );
    }

    /** @param array<string, string> $values */
    private static function substitute(ParsedPart $part, array $values): string
    {
        $out = '';
        foreach ($part->tokens as $token) {
            $out .= $token->isVariable ? ($values[$token->value] ?? '') : $token->value;
        }

        return $out;
    }
}
