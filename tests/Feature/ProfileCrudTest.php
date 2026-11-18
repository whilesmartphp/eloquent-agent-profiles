<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Events\AgentProfileCreated;
use Whilesmart\AgentProfiles\Events\AgentProfileDeleted;
use Whilesmart\AgentProfiles\Events\AgentProfileDraftUpdated;
use Whilesmart\AgentProfiles\Models\AgentProfile;

class ProfileCrudTest extends TestCase
{
    #[Test]
    public function a_member_creates_a_profile_with_a_normalized_draft(): void
    {
        Event::fake([AgentProfileCreated::class]);
        $member = $this->memberOf(1);

        $response = $this->actingAs($member)
            ->postJson('/api/agent-profiles', $this->profilePayload(['metadata' => ['team' => 'billing']]))
            ->assertCreated()
            ->assertJsonPath('data.owner_type', self::OWNER)
            ->assertJsonPath('data.owner_id', '1')
            ->assertJsonPath('data.scope_key', 'default')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.active_version', null)
            ->assertJsonPath('data.definition.purpose', 'Answer billing questions for customers.')
            ->assertJsonPath('data.definition.rules', [])
            ->assertJsonPath('data.definition.permissions.tools', [])
            ->assertJsonPath('data.metadata.team', 'billing');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.id'));
        $this->assertIsInt(AgentProfile::query()->value('id'));
        Event::assertDispatched(AgentProfileCreated::class);
    }

    #[Test]
    public function definitions_with_unknown_keys_or_bad_shapes_are_rejected(): void
    {
        $member = $this->memberOf(1);

        $this->actingAs($member)->postJson('/api/agent-profiles', $this->profilePayload([
            'definition' => $this->readyDefinition(['handler' => 'App\\Agents\\Run', 'rules' => [['id' => 'x', 'evaluator' => 'y', 'callback' => 'exec']]]),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['definition.handler', 'definition.rules.0.callback']);

        $this->assertDatabaseCount('agent_profiles', 0);
    }

    #[Test]
    public function owner_scope_and_identifier_input_is_validated(): void
    {
        $member = $this->memberOf(1);

        $this->actingAs($member)->postJson('/api/agent-profiles', $this->profilePayload(['owner_id' => ['nested'], 'scope_key' => 'bad key!']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_id', 'scope_key']);

        $this->actingAs($member)->postJson('/api/agent-profiles', ['name' => 'No owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_type', 'owner_id']);
    }

    #[Test]
    public function updates_need_the_current_revision_and_advance_it(): void
    {
        Event::fake([AgentProfileDraftUpdated::class]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->putJson("/api/agent-profiles/{$profile['id']}", ['name' => 'Renamed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['revision']);

        $this->actingAs($member)->putJson("/api/agent-profiles/{$profile['id']}", [
            'revision' => 1,
            'name' => 'Renamed',
            'definition' => $this->readyDefinition(['instructions' => 'Cite the invoice number.']),
        ])
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.definition.instructions', 'Cite the invoice number.');

        $this->actingAs($member)->putJson("/api/agent-profiles/{$profile['id']}", ['revision' => 1, 'name' => 'Stale'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame('Renamed', AgentProfile::query()->value('name'));
        Event::assertDispatchedTimes(AgentProfileDraftUpdated::class, 1);
    }

    #[Test]
    public function owner_and_scope_cannot_be_changed_by_an_update(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->patchJson("/api/agent-profiles/{$profile['id']}", ['revision' => 1, 'owner_id' => 2, 'scope_key' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_id', 'scope_key']);
    }

    #[Test]
    public function index_lists_only_accessible_profiles_and_filters_by_scope(): void
    {
        $member = $this->memberOf(1);
        $other = $this->memberOf(2);
        $this->createProfile($member, ['name' => 'Default scope']);
        $this->createProfile($member, ['name' => 'Staging scope', 'scope_key' => 'staging']);
        $this->createProfile($other, ['owner_id' => 2, 'name' => 'Other owner']);

        $this->actingAs($member)->getJson('/api/agent-profiles')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->actingAs($member)->getJson('/api/agent-profiles?scope_key=staging')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.name', 'Staging scope');
    }

    #[Test]
    public function deleting_soft_deletes_and_hides_the_profile(): void
    {
        Event::fake([AgentProfileDeleted::class]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->deleteJson("/api/agent-profiles/{$profile['id']}")->assertOk();
        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}")->assertNotFound();

        $this->assertSoftDeleted('agent_profiles', ['public_id' => $profile['id']]);
        Event::assertDispatched(AgentProfileDeleted::class);
    }

    #[Test]
    public function uuid_actors_and_uuid_owners_are_supported(): void
    {
        $workspace = '7d1c5e0a-2f4b-4c39-9a51-0e6f3b8d2a44';
        $member = $this->uuidMemberOf($workspace);

        $profile = $this->createProfile($member, ['owner_id' => $workspace]);

        $this->assertSame($workspace, $profile['owner_id']);
        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}")->assertOk();
        $this->actingAs($this->memberOf(1))->getJson("/api/agent-profiles/{$profile['id']}")->assertForbidden();
    }
}
