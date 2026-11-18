<?php

namespace Whilesmart\AgentProfiles\Validators;

use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

class ModelProviderValidator implements DefinitionValidator
{
    public function validate(array $definition, AgentProfile $profile): array
    {
        $known = array_map('strval', (array) config('agent-profiles.model_providers', []));
        $issues = [];

        $provider = $definition['model']['provider'] ?? null;
        if ($provider !== null && ! in_array($provider, $known, true)) {
            $issues[] = Issue::error('unknown_model_provider', "Model provider {$provider} is not one this host allows.", 'definition.model.provider');
        }

        foreach ($definition['model']['fallbacks'] ?? [] as $index => $fallback) {
            if (! in_array($fallback['provider'], $known, true)) {
                $issues[] = Issue::error('unknown_model_provider', "Model provider {$fallback['provider']} is not one this host allows.", "definition.model.fallbacks.{$index}.provider");
            }
        }

        return $issues;
    }
}
