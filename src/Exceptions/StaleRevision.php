<?php

namespace Whilesmart\AgentProfiles\Exceptions;

class StaleRevision extends AgentProfileException
{
    public function __construct(public readonly int $expected, public readonly int $current)
    {
        parent::__construct("The draft changed: expected revision {$expected}, current revision is {$current}.", 409);
    }
}
