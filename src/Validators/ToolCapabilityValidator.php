<?php

namespace Whilesmart\AgentProfiles\Validators;

use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

class ToolCapabilityValidator implements DefinitionValidator
{
    public function validate(array $definition, AgentProfile $profile): array
    {
        $known = array_map('strval', (array) config('agent-profiles.capabilities.tools', []));
        $issues = [];

        foreach ($definition['permissions']['tools'] ?? [] as $index => $tool) {
            if (! in_array($tool, $known, true)) {
                $issues[] = Issue::error('unknown_tool', "Tool {$tool} is not a capability this host grants.", "definition.permissions.tools.{$index}");
            }
        }

        return $issues;
    }
}
