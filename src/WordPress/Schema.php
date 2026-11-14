<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Tables, the schema version and the column check (cases 81 and 82, lesson 6).
 *
 * dbDelta can silently skip a column addition, so after every create or
 * upgrade every expected column is checked. A missing column puts the plugin
 * in a degraded state: an admin notice, a Site Health failure, and the runner
 * off. Rendering for consumers keeps working when the versions table is whole.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'promptlib_schema_version';
    public const STATE_OPTION = 'promptlib_schema_state';

    /** @return array<string, list<string>> table suffix => columns */
    public static function expectedColumns(): array
    {
        return [
            'promptlib_versions' => [
                'id', 'template_id', 'slug', 'type', 'version_no', 'definition', 'content_hash',
                'note', 'user_id', 'from_version', 'to_version', 'reason', 'created_at',
            ],
            'promptlib_runs' => [
                'id', 'template_id', 'slug', 'version_no', 'user_id', 'model', 'provider', 'status',
                'error_code', 'prompt_tokens', 'completion_tokens', 'cost_nanos', 'cost_source',
                'duration_ms', 'attempts', 'system_text', 'user_text', 'answer', 'compare_group',
                'created_at',
            ],
            'promptlib_counters' => ['day', 'metric', 'value'],
        ];
    }

    public static function table(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . $suffix;
    }

    /** @return list<string> */
    public static function createStatements(): array
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $versions = self::table('promptlib_versions');
        $runs = self::table('promptlib_runs');
        $counters = self::table('promptlib_counters');

        return [
            "CREATE TABLE {$versions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_id bigint(20) unsigned NOT NULL,
  slug varchar(100) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'version',
  version_no int(10) unsigned DEFAULT NULL,
  definition longtext NULL,
  content_hash char(64) DEFAULT NULL,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  from_version int(10) unsigned DEFAULT NULL,
  to_version int(10) unsigned DEFAULT NULL,
  reason text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY template_version (template_id,version_no),
  KEY slug_version (slug,version_no),
  KEY template_type (template_id,type)
) {$charset};",
            "CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_id bigint(20) unsigned NOT NULL,
  slug varchar(100) NOT NULL,
  version_no int(10) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  model varchar(200) NOT NULL DEFAULT '',
  provider varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  error_code varchar(64) NOT NULL DEFAULT '',
  prompt_tokens int(10) unsigned DEFAULT NULL,
  completion_tokens int(10) unsigned DEFAULT NULL,
  cost_nanos bigint(20) unsigned DEFAULT NULL,
  cost_source varchar(20) NOT NULL DEFAULT 'unknown',
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  system_text longtext NULL,
  user_text longtext NULL,
  answer longtext NULL,
  compare_group varchar(36) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY template_id (template_id),
  KEY created_at (created_at)
) {$charset};",
            "CREATE TABLE {$counters} (
  day date NOT NULL,
  metric varchar(64) NOT NULL,
  value bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric)
) {$charset};",
        ];
    }

    /**
     * Create or upgrade the tables, then check every column. Returns the
     * list of missing "table.column" names (empty when healthy).
     *
     * @return list<string>
     */
    public static function install(): array
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach (self::createStatements() as $sql) {
            dbDelta($sql);
        }
        $missing = self::missingColumns();
        update_option(self::STATE_OPTION, ['missing' => $missing], true);
        update_option(self::VERSION_OPTION, self::VERSION, true);

        return $missing;
    }

    /** Run install() when the stored schema version is older (case 82). */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    /** @return list<string> */
    public static function missingColumns(): array
    {
        global $wpdb;
        $missing = [];
        foreach (self::expectedColumns() as $suffix => $columns) {
            $table = self::table($suffix);
            $found = $wpdb->get_col("/*promptlib:schema.columns*/ SHOW COLUMNS FROM `{$table}`", 0);
            $found = is_array($found) ? array_map('strval', $found) : [];
            foreach ($columns as $column) {
                if (!in_array($column, $found, true)) {
                    $missing[] = $suffix . '.' . $column;
                }
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public static function storedMissing(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        $missing = is_array($state) ? ($state['missing'] ?? []) : [];

        return is_array($missing) ? array_values(array_map('strval', $missing)) : [];
    }

    public static function isDegraded(): bool
    {
        return self::storedMissing() !== [];
    }

    /** Drop every table (uninstall only). */
    public static function drop(): void
    {
        global $wpdb;
        foreach (array_keys(self::expectedColumns()) as $suffix) {
            $table = self::table($suffix);
            $wpdb->query("/*promptlib:schema.drop*/ DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
