<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary;

/**
 * One validation finding: an error (blocks publish) or a warning (shown only).
 */
final class Issue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $part = '',
        public readonly int $line = 0,
        public readonly int $column = 0,
    ) {
    }

    public static function error(string $code, string $message, string $part = '', int $line = 0, int $column = 0): self
    {
        return new self(self::ERROR, $code, $message, $part, $line, $column);
    }

    public static function warning(string $code, string $message, string $part = '', int $line = 0, int $column = 0): self
    {
        return new self(self::WARNING, $code, $message, $part, $line, $column);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function describe(): string
    {
        if ($this->part !== '' && $this->line > 0) {
            return sprintf('%s part, line %d, column %d: %s', ucfirst($this->part), $this->line, $this->column, $this->message);
        }
        if ($this->part !== '') {
            return sprintf('%s: %s', $this->part, $this->message);
        }

        return $this->message;
    }

    /** @return array{severity: string, code: string, message: string, part: string, line: int, column: int} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'part' => $this->part,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    public static function errorsOf(array $issues): array
    {
        return array_values(array_filter($issues, static fn (Issue $i): bool => $i->isError()));
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    public static function warningsOf(array $issues): array
    {
        return array_values(array_filter($issues, static fn (Issue $i): bool => !$i->isError()));
    }
}
