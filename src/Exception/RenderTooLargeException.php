<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class RenderTooLargeException extends PromptLibraryException
{
    public function __construct(private readonly int $bytes, private readonly int $limit)
    {
        parent::__construct(sprintf('Rendered prompt is %d bytes; the limit is %d.', $bytes, $limit));
    }

    public function errorCode(): string
    {
        return 'promptlib_too_large';
    }

    public function data(): array
    {
        return ['bytes' => $this->bytes, 'limit' => $this->limit];
    }
}
