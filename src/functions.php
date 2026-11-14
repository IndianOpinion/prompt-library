<?php

/**
 * The consumer contract (section 5.1). Global functions with the
 * promptlib_ prefix; stable from 0.1.0 under semantic versioning.
 */

declare(strict_types=1);

use IndianOpinion\PromptLibrary\CheckResult;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\RenderedPrompt;
use IndianOpinion\PromptLibrary\TemplateVersion;

if (!function_exists('promptlib_render')) {
    /**
     * Render a template by slug and version. Throws on any failure.
     *
     * @param array<string, mixed> $values
     * @param array{strict?: bool, fallback?: array<mixed>, consumer?: string} $options
     */
    function promptlib_render(string $slug, array $values, int|string $version = 'live', array $options = []): RenderedPrompt
    {
        return Plugin::instance()->library->render($slug, $values, $version, $options);
    }

    /** A read-only view of one version. Throws not-found. */
    function promptlib_get(string $slug, int|string $version = 'live'): TemplateVersion
    {
        return Plugin::instance()->library->get($slug, $version);
    }

    /** True when a template with at least one version exists and is not trashed. */
    function promptlib_exists(string $slug): bool
    {
        return Plugin::instance()->library->exists($slug);
    }

    /**
     * Missing required, unknown and unused names, without rendering.
     *
     * @param list<string> $supplied_names
     */
    function promptlib_check(string $slug, array $supplied_names, int|string $version = 'live'): CheckResult
    {
        return Plugin::instance()->library->check($slug, $supplied_names, $version);
    }

    /**
     * Declare which variables a consumer supplies for a slug. Call on init.
     *
     * @param list<string> $supplied_names
     */
    function promptlib_register_consumer(string $slug, string $consumer_id, array $supplied_names): void
    {
        Plugin::instance()->consumers->register($slug, $consumer_id, $supplied_names);
    }

    /**
     * Ship a default template with a plugin. Call on init.
     *
     * @param array<mixed> $definition
     */
    function promptlib_register_default(string $consumer_id, array $definition): void
    {
        Plugin::instance()->consumers->registerDefault($consumer_id, $definition);
    }

    /** The contract version: 1.0 for 0.1.0. */
    function promptlib_api_version(): string
    {
        return Plugin::API_VERSION;
    }
}
