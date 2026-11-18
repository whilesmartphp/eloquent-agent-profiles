<?php

namespace Whilesmart\AgentProfiles\Exceptions;

class SkillUnavailable extends AgentProfileException
{
    public static function make(string $message = 'The skill version is not available to this profile.'): self
    {
        return new self($message, 422);
    }
}
