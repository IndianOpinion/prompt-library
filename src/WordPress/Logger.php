<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Fires promptlib_log for every event and, when WP_DEBUG_LOG is on, writes
 * the line to the PHP error log with the prefix [prompt-library]. Callers
 * must never put prompt text or variable values in the context.
 */
class Logger
{
    private const FORBIDDEN_KEYS = ['system', 'user_part', 'values', 'prompt', 'answer', 'text', 'key', 'provider_key'];

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($context[$key]);
        }
        do_action('promptlib_log', $level, $message, $context);
        if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            $encoded = $context === [] ? '' : ' ' . (string) wp_json_encode($context);
            error_log(sprintf('[prompt-library] %s: %s%s', strtoupper($level), $message, $encoded));
        }
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log at most once per period for a given key (transient throttle).
     *
     * @param array<string, mixed> $context
     */
    public function throttled(string $key, int $seconds, string $level, string $message, array $context = []): void
    {
        $transient = 'promptlib_logged_' . md5($key);
        if (get_transient($transient) !== false) {
            return;
        }
        set_transient($transient, 1, $seconds);
        $this->log($level, $message, $context);
    }
}
