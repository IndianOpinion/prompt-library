<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

/**
 * A refused admin, REST or CLI action with a stable code, for example
 * promptlib_no_changes or promptlib_slug_taken. Not thrown by render.
 */
final class RequestException extends PromptLibraryException
{
    /** @param array<string, mixed> $extra */
    public function __construct(private readonly string $stableCode, string $message, private readonly int $status = 400, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->stableCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data(): array
    {
        return $this->extra;
    }
}
