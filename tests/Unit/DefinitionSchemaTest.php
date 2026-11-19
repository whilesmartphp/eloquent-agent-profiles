<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Support\Issue;

class DefinitionSchemaTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function complete(): array
    {
        return [
            'purpose' => 'Triage support tickets.',
            'personality' => 'Brief.',
            'instructions' => 'Label each ticket.',
            'rules' => [[
                'id' => 'no-refunds',
                'description' => 'Refunds need a person.',
                'evaluator' => 'keyword',
                'when' => ['event' => 'before_tool', 'tools' => ['tickets.update']],
                'resources' => ['ticket-db'],
                'config' => ['words' => ['refund', 'chargeback'], 'case_sensitive' => false],
                'action' => 'require_approval',
            ]],
            'skills' => [
                ['source' => 'house', 'reference' => 'house-style', 'skill' => 'release-notes', 'content_hash' => 'sha256:'.str_repeat('a', 64)],
            ],
            'resources' => [['id' => 'ticket-db', 'type' => 'database', 'reference' => 'tickets', 'access' => 'read']],
            'model' => ['provider' => 'acme', 'name' => 'acme-large-2', 'fallbacks' => [['provider' => 'acme', 'name' => 'acme-small']], 'temperature' => 0.2, 'max_output_tokens' => 2000],
            'permissions' => ['tools' => ['tickets.read', 'tickets.update'], 'approval_required' => ['tickets.update']],
            'limits' => ['max_steps' => 20, 'max_tool_calls' => 40, 'max_output_tokens' => 4000],
        ];
    }

    /**
     * @param  array<Issue>  $issues
     * @return array<int, string>
     */
    private function paths(array $issues): array
    {
        return array_map(fn (Issue $issue) => $issue->code.'@'.$issue->path, $issues);
    }

    #[Test]
    public function a_complete_definition_is_valid_and_normalizes_idempotently(): void
    {
        $schema = new DefinitionSchema;

        $this->assertSame([], $schema->validate($this->complete()));

        $normalized = $schema->normalize($this->complete());
        $this->assertSame([], $schema->validate($normalized));
        $this->assertSame($normalized, $schema->normalize($normalized));
        $this->assertSame('require_approval', $normalized['rules'][0]['action']);
        $this->assertNull($normalized['limits']['max_duration_seconds']);
    }

    #[Test]
    public function the_empty_definition_is_valid(): void
    {
        $schema = new DefinitionSchema;

        $this->assertSame([], $schema->validate([]));
        $this->assertSame([], $schema->validate($schema->empty()));
    }

    #[Test]
    public function unknown_keys_are_rejected_at_every_level(): void
    {
        $definition = $this->complete();
        $definition['endpoint'] = 'https://example.test/hook';
        $definition['rules'][0]['class'] = 'App\\Evaluators\\Anything';
        $definition['model']['base_url'] = 'https://example.test';
        $definition['skills'][0]['version'] = 3;

        $paths = $this->paths((new DefinitionSchema)->validate($definition));

        $this->assertContains('unknown_key@definition.endpoint', $paths);
        $this->assertContains('unknown_key@definition.rules.0.class', $paths);
        $this->assertContains('unknown_key@definition.model.base_url', $paths);
        $this->assertContains('unknown_key@definition.skills.0.version', $paths);
    }

    #[Test]
    public function cross_references_must_stay_inside_the_definition(): void
    {
        $definition = $this->complete();
        $definition['rules'][0]['when']['tools'] = ['payments.refund'];
        $definition['rules'][0]['resources'] = ['ledger'];
        $definition['permissions']['approval_required'] = ['mail.send'];

        $paths = $this->paths((new DefinitionSchema)->validate($definition));

        $this->assertContains('rule_tool_outside_ceiling@definition.rules.0.when.tools.0', $paths);
        $this->assertContains('unknown_resource_reference@definition.rules.0.resources.0', $paths);
        $this->assertContains('approval_outside_ceiling@definition.permissions.approval_required.0', $paths);
    }

    #[Test]
    public function types_formats_and_bounds_are_enforced(): void
    {
        $schema = new DefinitionSchema(['max_rules' => 1, 'max_short_text' => 10]);
        $definition = $this->complete();
        $definition['purpose'] = str_repeat('x', 11);
        $definition['rules'][] = $definition['rules'][0];
        $definition['skills'][0]['skill'] = 'NOT VALID';
        unset($definition['skills'][0]['content_hash']);
        $definition['model']['temperature'] = 3;
        $definition['limits']['max_steps'] = 0;
        $definition['resources'] = ['id' => 'x'];

        $paths = $this->paths($schema->validate($definition));

        $this->assertContains('length@definition.purpose', $paths);
        $this->assertContains('too_many@definition.rules', $paths);
        $this->assertContains('format@definition.skills.0.skill', $paths);
        $this->assertContains('required@definition.skills.0.content_hash', $paths);
        $this->assertContains('range@definition.model.temperature', $paths);
        $this->assertContains('range@definition.limits.max_steps', $paths);
        $this->assertContains('type@definition.resources', $paths);
    }

    #[Test]
    public function the_encoded_size_is_bounded(): void
    {
        $issues = (new DefinitionSchema(['max_bytes' => 100]))->validate(['instructions' => str_repeat('y', 200)]);

        $this->assertSame(['definition_size@definition'], $this->paths($issues));
    }

    #[Test]
    public function a_list_is_not_a_definition(): void
    {
        $this->assertSame(['definition_type@definition'], $this->paths((new DefinitionSchema)->validate(['purpose'])));
    }

    #[Test]
    public function rule_config_accepts_only_scalars_and_scalar_lists(): void
    {
        $definition = $this->complete();
        $definition['rules'][0]['config'] = ['nested' => ['deep' => ['x' => 1]], 'Bad Key' => 1];

        $paths = $this->paths((new DefinitionSchema)->validate($definition));

        $this->assertContains('config_value@definition.rules.0.config.nested', $paths);
        $this->assertContains('config_key@definition.rules.0.config.Bad Key', $paths);
    }
}
