<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * Canonical JSON for content hashes (section 5.4): object keys sorted,
 * no insignificant whitespace, unescaped Unicode and slashes. Lists keep
 * their order.
 */
final class CanonicalJson
{
    private function __construct()
    {
    }

    /** @param mixed $value */
    public static function encode($value): string
    {
        return json_encode(
            self::sort($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @param mixed $value */
    public static function hash($value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sort($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sort'], $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::sort($item);
        }

        return $out;
    }
}
