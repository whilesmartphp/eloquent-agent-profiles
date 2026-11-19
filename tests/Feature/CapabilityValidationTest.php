<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\NoSecretsValidator;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Validators\ModelProviderValidator;
use Whilesmart\AgentProfiles\Validators\ResourceTypeValidator;
use Whilesmart\AgentProfiles\Validators\RuleEvaluatorValidator;
use Whilesmart\AgentProfiles\Validators\ToolCapabilityValidator;

class CapabilityValidationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function capableDefinition(): array
    {
        return $this->readyDefinition([
            'permissions' => ['tools' => ['invoices.read', 'mail.send'], 'approval_required' => ['mail.send']],
            'resources' => [['id' => 'ledger', 'type' => 'database', 'reference' => 'ledger-main', 'access' => 'read']],
            'rules' => [[
                'id' => 'no-refund-promises',
                'evaluator' => 'keyword',
                'when' => ['event' => 'before_tool', 'tools' => ['mail.send']],
                'resources' => ['ledger'],
                'config' => ['words' => ['refund']],
                'action' => 'block',
            ]],
            'model' => ['provider' => 'acme', 'name' => 'acme-large'],
        ]);
    }

    #[Test]
    public function typed_capabilities_save_as_drafts_but_block_publication_by_default(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => $this->capableDefinition()]);

        $issues = $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->json('data.issues');

        $this->assertEqualsCanonicalizing(
            ['unknown_tool', 'unknown_tool', 'unknown_evaluator', 'unknown_resource_type', 'unknown_model_provider'],
            array_column($issues, 'code'),
        );

        $this->publish($member, $profile['id'], 1)->assertUnprocessable();
        $this->assertDatabaseCount('agent_profile_versions', 0);
    }

    #[Test]
    public function configured_capabilities_allow_publication_with_compiled_rules(): void
    {
        config([
            'agent-profiles.capabilities.tools' => ['invoices.read', 'mail.send'],
            'agent-profiles.rule_evaluators' => ['keyword'],
            'agent-profiles.resource_types' => ['database'],
            'agent-profiles.model_providers' => ['acme'],
        ]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => $this->capableDefinition()]);

        $response = $this->publish($member, $profile['id'], 1)
            ->assertCreated()
            ->assertJsonPath('data.rules.evaluators', ['keyword']);

        $actual = $response->json('data.rules.events.before_tool');
        ksort($actual);
        $this->assertSame(
            ['invoices.read' => [], 'mail.send' => ['no-refund-promises']],
            $actual,
        );
    }

    #[Test]
    public function validators_are_configurable(): void
    {
        config(['agent-profiles.validators' => [
            ToolCapabilityValidator::class,
            RuleEvaluatorValidator::class,
            ResourceTypeValidator::class,
            ModelProviderValidator::class,
            NoSecretsValidator::class,
        ]]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => $this->readyDefinition(['instructions' => 'The admin password is hunter2.'])]);

        $this->publish($member, $profile['id'], 1)
            ->assertUnprocessable()
            ->assertJsonPath('errors.issues.0.code', 'secret_in_instructions');

        config(['agent-profiles.validators' => []]);
        $grantsTools = $this->createProfile($member, ['definition' => $this->capableDefinition()]);
        $this->publish($member, $grantsTools['id'], 1)->assertCreated();
    }
}
