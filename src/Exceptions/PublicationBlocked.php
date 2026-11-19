<?php

namespace Whilesmart\AgentProfiles\Exceptions;

use Whilesmart\AgentProfiles\Support\Issue;

class PublicationBlocked extends AgentProfileException
{
    /**
     * @param  array<Issue>  $issues
     */
    public static function because(array $issues): self
    {
        return new self('The profile is not ready to publish.', 422, $issues);
    }
}
