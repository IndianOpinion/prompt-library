<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * The template post type (case 1, 6, 7) and its meta (section 6).
 */
final class PostType
{
    public const NAME = 'promptlib_template';

    public const META_SLUG = '_promptlib_slug';
    public const META_DRAFT = '_promptlib_draft';
    public const META_FIXTURES = '_promptlib_fixtures';
    public const META_LIVE = '_promptlib_live';
    public const META_OFFERS = '_promptlib_offers';
    public const META_INSTALLER = '_promptlib_installer';
    public const META_LAST_RENDERED = '_promptlib_last_rendered';
    public const META_KEYS = [
        self::META_SLUG, self::META_DRAFT, self::META_FIXTURES, self::META_LIVE,
        self::META_OFFERS, self::META_INSTALLER, self::META_LAST_RENDERED,
    ];

    /** @return array<string, mixed> */
    public static function args(): array
    {
        return [
            'labels' => [
                'name' => __('Prompt templates', 'prompt-library'),
                'singular_name' => __('Prompt template', 'prompt-library'),
                'menu_name' => __('Prompts', 'prompt-library'),
                'all_items' => __('All templates', 'prompt-library'),
                'add_new_item' => __('Add prompt template', 'prompt-library'),
                'edit_item' => __('Edit prompt template', 'prompt-library'),
                'search_items' => __('Search templates', 'prompt-library'),
                'not_found' => __('No prompt templates yet.', 'prompt-library'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'menu_icon' => 'dashicons-editor-code',
            'supports' => ['title', 'revisions'],
            'map_meta_cap' => true,
            'capability_type' => ['promptlib_template', 'promptlib_templates'],
            'capabilities' => [
                'edit_posts' => Capabilities::EDIT,
                'edit_others_posts' => Capabilities::EDIT,
                'edit_published_posts' => Capabilities::EDIT,
                'edit_private_posts' => Capabilities::EDIT,
                'publish_posts' => Capabilities::EDIT,
                'read_private_posts' => Capabilities::EDIT,
                'delete_posts' => Capabilities::EDIT,
                'delete_others_posts' => Capabilities::EDIT,
                'delete_published_posts' => Capabilities::EDIT,
                'delete_private_posts' => Capabilities::EDIT,
                'create_posts' => Capabilities::EDIT,
                'read' => Capabilities::EDIT,
            ],
        ];
    }

    public static function register(): void
    {
        register_post_type(self::NAME, self::args());
        $auth = static fn (): bool => current_user_can(Capabilities::EDIT);
        foreach (self::META_KEYS as $key) {
            register_post_meta(self::NAME, $key, [
                'single' => true,
                'type' => in_array($key, [self::META_SLUG, self::META_INSTALLER], true) ? 'string' : ($key === self::META_LIVE ? 'integer' : 'array'),
                'show_in_rest' => false,
                'revisions_enabled' => $key === self::META_DRAFT,
                'auth_callback' => $auth,
            ]);
        }
    }

    /** The block editor is never used for templates. */
    public static function useBlockEditor(bool $use, string $postType): bool
    {
        return $postType === self::NAME ? false : $use;
    }

    /**
     * Permanently deleting a template (it is already in the trash) needs the
     * manage capability (case 85). Trashing needs edit.
     *
     * @param list<string> $caps
     * @param array<mixed> $args
     * @return list<string>
     */
    public static function mapMetaCap(array $caps, string $cap, int $userId, array $args): array
    {
        if ($cap !== 'delete_post' || !isset($args[0])) {
            return $caps;
        }
        $post = get_post((int) $args[0]);
        if (!$post instanceof \WP_Post || $post->post_type !== self::NAME) {
            return $caps;
        }

        return $post->post_status === 'trash' ? [Capabilities::MANAGE] : [Capabilities::EDIT];
    }

    /**
     * WordPress must never append "-2" to a template's post name (case 4).
     * Uniqueness is enforced by the plugin before saving.
     */
    public static function keepSlug(string $slug, int $postId, string $status, string $postType, int $parent, string $original): string
    {
        return $postType === self::NAME ? $original : $slug;
    }
}
