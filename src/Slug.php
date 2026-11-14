<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * The public identifier of a template (case 3). Never adjusted silently.
 */
final class Slug
{
    public const PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';
    public const MAX_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * The problem with a slug, or null when it is valid. Lowercase it first
     * with normalise() if the input came from a person.
     */
    public static function problem(string $slug): ?string
    {
        if ($slug === '') {
            return 'The slug is empty.';
        }
        if (strlen($slug) > self::MAX_LENGTH) {
            return sprintf('The slug is longer than %d characters.', self::MAX_LENGTH);
        }
        if (preg_match(self::PATTERN, $slug) !== 1) {
            return 'The slug may use lower-case letters and digits, joined by single hyphens or underscores.';
        }

        return null;
    }

    public static function isValid(string $slug): bool
    {
        return self::problem($slug) === null;
    }

    /** Lowercase ASCII letters only. Every other character is left for problem() to judge. */
    public static function normalise(string $slug): string
    {
        return strtolower($slug);
    }
}
