<?php

/**
 * Uninstall: removes everything the plugin created, on every site of a
 * network, unless PROMPTLIB_KEEP_DATA_ON_UNINSTALL is true (case 86).
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\PromptLibrary\WordPress\Uninstaller::run();
