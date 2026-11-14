<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Stubs;

/**
 * In-memory state behind the WordPress stubs. Reset before every test.
 */
final class State
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $actionsFired = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actionLog = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool> */
    public static array $autoload = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    public static array $transients = [];
    /** @var array<string, mixed> */
    public static array $cache = [];
    /** @var array<int, \WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    public static int $nextPostId = 100;
    /** @var array<string, array<string, mixed>> */
    public static array $postTypes = [];
    /** @var array<string, array<string, array<string, mixed>>> */
    public static array $registeredMeta = [];
    public static int $currentUser = 0;
    /** @var array<int, array{login: string, email: string, roles: list<string>, caps: list<string>, super: bool}> */
    public static array $users = [];
    /** @var array<string, array{name: string, capabilities: array<string, bool>}> */
    public static array $roles = [];
    /** @var array<string, array{0: int, 1: string}> */
    public static array $cron = [];
    /** @var array<string, array<string, mixed>> */
    public static array $restRoutes = [];
    public static bool $multisite = false;
    public static string $timezone = 'UTC';
    /** @var list<string> */
    public static array $httpCalls = [];
    public static int $now = 1763100000;

    public static function reset(): void
    {
        self::$hooks = [];
        self::$actionsFired = [];
        self::$actionLog = [];
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$cache = [];
        self::$posts = [];
        self::$meta = [];
        self::$nextPostId = 100;
        self::$postTypes = [];
        self::$registeredMeta = [];
        self::$currentUser = 0;
        self::$users = [];
        self::$roles = [
            'administrator' => ['name' => 'Administrator', 'capabilities' => ['read' => true]],
            'editor' => ['name' => 'Editor', 'capabilities' => ['read' => true]],
            'author' => ['name' => 'Author', 'capabilities' => ['read' => true]],
        ];
        self::$cron = [];
        self::$restRoutes = [];
        self::$multisite = false;
        self::$timezone = 'UTC';
        self::$httpCalls = [];
        self::$now = 1763100000;
        $GLOBALS['wpdb'] = new \wpdb();
    }

    /** @param list<string> $roles */
    public static function addUser(int $id, string $login, array $roles = [], bool $super = false): void
    {
        self::$users[$id] = ['login' => $login, 'email' => $login . '@example.org', 'roles' => $roles, 'caps' => [], 'super' => $super];
    }

    /** @return list<string> */
    public static function capsOf(int $userId): array
    {
        $user = self::$users[$userId] ?? null;
        if ($user === null) {
            return [];
        }
        $caps = $user['caps'];
        foreach ($user['roles'] as $role) {
            foreach (self::$roles[$role]['capabilities'] ?? [] as $cap => $granted) {
                if ($granted) {
                    $caps[] = $cap;
                }
            }
        }

        return array_values(array_unique($caps));
    }
}
