<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class StorageException extends PromptLibraryException
{
    public function errorCode(): string
    {
        return 'promptlib_storage';
    }
}
