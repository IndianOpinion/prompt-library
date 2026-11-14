<?php

// A fake wpdb that understands this plugin's own statements by the marker
// comment each one carries (/*promptlib:name*/). It keeps the tables in
// memory, so repository code runs for real without MySQL.

declare(strict_types=1);

use IndianOpinion\PromptLibrary\Tests\Stubs\State;

class wpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    public int $rows_affected = 0;

    /** @var array<string, list<string>> table => columns */
    public array $tables = [];
    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];
    /** @var array<string, int> */
    public array $autoIncrement = [];
    /** @var array<string, int> day|metric => value */
    public array $counters = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var array<string, string> marker => error to raise once */
    public array $failOnce = [];
    /** @var list<string> columns dbDelta silently skips (lesson 6) */
    public array $skipColumns = [];
    /** @var (callable(): void)|null runs once before the next version insert */
    public $beforeVersionInsert = null;

    /** @var array<int, list<mixed>> */
    private array $prepared = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** @param mixed ...$args */
    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $id = count($this->prepared);
        $this->prepared[$id] = array_values($args);

        return $query . ' /*args:' . $id . '*/';
    }

    /** @return list<mixed> */
    private function args(string $sql): array
    {
        return preg_match('#/\*args:(\d+)\*/#', $sql, $m) === 1 ? $this->prepared[(int) $m[1]] : [];
    }

    private function marker(string $sql): string
    {
        return preg_match('#/\*promptlib:([a-z_.]+)\*/#', $sql, $m) === 1 ? $m[1] : '';
    }

    private function fail(string $marker): bool
    {
        if (isset($this->failOnce[$marker])) {
            $this->last_error = $this->failOnce[$marker];
            unset($this->failOnce[$marker]);
            return true;
        }
        $this->last_error = '';

        return false;
    }

    private function table(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    /** @return int|false */
    public function query(string $sql)
    {
        $this->queries[] = $sql;
        $marker = $this->marker($sql);
        if ($this->fail($marker)) {
            return false;
        }
        $args = $this->args($sql);
        switch ($marker) {
            case 'version.insert':
                if ($this->beforeVersionInsert !== null) {
                    $callback = $this->beforeVersionInsert;
                    $this->beforeVersionInsert = null;
                    $callback();
                }
                [$templateId, $slug, $number, $definition, $hash, $note, $userId, $created] = $args;
                foreach ($this->rows[$this->table('promptlib_versions')] ?? [] as $row) {
                    if ((int) $row['template_id'] === (int) $templateId && $row['version_no'] !== null && (int) $row['version_no'] === (int) $number) {
                        return 0;
                    }
                }
                $this->addRow('promptlib_versions', ['template_id' => (int) $templateId, 'slug' => $slug, 'type' => 'version', 'version_no' => (int) $number, 'definition' => $definition, 'content_hash' => $hash, 'note' => $note, 'user_id' => (int) $userId, 'from_version' => null, 'to_version' => null, 'reason' => null, 'created_at' => $created]);
                return 1;
            case 'counter.ensure':
                $key = $args[0] . '|' . $args[1];
                $this->counters[$key] ??= 0;
                return 1;
            case 'counter.add':
                $key = $args[1] . '|' . $args[2];
                if (!isset($this->counters[$key])) {
                    return 0;
                }
                $this->counters[$key] += (int) $args[0];
                return 1;
            case 'counter.reserve':
                $key = $args[1] . '|' . $args[2];
                if (!isset($this->counters[$key]) || $this->counters[$key] + (int) $args[3] > (int) $args[4]) {
                    return 0;
                }
                $this->counters[$key] += (int) $args[0];
                return 1;
            case 'counter.release':
                $key = $args[1] . '|' . $args[2];
                if (isset($this->counters[$key])) {
                    $this->counters[$key] = max(0, $this->counters[$key] - (int) $args[0]);
                }
                return 1;
            case 'counter.prune':
                $n = 0;
                foreach (array_keys($this->counters) as $key) {
                    if (explode('|', $key)[0] < $args[0]) {
                        unset($this->counters[$key]);
                        $n++;
                    }
                }
                return $n;
            case 'run.prune_age':
                return $this->deleteWhere('promptlib_runs', static fn (array $r): bool => $r['created_at'] < $args[0]);
            case 'run.prune_count':
                return $this->deleteWhere('promptlib_runs', static fn (array $r): bool => (int) $r['id'] <= (int) $args[0]);
            case 'schema.drop':
                if (preg_match('/`([^`]+)`/', $sql, $m) === 1) {
                    unset($this->tables[$m[1]], $this->rows[$m[1]]);
                }
                return 1;
            case 'uninstall.options':
                foreach (array_keys(State::$options) as $name) {
                    if (str_starts_with($name, 'promptlib_')) {
                        unset(State::$options[$name]);
                    }
                }
                foreach (array_keys(State::$transients) as $name) {
                    if (str_starts_with($name, 'promptlib_')) {
                        unset(State::$transients[$name]);
                    }
                }
                return 1;
        }

        throw new \LogicException('Fake wpdb does not know this statement: ' . $sql);
    }

    /** @return mixed */
    public function get_var(string $sql)
    {
        $this->queries[] = $sql;
        $marker = $this->marker($sql);
        if ($this->fail($marker)) {
            return null;
        }
        $args = $this->args($sql);
        switch ($marker) {
            case 'version.highest':
                $max = null;
                foreach ($this->rows[$this->table('promptlib_versions')] ?? [] as $row) {
                    if ((int) $row['template_id'] === (int) $args[0] && $row['type'] === 'version') {
                        $max = max((int) $max, (int) $row['version_no']);
                    }
                }
                return $max;
            case 'counter.get':
                return $this->counters[$args[0] . '|' . $args[1]] ?? null;
            case 'run.prune_threshold':
                $rows = $this->rows[$this->table('promptlib_runs')] ?? [];
                usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
                return isset($rows[(int) $args[0]]) ? $rows[(int) $args[0]]['id'] : null;
        }

        throw new \LogicException('Fake wpdb does not know this statement: ' . $sql);
    }

    /** @return list<mixed> */
    public function get_col(string $sql, int $column = 0): array
    {
        $this->queries[] = $sql;
        if ($this->marker($sql) === 'schema.columns' && preg_match('/`([^`]+)`/', $sql, $m) === 1) {
            return $this->tables[$m[1]] ?? [];
        }

        throw new \LogicException('Fake wpdb does not know this statement: ' . $sql);
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $output = 'OBJECT')
    {
        $this->queries[] = $sql;
        $marker = $this->marker($sql);
        if ($this->fail($marker)) {
            return null;
        }
        $args = $this->args($sql);
        switch ($marker) {
            case 'template.locate':
                [$slugKey, $slug, $liveKey, $type] = $args;
                ksort(State::$posts);
                foreach (State::$posts as $post) {
                    if (
                        $post->post_type === $type && !in_array($post->post_status, ['auto-draft', 'inherit'], true)
                        && (State::$meta[$post->ID][$slugKey] ?? null) === $slug
                    ) {
                        $live = State::$meta[$post->ID][$liveKey] ?? null;
                        return ['id' => (string) $post->ID, 'status' => $post->post_status, 'live' => $live === null ? null : (string) $live];
                    }
                }
                return null;
            case 'version.find':
                foreach ($this->rows[$this->table('promptlib_versions')] ?? [] as $row) {
                    if ((int) $row['template_id'] === (int) $args[0] && $row['type'] === 'version' && (int) $row['version_no'] === (int) $args[1]) {
                        return $row;
                    }
                }
                return null;
        }

        throw new \LogicException('Fake wpdb does not know this statement: ' . $sql);
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        $marker = $this->marker($sql);
        if ($this->fail($marker)) {
            return [];
        }
        $args = $this->args($sql);
        $versions = $this->rows[$this->table('promptlib_versions')] ?? [];
        $runs = $this->rows[$this->table('promptlib_runs')] ?? [];
        switch ($marker) {
            case 'version.list':
                $rows = array_values(array_filter($versions, static fn (array $r): bool => (int) $r['template_id'] === (int) $args[0] && $r['type'] === 'version'));
                usort($rows, static fn (array $a, array $b): int => $b['version_no'] <=> $a['version_no']);
                return $rows;
            case 'version.history':
                $rows = array_values(array_filter($versions, static fn (array $r): bool => (int) $r['template_id'] === (int) $args[0]));
                usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
                return $rows;
            case 'counter.day':
                $out = [];
                foreach ($this->counters as $key => $value) {
                    [$day, $metric] = explode('|', $key, 2);
                    if ($day === $args[0]) {
                        $out[] = ['metric' => $metric, 'value' => (string) $value];
                    }
                }
                return $out;
            case 'run.recent':
            case 'run.recent_user':
                $rows = $marker === 'run.recent_user' ? array_values(array_filter($runs, static fn (array $r): bool => (int) $r['user_id'] === (int) $args[0])) : $runs;
                usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
                $rows = array_map(static function (array $r): array {
                    unset($r['system_text'], $r['user_text'], $r['answer']);
                    return $r;
                }, $rows);
                return array_slice($rows, 0, (int) end($args));
            case 'run.for_user':
                $rows = array_values(array_filter($runs, static fn (array $r): bool => (int) $r['user_id'] === (int) $args[0]));
                return array_slice($rows, (int) $args[2], (int) $args[1]);
        }

        throw new \LogicException('Fake wpdb does not know this statement: ' . $sql);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string|null>|string|null $format
     * @return int|false
     */
    public function insert(string $table, array $data, $format = null)
    {
        $suffix = substr($table, strlen($this->prefix));
        if ($this->fail('insert.' . $suffix)) {
            return false;
        }
        if (!isset($this->tables[$table])) {
            $this->last_error = "Table '{$table}' doesn't exist";
            return false;
        }
        $this->addRow($suffix, $data);

        return 1;
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>|string|null $format
     * @return int|false
     */
    public function delete(string $table, array $where, $format = null)
    {
        $suffix = substr($table, strlen($this->prefix));

        return $this->deleteWhere($suffix, static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if ((string) $row[$key] !== (string) $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /** @param array<string, mixed> $data */
    private function addRow(string $suffix, array $data): void
    {
        $table = $this->table($suffix);
        $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
        $columns = $this->tables[$table] ?? array_keys($data);
        $row = array_fill_keys($columns, null);
        $row['id'] = $this->autoIncrement[$table];
        foreach ($data as $key => $value) {
            $row[$key] = $value;
        }
        $this->rows[$table][] = $row;
        $this->insert_id = $row['id'];
    }

    private function deleteWhere(string $suffix, callable $match): int
    {
        $table = $this->table($suffix);
        $before = count($this->rows[$table] ?? []);
        $this->rows[$table] = array_values(array_filter($this->rows[$table] ?? [], static fn (array $r): bool => !$match($r)));

        return $before - count($this->rows[$table]);
    }

    /** Create a table from a CREATE TABLE statement (used by the dbDelta stub). */
    public function createFromSql(string $sql): void
    {
        if (preg_match('/CREATE TABLE (\S+) \(/', $sql, $m) !== 1) {
            return;
        }
        $columns = [];
        foreach (explode("\n", $sql) as $line) {
            if (preg_match('/^  ([a-z_]+) [a-z]/', $line, $c) === 1 && !in_array($c[1], $this->skipColumns, true)) {
                $columns[] = $c[1];
            }
        }
        $this->tables[$m[1]] = $columns;
        $this->rows[$m[1]] ??= [];
    }
}
