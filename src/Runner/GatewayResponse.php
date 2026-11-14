<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

/**
 * The outcome of one runner attempt. On failure, errorKind is one of:
 * credit, no_route, auth, schema, guard, deadline, refused, configuration.
 */
final class GatewayResponse
{
    /**
     * @param array<mixed>|null $data
     * @param list<AttemptReport> $attempts
     * @param list<string> $guardActions
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data,
        public readonly array $attempts,
        public readonly array $guardActions = [],
        public readonly string $errorKind = '',
        public readonly string $errorClass = '',
        public readonly string $detail = '',
        public readonly bool $fallbackWorthy = false,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @param list<AttemptReport> $attempts
     * @param list<string> $guardActions
     */
    public static function success(array $data, array $attempts, array $guardActions = []): self
    {
        return new self(true, $data, $attempts, $guardActions);
    }

    /**
     * @param list<AttemptReport> $attempts
     * @param list<string> $guardActions
     */
    public static function failure(string $kind, string $class, string $detail, array $attempts, bool $fallbackWorthy, array $guardActions = []): self
    {
        return new self(false, null, $attempts, $guardActions, $kind, $class, $detail, $fallbackWorthy);
    }
}
