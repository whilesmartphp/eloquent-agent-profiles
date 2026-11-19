<?php

namespace Whilesmart\AgentProfiles\Exceptions;

use Whilesmart\AgentProfiles\Support\Issue;

class ProfileUnavailable extends AgentProfileException
{
    /**
     * @param  array<Issue>  $issues
     */
    public static function because(string $message, array $issues = []): self
    {
        return new self($message, 409, $issues);
    }
}
