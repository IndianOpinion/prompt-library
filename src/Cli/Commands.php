<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Cli;

use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Text;

/**
 * The logic behind `wp prompt-library`. Kept apart from the WP-CLI class so
 * that only the subcommands are public there. There is no command that runs
 * a model. Exit code 0 on success, 1 on any error, with the error code printed.
 */
final class Commands
{
    /** @var callable(string): void */
    private $out;

    /** @var callable(string): void */
    private $err;

    /**
     * @param (callable(string): void)|null $out
     * @param (callable(string): void)|null $err
     */
    public function __construct(private readonly Plugin $plugin, ?callable $out = null, ?callable $err = null)
    {
        $this->out = $out ?? static function (string $line): void {
            \WP_CLI::line($line);
        };
        $this->err = $err ?? static function (string $line): void {
            \WP_CLI::line($line);
        };
    }

    /**
     * Run a subcommand and return its exit code. Used by the methods above and by tests.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function dispatch(string $command, array $args, array $assoc): int
    {
        try {
            switch ($command) {
                case 'list':
                    foreach ($this->plugin->templates->all() as $post) {
                        $this->line(sprintf("%s\tlive=%d\t%s", $this->plugin->templates->slugOf($post->ID), $this->plugin->templates->live($post->ID), $post->post_title));
                    }
                    return 0;
                case 'show':
                    $version = $this->plugin->library->get($this->arg($args, 0), $this->versionOf($assoc));
                    $this->line(Text::json([
                        'version' => $version->version(),
                        'live' => $version->isLive(),
                        'note' => $version->note(),
                        'published_at' => $version->publishedAt()->format(DATE_ATOM),
                        'content_hash' => $version->contentHash(),
                        'definition' => $version->definition()->toArray(),
                    ], true));
                    return 0;
                case 'render':
                    $values = $this->readJson((string) ($assoc['values'] ?? ''));
                    $rendered = $this->plugin->library->render($this->arg($args, 0), $values, $this->versionOf($assoc), ['consumer' => 'wp-cli']);
                    $this->line('--- system ---');
                    $this->line($rendered->system());
                    $this->line('--- user ---');
                    $this->line($rendered->user());
                    return 0;
                case 'export':
                    $dir = rtrim((string) ($assoc['dir'] ?? '.'), '/');
                    if (!is_dir($dir) || !is_writable($dir)) {
                        throw new \RuntimeException('The directory does not exist or is not writable: ' . $dir);
                    }
                    if ($args === []) {
                        throw new \RuntimeException('Give at least one slug.');
                    }
                    foreach ($args as $slug) {
                        $found = $this->plugin->templates->locate($slug);
                        if ($found === null) {
                            throw new \RuntimeException('No such template: ' . $slug);
                        }
                        $doc = $this->plugin->importExport->export($found['id'], isset($assoc['with-draft']));
                        $file = $dir . '/' . $slug . '.json';
                        if (file_put_contents($file, Text::json($doc, true) . "\n") === false) {
                            throw new \RuntimeException('Could not write ' . $file);
                        }
                        $this->line('Exported ' . $file);
                    }
                    return 0;
                case 'import':
                    if ($args === []) {
                        throw new \RuntimeException('Give at least one file.');
                    }
                    foreach ($args as $file) {
                        $result = $this->plugin->importExport->import($this->readJson($file), $this->userId());
                        $this->line(sprintf('%s: %s', $result['slug'], $result['mode'] === 'created' ? 'created' : 'loaded into the draft; review and publish'));
                    }
                    return 0;
                case 'publish':
                    $slug = $this->arg($args, 0);
                    $found = $this->plugin->templates->locate($slug);
                    if ($found === null || $found['status'] === 'trash') {
                        throw new \RuntimeException('No such template: ' . $slug);
                    }
                    $definition = $this->readJson((string) ($assoc['from'] ?? ''));
                    $live = !(isset($assoc['no-live']) || (array_key_exists('live', $assoc) && $assoc['live'] === false));
                    $result = $this->plugin->publisher->publishDefinition($found['id'], $slug, $definition, (string) ($assoc['note'] ?? ''), $live, true, $this->userId());
                    $this->line(sprintf('Published version %d%s.', $result['version'], $result['live'] ? ' (live)' : ''));
                    return 0;
                case 'set-live':
                    $slug = $this->arg($args, 0);
                    $number = $this->arg($args, 1);
                    if (preg_match('/^[1-9][0-9]*$/', $number) !== 1) {
                        throw new \RuntimeException('The version must be a positive number.');
                    }
                    $found = $this->plugin->templates->locate($slug);
                    if ($found === null || $found['status'] === 'trash') {
                        throw new \RuntimeException('No such template: ' . $slug);
                    }
                    $this->plugin->publisher->setLive($found['id'], (int) $number, (string) ($assoc['reason'] ?? ''), true, $this->userId());
                    $this->line(sprintf('Version %s of %s is live.', $number, $slug));
                    return 0;
            }
            throw new \RuntimeException('Unknown command: ' . $command);
        } catch (PromptLibraryException $e) {
            ($this->err)(sprintf('Error: [%s] %s', $e->errorCode(), $e->getMessage()));
            return 1;
        } catch (\RuntimeException | \JsonException $e) {
            ($this->err)(sprintf('Error: [promptlib_cli] %s', $e->getMessage()));
            return 1;
        }
    }

    private function line(string $line): void
    {
        ($this->out)($line);
    }

    /** @param list<string> $args */
    private function arg(array $args, int $index): string
    {
        if (!isset($args[$index]) || $args[$index] === '') {
            throw new \RuntimeException('A required argument is missing.');
        }

        return $args[$index];
    }

    /** @param array<string, string|bool> $assoc */
    private function versionOf(array $assoc): int|string
    {
        $version = (string) ($assoc['version'] ?? 'live');

        return preg_match('/^[1-9][0-9]*$/', $version) === 1 ? (int) $version : $version;
    }

    /** @return array<mixed> */
    private function readJson(string $file): array
    {
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('Cannot read the file: ' . $file);
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('The file does not hold a JSON object: ' . $file);
        }

        return $data;
    }

    private function userId(): int
    {
        return get_current_user_id();
    }
}
