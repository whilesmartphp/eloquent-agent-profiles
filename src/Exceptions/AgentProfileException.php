<?php

namespace Whilesmart\AgentProfiles\Exceptions;

use RuntimeException;
use Whilesmart\AgentProfiles\Support\Issue;

class AgentProfileException extends RuntimeException
{
    /**
     * @param  array<Issue>  $issues
     */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issuesArray(): array
    {
        return array_map(fn (Issue $issue) => $issue->toArray(), $this->issues);
    }
}
