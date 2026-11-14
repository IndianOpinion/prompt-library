<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Cli\Command;
use IndianOpinion\PromptLibrary\Cli\Commands;
use IndianOpinion\PromptLibrary\Exception\RequestException;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\WordPress\Schema;
use IndianOpinion\PromptLibrary\WordPress\Settings;

final class ImportExportCliTest extends TestCase
{
    /** @return array<string, mixed> */
    private function exportWithTwoVersions(): array
    {
        $id = $this->publishedTemplate();
        $this->plugin->drafts->save($id, ['system' => 'Second.'] + $this->plugin->templates->draft($id), null, self::ADMIN);
        $this->plugin->publisher->publish($id, 'Second.', false, true, self::EDITOR);
        update_option(Settings::KEY_OPTION, 'secret-provider-key', false);
        $this->plugin->drafts->saveFixture($id, 'fixture', ['title' => 'x']);

        return $this->plugin->importExport->export($id, true);
    }

    private function emptySite(): void
    {
        $users = State::$users;
        State::reset();
        State::$users = $users;
        Schema::install();
        $this->plugin = new \IndianOpinion\PromptLibrary\Plugin($this->gateway, new \IndianOpinion\PromptLibrary\Tests\Support\FrozenClock());
        \IndianOpinion\PromptLibrary\Plugin::setInstance($this->plugin);
        $this->plugin->boot();
        do_action('init');
    }

    /** Acceptance test 62 (cases 76 and 77), and section 8: no key in exports. */
    public function testExportImportRoundTrip(): void
    {
        $doc = $this->exportWithTwoVersions();
        $json = (string) json_encode($doc);
        self::assertStringNotContainsString('secret-provider-key', $json);
        self::assertStringNotContainsString('fixture', $json);
        self::assertSame(1, $doc['live']);
        self::assertSame(['admin', 'editor'], array_column($doc['versions'], 'author'));

        $this->emptySite();
        $result = $this->plugin->importExport->import($doc, self::ADMIN);
        self::assertSame('created', $result['mode']);
        $id = $result['template_id'];
        self::assertSame(1, $this->plugin->templates->live($id));
        $rows = array_reverse($this->plugin->versions->versions($id));
        self::assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['version_no'], $rows));
        self::assertSame(array_column($doc['versions'], 'hash'), array_column($rows, 'content_hash'));
        self::assertSame(['First version.', 'Second.'], array_column($rows, 'note'));
        self::assertSame(self::EDITOR, (int) $rows[1]['user_id']);
        self::assertSame('Second.', promptlib_render('summary', ['title' => 't', 'body' => 'b'], 2)->system());
        self::assertSame([['promptlib_imported', ['summary', 'created']]], $this->actions('promptlib_imported'));
    }

    public function testTamperedHashIsRefused(): void
    {
        $doc = $this->exportWithTwoVersions();
        $doc['versions'][1]['definition']['system'] = 'Tampered.';
        $this->emptySite();
        try {
            $this->plugin->importExport->import($doc, self::ADMIN);
            self::fail('Expected a hash refusal.');
        } catch (RequestException $e) {
            self::assertSame('promptlib_import_hash', $e->errorCode());
        }
        self::assertNull($this->plugin->templates->locate('summary'));
    }

    /** Acceptance test 63 (case 78). */
    public function testImportOntoExistingSlugOnlyLoadsDraft(): void
    {
        $doc = $this->exportWithTwoVersions();
        $found = $this->plugin->templates->locate('summary');
        $doc['versions'][0]['definition']['system'] = 'Imported live text.';
        $doc['versions'][0]['hash'] = \IndianOpinion\PromptLibrary\Definition::fromArray($doc['versions'][0]['definition'])->contentHash();
        $result = $this->plugin->importExport->import($doc, self::ADMIN);
        self::assertSame('draft', $result['mode']);
        self::assertSame(2, $this->plugin->versions->highestNumber($found['id']));
        self::assertSame('Imported live text.', $this->plugin->templates->draft($found['id'])['system']);
        self::assertSame('You write short summaries.', promptlib_render('summary', ['title' => 't', 'body' => 'b'])->system());
    }

    /** Acceptance test 64 (case 79). */
    public function testOneInvalidVersionRefusesTheWholeFile(): void
    {
        $doc = $this->exportWithTwoVersions();
        $doc['versions'][1]['definition']['user'] = '{{ Broken }}';
        $this->emptySite();
        $this->expectException(ValidationException::class);
        try {
            $this->plugin->importExport->import($doc, self::ADMIN);
        } finally {
            self::assertNull($this->plugin->templates->locate('summary'));
        }
    }

    /** Acceptance test 65 (case 80). */
    public function testCliCommands(): void
    {
        $dir = sys_get_temp_dir() . '/promptlib-cli-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $out = [];
        $err = [];
        $cli = new Commands($this->plugin, static function (string $l) use (&$out): void {
            $out[] = $l;
        }, static function (string $l) use (&$err): void {
            $err[] = $l;
        });
        $this->publishedTemplate();
        file_put_contents($dir . '/values.json', json_encode(['title' => 'T', 'body' => 'B']));
        file_put_contents($dir . '/next.json', json_encode($this->definition(['system' => 'From a file.'])));

        self::assertSame(0, $cli->dispatch('list', [], []));
        self::assertStringStartsWith("summary\tlive=1", $out[0]);
        self::assertSame(0, $cli->dispatch('show', ['summary'], ['version' => '1']));
        self::assertSame(0, $cli->dispatch('render', ['summary'], ['values' => $dir . '/values.json']));
        self::assertStringContainsString("Title: T\n", implode("\n", $out));
        self::assertSame([], $this->gateway->calls);
        self::assertSame([], State::$httpCalls);
        self::assertSame(0, $cli->dispatch('publish', ['summary'], ['from' => $dir . '/next.json', 'note' => 'From CLI.', 'no-live' => true]));
        self::assertSame(1, promptlib_get('summary')->version());
        self::assertSame(0, $cli->dispatch('set-live', ['summary', '2'], ['reason' => 'CLI test']));
        self::assertSame(2, promptlib_get('summary')->version());
        self::assertSame(0, $cli->dispatch('export', ['summary'], ['dir' => $dir]));
        self::assertFileExists($dir . '/summary.json');
        self::assertSame(0, $cli->dispatch('import', [$dir . '/summary.json'], []));
        self::assertStringContainsString('loaded into the draft', end($out));

        self::assertSame(1, $cli->dispatch('show', ['missing'], []));
        self::assertStringContainsString('[promptlib_not_found]', end($err));
        self::assertSame(1, $cli->dispatch('render', ['summary'], ['values' => $dir . '/nope.json']));
        self::assertSame(1, $cli->dispatch('publish', ['summary'], ['from' => $dir . '/next.json', 'note' => '']));
        self::assertStringContainsString('[promptlib_note]', end($err));
        self::assertSame(1, $cli->dispatch('set-live', ['summary', '9'], []));
        self::assertSame(1, $cli->dispatch('export', ['summary'], ['dir' => $dir . '/missing-dir']));
        self::assertSame(1, $cli->dispatch('import', [], []));
        self::assertSame(1, $cli->dispatch('run', ['summary'], []));
        self::assertFalse(method_exists(Command::class, 'run'));
        $public = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(Command::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        self::assertSame(['__construct', 'list', 'show', 'render', 'export', 'import', 'publish', 'setLive'], $public);

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
