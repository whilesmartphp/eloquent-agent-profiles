<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\OwnerAccess\Authorizers\AllowAllAuthorizer;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class AuthorizationTest extends TestCase
{
    #[Test]
    public function the_allow_all_owner_default_fails_closed(): void
    {
        $member = $this->memberOf(1);
        $this->app->instance(OwnerAuthorizer::class, new AllowAllAuthorizer);

        $this->actingAs($member)->postJson('/api/agent-profiles', $this->profilePayload())->assertForbidden();

        AgentProfile::query()->create(['owner_type' => self::OWNER, 'owner_id' => '1', 'name' => 'Existing']);
        $id = AgentProfile::query()->value('public_id');

        $this->actingAs($member)->getJson('/api/agent-profiles')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($member)->getJson("/api/agent-profiles/{$id}")->assertForbidden();
        $this->assertDatabaseCount('agent_profiles', 1);
    }

    #[Test]
    public function guests_are_denied_even_with_a_strict_owner_authorizer(): void
    {
        AgentProfile::query()->create(['owner_type' => self::OWNER, 'owner_id' => '1', 'name' => 'Existing']);
        $id = AgentProfile::query()->value('public_id');

        $this->getJson("/api/agent-profiles/{$id}")->assertForbidden();
        $this->postJson('/api/agent-profiles', $this->profilePayload())->assertForbidden();
    }

    #[Test]
    public function members_of_another_owner_cannot_read_change_or_publish(): void
    {
        $owner = $this->memberOf(1);
        $stranger = $this->memberOf(2);
        $profile = $this->createProfile($owner);

        $this->actingAs($stranger)->postJson('/api/agent-profiles', $this->profilePayload())->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/agent-profiles/{$profile['id']}")->assertForbidden();
        $this->actingAs($stranger)->putJson("/api/agent-profiles/{$profile['id']}", ['revision' => 1, 'name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/agent-profiles/{$profile['id']}/readiness")->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/agent-profiles/{$profile['id']}/skills")->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/agent-profiles/{$profile['id']}/versions")->assertForbidden();
        $this->publish($stranger, $profile['id'], 1)->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/api/agent-profiles/{$profile['id']}")->assertForbidden();

        $this->assertSame('Billing assistant', AgentProfile::query()->value('name'));
        $this->assertDatabaseCount('agent_profile_versions', 0);
    }

    #[Test]
    public function a_host_authorizer_can_split_abilities(): void
    {
        $member = $this->memberOf(1);
        $this->app->instance(ProfileAuthorizer::class, new class implements ProfileAuthorizer
        {
            public function authorize(?Authenticatable $actor, string $ability, string $ownerType, string $ownerId, string $scopeKey): bool
            {
                return $actor !== null && ! in_array($ability, ['publish', 'activate'], true);
            }

            public function scope(Builder $query, ?Authenticatable $actor): Builder
            {
                return $query;
            }
        });

        $profile = $this->createProfile($member);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}")->assertOk();
        $this->publish($member, $profile['id'], 1)->assertForbidden();
        $this->assertDatabaseCount('agent_profile_versions', 0);
    }
}
