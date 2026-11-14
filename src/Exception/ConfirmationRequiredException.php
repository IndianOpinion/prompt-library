<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

/**
 * Publishing or moving the live pointer would break declared consumers
 * (case 49). Repeat the request with the confirmation flag to go ahead.
 */
final class ConfirmationRequiredException extends PromptLibraryException
{
    /** @param array<string, list<string>> $failing consumer id => missing required names */
    public function __construct(private readonly array $failing)
    {
        parent::__construct('These consumers do not supply a required variable: ' . implode(', ', array_keys($failing)) . '. Confirm to go ahead.');
    }

    public function errorCode(): string
    {
        return 'promptlib_confirmation_required';
    }

    /** @return array<string, list<string>> */
    public function failing(): array
    {
        return $this->failing;
    }

    public function data(): array
    {
        return ['consumers' => $this->failing];
    }
}
