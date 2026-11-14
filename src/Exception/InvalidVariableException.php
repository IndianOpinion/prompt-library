<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

final class InvalidVariableException extends PromptLibraryException
{
    /** @param array<string, string> $problems variable name => problem */
    public function __construct(private readonly array $problems)
    {
        $parts = [];
        foreach ($problems as $name => $problem) {
            $parts[] = $name . ': ' . $problem;
        }
        parent::__construct('Invalid variables: ' . implode('; ', $parts) . '.');
    }

    public function errorCode(): string
    {
        return 'promptlib_invalid_variable';
    }

    /** @return array<string, string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->problems);
    }

    public function data(): array
    {
        return ['problems' => $this->problems];
    }
}
