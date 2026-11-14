<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

/**
 * The one place the runner talks to a model. Never throws for a model
 * failure: it returns a failed response with every attempt it saw.
 */
interface ModelGateway
{
    /** True when the model library can be used (case 74). */
    public function available(): bool;

    public function call(GatewayCall $call): GatewayResponse;
}
