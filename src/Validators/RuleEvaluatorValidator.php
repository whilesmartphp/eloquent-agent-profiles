<?php

namespace Whilesmart\AgentProfiles\Validators;

use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

class RuleEvaluatorValidator implements DefinitionValidator
{
    public function validate(array $definition, AgentProfile $profile): array
    {
        $known = array_map('strval', (array) config('agent-profiles.rule_evaluators', []));
        $issues = [];

        foreach ($definition['rules'] ?? [] as $index => $rule) {
            if (! in_array($rule['evaluator'], $known, true)) {
                $issues[] = Issue::error('unknown_evaluator', "Rule {$rule['id']} needs evaluator {$rule['evaluator']}, which this host does not provide.", "definition.rules.{$index}.evaluator");
            }
        }

        return $issues;
    }
}
