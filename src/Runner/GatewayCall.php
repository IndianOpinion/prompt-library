<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

/**
 * One runner attempt: one model, one request.
 */
final class GatewayCall
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public readonly string $purpose,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $system,
        public readonly string $user,
        public readonly string $schemaName,
        public readonly array $schema,
        public readonly ?float $temperature,
        public readonly int $tokenCap,
        public readonly float $deadlineSeconds,
    ) {
    }
}
