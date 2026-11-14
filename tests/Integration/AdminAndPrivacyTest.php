<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Integration;

use IndianOpinion\PromptLibrary\Admin\Forms;
use IndianOpinion\PromptLibrary\Runner\RunRefused;
use IndianOpinion\PromptLibrary\Tests\TestCase;
use IndianOpinion\PromptLibrary\VariableSpec;
use IndianOpinion\PromptLibrary\WordPress\Privacy;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class AdminAndPrivacyTest extends TestCase
{
    public function testEditorFormBuildsADraft(): void
    {
        $form = Forms::draftFromPost([
            'slug' => 'x', 'title' => 'T', 'system' => "S\\n", 'user' => '{{ a }}',
            'variables' => '[{"name": "a"}]', 'output_mode' => 'json', 'output_schema' => '{"type": "object"}',
            'temperature' => '0.5', 'token_cap' => '100', 'note' => 'N',
        ]);
        self::assertSame([], $form['issues']);
        self::assertSame("S\\n", $form['draft']['system']);
        self::assertSame([['name' => 'a']], $form['draft']['variables']);
        self::assertSame(['mode' => 'json', 'schema' => ['type' => 'object']], $form['draft']['output']);
        self::assertSame(['temperature' => 0.5, 'token_cap' => 100], $form['draft']['settings']);
        $bad = Forms::draftFromPost(['variables' => '[{"name": ', 'output_mode' => 'json', 'output_schema' => 'nope']);
        self::assertSame(['variables_json', 'schema_json'], array_map(static fn ($i): string => $i->code, $bad['issues']));
    }

    public function testRunnerFormValues(): void
    {
        $specs = [
            VariableSpec::fromArray(['name' => 'items', 'type' => 'list', 'required' => false]),
            VariableSpec::fromArray(['name' => 'data', 'type' => 'json', 'required' => false]),
            VariableSpec::fromArray(['name' => 'title']),
            VariableSpec::fromArray(['name' => 'empty', 'required' => false]),
        ];
        $values = Forms::valuesFromPost($specs, ['items' => "a\r\n\r\nb", 'data' => '{"k": [1]}', 'title' => 'T', 'empty' => '']);
        self::assertSame(['items' => ['a', 'b'], 'data' => ['k' => [1]], 'title' => 'T'], $values);
    }

    public function testPersonalDataExportAndErase(): void
    {
        $this->configureRunner();
        $id = $this->publishedTemplate();
        $this->plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 'Personal title', 'body' => 'b'], self::ADMIN);
        $privacy = new Privacy($this->plugin);
        $export = $privacy->export('admin@example.org');
        self::assertTrue($export['done']);
        self::assertCount(1, $export['data']);
        self::assertStringContainsString('Personal title', (string) json_encode($export['data']));
        self::assertSame([], $privacy->export('editor@example.org')['data']);
        self::assertTrue($privacy->erase('admin@example.org')['items_removed']);
        self::assertSame([], $this->plugin->runs->recent(null));
        self::assertArrayHasKey('prompt-library', $privacy->exporters([]));
        self::assertArrayHasKey('prompt-library', $privacy->erasers([]));
    }

    #[RunInSeparateProcess]
    public function testUnknownMeterContractFailsClosed(): void
    {
        define('SPEND_METER_API', '2');
        $this->configureRunner();
        $id = $this->publishedTemplate();
        try {
            $this->plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
            self::fail('Expected a refusal.');
        } catch (RunRefused $e) {
            self::assertSame(['promptlib_meter_refused', 'unsupported_api'], [$e->refusalCode(), $e->reason()]);
        }
        self::assertTrue($this->plugin->meter->present());
        self::assertFalse($this->plugin->meter->bound());
        self::assertSame([], $this->gateway->calls);
    }

    #[RunInSeparateProcess]
    public function testNoMeterMeansNoMeterTraffic(): void
    {
        $this->configureRunner();
        $id = $this->publishedTemplate();
        $seen = 0;
        add_filter('spend_meter_pre_spend', static function () use (&$seen) {
            $seen++;
            return ['allowed' => false, 'reason' => 'kill_switch'];
        }, 10, 2);
        $result = $this->plugin->runner->run($id, [['version' => 1, 'model' => 'test/model']], ['title' => 't', 'body' => 'b'], self::ADMIN);
        self::assertSame('ok', $result['runs'][0]['status']);
        self::assertSame(0, $seen);
        self::assertSame([], $this->actions('spend_meter_record'));
    }
}
