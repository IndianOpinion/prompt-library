<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\CanonicalJson;
use IndianOpinion\PromptLibrary\Exception\StorageException;

/**
 * Template posts and their meta. Prompt text lives only in meta, never in
 * post content (lessons 1 to 3): post_content stays empty, and every meta
 * write is slashed first because the meta API unslashes its input.
 */
class TemplateRepository
{
    public const CACHE_GROUP = 'promptlib';
    public const LOCATE_TTL = 60;

    /**
     * Find a template by slug in one query, including trashed ones.
     * Cached for 60 seconds and invalidated on every change (case 88).
     *
     * @return array{id: int, status: string, live: int}|null
     */
    public function locate(string $slug): ?array
    {
        $key = 't:' . $slug;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached['id'] === 0 ? null : $cached;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "/*promptlib:template.locate*/ SELECT p.ID AS id, p.post_status AS status, live.meta_value AS live
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} live ON live.post_id = p.ID AND live.meta_key = %s
             WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft', 'inherit')
             ORDER BY p.ID ASC LIMIT 1",
            PostType::META_SLUG,
            $slug,
            PostType::META_LIVE,
            PostType::NAME
        ), ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            throw new StorageException('Prompt library storage read failed: ' . (string) $wpdb->last_error);
        }
        $found = is_array($row)
            ? ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'live' => (int) ($row['live'] ?? 0)]
            : ['id' => 0, 'status' => '', 'live' => 0];
        wp_cache_set($key, $found, self::CACHE_GROUP, self::LOCATE_TTL);

        return $found['id'] === 0 ? null : $found;
    }

    public function forget(string $slug): void
    {
        wp_cache_delete('t:' . $slug, self::CACHE_GROUP);
    }

    /**
     * Create the post for a new template. The caller has checked the slug.
     *
     * @param array<string, mixed> $draft
     */
    public function create(string $slug, array $draft, int $userId): int
    {
        $id = wp_insert_post(wp_slash([
            'post_type' => PostType::NAME,
            'post_status' => 'publish',
            'post_title' => (string) ($draft['title'] ?? $slug),
            'post_excerpt' => (string) ($draft['description'] ?? ''),
            'post_content' => '',
            'post_name' => $slug,
            'post_author' => $userId,
        ]), true);
        if (is_wp_error($id) || !is_int($id) || $id === 0) {
            throw new StorageException('Could not create the template post.');
        }
        update_post_meta($id, PostType::META_SLUG, $slug);
        $this->writeDraft($id, $draft);
        $this->forget($slug);

        return $id;
    }

    public function post(int $id): ?\WP_Post
    {
        $post = get_post($id);

        return $post instanceof \WP_Post && $post->post_type === PostType::NAME ? $post : null;
    }

    public function slugOf(int $id): string
    {
        return (string) get_post_meta($id, PostType::META_SLUG, true);
    }

    /** @return array<string, mixed> */
    public function draft(int $id): array
    {
        $draft = get_post_meta($id, PostType::META_DRAFT, true);

        return is_array($draft) ? $draft : [];
    }

    /**
     * Store the draft and title, then let WordPress record a revision that
     * carries the draft meta (case 45).
     *
     * @param array<string, mixed> $draft
     */
    public function saveDraft(int $id, array $draft): void
    {
        $this->writeDraft($id, $draft);
        $result = wp_update_post(wp_slash([
            'ID' => $id,
            'post_title' => (string) ($draft['title'] ?? ''),
            'post_excerpt' => (string) ($draft['description'] ?? ''),
            'post_content' => '',
        ]), true);
        if (is_wp_error($result)) {
            throw new StorageException('Could not update the template post.');
        }
        wp_save_post_revision($id);
    }

    /** @param array<string, mixed> $draft */
    private function writeDraft(int $id, array $draft): void
    {
        update_post_meta($id, PostType::META_DRAFT, wp_slash($draft));
    }

    /** @param array<string, mixed> $draft */
    public static function draftHash(array $draft): string
    {
        return CanonicalJson::hash($draft);
    }

    public function live(int $id): int
    {
        return (int) get_post_meta($id, PostType::META_LIVE, true);
    }

    public function setLive(int $id, int $version): void
    {
        update_post_meta($id, PostType::META_LIVE, $version);
        $this->forget($this->slugOf($id));
    }

    /** @return array<string, array<string, mixed>> fixture name => values */
    public function fixtures(int $id): array
    {
        $fixtures = get_post_meta($id, PostType::META_FIXTURES, true);

        return is_array($fixtures) ? $fixtures : [];
    }

    /** @param array<string, array<string, mixed>> $fixtures */
    public function saveFixtures(int $id, array $fixtures): void
    {
        update_post_meta($id, PostType::META_FIXTURES, wp_slash($fixtures));
    }

    /** @return array<string, array{hash: string, pending: bool, seen: int}> */
    public function offers(int $id): array
    {
        $offers = get_post_meta($id, PostType::META_OFFERS, true);

        return is_array($offers) ? $offers : [];
    }

    /** @param array<string, array{hash: string, pending: bool, seen: int}> $offers */
    public function saveOffers(int $id, array $offers): void
    {
        update_post_meta($id, PostType::META_OFFERS, wp_slash($offers));
    }

    public function installer(int $id): string
    {
        return (string) get_post_meta($id, PostType::META_INSTALLER, true);
    }

    public function setInstaller(int $id, string $consumer): void
    {
        update_post_meta($id, PostType::META_INSTALLER, wp_slash($consumer));
    }

    /** @return array<int, array{time: int, consumers: array<string, int>}> version => last render */
    public function lastRendered(int $id): array
    {
        $data = get_post_meta($id, PostType::META_LAST_RENDERED, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<int, array{time: int, consumers: array<string, int>}> $data */
    public function saveLastRendered(int $id, array $data): void
    {
        update_post_meta($id, PostType::META_LAST_RENDERED, wp_slash($data));
    }

    /**
     * Every template post, trashed ones included.
     *
     * @return list<\WP_Post>
     */
    public function all(bool $includeTrash = false): array
    {
        $posts = get_posts([
            'post_type' => PostType::NAME,
            'post_status' => $includeTrash ? ['publish', 'trash', 'draft', 'private'] : ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

        return array_values(array_filter($posts, static fn ($p): bool => $p instanceof \WP_Post));
    }
}
