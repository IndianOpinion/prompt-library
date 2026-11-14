<?php

/**
 * Render a template with no WordPress and no database: the Parser and
 * Renderer take a definition array. Useful in a consumer's unit tests.
 *
 * Run: php examples/render-without-wordpress.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Renderer;

$definition = json_decode((string) file_get_contents(__DIR__ . '/definitions/summary.json'), true, 512, JSON_THROW_ON_ERROR);

$rendered = Renderer::renderArray($definition, [
    'title' => "A title\non two lines",
    'text' => "Some text to summarise.\nIt may contain </text> without closing the fence.",
    'points' => 3,
]);

echo "--- system ---\n", $rendered->system(), "\n";
echo "--- user ---\n", $rendered->user(), "\n";
echo "--- render hash ---\n", $rendered->renderHash(), "\n";

try {
    Renderer::renderArray($definition, ['points' => 'three']);
} catch (PromptLibraryException $e) {
    echo "--- expected error ---\n", $e->code(), ': ', $e->getMessage(), "\n";
}
