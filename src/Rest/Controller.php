<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Rest;

use IndianOpinion\PromptLibrary\Exception\PromptLibraryException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Plugin;
use IndianOpinion\PromptLibrary\Runner\RunRefused;
use IndianOpinion\PromptLibrary\WordPress\Capabilities;

/**
 * REST namespace prompt-library/v1 (section 5.6). Every route has a
 * permission callback; cookie auth carries the core REST nonce.
 */
final class Controller
{
    public const NAMESPACE = 'prompt-library/v1';
    private const SLUG = '(?P<slug>[a-z0-9]+(?:[-_][a-z0-9]+)*)';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        $can = static fn (string $cap): \Closure => static fn (): bool => current_user_can($cap);
        register_rest_route(self::NAMESPACE, '/templates', [
            ['methods' => 'GET', 'callback' => [$this, 'listTemplates'], 'permission_callback' => $can(Capabilities::EDIT)],
        ]);
        register_rest_route(self::NAMESPACE, '/templates/' . self::SLUG, [
            ['methods' => 'GET', 'callback' => [$this, 'getTemplate'], 'permission_callback' => $can(Capabilities::EDIT)],
        ]);
        register_rest_route(self::NAMESPACE, '/templates/' . self::SLUG . '/versions/(?P<n>[1-9][0-9]*)', [
            ['methods' => 'GET', 'callback' => [$this, 'getVersion'], 'permission_callback' => $can(Capabilities::EDIT)],
        ]);
        register_rest_route(self::NAMESPACE, '/templates/' . self::SLUG . '/versions', [
            ['methods' => 'POST', 'callback' => [$this, 'publish'], 'permission_callback' => $can(Capabilities::PUBLISH)],
        ]);
        register_rest_route(self::NAMESPACE, '/templates/' . self::SLUG . '/live', [
            ['methods' => 'PUT', 'callback' => [$this, 'setLive'], 'permission_callback' => $can(Capabilities::PUBLISH)],
        ]);
        register_rest_route(self::NAMESPACE, '/templates/' . self::SLUG . '/preview', [
            ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $can(Capabilities::EDIT)],
        ]);
        register_rest_route(self::NAMESPACE, '/runs', [
            ['methods' => 'POST', 'callback' => [$this, 'run'], 'permission_callback' => $can(Capabilities::RUN)],
            ['methods' => 'GET', 'callback' => [$this, 'listRuns'], 'permission_callback' => $can(Capabilities::RUN)],
        ]);
    }

    public function listTemplates(\WP_REST_Request $request): \WP_REST_Response
    {
        $out = [];
        foreach ($this->plugin->templates->all() as $post) {
            $out[] = [
                'id' => $post->ID,
                'slug' => $this->plugin->templates->slugOf($post->ID),
                'title' => $post->post_title,
                'live' => $this->plugin->templates->live($post->ID),
            ];
        }

        return new \WP_REST_Response($out, 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function getTemplate(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId((string) $request['slug']);
            $versions = [];
            foreach ($this->plugin->versions->versions($id) as $row) {
                $versions[] = [
                    'version' => (int) $row['version_no'],
                    'note' => (string) $row['note'],
                    'author' => (int) $row['user_id'],
                    'published_at' => (string) $row['created_at'],
                    'content_hash' => (string) $row['content_hash'],
                ];
            }
            return new \WP_REST_Response([
                'id' => $id,
                'slug' => (string) $request['slug'],
                'live' => $this->plugin->templates->live($id),
                'draft' => $this->plugin->templates->draft($id),
                'versions' => $versions,
            ], 200);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function getVersion(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId((string) $request['slug']);
            $version = $this->plugin->library->load($id, (string) $request['slug'], (int) $request['n'], $this->plugin->templates->live($id));
            return new \WP_REST_Response([
                'version' => $version->version(),
                'live' => $version->isLive(),
                'note' => $version->note(),
                'published_at' => $version->publishedAt()->format(DATE_ATOM),
                'content_hash' => $version->contentHash(),
                'definition' => $version->definition()->toArray(),
            ], 200);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function publish(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId((string) $request['slug']);
            $result = $this->plugin->publisher->publish(
                $id,
                is_string($request['note']) ? $request['note'] : '',
                $request['make_live'] === null ? true : self::flag($request['make_live']),
                self::flag($request['confirm'] ?? false),
                get_current_user_id()
            );
            return new \WP_REST_Response($result, 201);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function setLive(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId((string) $request['slug']);
            $version = $request['version'];
            if (!is_numeric($version) || (int) $version < 1) {
                throw new RequestException('promptlib_invalid_request', 'Give the version number to make live.');
            }
            $this->plugin->publisher->setLive($id, (int) $version, is_string($request['reason']) ? $request['reason'] : '', self::flag($request['confirm'] ?? false), get_current_user_id());
            return new \WP_REST_Response(['live' => (int) $version], 200);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function preview(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId((string) $request['slug']);
            $values = is_array($request['values']) ? $request['values'] : [];
            $rendered = $this->plugin->runner->preview($id, self::selection($request['version']), $values);
            return new \WP_REST_Response($rendered->toArray(), 200);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function run(\WP_REST_Request $request)
    {
        return $this->guarded(function () use ($request): \WP_REST_Response {
            $id = $this->templateId(is_string($request['slug']) ? $request['slug'] : '');
            $values = is_array($request['values']) ? $request['values'] : [];
            $selections = [['version' => self::selection($request['version']), 'model' => (string) $request['model']]];
            $compare = $request['compare'];
            if (is_array($compare)) {
                $selections[] = ['version' => self::selection($compare['version'] ?? 'draft'), 'model' => (string) ($compare['model'] ?? $request['model'])];
            }
            return new \WP_REST_Response($this->plugin->runner->run($id, $selections, $values, get_current_user_id()), 200);
        });
    }

    public function listRuns(\WP_REST_Request $request): \WP_REST_Response
    {
        $all = current_user_can(Capabilities::MANAGE) && self::flag($request['all'] ?? false);

        return new \WP_REST_Response($this->plugin->runs->recent($all ? null : get_current_user_id(), 50), 200);
    }

    /** @param mixed $value */
    private static function flag($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /** @param mixed $value */
    private static function selection($value): int|string
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return 'draft';
    }

    private function templateId(string $slug): int
    {
        $found = $this->plugin->templates->locate($slug);
        if ($found === null || $found['status'] === 'trash') {
            throw new RequestException('promptlib_not_found', 'No such template.', 404);
        }

        return $found['id'];
    }

    /**
     * @param callable(): \WP_REST_Response $callback
     * @return \WP_REST_Response|\WP_Error
     */
    private function guarded(callable $callback)
    {
        try {
            return $callback();
        } catch (RunRefused $e) {
            return new \WP_Error($e->refusalCode(), $e->getMessage(), ['status' => $e->status()] + $e->data());
        } catch (RequestException $e) {
            return new \WP_Error($e->errorCode(), $e->getMessage(), ['status' => $e->status()] + $e->data());
        } catch (PromptLibraryException $e) {
            $status = in_array($e->errorCode(), ['promptlib_not_found', 'promptlib_version_not_found'], true) ? 404 : ($e->errorCode() === 'promptlib_confirmation_required' ? 409 : 422);
            return new \WP_Error($e->errorCode(), $e->getMessage(), ['status' => $status] + $e->data());
        }
    }
}
