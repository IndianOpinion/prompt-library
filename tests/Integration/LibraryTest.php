<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Exception\IntegrityException;
use IndianOpinion\PromptLibrary\Exception\InvalidVariableException;
use IndianOpinion\PromptLibrary\Exception\MissingVariableException;
use IndianOpinion\PromptLibrary\Exception\StorageException;
use IndianOpinion\PromptLibrary\Exception\TemplateNotFoundException;
use IndianOpinion\PromptLibrary\Exception\VersionNotFoundException;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\Library;

final class LibraryTest extends TestCase
{
    /** @var array<string, string> */
    private array $values = ['title' => 'A title', 'body' => 'Body text'];

    private function second(int $id, bool $live): void
    {
        $this->plugin->drafts->save($id, ['system' => 'Second system.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $this->plugin->publisher->publish($id, 'Second.', $live, true, self::ADMIN);
    }

    /** Acceptance test 30 (case 37). */
    public function testSelectors(): void
    {
        $id = $this->publishedTemplate();
        $this->second($id, false);
        $this->plugin->drafts->save($id, ['system' => 'Draft only.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        self::assertSame(1, promptlib_render('summary', $this->values)->version());
        self::assertSame(1, promptlib_render('summary', $this->values, 'live')->version());
        self::assertSame('Second system.', promptlib_render('summary', $this->values, 'latest')->system());
        self::assertSame('You write short summaries.', promptlib_render('summary', $this->values, 1)->system());
        self::assertSame(2, promptlib_get('summary', '2')->version());
        self::assertTrue(promptlib_get('summary')->isLive());
        self::assertFalse(promptlib_get('summary', 2)->isLive());
        foreach (['draft', 0, -1, 'next'] as $bad) {
            try {
                promptlib_render('summary', $this->values, $bad);
                self::fail('The draft or a bad selector must never render.');
            } catch (VersionNotFoundException $e) {
                self::assertSame(2, $e->highest());
            }
        }
    }

    /** Acceptance test 42 (case 53). */
    public function testNotFoundReasonsAndFallback(): void
    {
        $fallback = ['user' => 'Fallback {{ title }}', 'variables' => [['name' => 'title']]];
        $reasons = [];
        try {
            promptlib_render('nothing', []);
        } catch (TemplateNotFoundException $e) {
            $reasons[] = $e->reason();
        }
        $draftOnly = $this->plugin->drafts->create($this->definition(['slug' => 'draft-only']), self::ADMIN);
        try {
            promptlib_render('draft-only', $this->values);
        } catch (TemplateNotFoundException $e) {
            $reasons[] = $e->reason();
        }
        $id = $this->publishedTemplate();
        wp_trash_post($id);
        try {
            promptlib_render('summary', $this->values);
        } catch (TemplateNotFoundException $e) {
            $reasons[] = $e->reason();
            self::assertSame('promptlib_not_found', $e->code());
        }
        self::assertSame(['missing', 'unpublished', 'trashed'], $reasons);
        self::assertGreaterThan(0, $draftOnly);

        foreach (['nothing', 'draft-only', 'summary'] as $slug) {
            $rendered = promptlib_render($slug, ['title' => 'T'], 'live', ['fallback' => $fallback]);
            self::assertTrue($rendered->fromFallback());
            self::assertSame(0, $rendered->version());
            self::assertSame('Fallback T', $rendered->user());
        }
        $this->expectException(MissingVariableException::class);
        promptlib_render('nothing', [], 'live', ['fallback' => $fallback]);
    }

    public function testFallbackNeverCoversVariableErrors(): void
    {
        $this->publishedTemplate();
        $this->expectException(InvalidVariableException::class);
        promptlib_render('summary', ['title' => str_repeat('x', 2001), 'body' => 'b'], 'live', ['fallback' => ['user' => 'x']]);
    }

    /** Acceptance test 43 (case 54). */
    public function testMissingNumberNamesHighest(): void
    {
        $this->publishedTemplate();
        try {
            promptlib_get('summary', 7);
            self::fail('Expected version-not-found.');
        } catch (VersionNotFoundException $e) {
            self::assertSame(1, $e->highest());
            self::assertSame('promptlib_version_not_found', $e->code());
        }
    }

    /** Acceptance test 44 (case 55), and the filter's shape with the plugin active. */
    public function testSoftDependencyFilter(): void
    {
        $this->publishedTemplate();
        $out = apply_filters('promptlib_render', null, 'summary', $this->values, 'live', []);
        self::assertIsArray($out);
        self::assertSame(['system', 'user', 'slug', 'version', 'content_hash', 'render_hash', 'warnings', 'output', 'settings'], array_slice(array_keys($out), 0, 9));
        $error = apply_filters('promptlib_render', null, 'missing-slug', [], 'live', []);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('promptlib_not_found', $error->get_error_code());

        State::$hooks = [];
        self::assertNull(apply_filters('promptlib_render', null, 'summary', $this->values, 'live', []));
    }

    public function testExists(): void
    {
        self::assertFalse(promptlib_exists('summary'));
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        self::assertFalse(promptlib_exists('summary'));
        $this->plugin->publisher->publish($id, 'One.', true, true, self::ADMIN);
        self::assertTrue(promptlib_exists('summary'));
        wp_trash_post($id);
        self::assertFalse(promptlib_exists('summary'));
        self::assertSame('1.0', promptlib_api_version());
    }

    /** Acceptance test 71 (cases 87 and 89). */
    public function testStorageAndIntegrityErrorsThrow(): void
    {
        global $wpdb;
        $id = $this->publishedTemplate();
        $this->plugin->templates->forget('summary');
        $wpdb->failOnce['template.locate'] = 'Lost connection';
        try {
            promptlib_render('summary', $this->values);
            self::fail('Expected a storage exception.');
        } catch (StorageException $e) {
            self::assertSame('promptlib_storage', $e->code());
        }
        State::$cache = [];
        $table = $wpdb->prefix . 'promptlib_versions';
        foreach ($wpdb->rows[$table] as &$row) {
            if ($row['type'] === 'version') {
                $row['definition'] = str_replace('short summaries', 'tampered text', (string) $row['definition']);
            }
        }
        unset($row);
        try {
            promptlib_render('summary', $this->values);
            self::fail('Expected an integrity exception.');
        } catch (IntegrityException $e) {
            self::assertSame('promptlib_integrity', $e->code());
        }
        $errors = get_option(Library::INTEGRITY_OPTION);
        self::assertArrayHasKey('summary#1', $errors);
        foreach ($wpdb->rows[$table] as &$row) {
            if ($row['type'] === 'version') {
                $row['definition'] = '{not json';
            }
        }
        unset($row);
        $this->expectException(IntegrityException::class);
        promptlib_render('summary', $this->values);
        self::assertGreaterThan(0, $id);
    }

    /** Acceptance test 72 (case 88). */
    public function testPointerCacheIsInvalidatedOnMove(): void
    {
        $id = $this->publishedTemplate();
        $this->second($id, false);
        self::assertSame(1, promptlib_get('summary')->version());
        self::assertArrayHasKey('promptlib:t:summary', State::$cache);
        $this->plugin->publisher->setLive($id, 2, '', true, self::ADMIN);
        self::assertArrayNotHasKey('promptlib:t:summary', State::$cache);
        self::assertSame(2, promptlib_get('summary')->version());
    }

    /** Section 9: an uncached render costs at most two queries, and no write. */
    public function testUncachedRenderQueryBudget(): void
    {
        global $wpdb;
        $this->publishedTemplate();
        State::$cache = [];
        State::$transients['promptlib_seen_' . md5('100:1:')] = [1, 0];
        $wpdb->queries = [];
        promptlib_render('summary', $this->values);
        self::assertCount(2, $wpdb->queries);
        $wpdb->queries = [];
        promptlib_render('summary', $this->values);
        self::assertCount(0, $wpdb->queries);
    }

    /** Acceptance test 78 (case 99). */
    public function testLastRenderedAtMostOnceAnHour(): void
    {
        $id = $this->publishedTemplate();
        promptlib_render('summary', $this->values, 'live', ['consumer' => 'my-plugin']);
        $first = $this->plugin->templates->lastRendered($id)[1];
        self::assertSame(State::$now, $first['time']);
        self::assertArrayHasKey('my-plugin', $first['consumers']);
        State::$now += 600;
        promptlib_render('summary', $this->values, 'live', ['consumer' => 'my-plugin']);
        self::assertSame($first, $this->plugin->templates->lastRendered($id)[1]);
        State::$now += 3600;
        promptlib_render('summary', $this->values, 'live', ['consumer' => 'my-plugin']);
        self::assertSame(State::$now, $this->plugin->templates->lastRendered($id)[1]['time']);
    }
}
