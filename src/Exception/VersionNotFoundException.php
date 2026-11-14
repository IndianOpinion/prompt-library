<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class VersionNotFoundException extends PromptLibraryException
{
    public function __construct(
        private readonly string $slug,
        private readonly string $requested,
        private readonly int $highest,
    ) {
        parent::__construct(sprintf(
            'Prompt template "%s" has no version %s; the highest version is %d.',
            $slug,
            $requested,
            $highest
        ));
    }

    public function errorCode(): string
    {
        return 'promptlib_version_not_found';
    }

    public function highest(): int
    {
        return $this->highest;
    }

    public function data(): array
    {
        return ['slug' => $this->slug, 'requested' => $this->requested, 'highest' => $this->highest];
    }
}
