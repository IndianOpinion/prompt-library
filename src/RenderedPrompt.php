<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * The result of a render (section 5.2). Immutable.
 */
final class RenderedPrompt
{
    /**
     * @param list<string> $warnings
     * @param array{temperature: ?float, token_cap: ?int} $settings
     */
    public function __construct(
        private readonly string $slug,
        private readonly int $version,
        private readonly bool $fromFallback,
        private readonly string $system,
        private readonly string $user,
        private readonly string $contentHash,
        private readonly array $warnings,
        private readonly OutputSpec $output,
        private readonly array $settings,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** The version number, or 0 when rendered from a fallback or a draft. */
    public function version(): int
    {
        return $this->version;
    }

    public function fromFallback(): bool
    {
        return $this->fromFallback;
    }

    public function system(): string
    {
        return $this->system;
    }

    public function user(): string
    {
        return $this->user;
    }

    /** Hash of the version's content (section 5.4). */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /** SHA-256 of system, a NUL byte, and user. */
    public function renderHash(): string
    {
        return hash('sha256', $this->system . "\0" . $this->user);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function output(): OutputSpec
    {
        return $this->output;
    }

    /** @return array{temperature: ?float, token_cap: ?int} */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * A chat message list, system first; the system message is left out when empty.
     *
     * @return list<array{role: string, content: string}>
     */
    public function toMessages(): array
    {
        $messages = [];
        if ($this->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $this->system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->user];

        return $messages;
    }

    /**
     * The shape the promptlib_render filter returns.
     *
     * @return array{system: string, user: string, slug: string, version: int, content_hash: string, render_hash: string, warnings: list<string>, output: array<string, mixed>, settings: array{temperature: ?float, token_cap: ?int}, from_fallback: bool}
     */
    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'user' => $this->user,
            'slug' => $this->slug,
            'version' => $this->version,
            'content_hash' => $this->contentHash,
            'render_hash' => $this->renderHash(),
            'warnings' => $this->warnings,
            'output' => $this->output->toArray(),
            'settings' => $this->settings,
            'from_fallback' => $this->fromFallback,
        ];
    }
}
