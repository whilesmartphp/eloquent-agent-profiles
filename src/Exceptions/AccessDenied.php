<?php

namespace Whilesmart\AgentProfiles\Exceptions;

class AccessDenied extends AgentProfileException
{
    public static function for(string $ability): self
    {
        return new self("Not allowed to {$ability} this agent profile.", 403);
    }
}
