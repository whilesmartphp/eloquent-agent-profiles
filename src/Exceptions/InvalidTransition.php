<?php

namespace Whilesmart\AgentProfiles\Exceptions;

class InvalidTransition extends AgentProfileException
{
    public static function from(string $status, string $action): self
    {
        return new self("A {$status} version cannot be {$action}.", 409);
    }
}
