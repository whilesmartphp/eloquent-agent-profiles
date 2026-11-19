<?php

namespace Whilesmart\AgentProfiles\Exceptions;

use Whilesmart\AgentProfiles\Support\Issue;

class InvalidDefinition extends AgentProfileException
{
    /**
     * @param  array<Issue>  $issues
     */
    public static function because(array $issues): self
    {
        return new self('The definition is not valid.', 422, $issues);
    }
}
