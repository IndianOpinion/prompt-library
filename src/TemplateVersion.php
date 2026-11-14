<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * A read-only view of one published version (section 5.2).
 */
final class TemplateVersion
{
    public function __construct(
        private readonly int $templateId,
        private readonly int $version,
        private readonly Definition $definition,
        private readonly string $contentHash,
        private readonly \DateTimeImmutable $publishedAt,
        private readonly int $authorId,
        private readonly string $note,
        private readonly bool $live,
    ) {
    }

    public function templateId(): int
    {
        return $this->templateId;
    }

    public function slug(): string
    {
        return $this->definition->slug();
    }

    public function version(): int
    {
        return $this->version;
    }

    public function title(): string
    {
        return $this->definition->title();
    }

    /** @return list<VariableSpec> */
    public function variables(): array
    {
        return $this->definition->variables();
    }

    public function output(): OutputSpec
    {
        return $this->definition->output();
    }

    /** @return array{temperature: ?float, token_cap: ?int} */
    public function settings(): array
    {
        return $this->definition->settings();
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /** UTC. */
    public function publishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function authorId(): int
    {
        return $this->authorId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function systemTemplate(): string
    {
        return $this->definition->system();
    }

    public function userTemplate(): string
    {
        return $this->definition->user();
    }

    public function definition(): Definition
    {
        return $this->definition;
    }

    public function withLive(bool $live): self
    {
        return new self($this->templateId, $this->version, $this->definition, $this->contentHash, $this->publishedAt, $this->authorId, $this->note, $live);
    }
}
