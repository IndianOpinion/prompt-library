<?php

/**
 * Plugin Name:       Prompt Library
 * Plugin URI:        https://github.com/IndianOpinion/prompt-library
 * Description:       Prompt templates with typed variables, immutable versions, a live pointer and a capped test runner.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       prompt-library
 * Network:           true
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('PROMPTLIB_FILE', __FILE__);
define('PROMPTLIB_DIR', __DIR__);

// Composer dependencies (llm-json-php). Without them the test runner is off
// and rendering for consumers still works.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/functions.php';

register_activation_hook(__FILE__, [\IndianOpinion\PromptLibrary\WordPress\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\PromptLibrary\WordPress\Lifecycle::class, 'deactivate']);

\IndianOpinion\PromptLibrary\Plugin::instance()->boot();
