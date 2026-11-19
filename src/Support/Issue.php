<?php

namespace Whilesmart\AgentProfiles\Support;

final class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly string $severity = self::ERROR,
    ) {}

    public static function error(string $code, string $message, ?string $path = null): self
    {
        return new self($code, $message, $path, self::ERROR);
    }

    public static function warning(string $code, string $message, ?string $path = null): self
    {
        return new self($code, $message, $path, self::WARNING);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * @param  array<Issue>  $issues
     */
    public static function anyErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, message: string, path: ?string, severity: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
            'severity' => $this->severity,
        ];
    }
}
