<?php

// WordPress functions for the stub layer: hooks, options, transients, cache,
// posts and meta (with the real unslashing behaviour), users and roles.

declare(strict_types=1);

use IndianOpinion\PromptLibrary\Tests\Stubs\State;

const ARRAY_A = 'ARRAY_A';
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Hooks.
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    State::$hooks[$hook][$priority][] = [$callback, $args];
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_all_filters(string $hook): void
{
    unset(State::$hooks[$hook]);
}

function has_filter(string $hook): bool
{
    return !empty(State::$hooks[$hook]);
}

/**
 * @param mixed $value
 * @param mixed ...$args
 * @return mixed
 */
function apply_filters(string $hook, $value, ...$args)
{
    $callbacks = State::$hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $count));
        }
    }
    return $value;
}

/** @param mixed ...$args */
function do_action(string $hook, ...$args): void
{
    State::$actionsFired[$hook] = (State::$actionsFired[$hook] ?? 0) + 1;
    State::$actionLog[] = [$hook, $args];
    $callbacks = State::$hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $callback(...array_slice($args, 0, max(1, $count)));
        }
    }
}

function did_action(string $hook): int
{
    return State::$actionsFired[$hook] ?? 0;
}

// Options and transients.
/**
 * @param mixed $default
 * @return mixed
 */
function get_option(string $name, $default = false)
{
    return array_key_exists($name, State::$options) ? State::$options[$name] : $default;
}

/** @param mixed $value */
function update_option(string $name, $value, $autoload = null): bool
{
    State::$options[$name] = $value;
    State::$autoload[$name] = $autoload === null ? true : (bool) $autoload;
    return true;
}

/** @param mixed $value */
function add_option(string $name, $value = '', string $deprecated = '', $autoload = true): bool
{
    if (array_key_exists($name, State::$options)) {
        return false;
    }
    State::$options[$name] = $value;
    State::$autoload[$name] = (bool) $autoload;
    return true;
}

function delete_option(string $name): bool
{
    unset(State::$options[$name]);
    return true;
}

/** @return mixed */
function get_transient(string $name)
{
    if (!isset(State::$transients[$name])) {
        return false;
    }
    [$value, $expires] = State::$transients[$name];
    if ($expires > 0 && $expires <= State::$now) {
        unset(State::$transients[$name]);
        return false;
    }
    return $value;
}

/** @param mixed $value */
function set_transient(string $name, $value, int $ttl = 0): bool
{
    State::$transients[$name] = [$value, $ttl > 0 ? State::$now + $ttl : 0];
    return true;
}

function delete_transient(string $name): bool
{
    unset(State::$transients[$name]);
    return true;
}

// Object cache.
/** @return mixed */
function wp_cache_get(string $key, string $group = '')
{
    return State::$cache[$group . ':' . $key] ?? false;
}

/** @param mixed $value */
function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
{
    State::$cache[$group . ':' . $key] = $value;
    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(State::$cache[$group . ':' . $key]);
    return true;
}

function wp_cache_supports(string $feature): bool
{
    return true;
}

function wp_cache_flush_group(string $group): bool
{
    foreach (array_keys(State::$cache) as $key) {
        if (str_starts_with($key, $group . ':')) {
            unset(State::$cache[$key]);
        }
    }
    return true;
}

// Slashing, as WordPress does it.
/**
 * @param mixed $value
 * @return mixed
 */
function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }
    return is_string($value) ? addslashes($value) : $value;
}

/**
 * @param mixed $value
 * @return mixed
 */
function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
}

// Posts.
function register_post_type(string $type, array $args = []): object
{
    State::$postTypes[$type] = $args;
    return (object) $args;
}

function register_post_meta(string $type, string $key, array $args): bool
{
    State::$registeredMeta[$type][$key] = $args;
    return true;
}

/** @param mixed $thing */
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/** @return int|WP_Error */
function wp_insert_post(array $postarr, bool $wpError = false)
{
    $postarr = wp_unslash($postarr);
    $postarr['post_content'] = apply_filters('content_save_pre', (string) ($postarr['post_content'] ?? ''));
    $id = State::$nextPostId++;
    $type = (string) ($postarr['post_type'] ?? 'post');
    $name = (string) ($postarr['post_name'] ?? '');
    if ($name !== '' && !in_array($postarr['post_status'] ?? 'draft', ['draft', 'auto-draft', 'pending'], true)) {
        $original = $name;
        foreach (State::$posts as $other) {
            if ($other->post_type === $type && $other->post_name === $name) {
                $name = $original . '-2';
            }
        }
        $name = apply_filters('wp_unique_post_slug', $name, $id, (string) ($postarr['post_status'] ?? 'publish'), $type, 0, $original);
    }
    State::$posts[$id] = new WP_Post(['ID' => $id, 'post_name' => $name] + $postarr);
    State::$meta[$id] = [];
    return $id;
}

/** @return int|WP_Error */
function wp_update_post(array $postarr, bool $wpError = false)
{
    $postarr = wp_unslash($postarr);
    $id = (int) ($postarr['ID'] ?? 0);
    if (!isset(State::$posts[$id])) {
        return $wpError ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
    }
    if (array_key_exists('post_content', $postarr)) {
        $postarr['post_content'] = apply_filters('content_save_pre', (string) $postarr['post_content']);
    }
    foreach ($postarr as $key => $value) {
        if ($key !== 'ID' && property_exists(State::$posts[$id], $key)) {
            State::$posts[$id]->{$key} = $value;
        }
    }
    return $id;
}

function get_post(int $id): ?WP_Post
{
    return State::$posts[$id] ?? null;
}

/** @return string|false */
function get_post_type(int $id)
{
    return isset(State::$posts[$id]) ? State::$posts[$id]->post_type : false;
}

/** @return list<WP_Post|int> */
function get_posts(array $args = []): array
{
    $statuses = $args['post_status'] ?? 'publish';
    $statuses = $statuses === 'any' ? ['publish', 'draft', 'private', 'pending'] : (array) $statuses;
    $out = [];
    ksort(State::$posts);
    foreach (State::$posts as $post) {
        if (($args['post_type'] ?? $post->post_type) !== $post->post_type || !in_array($post->post_status, $statuses, true)) {
            continue;
        }
        $out[] = ($args['fields'] ?? '') === 'ids' ? $post->ID : $post;
    }
    return $out;
}

/** @return WP_Post|false */
function wp_trash_post(int $id)
{
    if (!isset(State::$posts[$id])) {
        return false;
    }
    State::$posts[$id]->post_status = 'trash';
    do_action('trashed_post', $id);
    return State::$posts[$id];
}

/** @return WP_Post|false */
function wp_untrash_post(int $id)
{
    if (!isset(State::$posts[$id])) {
        return false;
    }
    State::$posts[$id]->post_status = apply_filters('wp_untrash_post_status', 'draft', $id, 'publish');
    do_action('untrashed_post', $id, 'publish');
    return State::$posts[$id];
}

/** @return WP_Post|false|null */
function wp_delete_post(int $id, bool $force = false)
{
    if (!isset(State::$posts[$id])) {
        return false;
    }
    $post = State::$posts[$id];
    do_action('before_delete_post', $id, $post);
    foreach (State::$posts as $other) {
        if ($other->post_parent === $id && in_array($other->post_type, ['revision'], true)) {
            unset(State::$posts[$other->ID], State::$meta[$other->ID]);
        }
    }
    unset(State::$posts[$id], State::$meta[$id]);
    do_action('deleted_post', $id, $post);
    return $post;
}

/** @return mixed */
function get_post_meta(int $id, string $key = '', bool $single = false)
{
    $value = State::$meta[$id][$key] ?? null;
    if ($value === null) {
        return $single ? '' : [];
    }
    return $single ? $value : [$value];
}

/** @param mixed $value */
function update_post_meta(int $id, string $key, $value): bool
{
    return update_metadata('post', $id, $key, $value);
}

/** @param mixed $value */
function update_metadata(string $type, int $id, string $key, $value): bool
{
    // The meta API unslashes its input; callers must slash first.
    $value = wp_unslash($value);
    $value = apply_filters('sanitize_post_meta_' . $key, $value, $key, $type);
    State::$meta[$id][$key] = is_array($value) || is_object($value) ? unserialize(serialize($value)) : $value;
    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset(State::$meta[$id][$key]);
    return true;
}

/** Copies the revisioned meta keys onto a new revision, as WordPress 6.4 does. */
function wp_save_post_revision(int $id): ?int
{
    $post = State::$posts[$id] ?? null;
    if ($post === null) {
        return null;
    }
    $revisions = (int) apply_filters('wp_revisions_to_keep', -1, $post);
    if ($revisions === 0) {
        return null;
    }
    $revisionId = State::$nextPostId++;
    State::$posts[$revisionId] = new WP_Post(['ID' => $revisionId, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_title' => $post->post_title]);
    State::$meta[$revisionId] = [];
    foreach (State::$registeredMeta[$post->post_type] ?? [] as $key => $args) {
        if (!empty($args['revisions_enabled']) && isset(State::$meta[$id][$key])) {
            State::$meta[$revisionId][$key] = State::$meta[$id][$key];
        }
    }
    return $revisionId;
}

/** @return int|WP_Error */
function wp_create_post_autosave(array $postData)
{
    $postData = wp_unslash($postData);
    $parent = (int) $postData['post_ID'];
    foreach (State::$posts as $post) {
        if ($post->post_parent === $parent && $post->post_name === $parent . '-autosave-v1') {
            $post->post_title = (string) ($postData['post_title'] ?? '');
            return $post->ID;
        }
    }
    $id = State::$nextPostId++;
    State::$posts[$id] = new WP_Post(['ID' => $id, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_name' => $parent . '-autosave-v1', 'post_title' => (string) ($postData['post_title'] ?? '')]);
    State::$meta[$id] = [];
    return $id;
}

// Users and capabilities.
function get_current_user_id(): int
{
    return State::$currentUser;
}

function wp_set_current_user(int $id): void
{
    State::$currentUser = $id;
}

/** @param mixed ...$args */
function user_can(int $userId, string $cap, ...$args): bool
{
    $user = State::$users[$userId] ?? null;
    if ($user === null) {
        return false;
    }
    if ($user['super']) {
        return true;
    }
    $required = [$cap];
    if (in_array($cap, ['edit_post', 'delete_post', 'read_post'], true)) {
        $required = apply_filters('map_meta_cap', ['promptlib_edit'], $cap, $userId, $args);
    }
    $caps = State::capsOf($userId);
    foreach ($required as $needed) {
        if (!in_array($needed, $caps, true)) {
            return false;
        }
    }
    return true;
}

/** @param mixed ...$args */
function current_user_can(string $cap, ...$args): bool
{
    return user_can(State::$currentUser, $cap, ...$args);
}

/** @return WP_User|false */
function get_userdata(int $id)
{
    return isset(State::$users[$id]) ? new WP_User($id) : false;
}

/** @return WP_User|false */
function get_user_by(string $field, string $value)
{
    foreach (State::$users as $id => $user) {
        if (($field === 'login' && $user['login'] === $value) || ($field === 'email' && $user['email'] === $value)) {
            return new WP_User($id);
        }
    }
    return false;
}

function get_role(string $role): ?WP_Role
{
    return isset(State::$roles[$role]) ? new WP_Role($role) : null;
}

function wp_roles(): WP_Roles
{
    return new WP_Roles();
}

// Cron.
/** @return int|false */
function wp_next_scheduled(string $hook)
{
    return isset(State::$cron[$hook]) ? State::$cron[$hook][0] : false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    State::$cron[$hook] = [$time, $recurrence];
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(State::$cron[$hook]) ? 1 : 0;
    unset(State::$cron[$hook]);
    return $had;
}

// Schema.
/** @return array<string, string> */
function dbDelta(string $sql): array
{
    $GLOBALS['wpdb']->createFromSql($sql);
    return [];
}

// REST.
function register_rest_route(string $namespace, string $route, array $args = []): bool
{
    State::$restRoutes[$namespace . $route] = $args;
    return true;
}

// Misc.
function is_multisite(): bool
{
    return State::$multisite;
}

function is_admin(): bool
{
    return false;
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(State::$timezone);
}

/** @param mixed $value */
function wp_json_encode($value, int $flags = 0)
{
    return json_encode($value, $flags);
}

function wp_generate_uuid4(): string
{
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function absint($value): int
{
    return abs((int) $value);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}

function wp_kses_post(string $text): string
{
    return $text;
}

function __return_false(): bool
{
    return false;
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}

/** @param array<string, mixed> $args */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
