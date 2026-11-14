<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class IntegrityException extends PromptLibraryException
{
    public function __construct(private readonly string $slug, private readonly int $version, string $detail)
    {
        parent::__construct(sprintf('Version %d of prompt template "%s" failed its integrity check: %s', $version, $slug, $detail));
    }

    public function errorCode(): string
    {
        return 'promptlib_integrity';
    }

    public function data(): array
    {
        return ['slug' => $this->slug, 'version' => $this->version];
    }
}
