<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class TemplateNotFoundException extends PromptLibraryException
{
    public const MISSING = 'missing';
    public const TRASHED = 'trashed';
    public const UNPUBLISHED = 'unpublished';

    public function __construct(private readonly string $slug, private readonly string $reason)
    {
        parent::__construct(sprintf('Prompt template "%s" not found (%s).', $slug, $reason));
    }

    public function errorCode(): string
    {
        return 'promptlib_not_found';
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** One of missing, trashed, unpublished. */
    public function reason(): string
    {
        return $this->reason;
    }

    public function data(): array
    {
        return ['slug' => $this->slug, 'reason' => $this->reason];
    }
}
