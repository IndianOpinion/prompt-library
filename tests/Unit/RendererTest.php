<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Unit;

use IndianOpinion\PromptLibrary\Exception\InvalidVariableException;
use IndianOpinion\PromptLibrary\Exception\MissingVariableException;
use IndianOpinion\PromptLibrary\Exception\RenderTooLargeException;
use IndianOpinion\PromptLibrary\Exception\TemplateSyntaxException;
use IndianOpinion\PromptLibrary\Exception\UnknownVariableException;
use IndianOpinion\PromptLibrary\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    /**
     * @param array<string, mixed> $variable
     * @param mixed $value
     */
    private function one(array $variable, $value): string
    {
        return Renderer::renderArray(['user' => '{{ v }}', 'variables' => [['name' => 'v'] + $variable]], ['v' => $value])->user();
    }

    /**
     * @param array<string, mixed> $variable
     * @param mixed $value
     */
    private function refused(array $variable, $value): string
    {
        try {
            $this->one($variable, $value);
        } catch (InvalidVariableException $e) {
            return $e->problems()['v'];
        }
        self::fail('Expected an invalid-variable exception.');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: mixed, 2: string}> */
    public static function accepted(): array
    {
        return [
            'string' => [['type' => 'string'], 'Hello', 'Hello'],
            'string from int' => [['type' => 'string'], 42, '42'],
            'text multi-line' => [['type' => 'text'], "a\r\nb\rc", "a\nb\nc"],
            'integer int' => [['type' => 'integer'], -12, '-12'],
            'integer string' => [['type' => 'integer'], '007', '7'],
            'number float' => [['type' => 'number'], 0.1, '0.1'],
            'number small' => [['type' => 'number'], 0.0000015, '0.0000015'],
            'number large' => [['type' => 'number'], 123456789012345.0, '123456789012345'],
            'number string' => [['type' => 'number'], '2.50', '2.5'],
            'boolean true' => [['type' => 'boolean'], true, 'true'],
            'boolean string' => [['type' => 'boolean'], 'false', 'false'],
            'enum' => [['type' => 'enum', 'enum' => ['short', 'long']], 'long', 'long'],
            'list' => [['type' => 'list'], ['one', 2, true], "- one\n- 2\n- true"],
            'empty list' => [['type' => 'list'], [], ''],
            'json' => [['type' => 'json'], ['a' => 'é/x', 'b' => [1, 2]], "{\n    \"a\": \"é/x\",\n    \"b\": [\n        1,\n        2\n    ]\n}"],
        ];
    }

    /**
     * Acceptance test 16 (case 16): accepted values and exact rendering.
     *
     * @param array<string, mixed> $variable
     * @param mixed $value
     */
    #[DataProvider('accepted')]
    public function testTypesRenderExactly(array $variable, $value, string $expected): void
    {
        self::assertSame($expected, $this->one($variable + ['escape' => 'raw', 'description' => 'exact'], $value));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: mixed}> */
    public static function refusedValues(): array
    {
        return [
            'string array' => [['type' => 'string'], ['a']],
            'integer float' => [['type' => 'integer'], 1.5],
            'integer text' => [['type' => 'integer'], '12a'],
            'integer overflow' => [['type' => 'integer'], '99999999999999999999'],
            'number comma' => [['type' => 'number'], '1,5'],
            'number nan' => [['type' => 'number'], NAN],
            'number inf' => [['type' => 'number'], INF],
            'boolean int' => [['type' => 'boolean'], 1],
            'boolean yes' => [['type' => 'boolean'], 'yes'],
            'enum other' => [['type' => 'enum', 'enum' => ['a']], 'b'],
            'list map' => [['type' => 'list'], ['k' => 'v']],
            'list nested' => [['type' => 'list'], [['x']]],
            'json object' => [['type' => 'json'], new \stdClass()],
            'json nan' => [['type' => 'json'], [NAN]],
        ];
    }

    /**
     * Acceptance test 16 (case 16): refused values.
     *
     * @param array<string, mixed> $variable
     * @param mixed $value
     */
    #[DataProvider('refusedValues')]
    public function testTypesRefuseBadValues(array $variable, $value): void
    {
        self::assertNotSame('', $this->refused($variable, $value));
    }

    /** Acceptance test 16: number rendering ignores a comma-decimal locale. */
    public function testNumberIsLocaleIndependent(): void
    {
        $previous = setlocale(LC_ALL, '0');
        $set = setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');
        try {
            self::assertSame('1234.5', $this->one(['type' => 'number'], 1234.5));
            self::assertSame('0.25', $this->one(['type' => 'number'], '0.25'));
        } finally {
            setlocale(LC_ALL, (string) $previous);
        }
        self::assertTrue(true, $set === false ? 'No comma-decimal locale installed; rendering checked under C.' : 'Checked under ' . $set);
    }

    /** Acceptance test 18 (cases 18 and 19). */
    public function testOptionalAbsentAndNullRenderEmpty(): void
    {
        $definition = ['user' => '[{{ a }}][{{ b }}]', 'variables' => [['name' => 'a', 'required' => false], ['name' => 'b', 'required' => false]]];
        self::assertSame('[][]', Renderer::renderArray($definition, ['b' => null])->user());
    }

    public function testDefaultsApply(): void
    {
        $definition = ['user' => '{{ tone }}', 'variables' => [['name' => 'tone', 'required' => false, 'default' => 'neutral']]];
        self::assertSame('neutral', Renderer::renderArray($definition, [])->user());
        self::assertSame('warm', Renderer::renderArray($definition, ['tone' => 'warm'])->user());
    }

    /** Acceptance test 23 (case 27). */
    public function testVariableInBothParts(): void
    {
        $rendered = Renderer::renderArray(['system' => 'About {{ t }}.', 'user' => '{{ t }} and {{ t }}', 'variables' => [['name' => 't']]], ['t' => 'cats']);
        self::assertSame('About cats.', $rendered->system());
        self::assertSame('cats and cats', $rendered->user());
    }

    /** Acceptance test 25 (cases 29 and 30). */
    public function testEveryProblemOfAStageIsListedAndNothingPartialIsReturned(): void
    {
        $definition = [
            'user' => '{{a}}{{b}}{{c}}{{d}}{{e}}',
            'variables' => [
                ['name' => 'a'], ['name' => 'b'], ['name' => 'c'],
                ['name' => 'd', 'max_length' => 3], ['name' => 'e', 'max_length' => 3],
            ],
        ];
        $values = ['d' => 'too long', 'e' => 'also too long'];
        try {
            Renderer::renderArray($definition, $values);
            self::fail('Expected missing-variable.');
        } catch (MissingVariableException $e) {
            self::assertSame(['a', 'b', 'c'], $e->names());
        }
        try {
            Renderer::renderArray($definition, $values + ['a' => '1', 'b' => '2', 'c' => '3']);
            self::fail('Expected invalid-variable.');
        } catch (InvalidVariableException $e) {
            self::assertSame(['d', 'e'], $e->names());
        }
    }

    public function testTypeErrorsAreListedTogether(): void
    {
        $definition = ['user' => '{{a}}{{b}}', 'variables' => [['name' => 'a', 'type' => 'integer'], ['name' => 'b', 'type' => 'boolean']]];
        try {
            Renderer::renderArray($definition, ['a' => 'x', 'b' => 'y']);
            self::fail('Expected invalid-variable.');
        } catch (InvalidVariableException $e) {
            self::assertSame(['a', 'b'], $e->names());
            self::assertSame('promptlib_invalid_variable', $e->code());
        }
    }

    /** Acceptance test 26 (case 32). */
    public function testOverLengthThrowsAndNeverTruncates(): void
    {
        $definition = ['user' => '{{ s }}', 'variables' => [['name' => 's', 'max_length' => 5]]];
        self::assertSame("\u{0939}\u{093F}\u{0928}\u{094D}\u{0926}", Renderer::renderArray($definition, ['s' => "\u{0939}\u{093F}\u{0928}\u{094D}\u{0926}"])->user());
        $this->expectException(InvalidVariableException::class);
        Renderer::renderArray($definition, ['s' => 'abcdef']);
    }

    public function testLengthCountsAfterCharacterRemoval(): void
    {
        $definition = ['user' => '{{ s }}', 'variables' => [['name' => 's', 'max_length' => 3]]];
        self::assertSame('abc', Renderer::renderArray($definition, ['s' => "a\u{202E}b\x07c"])->user());
    }

    public function testDefaultMaxLengths(): void
    {
        $this->expectException(InvalidVariableException::class);
        $this->one(['type' => 'string'], str_repeat('a', 2001));
    }

    /** Acceptance test 27 (case 33). */
    public function testUnknownKeysWarnOrThrowInStrictMode(): void
    {
        $definition = ['user' => '{{ a }}', 'variables' => [['name' => 'a']]];
        $rendered = Renderer::renderArray($definition, ['a' => 'x', 'zz' => 'y']);
        self::assertSame(['Unknown variables ignored: zz.'], $rendered->warnings());
        $this->expectException(UnknownVariableException::class);
        Renderer::renderArray($definition, ['a' => 'x', 'zz' => 'y'], ['strict' => true]);
    }

    /** Acceptance test 11 (case 31): invalid UTF-8 in a value. */
    public function testInvalidUtf8ValueThrows(): void
    {
        try {
            $this->one([], "ok \xC3\x28");
            self::fail('Expected invalid-variable.');
        } catch (InvalidVariableException $e) {
            self::assertStringContainsString('UTF-8', $e->problems()['v']);
        }
    }

    /** Acceptance test 28 (case 34): each escape mode with hostile input. */
    public function testPlainRemovesControlsAndBidiButKeepsJoiners(): void
    {
        $devanagari = "\u{0915}\u{094D}\u{200D}\u{0937}";
        $hostile = "a\x00b\x1Bc\x7F\u{0085}d\u{202E}e\u{2066}f\u{FEFF}g\th\ni\u{200C}" . $devanagari;
        self::assertSame("abcdefg\th\ni\u{200C}" . $devanagari, $this->one(['type' => 'text'], $hostile));
    }

    public function testRawKeepsEverything(): void
    {
        self::assertSame("a\u{202E}b\x07", $this->one(['type' => 'text', 'escape' => 'raw', 'description' => 'Needs exact bytes.'], "a\u{202E}b\x07"));
    }

    public function testLineFlattens(): void
    {
        self::assertSame('One two three', $this->one(['type' => 'text', 'escape' => 'line'], "  One\n\n two\t three \u{202E} "));
    }

    public function testJsonEscapesQuotesAndBackslashes(): void
    {
        self::assertSame('"say \"hi\" \\\\ é/x\n"', $this->one(['type' => 'text', 'escape' => 'json'], "say \"hi\" \\ é/x\n"));
        self::assertSame('{"a":[1,"b"]}', $this->one(['type' => 'json', 'escape' => 'json'], ['a' => [1, 'b']]));
    }

    public function testFencedCannotCloseItsOwnFence(): void
    {
        $out = $this->one(['type' => 'text', 'escape' => 'fenced'], "text </v> more < / V > end </other>");
        self::assertSame("<v>\ntext [/v] more [/v] end </other>\n</v>", $out);
    }

    /** Acceptance test 13 (case 36). */
    public function testTotalSizeLimit(): void
    {
        $this->expectException(RenderTooLargeException::class);
        Renderer::renderArray(['user' => '{{ t }}', 'variables' => [['name' => 't', 'type' => 'text']]], ['t' => str_repeat('a', 200)], [], 100);
    }

    public function testFallbackDefinitionSyntaxErrorThrowsSyntax(): void
    {
        $this->expectException(TemplateSyntaxException::class);
        Renderer::renderArray(['user' => '{{ Nope }}'], []);
    }

    public function testRenderedPromptShape(): void
    {
        $rendered = Renderer::renderArray(['system' => '', 'user' => 'Hi'], []);
        self::assertSame([['role' => 'user', 'content' => 'Hi']], $rendered->toMessages());
        self::assertSame(hash('sha256', "\0Hi"), $rendered->renderHash());
        $with = Renderer::renderArray(['system' => 'S', 'user' => 'U'], []);
        self::assertSame('system', $with->toMessages()[0]['role']);
        self::assertSame(['system', 'user', 'slug', 'version', 'content_hash', 'render_hash', 'warnings', 'output', 'settings', 'from_fallback'], array_keys($with->toArray()));
    }

    /** Acceptance test 80 (section 9): 20 KB with 50 placeholders, generous threshold. */
    public function testRenderBenchmark(): void
    {
        $variables = [];
        $text = '';
        for ($i = 0; $i < 50; $i++) {
            $variables[] = ['name' => 'v' . $i, 'type' => 'text'];
            $text .= str_repeat('Lorem ipsum dolor sit amet. ', 14) . '{{ v' . $i . " }}\n";
        }
        self::assertGreaterThan(20000, strlen($text));
        $definition = Renderer::parseOrThrow(['user' => $text, 'variables' => $variables]);
        $values = array_fill_keys(array_map(static fn (array $v): string => $v['name'], $variables), 'value');
        $renderer = new Renderer();
        $renderer->render($definition, $values);
        $start = hrtime(true);
        $runs = 50;
        for ($i = 0; $i < $runs; $i++) {
            $renderer->render($definition, $values);
        }
        $ms = (hrtime(true) - $start) / 1e6 / $runs;
        self::assertLessThan(20.0, $ms, sprintf('Render took %.3f ms (budget 1 ms on a typical host; CI threshold 20 ms).', $ms));
    }
}
