<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Unit;

use IndianOpinion\PromptLibrary\Definition;
use IndianOpinion\PromptLibrary\Diff;
use IndianOpinion\PromptLibrary\Exception\ValidationException;
use IndianOpinion\PromptLibrary\Issue;
use IndianOpinion\PromptLibrary\Slug;
use IndianOpinion\PromptLibrary\VariableSpec;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    /** @param array<string, mixed> $raw */
    private function codes(array $raw): array
    {
        return array_map(static fn (Issue $i): string => $i->code, Definition::validate($raw + ['slug' => 'ok', 'user' => 'x']));
    }

    /** Acceptance test 3 (case 3). */
    public function testSlugRules(): void
    {
        foreach (['a', 'a-b', 'a_b', 'abc123', 'a-b_c', str_repeat('a', 100)] as $ok) {
            self::assertNull(Slug::problem($ok), $ok);
        }
        self::assertSame('a-b', Slug::normalise('A-b'));
        foreach (['a--b', '-a', 'a-', 'a b', 'a.b', '', str_repeat('a', 101), 'é'] as $bad) {
            self::assertNotNull(Slug::problem($bad), $bad);
        }
    }

    /** Acceptance test 15 (cases 14 and 15). */
    public function testVariableNames(): void
    {
        foreach (['Name', '1a', '_a', 'a-b', str_repeat('a', 65)] as $bad) {
            self::assertContains('variable_name', $this->codes(['variables' => [['name' => $bad]]]), $bad);
        }
        self::assertNotContains('variable_name', $this->codes(['variables' => [['name' => 'a_1']], 'user' => '{{a_1}}']));
    }

    /** Acceptance test 17 (cases 17 and 20). */
    public function testDefaultsMustValidate(): void
    {
        self::assertContains('variable_default', $this->codes(['variables' => [['name' => 'n', 'type' => 'integer', 'required' => false, 'default' => 'x']], 'user' => '{{n}}']));
        self::assertContains('variable_default', $this->codes(['variables' => [['name' => 'n', 'required' => false, 'default' => 'abcdef', 'max_length' => 3]], 'user' => '{{n}}']));
        self::assertContains('variable_default_required', $this->codes(['variables' => [['name' => 'n', 'default' => 'x']], 'user' => '{{n}}']));
    }

    /** Acceptance test 22 (cases 25 and 26). */
    public function testUndeclaredIsErrorUnusedIsWarning(): void
    {
        $issues = Definition::validate(['slug' => 's', 'user' => '{{ ghost }}', 'variables' => [['name' => 'spare']]]);
        $byCode = [];
        foreach ($issues as $issue) {
            $byCode[$issue->code] = $issue;
        }
        self::assertTrue($byCode['undeclared']->isError());
        self::assertSame(1, $byCode['undeclared']->line);
        self::assertFalse($byCode['unused']->isError());
    }

    /** Acceptance test 29 (case 35). */
    public function testNoPerUseEscapeOption(): void
    {
        self::assertContains('syntax_placeholder', $this->codes(['user' => '{{ t | raw }}', 'variables' => [['name' => 't']]]));
        self::assertContains('variable_key', $this->codes(['variables' => [['name' => 't', 'escape_per_use' => ['raw']]], 'user' => '{{t}}']));
    }

    /** Acceptance test 12 (case 11). */
    public function testEmptyUserPartFailsEmptySystemPasses(): void
    {
        self::assertSame([], Issue::errorsOf(Definition::validate(['slug' => 's', 'system' => '', 'user' => 'Go'])));
        self::assertContains('user_empty', $this->codes(['user' => "  \n"]));
    }

    /** Acceptance test 11 and 13 (cases 10 and 12): save-blocking problems. */
    public function testUtf8AndSizeBlockSaving(): void
    {
        $issues = Definition::validate(['slug' => 's', 'system' => "ab\xFFcd", 'user' => 'x']);
        $utf8 = array_values(array_filter($issues, static fn (Issue $i): bool => $i->code === 'utf8'));
        self::assertCount(1, $utf8);
        self::assertStringContainsString('byte 2', $utf8[0]->message);
        self::assertTrue(Definition::blocksSave($utf8[0]));
        $size = Definition::validate(['slug' => 's', 'user' => str_repeat('a', 11)], ['max_part_bytes' => 10]);
        self::assertSame('part_size', $size[0]->code);
        self::assertTrue(Definition::blocksSave($size[0]));
        self::assertFalse(Definition::blocksSave(Issue::error('syntax_placeholder', 'x')));
    }

    public function testOutputAndSettings(): void
    {
        self::assertContains('output_schema', $this->codes(['output' => ['mode' => 'json', 'schema' => ['type' => 'array']]]));
        self::assertContains('settings_temperature', $this->codes(['settings' => ['temperature' => 3]]));
        self::assertContains('settings_token_cap', Array_map(static fn (Issue $i): string => $i->code, Definition::validate(['slug' => 's', 'user' => 'x', 'settings' => ['token_cap' => 9000]], ['max_token_cap' => 8192])));
        $definition = Definition::fromArray(['slug' => 's', 'user' => 'x', 'output' => ['mode' => 'json', 'schema' => ['type' => 'object']], 'settings' => ['temperature' => 1]]);
        self::assertSame('json', $definition->output()->mode());
        self::assertSame(1.0, $definition->settings()['temperature']);
    }

    /** Section 5.4: title, description and note do not change the hash; content does. */
    public function testContentHash(): void
    {
        $base = ['slug' => 's', 'title' => 'A', 'description' => 'B', 'note' => 'C', 'system' => 'S', 'user' => 'U {{x}}', 'variables' => [['name' => 'x']]];
        $hash = Definition::fromArray($base)->contentHash();
        self::assertSame($hash, Definition::fromArray(['title' => 'Z', 'description' => 'Y', 'note' => 'X'] + $base)->contentHash());
        self::assertNotSame($hash, Definition::fromArray(['user' => 'V {{x}}'] + $base)->contentHash());
        self::assertSame($hash, Definition::fromArray(['user' => "U {{x}}"] + $base)->contentHash());
        self::assertSame(64, strlen($hash));
        self::assertSame($hash, Definition::fromArray(Definition::fromArray($base)->toArray())->contentHash());
    }

    public function testFromArrayThrowsWithEveryError(): void
    {
        try {
            Definition::fromArray(['slug' => 'Bad Slug', 'user' => '{{ x }}']);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            $codes = array_map(static fn (Issue $i): string => $i->code, $e->issues());
            self::assertContains('slug', $codes);
            self::assertContains('undeclared', $codes);
        }
    }

    public function testVariableSpecFromArray(): void
    {
        $spec = VariableSpec::fromArray(['name' => 'tone', 'type' => 'enum', 'enum' => ['a', 'b'], 'required' => false, 'default' => 'a', 'description' => 'Tone.']);
        self::assertSame(['tone', 'enum', false, 'a', null, 'plain', ['a', 'b'], 'Tone.'], [$spec->name(), $spec->type(), $spec->required(), $spec->default(), $spec->maxLength(), $spec->escape(), $spec->enum(), $spec->description()]);
        $this->expectException(ValidationException::class);
        VariableSpec::fromArray(['name' => 'x', 'type' => 'enum']);
    }

    /** Acceptance test 38 (case 47). */
    public function testDiffShowsPartLinesAndVariableChanges(): void
    {
        $old = ['system' => "a\nb\nc", 'user' => 'u', 'variables' => [['name' => 'x'], ['name' => 'y']]];
        $new = ['system' => "a\nB\nc\nd", 'user' => 'u', 'variables' => [['name' => 'x', 'type' => 'text'], ['name' => 'z']]];
        $diff = Diff::definitions($old, $new);
        self::assertSame([
            ['op' => 'same', 'line' => 'a'],
            ['op' => 'remove', 'line' => 'b'],
            ['op' => 'add', 'line' => 'B'],
            ['op' => 'same', 'line' => 'c'],
            ['op' => 'add', 'line' => 'd'],
        ], $diff['system']);
        self::assertSame([['op' => 'same', 'line' => 'u']], $diff['user']);
        $changes = [];
        foreach ($diff['variables'] as $change) {
            $changes[$change['name']] = $change['change'];
        }
        self::assertSame(['x' => 'changed', 'y' => 'removed', 'z' => 'added'], $changes);
    }
}
