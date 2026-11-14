<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests;

use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\Support\FakeGateway;
use IndianOpinion\PromptLibrary\Tests\Support\FrozenClock;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;

/**
 * Base for tests that need the plugin: fresh stub state, tables, roles,
 * three users and a plugin wired to a fake gateway and a frozen clock.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const ADMIN = 1;
    protected const EDITOR = 2;
    protected const AUTHOR = 3;

    protected Plugin $plugin;
    protected FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        Schema::install();
        Capabilities::grant();
        State::addUser(self::ADMIN, 'admin', ['administrator']);
        State::addUser(self::EDITOR, 'editor', ['editor']);
        State::addUser(self::AUTHOR, 'author', ['author']);
        wp_set_current_user(self::ADMIN);
        $this->gateway = new FakeGateway();
        $this->plugin = new Plugin($this->gateway, new FrozenClock());
        Plugin::setInstance($this->plugin);
        $this->plugin->boot();
        do_action('init');
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        parent::tearDown();
    }

    /**
     * A small valid definition.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function definition(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'summary',
            'title' => 'Summary',
            'description' => 'Summarise an article.',
            'system' => 'You write short summaries.',
            'user' => "Title: {{ title }}\n\n{{ body }}",
            'variables' => [
                ['name' => 'title', 'type' => 'string', 'escape' => 'line'],
                ['name' => 'body', 'type' => 'text', 'escape' => 'fenced'],
            ],
            'output' => ['mode' => 'text'],
            'settings' => ['temperature' => null, 'token_cap' => null],
            'note' => 'First wording.',
        ];
    }

    /**
     * Create a template and publish version 1 live.
     *
     * @param array<string, mixed> $overrides
     */
    protected function publishedTemplate(array $overrides = []): int
    {
        $id = $this->plugin->drafts->create($this->definition($overrides), self::ADMIN);
        $this->plugin->publisher->publish($id, 'First version.', true, true, self::ADMIN);

        return $id;
    }

    /** Configure the runner so Run is available. */
    protected function configureRunner(array $overrides = []): void
    {
        update_option(Settings::OPTION, $overrides + Settings::defaults() + ['models' => []], false);
        $settings = get_option(Settings::OPTION);
        if (($settings['models'] ?? []) === []) {
            $settings['models'] = ['test/model', 'test/other'];
            update_option(Settings::OPTION, $settings, false);
        }
        update_option(Settings::KEY_OPTION, 'test-key-not-real', false);
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    protected function actions(string $hook): array
    {
        return array_values(array_filter(State::$actionLog, static fn (array $e): bool => $e[0] === $hook));
    }
}
