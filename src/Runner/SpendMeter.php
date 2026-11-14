<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Runner;

use IndianOpinion\PromptLibrary\WordPress\Logger;

/**
 * The only file that knows the spend-meter contract (section 5.8).
 *
 * Optional: when the meter is not installed, or its pre-spend filter
 * returns null, the run goes on under the plugin's own caps. When it is
 * installed but broken (throws, returns a malformed decision, or speaks a
 * contract version this adapter does not know), the run stops: a broken
 * meter must not mean unmetered spend.
 */
class SpendMeter
{
    public const CALLER = 'prompt-library';
    /** Contract versions this adapter binds to. */
    public const KNOWN_API = ['1'];
    /** Reasons a meter may give with allowed=true. */
    public const ALLOWED_REASONS = ['', 'ok', 'allowed'];

    /** Deny codes with a plain-language message. Others show a generic message. */
    public const DENY_MESSAGES = [
        'kill_switch' => 'The spend meter kill switch is on.',
        'kill_switch_purpose' => 'The spend meter has switched off spending for this purpose.',
        'unpriced_model' => 'The spend meter has no price for this model, so it will not allow the call.',
        'ledger_unavailable' => 'The spend meter ledger is unavailable, so it will not allow the call.',
        'denied_by_filter' => 'A spend meter rule denied the call.',
        'decision_filter_error' => 'The spend meter could not decide, so it will not allow the call.',
        'invalid_request' => 'The spend meter rejected the request as invalid.',
    ];

    public function __construct(private readonly Logger $logger)
    {
    }

    /** True when the meter announces itself with its contract constant. */
    public function present(): bool
    {
        return defined('SPEND_METER_API');
    }

    public function bound(): bool
    {
        return $this->present() && in_array((string) constant('SPEND_METER_API'), self::KNOWN_API, true);
    }

    /**
     * The pre-call check. Never throws.
     *
     * @param array{purpose: string, provider: string, model: string, est_input_tokens: int, max_output_tokens: int, template: string, version: int|string} $call
     * @return array{allowed: bool, metered: bool, reason: string, message: string, reservation_id: ?string, remaining: ?int, estimate: ?int}
     */
    public function preSpend(array $call): array
    {
        if (!$this->present()) {
            return self::unmetered();
        }
        if (!$this->bound()) {
            $this->logger->throttled('meter-api', HOUR_IN_SECONDS, 'error', 'The spend meter speaks a contract version this plugin does not know; runs are refused.', [
                'api' => (string) constant('SPEND_METER_API'),
            ]);
            return self::deny('unsupported_api', 'The installed spend meter speaks a contract version this plugin does not know.');
        }
        $intent = [
            'purpose' => $call['purpose'],
            'caller' => self::CALLER,
            'provider' => $call['provider'],
            'model' => $call['model'],
            'est_input_tokens' => $call['est_input_tokens'],
            'max_output_tokens' => $call['max_output_tokens'],
            'meta' => ['template' => $call['template'], 'version' => $call['version']],
        ];
        try {
            $decision = apply_filters('spend_meter_pre_spend', null, $intent);
        } catch (\Throwable $e) {
            $this->logger->error('The spend meter pre-spend filter threw; the run is refused.', ['error' => get_class($e) . ': ' . $e->getMessage()]);
            return self::deny('meter_error', 'The spend meter failed, so the run was stopped.');
        }
        if ($decision === null) {
            return self::unmetered();
        }
        if (!is_array($decision) || !is_bool($decision['allowed'] ?? null)) {
            $this->logger->error('The spend meter returned a malformed decision; the run is refused.', ['type' => get_debug_type($decision)]);
            return self::deny('meter_malformed', 'The spend meter gave an unreadable answer, so the run was stopped.');
        }
        if (isset($decision['api']) && !in_array((string) $decision['api'], self::KNOWN_API, true)) {
            $this->logger->error('The spend meter decision names an unknown contract version; the run is refused.', ['api' => (string) $decision['api']]);
            return self::deny('unsupported_api', 'The spend meter answered in a contract version this plugin does not know.');
        }
        $reason = is_string($decision['reason'] ?? null) ? $decision['reason'] : '';
        $message = is_string($decision['message'] ?? null) ? $decision['message'] : '';
        $reservation = $decision['reservation_id'] ?? null;
        $reservation = is_string($reservation) && $reservation !== '' ? $reservation : null;
        $remaining = isset($decision['remaining']) && is_int($decision['remaining']) ? $decision['remaining'] : null;
        $estimate = isset($decision['estimate']) && is_int($decision['estimate']) ? $decision['estimate'] : null;
        if ($decision['allowed'] === true) {
            if (!in_array($reason, self::ALLOWED_REASONS, true)) {
                $this->logger->error('The spend meter allowed the call with an unknown reason; the run is refused.', ['reason' => $reason, 'message' => $message]);
                if ($reservation !== null) {
                    $this->release($reservation);
                }
                return self::deny('meter_malformed', 'The spend meter gave an unreadable answer, so the run was stopped.');
            }
            return ['allowed' => true, 'metered' => true, 'reason' => $reason, 'message' => $message, 'reservation_id' => $reservation, 'remaining' => $remaining, 'estimate' => $estimate];
        }
        $known = self::denyMessage($reason);
        if ($known === null) {
            $this->logger->warning('The spend meter refused the call with a reason this plugin does not know.', ['reason' => $reason, 'message' => $message]);
        } else {
            $this->logger->info('The spend meter refused the call.', ['reason' => $reason, 'message' => $message]);
        }

        return self::deny($reason === '' ? 'denied' : $reason, $known ?? 'Refused by the spend meter.');
    }

    /**
     * The plain-language text for a deny code the screen knows, or null.
     * The six cap codes all start with "cap_".
     */
    public static function denyMessage(string $reason): ?string
    {
        if (isset(self::DENY_MESSAGES[$reason])) {
            return self::DENY_MESSAGES[$reason];
        }
        if (preg_match('/^cap_[a-z0-9_]+$/', $reason) === 1) {
            return 'A spend meter cap is reached.';
        }

        return null;
    }

    /** Give back a reservation when the run stops before the call is made. */
    public function release(?string $reservationId): void
    {
        if ($reservationId === null || !$this->bound()) {
            return;
        }
        try {
            do_action('spend_meter_release', $reservationId);
        } catch (\Throwable $e) {
            $this->logger->error('A spend meter release listener threw.', ['error' => get_class($e) . ': ' . $e->getMessage()]);
        }
    }

    /**
     * Report one attempt. A throwing listener never breaks the run.
     *
     * @param array{purpose: string, provider: string, model: string, attempt: int, fallback_from: ?string} $context
     */
    public function record(?string $reservationId, AttemptReport $attempt, array $context): void
    {
        if (!$this->bound()) {
            return;
        }
        $call = [
            'reservation_id' => $reservationId,
            'call_id' => wp_generate_uuid4(),
            'purpose' => $context['purpose'],
            'caller' => self::CALLER,
            'provider' => $attempt->provider !== '' ? $attempt->provider : $context['provider'],
            'model' => $attempt->model !== '' ? $attempt->model : $context['model'],
            'status' => $attempt->status,
            'input_tokens' => $attempt->inputTokens,
            'cached_input_tokens' => $attempt->cachedInputTokens,
            'output_tokens' => $attempt->outputTokens,
            'reasoning_tokens' => $attempt->reasoningTokens,
            'http_status' => $attempt->httpStatus,
            'error_code' => substr($attempt->errorCode, 0, 64),
            'latency_ms' => $attempt->latencyMs,
            'attempt' => $context['attempt'],
        ];
        if ($attempt->costSource === 'reported' && $attempt->costNanos() !== null) {
            $call['provider_cost'] = $attempt->costNanos();
        }
        if ($context['fallback_from'] !== null) {
            $call['fallback_from'] = $context['fallback_from'];
        }
        try {
            do_action('spend_meter_record', $call);
        } catch (\Throwable $e) {
            $this->logger->error('A spend meter record listener threw; the run goes on.', ['error' => get_class($e) . ': ' . $e->getMessage()]);
        }
    }

    /** @return array{allowed: bool, metered: bool, reason: string, message: string, reservation_id: ?string, remaining: ?int, estimate: ?int} */
    private static function unmetered(): array
    {
        return ['allowed' => true, 'metered' => false, 'reason' => '', 'message' => '', 'reservation_id' => null, 'remaining' => null, 'estimate' => null];
    }

    /** @return array{allowed: bool, metered: bool, reason: string, message: string, reservation_id: ?string, remaining: ?int, estimate: ?int} */
    private static function deny(string $reason, string $message): array
    {
        return ['allowed' => false, 'metered' => true, 'reason' => $reason, 'message' => $message, 'reservation_id' => null, 'remaining' => null, 'estimate' => null];
    }
}
