<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class UnknownVariableException extends PromptLibraryException
{
    /** @param list<string> $names */
    public function __construct(private readonly array $names)
    {
        parent::__construct('Unknown variables supplied: ' . implode(', ', $names) . '.');
    }

    public function errorCode(): string
    {
        return 'promptlib_unknown_variable';
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
