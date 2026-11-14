<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

use IndianOpinion\PromptLibrary\Exception\TemplateSyntaxException;

/**
 * Finds placeholders in one part of a template with a single left-to-right
 * scan (cases 21 to 24 and 28). No WordPress needed.
 *
 * Rules:
 * - `{{ name }}` with optional spaces or tabs is a placeholder.
 * - `\{{` is a literal `{{`. Before `{{`, each pair of backslashes is one
 *   literal backslash; an odd one left over escapes the braces.
 *   A backslash anywhere else is literal.
 * - In a run of three or more `{`, the extra braces are literal.
 * - Any other `{{` is a syntax error with line and column.
 */
final class Parser
{
    private const PLACEHOLDER = '/\G\{\{[ \t]*([a-z][a-z0-9_]{0,63})[ \t]*\}\}/';
    private const CLOSED_ON_LINE = '/\G\{\{([^\n]*?)\}\}/';

    /**
     * Parse a part and return its tokens and every syntax problem.
     */
    public static function analyse(string $text, string $part): ParsedPart
    {
        $tokens = [];
        $issues = [];
        $buffer = '';
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $char = $text[$i];
            if ($char === '\\') {
                $j = $i;
                while ($j < $length && $text[$j] === '\\') {
                    $j++;
                }
                $run = $j - $i;
                if (substr($text, $j, 2) === '{{') {
                    $buffer .= str_repeat('\\', intdiv($run, 2));
                    if ($run % 2 === 1) {
                        $buffer .= '{{';
                        $i = $j + 2;
                        continue;
                    }
                    $i = $j;
                    continue;
                }
                $buffer .= str_repeat('\\', $run);
                $i = $j;
                continue;
            }
            if ($char === '{' && ($text[$i + 1] ?? '') === '{') {
                $j = $i;
                while ($j < $length && $text[$j] === '{') {
                    $j++;
                }
                $buffer .= str_repeat('{', $j - $i - 2);
                $open = $j - 2;
                if (preg_match(self::PLACEHOLDER, $text, $match, 0, $open) === 1) {
                    if ($buffer !== '') {
                        $tokens[] = Token::text($buffer);
                        $buffer = '';
                    }
                    $tokens[] = Token::variable($match[1], $open);
                    $i = $open + strlen($match[0]);
                    continue;
                }
                [$line, $column] = Text::position($text, $open);
                if (preg_match(self::CLOSED_ON_LINE, $text, $bad, 0, $open) === 1) {
                    $message = sprintf(
                        'Invalid placeholder "%s": only a lower-case variable name may sit between the braces. Write \\{{ for a literal {{.',
                        $bad[0]
                    );
                    $issues[] = Issue::error('syntax_placeholder', $message, $part, $line, $column);
                    $buffer .= $bad[0];
                    $i = $open + strlen($bad[0]);
                    continue;
                }
                $issues[] = Issue::error('syntax_unclosed', 'Unclosed placeholder: "{{" has no "}}" on the same line. Write \\{{ for a literal {{.', $part, $line, $column);
                $buffer .= '{{';
                $i = $open + 2;
                continue;
            }
            $buffer .= $char;
            $i++;
        }
        if ($buffer !== '') {
            $tokens[] = Token::text($buffer);
        }

        return new ParsedPart($part, $tokens, $issues, $text);
    }

    /**
     * Parse a part or throw TemplateSyntaxException with every problem.
     */
    public static function parse(string $text, string $part): ParsedPart
    {
        $parsed = self::analyse($text, $part);
        if ($parsed->issues !== []) {
            throw new TemplateSyntaxException($parsed->issues);
        }

        return $parsed;
    }
}
