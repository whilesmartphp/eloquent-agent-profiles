<?php

namespace Tests\Support;

use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

class NoSecretsValidator implements DefinitionValidator
{
    public function validate(array $definition, AgentProfile $profile): array
    {
        return str_contains((string) $definition['instructions'], 'password')
            ? [Issue::error('secret_in_instructions', 'Instructions must not hold credentials.', 'definition.instructions')]
            : [];
    }
}
