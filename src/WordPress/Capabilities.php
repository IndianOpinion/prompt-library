<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * The plugin's own capabilities (section 8).
 */
final class Capabilities
{
    public const EDIT = 'promptlib_edit';
    public const PUBLISH = 'promptlib_publish';
    public const RUN = 'promptlib_run';
    public const MANAGE = 'promptlib_manage';
    public const ALL = [self::EDIT, self::PUBLISH, self::RUN, self::MANAGE];

    /** @return array<string, list<string>> role => capabilities */
    public static function defaultMap(): array
    {
        return [
            'administrator' => [self::EDIT, self::PUBLISH, self::RUN, self::MANAGE],
            'editor' => [self::EDIT, self::PUBLISH],
        ];
    }

    /** @return array<string, list<string>> */
    public static function map(): array
    {
        $map = apply_filters('promptlib_role_caps', self::defaultMap());

        return is_array($map) ? $map : self::defaultMap();
    }

    /** Grant the mapped capabilities (activation, or a reset by the administrator). */
    public static function grant(): void
    {
        foreach (self::map() as $roleName => $caps) {
            $role = get_role((string) $roleName);
            if ($role === null) {
                continue;
            }
            foreach ((array) $caps as $cap) {
                if (in_array($cap, self::ALL, true)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /** Remove every plugin capability from every role. */
    public static function revokeAll(): void
    {
        $roles = wp_roles();
        foreach (array_keys($roles->roles) as $roleName) {
            $role = get_role((string) $roleName);
            if ($role === null) {
                continue;
            }
            foreach (self::ALL as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    /** Reset: remove all, then grant the map again. */
    public static function reset(): void
    {
        self::revokeAll();
        self::grant();
    }
}
