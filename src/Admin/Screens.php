<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Admin;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Diff;
use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Runner\RunRefused;
use IndianOpinion\PromptLibrary\Text;
use IndianOpinion\PromptLibrary\VariableSpec;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;
use IndianOpinion\PromptLibrary\WordPress\CounterRepository;
use IndianOpinion\PromptLibrary\WordPress\Settings;
use IndianOpinion\PromptLibrary\WordPress\TemplateRepository;

/**
 * The plugin's own screens. Plain textareas, monospace, no rich text and no
 * block editor. Rendered prompts and answers are shown as text, never HTML.
 */
final class Screens
{
    private const MESSAGES = [
        'created' => 'Template created.',
        'saved' => 'Draft saved.',
        'published' => 'Version published.',
        'live' => 'Live version changed.',
        'restored' => 'The version was copied into the draft. Nothing was published.',
        'offer' => 'The offered update is in the draft. Review it and publish.',
        'fixture' => 'Fixture saved.',
        'fixture_deleted' => 'Fixture deleted.',
        'roles' => 'Roles reset to the default capability map.',
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** @param array<string, mixed> $state */
    public function editor(array $state): void
    {
        $id = isset($_GET['template']) ? absint($_GET['template']) : 0;
        $templates = $this->plugin->templates;
        $post = $id > 0 ? $templates->post($id) : null;
        if ($id > 0 && ($post === null || !current_user_can('edit_post', $id))) {
            wp_die(esc_html__('That template does not exist or you may not edit it.', 'prompt-library'));
        }
        $draft = $id > 0 ? $templates->draft($id) : DraftView::draft();
        $hash = TemplateRepository::draftHash($draft);
        $raw = ['variables' => Text::json($draft['variables'] ?? [], true), 'output_schema' => isset($draft['output']['schema']) ? Text::json($draft['output']['schema'], true) : ''];
        if (isset($state['form']) && is_array($state['form']) && ($state['form']['promptlib_action'] ?? '') === 'save_draft') {
            $form = Forms::draftFromPost($state['form']);
            $draft = $form['draft'];
            $raw = $form['raw'] + $raw;
            $hash = (string) ($state['form']['draft_hash'] ?? $hash);
        }
        $highest = $id > 0 ? $this->plugin->versions->highestNumber($id) : 0;
        $live = $id > 0 ? $templates->live($id) : 0;

        echo '<div class="wrap promptlib">';
        printf('<h1>%s</h1>', esc_html($id > 0 ? sprintf('Edit template: %s', (string) $post?->post_title) : 'Add prompt template'));
        $this->messages($state);
        if ($id > 0) {
            if (function_exists('wp_check_post_lock')) {
                $lockedBy = wp_check_post_lock($id);
                if ($lockedBy) {
                    $other = get_userdata((int) $lockedBy);
                    printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html(sprintf('%s is currently editing this template. Saving may conflict with their work.', $other instanceof \WP_User ? $other->display_name : 'Someone else')));
                } elseif (function_exists('wp_set_post_lock')) {
                    wp_set_post_lock($id);
                }
            }
            printf(
                '<p class="promptlib-links">%s &middot; <a href="%s">%s</a> &middot; <a href="%s">%s</a> &middot; <a href="%s">%s</a></p>',
                esc_html(sprintf('Live version: %s. Highest version: %d.', $live > 0 ? (string) $live : 'none', $highest)),
                esc_url(Admin::url('promptlib-history', ['template' => $id])),
                esc_html__('History', 'prompt-library'),
                esc_url(Admin::url('promptlib-runner', ['template' => $id])),
                esc_html__('Test runner', 'prompt-library'),
                esc_url(wp_nonce_url(add_query_arg(['action' => 'promptlib_export', 'template' => $id, 'with_draft' => 1], admin_url('admin-post.php')), 'promptlib_export')),
                esc_html__('Export', 'prompt-library')
            );
            $this->offers($id);
        }
        if (isset($state['conflict']) && is_array($state['conflict'])) {
            echo '<div class="notice notice-error"><p>' . esc_html('Someone else saved this draft after your page loaded. Nothing was saved. Their version is on the left, yours on the right. Copy what you need, reload, and save again.') . '</p></div>';
            echo '<div class="promptlib-columns">';
            foreach (['stored' => 'Saved draft', 'submitted' => 'Your changes'] as $key => $label) {
                $side = DraftView::draft((array) $state['conflict'][$key]);
                printf('<div><h3>%s</h3><h4>System</h4><pre class="promptlib-text">%s</pre><h4>User</h4><pre class="promptlib-text">%s</pre></div>', esc_html($label), esc_html((string) $side['system']), esc_html((string) $side['user']));
            }
            echo '</div>';
        }

        echo '<form method="post" class="promptlib-editor" data-template="' . esc_attr((string) $id) . '">';
        wp_nonce_field('promptlib_save_draft');
        echo '<input type="hidden" name="promptlib_action" value="save_draft">';
        printf('<input type="hidden" name="template" value="%d">', $id);
        printf('<input type="hidden" name="draft_hash" value="%s">', esc_attr($hash));
        echo '<table class="form-table" role="presentation">';
        $slugLocked = $highest > 0;
        printf(
            '<tr><th><label for="promptlib-slug">Slug</label></th><td><input id="promptlib-slug" name="slug" class="regular-text code" value="%s" %s required><p class="description">%s</p></td></tr>',
            esc_attr((string) ($draft['slug'] ?? '')),
            $slugLocked ? 'readonly' : '',
            esc_html($slugLocked ? 'Locked since version 1 was published. Use "Duplicate as new slug" below to rename.' : 'Lower-case letters and digits, joined by single hyphens or underscores. Consumers use this name.')
        );
        printf('<tr><th><label for="promptlib-title">Title</label></th><td><input id="promptlib-title" name="title" class="regular-text" value="%s"></td></tr>', esc_attr((string) ($draft['title'] ?? '')));
        printf('<tr><th><label for="promptlib-description">Description</label></th><td><textarea id="promptlib-description" name="description" rows="2" class="large-text">%s</textarea></td></tr>', esc_textarea((string) ($draft['description'] ?? '')));
        printf('<tr><th><label for="promptlib-system">System part</label></th><td><textarea id="promptlib-system" name="system" rows="12" class="large-text code promptlib-mono" spellcheck="false">%s</textarea></td></tr>', esc_textarea((string) ($draft['system'] ?? '')));
        printf('<tr><th><label for="promptlib-user">User part</label></th><td><textarea id="promptlib-user" name="user" rows="12" class="large-text code promptlib-mono" spellcheck="false">%s</textarea><p class="description">%s</p></td></tr>', esc_textarea((string) ($draft['user'] ?? '')), esc_html('Placeholders: {{ name }}. Write \{{ for a literal {{. No filters, defaults or expressions.'));
        printf('<tr><th><label for="promptlib-variables">Variables (JSON)</label></th><td><textarea id="promptlib-variables" name="variables" rows="10" class="large-text code promptlib-mono" spellcheck="false">%s</textarea><p class="description">%s</p></td></tr>', esc_textarea($raw['variables']), esc_html('A list of objects with name, type (string, text, integer, number, boolean, enum, list, json), required, default, max_length, escape (plain, raw, line, json, fenced), enum and description.'));
        $mode = ($draft['output']['mode'] ?? 'text') === 'json' ? 'json' : 'text';
        printf('<tr><th><label for="promptlib-output-mode">Output</label></th><td><select id="promptlib-output-mode" name="output_mode"><option value="text" %s>text</option><option value="json" %s>json</option></select><p><textarea name="output_schema" rows="6" class="large-text code promptlib-mono" placeholder="JSON schema with an object root" spellcheck="false">%s</textarea></p></td></tr>', selected($mode, 'text', false), selected($mode, 'json', false), esc_textarea($raw['output_schema']));
        printf(
            '<tr><th>Model settings</th><td><label>Temperature <input name="temperature" size="5" value="%s"></label> <label>Output token cap <input name="token_cap" size="7" value="%s"></label><p class="description">Empty uses the site defaults.</p></td></tr>',
            esc_attr(is_scalar($draft['settings']['temperature'] ?? null) ? (string) $draft['settings']['temperature'] : ''),
            esc_attr(is_scalar($draft['settings']['token_cap'] ?? null) ? (string) $draft['settings']['token_cap'] : '')
        );
        printf('<tr><th><label for="promptlib-note">Note for the next version</label></th><td><input id="promptlib-note" name="note" class="large-text" value="%s" maxlength="500"></td></tr>', esc_attr((string) ($draft['note'] ?? '')));
        echo '</table>';
        submit_button($id > 0 ? __('Save draft', 'prompt-library') : __('Create template', 'prompt-library'));
        echo '<p class="promptlib-autosave description" aria-live="polite"></p>';
        echo '</form>';

        if ($id > 0) {
            $issues = Definition::validate(['slug' => $templates->slugOf($id)] + $templates->draft($id), \IndianOpinion\PromptLibrary\WordPress\Drafts::limits());
            echo '<h2>' . esc_html__('Checks on the saved draft', 'prompt-library') . '</h2>';
            echo $issues === [] ? '<p>' . esc_html__('No errors or warnings.', 'prompt-library') . '</p>' : Admin::issuesList($issues); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html.
            $this->publishForm($id, $issues, $state);
            if ($slugLocked) {
                echo '<h2>' . esc_html__('Duplicate as new slug', 'prompt-library') . '</h2><form method="post">';
                wp_nonce_field('promptlib_duplicate');
                printf('<input type="hidden" name="promptlib_action" value="duplicate"><input type="hidden" name="template" value="%d">', $id);
                echo '<input name="new_slug" class="regular-text code" required> ';
                submit_button(__('Duplicate', 'prompt-library'), 'secondary', 'submit', false);
                echo '</form>';
            }
        }
        echo '</div>';
    }

    /**
     * @param list<Issue> $issues
     * @param array<string, mixed> $state
     */
    private function publishForm(int $id, array $issues, array $state): void
    {
        if (!current_user_can(Capabilities::PUBLISH)) {
            return;
        }
        echo '<h2>' . esc_html__('Publish a version', 'prompt-library') . '</h2>';
        $errors = Issue::errorsOf($issues);
        if ($errors !== []) {
            echo '<p>' . esc_html__('Fix the errors above before publishing.', 'prompt-library') . '</p>';
            return;
        }
        $confirm = isset($state['confirm']) && is_array($state['confirm']) && $state['confirm']['action'] === 'publish' ? $state['confirm'] : null;
        echo '<form method="post">';
        wp_nonce_field('promptlib_publish');
        printf('<input type="hidden" name="promptlib_action" value="publish"><input type="hidden" name="template" value="%d">', $id);
        $note = $confirm !== null ? (string) ($confirm['post']['version_note'] ?? '') : (string) ($this->plugin->templates->draft($id)['note'] ?? '');
        printf('<p><label>Version note (required, one line) <input name="version_note" class="large-text" maxlength="500" value="%s" required></label></p>', esc_attr($note));
        echo '<p><label><input type="checkbox" name="make_live" value="1" checked> ' . esc_html__('Make live', 'prompt-library') . '</label></p>';
        if ($confirm !== null) {
            $this->confirmBox($confirm['failing']);
        }
        submit_button(__('Publish version', 'prompt-library'), 'primary', 'submit', false);
        echo '</form>';
    }

    /** @param array<string, list<string>> $failing */
    private function confirmBox(array $failing): void
    {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('These declared consumers do not supply a required variable and will fail once this version is live:', 'prompt-library') . '</p><ul>';
        foreach ($failing as $consumer => $missing) {
            printf('<li><code>%s</code>: %s</li>', esc_html($consumer), esc_html(implode(', ', $missing)));
        }
        echo '</ul><p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('I understand; go ahead.', 'prompt-library') . '</label></p></div>';
    }

    private function offers(int $id): void
    {
        foreach ($this->plugin->templates->offers($id) as $consumer => $offer) {
            if (empty($offer['pending'])) {
                continue;
            }
            echo '<div class="notice notice-info inline"><form method="post"><p>';
            echo esc_html(sprintf('An update is offered by %s. Load it into the draft to review it; nothing is published automatically.', $consumer)) . ' ';
            wp_nonce_field('promptlib_load_offer');
            printf('<input type="hidden" name="promptlib_action" value="load_offer"><input type="hidden" name="template" value="%d"><input type="hidden" name="consumer" value="%s">', $id, esc_attr((string) $consumer));
            submit_button(__('Load into draft', 'prompt-library'), 'secondary small', 'submit', false);
            echo '</p></form></div>';
        }
    }

    /** @param array<string, mixed> $state */
    public function history(array $state): void
    {
        $id = isset($_GET['template']) ? absint($_GET['template']) : 0;
        $post = $this->plugin->templates->post($id);
        if ($post === null || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('That template does not exist or you may not view it.', 'prompt-library'));
        }
        $live = $this->plugin->templates->live($id);
        echo '<div class="wrap promptlib">';
        printf('<h1>%s</h1>', esc_html(sprintf('History: %s', $post->post_title)));
        $this->messages($state);
        printf('<p><a href="%s">%s</a></p>', esc_url(Admin::url('promptlib-edit', ['template' => $id])), esc_html__('Back to the editor', 'prompt-library'));
        $rows = $this->plugin->versions->history($id);
        echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>What</th><th>Who</th><th>Note or reason</th><th>Hash</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $user = get_userdata((int) $row['user_id']);
            $who = $user instanceof \WP_User ? $user->display_name : ((int) $row['user_id'] === 0 ? 'system' : '#' . (int) $row['user_id']);
            if ($row['type'] === 'version') {
                $number = (int) $row['version_no'];
                $what = sprintf('Version %d%s', $number, $number === $live ? ' (live)' : '');
                $note = (string) $row['note'];
            } else {
                $what = match ((string) $row['type']) {
                    'live' => sprintf('Live moved from %s to %s', $row['from_version'] === null ? 'none' : (string) $row['from_version'], (string) $row['to_version']),
                    'import' => 'Import',
                    'trash' => 'Trashed',
                    'restore' => 'Restored from the trash',
                    'install' => 'Installed by a consumer',
                    default => (string) $row['type'],
                };
                $note = (string) $row['reason'];
            }
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>', esc_html((string) $row['created_at']), esc_html($what), esc_html($who), esc_html($note), esc_html(substr((string) ($row['content_hash'] ?? ''), 0, 12)));
            if ($row['type'] === 'version') {
                $number = (int) $row['version_no'];
                if ($number !== $live && current_user_can(Capabilities::PUBLISH)) {
                    echo '<form method="post" class="promptlib-inline">';
                    wp_nonce_field('promptlib_set_live');
                    printf('<input type="hidden" name="promptlib_action" value="set_live"><input type="hidden" name="template" value="%d"><input type="hidden" name="version" value="%d">', $id, $number);
                    echo '<input name="reason" placeholder="Reason (optional)" size="18"> ';
                    if (isset($state['confirm']) && is_array($state['confirm']) && (int) ($state['confirm']['post']['version'] ?? 0) === $number) {
                        $this->confirmBox($state['confirm']['failing']);
                    }
                    submit_button(__('Make live', 'prompt-library'), 'secondary small', 'submit', false);
                    echo '</form> ';
                }
                echo '<form method="post" class="promptlib-inline">';
                wp_nonce_field('promptlib_restore_version');
                printf('<input type="hidden" name="promptlib_action" value="restore_version"><input type="hidden" name="template" value="%d"><input type="hidden" name="version" value="%d">', $id, $number);
                submit_button(__('Restore to draft', 'prompt-library'), 'secondary small', 'submit', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        $numbers = array_map(static fn (array $r): int => (int) $r['version_no'], $this->plugin->versions->versions($id));
        $a = isset($_GET['a']) ? sanitize_key(wp_unslash((string) $_GET['a'])) : '';
        $b = isset($_GET['b']) ? sanitize_key(wp_unslash((string) $_GET['b'])) : 'draft';
        echo '<h2>' . esc_html__('Compare', 'prompt-library') . '</h2><form method="get">';
        printf('<input type="hidden" name="post_type" value="%s"><input type="hidden" name="page" value="promptlib-history"><input type="hidden" name="template" value="%d">', esc_attr((string) $post->post_type), $id);
        foreach (['a' => $a, 'b' => $b] as $name => $current) {
            printf('<select name="%s">', esc_attr($name));
            printf('<option value="draft" %s>draft</option>', selected($current, 'draft', false));
            foreach ($numbers as $n) {
                printf('<option value="%d" %s>version %d</option>', $n, selected($current, (string) $n, false), $n);
            }
            echo '</select> ';
        }
        submit_button(__('Show diff', 'prompt-library'), 'secondary', '', false);
        echo '</form>';
        if ($a !== '') {
            $this->diff($id, $a, $b);
        }
        echo '</div>';
    }

    private function diff(int $id, string $a, string $b): void
    {
        $side = function (string $which) use ($id): array {
            if ($which === 'draft') {
                return $this->plugin->templates->draft($id);
            }
            $row = $this->plugin->versions->find($id, (int) $which);
            $decoded = $row !== null ? json_decode((string) $row['definition'], true) : null;
            return is_array($decoded) ? $decoded : [];
        };
        $diff = Diff::definitions($side($a), $side($b));
        foreach (['system', 'user'] as $part) {
            printf('<h3>%s</h3><pre class="promptlib-diff">', esc_html(ucfirst($part) . ' part'));
            foreach ($diff[$part] as $line) {
                $prefix = ['same' => '  ', 'add' => '+ ', 'remove' => '- '][$line['op']];
                printf('<span class="promptlib-%s">%s</span>' . "\n", esc_attr($line['op']), esc_html($prefix . $line['line']));
            }
            echo '</pre>';
        }
        echo '<h3>' . esc_html__('Variables', 'prompt-library') . '</h3>';
        if ($diff['variables'] === []) {
            echo '<p>' . esc_html__('No variable changes.', 'prompt-library') . '</p>';
        } else {
            echo '<table class="widefat"><thead><tr><th>Name</th><th>Change</th><th>Before</th><th>After</th></tr></thead><tbody>';
            foreach ($diff['variables'] as $change) {
                printf('<tr><td><code>%s</code></td><td>%s</td><td><pre>%s</pre></td><td><pre>%s</pre></td></tr>', esc_html($change['name']), esc_html($change['change']), esc_html($change['before'] === null ? '' : Text::json($change['before'], true)), esc_html($change['after'] === null ? '' : Text::json($change['after'], true)));
            }
            echo '</tbody></table>';
        }
        if ($diff['output_changed'] || $diff['settings_changed']) {
            echo '<p>' . esc_html(trim(($diff['output_changed'] ? 'The output declaration changed. ' : '') . ($diff['settings_changed'] ? 'The model settings changed.' : ''))) . '</p>';
        }
    }

    /** @return list<VariableSpec> */
    public function draftSpecs(int $id): array
    {
        $specs = [];
        foreach ((array) ($this->plugin->templates->draft($id)['variables'] ?? []) as $index => $raw) {
            [$spec] = VariableSpec::parse($raw, (int) $index);
            if ($spec !== null && $spec->name() !== '') {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /** @return list<VariableSpec> */
    private function specsFor(int $id, int|string $selection): array
    {
        if ($selection === 'draft') {
            return $this->draftSpecs($id);
        }
        try {
            return $this->plugin->library->load($id, $this->plugin->templates->slugOf($id), (int) $selection, $this->plugin->templates->live($id))->variables();
        } catch (PromptLibraryException $e) {
            return $this->draftSpecs($id);
        }
    }

    /**
     * Handle Preview or Run from the runner form.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handleRunner(string $action, int $id, array $post, int $userId): array
    {
        $version = self::selection($post['version'] ?? 'draft');
        $values = Forms::valuesFromPost($this->specsFor($id, $version), (array) ($post['values'] ?? []));
        $state = ['post' => $post, 'values' => $values];
        if ($action === 'preview') {
            try {
                $rendered = $this->plugin->runner->preview($id, $version, $values);
                $state['preview'] = $rendered->toArray();
            } catch (PromptLibraryException $e) {
                $state['error'] = $e->getMessage();
                $state['problems'] = $e->data();
            }
            return $state;
        }
        $selections = [['version' => $version, 'model' => (string) ($post['model'] ?? '')]];
        if (!empty($post['compare'])) {
            $selections[] = ['version' => self::selection($post['compare_version'] ?? 'draft'), 'model' => (string) ($post['compare_model'] ?? ($post['model'] ?? ''))];
        }
        try {
            $state['result'] = $this->plugin->runner->run($id, $selections, $values, $userId);
        } catch (RunRefused $e) {
            $state['error'] = $e->getMessage();
            $state['problems'] = $e->data();
        }

        return $state;
    }

    /** @param mixed $value */
    private static function selection($value): int|string
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : 'draft';
    }

    /** @param array<string, mixed> $state */
    public function runner(array $state): void
    {
        $id = isset($_GET['template']) ? absint($_GET['template']) : 0;
        if ($id === 0 && isset($state['runner']['post']['template'])) {
            $id = absint($state['runner']['post']['template']);
        }
        echo '<div class="wrap promptlib">';
        echo '<h1>' . esc_html__('Test runner', 'prompt-library') . '</h1>';
        $this->messages($state);
        if ($id === 0 || $this->plugin->templates->post($id) === null) {
            echo '<p>' . esc_html__('Choose a template:', 'prompt-library') . '</p><ul>';
            foreach ($this->plugin->templates->all() as $post) {
                printf('<li><a href="%s">%s</a> <code>%s</code></li>', esc_url(Admin::url('promptlib-runner', ['template' => $post->ID])), esc_html($post->post_title), esc_html($this->plugin->templates->slugOf($post->ID)));
            }
            echo '</ul></div>';
            return;
        }
        $runnerState = isset($state['runner']) && is_array($state['runner']) ? $state['runner'] : [];
        $posted = isset($runnerState['post']) && is_array($runnerState['post']) ? $runnerState['post'] : [];
        $settings = Settings::load();
        $disabled = $this->plugin->runner->disabledReason($settings);
        $version = self::selection($posted['version'] ?? ($_GET['version'] ?? 'draft'));
        $specs = $this->specsFor($id, $version);
        $fixtures = $this->plugin->templates->fixtures($id);
        $fixtureName = isset($_GET['fixture']) ? sanitize_text_field(wp_unslash((string) $_GET['fixture'])) : '';
        $values = isset($posted['values']) && is_array($posted['values']) ? $posted['values'] : ($fixtures[$fixtureName] ?? []);
        $numbers = array_map(static fn (array $r): int => (int) $r['version_no'], $this->plugin->versions->versions($id));

        printf('<p><a href="%s">%s</a></p>', esc_url(Admin::url('promptlib-edit', ['template' => $id])), esc_html(sprintf('Template: %s', $this->plugin->templates->slugOf($id))));
        if ($fixtures !== []) {
            echo '<p>' . esc_html__('Fixtures:', 'prompt-library') . ' ';
            foreach (array_keys($fixtures) as $name) {
                printf('<a class="button button-small" href="%s">%s</a> ', esc_url(Admin::url('promptlib-runner', ['template' => $id, 'fixture' => (string) $name])), esc_html((string) $name));
            }
            echo '</p>';
        }
        echo '<form method="post" class="promptlib-runner">';
        wp_nonce_field('promptlib_run');
        printf('<input type="hidden" name="template" value="%d">', $id);
        echo '<p><label>Version <select name="version"><option value="draft">draft</option>';
        foreach ($numbers as $n) {
            printf('<option value="%d" %s>version %d</option>', $n, selected($version, $n, false), $n);
        }
        echo '</select></label> <label>Model <select name="model">';
        foreach ($settings->models() as $model) {
            printf('<option %s>%s</option>', selected((string) ($posted['model'] ?? $settings->defaultModel()), $model, false), esc_html($model));
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="compare" value="1" ' . checked(!empty($posted['compare']), true, false) . '> Compare with</label> <select name="compare_version"><option value="draft">draft</option>';
        foreach ($numbers as $n) {
            printf('<option value="%d" %s>version %d</option>', $n, selected((string) ($posted['compare_version'] ?? ''), (string) $n, false), $n);
        }
        echo '</select> <select name="compare_model">';
        foreach ($settings->models() as $model) {
            printf('<option %s>%s</option>', selected((string) ($posted['compare_model'] ?? ''), $model, false), esc_html($model));
        }
        echo '</select> <span class="description">Compare makes two calls; both count against the caps.</span></p>';
        echo '<table class="form-table" role="presentation">';
        foreach ($specs as $spec) {
            $value = $values[$spec->name()] ?? '';
            if (is_array($value)) {
                $value = $spec->type() === 'list' ? implode("\n", array_map('strval', $value)) : Text::json($value, true);
            }
            $hint = sprintf('%s, escape %s%s%s', $spec->type(), $spec->escape(), $spec->maxLength() !== null ? ', at most ' . $spec->maxLength() . ' characters' : '', $spec->enum() !== [] ? ', one of: ' . implode(', ', $spec->enum()) : '');
            $default = $spec->hasDefault() ? ' Default: ' . (is_scalar($spec->default()) ? (string) $spec->default() : Text::json($spec->default())) : '';
            printf(
                '<tr><th><label for="promptlib-v-%1$s">%1$s%2$s</label></th><td><textarea id="promptlib-v-%1$s" name="values[%1$s]" rows="%3$d" class="large-text code promptlib-mono">%4$s</textarea><p class="description">%5$s</p></td></tr>',
                esc_attr($spec->name()),
                $spec->required() ? ' <span class="required">*</span>' : '',
                in_array($spec->type(), ['text', 'list', 'json'], true) ? 5 : 1,
                esc_textarea(is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : ''),
                esc_html(trim($hint . '. ' . $spec->description() . $default))
            );
        }
        echo '</table>';
        echo '<p>';
        echo '<button class="button" name="promptlib_action" value="preview">' . esc_html__('Preview (no model call)', 'prompt-library') . '</button> ';
        if (current_user_can(Capabilities::RUN)) {
            printf('<button class="button button-primary" name="promptlib_action" value="run" %s>%s</button> ', $disabled !== null ? 'disabled' : '', esc_html__('Run', 'prompt-library'));
        }
        if ($disabled !== null) {
            $link = current_user_can(Capabilities::MANAGE) ? sprintf(' <a href="%s">%s</a>', esc_url(Admin::url('promptlib-settings')), esc_html__('Settings', 'prompt-library')) : '';
            echo '<span class="description">' . esc_html($disabled['message']) . '</span>' . $link; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        }
        echo '</p></form>';
        echo '<form method="post" class="promptlib-inline">';
        wp_nonce_field('promptlib_save_fixture');
        printf('<input type="hidden" name="promptlib_action" value="save_fixture"><input type="hidden" name="template" value="%d">', $id);
        foreach ($values as $name => $value) {
            if (is_scalar($value)) {
                printf('<input type="hidden" name="values[%s]" value="%s">', esc_attr((string) $name), esc_attr((string) $value));
            }
        }
        echo '<input name="fixture_name" placeholder="Fixture name" size="20"> ';
        submit_button(__('Save values as fixture', 'prompt-library'), 'secondary small', 'submit', false);
        echo '</form>';

        if (isset($runnerState['error'])) {
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html((string) $runnerState['error']));
        }
        if (isset($runnerState['preview']) && is_array($runnerState['preview'])) {
            $this->renderedColumn($runnerState['preview'], null);
        }
        if (isset($runnerState['result']['runs']) && is_array($runnerState['result']['runs'])) {
            echo '<div class="promptlib-columns">';
            foreach ($runnerState['result']['runs'] as $run) {
                $this->renderedColumn($run, $run);
            }
            echo '</div>';
        }
        $this->runnerFooter($settings);
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $rendered
     * @param array<string, mixed>|null $run
     */
    private function renderedColumn(array $rendered, ?array $run): void
    {
        $system = (string) ($rendered['system'] ?? '');
        $user = (string) ($rendered['user'] ?? '');
        $chars = Text::length($system) + Text::length($user);
        echo '<div class="promptlib-result"><div class="promptlib-columns"><div>';
        printf('<h3>%s</h3>', esc_html(sprintf('Rendered prompt (%d characters, about %d tokens)', $chars, (int) ceil($chars / 4))));
        printf('<h4>System</h4><pre class="promptlib-text">%s</pre><h4>User</h4><pre class="promptlib-text">%s</pre>', esc_html($system), esc_html($user));
        echo '</div><div>';
        if ($run !== null) {
            echo '<h3>' . esc_html__('Answer', 'prompt-library') . '</h3>';
            if ($run['status'] === 'ok') {
                printf('<pre class="promptlib-text">%s</pre>', esc_html((string) $run['answer']));
            } else {
                printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html((string) $run['message']));
            }
            if (!empty($run['wrapper'])) {
                printf('<p class="description">%s</p>', esc_html((string) $run['wrapper']));
            }
            if (!empty($run['validation'])) {
                printf('<p>%s</p>', esc_html((string) $run['validation']));
            }
        }
        echo '</div></div>';
        if ($run !== null) {
            $cost = $run['cost'] === null ? 'unknown' : sprintf('%.6F (%s)', (float) $run['cost'], (string) $run['cost_source']);
            printf(
                '<p class="promptlib-meta">%s</p>',
                esc_html(sprintf(
                    'Model %s%s. Version %s. Content hash %s. Prompt tokens %d, completion tokens %d. Cost %s. %d ms. Attempts %d.%s Run %d.',
                    (string) $run['model'],
                    $run['answered_by'] !== null && $run['answered_by'] !== $run['model'] ? ' (answered by ' . (string) $run['answered_by'] . ')' : '',
                    (string) $run['version'],
                    substr((string) $run['content_hash'], 0, 12),
                    (int) $run['prompt_tokens'],
                    (int) $run['completion_tokens'],
                    $cost,
                    (int) $run['duration_ms'],
                    (int) $run['attempts'],
                    $run['guard'] !== [] ? ' Guard: ' . implode('; ', (array) $run['guard']) . '.' : '',
                    (int) $run['run_id']
                ))
            );
        } else {
            printf('<p class="promptlib-meta">%s</p>', esc_html(sprintf('Preview only, no model call. Content hash %s. Render hash %s.', substr((string) ($rendered['content_hash'] ?? ''), 0, 12), substr((string) ($rendered['render_hash'] ?? ''), 0, 12))));
        }
        echo '</div>';
    }

    private function runnerFooter(Settings $settings): void
    {
        $today = $this->plugin->counters->day($this->plugin->clock->siteDay());
        $refusals = [];
        foreach ($today as $metric => $count) {
            if (str_starts_with($metric, CounterRepository::REFUSED_PREFIX)) {
                $refusals[] = sprintf('%d %s', $count, str_replace('promptlib_', '', substr($metric, strlen(CounterRepository::REFUSED_PREFIX))));
            }
        }
        $costCap = $settings->dailyCost();
        printf(
            '<p class="promptlib-footer">%s</p>',
            esc_html(sprintf(
                'Today (site timezone): %d of %d runs. Known cost %s%s. Refusals today: %s. Spend meter: %s.',
                $today[CounterRepository::RUNS] ?? 0,
                $settings->dailyRuns(),
                sprintf('%.6F', ($today[CounterRepository::COST] ?? 0) / 1e9),
                $costCap > 0 ? sprintf(' of %.6F', $costCap) : ' (no cost cap)',
                $refusals === [] ? 'none' : implode(', ', $refusals),
                $this->plugin->meter->present() ? ($this->plugin->meter->bound() ? 'active' : 'present, unsupported version') : 'not installed'
            ))
        );
        $recent = $this->plugin->runs->recent(current_user_can(Capabilities::MANAGE) ? null : get_current_user_id(), 20);
        if ($recent === []) {
            return;
        }
        echo '<h2>' . esc_html__('Recent runs', 'prompt-library') . '</h2><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Template</th><th>Version</th><th>Model</th><th>Outcome</th><th>Tokens</th><th>Cost</th><th>ms</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                esc_html((string) $row['created_at']),
                esc_html((string) $row['slug']),
                esc_html((int) $row['version_no'] === 0 ? 'draft' : (string) $row['version_no']),
                esc_html((string) $row['model']),
                esc_html($row['status'] === 'ok' ? 'ok' : (string) $row['error_code']),
                esc_html(sprintf('%d / %d', (int) $row['prompt_tokens'], (int) $row['completion_tokens'])),
                esc_html($row['cost_nanos'] === null ? 'unknown' : sprintf('%.6F', (int) $row['cost_nanos'] / 1e9)),
                (int) $row['duration_ms']
            );
        }
        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $state */
    public function tools(array $state): void
    {
        echo '<div class="wrap promptlib"><h1>' . esc_html__('Import and export', 'prompt-library') . '</h1>';
        if (isset($state['import']) && is_array($state['import'])) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', $state['import']['ok'] ? 'success' : 'error', esc_html((string) $state['import']['message']));
        }
        $this->messages($state);
        echo '<h2>' . esc_html__('Export', 'prompt-library') . '</h2><ul>';
        foreach ($this->plugin->templates->all() as $post) {
            printf(
                '<li>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></li>',
                esc_html($this->plugin->templates->slugOf($post->ID)),
                esc_url(wp_nonce_url(add_query_arg(['action' => 'promptlib_export', 'template' => $post->ID], admin_url('admin-post.php')), 'promptlib_export')),
                esc_html__('versions', 'prompt-library'),
                esc_url(wp_nonce_url(add_query_arg(['action' => 'promptlib_export', 'template' => $post->ID, 'with_draft' => 1], admin_url('admin-post.php')), 'promptlib_export')),
                esc_html__('versions and draft', 'prompt-library')
            );
        }
        echo '</ul><h2>' . esc_html__('Import', 'prompt-library') . '</h2>';
        echo '<p>' . esc_html__('A new slug is created with the same version numbers, notes, hashes and live number. An existing slug only gets the imported live version in its draft; nothing is published.', 'prompt-library') . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('promptlib_import');
        echo '<input type="hidden" name="promptlib_action" value="import"><input type="file" name="import_file" accept="application/json,.json" required> ';
        submit_button(__('Import', 'prompt-library'), 'primary', 'submit', false);
        echo '</form></div>';
    }

    /**
     * Validate and store the settings form.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, errors: list<string>}
     */
    public function saveSettings(array $post): array
    {
        $input = (array) ($post['settings'] ?? []);
        $input['runner_enabled'] = !empty($input['runner_enabled']);
        $input['store_runs'] = !empty($input['store_runs']);
        $prices = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($input['price_table'] ?? '')) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', explode(',', $line));
            $prices[] = ['model' => $cells[0] ?? '', 'input' => $cells[1] ?? '', 'output' => $cells[2] ?? '', 'taken' => $cells[3] ?? ''];
        }
        $input['price_table'] = $prices;
        [$clean, $errors] = Settings::sanitise($input);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }
        update_option(Settings::OPTION, $clean, false);
        $key = isset($post['provider_key']) && is_string($post['provider_key']) ? trim($post['provider_key']) : '';
        if (!empty($post['clear_key'])) {
            delete_option(Settings::KEY_OPTION);
        } elseif ($key !== '') {
            update_option(Settings::KEY_OPTION, $key, false);
        }

        return ['ok' => true, 'errors' => []];
    }

    /** @param array<string, mixed> $state */
    public function settings(array $state): void
    {
        if (!current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You may not change these settings.', 'prompt-library'));
        }
        $settings = Settings::load();
        $s = $settings->all();
        echo '<div class="wrap promptlib"><h1>' . esc_html__('Prompt library settings', 'prompt-library') . '</h1>';
        $this->messages($state);
        if (isset($state['settings']) && is_array($state['settings'])) {
            if ($state['settings']['ok']) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'prompt-library') . '</p></div>';
                $settings = Settings::load();
                $s = $settings->all();
            } else {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('Not saved. Check these fields: ' . implode(', ', $state['settings']['errors']) . '.'));
            }
        }
        $keySource = defined('PROMPTLIB_PROVIDER_KEY') ? 'A key is set by the PROMPTLIB_PROVIDER_KEY constant.' : (Settings::hasProviderKey() ? 'A key is set.' : 'No key is set.');
        echo '<form method="post">';
        wp_nonce_field('promptlib_save_settings');
        echo '<input type="hidden" name="promptlib_action" value="save_settings"><table class="form-table" role="presentation">';
        printf('<tr><th>Test runner</th><td><label><input type="checkbox" name="settings[runner_enabled]" value="1" %s> On</label><p class="description">The kill switch. The PROMPTLIB_DISABLE_RUNNER constant wins.</p></td></tr>', checked((bool) $s['runner_enabled'], true, false));
        echo '<tr><th>Provider</th><td><select name="settings[provider_kind]">';
        foreach (['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'generic' => 'Generic chat completions endpoint'] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected((string) $s['provider_kind'], $value, false), esc_html($label));
        }
        printf('</select><p><label>Base URL (generic only, HTTPS unless localhost) <input name="settings[base_url]" class="regular-text code" value="%s"></label></p></td></tr>', esc_attr((string) $s['base_url']));
        printf('<tr><th>Provider key</th><td><input type="password" name="provider_key" class="regular-text" autocomplete="new-password" value=""> <label><input type="checkbox" name="clear_key" value="1"> Clear the stored key</label><p class="description">%s Write-only: the key is never shown.</p></td></tr>', esc_html($keySource));
        printf('<tr><th>Models offered</th><td><textarea name="settings[models]" rows="3" class="large-text code">%s</textarea><p class="description">One model id per line. Run is disabled while this is empty.</p></td></tr>', esc_textarea(implode("\n", $settings->models())));
        printf('<tr><th>Default model</th><td><input name="settings[default_model]" class="regular-text code" value="%s"><p class="description">Empty: the first in the list.</p></td></tr>', esc_attr((string) $s['default_model']));
        printf('<tr><th>Fallback models</th><td><textarea name="settings[fallback_models]" rows="2" class="large-text code">%s</textarea><p class="description">Tried in order when the chosen model fails with an error another model may not have. Each attempt is checked against the caps and the spend meter first.</p></td></tr>', esc_textarea(implode("\n", $settings->fallbackModels())));
        printf('<tr><th>Default temperature</th><td><input name="settings[default_temperature]" size="5" value="%s"> <span class="description">Empty: the provider default.</span></td></tr>', esc_attr($s['default_temperature'] === null ? '' : (string) $s['default_temperature']));
        printf('<tr><th>Output token cap</th><td><label>Default <input name="settings[default_token_cap]" size="7" value="%d"></label> <label>Site maximum <input name="settings[max_token_cap]" size="7" value="%d"></label></td></tr>', (int) $s['default_token_cap'], (int) $s['max_token_cap']);
        printf('<tr><th>Daily caps</th><td><label>Runs <input name="settings[daily_runs]" size="6" value="%d"></label> <label>Known cost <input name="settings[daily_cost]" size="8" value="%s"></label><p class="description">Per site, per day in the site timezone. A cost cap of 0 is off. Cost is in the provider currency as reported, or from the price table.</p></td></tr>', (int) $s['daily_runs'], esc_attr((string) $s['daily_cost']));
        $lines = array_map(static fn (array $r): string => sprintf('%s, %s, %s, %s', $r['model'], (string) $r['input'], (string) $r['output'], $r['taken']), $settings->priceTable());
        printf('<tr><th>Price table</th><td><textarea name="settings[price_table]" rows="3" class="large-text code" placeholder="model, input per million, output per million, YYYY-MM-DD">%s</textarea><p class="description">Optional. Prices are never guessed: a model not listed has an unknown cost unless the provider reports one.</p></td></tr>', esc_textarea(implode("\n", $lines)));
        printf('<tr><th>Run rows</th><td><label><input type="checkbox" name="settings[store_runs]" value="1" %s> Store prompt text and answers</label> <label>Keep for <input name="settings[run_retention_days]" size="4" value="%d"> days</label><p class="description">Run rows may hold personal data typed into values. They are visible to their author and to administrators, and covered by the personal data exporter and eraser. Untick to keep metadata only.</p></td></tr>', checked((bool) $s['store_runs'], true, false), (int) $s['run_retention_days']);
        printf('<tr><th>Per-user rate</th><td><input name="settings[rate_per_minute]" size="4" value="%d"> runs a minute</td></tr>', (int) $s['rate_per_minute']);
        echo '</table>';
        submit_button();
        echo '</form><h2>' . esc_html__('Roles', 'prompt-library') . '</h2><table class="widefat"><thead><tr><th>Role</th>';
        foreach (Capabilities::ALL as $cap) {
            printf('<th><code>%s</code></th>', esc_html($cap));
        }
        echo '</tr></thead><tbody>';
        foreach (wp_roles()->roles as $name => $role) {
            printf('<tr><td>%s</td>', esc_html(translate_user_role((string) $role['name'])));
            foreach (Capabilities::ALL as $cap) {
                printf('<td>%s</td>', !empty($role['capabilities'][$cap]) ? '&#10003;' : '');
            }
            echo '</tr>';
        }
        echo '</tbody></table><form method="post">';
        wp_nonce_field('promptlib_reset_roles');
        echo '<input type="hidden" name="promptlib_action" value="reset_roles">';
        submit_button(__('Reset roles to the default map', 'prompt-library'), 'secondary');
        echo '</form></div>';
    }

    /** @param array<string, mixed> $state */
    private function messages(array $state): void
    {
        $msg = isset($_GET['msg']) ? sanitize_key(wp_unslash((string) $_GET['msg'])) : '';
        if (isset(self::MESSAGES[$msg]) && !isset($state['issues']) && !isset($state['error'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(self::MESSAGES[$msg]));
        }
        if (isset($state['error'])) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html((string) $state['error']));
        }
        if (isset($state['issues']) && is_array($state['issues'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Not saved:', 'prompt-library') . '</p>' . Admin::issuesList($state['issues']) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html.
        }
    }
}
