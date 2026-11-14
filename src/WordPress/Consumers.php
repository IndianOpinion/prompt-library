<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\CheckResult;
use IndianOpinion\PromptLibrary\Definition;

/**
 * In-memory registry of consumers (case 48) and their shipped default
 * definitions (case 51). Filled on init by other plugins, every request.
 */
class Consumers
{
    /** @var array<string, array<string, list<string>>> slug => consumer id => supplied names */
    private array $supplied = [];

    /** @var array<string, array<string, array<mixed>>> slug => consumer id => definition */
    private array $defaults = [];

    /** @param list<string> $names */
    public function register(string $slug, string $consumerId, array $names): void
    {
        $this->supplied[$slug][$consumerId] = array_values(array_unique(array_map('strval', $names)));
    }

    /** @param array<mixed> $definition */
    public function registerDefault(string $consumerId, array $definition): void
    {
        $slug = (string) ($definition['slug'] ?? '');
        if ($slug === '' || $consumerId === '') {
            return;
        }
        $this->defaults[$slug][$consumerId] = $definition;
    }

    /** @return array<string, list<string>> consumer id => supplied names */
    public function forSlug(string $slug): array
    {
        return $this->supplied[$slug] ?? [];
    }

    /** @return array<string, array<string, array<mixed>>> */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /** @return array<mixed>|null the definition of the first consumer that registered one */
    public function registeredDefault(string $slug): ?array
    {
        $defaults = $this->defaults[$slug] ?? [];
        if ($defaults === []) {
            return null;
        }

        return reset($defaults);
    }

    /** @return array<mixed>|null */
    public function defaultFrom(string $slug, string $consumerId): ?array
    {
        return $this->defaults[$slug][$consumerId] ?? null;
    }

    /**
     * Declared consumers that would fail on a definition (case 49).
     *
     * @return array<string, list<string>> consumer id => missing required names
     */
    public function failing(string $slug, Definition $definition): array
    {
        $failing = [];
        foreach ($this->forSlug($slug) as $consumer => $names) {
            $check = CheckResult::of($definition, $names);
            if (!$check->ok()) {
                $failing[$consumer] = $check->missingRequired();
            }
        }

        return $failing;
    }
}
