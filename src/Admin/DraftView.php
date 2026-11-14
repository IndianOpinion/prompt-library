<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Admin;

use IndianOpinion\PromptLibrary\WordPress\Drafts;

/**
 * Small helper for screens that show a draft with every key present.
 */
final class DraftView
{
    /**
     * @param array<mixed> $draft
     * @return array<string, mixed>
     */
    public static function draft(array $draft = []): array
    {
        return Drafts::normalise($draft);
    }
}
