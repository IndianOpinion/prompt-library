<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Plugin;

/**
 * Site Health tests (case 98): library present, provider configured, tables
 * and columns, runner on or off, today's runs against the cap, integrity.
 */
final class SiteHealth
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'tests']);
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function tests($tests): array
    {
        $tests = is_array($tests) ? $tests : [];
        $tests['direct']['promptlib_status'] = [
            'label' => __('Prompt library', 'prompt-library'),
            'test' => [$this, 'result'],
        ];

        return $tests;
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        $checks = $this->checks();
        $status = 'good';
        foreach ($checks as $check) {
            if ($check['status'] === 'critical') {
                $status = 'critical';
            } elseif ($check['status'] === 'recommended' && $status === 'good') {
                $status = 'recommended';
            }
        }
        $items = '';
        foreach ($checks as $check) {
            $items .= '<li><strong>' . esc_html($check['label']) . ':</strong> ' . esc_html($check['detail']) . '</li>';
        }
        $labels = [
            'good' => __('Prompt library is healthy', 'prompt-library'),
            'recommended' => __('Prompt library needs attention', 'prompt-library'),
            'critical' => __('Prompt library has a problem', 'prompt-library'),
        ];

        return [
            'label' => $labels[$status],
            'status' => $status,
            'badge' => ['label' => __('Prompt library', 'prompt-library'), 'color' => $status === 'good' ? 'blue' : 'red'],
            'description' => '<ul>' . $items . '</ul>',
            'test' => 'promptlib_status',
        ];
    }

    /**
     * Each state the plugin reports.
     *
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        $settings = Settings::load();
        $checks = [];
        $libraryPresent = $this->plugin->runner->gateway()->available();
        $checks[] = ['id' => 'library', 'label' => 'Model library', 'status' => $libraryPresent ? 'good' : 'recommended', 'detail' => $libraryPresent ? 'The model gateway is available.' : 'llm-json-php is missing; run composer install in the plugin folder. Rendering still works.'];
        $configured = Settings::hasProviderKey() && $settings->models() !== [];
        $checks[] = ['id' => 'provider', 'label' => 'Provider', 'status' => $configured ? 'good' : 'recommended', 'detail' => $configured ? 'A provider key and at least one model are configured.' : 'No provider key or no model is configured; the test runner is off.'];
        $missing = Schema::storedMissing();
        $checks[] = ['id' => 'tables', 'label' => 'Tables', 'status' => $missing === [] ? 'good' : 'critical', 'detail' => $missing === [] ? 'Every table and column is present.' : 'Missing columns: ' . implode(', ', $missing) . '. Deactivate and activate the plugin to retry.'];
        $disabled = $this->plugin->runner->disabledReason($settings);
        $checks[] = ['id' => 'runner', 'label' => 'Test runner', 'status' => 'good', 'detail' => $disabled === null ? 'On.' : 'Off: ' . $disabled['message']];
        $today = $this->plugin->counters->day($this->plugin->clock->siteDay());
        $checks[] = ['id' => 'today', 'label' => 'Runs today', 'status' => 'good', 'detail' => sprintf('%d of %d.', $today[CounterRepository::RUNS] ?? 0, $settings->dailyRuns())];
        $integrity = get_option(Library::INTEGRITY_OPTION, []);
        $integrity = is_array($integrity) ? $integrity : [];
        $checks[] = ['id' => 'integrity', 'label' => 'Version integrity', 'status' => $integrity === [] ? 'good' : 'critical', 'detail' => $integrity === [] ? 'No integrity errors seen.' : 'Versions that failed their integrity check: ' . implode(', ', array_keys($integrity)) . '.'];
        if ($settings->isCorrupt()) {
            $checks[] = ['id' => 'settings', 'label' => 'Settings', 'status' => 'recommended', 'detail' => 'The stored settings are unreadable; safe defaults are in use until they are saved again.'];
        }

        return $checks;
    }
}
