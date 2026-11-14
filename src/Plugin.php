<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

use IndianOpinion\PromptLibrary\Admin\Admin;
use IndianOpinion\PromptLibrary\Cli\Command;
use IndianOpinion\PromptLibrary\Rest\Controller;
use IndianOpinion\PromptLibrary\Runner\LlmJsonGateway;
use IndianOpinion\PromptLibrary\Runner\ModelGateway;
use IndianOpinion\PromptLibrary\Runner\RunGuard;
use IndianOpinion\PromptLibrary\Runner\Runner;
use IndianOpinion\PromptLibrary\Runner\SpendMeter;
use IndianOpinion\PromptLibrary\WordPress\Clock;
use IndianOpinion\PromptLibrary\WordPress\Consumers;
use IndianOpinion\PromptLibrary\WordPress\CounterRepository;
use IndianOpinion\PromptLibrary\WordPress\Defaults;
use IndianOpinion\PromptLibrary\WordPress\Drafts;
use IndianOpinion\PromptLibrary\WordPress\ImportExport;
use IndianOpinion\PromptLibrary\WordPress\Library;
use IndianOpinion\PromptLibrary\WordPress\Lifecycle;
use IndianOpinion\PromptLibrary\WordPress\Logger;
use IndianOpinion\PromptLibrary\WordPress\PostType;
use IndianOpinion\PromptLibrary\WordPress\Privacy;
use IndianOpinion\PromptLibrary\WordPress\Publisher;
use IndianOpinion\PromptLibrary\WordPress\RunRepository;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\SiteHealth;
use IndianOpinion\PromptLibrary\WordPress\TemplateRepository;
use IndianOpinion\PromptLibrary\WordPress\VersionRepository;

/**
 * Service container and hook wiring. One instance per request.
 */
final class Plugin
{
    public const API_VERSION = '1.0';
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    public readonly Clock $clock;
    public readonly Logger $logger;
    public readonly TemplateRepository $templates;
    public readonly VersionRepository $versions;
    public readonly RunRepository $runs;
    public readonly CounterRepository $counters;
    public readonly Consumers $consumers;
    public readonly Library $library;
    public readonly Drafts $drafts;
    public readonly Publisher $publisher;
    public readonly Defaults $defaults;
    public readonly ImportExport $importExport;
    public readonly Lifecycle $lifecycle;
    public readonly SpendMeter $meter;
    public readonly Runner $runner;

    public function __construct(?ModelGateway $gateway = null, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new Clock();
        $this->logger = new Logger();
        $this->templates = new TemplateRepository();
        $this->versions = new VersionRepository();
        $this->runs = new RunRepository();
        $this->counters = new CounterRepository();
        $this->consumers = new Consumers();
        $this->library = new Library($this->templates, $this->versions, $this->consumers, $this->clock, $this->logger);
        $this->drafts = new Drafts($this->templates, $this->versions, $this->logger);
        $this->publisher = new Publisher($this->templates, $this->versions, $this->consumers, $this->clock, $this->logger);
        $this->defaults = new Defaults($this->templates, $this->versions, $this->consumers, $this->drafts, $this->publisher, $this->clock, $this->logger);
        $this->importExport = new ImportExport($this->templates, $this->versions, $this->drafts, $this->clock, $this->logger);
        $this->lifecycle = new Lifecycle($this->templates, $this->versions, $this->runs, $this->counters, $this->clock, $this->logger);
        $this->meter = new SpendMeter($this->logger);
        $this->runner = new Runner(
            $this->templates,
            $this->library,
            $this->counters,
            $this->runs,
            $this->meter,
            $gateway ?? new LlmJsonGateway(),
            new RunGuard($this->clock),
            $this->clock,
            $this->logger,
        );
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Replace the instance (tests, or a site that wires its own gateway). */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    /** Register every hook. Called once from the main plugin file. */
    public function boot(): void
    {
        add_action('init', [PostType::class, 'register']);
        add_filter('use_block_editor_for_post_type', [PostType::class, 'useBlockEditor'], 10, 2);
        add_filter('map_meta_cap', [PostType::class, 'mapMetaCap'], 10, 4);
        add_filter('wp_unique_post_slug', [PostType::class, 'keepSlug'], 10, 6);
        add_filter('wp_untrash_post_status', [Lifecycle::class, 'untrashStatus'], 10, 2);
        add_filter('promptlib_render', [$this->library, 'filterRender'], 10, 5);

        add_action('trashed_post', [$this->lifecycle, 'onTrashed']);
        add_action('untrashed_post', [$this->lifecycle, 'onUntrashed']);
        add_action('before_delete_post', [$this->lifecycle, 'onDelete']);
        add_action(Lifecycle::PRUNE_HOOK, [$this->lifecycle, 'prune']);
        add_action('wp_initialize_site', [Lifecycle::class, 'newSite'], 200);

        add_action('admin_init', [Schema::class, 'maybeUpgrade'], 5);
        add_action('admin_init', [$this->defaults, 'process'], 20);

        add_action('rest_api_init', function (): void {
            (new Controller($this))->register();
        });
        (new SiteHealth($this))->register();
        (new Privacy($this))->register();
        if (is_admin()) {
            (new Admin($this))->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('prompt-library', new Command($this));
        }
    }
}
