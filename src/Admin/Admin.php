<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Admin;

use IndianOpinion\PromptLibrary\Exception\ConfirmationRequiredException;
use IndianOpinion\PromptLibrary\Exception\DraftConflictException;
use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Text;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;
use IndianOpinion\PromptLibrary\WordPress\PostType;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;

/**
 * Admin menus, routing, notices, list columns and form handlers. The
 * screens themselves live in Screens. Every handler checks a nonce and a
 * capability; every value printed is escaped for its context.
 */
final class Admin
{
    public const PARENT = 'edit.php?post_type=' . PostType::NAME;

    /** @var array<string, mixed> state handed from a load-* handler to the screen */
    private array $state = [];

    private Screens $screens;

    public function __construct(private readonly Plugin $plugin)
    {
        $this->screens = new Screens($plugin);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menus']);
        add_action('load-post.php', [$this, 'redirectCoreEditor']);
        add_action('load-post-new.php', [$this, 'redirectCoreEditor']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('manage_' . PostType::NAME . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . PostType::NAME . '_posts_custom_column', [$this, 'column'], 10, 2);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_action('wp_ajax_promptlib_autosave', [$this, 'ajaxAutosave']);
        add_action('admin_post_promptlib_export', [$this, 'downloadExport']);
    }

    public function menus(): void
    {
        $edit = add_submenu_page(self::PARENT, __('Edit template', 'prompt-library'), __('Add template', 'prompt-library'), Capabilities::EDIT, 'promptlib-edit', [$this, 'renderEditor']);
        $history = add_submenu_page('', __('Template history', 'prompt-library'), '', Capabilities::EDIT, 'promptlib-history', [$this, 'renderHistory']);
        $runner = add_submenu_page(self::PARENT, __('Test runner', 'prompt-library'), __('Test runner', 'prompt-library'), Capabilities::EDIT, 'promptlib-runner', [$this, 'renderRunner']);
        $tools = add_submenu_page(self::PARENT, __('Import and export', 'prompt-library'), __('Import and export', 'prompt-library'), Capabilities::PUBLISH, 'promptlib-tools', [$this, 'renderTools']);
        $settings = add_submenu_page(self::PARENT, __('Prompt library settings', 'prompt-library'), __('Settings', 'prompt-library'), Capabilities::MANAGE, 'promptlib-settings', [$this, 'renderSettings']);
        remove_submenu_page(self::PARENT, 'post-new.php?post_type=' . PostType::NAME);
        foreach (['edit' => $edit, 'history' => $history, 'runner' => $runner, 'tools' => $tools, 'settings' => $settings] as $screen => $hook) {
            if (is_string($hook)) {
                add_action('load-' . $hook, function () use ($screen): void {
                    $this->handle($screen);
                });
            }
        }
    }

    /** The core editor is never used for templates (case 6). */
    public function redirectCoreEditor(): void
    {
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash((string) $_GET['post_type'])) : '';
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($id > 0) {
            $type = (string) get_post_type($id);
        }
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        // Only the edit screen is replaced; trash, untrash and delete links keep working.
        if ($type !== PostType::NAME || ($id > 0 && !in_array($action, ['', 'edit'], true))) {
            return;
        }
        wp_safe_redirect(self::url('promptlib-edit', $id > 0 ? ['template' => $id] : []));
        exit;
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'promptlib') && get_current_screen()?->post_type !== PostType::NAME) {
            return;
        }
        wp_enqueue_style('promptlib-admin', plugins_url('assets/admin.css', PROMPTLIB_FILE), [], Plugin::VERSION);
        wp_enqueue_script('promptlib-admin', plugins_url('assets/admin.js', PROMPTLIB_FILE), [], Plugin::VERSION, true);
        wp_localize_script('promptlib-admin', 'promptlibAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'autosaveNonce' => wp_create_nonce('promptlib_autosave'),
        ]);
    }

    public function notices(): void
    {
        if (!current_user_can(Capabilities::EDIT)) {
            return;
        }
        if (Schema::isDegraded()) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sprintf(
                'Prompt library: these columns are missing, so the test runner is off: %s. Deactivate and activate the plugin to retry, and see Site Health.',
                implode(', ', Schema::storedMissing())
            )));
        }
        if (!$this->plugin->runner->gateway()->available()) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html('Prompt library: the llm-json-php library is not installed, so the test runner is off. Run composer install in the plugin folder. Rendering for other plugins keeps working.'));
        }
        if (Settings::load()->isCorrupt() && current_user_can(Capabilities::MANAGE)) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html('Prompt library: the stored settings could not be read. Safe defaults are in use in memory (runner on, 50 runs a day, cost cap off). Nothing has been written; save the settings to fix this.'));
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['promptlib_slug'] = __('Slug', 'prompt-library');
                $out['promptlib_live'] = __('Live version', 'prompt-library');
                $out['promptlib_rendered'] = __('Last rendered', 'prompt-library');
            }
        }

        return $out;
    }

    public function column(string $column, int $postId): void
    {
        $templates = $this->plugin->templates;
        if ($column === 'promptlib_slug') {
            echo '<code>' . esc_html($templates->slugOf($postId)) . '</code>';
        } elseif ($column === 'promptlib_live') {
            $live = $templates->live($postId);
            echo esc_html($live > 0 ? (string) $live : __('none (draft only)', 'prompt-library'));
        } elseif ($column === 'promptlib_rendered') {
            $latest = 0;
            $consumers = [];
            foreach ($templates->lastRendered($postId) as $entry) {
                $latest = max($latest, (int) ($entry['time'] ?? 0));
                $consumers = array_merge($consumers, array_keys($entry['consumers'] ?? []));
            }
            echo esc_html($latest > 0 ? sprintf('%s (%s)', wp_date('Y-m-d H:i', $latest), implode(', ', array_unique($consumers))) : __('never', 'prompt-library'));
        }
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== PostType::NAME) {
            return $actions;
        }
        unset($actions['inline hide-if-no-js'], $actions['view']);
        $actions['edit'] = sprintf('<a href="%s">%s</a>', esc_url(self::url('promptlib-edit', ['template' => $post->ID])), esc_html__('Edit', 'prompt-library'));
        $actions['promptlib_history'] = sprintf('<a href="%s">%s</a>', esc_url(self::url('promptlib-history', ['template' => $post->ID])), esc_html__('History', 'prompt-library'));
        $actions['promptlib_run'] = sprintf('<a href="%s">%s</a>', esc_url(self::url('promptlib-runner', ['template' => $post->ID])), esc_html__('Test', 'prompt-library'));
        if (isset($actions['trash'])) {
            $seen = $this->plugin->lifecycle->recentConsumers($post->ID);
            if ($seen !== []) {
                $message = sprintf('This template was rendered in the last 7 days by: %s. Trashed templates throw not-found for these consumers. Trash it anyway?', implode(', ', $seen));
                $actions['trash'] = str_replace('<a ', '<a data-promptlib-confirm="' . esc_attr($message) . '" ', $actions['trash']);
            }
        }

        return $actions;
    }

    public function ajaxAutosave(): void
    {
        check_ajax_referer('promptlib_autosave', 'nonce');
        $id = isset($_POST['template']) ? absint($_POST['template']) : 0;
        if ($id === 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        $form = Forms::draftFromPost(self::posted());
        try {
            $this->plugin->drafts->autosave($id, $form['draft']);
        } catch (PromptLibraryException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
        wp_send_json_success(['saved' => gmdate('H:i:s')]);
    }

    public function downloadExport(): void
    {
        check_admin_referer('promptlib_export');
        if (!current_user_can(Capabilities::PUBLISH)) {
            wp_die(esc_html__('You may not export templates.', 'prompt-library'), '', ['response' => 403]);
        }
        $id = isset($_GET['template']) ? absint($_GET['template']) : 0;
        try {
            $doc = $this->plugin->importExport->export($id, !empty($_GET['with_draft']));
        } catch (PromptLibraryException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 404]);
        }
        $slug = (string) ($doc['slug'] ?? 'template');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($slug) . '.json"');
        echo Text::json($doc, true); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download, not HTML.
        exit;
    }

    /** Process a POST for a screen before any output (post, redirect, get). */
    private function handle(string $screen): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['promptlib_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash((string) $_POST['promptlib_action']));
        // Preview and Run share one form, so they share one nonce.
        check_admin_referer('promptlib_' . ($action === 'preview' ? 'run' : $action));
        $post = self::posted();
        $id = isset($post['template']) ? absint($post['template']) : 0;
        $user = get_current_user_id();
        try {
            switch ($action) {
                case 'save_draft':
                    $this->requireCap($id > 0 ? 'edit_post' : Capabilities::EDIT, $id);
                    $form = Forms::draftFromPost($post);
                    if ($form['issues'] !== []) {
                        throw new ValidationException(array_values($form['issues']), 'promptlib_unsavable');
                    }
                    if ($id === 0) {
                        $id = $this->plugin->drafts->create($form['draft'], $user);
                        $this->redirect('promptlib-edit', ['template' => $id, 'msg' => 'created']);
                    }
                    $hash = isset($post['draft_hash']) && is_string($post['draft_hash']) && $post['draft_hash'] !== '' ? $post['draft_hash'] : null;
                    $this->plugin->drafts->save($id, $form['draft'], $hash, $user);
                    $this->redirect('promptlib-edit', ['template' => $id, 'msg' => 'saved']);
                    // no break: redirect exits.
                case 'publish':
                    $this->requireCap(Capabilities::PUBLISH);
                    $result = $this->plugin->publisher->publish($id, (string) ($post['version_note'] ?? ''), !empty($post['make_live']), !empty($post['confirm']), $user);
                    $this->redirect('promptlib-edit', ['template' => $id, 'msg' => 'published', 'v' => $result['version']]);
                    // no break
                case 'set_live':
                    $this->requireCap(Capabilities::PUBLISH);
                    $this->plugin->publisher->setLive($id, absint($post['version'] ?? 0), (string) ($post['reason'] ?? ''), !empty($post['confirm']), $user);
                    $this->redirect('promptlib-history', ['template' => $id, 'msg' => 'live']);
                    // no break
                case 'restore_version':
                    $this->requireCap('edit_post', $id);
                    $this->plugin->drafts->restoreVersion($id, absint($post['version'] ?? 0));
                    $this->redirect('promptlib-edit', ['template' => $id, 'msg' => 'restored']);
                    // no break
                case 'duplicate':
                    $this->requireCap(Capabilities::EDIT);
                    $new = $this->plugin->drafts->duplicate($id, (string) ($post['new_slug'] ?? ''), $user);
                    $this->redirect('promptlib-edit', ['template' => $new, 'msg' => 'created']);
                    // no break
                case 'load_offer':
                    $this->requireCap('edit_post', $id);
                    $this->plugin->defaults->loadOffer($id, (string) ($post['consumer'] ?? ''), $user);
                    $this->redirect('promptlib-edit', ['template' => $id, 'msg' => 'offer']);
                    // no break
                case 'save_fixture':
                    $this->requireCap('edit_post', $id);
                    $specs = $this->screens->draftSpecs($id);
                    $this->plugin->drafts->saveFixture($id, (string) ($post['fixture_name'] ?? ''), Forms::valuesFromPost($specs, (array) ($post['values'] ?? [])));
                    $this->redirect('promptlib-runner', ['template' => $id, 'msg' => 'fixture']);
                    // no break
                case 'delete_fixture':
                    $this->requireCap('edit_post', $id);
                    $this->plugin->drafts->deleteFixture($id, (string) ($post['fixture_name'] ?? ''));
                    $this->redirect('promptlib-runner', ['template' => $id, 'msg' => 'fixture_deleted']);
                    // no break
                case 'preview':
                case 'run':
                    $this->requireCap($action === 'run' ? Capabilities::RUN : 'edit_post', $id);
                    $this->state['runner'] = $this->screens->handleRunner($action, $id, $post, $user);
                    return;
                case 'import':
                    $this->requireCap(Capabilities::PUBLISH);
                    $this->state['import'] = $this->import();
                    return;
                case 'save_settings':
                    $this->requireCap(Capabilities::MANAGE);
                    $this->state['settings'] = $this->screens->saveSettings($post);
                    return;
                case 'reset_roles':
                    $this->requireCap(Capabilities::MANAGE);
                    Capabilities::reset();
                    $this->redirect('promptlib-settings', ['msg' => 'roles']);
            }
            throw new RequestException('promptlib_invalid_request', 'Unknown action.');
        } catch (ConfirmationRequiredException $e) {
            $this->state['confirm'] = ['action' => $action, 'failing' => $e->failing(), 'post' => $post];
        } catch (DraftConflictException $e) {
            $this->state['conflict'] = ['stored' => $e->stored(), 'submitted' => $e->submitted()];
            $this->state['form'] = $post;
        } catch (ValidationException $e) {
            $this->state['issues'] = $e->issues();
            $this->state['form'] = $post;
        } catch (PromptLibraryException $e) {
            $this->state['error'] = $e->getMessage();
            $this->state['form'] = $post;
        }
    }

    /** @return array{ok: bool, message: string} */
    private function import(): array
    {
        if (!isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['import_file']['tmp_name'])) {
            return ['ok' => false, 'message' => 'Choose an export file to import.'];
        }
        $json = file_get_contents((string) $_FILES['import_file']['tmp_name']);
        $doc = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($doc)) {
            return ['ok' => false, 'message' => 'The file is not valid JSON.'];
        }
        $result = $this->plugin->importExport->import($doc, get_current_user_id());

        return ['ok' => true, 'message' => $result['mode'] === 'created'
            ? sprintf('Created "%s" with its versions and live number.', $result['slug'])
            : sprintf('"%s" already exists: the imported live version is now in its draft. Review it and publish.', $result['slug'])];
    }

    private function requireCap(string $cap, int $id = 0): void
    {
        $ok = $id > 0 ? current_user_can($cap, $id) : current_user_can($cap);
        if (!$ok) {
            wp_die(esc_html__('You are not allowed to do that.', 'prompt-library'), '', ['response' => 403]);
        }
    }

    /** @param array<string, scalar> $args */
    private function redirect(string $page, array $args): void
    {
        wp_safe_redirect(self::url($page, $args));
        exit;
    }

    /** @param array<string, scalar> $args */
    public static function url(string $page, array $args = []): string
    {
        return add_query_arg(array_merge(['post_type' => PostType::NAME, 'page' => $page], $args), admin_url('edit.php'));
    }

    /** @return array<string, mixed> */
    private static function posted(): array
    {
        $post = wp_unslash($_POST);

        return is_array($post) ? $post : [];
    }

    public function renderEditor(): void
    {
        $this->screens->editor($this->state);
    }

    public function renderHistory(): void
    {
        $this->screens->history($this->state);
    }

    public function renderRunner(): void
    {
        $this->screens->runner($this->state);
    }

    public function renderTools(): void
    {
        $this->screens->tools($this->state);
    }

    public function renderSettings(): void
    {
        $this->screens->settings($this->state);
    }

    /** @param list<Issue> $issues */
    public static function issuesList(array $issues): string
    {
        if ($issues === []) {
            return '';
        }
        $html = '<ul class="promptlib-issues">';
        foreach ($issues as $issue) {
            $html .= sprintf('<li class="promptlib-%s">%s</li>', esc_attr($issue->severity), esc_html($issue->describe()));
        }

        return $html . '</ul>';
    }
}
