<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Cli;

use IndianOpinion\PromptLibrary\Plugin;

/**
 * Manage prompt templates. There is no command that runs a model.
 *
 * Exit code 0 on success, 1 on any error, with the error code printed.
 */
final class Command
{
    private Commands $commands;

    public function __construct(Plugin $plugin)
    {
        $this->commands = new Commands($plugin);
    }

    /**
     * List templates.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list(array $args = [], array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('list', $args, $assoc));
    }

    /**
     * Show a version.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The template slug.
     *
     * [--version=<v>]
     * : live (default), latest or a number.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function show(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('show', $args, $assoc));
    }

    /**
     * Render a version with values from a JSON file. Never calls a model.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The template slug.
     *
     * --values=<file.json>
     * : A JSON object of values.
     *
     * [--version=<v>]
     * : live (default), latest or a number.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function render(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('render', $args, $assoc));
    }

    /**
     * Export templates as JSON files.
     *
     * ## OPTIONS
     *
     * <slug>...
     * : One or more slugs.
     *
     * [--dir=<dir>]
     * : Directory to write to. Default: the current directory.
     *
     * [--with-draft]
     * : Include the draft.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function export(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('export', $args, $assoc));
    }

    /**
     * Import export files.
     *
     * ## OPTIONS
     *
     * <file>...
     * : One or more export files.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function import(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('import', $args, $assoc));
    }

    /**
     * Publish a definition file as the next version.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The template slug.
     *
     * --from=<file.json>
     * : A definition file (format prompt-library/1).
     *
     * --note=<text>
     * : The version note.
     *
     * [--no-live]
     * : Publish without moving the live pointer.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function publish(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('publish', $args, $assoc));
    }

    /**
     * Move the live pointer.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The template slug.
     *
     * <n>
     * : The version number.
     *
     * [--reason=<text>]
     * : Why.
     *
     * @subcommand set-live
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function setLive(array $args, array $assoc = []): void
    {
        $this->finish($this->commands->dispatch('set-live', $args, $assoc));
    }

    private function finish(int $code): void
    {
        if ($code !== 0) {
            \WP_CLI::halt($code);
        }
    }
}
