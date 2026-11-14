<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Activation, deactivation, multisite, the daily prune, and the post
 * lifecycle hooks for trash, restore and permanent delete (cases 81 to 86, 92).
 */
class Lifecycle
{
    public const PRUNE_HOOK = 'promptlib_daily_prune';

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly RunRepository $runs,
        private readonly CounterRepository $counters,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** Activation on one site. */
    public static function activateSite(): void
    {
        $missing = Schema::install();
        Capabilities::grant();
        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
        if ($missing !== []) {
            (new Logger())->error('Prompt library tables are missing columns; the test runner is off.', ['missing' => $missing]);
        }
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::activateSite();
                restore_current_blog();
            }
            return;
        }
        self::activateSite();
    }

    /** Deactivation changes nothing but the schedule (case 83). */
    public static function deactivate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                wp_clear_scheduled_hook(self::PRUNE_HOOK);
                restore_current_blog();
            }
            return;
        }
        wp_clear_scheduled_hook(self::PRUNE_HOOK);
    }

    /** A new site on a network where the plugin is network-active (case 92). */
    public static function newSite(\WP_Site $site): void
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network(plugin_basename(PROMPTLIB_FILE))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        self::activateSite();
        restore_current_blog();
    }

    /** Daily: run rows by age and count, counters older than 7 days. */
    public function prune(): void
    {
        $days = max(1, (int) apply_filters('promptlib_run_retention_days', Settings::load()->retentionDays()));
        $cutoff = $this->clock->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $this->runs->prune($cutoff, RunRepository::MAX_ROWS);
        $this->counters->prune($this->clock->siteDay(), 7);
    }

    public function onTrashed(int $postId): void
    {
        $post = $this->templates->post($postId);
        if ($post === null) {
            return;
        }
        $slug = $this->templates->slugOf($postId);
        $this->templates->forget($slug);
        $this->versions->insertEvent($postId, $slug, 'trash', null, null, '', get_current_user_id(), $this->clock->nowUtc());
        do_action('promptlib_template_trashed', $postId, $slug);
        $this->logger->info('Template trashed.', ['template_id' => $postId, 'slug' => $slug, 'user_id' => get_current_user_id()]);
    }

    public function onUntrashed(int $postId): void
    {
        $post = $this->templates->post($postId);
        if ($post === null) {
            return;
        }
        $slug = $this->templates->slugOf($postId);
        $this->templates->forget($slug);
        $this->versions->insertEvent($postId, $slug, 'restore', null, null, '', get_current_user_id(), $this->clock->nowUtc());
        do_action('promptlib_template_restored', $postId, $slug);
        $this->logger->info('Template restored from the trash.', ['template_id' => $postId, 'slug' => $slug, 'user_id' => get_current_user_id()]);
    }

    /** Restored templates come back as they were, not as drafts. */
    public static function untrashStatus(string $status, int $postId): string
    {
        return get_post_type($postId) === PostType::NAME ? 'publish' : $status;
    }

    public function onDelete(int $postId): void
    {
        if (get_post_type($postId) !== PostType::NAME) {
            return;
        }
        $slug = $this->templates->slugOf($postId);
        $this->versions->deleteTemplate($postId);
        $this->runs->deleteForTemplate($postId);
        $this->templates->forget($slug);
        do_action('promptlib_template_deleted', $postId, $slug);
        $this->logger->info('Template permanently deleted.', ['template_id' => $postId, 'slug' => $slug, 'user_id' => get_current_user_id()]);
    }

    /**
     * Consumers seen rendering this template in the last 7 days (case 84).
     *
     * @return list<string>
     */
    public function recentConsumers(int $postId): array
    {
        $since = $this->clock->timestamp() - 7 * DAY_IN_SECONDS;
        $seen = [];
        foreach ($this->templates->lastRendered($postId) as $entry) {
            if (($entry['time'] ?? 0) < $since) {
                continue;
            }
            foreach ($entry['consumers'] ?? [] as $consumer => $time) {
                if ($time >= $since) {
                    $seen[] = (string) $consumer;
                }
            }
        }

        return array_values(array_unique($seen));
    }
}
