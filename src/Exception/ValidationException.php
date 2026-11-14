<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

use IndianOpinion\PromptLibrary\Issue;

/**
 * A definition, draft or import that does not pass validation. Used by the
 * editor, publish, import and register-default paths, never by render.
 */
final class ValidationException extends PromptLibraryException
{
    private string $stableCode;

    /** @param non-empty-list<Issue> $issues */
    public function __construct(private readonly array $issues, string $code = 'promptlib_invalid_definition')
    {
        $this->stableCode = $code;
        $first = $issues[0];
        $more = count($issues) > 1 ? sprintf(' (and %d more)', count($issues) - 1) : '';
        parent::__construct($first->describe() . $more);
    }


    public function errorCode(): string
    {
        return $this->stableCode;
    }

    /** @return non-empty-list<Issue> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function data(): array
    {
        return ['issues' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues)];
    }
}
