<?php

namespace Whilesmart\AgentProfiles\Validators;

use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

class ResourceTypeValidator implements DefinitionValidator
{
    public function validate(array $definition, AgentProfile $profile): array
    {
        $known = array_map('strval', (array) config('agent-profiles.resource_types', []));
        $issues = [];

        foreach ($definition['resources'] ?? [] as $index => $resource) {
            if (! in_array($resource['type'], $known, true)) {
                $issues[] = Issue::error('unknown_resource_type', "Resource {$resource['id']} has type {$resource['type']}, which this host cannot resolve.", "definition.resources.{$index}.type");
            }
        }

        return $issues;
    }
}
