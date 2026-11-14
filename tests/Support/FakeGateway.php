<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Support;

use IndianOpinion\PromptLibrary\Runner\AttemptReport;
use IndianOpinion\PromptLibrary\Runner\GatewayCall;
use IndianOpinion\PromptLibrary\Runner\GatewayResponse;
use IndianOpinion\PromptLibrary\Runner\ModelGateway;

/**
 * Scripted model gateway: no HTTP. Each call takes the next queued response.
 */
final class FakeGateway implements ModelGateway
{
    /** @var list<GatewayResponse|callable(GatewayCall): GatewayResponse> */
    public array $queue = [];
    /** @var list<GatewayCall> */
    public array $calls = [];
    public bool $available = true;

    public function available(): bool
    {
        return $this->available;
    }

    public function call(GatewayCall $call): GatewayResponse
    {
        $this->calls[] = $call;
        $next = array_shift($this->queue) ?? self::ok(['answer' => 'default answer'], $call->model);

        return is_callable($next) ? $next($call) : $next;
    }

    /** @param array<mixed> $data */
    public static function ok(array $data, string $model = 'test/model', ?float $cost = 0.000002, string $source = 'reported'): GatewayResponse
    {
        return GatewayResponse::success($data, [new AttemptReport('openrouter', $model, AttemptReport::OK, 120, 0, 30, 0, $cost, $cost === null ? 'unknown' : $source, 200, '', 15, false)]);
    }

    public static function fail(string $kind, bool $fallbackWorthy, string $model = 'test/model', bool $billed = true): GatewayResponse
    {
        $attempt = new AttemptReport('openrouter', $model, $billed ? AttemptReport::ERROR_BILLED : AttemptReport::ERROR_UNBILLED, $billed ? 100 : 0, 0, $billed ? 10 : 0, 0, $billed ? 0.000001 : null, $billed ? 'reported' : 'unknown', 200, $kind === 'schema' ? 'schema_violation' : 'server_error', 10, true);

        return GatewayResponse::failure($kind, ucfirst($kind) . 'Exception', 'detail of ' . $kind, [$attempt], $fallbackWorthy);
    }
}
