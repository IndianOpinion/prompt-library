<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * Type checks, type rendering and escaping of one value (cases 16, 31, 32, 34).
 *
 * The pipeline for a supplied value is: typed() checks the type and turns the
 * value into its base text; clean() applies the character-removing part of the
 * escape mode; the length is checked on that; finish() fences or JSON-encodes.
 */
final class ValueFormatter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private function __construct()
    {
    }

    /**
     * Check a value against its declared type and return the normalised value
     * and its base text, or a problem.
     *
     * @param mixed $value
     * @return array{ok: bool, value: mixed, text: string, problem: string}
     */
    public static function typed(VariableSpec $spec, $value): array
    {
        try {
            [$normalised, $text] = self::convert($spec, $value);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'value' => null, 'text' => '', 'problem' => $e->getMessage()];
        }

        return ['ok' => true, 'value' => $normalised, 'text' => $text, 'problem' => ''];
    }

    /** The character-removing step of the escape mode. */
    public static function clean(VariableSpec $spec, string $text): string
    {
        return match ($spec->escape()) {
            'raw' => $text,
            'line' => Text::line($text),
            default => Text::plain($text),
        };
    }

    /**
     * The text that is substituted for the placeholder.
     *
     * @param mixed $normalised the value returned by typed()
     */
    public static function finish(VariableSpec $spec, string $cleaned, $normalised): string
    {
        if ($spec->escape() === 'json') {
            if ($spec->type() === 'json') {
                return (string) json_encode(self::cleanLeaves($normalised, 'plain'), self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION);
            }

            return (string) json_encode($cleaned, self::JSON_FLAGS);
        }
        if ($spec->escape() === 'fenced') {
            $name = $spec->name();
            $pattern = '/<\s*\/\s*' . preg_quote($name, '/') . '\s*>/iu';
            $inner = (string) preg_replace($pattern, '[/' . $name . ']', $cleaned);

            return '<' . $name . ">\n" . $inner . "\n</" . $name . '>';
        }

        return $cleaned;
    }

    /**
     * The text used for max_length: the cleaned text, or for a json variable
     * with json escaping, its compact JSON.
     *
     * @param mixed $normalised
     */
    public static function measured(VariableSpec $spec, string $cleaned, $normalised): string
    {
        if ($spec->escape() === 'json' && $spec->type() === 'json') {
            return (string) json_encode(self::cleanLeaves($normalised, 'plain'), self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION);
        }

        return $cleaned;
    }

    /**
     * @param mixed $value
     * @return array{0: mixed, 1: string}
     */
    private static function convert(VariableSpec $spec, $value): array
    {
        switch ($spec->type()) {
            case 'string':
            case 'text':
                $string = self::scalarString($value, 'text');
                return [$string, $string];
            case 'integer':
                $int = self::integer($value);
                return [$int, (string) $int];
            case 'number':
                $number = self::number($value);
                return [$number, self::formatNumber($number)];
            case 'boolean':
                if ($value === true || $value === 'true') {
                    return [true, 'true'];
                }
                if ($value === false || $value === 'false') {
                    return [false, 'false'];
                }
                throw new \InvalidArgumentException('must be true or false');
            case 'enum':
                if (!is_string($value) || !in_array($value, $spec->enum(), true)) {
                    throw new \InvalidArgumentException('must be one of: ' . implode(', ', $spec->enum()));
                }
                return [$value, $value];
            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    throw new \InvalidArgumentException('must be a list');
                }
                $items = [];
                foreach ($value as $item) {
                    $items[] = self::listItem($item);
                }
                $lines = array_map(static fn (string $item): string => '- ' . $item, $items);
                return [$items, implode("\n", $lines)];
            case 'json':
                $checked = self::jsonValue($value, 0);
                $clean = $spec->escape() === 'raw' ? $checked : self::cleanLeaves($checked, 'plain');
                $encoded = json_encode($clean, self::JSON_FLAGS | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
                if ($encoded === false) {
                    throw new \InvalidArgumentException('cannot be encoded as JSON');
                }
                return [$checked, $encoded];
        }

        throw new \InvalidArgumentException('has an unknown type');
    }

    /** @param mixed $value */
    private static function scalarString($value, string $what): string
    {
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('must be ' . $what);
            }
            return is_int($value) ? (string) $value : self::formatNumber($value);
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('must be ' . $what);
        }

        return self::utf8($value);
    }

    private static function utf8(string $value): string
    {
        $offset = Text::invalidUtf8Offset($value);
        if ($offset !== null) {
            throw new \InvalidArgumentException(sprintf('is not valid UTF-8 (byte %d)', $offset));
        }

        return Text::normaliseNewlines($value);
    }

    /** @param mixed $value */
    private static function integer($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('must be a whole number');
        }
        $normal = (string) preg_replace('/^(-?)0+(?=[0-9])/', '$1', $value);
        if ($normal === '-0') {
            $normal = '0';
        }
        $int = (int) $normal;
        if ((string) $int !== $normal) {
            throw new \InvalidArgumentException('is outside the integer range');
        }

        return $int;
    }

    /**
     * @param mixed $value
     */
    private static function number($value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('must be a finite number (NaN and infinity are refused)');
            }
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/', $value) === 1) {
            if (preg_match('/^-?[0-9]+$/', $value) === 1) {
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if (is_int($int)) {
                    return $int;
                }
            }
            $float = (float) $value;
            if (!is_finite($float)) {
                throw new \InvalidArgumentException('must be a finite number');
            }
            return $float;
        }

        throw new \InvalidArgumentException('must be a number written with digits and a "." decimal point');
    }

    /**
     * Plain decimal, "." as the decimal point, no thousands separator, no
     * exponent between 1e-6 and 1e15, independent of the locale.
     */
    public static function formatNumber(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }
        $text = (string) json_encode($number, JSON_PRESERVE_ZERO_FRACTION);
        if (str_ends_with($text, '.0')) {
            $text = substr($text, 0, -2);
        }
        $abs = abs($number);
        if (stripos($text, 'e') === false || $abs < 1e-6 || $abs >= 1e15) {
            return $text;
        }

        return self::expandExponent($text);
    }

    private static function expandExponent(string $text): string
    {
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$mantissa, $exponent] = preg_split('/[eE]/', $text) ?: [$text, '0'];
        $exponent = (int) $exponent;
        $parts = explode('.', $mantissa);
        $intPart = $parts[0];
        $fraction = $parts[1] ?? '';
        $digits = $intPart . $fraction;
        $point = strlen($intPart) + $exponent;
        if ($point <= 0) {
            $result = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $result = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $result = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        $result = ltrim($result, '0');
        if ($result === '' || $result[0] === '.') {
            $result = '0' . $result;
        }
        if (str_contains($result, '.')) {
            $result = rtrim(rtrim($result, '0'), '.');
        }

        return ($negative ? '-' : '') . $result;
    }

    /** @param mixed $item */
    private static function listItem($item): string
    {
        if (is_bool($item)) {
            return $item ? 'true' : 'false';
        }
        if (is_string($item) || is_int($item) || is_float($item)) {
            return self::scalarString($item, 'text');
        }

        throw new \InvalidArgumentException('must be a list of strings or other scalar values');
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function jsonValue($value, int $depth)
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('is nested too deeply');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('contains NaN or infinity');
            }
            return $value;
        }
        if (is_string($value)) {
            return self::utf8($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $key = self::utf8($key);
                }
                $out[$key] = self::jsonValue($item, $depth + 1);
            }
            return $out;
        }

        throw new \InvalidArgumentException('must be an array or a scalar that JSON can encode');
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function cleanLeaves($value, string $mode)
    {
        if (is_string($value)) {
            return $mode === 'plain' ? Text::plain($value) : $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? Text::plain($key) : $key] = self::cleanLeaves($item, $mode);
            }
            return $out;
        }

        return $value;
    }
}
