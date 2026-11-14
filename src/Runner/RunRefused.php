<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

/**
 * The runner refused before calling a model. The code is a REST error code
 * (section 5.5), for example promptlib_cap_reached.
 */
final class RunRefused extends \RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly string $refusalCode,
        string $message,
        private readonly int $status = 403,
        private readonly string $reason = '',
        private readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    public function refusalCode(): string
    {
        return $this->refusalCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** A finer reason, for example the spend meter's reason code. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data + ['reason' => $this->reason];
    }
}
