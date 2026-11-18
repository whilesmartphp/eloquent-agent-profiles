<?php

namespace Whilesmart\AgentProfiles\Contracts;

use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

/**
 * Checks a structurally valid, normalized definition against what the host can actually
 * provide. Errors block publication; drafts are still saved.
 */
interface DefinitionValidator
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<Issue>
     */
    public function validate(array $definition, AgentProfile $profile): array;
}
