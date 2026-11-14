<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * Line-based diff between two definitions (case 47): per part, plus a table
 * of variable changes. Longest common subsequence; fine for prompt-sized text.
 */
final class Diff
{
    private const MAX_CELLS = 4000000;

    private function __construct()
    {
    }

    /**
     * @return list<array{op: string, line: string}> op is "same", "add" or "remove"
     */
    public static function lines(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $n = count($a);
        $m = count($b);
        if ($n * $m > self::MAX_CELLS) {
            $out = [];
            foreach ($a as $line) {
                $out[] = ['op' => 'remove', 'line' => $line];
            }
            foreach ($b as $line) {
                $out[] = ['op' => 'add', 'line' => $line];
            }
            return $out;
        }
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => 'same', 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $out[] = ['op' => 'remove', 'line' => $a[$i]];
                $i++;
            } else {
                $out[] = ['op' => 'add', 'line' => $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['op' => 'remove', 'line' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['op' => 'add', 'line' => $b[$j]];
        }

        return $out;
    }

    /**
     * Compare two raw definitions (or drafts).
     *
     * @param array<mixed> $old
     * @param array<mixed> $new
     * @return array{system: list<array{op: string, line: string}>, user: list<array{op: string, line: string}>, variables: list<array{name: string, change: string, before: ?array<string, mixed>, after: ?array<string, mixed>}>, output_changed: bool, settings_changed: bool}
     */
    public static function definitions(array $old, array $new): array
    {
        $oldVars = self::variablesByName($old['variables'] ?? []);
        $newVars = self::variablesByName($new['variables'] ?? []);
        $changes = [];
        foreach ($oldVars as $name => $spec) {
            if (!isset($newVars[$name])) {
                $changes[] = ['name' => $name, 'change' => 'removed', 'before' => $spec, 'after' => null];
            } elseif (CanonicalJson::encode($spec) !== CanonicalJson::encode($newVars[$name])) {
                $changes[] = ['name' => $name, 'change' => 'changed', 'before' => $spec, 'after' => $newVars[$name]];
            }
        }
        foreach ($newVars as $name => $spec) {
            if (!isset($oldVars[$name])) {
                $changes[] = ['name' => $name, 'change' => 'added', 'before' => null, 'after' => $spec];
            }
        }

        return [
            'system' => self::lines(self::text($old['system'] ?? ''), self::text($new['system'] ?? '')),
            'user' => self::lines(self::text($old['user'] ?? ''), self::text($new['user'] ?? '')),
            'variables' => $changes,
            'output_changed' => CanonicalJson::encode($old['output'] ?? ['mode' => 'text']) !== CanonicalJson::encode($new['output'] ?? ['mode' => 'text']),
            'settings_changed' => CanonicalJson::encode($old['settings'] ?? []) !== CanonicalJson::encode($new['settings'] ?? []),
        ];
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return is_string($value) ? Text::normaliseNewlines($value) : '';
    }

    /**
     * @param mixed $variables
     * @return array<string, array<string, mixed>>
     */
    private static function variablesByName($variables): array
    {
        $out = [];
        if (!is_array($variables)) {
            return $out;
        }
        foreach ($variables as $index => $raw) {
            [$spec] = VariableSpec::parse($raw, (int) $index);
            if ($spec !== null && $spec->name() !== '') {
                $out[$spec->name()] = $spec->toArray();
            }
        }

        return $out;
    }
}
