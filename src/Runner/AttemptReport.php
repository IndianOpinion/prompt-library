<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

/**
 * One attempt as the library reported it, in the shape the spend path needs.
 */
final class AttemptReport
{
    public const OK = 'ok';
    public const ERROR_BILLED = 'error_billed';
    public const ERROR_UNBILLED = 'error_unbilled';

    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $status,
        public readonly int $inputTokens = 0,
        public readonly int $cachedInputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly ?float $cost = null,
        public readonly string $costSource = 'unknown',
        public readonly ?int $httpStatus = null,
        public readonly string $errorCode = '',
        public readonly int $latencyMs = 0,
        public readonly bool $retryable = false,
    ) {
    }

    /** Cost in nanos (billionths of the provider's currency unit), or null when unknown. */
    public function costNanos(): ?int
    {
        return $this->cost === null ? null : (int) round($this->cost * 1e9);
    }
}
