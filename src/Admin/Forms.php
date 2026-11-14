<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Admin;

use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\VariableSpec;

/**
 * Turns posted admin forms into drafts and values. Input arrives slashed;
 * every reader unslashes first. Pure functions, easy to test.
 */
final class Forms
{
    private function __construct()
    {
    }

    /**
     * Build a draft from the editor form. JSON fields that do not decode are
     * reported as save-blocking issues and kept as text for the form.
     *
     * @param array<string, mixed> $post already unslashed
     * @return array{draft: array<string, mixed>, issues: list<Issue>, raw: array<string, string>}
     */
    public static function draftFromPost(array $post): array
    {
        $issues = [];
        $raw = [
            'variables' => self::str($post, 'variables', '[]'),
            'output_schema' => self::str($post, 'output_schema', ''),
        ];
        $variables = json_decode($raw['variables'] === '' ? '[]' : $raw['variables'], true);
        if (!is_array($variables)) {
            $issues[] = Issue::error('variables_json', 'The variables field is not a valid JSON list: ' . json_last_error_msg() . '.', 'variables');
            $variables = [];
        }
        $mode = self::str($post, 'output_mode', 'text') === 'json' ? 'json' : 'text';
        $output = ['mode' => 'text'];
        if ($mode === 'json') {
            $schema = json_decode($raw['output_schema'], true);
            if (!is_array($schema)) {
                $issues[] = Issue::error('schema_json', 'The output schema is not valid JSON: ' . json_last_error_msg() . '.', 'output');
                $schema = [];
            }
            $output = ['mode' => 'json', 'schema' => $schema];
        }
        $temperature = trim(self::str($post, 'temperature', ''));
        $tokenCap = trim(self::str($post, 'token_cap', ''));
        $settings = [
            'temperature' => $temperature === '' ? null : (is_numeric($temperature) ? (float) $temperature : $temperature),
            'token_cap' => $tokenCap === '' ? null : (preg_match('/^[0-9]+$/', $tokenCap) === 1 ? (int) $tokenCap : $tokenCap),
        ];

        return [
            'draft' => [
                'slug' => self::str($post, 'slug', ''),
                'title' => self::str($post, 'title', ''),
                'description' => self::str($post, 'description', ''),
                'system' => self::str($post, 'system', ''),
                'user' => self::str($post, 'user', ''),
                'variables' => $variables,
                'output' => $output,
                'settings' => $settings,
                'note' => self::str($post, 'note', ''),
            ],
            'issues' => $issues,
            'raw' => $raw,
        ];
    }

    /**
     * Convert runner form strings to typed values. Empty fields are absent.
     *
     * @param list<VariableSpec> $specs
     * @param array<string, mixed> $input already unslashed
     * @return array<string, mixed>
     */
    public static function valuesFromPost(array $specs, array $input): array
    {
        $values = [];
        foreach ($specs as $spec) {
            $name = $spec->name();
            if (!isset($input[$name]) || !is_string($input[$name]) || $input[$name] === '') {
                continue;
            }
            $text = $input[$name];
            switch ($spec->type()) {
                case 'list':
                    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
                    $values[$name] = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
                    break;
                case 'json':
                    $decoded = json_decode($text, true);
                    $values[$name] = json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
                    break;
                default:
                    $values[$name] = $text;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $post */
    private static function str(array $post, string $key, string $default): string
    {
        return isset($post[$key]) && is_string($post[$key]) ? $post[$key] : $default;
    }
}
