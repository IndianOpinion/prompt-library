<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\WordPress;

use IndianOpinion\PromptLibrary\Plugin;

/**
 * Personal data exporter and eraser for run rows, keyed on the user who ran
 * them (section 8).
 */
final class Privacy
{
    private const PAGE = 100;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'erasers']);
        add_action('admin_init', [$this, 'policy']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public function exporters($exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['prompt-library'] = ['exporter_friendly_name' => __('Prompt library test runs', 'prompt-library'), 'callback' => [$this, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public function erasers($erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['prompt-library'] = ['eraser_friendly_name' => __('Prompt library test runs', 'prompt-library'), 'callback' => [$this, 'erase']];

        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['data' => [], 'done' => true];
        }
        $rows = $this->plugin->runs->forUser((int) $user->ID, self::PAGE, ($page - 1) * self::PAGE);
        $data = [];
        foreach ($rows as $row) {
            $fields = [];
            foreach (['slug', 'version_no', 'model', 'status', 'created_at', 'system_text', 'user_text', 'answer'] as $key) {
                if (($row[$key] ?? null) !== null) {
                    $fields[] = ['name' => $key, 'value' => (string) $row[$key]];
                }
            }
            $data[] = ['group_id' => 'prompt-library-runs', 'group_label' => __('Prompt library test runs', 'prompt-library'), 'item_id' => 'promptlib-run-' . $row['id'], 'data' => $fields];
        }

        return ['data' => $data, 'done' => count($rows) < self::PAGE];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $removed = $this->plugin->runs->deleteForUser((int) $user->ID);

        return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    public function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('Prompt Library', wp_kses_post(
            '<p>' . __('The prompt library test runner stores the prompts it sends and the answers it receives, including any personal data typed into the variable values, for 30 days by default. Rows are visible only to the person who ran them and to administrators. An administrator can switch storage to metadata only.', 'prompt-library') . '</p>'
        ));
    }
}
