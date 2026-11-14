<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * Which supplied names fit a version, without rendering (case 50).
 */
final class CheckResult
{
    /**
     * @param list<string> $missingRequired
     * @param list<string> $unknown
     * @param list<string> $unused
     */
    public function __construct(
        private readonly array $missingRequired,
        private readonly array $unknown,
        private readonly array $unused,
    ) {
    }

    /**
     * @param list<string> $supplied
     */
    public static function of(Definition $definition, array $supplied): self
    {
        $declared = array_map(static fn (VariableSpec $v): string => $v->name(), $definition->variables());
        $missing = array_values(array_diff($definition->requiredNames(), $supplied));
        $unknown = array_values(array_diff($supplied, $declared));
        $unused = array_values(array_diff($declared, $supplied, $definition->requiredNames()));

        return new self($missing, $unknown, $unused);
    }

    /** True when no required variable is missing. Unknown and unused names do not fail a check. */
    public function ok(): bool
    {
        return $this->missingRequired === [];
    }

    /** @return list<string> */
    public function missingRequired(): array
    {
        return $this->missingRequired;
    }

    /** @return list<string> */
    public function unknown(): array
    {
        return $this->unknown;
    }

    /** @return list<string> Optional declared variables the caller does not supply. */
    public function unused(): array
    {
        return $this->unused;
    }
}
