<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Exception\DraftConflictException;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\PostType;
use IndianOpinion\PromptLibrary\WordPress\TemplateRepository;

final class TemplatesTest extends TestCase
{
    /** Acceptance test 1 (case 1). */
    public function testPostTypeIsPrivate(): void
    {
        $args = State::$postTypes[PostType::NAME];
        self::assertFalse($args['public']);
        self::assertFalse($args['publicly_queryable']);
        self::assertFalse($args['show_in_rest']);
        self::assertTrue($args['exclude_from_search']);
        self::assertFalse($args['has_archive']);
        self::assertFalse($args['rewrite']);
        self::assertLessThanOrEqual(20, strlen(PostType::NAME));
        foreach (PostType::META_KEYS as $key) {
            self::assertFalse(State::$registeredMeta[PostType::NAME][$key]['show_in_rest']);
        }
        self::assertTrue(State::$registeredMeta[PostType::NAME][PostType::META_DRAFT]['revisions_enabled']);
    }

    /** Acceptance test 6 (case 6). */
    public function testNoBlockEditor(): void
    {
        self::assertFalse(apply_filters('use_block_editor_for_post_type', true, PostType::NAME));
        self::assertTrue(apply_filters('use_block_editor_for_post_type', true, 'post'));
        self::assertNotContains('editor', State::$postTypes[PostType::NAME]['supports']);
    }

    /** Acceptance test 2 (cases 2 and 76). */
    public function testDraftRoundTripsEveryField(): void
    {
        $definition = $this->definition([
            'output' => ['mode' => 'json', 'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
            'settings' => ['temperature' => 0.3, 'token_cap' => 500],
        ]);
        $id = $this->plugin->drafts->create($definition, self::ADMIN);
        $draft = $this->plugin->templates->draft($id);
        foreach ($definition as $key => $value) {
            self::assertSame($value, $draft[$key], $key);
        }
        self::assertSame('', State::$posts[$id]->post_content);
        self::assertSame('Summary', State::$posts[$id]->post_title);
    }

    /** Acceptance tests 7 and 8 (cases 7 and 22, lessons 1 to 3). */
    public function testPromptTextSurvivesKsesAndSlashing(): void
    {
        add_filter('content_save_pre', static fn (string $content): string => strip_tags(str_replace('"', '&quot;', $content)));
        wp_set_current_user(self::EDITOR);
        $text = "Say \"hi\" and 'bye' -- now...\n\n<tag>keep</tag> [shortcode attr=\"1\"]\n\nC:\\path\\to \\{{ literal }} \\\\n";
        $id = $this->plugin->drafts->create($this->definition(['system' => $text, 'user' => $text . '{{ title }}{{ body }}']), self::EDITOR);
        $this->plugin->drafts->save($id, ['system' => $text] + $this->plugin->templates->draft($id), null, self::EDITOR);
        self::assertSame($text, $this->plugin->templates->draft($id)['system']);
    }

    /** Acceptance test 3 (case 3): uppercase lowercased; invalid refused with a message. */
    public function testSlugIsLowercasedOrRefused(): void
    {
        $id = $this->plugin->drafts->create($this->definition(['slug' => 'A-b']), self::ADMIN);
        self::assertSame('a-b', $this->plugin->templates->slugOf($id));
        foreach (['a--b', '-a', 'a b', str_repeat('a', 101)] as $bad) {
            try {
                $this->plugin->drafts->create($this->definition(['slug' => $bad]), self::ADMIN);
                self::fail('Expected a refusal for ' . $bad);
            } catch (ValidationException $e) {
                self::assertSame('slug', $e->issues()[0]->code);
            }
        }
    }

    /** Acceptance test 4 (case 4). */
    public function testDuplicateSlugIsRefusedEvenAgainstTrash(): void
    {
        $first = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        try {
            $this->plugin->drafts->create($this->definition(['title' => 'Other']), self::ADMIN);
            self::fail('Expected slug_taken.');
        } catch (RequestException $e) {
            self::assertSame('promptlib_slug_taken', $e->errorCode());
            self::assertStringContainsString('Summary', $e->getMessage());
        }
        wp_trash_post($first);
        try {
            $this->plugin->drafts->create($this->definition(), self::ADMIN);
            self::fail('Expected slug_taken against the trash.');
        } catch (RequestException $e) {
            self::assertStringContainsString('in the trash', $e->getMessage());
        }
        foreach (State::$posts as $post) {
            self::assertStringNotContainsString('-2', $post->post_name);
        }
    }

    public function testWordPressNeverAppendsANumber(): void
    {
        $id = wp_insert_post(['post_type' => PostType::NAME, 'post_status' => 'publish', 'post_name' => 'same']);
        $other = wp_insert_post(['post_type' => PostType::NAME, 'post_status' => 'publish', 'post_name' => 'same']);
        self::assertSame('same', State::$posts[$id]->post_name);
        self::assertSame('same', State::$posts[$other]->post_name);
    }

    /** Acceptance test 5 (case 5). */
    public function testSlugLockedAfterVersionOneAndDuplicateWorks(): void
    {
        $id = $this->publishedTemplate();
        try {
            $this->plugin->drafts->save($id, ['slug' => 'renamed'] + $this->plugin->templates->draft($id), null, self::ADMIN);
            self::fail('Expected slug_locked.');
        } catch (RequestException $e) {
            self::assertSame('promptlib_slug_locked', $e->errorCode());
        }
        $copy = $this->plugin->drafts->duplicate($id, 'renamed', self::ADMIN);
        self::assertSame('renamed', $this->plugin->templates->slugOf($copy));
        self::assertSame('summary', $this->plugin->templates->slugOf($id));
        self::assertTrue($this->plugin->library->exists('summary'));
    }

    public function testSlugMayChangeBeforeVersionOne(): void
    {
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        $this->plugin->drafts->save($id, ['slug' => 'renamed'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        self::assertSame('renamed', $this->plugin->templates->slugOf($id));
        self::assertNull($this->plugin->templates->locate('summary'));
    }

    /** Acceptance test 9 (case 8). */
    public function testBrokenDraftSavesButDoesNotPublish(): void
    {
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        $result = $this->plugin->drafts->save($id, ['user' => "ok\n  {{ Bad }}"] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $error = $result['issues'][0];
        self::assertSame(['user', 2, 3], [$error->part, $error->line, $error->column]);
        $this->expectException(ValidationException::class);
        $this->plugin->publisher->publish($id, 'Try.', true, true, self::ADMIN);
    }

    /** Acceptance test 10 (case 9). */
    public function testLineEndingsNormalisedTrailingNewlineKept(): void
    {
        $id = $this->plugin->drafts->create($this->definition(['system' => "a\r\nb\rc\n", 'user' => "  x {{ title }}{{ body }}\r\n\r\n"]), self::ADMIN);
        $draft = $this->plugin->templates->draft($id);
        self::assertSame("a\nb\nc\n", $draft['system']);
        self::assertSame("  x {{ title }}{{ body }}\n\n", $draft['user']);
    }

    /** Acceptance test 11 (case 10). */
    public function testInvalidUtf8RefusedOnSave(): void
    {
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        try {
            $this->plugin->drafts->save($id, ['system' => "ok\xC3\x28"] + $this->plugin->templates->draft($id), null, self::ADMIN);
            self::fail('Expected a refusal.');
        } catch (ValidationException $e) {
            self::assertSame('utf8', $e->issues()[0]->code);
            self::assertStringContainsString('byte 2', $e->issues()[0]->message);
        }
    }

    /** Acceptance test 13 (case 12). */
    public function testOversizePartRefusedOnSave(): void
    {
        add_filter('promptlib_max_part_bytes', static fn (): int => 50);
        $this->expectException(ValidationException::class);
        $this->plugin->drafts->create($this->definition(['system' => str_repeat('a', 51)]), self::ADMIN);
    }

    /** Acceptance test 14 (case 13). */
    public function testStaleDraftHashRefusesAndReturnsBoth(): void
    {
        $id = $this->plugin->drafts->create($this->definition(), self::ADMIN);
        $hash = TemplateRepository::draftHash($this->plugin->templates->draft($id));
        $this->plugin->drafts->save($id, ['system' => 'Changed by editor one.'] + $this->plugin->templates->draft($id), $hash, self::ADMIN);
        try {
            $this->plugin->drafts->save($id, ['system' => 'Changed by editor two.'] + $this->plugin->templates->draft($id), $hash, self::EDITOR);
            self::fail('Expected a conflict.');
        } catch (DraftConflictException $e) {
            self::assertSame('Changed by editor one.', $e->stored()['system']);
            self::assertSame('Changed by editor two.', $e->submitted()['system']);
        }
        self::assertSame('Changed by editor one.', $this->plugin->templates->draft($id)['system']);
    }

    /** Acceptance test 37 (cases 45 and 46). */
    public function testRevisionsCarryDraftAndNeverTouchVersions(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Revised.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $revisions = array_filter(State::$posts, static fn ($p): bool => $p->post_type === 'revision' && $p->post_parent === $id);
        self::assertNotEmpty($revisions);
        $last = end($revisions);
        self::assertSame('Revised.', State::$meta[$last->ID][PostType::META_DRAFT]['system']);

        add_filter('wp_revisions_to_keep', static fn (): int => 0);
        $this->plugin->drafts->save($id, ['system' => 'No revision.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        self::assertSame('You write short summaries.', promptlib_get('summary')->systemTemplate());

        $this->plugin->drafts->autosave($id, ['system' => 'Autosaved text.'] + $this->plugin->templates->draft($id));
        self::assertSame('You write short summaries.', promptlib_render('summary', ['title' => 't', 'body' => 'b'])->system());
        self::assertSame('No revision.', $this->plugin->templates->draft($id)['system']);
    }

    /** Acceptance test 35 (case 43). */
    public function testRestoreToDraftOnlyCopies(): void
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Second.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $this->plugin->publisher->publish($id, 'Second.', true, true, self::ADMIN);
        $this->plugin->drafts->restoreVersion($id, 1);
        self::assertSame('You write short summaries.', $this->plugin->templates->draft($id)['system']);
        self::assertSame(2, $this->plugin->templates->live($id));
        self::assertSame(2, $this->plugin->versions->highestNumber($id));
    }

    /** Acceptance test 49 (case 61). */
    public function testFixtures(): void
    {
        $id = $this->publishedTemplate();
        $hash = promptlib_get('summary')->contentHash();
        $this->plugin->drafts->saveFixture($id, 'Short article', ['title' => 'A title', 'body' => 'Some text', 'stray' => 'x']);
        self::assertSame(['Short article' => ['title' => 'A title', 'body' => 'Some text']], $this->plugin->templates->fixtures($id));
        self::assertSame($hash, promptlib_get('summary')->contentHash());
        try {
            $this->plugin->drafts->saveFixture($id, 'Bad', ['title' => ['not', 'text']]);
            self::fail('Expected an invalid fixture.');
        } catch (ValidationException $e) {
            self::assertSame('promptlib_fixture_invalid', $e->errorCode());
        }
        add_filter('promptlib_fixture_limit', static fn (): int => 1);
        try {
            $this->plugin->drafts->saveFixture($id, 'Second', ['title' => 'x']);
            self::fail('Expected the fixture limit.');
        } catch (RequestException $e) {
            self::assertSame('promptlib_fixture_limit', $e->errorCode());
        }
        $this->plugin->drafts->saveFixture($id, 'Short article', ['title' => 'Replaced']);
        $this->plugin->drafts->deleteFixture($id, 'Short article');
        self::assertSame([], $this->plugin->templates->fixtures($id));
    }

    public function testDefaultFixtureLimitIsFifty(): void
    {
        $id = $this->publishedTemplate();
        for ($i = 0; $i < 50; $i++) {
            $this->plugin->drafts->saveFixture($id, 'f' . $i, ['title' => 't']);
        }
        $this->expectException(RequestException::class);
        $this->plugin->drafts->saveFixture($id, 'one more', ['title' => 't']);
    }
}
