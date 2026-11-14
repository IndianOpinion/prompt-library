<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Runner\GatewayCall;
use IndianOpinion\PromptLibrary\Runner\LlmJsonGateway;
use IndianOpinion\PromptLibrary\Runner\Runner;
use IndianOpinion\PromptLibrary\Tests\Support\FrozenClock;
use IndianOpinion\PromptLibrary\Tests\TestCase;

/**
 * The real llm-json-php gateway against the library's mock transport. No network.
 */
final class GatewayTest extends TestCase
{
    private function call(array $schema = []): GatewayCall
    {
        return new GatewayCall('prompt-test', 'openrouter', 'test/model', 'System text.', 'User text.', 'answer', $schema ?: Runner::textSchema(), 0.4, 321, 40.0);
    }

    /** Acceptance test 56 (case 68), on the wire. */
    public function testRequestBodyCarriesPartsSchemaCapAndTemperature(): void
    {
        $this->configureRunner();
        $transport = new MockTransport(MockTransport::completion(['answer' => 'Hello.'], 'stop', ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13, 'cost' => 0.00001]));
        $response = (new LlmJsonGateway($transport))->call($this->call());
        self::assertTrue($response->ok);
        self::assertSame(['answer' => 'Hello.'], $response->data);
        $body = json_decode($transport->lastRequest()->body, true);
        self::assertSame('test/model', $body['model']);
        self::assertSame(321, $body['max_tokens'] ?? $body['max_completion_tokens'] ?? null);
        self::assertSame(0.4, $body['temperature']);
        $messages = array_column($body['messages'], 'content', 'role');
        self::assertStringContainsString('System text.', $messages['system']);
        self::assertSame('User text.', $messages['user']);
        self::assertStringContainsString('answer', (string) json_encode($body));
        self::assertSame(1, count($response->attempts));
        self::assertSame('ok', $response->attempts[0]->status);
        self::assertSame(10, $response->attempts[0]->inputTokens);
    }

    public function testFailuresMapToKinds(): void
    {
        $this->configureRunner();
        $cases = [
            'credit' => MockTransport::status(402, '{"error":{"message":"Insufficient credits"}}'),
            'auth' => MockTransport::status(401, '{"error":{"message":"bad key"}}'),
            'schema' => MockTransport::completion(['other' => 1]),
        ];
        foreach ($cases as $kind => $http) {
            $response = (new LlmJsonGateway(new MockTransport($http, $http, $http)))->call($this->call());
            self::assertFalse($response->ok, $kind);
            self::assertSame($kind, $response->errorKind, $kind);
            self::assertNotSame([], $response->attempts, $kind);
        }
    }

    /** Acceptance test 61 (case 75): a supplied client is used and the caps still apply. */
    public function testSuppliedClientIsUsedAndCapsStillApply(): void
    {
        $this->configureRunner();
        $transport = new MockTransport(MockTransport::completion(['answer' => 'From the site client.']));
        $routes = [];
        add_filter('promptlib_runner_client', static function ($client, array $route) use ($transport, &$routes) {
            $routes[] = $route;
            return Client::create(Chain::of(Route::to(Provider::openAi('site-key'), 'site/model')), $transport);
        }, 10, 2);
        $plugin = new Plugin(new LlmJsonGateway(), new FrozenClock());
        $id = $this->publishedTemplate();
        add_filter('promptlib_daily_caps', static fn (array $caps): array => ['runs' => 1] + $caps);
        $result = $plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
        self::assertSame('From the site client.', $result['runs'][0]['answer']);
        self::assertSame([['provider' => 'openrouter', 'model' => 'test/model']], $routes);
        $this->expectException(\IndianOpinion\PromptLibrary\Runner\RunRefused::class);
        $plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
    }

    public function testGatewayReportsLibraryAvailable(): void
    {
        self::assertTrue((new LlmJsonGateway())->available());
    }
}
