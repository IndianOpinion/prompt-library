<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * Small, dependency-free text helpers. All byte-exact: nothing here changes
 * text except what each method's name says.
 */
final class Text
{
    /** Characters removed by the plain escape mode (case 34). ZWJ and ZWNJ are kept. */
    private const PLAIN_STRIP = '/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private function __construct()
    {
    }

    /** CRLF and lone CR become LF. Nothing else changes. */
    public static function normaliseNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function isUtf8(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8');
    }

    /**
     * Byte offset of the first invalid UTF-8 sequence, or null when valid.
     */
    public static function invalidUtf8Offset(string $text): ?int
    {
        if (self::isUtf8($text)) {
            return null;
        }
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                $i++;
                continue;
            }
            $need = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 1,
                $byte >= 0xE0 && $byte <= 0xEF => 2,
                $byte >= 0xF0 && $byte <= 0xF4 => 3,
                default => -1,
            };
            if ($need < 0 || $i + $need > $length - 1) {
                return $i;
            }
            $sequence = substr($text, $i, $need + 1);
            if (!mb_check_encoding($sequence, 'UTF-8')) {
                return $i;
            }
            $i += $need + 1;
        }

        return 0;
    }

    /** Unicode code points in a valid UTF-8 string. */
    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * The plain escape mode: remove C0 controls except tab and LF, DEL, C1
     * controls, bidirectional overrides and isolates, and the BOM.
     */
    public static function plain(string $text): string
    {
        return (string) preg_replace(self::PLAIN_STRIP, '', $text);
    }

    /** Plain, then every whitespace run becomes one space, then trim. */
    public static function line(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', self::plain($text));

        return trim($text, ' ');
    }

    /**
     * Line and column (both 1-based, column in code points) of a byte offset.
     *
     * @return array{0: int, 1: int}
     */
    public static function position(string $text, int $offset): array
    {
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastBreak = strrpos($before, "\n");
        $lineText = $lastBreak === false ? $before : substr($before, $lastBreak + 1);

        return [$line, self::length($lineText) + 1];
    }

    /**
     * JSON for display and storage: unescaped Unicode and slashes.
     *
     * @param mixed $value
     */
    public static function json($value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags);
    }
}
