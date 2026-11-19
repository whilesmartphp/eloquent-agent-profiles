<?php

namespace Whilesmart\AgentProfiles\Definition;

use Whilesmart\AgentProfiles\Support\Issue;

/**
 * Structural rules for an agent definition. Every object rejects keys it does not declare,
 * every list and text field is bounded, and nothing in a definition names a PHP class,
 * callable or endpoint: evaluators, tools, resource types and skill sources are plain
 * names that host-configured validators accept or reject.
 */
final class DefinitionSchema
{
    public const EVENTS = ['always', 'before_tool', 'after_tool', 'before_response'];

    public const ACTIONS = ['block', 'warn', 'require_approval'];

    public const ACCESS = ['read', 'write'];

    public const MANAGED_SOURCE = 'managed';

    private const KEYS = ['purpose', 'personality', 'instructions', 'rules', 'skills', 'resources', 'model', 'permissions', 'limits'];

    private const SLUG = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    private const TOOL = '/^[a-z0-9][a-z0-9_.:-]{0,127}$/';

    private const SKILL_NAME = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const HASH = '/^(sha256:)?[0-9a-f]{64}$/';

    private const MODEL_NAME = '/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,127}$/';

    private const LIMIT_KEYS = ['max_steps', 'max_tool_calls', 'max_input_tokens', 'max_output_tokens', 'max_duration_seconds'];

    /** @var array<string, mixed> */
    private array $bounds;

    /** @var array<Issue> */
    private array $issues = [];

    /**
     * @param  array<string, mixed>  $bounds
     */
    public function __construct(array $bounds = [])
    {
        $this->bounds = $bounds + [
            'max_bytes' => 65536,
            'max_text' => 20000,
            'max_short_text' => 2000,
            'max_rules' => 100,
            'max_skills' => 50,
            'max_resources' => 100,
            'max_tools' => 200,
            'max_rule_config_entries' => 50,
            'max_fallback_models' => 5,
            'limits' => [],
        ];
        $this->bounds['limits'] = (array) $this->bounds['limits'] + [
            'max_steps' => 1000,
            'max_tool_calls' => 10000,
            'max_input_tokens' => 2000000,
            'max_output_tokens' => 1000000,
            'max_duration_seconds' => 86400,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function empty(): array
    {
        return $this->normalize([]);
    }

    /**
     * @return array<Issue>
     */
    public function validate(mixed $definition): array
    {
        $this->issues = [];

        if (! is_array($definition) || ($definition !== [] && array_is_list($definition))) {
            return [Issue::error('definition_type', 'The definition must be an object.', 'definition')];
        }

        try {
            $bytes = strlen(json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\JsonException) {
            return [Issue::error('definition_encoding', 'The definition must be valid UTF-8 JSON.', 'definition')];
        }

        if ($bytes > (int) $this->bounds['max_bytes']) {
            return [Issue::error('definition_size', "The definition is {$bytes} bytes; the limit is {$this->bounds['max_bytes']}.", 'definition')];
        }

        $this->object($definition, 'definition', self::KEYS);

        foreach (['purpose', 'personality'] as $key) {
            $this->text($definition[$key] ?? null, "definition.{$key}", (int) $this->bounds['max_short_text']);
        }
        $this->text($definition['instructions'] ?? null, 'definition.instructions', (int) $this->bounds['max_text']);

        $tools = $this->permissions($definition['permissions'] ?? null);
        $resourceIds = $this->resources($definition['resources'] ?? null);
        $this->rules($definition['rules'] ?? null, $tools, $resourceIds);
        $this->skills($definition['skills'] ?? null);
        $this->limits($definition['limits'] ?? null);
        $this->model($definition['model'] ?? null, $definition['limits']['max_output_tokens'] ?? null);

        return $this->issues;
    }

    /**
     * The definition with every section present and defaults filled. Call only on a definition
     * that validated without issues.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalize(array $definition): array
    {
        $model = (array) ($definition['model'] ?? []);
        $permissions = (array) ($definition['permissions'] ?? []);
        $limits = (array) ($definition['limits'] ?? []);

        return [
            'purpose' => $this->trimmed($definition['purpose'] ?? null),
            'personality' => $this->trimmed($definition['personality'] ?? null),
            'instructions' => $this->trimmed($definition['instructions'] ?? null),
            'rules' => array_map(fn (array $rule) => [
                'id' => $rule['id'],
                'description' => $this->trimmed($rule['description'] ?? null),
                'evaluator' => $rule['evaluator'],
                'when' => [
                    'event' => $rule['when']['event'] ?? 'always',
                    'tools' => array_values($rule['when']['tools'] ?? []),
                ],
                'resources' => array_values($rule['resources'] ?? []),
                'config' => (array) ($rule['config'] ?? []),
                'action' => $rule['action'] ?? 'block',
            ], array_values((array) ($definition['rules'] ?? []))),
            'skills' => array_map(fn (array $ref) => [
                'source' => $ref['source'],
                'reference' => $ref['reference'],
                'skill' => $ref['skill'],
                'content_hash' => $ref['content_hash'],
            ], array_values((array) ($definition['skills'] ?? []))),
            'resources' => array_map(fn (array $resource) => [
                'id' => $resource['id'],
                'type' => $resource['type'],
                'reference' => $resource['reference'],
                'access' => $resource['access'] ?? 'read',
            ], array_values((array) ($definition['resources'] ?? []))),
            'model' => [
                'provider' => $model['provider'] ?? null,
                'name' => $model['name'] ?? null,
                'fallbacks' => array_map(fn (array $fallback) => [
                    'provider' => $fallback['provider'],
                    'name' => $fallback['name'],
                ], array_values((array) ($model['fallbacks'] ?? []))),
                'temperature' => isset($model['temperature']) ? (float) $model['temperature'] : null,
                'max_output_tokens' => $model['max_output_tokens'] ?? null,
            ],
            'permissions' => [
                'tools' => array_values((array) ($permissions['tools'] ?? [])),
                'approval_required' => array_values((array) ($permissions['approval_required'] ?? [])),
            ],
            'limits' => array_combine(self::LIMIT_KEYS, array_map(fn (string $key) => $limits[$key] ?? null, self::LIMIT_KEYS)),
        ];
    }

    /**
     * @return array<string>
     */
    private function permissions(mixed $value): array
    {
        if (! $this->optionalObject($value, 'definition.permissions', ['tools', 'approval_required'])) {
            return [];
        }

        $tools = $this->names($value['tools'] ?? null, 'definition.permissions.tools', self::TOOL, (int) $this->bounds['max_tools'], 'tool');
        $approval = $this->names($value['approval_required'] ?? null, 'definition.permissions.approval_required', self::TOOL, (int) $this->bounds['max_tools'], 'tool');

        foreach ($approval as $index => $tool) {
            if (! in_array($tool, $tools, true)) {
                $this->add('approval_outside_ceiling', "Tool {$tool} needs approval but is not granted in permissions.tools.", "definition.permissions.approval_required.{$index}");
            }
        }

        return $tools;
    }

    /**
     * @return array<string>
     */
    private function resources(mixed $value): array
    {
        $ids = [];

        foreach ($this->list($value, 'definition.resources', (int) $this->bounds['max_resources']) as $index => $resource) {
            $path = "definition.resources.{$index}";
            if (! $this->object($resource, $path, ['id', 'type', 'reference', 'access'])) {
                continue;
            }

            $id = $this->pattern($resource['id'] ?? null, "{$path}.id", self::SLUG, 64, true);
            $this->pattern($resource['type'] ?? null, "{$path}.type", self::SLUG, 64, true);
            $this->reference($resource['reference'] ?? null, "{$path}.reference");
            $this->choice($resource['access'] ?? null, "{$path}.access", self::ACCESS, false);

            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    $this->add('duplicate_resource', "Resource id {$id} is used twice.", "{$path}.id");
                }
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string>  $tools
     * @param  array<string>  $resourceIds
     */
    private function rules(mixed $value, array $tools, array $resourceIds): void
    {
        $ids = [];

        foreach ($this->list($value, 'definition.rules', (int) $this->bounds['max_rules']) as $index => $rule) {
            $path = "definition.rules.{$index}";
            if (! $this->object($rule, $path, ['id', 'description', 'evaluator', 'when', 'resources', 'config', 'action'])) {
                continue;
            }

            $id = $this->pattern($rule['id'] ?? null, "{$path}.id", self::SLUG, 64, true);
            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    $this->add('duplicate_rule', "Rule id {$id} is used twice.", "{$path}.id");
                }
                $ids[] = $id;
            }

            $this->text($rule['description'] ?? null, "{$path}.description", (int) $this->bounds['max_short_text']);
            $this->pattern($rule['evaluator'] ?? null, "{$path}.evaluator", self::SLUG, 64, true);
            $this->choice($rule['action'] ?? null, "{$path}.action", self::ACTIONS, false);

            if ($this->optionalObject($rule['when'] ?? null, "{$path}.when", ['event', 'tools'])) {
                $this->choice($rule['when']['event'] ?? null, "{$path}.when.event", self::EVENTS, false);
                foreach ($this->names($rule['when']['tools'] ?? null, "{$path}.when.tools", self::TOOL, (int) $this->bounds['max_tools'], 'tool') as $toolIndex => $tool) {
                    if (! in_array($tool, $tools, true)) {
                        $this->add('rule_tool_outside_ceiling', "Rule {$id} applies to tool {$tool}, which permissions.tools does not grant.", "{$path}.when.tools.{$toolIndex}");
                    }
                }
            }

            foreach ($this->names($rule['resources'] ?? null, "{$path}.resources", self::SLUG, (int) $this->bounds['max_resources'], 'resource id') as $resourceIndex => $resource) {
                if (! in_array($resource, $resourceIds, true)) {
                    $this->add('unknown_resource_reference', "Rule {$id} uses resource {$resource}, which the definition does not declare.", "{$path}.resources.{$resourceIndex}");
                }
            }

            $this->ruleConfig($rule['config'] ?? null, "{$path}.config");
        }
    }

    private function ruleConfig(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->add('type', 'Rule config must be an object.', $path);

            return;
        }

        if (count($value) > (int) $this->bounds['max_rule_config_entries']) {
            $this->add('too_many', "Rule config holds more than {$this->bounds['max_rule_config_entries']} entries.", $path);
        }

        foreach ($value as $key => $item) {
            $itemPath = "{$path}.{$key}";
            if (! is_string($key) || ! preg_match(self::SLUG, $key) || strlen($key) > 64) {
                $this->add('config_key', 'Rule config keys must be lowercase names.', $itemPath);

                continue;
            }

            if (is_array($item)) {
                if (! array_is_list($item) || count($item) > 100) {
                    $this->add('config_value', 'Rule config values may be scalars or lists of up to 100 scalars.', $itemPath);

                    continue;
                }
                foreach ($item as $entryIndex => $entry) {
                    $this->scalar($entry, "{$itemPath}.{$entryIndex}");
                }

                continue;
            }

            $this->scalar($item, $itemPath);
        }
    }

    private function scalar(mixed $value, string $path): void
    {
        if (is_string($value)) {
            $this->text($value, $path, 1000);

            return;
        }

        if ($value !== null && ! is_bool($value) && ! is_int($value) && ! is_float($value)) {
            $this->add('config_value', 'Rule config values may be text, numbers, booleans or null.', $path);
        }
    }

    private function skills(mixed $value): void
    {
        $seen = [];

        foreach ($this->list($value, 'definition.skills', (int) $this->bounds['max_skills']) as $index => $ref) {
            $path = "definition.skills.{$index}";
            if (! is_array($ref) || ($ref !== [] && array_is_list($ref))) {
                $this->add('type', 'A skill reference must be an object.', $path);

                continue;
            }

            $source = $this->pattern($ref['source'] ?? null, "{$path}.source", self::SLUG, 64, true);
            if ($source === null) {
                continue;
            }

            if ($source === self::MANAGED_SOURCE) {
                $this->add('managed_skill_binding', 'Assign managed skills through HasSkills bindings.', $path);

                continue;
            }
            $this->object($ref, $path, ['source', 'reference', 'skill', 'content_hash']);
            $reference = $this->reference($ref['reference'] ?? null, "{$path}.reference");
            $skill = $this->pattern($ref['skill'] ?? null, "{$path}.skill", self::SKILL_NAME, 64, true);
            $this->pattern($ref['content_hash'] ?? null, "{$path}.content_hash", self::HASH, 71, true);
            $key = "{$source}:{$reference}:{$skill}";

            if ($skill !== null) {
                if (in_array($key, $seen, true)) {
                    $this->add('duplicate_skill', 'The same skill is referenced twice.', $path);
                }
                $seen[] = $key;
            }
        }
    }

    private function model(mixed $value, mixed $limitOutput): void
    {
        if (! $this->optionalObject($value, 'definition.model', ['provider', 'name', 'fallbacks', 'temperature', 'max_output_tokens'])) {
            return;
        }

        $provider = $this->pattern($value['provider'] ?? null, 'definition.model.provider', self::SLUG, 64, false);
        $name = $this->pattern($value['name'] ?? null, 'definition.model.name', self::MODEL_NAME, 128, false);

        if ($name !== null && $provider === null) {
            $this->add('model_without_provider', 'A model name needs a provider.', 'definition.model.name');
        }

        foreach ($this->list($value['fallbacks'] ?? null, 'definition.model.fallbacks', (int) $this->bounds['max_fallback_models']) as $index => $fallback) {
            $path = "definition.model.fallbacks.{$index}";
            if ($this->object($fallback, $path, ['provider', 'name'])) {
                $this->pattern($fallback['provider'] ?? null, "{$path}.provider", self::SLUG, 64, true);
                $this->pattern($fallback['name'] ?? null, "{$path}.name", self::MODEL_NAME, 128, true);
            }
        }

        $temperature = $value['temperature'] ?? null;
        if ($temperature !== null && (! (is_int($temperature) || is_float($temperature)) || $temperature < 0 || $temperature > 2)) {
            $this->add('range', 'Temperature must be a number from 0 to 2.', 'definition.model.temperature');
        }

        $output = $this->integer($value['max_output_tokens'] ?? null, 'definition.model.max_output_tokens', 1, (int) $this->bounds['limits']['max_output_tokens']);
        if ($output !== null && is_int($limitOutput) && $output > $limitOutput) {
            $this->add('model_over_limit', 'The model output budget exceeds limits.max_output_tokens.', 'definition.model.max_output_tokens');
        }
    }

    private function limits(mixed $value): void
    {
        if (! $this->optionalObject($value, 'definition.limits', self::LIMIT_KEYS)) {
            return;
        }

        foreach (self::LIMIT_KEYS as $key) {
            $this->integer($value[$key] ?? null, "definition.limits.{$key}", $key === 'max_tool_calls' ? 0 : 1, (int) $this->bounds['limits'][$key]);
        }
    }

    /**
     * @param  array<string>  $allowed
     */
    private function object(mixed $value, string $path, array $allowed): bool
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->add('type', 'Must be an object.', $path);

            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->add('unknown_key', "Unknown key {$key}.", "{$path}.{$key}");
            }
        }

        return true;
    }

    /**
     * @param  array<string>  $allowed
     */
    private function optionalObject(mixed $value, string $path, array $allowed): bool
    {
        return $value !== null && $this->object($value, $path, $allowed);
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value, string $path, int $max): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->add('type', 'Must be a list.', $path);

            return [];
        }

        if (count($value) > $max) {
            $this->add('too_many', "Holds more than {$max} entries.", $path);

            return [];
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function names(mixed $value, string $path, string $pattern, int $max, string $label): array
    {
        $found = [];

        foreach ($this->list($value, $path, $max) as $index => $item) {
            $name = $this->pattern($item, "{$path}.{$index}", $pattern, 128, true, $label);
            if ($name === null) {
                continue;
            }
            if (in_array($name, $found, true)) {
                $this->add('duplicate', "{$name} is listed twice.", "{$path}.{$index}");

                continue;
            }
            $found[$index] = $name;
        }

        return $found;
    }

    private function pattern(mixed $value, string $path, string $pattern, int $max, bool $required, string $label = 'value'): ?string
    {
        if ($value === null) {
            if ($required) {
                $this->add('required', 'Required.', $path);
            }

            return null;
        }

        if (! is_string($value) || strlen($value) > $max || ! preg_match($pattern, $value)) {
            $this->add('format', "Not a valid {$label}.", $path);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string>  $choices
     */
    private function choice(mixed $value, string $path, array $choices, bool $required): void
    {
        if ($value === null) {
            if ($required) {
                $this->add('required', 'Required.', $path);
            }

            return;
        }

        if (! in_array($value, $choices, true)) {
            $this->add('choice', 'Must be one of: '.implode(', ', $choices).'.', $path);
        }
    }

    private function text(mixed $value, string $path, int $max): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->add('type', 'Must be text.', $path);

            return;
        }

        if (mb_strlen($value) > $max) {
            $this->add('length', "Longer than {$max} characters.", $path);
        }
    }

    private function reference(mixed $value, string $path): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->add('required', 'Required.', $path);

            return null;
        }

        if (strlen($value) > 500 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $this->add('format', 'References are single-line text of at most 500 bytes.', $path);

            return null;
        }

        return $value;
    }

    private function integer(mixed $value, string $path, int $min, int $max): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            $this->add('range', "Must be a whole number from {$min} to {$max}.", $path);

            return null;
        }

        return $value;
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function add(string $code, string $message, string $path): void
    {
        $this->issues[] = Issue::error($code, $message, $path);
    }
}
