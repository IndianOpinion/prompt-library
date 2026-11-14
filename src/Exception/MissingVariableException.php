<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class MissingVariableException extends PromptLibraryException
{
    /** @param list<string> $names */
    public function __construct(private readonly array $names)
    {
        parent::__construct('Missing required variables: ' . implode(', ', $names) . '.');
    }

    public function errorCode(): string
    {
        return 'promptlib_missing_variable';
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }

    public function data(): array
    {
        return ['names' => $this->names];
    }
}
