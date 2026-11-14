<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Admin\Admin;
use IndianOpinion\PromptLibrary\Exception\TemplateNotFoundException;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;
use IndianOpinion\PromptLibrary\WordPress\CounterRepository;
use IndianOpinion\PromptLibrary\WordPress\Lifecycle;
use IndianOpinion\PromptLibrary\WordPress\PostType;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;
use IndianOpinion\PromptLibrary\WordPress\SiteHealth;
use IndianOpinion\PromptLibrary\WordPress\Uninstaller;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class LifecycleTest extends TestCase
{
    /** Acceptance test 66 (cases 81 and 82). */
    public function testActivationCreatesTablesAndChecksColumns(): void
    {
        State::reset();
        Lifecycle::activateSite();
        global $wpdb;
        foreach (Schema::expectedColumns() as $suffix => $columns) {
            self::assertSame($columns, $wpdb->tables[$wpdb->prefix . $suffix]);
        }
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        self::assertFalse(Schema::isDegraded());
        self::assertArrayHasKey(Lifecycle::PRUNE_HOOK, State::$cron);
        self::assertTrue(State::$roles['administrator']['capabilities'][Capabilities::RUN]);
        self::assertArrayNotHasKey(Capabilities::RUN, State::$roles['editor']['capabilities']);
        self::assertTrue(State::$roles['editor']['capabilities'][Capabilities::PUBLISH]);
        self::assertTrue(State::$autoload[Schema::VERSION_OPTION]);
    }

    public function testMissingColumnDegradesAndDisablesRunner(): void
    {
        $this->configureRunner();
        global $wpdb;
        $wpdb->tables = [];
        $wpdb->skipColumns = ['compare_group'];
        update_option(Schema::VERSION_OPTION, '0');
        do_action('admin_init');
        self::assertSame(['promptlib_runs.compare_group'], Schema::storedMissing());
        self::assertTrue(Schema::isDegraded());
        self::assertSame('promptlib_runner_disabled', $this->plugin->runner->disabledReason()['code'] ?? null);
        $checks = array_column((new SiteHealth($this->plugin))->checks(), null, 'id');
        self::assertSame('critical', $checks['tables']['status']);
        $this->publishedTemplate();
        self::assertSame(1, promptlib_render('summary', ['title' => 't', 'body' => 'b'])->version());

        $wpdb->skipColumns = [];
        Lifecycle::activateSite();
        self::assertFalse(Schema::isDegraded());
    }

    public function testUpgradeRunsOnlyWhenTheSchemaVersionIsOlder(): void
    {
        global $wpdb;
        $wpdb->tables = [];
        do_action('admin_init');
        self::assertSame([], $wpdb->tables);
        update_option(Schema::VERSION_OPTION, '0');
        do_action('admin_init');
        self::assertCount(3, $wpdb->tables);
    }

    /** Acceptance test 67 (case 83). */
    public function testDeactivationOnlyUnschedules(): void
    {
        $this->publishedTemplate();
        Lifecycle::activateSite();
        $options = State::$options;
        Lifecycle::deactivate();
        self::assertArrayNotHasKey(Lifecycle::PRUNE_HOOK, State::$cron);
        self::assertSame($options, State::$options);
        self::assertNotNull($this->plugin->templates->locate('summary'));
    }

    /** Acceptance test 68 (case 84). */
    public function testTrashRestoreAndRecentConsumers(): void
    {
        $id = $this->publishedTemplate();
        promptlib_render('summary', ['title' => 't', 'body' => 'b'], 'live', ['consumer' => 'my-plugin']);
        self::assertSame(['my-plugin'], $this->plugin->lifecycle->recentConsumers($id));
        $actions = (new Admin($this->plugin))->rowActions(['trash' => '<a href="#trash">Trash</a>'], State::$posts[$id]);
        self::assertStringContainsString('data-promptlib-confirm=', $actions['trash']);
        self::assertStringContainsString('my-plugin', $actions['trash']);
        wp_trash_post($id);
        try {
            promptlib_render('summary', ['title' => 't', 'body' => 'b']);
            self::fail('Expected trashed.');
        } catch (TemplateNotFoundException $e) {
            self::assertSame('trashed', $e->reason());
        }
        wp_untrash_post($id);
        self::assertSame('publish', State::$posts[$id]->post_status);
        self::assertSame(1, promptlib_render('summary', ['title' => 't', 'body' => 'b'])->version());
        $types = array_column($this->plugin->versions->history($id), 'type');
        self::assertSame(['restore', 'trash'], array_slice($types, 0, 2));
        self::assertCount(1, $this->actions('promptlib_template_trashed'));
        self::assertCount(1, $this->actions('promptlib_template_restored'));
        State::$now += 8 * 86400;
        self::assertSame([], $this->plugin->lifecycle->recentConsumers($id));
    }

    /** Acceptance test 69 (case 85). */
    public function testPermanentDeleteRemovesEverythingAndNeedsManage(): void
    {
        $this->configureRunner();
        $id = $this->publishedTemplate();
        $this->plugin->drafts->saveFixture($id, 'f', ['title' => 't']);
        $this->plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
        wp_trash_post($id);
        self::assertFalse(user_can(self::EDITOR, 'delete_post', $id));
        self::assertTrue(user_can(self::ADMIN, 'delete_post', $id));
        wp_untrash_post($id);
        self::assertTrue(user_can(self::EDITOR, 'delete_post', $id));
        wp_delete_post($id, true);
        global $wpdb;
        self::assertSame([], $wpdb->rows[$wpdb->prefix . 'promptlib_versions']);
        self::assertSame([], $wpdb->rows[$wpdb->prefix . 'promptlib_runs']);
        self::assertArrayNotHasKey($id, State::$meta);
        self::assertCount(1, $this->actions('promptlib_template_deleted'));
        self::assertNull($this->plugin->templates->locate('summary'));
    }

    /** Acceptance test 70 (case 86). */
    public function testUninstallRemovesEverythingAndNothingElse(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'rev'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        update_option('blogname', 'Keep me');
        update_option(Settings::OPTION, Settings::defaults(), false);
        set_transient('promptlib_rate_1_1', 1, 60);
        set_transient('someone_else', 1, 60);
        $other = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Keep']);
        update_post_meta($other, 'unrelated', 'keep');
        Lifecycle::activateSite();
        Uninstaller::run();
        global $wpdb;
        self::assertSame([], $wpdb->tables);
        foreach (State::$posts as $post) {
            self::assertNotSame(PostType::NAME, $post->post_type);
            self::assertNotSame($id, $post->post_parent);
        }
        self::assertSame('Keep me', get_option('blogname'));
        self::assertSame('keep', get_post_meta($other, 'unrelated', true));
        self::assertSame([], array_filter(array_keys(State::$options), static fn (string $k): bool => str_starts_with($k, 'promptlib_')));
        self::assertFalse(get_transient('promptlib_rate_1_1'));
        self::assertSame(1, get_transient('someone_else'));
        self::assertArrayNotHasKey(Lifecycle::PRUNE_HOOK, State::$cron);
        foreach (State::$roles as $role) {
            foreach (Capabilities::ALL as $cap) {
                self::assertArrayNotHasKey($cap, $role['capabilities']);
            }
        }
    }

    #[RunInSeparateProcess]
    public function testUninstallKeepsDataWithTheConstant(): void
    {
        $this->publishedTemplate();
        define('PROMPTLIB_KEEP_DATA_ON_UNINSTALL', true);
        $options = State::$options;
        Uninstaller::run();
        self::assertSame($options, State::$options);
        self::assertNotNull($this->plugin->templates->locate('summary'));
    }

    /** Acceptance test 74 (case 91). */
    public function testCorruptSettingsUseSafeDefaultsWithoutAWrite(): void
    {
        update_option(Settings::OPTION, 'not an array', false);
        $settings = Settings::load();
        self::assertTrue($settings->isCorrupt());
        self::assertTrue($settings->runnerEnabled());
        self::assertSame(50, $settings->dailyRuns());
        self::assertSame(0.0, $settings->dailyCost());
        self::assertSame('not an array', get_option(Settings::OPTION));
        update_option(Settings::OPTION, ['daily_runs' => 'many'], false);
        self::assertTrue(Settings::load()->isCorrupt());
        self::assertFalse(Settings::fromArray([])->isCorrupt());
    }

    public function testSettingsValidation(): void
    {
        [, $errors] = Settings::sanitise(['provider_kind' => 'generic', 'base_url' => 'http://remote.example/v1']);
        self::assertContains('base_url', $errors);
        [$clean, $errors] = Settings::sanitise(['provider_kind' => 'generic', 'base_url' => 'http://localhost:11434/v1', 'models' => "a/b\nc:d", 'price_table' => [['model' => 'a/b', 'input' => '1', 'output' => '2', 'taken' => '2026-11-01']]]);
        self::assertSame([], $errors);
        self::assertSame(['a/b', 'c:d'], $clean['models']);
        self::assertSame(1.0, $clean['price_table'][0]['input']);
        [, $errors] = Settings::sanitise(['price_table' => [['model' => 'x', 'input' => '1', 'output' => '2', 'taken' => 'yesterday']]]);
        self::assertContains('price_table', $errors);
        self::assertSame(8192, Settings::defaults()['max_token_cap']);
        self::assertSame(2048, Settings::defaults()['default_token_cap']);
    }

    /** Acceptance test 75 (cases 92 to 94), stub-level: key rule and super admin. Isolation needs integration. */
    #[RunInSeparateProcess]
    public function testNetworkKeyRuleAndSuperAdmin(): void
    {
        define('PROMPTLIB_PROVIDER_KEY', 'network-key');
        update_option(Settings::KEY_OPTION, 'site-key', false);
        self::assertSame('network-key', Settings::providerKey());
        State::$multisite = true;
        self::assertSame('network-key', Settings::providerKey());
        define('PROMPTLIB_ALLOW_SITE_KEY', true);
        self::assertSame('site-key', Settings::providerKey());
        State::addUser(9, 'super', [], true);
        foreach (Capabilities::ALL as $cap) {
            self::assertTrue(user_can(9, $cap));
        }
    }

    /** Acceptance test 76 (case 95): each event fires its action and a log line. */
    public function testEventsFireActionsAndLogLines(): void
    {
        $messages = [];
        add_action('promptlib_log', static function (string $level, string $message, array $context) use (&$messages): void {
            $messages[] = $message;
            foreach (['system', 'values', 'answer'] as $forbidden) {
                if (array_key_exists($forbidden, $context)) {
                    throw new \LogicException('Prompt text in a log line.');
                }
            }
        }, 10, 3);
        $id = $this->publishedTemplate();
        wp_trash_post($id);
        wp_untrash_post($id);
        $doc = $this->plugin->importExport->export($id);
        $this->plugin->importExport->import($doc, self::ADMIN);
        wp_delete_post($id, true);
        foreach (['Version published.', 'Live version changed.', 'Template trashed.', 'Template restored from the trash.', 'Template imported into the draft.', 'Template permanently deleted.'] as $expected) {
            self::assertContains($expected, $messages);
        }
        foreach (['promptlib_version_published', 'promptlib_live_changed', 'promptlib_template_trashed', 'promptlib_template_restored', 'promptlib_imported', 'promptlib_template_deleted'] as $hook) {
            self::assertNotEmpty($this->actions($hook), $hook);
        }
    }

    /** Acceptance test 77 (case 98). */
    public function testSiteHealthReportsEachState(): void
    {
        $health = new SiteHealth($this->plugin);
        $ids = array_column($health->checks(), 'status', 'id');
        self::assertSame(['library', 'provider', 'tables', 'runner', 'today', 'integrity'], array_keys($ids));
        self::assertSame('recommended', $ids['provider']);
        $this->configureRunner();
        $this->plugin->counters->add($this->plugin->clock->siteDay(), CounterRepository::RUNS, 3);
        $checks = array_column($health->checks(), null, 'id');
        self::assertSame('good', $checks['provider']['status']);
        self::assertSame('3 of 50.', $checks['today']['detail']);
        self::assertSame('On.', $checks['runner']['detail']);
        update_option('promptlib_integrity_errors', ['x#1' => ['slug' => 'x', 'version' => 1]], false);
        self::assertSame('critical', $health->result()['status']);
        $tests = $health->tests([]);
        self::assertArrayHasKey('promptlib_status', $tests['direct']);
    }

    public function testDailyPrune(): void
    {
        $this->configureRunner();
        $id = $this->publishedTemplate();
        $this->plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
        State::$now += 31 * 86400;
        do_action(Lifecycle::PRUNE_HOOK);
        global $wpdb;
        self::assertSame([], $wpdb->rows[$wpdb->prefix . 'promptlib_runs']);
        self::assertSame([], $wpdb->counters);
    }
}
