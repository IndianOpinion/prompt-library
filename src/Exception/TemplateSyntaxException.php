<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

use IndianOpinion\PromptLibrary\Issue;

final class TemplateSyntaxException extends PromptLibraryException
{
    /** @param non-empty-list<Issue> $issues */
    public function __construct(private readonly array $issues)
    {
        $first = $issues[0];
        $more = count($issues) > 1 ? sprintf(' (and %d more)', count($issues) - 1) : '';
        parent::__construct($first->describe() . $more);
    }

    public function errorCode(): string
    {
        return 'promptlib_syntax';
    }

    /** @return non-empty-list<Issue> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function part(): string
    {
        return $this->issues[0]->part;
    }

    public function line(): int
    {
        return $this->issues[0]->line;
    }

    public function column(): int
    {
        return $this->issues[0]->column;
    }

    public function data(): array
    {
        return ['issues' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues)];
    }
}
