<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegerUser;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Events\AgentProfileSkillsChanged;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Services\ExecutionManifestResolver;
use Whilesmart\Skills\Models\Skill;
use Whilesmart\Skills\Models\SkillVersion;
use Whilesmart\Skills\Traits\HasSkills;

class ManagedSkillTest extends TestCase
{
    private IntegerUser $member;

    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->memberOf(1);
        $this->profile = $this->createProfile($this->member);
    }

    private function bind(SkillVersion $version, int $revision): TestResponse
    {
        return $this->actingAs($this->member)->postJson("/api/agent-profiles/{$this->profile['id']}/skills", [
            'revision' => $revision,
            'skill_version_id' => $version->public_id,
        ]);
    }

    #[Test]
    public function the_profile_model_uses_the_shared_has_skills_trait(): void
    {
        $this->assertContains(HasSkills::class, class_uses_recursive(AgentProfile::class));
    }

    #[Test]
    public function a_same_owner_and_scope_version_is_bound_and_pinned_on_publish(): void
    {
        Event::fake([AgentProfileSkillsChanged::class]);
        $version = $this->catalogue()->version(self::OWNER, 1, 'default', 'refund-policy');

        $this->bind($version, 1)
            ->assertCreated()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.skills.0.name', 'refund-policy')
            ->assertJsonPath('data.skills.0.available', true);

        $this->assertSame(1, AgentProfile::query()->firstOrFail()->skillBindings()->count());
        $this->bind($version, 1)->assertStatus(409);

        $this->publish($this->member, $this->profile['id'], 2, activate: true)
            ->assertCreated()
            ->assertJsonPath('data.skills.managed.0.skill_version_public_id', $version->public_id)
            ->assertJsonPath('data.skills.managed.0.skill_public_id', Skill::query()->whereKey($version->skill_id)->value('public_id'))
            ->assertJsonPath('data.skills.managed.0.content_hash', $version->content_hash);

        $manifest = app(ExecutionManifestResolver::class)->resolve(AgentProfileVersion::query()->firstOrFail(), $this->member);
        $this->assertSame([['name' => 'refund-policy', 'description' => 'Helps with a task.']], $manifest->skills->index());
        $this->assertNotEmpty($manifest->skillTools());

        Event::assertDispatched(AgentProfileSkillsChanged::class);
    }

    #[Test]
    public function direct_trait_assignment_invalidates_stale_http_publication(): void
    {
        $version = $this->catalogue()->version(self::OWNER, 1, 'default', 'refund-policy');
        $profile = AgentProfile::query()->where('public_id', $this->profile['id'])->firstOrFail();
        $profile->assignSkillVersion($version, $this->member);

        $this->assertSame(2, $profile->fresh()->draft_revision);
        $this->publish($this->member, $this->profile['id'], 1)->assertStatus(409);
        $this->publish($this->member, $this->profile['id'], 2)->assertCreated();

        $profile->detachSkill($version->skill, $this->member);
        $this->assertSame(3, $profile->fresh()->draft_revision);
        $this->publish($this->member, $this->profile['id'], 2)->assertStatus(409);
    }

    #[Test]
    public function skills_of_another_owner_or_scope_cannot_be_bound(): void
    {
        $otherOwner = $this->catalogue()->version(self::OWNER, 2, 'default', 'foreign-owner');
        $otherScope = $this->catalogue()->version(self::OWNER, 1, 'staging', 'foreign-scope');
        $otherType = $this->catalogue()->version('team', 1, 'default', 'foreign-type');

        $this->bind($otherOwner, 1)->assertUnprocessable();
        $this->bind($otherScope, 1)->assertUnprocessable();
        $this->bind($otherType, 1)->assertUnprocessable();

        $this->assertSame(0, AgentProfile::query()->firstOrFail()->skillBindings()->count());
        $this->assertSame(1, AgentProfile::query()->firstOrFail()->draft_revision);
    }

    #[Test]
    public function revoked_or_deleted_skills_cannot_be_bound(): void
    {
        $revoked = $this->catalogue()->version(self::OWNER, 1, 'default', 'revoked-skill');
        $this->catalogue()->revoke($revoked);
        $deleted = $this->catalogue()->version(self::OWNER, 1, 'default', 'deleted-skill');
        $this->catalogue()->deleteSkill($deleted);

        $this->bind($revoked, 1)->assertUnprocessable();
        $this->bind($deleted, 1)->assertUnprocessable();
    }

    #[Test]
    public function revoking_a_bound_skill_blocks_publication_activation_and_resolution(): void
    {
        $first = $this->catalogue()->version(self::OWNER, 1, 'default', 'refund-policy');
        $this->bind($first, 1)->assertCreated();
        $this->publish($this->member, $this->profile['id'], 2)->assertCreated();

        $this->catalogue()->revoke($first);
        $base = "/api/agent-profiles/{$this->profile['id']}";

        $this->actingAs($this->member)->getJson("{$base}/skills")->assertJsonPath('data.0.available', false);
        $this->actingAs($this->member)->getJson("{$base}/readiness")
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'skill_unavailable');
        $this->publish($this->member, $this->profile['id'], 2)->assertUnprocessable();

        $this->actingAs($this->member)->postJson("{$base}/versions/1/activate")
            ->assertStatus(409)
            ->assertJsonPath('errors.issues.0.code', 'skill_unavailable');
    }

    #[Test]
    public function an_active_version_stops_resolving_once_a_pinned_skill_is_revoked(): void
    {
        $version = $this->catalogue()->version(self::OWNER, 1, 'default', 'refund-policy');
        $this->bind($version, 1)->assertCreated();
        $this->publish($this->member, $this->profile['id'], 2, activate: true)->assertCreated();
        $base = "/api/agent-profiles/{$this->profile['id']}";

        $this->actingAs($this->member)->getJson("{$base}/versions/1/manifest")->assertOk();

        $this->catalogue()->revoke($version);

        $this->actingAs($this->member)->getJson("{$base}/versions/1/manifest")
            ->assertStatus(409)
            ->assertJsonPath('errors.issues.0.code', 'skill_unavailable');
    }

    #[Test]
    public function definition_managed_refs_must_match_a_binding_and_unbinding_advances_the_revision(): void
    {
        $version = $this->catalogue()->version(self::OWNER, 1, 'default', 'refund-policy');
        $skillId = Skill::query()->whereKey($version->skill_id)->value('public_id');
        $base = "/api/agent-profiles/{$this->profile['id']}";

        $this->actingAs($this->member)->putJson($base, [
            'revision' => 1,
            'definition' => $this->readyDefinition(['skills' => [['source' => 'managed', 'skill' => $skillId]]]),
        ])->assertOk();

        $this->actingAs($this->member)->getJson("{$base}/readiness")->assertJsonPath('data.issues.0.code', 'skill_not_bound');

        $this->bind($version, 2)->assertCreated();
        $this->actingAs($this->member)->getJson("{$base}/readiness")->assertJsonPath('data.ready', true);

        $this->actingAs($this->member)->deleteJson("{$base}/skills/{$skillId}", ['revision' => 3])
            ->assertOk()
            ->assertJsonPath('data.revision', 4);
        $this->actingAs($this->member)->getJson("{$base}/skills")->assertJsonPath('data', []);
    }
}
