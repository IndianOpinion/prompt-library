<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\RenderedPrompt;
use IndianOpinion\PromptLibrary\Renderer;
use IndianOpinion\PromptLibrary\Text;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;
use IndianOpinion\PromptLibrary\WordPress\Clock;
use IndianOpinion\PromptLibrary\WordPress\CounterRepository;
use IndianOpinion\PromptLibrary\WordPress\Library;
use IndianOpinion\PromptLibrary\WordPress\Logger;
use IndianOpinion\PromptLibrary\WordPress\RunRepository;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;
use IndianOpinion\PromptLibrary\WordPress\TemplateRepository;

/**
 * The test runner and its spend path (cases 56 to 75). The order is fixed:
 * kill switch, permission, render, own daily caps, spend-meter check, call,
 * report, settle, record. Every stop after the own reservation gives it back.
 */
class Runner
{
    public const PURPOSE = 'prompt-test';
    public const DEFAULT_DEADLINE = 60;
    public const MIN_ATTEMPT_SECONDS = 5;
    public const LOCK_GRACE = 30;

    public const MESSAGES = [
        'credit' => 'Out of credit: the provider account has no balance left.',
        'no_route' => 'No route answered: every attempt failed.',
        'auth' => 'Bad key: the provider rejected the key.',
        'schema' => 'The output did not match the schema.',
        'guard' => 'The output was rejected by the guard.',
        'deadline' => 'Timed out before an answer arrived.',
        'refused' => 'The model refused to answer.',
        'configuration' => 'The request is not valid for this provider or model.',
    ];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly Library $library,
        private readonly CounterRepository $counters,
        private readonly RunRepository $runs,
        private readonly SpendMeter $meter,
        private readonly ModelGateway $gateway,
        private readonly RunGuard $guard,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Why Run is unavailable right now, or null. Read at the moment of the
     * call, never cached (lesson 7). Preview stays available either way.
     *
     * @return array{code: string, message: string}|null
     */
    public function disabledReason(?Settings $settings = null): ?array
    {
        $settings ??= Settings::load();
        if (defined('PROMPTLIB_DISABLE_RUNNER') && constant('PROMPTLIB_DISABLE_RUNNER') === true) {
            return ['code' => 'promptlib_runner_disabled', 'message' => 'The test runner is switched off by the PROMPTLIB_DISABLE_RUNNER constant.'];
        }
        if (!$settings->runnerEnabled()) {
            return ['code' => 'promptlib_runner_disabled', 'message' => 'The test runner is switched off in the settings.'];
        }
        if (Schema::isDegraded()) {
            return ['code' => 'promptlib_runner_disabled', 'message' => 'The plugin tables are missing columns, so the test runner is off. See Site Health.'];
        }
        if (!$this->gateway->available()) {
            return ['code' => 'promptlib_not_configured', 'message' => 'The model library is not installed. Run composer install in the plugin folder.'];
        }
        if ($settings->models() === []) {
            return ['code' => 'promptlib_not_configured', 'message' => 'No models are configured. Add at least one in the settings.'];
        }
        if (!Settings::hasProviderKey() && !($settings->providerKind() === 'generic' && $settings->baseUrl() !== '')) {
            return ['code' => 'promptlib_not_configured', 'message' => 'No provider key is configured. Add one in the settings or with the PROMPTLIB_PROVIDER_KEY constant.'];
        }

        return null;
    }

    /**
     * Render the draft or a version without calling a model (case 57).
     *
     * @param array<string, mixed> $values
     */
    public function preview(int $templateId, int|string $selection, array $values): RenderedPrompt
    {
        return $this->renderSelection($templateId, $selection, $values);
    }

    /**
     * Run one selection, or two for compare mode (case 60).
     *
     * @param list<array{version: int|string, model: string}> $selections
     * @param array<string, mixed> $values
     * @return array{group: string, runs: list<array<string, mixed>>, today: array<string, int>}
     * @throws RunRefused
     */
    public function run(int $templateId, array $selections, array $values, int $userId): array
    {
        $day = $this->clock->siteDay();
        $slug = $this->templates->slugOf($templateId);
        $settings = Settings::load();

        // Step 1: kill switch, and a usable configuration.
        $disabled = $this->disabledReason($settings);
        if ($disabled !== null) {
            $this->refuse($day, $disabled['code'], $disabled['message'], $userId, $slug, $disabled['code'] === 'promptlib_not_configured' ? 409 : 403);
        }
        // Step 2: permission (the REST and admin layers check the nonce).
        if (!user_can($userId, Capabilities::RUN)) {
            $this->refuse($day, 'promptlib_forbidden', 'You may not use the test runner.', $userId, $slug, 403);
        }
        if ($selections === [] || count($selections) > 2) {
            throw new RunRefused('promptlib_invalid_request', 'Choose one selection, or two to compare.', 400);
        }
        foreach ($selections as $selection) {
            if (!in_array($selection['model'], $settings->models(), true)) {
                throw new RunRefused('promptlib_invalid_request', 'That model is not in the list of models offered.', 400);
            }
        }
        $deadline = $this->deadlineSeconds();
        if (!$this->guard->acquire($userId, (int) ceil($deadline) + self::LOCK_GRACE)) {
            $this->refuse($day, 'promptlib_busy', 'A run is already in progress.', $userId, $slug, 429);
        }
        try {
            $rate = max(1, (int) apply_filters('promptlib_rate_limit', $settings->ratePerMinute(), $userId));
            if (!$this->guard->rate($userId, $rate)) {
                $this->refuse($day, 'promptlib_rate_limited', sprintf('At most %d runs a minute.', $rate), $userId, $slug, 429);
            }

            // Step 3: render. Errors stop the run before any spend.
            $prepared = [];
            foreach ($selections as $selection) {
                try {
                    $rendered = $this->renderSelection($templateId, $selection['version'], $values);
                } catch (PromptLibraryException $e) {
                    throw new RunRefused($e->errorCode(), $e->getMessage(), 422, '', $e->data());
                }
                $prepared[] = ['selection' => $selection, 'rendered' => $rendered];
            }

            // Step 4: own daily caps, reserved atomically before any call.
            $caps = $this->caps($settings);
            $count = count($prepared);
            if ($caps['cost'] > 0 && $this->counters->get($day, CounterRepository::COST) >= (int) round($caps['cost'] * 1e9)) {
                $this->refuse($day, 'promptlib_cap_reached', "Today's cost cap is reached.", $userId, $slug, 429, 'cost');
            }
            if ($caps['runs'] <= 0 || !$this->counters->reserve($day, CounterRepository::RUNS, $count, $caps['runs'])) {
                $this->refuse($day, 'promptlib_cap_reached', $count > 1
                    ? sprintf("Today's run cap of %d leaves room for fewer than %d runs; neither was made.", $caps['runs'], $count)
                    : sprintf("Today's run cap of %d is reached.", $caps['runs']), $userId, $slug, 429, 'runs');
            }

            // Step 5: spend-meter checks for every first attempt, before the first call.
            $decisions = [];
            foreach ($prepared as $item) {
                $decision = $this->meter->preSpend($this->intent($settings, $item['selection']['model'], $item['rendered'], $slug, $item['selection']['version']));
                if (!$decision['allowed']) {
                    foreach ($decisions as $earlier) {
                        $this->meter->release($earlier['reservation_id']);
                    }
                    $this->counters->release($day, CounterRepository::RUNS, $count);
                    $this->refuse($day, 'promptlib_meter_refused', $decision['message'], $userId, $slug, 429, $decision['reason']);
                }
                $decisions[] = $decision;
            }

            // Steps 6 to 9, per selection.
            $group = $count > 1 ? wp_generate_uuid4() : '';
            $start = microtime(true);
            $results = [];
            foreach ($prepared as $index => $item) {
                $results[] = $this->execute($templateId, $slug, $item['selection'], $item['rendered'], $decisions[$index], $settings, $group, $userId, $day, $start, $deadline);
            }
        } finally {
            $this->guard->release($userId);
        }

        return ['group' => $group, 'runs' => $results, 'today' => $this->counters->day($day)];
    }

    /**
     * Steps 6 to 9 for one selection. Each fallback attempt gets its own
     * pre-spend check and reservation id (decision D1).
     *
     * @param array{version: int|string, model: string} $selection
     * @param array{allowed: bool, metered: bool, reason: string, message: string, reservation_id: ?string, remaining: ?int, estimate: ?int} $decision
     * @return array<string, mixed>
     */
    private function execute(int $templateId, string $slug, array $selection, RenderedPrompt $rendered, array $decision, Settings $settings, string $group, int $userId, string $day, float $start, float $deadline): array
    {
        $purpose = (string) apply_filters('promptlib_runner_purpose', self::PURPOSE, $slug);
        $output = $rendered->output();
        $json = $output->mode() === 'json';
        $schema = $json ? (array) $output->schema() : self::textSchema();
        $tokenCap = $this->tokenCap($rendered, $settings);
        $temperature = $rendered->settings()['temperature'] ?? $settings->defaultTemperature();
        $routes = array_values(array_unique(array_merge([$selection['model']], $settings->fallbackModels())));
        $provider = $settings->providerKind();

        $reports = [];
        $response = null;
        $failure = null;
        $attemptNumber = 0;
        $callStart = microtime(true);
        foreach ($routes as $i => $model) {
            if ($i > 0) {
                $decision = $this->meter->preSpend($this->intent($settings, $model, $rendered, $slug, $selection['version']));
                if (!$decision['allowed']) {
                    $this->counters->add($day, CounterRepository::REFUSED_PREFIX . 'promptlib_meter_refused', 1);
                    $failure = ['kind' => 'meter', 'class' => 'meter:' . $decision['reason'], 'message' => $decision['message']];
                    break;
                }
            }
            $remaining = $deadline - (microtime(true) - $start);
            if ($remaining < self::MIN_ATTEMPT_SECONDS) {
                $this->meter->release($decision['reservation_id']);
                $failure = ['kind' => 'deadline', 'class' => 'deadline_guard', 'message' => self::MESSAGES['deadline']];
                break;
            }
            $response = $this->gateway->call(new GatewayCall(
                $purpose,
                $provider,
                $model,
                $rendered->system(),
                $rendered->user(),
                $json ? 'output' : 'answer',
                $schema,
                $temperature,
                $tokenCap,
                $remaining,
            ));
            if ($response->attempts === []) {
                $this->meter->release($decision['reservation_id']);
            }
            foreach ($response->attempts as $attempt) {
                $attemptNumber++;
                $reports[] = $attempt;
                $this->meter->record($decision['reservation_id'], $attempt, [
                    'purpose' => $purpose,
                    'provider' => $provider,
                    'model' => $model,
                    'attempt' => $attemptNumber,
                    'fallback_from' => $i > 0 ? $routes[0] : null,
                ]);
            }
            if ($response->ok) {
                $failure = null;
                break;
            }
            $failure = ['kind' => $response->errorKind, 'class' => $response->errorClass, 'message' => self::MESSAGES[$response->errorKind] ?? self::MESSAGES['no_route']];
            if (!$response->fallbackWorthy) {
                break;
            }
        }
        $durationMs = (int) round((microtime(true) - $callStart) * 1000);

        // Step 8: settle known cost; the run reservation is kept.
        $known = 0;
        $sources = [];
        $promptTokens = 0;
        $completionTokens = 0;
        foreach ($reports as $report) {
            $promptTokens += $report->inputTokens;
            $completionTokens += $report->outputTokens;
            $nanos = $report->costNanos();
            $sources[$report->costSource] = true;
            if ($nanos !== null) {
                $known += $nanos;
            }
        }
        if ($known > 0) {
            $this->counters->add($day, CounterRepository::COST, $known);
        }
        $costSource = self::costSource(array_keys($sources));

        $ok = $response !== null && $response->ok && $failure === null;
        $answer = null;
        $validation = null;
        if ($ok && $response->data !== null) {
            if ($json) {
                $answer = Text::json($response->data, true);
                $validation = "Valid against the template's schema.";
            } else {
                $answer = is_string($response->data['answer'] ?? null) ? $response->data['answer'] : Text::json($response->data, true);
            }
        } elseif ($failure !== null && $failure['kind'] === 'schema') {
            $validation = 'Did not match the schema: ' . ($response !== null ? $response->detail : '');
        }

        // Step 9: record.
        $store = (bool) apply_filters('promptlib_store_runs', $settings->storeRuns(), $slug);
        $version = is_int($selection['version']) ? $selection['version'] : 0;
        $row = [
            'template_id' => $templateId,
            'slug' => $slug,
            'version_no' => $version,
            'user_id' => $userId,
            'model' => $selection['model'],
            'provider' => $provider,
            'status' => $ok ? 'ok' : 'failed',
            'error_code' => $ok ? '' : substr($failure['kind'] . ':' . $failure['class'], 0, 64),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_nanos' => $costSource === 'unknown' ? null : $known,
            'cost_source' => $costSource,
            'duration_ms' => $durationMs,
            'attempts' => count($reports),
            'system_text' => $store ? $rendered->system() : null,
            'user_text' => $store ? $rendered->user() : null,
            'answer' => $store ? $answer : null,
            'compare_group' => $group,
            'created_at' => $this->clock->nowUtc(),
        ];
        $runId = $this->runs->insert($row);
        $row['id'] = $runId;
        $public = $row;
        unset($public['system_text'], $public['user_text'], $public['answer']);
        do_action('promptlib_run_finished', $public);
        $this->logger->info('Test run finished.', [
            'user_id' => $userId,
            'slug' => $slug,
            'version' => $version === 0 ? 'draft' : $version,
            'model' => $selection['model'],
            'outcome' => $ok ? 'ok' : $row['error_code'],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_nanos' => $row['cost_nanos'],
            'duration_ms' => $durationMs,
        ]);

        $chars = Text::length($rendered->system()) + Text::length($rendered->user());

        return [
            'run_id' => $runId,
            'version' => $version === 0 ? 'draft' : $version,
            'model' => $selection['model'],
            'answered_by' => $ok && $reports !== [] ? $reports[count($reports) - 1]->model : null,
            'status' => $ok ? 'ok' : 'failed',
            'message' => $ok ? '' : $failure['message'],
            'error_code' => $row['error_code'],
            'system' => $rendered->system(),
            'user' => $rendered->user(),
            'chars' => $chars,
            'est_tokens' => self::estimateTokens($rendered),
            'answer' => $answer,
            'output_mode' => $output->mode(),
            'wrapper' => $json ? null : 'The runner asked for an object with one string field "answer" and shows that field. In modes without strict structured output the library puts this schema in the system message.',
            'validation' => $validation,
            'content_hash' => $rendered->contentHash(),
            'render_hash' => $rendered->renderHash(),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost' => $costSource === 'unknown' ? null : $known / 1e9,
            'cost_source' => $costSource,
            'duration_ms' => $durationMs,
            'attempts' => count($reports),
            'guard' => $response !== null ? $response->guardActions : [],
            'meter' => $decision['metered'] ? ['remaining' => $decision['remaining']] : null,
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderSelection(int $templateId, int|string $selection, array $values): RenderedPrompt
    {
        $slug = $this->templates->slugOf($templateId);
        $renderer = new Renderer(Library::maxRenderBytes());
        if ($selection === 'draft') {
            $draft = $this->templates->draft($templateId);
            $draft['slug'] = $slug;
            return $renderer->render(Renderer::parseOrThrow($draft), $values, [], ['slug' => $slug, 'version' => 0]);
        }
        $number = is_int($selection) ? $selection : (int) $selection;
        $version = $this->library->load($templateId, $slug, $number, $this->templates->live($templateId));

        return $renderer->render($version->definition(), $values, [], [
            'slug' => $slug,
            'version' => $number,
            'content_hash' => $version->contentHash(),
        ]);
    }

    /**
     * The runner's deadline: 60 seconds (filterable), never above the PHP
     * time limit minus 10 seconds when that limit is set (case 68).
     */
    public function deadlineSeconds(): float
    {
        $deadline = (float) max(1, (int) apply_filters('promptlib_runner_deadline', self::DEFAULT_DEADLINE));
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $deadline = min($deadline, (float) max(1, $limit - 10));
        }

        return $deadline;
    }

    /** Characters divided by four, rounded up; an estimate (decision D7). */
    public static function estimateTokens(RenderedPrompt $rendered): int
    {
        $chars = Text::length($rendered->system()) + Text::length($rendered->user());

        return max(0, (int) apply_filters('promptlib_estimate_tokens', (int) ceil($chars / 4), $chars));
    }

    /** @return array{runs: int, cost: float} */
    private function caps(Settings $settings): array
    {
        $caps = apply_filters('promptlib_daily_caps', ['runs' => $settings->dailyRuns(), 'cost' => $settings->dailyCost()]);
        $caps = is_array($caps) ? $caps : [];

        return ['runs' => (int) ($caps['runs'] ?? $settings->dailyRuns()), 'cost' => (float) ($caps['cost'] ?? $settings->dailyCost())];
    }

    private function tokenCap(RenderedPrompt $rendered, Settings $settings): int
    {
        $cap = $rendered->settings()['token_cap'] ?? $settings->defaultTokenCap();

        return max(1, min($cap, $settings->maxTokenCap()));
    }

    /**
     * @return array{purpose: string, provider: string, model: string, est_input_tokens: int, max_output_tokens: int, template: string, version: int|string}
     */
    private function intent(Settings $settings, string $model, RenderedPrompt $rendered, string $slug, int|string $version): array
    {
        return [
            'purpose' => (string) apply_filters('promptlib_runner_purpose', self::PURPOSE, $slug),
            'provider' => $settings->providerKind(),
            'model' => $model,
            'est_input_tokens' => self::estimateTokens($rendered),
            'max_output_tokens' => $this->tokenCap($rendered, $settings),
            'template' => $slug,
            'version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    public static function textSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ];
    }

    /** @param list<string> $sources */
    private static function costSource(array $sources): string
    {
        if ($sources === []) {
            return 'unknown';
        }
        if (count($sources) === 1) {
            return $sources[0];
        }

        return in_array('unknown', $sources, true) ? 'partial' : 'mixed';
    }

    /**
     * Count, log and throw a refusal (cases 96 and 97).
     *
     * @return never
     */
    private function refuse(string $day, string $code, string $message, int $userId, string $slug, int $status, string $reason = ''): void
    {
        $this->counters->add($day, CounterRepository::REFUSED_PREFIX . $code, 1);
        $this->logger->info('Test run refused.', [
            'user_id' => $userId,
            'slug' => $slug,
            'outcome' => $code,
            'reason' => $reason,
        ]);
        throw new RunRefused($code, $message, $status, $reason);
    }
}
