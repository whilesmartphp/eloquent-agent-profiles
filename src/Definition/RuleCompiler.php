<?php

namespace Whilesmart\AgentProfiles\Definition;

/**
 * Turns the structured rules of a normalized definition into the lookup a host runtime reads:
 * rule ids per event and, for tool events, per granted tool. Compilation is pure data; no
 * evaluator is resolved or run here.
 */
final class RuleCompiler
{
    /**
     * @param  array<string, mixed>  $definition  A normalized definition.
     * @return array{events: array<string, mixed>, rules: array<string, array<string, mixed>>, evaluators: array<int, string>}
     */
    public function compile(array $definition): array
    {
        $granted = array_values((array) ($definition['permissions']['tools'] ?? []));
        sort($granted, SORT_STRING);

        $events = [
            'always' => [],
            'before_tool' => array_fill_keys($granted, []),
            'after_tool' => array_fill_keys($granted, []),
            'before_response' => [],
        ];
        $rules = [];
        $evaluators = [];

        $ordered = array_values((array) ($definition['rules'] ?? []));
        usort($ordered, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        foreach ($ordered as $rule) {
            $event = $rule['when']['event'] ?? 'always';
            $rules[$rule['id']] = [
                'evaluator' => $rule['evaluator'],
                'action' => $rule['action'] ?? 'block',
                'event' => $event,
                'resources' => array_values($rule['resources'] ?? []),
                'config' => (array) ($rule['config'] ?? []),
            ];
            $evaluators[$rule['evaluator']] = true;

            if (in_array($event, ['before_tool', 'after_tool'], true)) {
                $tools = array_values($rule['when']['tools'] ?? []) ?: $granted;
                foreach ($tools as $tool) {
                    if (array_key_exists($tool, $events[$event])) {
                        $events[$event][$tool][] = $rule['id'];
                    }
                }

                continue;
            }

            $events[$event][] = $rule['id'];
        }

        $evaluators = array_keys($evaluators);
        sort($evaluators, SORT_STRING);

        return ['events' => $events, 'rules' => $rules, 'evaluators' => $evaluators];
    }
}
