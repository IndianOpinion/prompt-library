<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Rest\Controller;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\Settings;

final class RestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init');
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    private function call(string $route, string $method, array $params = [], ?int $user = null)
    {
        if ($user !== null) {
            wp_set_current_user($user);
        }
        foreach (State::$restRoutes as $pattern => $handlers) {
            $regex = '#^' . $pattern . '$#';
            if (preg_match($regex, Controller::NAMESPACE . $route, $m) !== 1) {
                continue;
            }
            foreach ($handlers as $handler) {
                if ($handler['methods'] !== $method) {
                    continue;
                }
                if (!($handler['permission_callback'])()) {
                    return 'forbidden';
                }
                $request = new \WP_REST_Request($method, $route);
                foreach (array_merge($params, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)) as $key => $value) {
                    $request[$key] = $value;
                }
                return ($handler['callback'])($request);
            }
        }
        self::fail('No route for ' . $method . ' ' . $route);
    }

    /** Acceptance test 79 (section 8). */
    public function testEveryRouteChecksItsCapability(): void
    {
        $this->publishedTemplate();
        $cases = [
            ['/templates', 'GET', self::AUTHOR],
            ['/templates/summary', 'GET', self::AUTHOR],
            ['/templates/summary/versions/1', 'GET', self::AUTHOR],
            ['/templates/summary/versions', 'POST', self::AUTHOR],
            ['/templates/summary/live', 'PUT', self::AUTHOR],
            ['/templates/summary/preview', 'POST', self::AUTHOR],
            ['/runs', 'POST', self::EDITOR],
            ['/runs', 'GET', self::EDITOR],
            ['/templates', 'GET', 0],
        ];
        foreach ($cases as [$route, $method, $user]) {
            self::assertSame('forbidden', $this->call($route, $method, [], $user), $method . ' ' . $route);
        }
        foreach (State::$restRoutes as $handlers) {
            foreach ($handlers as $handler) {
                self::assertArrayHasKey('permission_callback', $handler);
            }
        }
    }

    public function testRoutesWork(): void
    {
        $this->configureRunner();
        $this->publishedTemplate();
        update_option(Settings::KEY_OPTION, 'sk-provider-secret-value', false);
        $responses = [];
        $responses[] = $list = $this->call('/templates', 'GET', [], self::EDITOR);
        self::assertSame('summary', $list->get_data()[0]['slug']);
        $responses[] = $one = $this->call('/templates/summary', 'GET');
        self::assertSame(1, $one->get_data()['versions'][0]['version']);
        $responses[] = $this->call('/templates/summary/versions/1', 'GET');
        $preview = $this->call('/templates/summary/preview', 'POST', ['version' => 'draft', 'values' => ['title' => 't', 'body' => 'b']]);
        self::assertSame("Title: t\n\n<body>\nb\n</body>", $preview->get_data()['user']);
        $responses[] = $preview;
        $responses[] = $run = $this->call('/runs', 'POST', ['slug' => 'summary', 'version' => '1', 'model' => 'test/model', 'values' => ['title' => 't', 'body' => 'b']], self::ADMIN);
        self::assertSame('ok', $run->get_data()['runs'][0]['status']);
        $responses[] = $this->call('/runs', 'GET');
        $error = $this->call('/templates/summary/versions', 'POST', ['note' => 'Nothing changed.']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('promptlib_no_changes', $error->get_error_code());
        self::assertSame(409, $error->get_error_data()['status']);
        $live = $this->call('/templates/summary/live', 'PUT', ['version' => 5]);
        self::assertSame('promptlib_version_not_found', $live->get_error_code());
        $missing = $this->call('/templates/nothing', 'GET');
        self::assertSame(404, $missing->get_error_data()['status']);
        $refused = $this->call('/runs', 'POST', ['slug' => 'summary', 'version' => '1', 'model' => 'not-offered', 'values' => []]);
        self::assertSame('promptlib_invalid_request', $refused->get_error_code());
        foreach ($responses as $response) {
            self::assertStringNotContainsString('sk-provider-secret-value', (string) json_encode($response->get_data()));
        }
    }

    public function testPublishAndLiveThroughRest(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Two.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $created = $this->call('/templates/summary/versions', 'POST', ['note' => 'Two.', 'make_live' => false], self::EDITOR);
        self::assertSame(201, $created->get_status());
        self::assertSame(1, $this->plugin->templates->live($id));
        $this->call('/templates/summary/live', 'PUT', ['version' => '2', 'reason' => 'Ready.']);
        self::assertSame(2, $this->plugin->templates->live($id));
    }
}
