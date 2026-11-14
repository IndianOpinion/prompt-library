<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * The result of parsing one part.
 */
final class ParsedPart
{
    /**
     * @param list<Token> $tokens
     * @param list<Issue> $issues
     */
    public function __construct(
        public readonly string $part,
        public readonly array $tokens,
        public readonly array $issues,
        public readonly string $source = '',
    ) {
    }

    /**
     * Line and column (1-based) of a token, computed only when needed.
     *
     * @return array{0: int, 1: int}
     */
    public function position(Token $token): array
    {
        return Text::position($this->source, $token->offset);
    }

    /** @return list<string> Distinct variable names in order of first use. */
    public function names(): array
    {
        $names = [];
        foreach ($this->tokens as $token) {
            if ($token->isVariable && !in_array($token->value, $names, true)) {
                $names[] = $token->value;
            }
        }

        return $names;
    }

    /** @return list<Token> */
    public function variables(): array
    {
        return array_values(array_filter($this->tokens, static fn (Token $t): bool => $t->isVariable));
    }
}
