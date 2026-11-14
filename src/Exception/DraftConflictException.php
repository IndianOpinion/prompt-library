<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

/**
 * The draft changed since the editor's page loaded (case 13). Carries both
 * versions so the editor can show them side by side.
 */
final class DraftConflictException extends PromptLibraryException
{
    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    public function __construct(private readonly array $stored, private readonly array $submitted)
    {
        parent::__construct('The draft was changed by someone else since this page loaded. Nothing was saved.');
    }

    public function errorCode(): string
    {
        return 'promptlib_draft_conflict';
    }

    /** @return array<string, mixed> */
    public function stored(): array
    {
        return $this->stored;
    }

    /** @return array<string, mixed> */
    public function submitted(): array
    {
        return $this->submitted;
    }
}
