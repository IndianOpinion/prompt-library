<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Removes the template posts with their meta and revisions, the tables, the
 * options, the transients, the cron event and the capabilities. Never touches
 * anything it did not create.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (defined('PROMPTLIB_KEEP_DATA_ON_UNINSTALL') && constant('PROMPTLIB_KEEP_DATA_ON_UNINSTALL') === true) {
            return;
        }
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        $ids = get_posts([
            'post_type' => PostType::NAME,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        $trashed = get_posts([
            'post_type' => PostType::NAME,
            'post_status' => 'trash',
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        foreach (array_unique(array_merge($ids, $trashed)) as $id) {
            // Deletes revisions and every meta row of the post with it.
            wp_delete_post((int) $id, true);
        }
        Schema::drop();
        $wpdb->query($wpdb->prepare(
            "/*promptlib:uninstall.options*/ DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('promptlib_') . '%',
            $wpdb->esc_like('_transient_promptlib_') . '%',
            $wpdb->esc_like('_transient_timeout_promptlib_') . '%'
        ));
        wp_clear_scheduled_hook(Lifecycle::PRUNE_HOOK);
        Capabilities::revokeAll();
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(TemplateRepository::CACHE_GROUP);
        }
    }
}
