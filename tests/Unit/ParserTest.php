<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Unit;

use IndianOpinion\PromptLibrary\Exception\TemplateSyntaxException;
use IndianOpinion\PromptLibrary\Parser;
use IndianOpinion\PromptLibrary\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    /** @param array<mixed> $variables */
    private function render(string $user, array $values, array $variables = [['name' => 'x', 'escape' => 'raw', 'description' => 'test']]): string
    {
        return Renderer::renderArray(['user' => $user, 'variables' => $variables], $values)->user();
    }

    /** Acceptance test 19 (case 21). */
    public function testSpacingVariantsRenderTheSame(): void
    {
        foreach (['{{x}}', '{{ x }}', "{{\tx\t}}", '{{  x}}', '{{x   }}'] as $placeholder) {
            self::assertSame('[v]', $this->render('[' . $placeholder . ']', ['x' => 'v']), $placeholder);
        }
    }

    /** Acceptance test 20 (cases 22 and 23). */
    public function testEscapesAndBraceRuns(): void
    {
        self::assertSame('{{x}}', $this->render('\{{x}}', ['x' => 'v']));
        self::assertSame('\v', $this->render('\\\\{{x}}', ['x' => 'v']));
        self::assertSame('{v}', $this->render('{{{x}}}', ['x' => 'v']));
        self::assertSame('{{v}}', $this->render('{{{{x}}}}', ['x' => 'v']));
        self::assertSame('a \ b \n', $this->render('a \ b \n', ['x' => 'v']));
        self::assertSame('{"a": {"b": 1}} }}', $this->render('{"a": {"b": 1}} }}', ['x' => 'v']));
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function malformed(): array
    {
        return [
            'upper case' => ['ab {{ Name }}', 1, 4],
            'two words' => ["line one\n  {{ a b }}", 2, 3],
            'empty' => ['{{}}', 1, 1],
            'unclosed' => ['x {{name', 1, 3],
            'filter' => ['{{ x | upper }}', 1, 1],
            'dots' => ['{{ x.y }}', 1, 1],
            'unicode before' => ["\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947} {{ ? }}", 1, 8],
        ];
    }

    /** Acceptance test 21 (case 24). */
    #[DataProvider('malformed')]
    public function testMalformedPlaceholdersAreErrorsWithLineAndColumn(string $text, int $line, int $column): void
    {
        $parsed = Parser::analyse($text, 'user');
        self::assertNotEmpty($parsed->issues);
        self::assertSame($line, $parsed->issues[0]->line);
        self::assertSame($column, $parsed->issues[0]->column);
        self::assertSame('user', $parsed->issues[0]->part);
        $this->expectException(TemplateSyntaxException::class);
        Parser::parse($text, 'user');
    }

    public function testEveryErrorIsReported(): void
    {
        $parsed = Parser::analyse("{{ A }} and {{ b c }}\n{{", 'system');
        self::assertCount(3, $parsed->issues);
    }

    /** Acceptance test 24 (case 28). */
    public function testValuesAreNeverScannedAgain(): void
    {
        $out = Renderer::renderArray([
            'user' => '{{ a }} / {{ other }}',
            'variables' => [['name' => 'a', 'escape' => 'raw', 'description' => 'test'], ['name' => 'other']],
        ], ['a' => '{{ other }}', 'other' => 'X'])->user();
        self::assertSame('{{ other }} / X', $out);
    }

    public function testNamesInOrderOfFirstUse(): void
    {
        self::assertSame(['b', 'a'], Parser::parse('{{b}} {{a}} {{b}}', 'user')->names());
    }
}
