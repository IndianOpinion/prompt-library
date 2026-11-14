<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Exception\ConfirmationRequiredException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\VersionRepository;

final class VersionsTest extends TestCase
{
    /** Acceptance test 31 (case 38). */
    public function testPublishStoresAuthorTimeNoteHash(): void
    {
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        $result = $this->plugin->publisher->publish($id, 'First wording.', true, true, self::EDITOR);
        self::assertSame(1, $result['version']);
        $row = $this->plugin->versions->find($id, 1);
        self::assertNotNull($row);
        self::assertSame(self::EDITOR, (int) $row['user_id']);
        self::assertSame('First wording.', $row['note']);
        self::assertSame(gmdate('Y-m-d H:i:s', 1763100000), $row['created_at']);
        self::assertSame($result['content_hash'], $row['content_hash']);
        self::assertSame(1, $this->plugin->templates->live($id));
        self::assertCount(1, $this->actions('promptlib_version_published'));
        self::assertSame([$id, 'summary', 1, self::EDITOR], $this->actions('promptlib_version_published')[0][1]);
    }

    public function testNoteIsRequiredOneLineAndShort(): void
    {
        $id = $this->plugin->drafts->create($this->definition(['note' => '']), self::ADMIN);
        foreach (['', "two\nlines", str_repeat('a', 501)] as $note) {
            try {
                $this->plugin->publisher->publish($id, $note, true, true, self::ADMIN);
                self::fail('Expected the note to be refused.');
            } catch (ValidationException $e) {
                self::assertSame('promptlib_note', $e->errorCode());
            }
        }
        self::assertSame(0, $this->plugin->versions->highestNumber($id));
    }

    /** Acceptance test 12 (case 11). */
    public function testEmptySystemPublishesEmptyUserDoesNot(): void
    {
        $id = $this->plugin->drafts->create($this->definition(['system' => '']), self::ADMIN);
        self::assertSame(1, $this->plugin->publisher->publish($id, 'Empty system.', true, true, self::ADMIN)['version']);
        $other = $this->plugin->drafts->create($this->definition(['slug' => 'other', 'user' => '', 'variables' => []]), self::ADMIN);
        $this->expectException(ValidationException::class);
        $this->plugin->publisher->publish($other, 'Empty user.', true, true, self::ADMIN);
    }

    /** Acceptance test 32 (case 39). */
    public function testUnchangedDraftIsRefused(): void
    {
        $id = $this->publishedTemplate();
        try {
            $this->plugin->publisher->publish($id, 'Again.', true, true, self::ADMIN);
            self::fail('Expected no-changes.');
        } catch (RequestException $e) {
            self::assertSame('promptlib_no_changes', $e->errorCode());
            self::assertSame('No changes since version 1.', $e->getMessage());
        }
        $this->plugin->drafts->save($id, ['title' => 'New title only'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $this->expectException(RequestException::class);
        $this->plugin->publisher->publish($id, 'Title only.', true, true, self::ADMIN);
    }

    /** Acceptance test 33 (case 40). */
    public function testConcurrentPublishGetsConsecutiveNumbers(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Mine.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        global $wpdb;
        $wpdb->beforeVersionInsert = function () use ($id): void {
            // Another editor publishes version 2 between our read and our insert.
            $this->plugin->versions->insertVersion($id, 'summary', 2, '{}', str_repeat('0', 64), 'Theirs.', self::EDITOR, '2026-11-14 00:00:00');
        };
        $result = $this->plugin->publisher->publish($id, 'Mine.', true, true, self::ADMIN);
        self::assertSame(3, $result['version']);
        $numbers = array_map(static fn (array $r): int => (int) $r['version_no'], $this->plugin->versions->versions($id));
        self::assertSame([3, 2, 1], $numbers);
    }

    public function testSecondConflictAsksToReload(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Mine.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        global $wpdb;
        $wpdb->beforeVersionInsert = function () use ($id, $wpdb): void {
            $this->plugin->versions->insertVersion($id, 'summary', 2, '{}', str_repeat('0', 64), 'A.', self::EDITOR, '2026-11-14 00:00:00');
            $wpdb->beforeVersionInsert = function () use ($id): void {
                $this->plugin->versions->insertVersion($id, 'summary', 3, '{}', str_repeat('0', 64), 'B.', self::EDITOR, '2026-11-14 00:00:00');
            };
        };
        $this->expectException(RequestException::class);
        $this->plugin->publisher->publish($id, 'Mine.', true, true, self::ADMIN);
    }

    /** Acceptance test 34 (cases 41 and 42). */
    public function testPublishWithoutLiveAndRollback(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Second.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $this->plugin->publisher->publish($id, 'Second.', false, false, self::ADMIN);
        self::assertSame(1, $this->plugin->templates->live($id));
        $this->plugin->publisher->setLive($id, 2, 'Try the new wording.', false, self::EDITOR);
        $this->plugin->publisher->setLive($id, 1, 'Rolled back: worse answers.', false, self::EDITOR);
        self::assertSame(1, $this->plugin->templates->live($id));
        self::assertSame(2, $this->plugin->versions->highestNumber($id));
        $events = array_values(array_filter($this->plugin->versions->history($id), static fn (array $r): bool => $r['type'] === 'live'));
        self::assertSame([2, 1, 'Rolled back: worse answers.', self::EDITOR], [(int) $events[0]['from_version'], (int) $events[0]['to_version'], $events[0]['reason'], (int) $events[0]['user_id']]);
        $changed = $this->actions('promptlib_live_changed');
        self::assertSame([$id, 'summary', 2, 1, self::EDITOR], end($changed)[1]);
    }

    /** Acceptance test 36 (case 44). */
    public function testNoCodePathUpdatesAVersion(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => strtolower($m->getName()), (new \ReflectionClass(VersionRepository::class))->getMethods());
        foreach ($methods as $method) {
            self::assertStringNotContainsString('update', $method);
        }
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/VersionRepository.php');
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\b/', $source);
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Rest/Controller.php');
        self::assertDoesNotMatchRegularExpression("/versions\/\(\?P<n>[^']*'[^\]]*'(PUT|PATCH|DELETE)'/", $routes);
        $cli = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Command.php');
        self::assertStringNotContainsString("case 'edit", $cli);
        self::assertContains('promptlib_versions.version_no', array_map(static fn (string $c): string => 'promptlib_versions.' . $c, Schema::expectedColumns()['promptlib_versions']));
    }

    /** Acceptance test 39 (cases 48, 49 and 50). */
    public function testDeclaredConsumersBlockLiveUntilConfirmed(): void
    {
        $id = $this->publishedTemplate();
        promptlib_register_consumer('summary', 'my-plugin', ['title', 'body']);
        $draft = $this->plugin->templates->draft($id);
        $draft['user'] .= ' {{ tone }}';
        $draft['variables'][] = ['name' => 'tone'];
        $this->plugin->drafts->save($id, $draft, null, self::ADMIN);
        try {
            $this->plugin->publisher->publish($id, 'Adds tone.', true, false, self::ADMIN);
            self::fail('Expected a confirmation request.');
        } catch (ConfirmationRequiredException $e) {
            self::assertSame(['my-plugin' => ['tone']], $e->failing());
        }
        self::assertSame(1, $this->plugin->versions->highestNumber($id));
        $this->plugin->publisher->publish($id, 'Adds tone.', false, false, self::ADMIN);
        self::assertFalse(promptlib_check('summary', ['title', 'body'], 2)->ok());
        self::assertSame(['tone'], promptlib_check('summary', ['title', 'body'], 2)->missingRequired());
        self::assertTrue(promptlib_check('summary', ['title', 'body'])->ok());
        try {
            $this->plugin->publisher->setLive($id, 2, '', false, self::ADMIN);
            self::fail('Expected a confirmation request.');
        } catch (ConfirmationRequiredException $e) {
            self::assertSame(['my-plugin'], array_keys($e->failing()));
        }
        $this->plugin->publisher->setLive($id, 2, 'Consumer updated next.', true, self::ADMIN);
        self::assertSame(2, $this->plugin->templates->live($id));
        $check = promptlib_check('summary', ['title', 'body', 'extra']);
        self::assertSame(['extra'], $check->unknown());
    }

    /** Acceptance test 40 (case 51). */
    public function testRegisteredDefaultInstallsThenOnlyOffers(): void
    {
        promptlib_register_default('my-plugin', $this->definition(['slug' => 'shipped']));
        self::assertTrue(promptlib_render('shipped', ['title' => 'a', 'body' => 'b'])->fromFallback());
        do_action('admin_init');
        $found = $this->plugin->templates->locate('shipped');
        self::assertNotNull($found);
        self::assertSame(1, $found['live']);
        $row = $this->plugin->versions->find($found['id'], 1);
        self::assertSame('Installed by my-plugin', $row['note']);
        self::assertSame('my-plugin', $this->plugin->templates->installer($found['id']));
        self::assertFalse(promptlib_render('shipped', ['title' => 'a', 'body' => 'b'])->fromFallback());

        $this->plugin->consumers->registerDefault('my-plugin', $this->definition(['slug' => 'shipped', 'system' => 'Better system text.']));
        do_action('admin_init');
        self::assertSame(1, $this->plugin->versions->highestNumber($found['id']));
        $offers = $this->plugin->templates->offers($found['id']);
        self::assertTrue($offers['my-plugin']['pending']);
        $this->plugin->defaults->loadOffer($found['id'], 'my-plugin', self::ADMIN);
        self::assertSame('Better system text.', $this->plugin->templates->draft($found['id'])['system']);
        self::assertFalse($this->plugin->templates->offers($found['id'])['my-plugin']['pending']);
        self::assertSame(1, $this->plugin->versions->highestNumber($found['id']));
    }

    /** Acceptance test 41 (case 52). */
    public function testSecondConsumerDefaultIsOnlyAnOffer(): void
    {
        promptlib_register_default('first', $this->definition(['slug' => 'shared']));
        do_action('admin_init');
        $logs = [];
        add_action('promptlib_log', static function (string $level, string $message) use (&$logs): void {
            $logs[] = $level . ': ' . $message;
        }, 10, 2);
        promptlib_register_default('second', $this->definition(['slug' => 'shared', 'system' => 'Second wording.']));
        do_action('admin_init');
        do_action('admin_init');
        $found = $this->plugin->templates->locate('shared');
        self::assertSame('first', $this->plugin->templates->installer($found['id']));
        self::assertSame(1, $this->plugin->versions->highestNumber($found['id']));
        self::assertTrue($this->plugin->templates->offers($found['id'])['second']['pending']);
        $warnings = array_filter($logs, static fn (string $l): bool => str_starts_with($l, 'warning: A consumer registered a default'));
        self::assertCount(1, $warnings);
    }
}
