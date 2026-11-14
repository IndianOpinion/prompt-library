<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Exception;

/**
 * Base class for every exception the consumer contract throws.
 *
 * Each subclass has a stable string code, used as the WP_Error code by the
 * soft-dependency filter.
 */
abstract class PromptLibraryException extends \RuntimeException
{
    /** The stable error code, for example `promptlib_not_found`. */
    abstract public function errorCode(): string;

    /**
     * Same as errorCode(); kept short for callers that read it often.
     */
    public function code(): string
    {
        return $this->errorCode();
    }

    /**
     * Extra structured data for WP_Error and logs. Never holds prompt text or values.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
