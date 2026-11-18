<?php

namespace Whilesmart\AgentProfiles\Exceptions;

class ImmutableVersion extends AgentProfileException
{
    public static function make(): self
    {
        return new self('Published agent profile versions cannot be changed or deleted.', 409);
    }
}
