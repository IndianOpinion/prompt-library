<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Exception\MissingVariableException;
use IndianOpinion\PromptLibrary\Runner\RunGuard;
use IndianOpinion\PromptLibrary\Runner\RunRefused;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\Support\FakeGateway;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\CounterRepository;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RunnerTest extends TestCase
{
    private int $id;

    /** @var array<string, string> */
    private array $values = ['title' => 'A title', 'body' => 'Body text'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRunner();
        $this->id = $this->publishedTemplate();
    }

    /**
     * @param list<array{version: int|string, model: string}>|null $selections
     * @return array{group: string, runs: list<array<string, mixed>>, today: array<string, int>}
     */
    private function go(?array $selections = null, int $user = self::ADMIN): array
    {
        return $this->plugin->runner->run($this->id, $selections ?? [['version' => 1, 'model' => 'test/model']], $this->values, $user);
    }

    private function refusal(callable $callback): RunRefused
    {
        try {
            $callback();
        } catch (RunRefused $e) {
            return $e;
        }
        self::fail('Expected the run to be refused.');
    }

    private function today(string $metric): int
    {
        return $this->plugin->counters->get($this->plugin->clock->siteDay(), $metric);
    }

    /** Installs a fake spend meter. */
    private function meter(callable $decide, ?array &$records = null, ?array &$released = null): void
    {
        if (!defined('SPEND_METER_API')) {
            define('SPEND_METER_API', '1');
        }
        $records = [];
        $released = [];
        add_filter('spend_meter_pre_spend', $decide, 10, 2);
        add_action('spend_meter_record', static function (array $call) use (&$records): void {
            $records[] = $call;
        });
        add_action('spend_meter_release', static function (string $id) use (&$released): void {
            $released[] = $id;
        });
    }

    /** Acceptance test 45 (cases 56 and 57). */
    public function testPreviewMakesNoModelCall(): void
    {
        $this->plugin->drafts->save($this->id, ['system' => 'Draft system.'] + $this->plugin->templates->draft($this->id), null, self::ADMIN);
        $rendered = $this->plugin->runner->preview($this->id, 'draft', $this->values);
        self::assertSame('Draft system.', $rendered->system());
        self::assertSame([], $this->gateway->calls);
        self::assertSame(0, $this->today(CounterRepository::RUNS));
        $this->expectException(MissingVariableException::class);
        $this->plugin->runner->preview($this->id, 1, []);
    }

    /** Acceptance test 46 (case 58). */
    public function testRunShowsBothColumnsAndMetadata(): void
    {
        $this->gateway->queue[] = FakeGateway::ok(['answer' => "A short\nanswer."]);
        $result = $this->go();
        $run = $result['runs'][0];
        self::assertSame('ok', $run['status']);
        self::assertSame("A short\nanswer.", $run['answer']);
        self::assertSame("Title: A title\n\n<body>\nBody text\n</body>", $run['user']);
        self::assertSame('You write short summaries.', $run['system']);
        foreach (['model', 'version', 'content_hash', 'prompt_tokens', 'completion_tokens', 'cost', 'cost_source', 'duration_ms', 'attempts', 'guard', 'run_id', 'chars', 'est_tokens'] as $key) {
            self::assertArrayHasKey($key, $run);
        }
        self::assertSame(120, $run['prompt_tokens']);
        self::assertSame(30, $run['completion_tokens']);
        self::assertSame('reported', $run['cost_source']);
        self::assertSame(1, $run['attempts']);
        self::assertSame(1, $this->today(CounterRepository::RUNS));
    }

    /** Acceptance test 47 (case 59). */
    public function testTextWrapperAndJsonSchema(): void
    {
        $this->go();
        self::assertSame('answer', $this->gateway->calls[0]->schemaName);
        self::assertSame(['answer'], $this->gateway->calls[0]->schema['required']);

        $schema = ['type' => 'object', 'properties' => ['points' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['points'], 'additionalProperties' => false];
        $draft = $this->plugin->templates->draft($this->id);
        $draft['output'] = ['mode' => 'json', 'schema' => $schema];
        $this->plugin->drafts->save($this->id, $draft, null, self::ADMIN);
        $this->gateway->queue[] = FakeGateway::ok(['points' => ['a', 'b']]);
        $run = $this->go([['version' => 'draft', 'model' => 'test/model']])['runs'][0];
        self::assertSame($schema, $this->gateway->calls[1]->schema);
        self::assertSame("{\n    \"points\": [\n        \"a\",\n        \"b\"\n    ]\n}", $run['answer']);
        self::assertSame("Valid against the template's schema.", $run['validation']);
        self::assertSame(0, $run['version'] === 'draft' ? 0 : 1);

        $this->gateway->queue[] = FakeGateway::fail('schema', false);
        $failed = $this->go([['version' => 'draft', 'model' => 'test/model']])['runs'][0];
        self::assertStringStartsWith('Did not match the schema', (string) $failed['validation']);
    }

    /** Acceptance test 48 (case 60). */
    public function testCompareReservesBothFirst(): void
    {
        $result = $this->go([['version' => 1, 'model' => 'test/model'], ['version' => 1, 'model' => 'test/other']]);
        self::assertCount(2, $result['runs']);
        self::assertNotSame('', $result['group']);
        self::assertSame(2, $this->today(CounterRepository::RUNS));

        add_filter('promptlib_daily_caps', static fn (array $caps): array => ['runs' => 3] + $caps);
        $calls = count($this->gateway->calls);
        $refused = $this->refusal(fn () => $this->go([['version' => 1, 'model' => 'test/model'], ['version' => 'draft', 'model' => 'test/model']]));
        self::assertSame('promptlib_cap_reached', $refused->refusalCode());
        self::assertCount($calls, $this->gateway->calls);
        self::assertSame(2, $this->today(CounterRepository::RUNS));
    }

    /** Acceptance test 50 (case 62). */
    public function testOneRunInFlightLockExpiresAndRateLimit(): void
    {
        $guard = new RunGuard($this->plugin->clock);
        self::assertTrue($guard->acquire(self::ADMIN, 90));
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_busy', $refused->refusalCode());
        self::assertSame('A run is already in progress.', $refused->getMessage());
        State::$now += 91;
        $this->go();
        self::assertArrayNotHasKey(RunGuard::lockName(self::ADMIN), State::$options);

        add_filter('promptlib_rate_limit', static fn (): int => 2);
        $this->go();
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_rate_limited', $refused->refusalCode());
        State::$now += 60;
        $this->go();
    }

    /** Acceptance test 51 (case 63). */
    public function testKillSwitchSettingIsReadAtTheMomentOfTheCall(): void
    {
        $runner = $this->plugin->runner;
        self::assertNull($runner->disabledReason());
        $settings = get_option(Settings::OPTION);
        $settings['runner_enabled'] = false;
        update_option(Settings::OPTION, $settings, false);
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_runner_disabled', $refused->refusalCode());
        self::assertSame([], $this->gateway->calls);
        self::assertNotNull($runner->preview($this->id, 1, $this->values));
        self::assertSame(1, $this->today(CounterRepository::REFUSED_PREFIX . 'promptlib_runner_disabled'));
    }

    /** Acceptance test 51 (case 63): the constant, in a separate process. */
    #[RunInSeparateProcess]
    public function testKillSwitchConstant(): void
    {
        self::assertFalse(defined('PROMPTLIB_DISABLE_RUNNER'));
        define('PROMPTLIB_DISABLE_RUNNER', true);
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_runner_disabled', $refused->refusalCode());
        self::assertStringContainsString('PROMPTLIB_DISABLE_RUNNER', $refused->getMessage());
        self::assertSame([], $this->gateway->calls);
    }

    /** Acceptance test 52 (case 64). */
    public function testCapabilityIsRequired(): void
    {
        $refused = $this->refusal(fn () => $this->go(null, self::EDITOR));
        self::assertSame('promptlib_forbidden', $refused->refusalCode());
        self::assertSame([], $this->gateway->calls);
    }

    /** Acceptance test 53 (case 65). */
    public function testRenderErrorStopsBeforeReservation(): void
    {
        $refused = $this->refusal(fn () => $this->plugin->runner->run($this->id, [['version' => 1, 'model' => 'test/model']], [], self::ADMIN));
        self::assertSame('promptlib_missing_variable', $refused->refusalCode());
        self::assertSame(0, $this->today(CounterRepository::RUNS));
        self::assertSame([], $this->gateway->calls);
    }

    /** Acceptance test 54 (case 66). */
    public function testDailyRunCapAndCostCap(): void
    {
        $day = $this->plugin->clock->siteDay();
        $this->plugin->counters->add($day, CounterRepository::RUNS, 0);
        global $wpdb;
        $wpdb->counters[$day . '|runs'] = 48;
        $this->go();
        $this->go();
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_cap_reached', $refused->refusalCode());
        self::assertSame(50, $this->today(CounterRepository::RUNS));
        self::assertSame(2, count($this->gateway->calls));

        $wpdb->counters[$day . '|runs'] = 0;
        $wpdb->counters[$day . '|cost_nanos'] = 0;
        $settings = get_option(Settings::OPTION);
        $settings['daily_cost'] = 0.000003;
        update_option(Settings::OPTION, $settings, false);
        $this->gateway->queue[] = FakeGateway::ok(['answer' => 'x'], 'test/model', 0.000002);
        $this->gateway->queue[] = FakeGateway::ok(['answer' => 'x'], 'test/model', 0.000002);
        $this->go();
        $this->go();
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_cap_reached', $refused->refusalCode());
        self::assertSame('cost', $refused->reason());
    }

    public function testConditionalIncrementIsOneStatement(): void
    {
        global $wpdb;
        $wpdb->queries = [];
        $this->plugin->counters->reserve('2026-11-14', 'runs', 1, 50);
        $reserve = array_values(array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'counter.reserve')));
        self::assertCount(1, $reserve);
        self::assertStringContainsString('SET value = value + %d WHERE day = %s AND metric = %s AND value + %d <= %d', $reserve[0]);
    }

    public function testCapsApplyWithAMeterPresent(): void
    {
        $this->meter(static fn ($n, array $intent): array => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r-1', 'api' => 1]);
        add_filter('promptlib_daily_caps', static fn (array $caps): array => ['runs' => 1] + $caps);
        $this->go();
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame('promptlib_cap_reached', $refused->refusalCode());
    }

    /** Acceptance test 55 (case 67). */
    public function testMeterNullPassesRefusalStopsBrokenFailsClosed(): void
    {
        $intents = [];
        $decision = null;
        $this->meter(static function ($n, array $intent) use (&$intents, &$decision) {
            $intents[] = $intent;
            if ($decision instanceof \Throwable) {
                throw $decision;
            }
            return $decision;
        }, $records, $released);

        $this->go();
        self::assertCount(1, $this->gateway->calls);
        self::assertSame(['purpose', 'caller', 'provider', 'model', 'est_input_tokens', 'max_output_tokens', 'meta'], array_keys($intents[0]));
        self::assertSame('prompt-test', $intents[0]['purpose']);
        self::assertSame('prompt-library', $intents[0]['caller']);
        self::assertSame(['template' => 'summary', 'version' => 1], $intents[0]['meta']);
        self::assertArrayNotHasKey('test', $intents[0]);
        self::assertArrayNotHasKey('dry_run', $intents[0]);
        self::assertSame(2048, $intents[0]['max_output_tokens']);

        $decision = ['allowed' => false, 'reason' => 'cap_daily', 'message' => 'internal detail', 'reservation_id' => null];
        $refused = $this->refusal(fn () => $this->go());
        self::assertSame(['promptlib_meter_refused', 'cap_daily'], [$refused->refusalCode(), $refused->reason()]);
        self::assertStringNotContainsString('internal detail', $refused->getMessage());
        self::assertSame(1, $this->today(CounterRepository::RUNS));

        $decision = ['allowed' => false, 'reason' => 'something_new'];
        self::assertSame('Refused by the spend meter.', $this->refusal(fn () => $this->go())->getMessage());

        foreach ([new \RuntimeException('meter broke'), 'yes', ['allowed' => 'true'], ['allowed' => true, 'reason' => 'weird', 'reservation_id' => 'r-x'], ['allowed' => true, 'api' => 2]] as $bad) {
            $decision = $bad;
            $refused = $this->refusal(fn () => $this->go());
            self::assertSame('promptlib_meter_refused', $refused->refusalCode());
        }
        self::assertCount(1, $this->gateway->calls);
        self::assertContains('r-x', $released);
    }

    public function testStopAfterAllowedCheckReleasesTheReservation(): void
    {
        $this->meter(static fn ($n, array $intent): array => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'res-1', 'remaining' => 500, 'estimate' => 3, 'api' => 1], $records, $released);
        add_filter('promptlib_runner_deadline', static fn (): int => 3);
        $run = $this->go()['runs'][0];
        self::assertSame('failed', $run['status']);
        self::assertSame(['res-1'], $released);
        self::assertSame([], $this->gateway->calls);
        self::assertSame([], $records);
    }

    public function testCompareSecondMeterRefusalReleasesTheFirst(): void
    {
        $n = 0;
        $this->meter(static function () use (&$n): array {
            $n++;
            return $n === 1 ? ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'first'] : ['allowed' => false, 'reason' => 'kill_switch'];
        }, $records, $released);
        $refused = $this->refusal(fn () => $this->go([['version' => 1, 'model' => 'test/model'], ['version' => 1, 'model' => 'test/other']]));
        self::assertSame('The spend meter kill switch is on.', $refused->getMessage());
        self::assertSame(['first'], $released);
        self::assertSame([], $this->gateway->calls);
        self::assertSame(0, $this->today(CounterRepository::RUNS));
    }

    /** Acceptance test 56 (case 68). */
    public function testRequestCarriesPurposePartsCapTemperatureDeadline(): void
    {
        $draft = $this->plugin->templates->draft($this->id);
        $draft['settings'] = ['temperature' => 0.2, 'token_cap' => 300];
        $this->plugin->drafts->save($this->id, $draft, null, self::ADMIN);
        add_filter('promptlib_runner_purpose', static fn (string $p, string $slug): string => $p . ':' . $slug, 10, 2);
        $previous = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            $this->go([['version' => 'draft', 'model' => 'test/model']]);
        } finally {
            ini_set('max_execution_time', (string) $previous);
        }
        $call = $this->gateway->calls[0];
        self::assertSame('prompt-test:summary', $call->purpose);
        self::assertSame('You write short summaries.', $call->system);
        self::assertStringStartsWith('Title: A title', $call->user);
        self::assertSame(300, $call->tokenCap);
        self::assertSame(0.2, $call->temperature);
        self::assertLessThanOrEqual(20.0, $call->deadlineSeconds);
        self::assertGreaterThan(15.0, $call->deadlineSeconds);
    }

    /** Acceptance test 57 (case 69, decision D1): every attempt is recorded, each fallback pre-checked. */
    public function testEveryAttemptReachesTheMeter(): void
    {
        $settings = get_option(Settings::OPTION);
        $settings['fallback_models'] = ['test/other'];
        update_option(Settings::OPTION, $settings, false);
        $n = 0;
        $this->meter(static function () use (&$n): array {
            $n++;
            return ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'res-' . $n];
        }, $records, $released);
        add_action('spend_meter_record', static function (): void {
            throw new \RuntimeException('listener broke');
        }, 20);
        $this->gateway->queue[] = FakeGateway::fail('no_route', true, 'test/model');
        $this->gateway->queue[] = FakeGateway::ok(['answer' => 'Second model answered.'], 'test/other', 0.000004);
        $run = $this->go()['runs'][0];
        self::assertSame('ok', $run['status']);
        self::assertSame('test/other', $run['answered_by']);
        self::assertSame(2, $n);
        self::assertCount(2, $records);
        self::assertSame(['res-1', 'error_billed', 1], [$records[0]['reservation_id'], $records[0]['status'], $records[0]['attempt']]);
        self::assertSame(['res-2', 'ok', 2, 'test/model'], [$records[1]['reservation_id'], $records[1]['status'], $records[1]['attempt'], $records[1]['fallback_from']]);
        foreach (['reservation_id', 'call_id', 'purpose', 'caller', 'provider', 'model', 'status', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'http_status', 'error_code', 'latency_ms', 'attempt'] as $key) {
            self::assertArrayHasKey($key, $records[0]);
        }
        self::assertSame(1000, $records[0]['provider_cost']);
        self::assertNotSame($records[0]['call_id'], $records[1]['call_id']);
        self::assertSame([], $released);
    }

    public function testNonRetryableFailureDoesNotFallBack(): void
    {
        $settings = get_option(Settings::OPTION);
        $settings['fallback_models'] = ['test/other'];
        update_option(Settings::OPTION, $settings, false);
        $this->gateway->queue[] = FakeGateway::fail('auth', false);
        $run = $this->go()['runs'][0];
        self::assertSame('Bad key: the provider rejected the key.', $run['message']);
        self::assertCount(1, $this->gateway->calls);
    }

    /** Acceptance test 58 (case 70). */
    public function testKnownCostAddedUnknownLabelled(): void
    {
        $this->gateway->queue[] = FakeGateway::ok(['answer' => 'x'], 'test/model', 0.000002);
        $this->go();
        self::assertSame(2000, $this->today(CounterRepository::COST));
        $this->gateway->queue[] = FakeGateway::ok(['answer' => 'x'], 'test/model', null);
        $run = $this->go()['runs'][0];
        self::assertSame('unknown', $run['cost_source']);
        self::assertNull($run['cost']);
        self::assertSame(2000, $this->today(CounterRepository::COST));
        $rows = $this->plugin->runs->recent(null);
        self::assertNull($rows[0]['cost_nanos']);
        self::assertSame('unknown', $rows[0]['cost_source']);
    }

    /** Acceptance test 59 (cases 71 and 72). */
    public function testEachOutcomeWritesOneRowAndAPlainMessage(): void
    {
        $expected = [
            'credit' => 'Out of credit: the provider account has no balance left.',
            'no_route' => 'No route answered: every attempt failed.',
            'auth' => 'Bad key: the provider rejected the key.',
            'schema' => 'The output did not match the schema.',
            'guard' => 'The output was rejected by the guard.',
            'deadline' => 'Timed out before an answer arrived.',
        ];
        add_filter('promptlib_rate_limit', static fn (): int => 100);
        $this->go();
        foreach ($expected as $kind => $message) {
            $this->gateway->queue[] = FakeGateway::fail($kind, false);
            $run = $this->go()['runs'][0];
            self::assertSame('failed', $run['status']);
            self::assertSame($message, $run['message']);
            self::assertStringStartsWith($kind . ':', $run['error_code']);
        }
        $rows = $this->plugin->runs->recent(null, 100);
        self::assertCount(count($expected) + 1, $rows);
        self::assertSame('ok', end($rows)['status']);
        self::assertSame(count($expected) + 1, count($this->actions('promptlib_run_finished')));
        self::assertArrayNotHasKey('system_text', $this->actions('promptlib_run_finished')[0][1][0]);
    }

    public function testStoreRunsOffKeepsMetadataOnly(): void
    {
        add_filter('promptlib_store_runs', '__return_false');
        $this->go();
        global $wpdb;
        $row = $wpdb->rows[$wpdb->prefix . 'promptlib_runs'][0];
        self::assertNull($row['system_text']);
        self::assertNull($row['answer']);
        self::assertSame('ok', $row['status']);
    }

    /** Acceptance test 60 (cases 73 and 74). */
    public function testNotConfiguredDisablesRunNotPreviewOrRender(): void
    {
        delete_option(Settings::KEY_OPTION);
        self::assertSame('promptlib_not_configured', $this->plugin->runner->disabledReason()['code'] ?? null);
        update_option(Settings::KEY_OPTION, 'k', false);
        $settings = get_option(Settings::OPTION);
        $settings['models'] = [];
        update_option(Settings::OPTION, $settings, false);
        self::assertSame('promptlib_not_configured', $this->plugin->runner->disabledReason()['code'] ?? null);
        $this->configureRunner();
        $this->gateway->available = false;
        self::assertStringContainsString('composer install', $this->plugin->runner->disabledReason()['message'] ?? '');
        self::assertSame('promptlib_not_configured', $this->refusal(fn () => $this->go())->refusalCode());
        self::assertNotNull($this->plugin->runner->preview($this->id, 1, $this->values));
        self::assertSame(1, promptlib_render('summary', $this->values)->version());
    }

    public function testDegradedSchemaDisablesRunner(): void
    {
        update_option(Schema::STATE_OPTION, ['missing' => ['promptlib_runs.answer']], true);
        self::assertSame('promptlib_runner_disabled', $this->plugin->runner->disabledReason()['code'] ?? null);
        self::assertSame(1, promptlib_render('summary', $this->values)->version());
    }

    /** Acceptance test 73 (case 90, decision D2): site-timezone window, UTC timestamps. */
    public function testCountersFollowTheSiteTimezoneTimestampsStayUtc(): void
    {
        State::$timezone = 'Asia/Kolkata';
        State::$now = (new \DateTimeImmutable('2026-11-14 20:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $this->go();
        global $wpdb;
        self::assertArrayHasKey('2026-11-15|runs', $wpdb->counters);
        self::assertSame('2026-11-14 20:00:00', $this->plugin->runs->recent(null)[0]['created_at']);
    }

    /** Acceptance test 76 (cases 96 and 97): log lines hold no prompt text or values. */
    public function testRunLogHasNoPromptText(): void
    {
        $lines = [];
        add_action('promptlib_log', static function (string $level, string $message, array $context) use (&$lines): void {
            $lines[] = [$message, $context];
        }, 10, 3);
        $this->go();
        $finished = array_values(array_filter($lines, static fn (array $l): bool => $l[0] === 'Test run finished.'));
        self::assertCount(1, $finished);
        self::assertSame(['user_id', 'slug', 'version', 'model', 'outcome', 'prompt_tokens', 'completion_tokens', 'cost_nanos', 'duration_ms'], array_keys($finished[0][1]));
        $encoded = (string) json_encode($lines);
        self::assertStringNotContainsString('A title', $encoded);
        self::assertStringNotContainsString('short summaries', $encoded);
    }

    /** Acceptance test 61 (case 75). */
    public function testEstimateIsCharactersOverFourAndFilterable(): void
    {
        $rendered = $this->plugin->runner->preview($this->id, 1, ['title' => 'abcd', 'body' => '']);
        $chars = mb_strlen($rendered->system()) + mb_strlen($rendered->user());
        self::assertSame((int) ceil($chars / 4), \IndianOpinion\PromptLibrary\Runner\Runner::estimateTokens($rendered));
        add_filter('promptlib_estimate_tokens', static fn (int $estimate, int $chars): int => $chars, 10, 2);
        self::assertSame($chars, \IndianOpinion\PromptLibrary\Runner\Runner::estimateTokens($rendered));
    }
}
