<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * A piece of a parsed part: literal text, or a variable reference.
 */
final class Token
{
    private function __construct(
        public readonly bool $isVariable,
        public readonly string $value,
        public readonly int $offset = 0,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(false, $text);
    }

    /** @param int $offset byte offset of the opening braces, for error positions */
    public static function variable(string $name, int $offset): self
    {
        return new self(true, $name, $offset);
    }
}
