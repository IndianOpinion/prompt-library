<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\CostSource;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\PriceTable;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\Transport;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use IndianOpinion\PromptLibrary\WordPress\Settings;

/**
 * The model gateway built on llm-json-php. One call is one route with the
 * reliability layer off, so the runner sees and pre-checks every attempt
 * (decision D1). A site may hand over its own client through the
 * promptlib_runner_client filter (case 75).
 */
class LlmJsonGateway implements ModelGateway
{
    public function __construct(private readonly ?Transport $transport = null)
    {
    }

    public function available(): bool
    {
        return class_exists(Client::class);
    }

    public function call(GatewayCall $call): GatewayResponse
    {
        try {
            $client = $this->client($call);
            $schema = Schema::fromArray($call->schemaName, $call->schema);
            $request = Request::for($call->purpose, $schema)
                ->withInput($call->user)
                ->withTokenCap($call->tokenCap)
                ->withDeadline($call->deadlineSeconds);
            if ($call->system !== '') {
                $request = $request->withSystem($call->system);
            }
            if ($call->temperature !== null) {
                $request = $request->withTemperature($call->temperature);
            }
            $result = $client->complete($request);
        } catch (CreditExhaustedException $e) {
            return GatewayResponse::failure('credit', self::short($e), $e->getMessage(), $this->attempts($e->attempts()), false);
        } catch (ChainExhaustedException $e) {
            $attempts = $this->attempts($e->attempts());
            if ($e->isDeadline()) {
                return GatewayResponse::failure('deadline', self::short($e), $e->getMessage(), $attempts, false);
            }
            $last = $e->attempts() === [] ? null : $e->attempts()[count($e->attempts()) - 1];
            [$kind, $worthy] = self::kindOf($last?->outcome);
            return GatewayResponse::failure($kind, self::short($e), $e->getMessage(), $attempts, $worthy, $last?->outcome === Outcome::GuardRejected ? ['rejected'] : []);
        } catch (ConfigurationException $e) {
            return GatewayResponse::failure('configuration', self::short($e), $e->getMessage(), [], false);
        } catch (LlmJsonException $e) {
            return GatewayResponse::failure('no_route', self::short($e), $e->getMessage(), [], false);
        }

        $guard = [];
        foreach ($result->guardReport()->repairs as $repair) {
            $guard[] = 'repaired ' . $repair->rule . ' at ' . $repair->path;
        }
        foreach ($result->guardReport()->earlierRejections as $violation) {
            $guard[] = 'rejected ' . $violation->rule . ' at ' . $violation->path;
        }
        if ($result->guardReport()->regenerated) {
            $guard[] = 'regenerated once';
        }

        return GatewayResponse::success($result->data(), $this->attempts($result->attempts()), $guard);
    }

    private function client(GatewayCall $call): Client
    {
        $supplied = apply_filters('promptlib_runner_client', null, ['provider' => $call->provider, 'model' => $call->model]);
        if ($supplied instanceof Client) {
            return $supplied;
        }
        $settings = Settings::load();
        $key = Settings::providerKey();
        $provider = match ($call->provider) {
            'openai' => Provider::openAi($key),
            'generic' => Provider::generic('generic', $settings->baseUrl(), $key, $key === '', self::isLocalHttp($settings->baseUrl()))
                ->withStructuredOutputs(false, true),
            default => Provider::openRouter($key, home_url('/'), 'prompt-library'),
        };
        $route = Route::to($provider, $call->model)->withTokenCap($call->tokenCap);
        $options = Options::defaults()->withReliability(false)->withDeadline(max(1.0, $call->deadlineSeconds), min(5.0, max(1.0, $call->deadlineSeconds)))
            ->withStoreKeyPrefix('promptlib_llmjson');
        $client = Client::create(Chain::of($route), $this->transport ?? new WordPressTransport())
            ->withStore(new WordPressTransientStore())
            ->withOptions($options);
        $prices = [];
        foreach ($settings->priceTable() as $row) {
            $prices[$row['model']] = ['input' => $row['input'], 'output' => $row['output']];
        }
        if ($prices !== []) {
            $client = $client->withPriceTable(PriceTable::fromArray($prices));
        }

        return $client;
    }

    private static function isLocalHttp(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'http'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /**
     * @param list<Attempt> $attempts
     * @return list<AttemptReport>
     */
    private function attempts(array $attempts): array
    {
        $out = [];
        foreach ($attempts as $a) {
            $usage = $a->usage;
            $billed = ($usage->cost ?? 0.0) > 0 || ($usage->promptTokens ?? 0) + ($usage->completionTokens ?? 0) > 0;
            $out[] = new AttemptReport(
                $a->provider,
                $a->model,
                $a->succeeded() ? AttemptReport::OK : ($billed ? AttemptReport::ERROR_BILLED : AttemptReport::ERROR_UNBILLED),
                $usage->promptTokens ?? 0,
                $usage->cachedTokens ?? 0,
                $usage->completionTokens ?? 0,
                $usage->reasoningTokens ?? 0,
                $usage->cost,
                $usage->cost === null ? 'unknown' : ($usage->costSource === CostSource::Reported ? 'reported' : ($usage->costSource === CostSource::PriceTable ? 'price_table' : $usage->costSource->value)),
                $a->httpStatus,
                $a->succeeded() ? '' : $a->outcome->value,
                $a->durationMs,
                $a->outcome->isRetryable(),
            );
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: bool} error kind, and whether another model may help
     */
    private static function kindOf(?Outcome $outcome): array
    {
        return match ($outcome) {
            Outcome::AuthenticationFailed => ['auth', false],
            Outcome::CreditExhausted => ['credit', false],
            Outcome::SchemaViolation, Outcome::Malformed, Outcome::Truncated => ['schema', true],
            Outcome::GuardRejected => ['guard', true],
            Outcome::Refusal, Outcome::ContentFilter => ['refused', true],
            Outcome::RequestRejected, Outcome::Redirect => ['no_route', false],
            default => ['no_route', true],
        };
    }

    private static function short(\Throwable $e): string
    {
        $class = get_class($e);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
