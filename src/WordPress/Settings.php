<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

/**
 * Site settings (section 7). Loaded fresh on every call to load(), so a kill
 * switch flipped between page load and Run takes effect (lesson 7).
 */
final class Settings
{
    public const OPTION = 'promptlib_settings';
    public const KEY_OPTION = 'promptlib_provider_key';
    public const PROVIDERS = ['openrouter', 'openai', 'generic'];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values, private readonly bool $corrupt)
    {
        $this->values = $values;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'runner_enabled' => true,
            'provider_kind' => 'openrouter',
            'base_url' => '',
            'models' => [],
            'fallback_models' => [],
            'default_model' => '',
            'default_temperature' => null,
            'default_token_cap' => 2048,
            'max_token_cap' => 8192,
            'daily_runs' => 50,
            'daily_cost' => 0.0,
            'price_table' => [],
            'store_runs' => true,
            'run_retention_days' => 30,
            'rate_per_minute' => 10,
        ];
    }

    /**
     * Read the option now. Missing settings use the defaults; corrupt
     * settings use the defaults in memory and are flagged, never written.
     */
    public static function load(): self
    {
        $raw = get_option(self::OPTION, null);
        if ($raw === null || $raw === false) {
            return new self(self::defaults(), false);
        }
        if (!is_array($raw)) {
            return new self(self::defaults(), true);
        }
        [$clean, $errors] = self::sanitise($raw);
        if ($errors !== []) {
            return new self(self::defaults(), true);
        }

        return new self($clean, false);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        [$clean] = self::sanitise($values + self::defaults());

        return new self($clean, false);
    }

    /**
     * Validate a settings array. Unknown keys are dropped.
     *
     * @param array<mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function sanitise(array $input): array
    {
        $d = self::defaults();
        $out = $d;
        $errors = [];
        $out['runner_enabled'] = self::bool($input['runner_enabled'] ?? $d['runner_enabled'], 'runner_enabled', $errors);
        $kind = $input['provider_kind'] ?? $d['provider_kind'];
        if (!is_string($kind) || !in_array($kind, self::PROVIDERS, true)) {
            $errors[] = 'provider_kind';
            $kind = $d['provider_kind'];
        }
        $out['provider_kind'] = $kind;
        $url = $input['base_url'] ?? '';
        if (!is_string($url)) {
            $errors[] = 'base_url';
            $url = '';
        }
        $url = trim($url);
        if ($url !== '' && !self::urlAllowed($url)) {
            $errors[] = 'base_url';
            $url = '';
        }
        if ($kind === 'generic' && $url === '') {
            $errors[] = 'base_url_required';
        }
        $out['base_url'] = $url;
        $out['models'] = self::modelList($input['models'] ?? [], 'models', $errors);
        $out['fallback_models'] = self::modelList($input['fallback_models'] ?? [], 'fallback_models', $errors);
        $default = $input['default_model'] ?? '';
        $out['default_model'] = is_string($default) && in_array($default, $out['models'], true) ? $default : '';
        $temperature = $input['default_temperature'] ?? null;
        if ($temperature === '' || $temperature === null) {
            $out['default_temperature'] = null;
        } elseif (is_numeric($temperature) && (float) $temperature >= 0 && (float) $temperature <= 2) {
            $out['default_temperature'] = (float) $temperature;
        } else {
            $errors[] = 'default_temperature';
        }
        $out['max_token_cap'] = self::int($input['max_token_cap'] ?? $d['max_token_cap'], 1, 1000000, 'max_token_cap', $errors, $d['max_token_cap']);
        $out['default_token_cap'] = min(
            self::int($input['default_token_cap'] ?? $d['default_token_cap'], 1, 1000000, 'default_token_cap', $errors, $d['default_token_cap']),
            $out['max_token_cap']
        );
        $out['daily_runs'] = self::int($input['daily_runs'] ?? $d['daily_runs'], 0, 100000, 'daily_runs', $errors, $d['daily_runs']);
        $cost = $input['daily_cost'] ?? 0;
        if (is_numeric($cost) && (float) $cost >= 0) {
            $out['daily_cost'] = (float) $cost;
        } else {
            $errors[] = 'daily_cost';
        }
        $out['price_table'] = self::prices($input['price_table'] ?? [], $errors);
        $out['store_runs'] = self::bool($input['store_runs'] ?? $d['store_runs'], 'store_runs', $errors);
        $out['run_retention_days'] = self::int($input['run_retention_days'] ?? $d['run_retention_days'], 1, 3650, 'run_retention_days', $errors, $d['run_retention_days']);
        $out['rate_per_minute'] = self::int($input['rate_per_minute'] ?? $d['rate_per_minute'], 1, 1000, 'rate_per_minute', $errors, $d['rate_per_minute']);

        return [$out, $errors];
    }

    /** HTTPS, or plain HTTP to localhost only. */
    public static function urlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    public function isCorrupt(): bool
    {
        return $this->corrupt;
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->values[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function runnerEnabled(): bool
    {
        return (bool) $this->values['runner_enabled'];
    }

    /** @return list<string> */
    public function models(): array
    {
        /** @var list<string> */
        return $this->values['models'];
    }

    /** @return list<string> */
    public function fallbackModels(): array
    {
        /** @var list<string> */
        return $this->values['fallback_models'];
    }

    public function defaultModel(): string
    {
        $models = $this->models();
        $default = (string) $this->values['default_model'];

        return $default !== '' ? $default : ($models[0] ?? '');
    }

    public function providerKind(): string
    {
        return (string) $this->values['provider_kind'];
    }

    public function baseUrl(): string
    {
        return (string) $this->values['base_url'];
    }

    public function defaultTemperature(): ?float
    {
        $t = $this->values['default_temperature'];

        return $t === null ? null : (float) $t;
    }

    public function defaultTokenCap(): int
    {
        return (int) $this->values['default_token_cap'];
    }

    public function maxTokenCap(): int
    {
        return (int) $this->values['max_token_cap'];
    }

    public function dailyRuns(): int
    {
        return (int) $this->values['daily_runs'];
    }

    public function dailyCost(): float
    {
        return (float) $this->values['daily_cost'];
    }

    public function storeRuns(): bool
    {
        return (bool) $this->values['store_runs'];
    }

    public function retentionDays(): int
    {
        return (int) $this->values['run_retention_days'];
    }

    public function ratePerMinute(): int
    {
        return (int) $this->values['rate_per_minute'];
    }

    /** @return list<array{model: string, input: float, output: float, taken: string}> */
    public function priceTable(): array
    {
        /** @var list<array{model: string, input: float, output: float, taken: string}> */
        return $this->values['price_table'];
    }

    /**
     * The provider key. A constant is preferred; on multisite a site key may
     * override the network constant only when PROMPTLIB_ALLOW_SITE_KEY is true.
     */
    public static function providerKey(): string
    {
        $siteKey = get_option(self::KEY_OPTION, '');
        $siteKey = is_string($siteKey) ? $siteKey : '';
        $constant = defined('PROMPTLIB_PROVIDER_KEY') ? (string) constant('PROMPTLIB_PROVIDER_KEY') : '';
        if ($constant === '') {
            return $siteKey;
        }
        $allowSite = is_multisite() && defined('PROMPTLIB_ALLOW_SITE_KEY') && constant('PROMPTLIB_ALLOW_SITE_KEY') === true;

        return $allowSite && $siteKey !== '' ? $siteKey : $constant;
    }

    public static function hasProviderKey(): bool
    {
        return self::providerKey() !== '';
    }

    /**
     * @param mixed $value
     * @param list<string> $errors
     */
    private static function bool($value, string $name, array &$errors): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'on', 'yes', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', '', 'off', 'no', 'false'], true)) {
            return false;
        }
        $errors[] = $name;

        return (bool) self::defaults()[$name];
    }

    /**
     * @param mixed $value
     * @param list<string> $errors
     */
    private static function int($value, int $min, int $max, string $name, array &$errors, int $fallback): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1)) {
            $int = (int) $value;
            if ($int >= $min && $int <= $max) {
                return $int;
            }
        }
        $errors[] = $name;

        return $fallback;
    }

    /**
     * @param mixed $value
     * @param list<string> $errors
     * @return list<string>
     */
    private static function modelList($value, string $name, array &$errors): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            $errors[] = $name;
            return [];
        }
        $out = [];
        foreach ($value as $model) {
            if (!is_string($model)) {
                $errors[] = $name;
                continue;
            }
            $model = trim($model);
            if ($model === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,199}$/', $model) !== 1) {
                $errors[] = $name;
                continue;
            }
            if (!in_array($model, $out, true)) {
                $out[] = $model;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @param list<string> $errors
     * @return list<array{model: string, input: float, output: float, taken: string}>
     */
    private static function prices($value, array &$errors): array
    {
        if (!is_array($value)) {
            $errors[] = 'price_table';
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row) || !is_string($row['model'] ?? null) || trim($row['model']) === '') {
                if (is_array($row) && ($row['model'] ?? '') === '') {
                    continue;
                }
                $errors[] = 'price_table';
                continue;
            }
            $input = $row['input'] ?? null;
            $output = $row['output'] ?? null;
            $taken = $row['taken'] ?? '';
            if (
                !is_numeric($input) || !is_numeric($output) || (float) $input < 0 || (float) $output < 0
                || !is_string($taken) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $taken) !== 1
            ) {
                $errors[] = 'price_table';
                continue;
            }
            $out[] = ['model' => trim($row['model']), 'input' => (float) $input, 'output' => (float) $output, 'taken' => $taken];
        }

        return $out;
    }
}
