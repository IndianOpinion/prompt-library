<?php

/**
 * Plugin Name: Example prompt consumer
 * Description: Shows how another plugin uses prompt-library: a shipped default, a declared consumer, and the soft-dependency filter with a built-in fallback.
 *
 * Copy into wp-content/plugins/ to try it. It makes no model call itself.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const EXAMPLE_CONSUMER_ID = 'example-consumer';

/** The text this plugin uses when prompt-library is not installed. */
function example_consumer_builtin(): array
{
    return [
        'format' => 'prompt-library/1',
        'slug' => 'example-summary',
        'title' => 'Example summary',
        'system' => 'You summarise texts accurately.',
        'user' => "Summarise in {{ points }} points.\nTitle: {{ title }}\n\n{{ text }}",
        'variables' => [
            ['name' => 'title', 'type' => 'string', 'escape' => 'line'],
            ['name' => 'text', 'type' => 'text', 'escape' => 'fenced'],
            ['name' => 'points', 'type' => 'integer', 'required' => false, 'default' => 3],
        ],
        'output' => ['mode' => 'text'],
        'settings' => ['temperature' => null, 'token_cap' => 600],
        'note' => 'Shipped with the example consumer.',
    ];
}

add_action('init', static function (): void {
    if (!function_exists('promptlib_register_default')) {
        return;
    }
    // Installed as version 1 the first time; later changes are offered, never published.
    promptlib_register_default(EXAMPLE_CONSUMER_ID, example_consumer_builtin());
    // Lets the editor warn before a version that needs more variables goes live.
    promptlib_register_consumer('example-summary', EXAMPLE_CONSUMER_ID, ['title', 'text', 'points']);
});

/**
 * Build the messages for a model call. Works with or without prompt-library.
 *
 * @return list<array{role: string, content: string}>
 */
function example_consumer_messages(string $title, string $text): array
{
    $values = ['title' => $title, 'text' => $text, 'points' => 3];
    $rendered = apply_filters('promptlib_render', null, 'example-summary', $values, 'live', [
        'consumer' => EXAMPLE_CONSUMER_ID,
        'fallback' => example_consumer_builtin(),
    ]);
    if (is_wp_error($rendered)) {
        // A variable problem: fix the caller. Never send a half-built prompt.
        throw new RuntimeException($rendered->get_error_message());
    }
    if ($rendered === null) {
        // prompt-library is not active: use the built-in text as is.
        return [['role' => 'user', 'content' => "Summarise in 3 points.\nTitle: {$title}\n\n{$text}"]];
    }
    $messages = [];
    if ($rendered['system'] !== '') {
        $messages[] = ['role' => 'system', 'content' => $rendered['system']];
    }
    $messages[] = ['role' => 'user', 'content' => $rendered['user']];

    return $messages;
}
