<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionActivated;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionRevoked;
use Whilesmart\AgentProfiles\Exceptions\AccessDenied;
use Whilesmart\AgentProfiles\Exceptions\ProfileUnavailable;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Services\ExecutionManifestResolver;

class LifecycleTest extends TestCase
{
    #[Test]
    public function only_one_version_is_active_at_a_time(): void
    {
        Event::fake([AgentProfileVersionActivated::class]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = "/api/agent-profiles/{$profile['id']}";

        $this->publish($member, $profile['id'], 1)->assertCreated();
        $this->actingAs($member)->postJson("{$base}/versions/1/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($member)->getJson($base)->assertJsonPath('data.active_version', 1);

        $this->actingAs($member)->putJson($base, ['revision' => 1, 'definition' => $this->readyDefinition(['purpose' => 'Second'])])->assertOk();
        $this->publish($member, $profile['id'], 2, activate: true)->assertCreated()->assertJsonPath('data.status', 'active');

        $this->actingAs($member)->getJson("{$base}/versions/1")->assertJsonPath('data.status', 'inactive');
        $this->actingAs($member)->getJson($base)->assertJsonPath('data.active_version', 2);
        Event::assertDispatchedTimes(AgentProfileVersionActivated::class, 2);
    }

    #[Test]
    public function the_manifest_resolves_only_for_an_active_version(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = "/api/agent-profiles/{$profile['id']}";
        $this->publish($member, $profile['id'], 1)->assertCreated();

        $this->actingAs($member)->getJson("{$base}/versions/1/manifest")->assertStatus(409);

        $this->actingAs($member)->postJson("{$base}/versions/1/activate")->assertOk();
        $this->actingAs($member)->getJson("{$base}/versions/1/manifest")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.definition.instructions', 'Quote amounts from the invoice and never guess.')
            ->assertJsonPath('data.skills', []);

        $this->actingAs($member)->postJson("{$base}/versions/1/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($member)->postJson("{$base}/versions/1/deactivate")->assertStatus(409);
        $this->actingAs($member)->getJson("{$base}/versions/1/manifest")->assertStatus(409);
    }

    #[Test]
    public function revocation_is_terminal(): void
    {
        Event::fake([AgentProfileVersionRevoked::class]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = "/api/agent-profiles/{$profile['id']}";
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();

        $this->actingAs($member)->postJson("{$base}/versions/1/revoke", ['reason' => 'Gave wrong refund amounts.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revocation_reason', 'Gave wrong refund amounts.');

        $this->actingAs($member)->getJson("{$base}/versions/1/manifest")->assertStatus(409);
        $this->actingAs($member)->postJson("{$base}/versions/1/activate")->assertStatus(409);
        $this->actingAs($member)->postJson("{$base}/versions/1/revoke")->assertStatus(409);
        $this->actingAs($member)->getJson($base)->assertJsonPath('data.active_version', null);
        Event::assertDispatchedTimes(AgentProfileVersionRevoked::class, 1);
    }

    #[Test]
    public function strangers_cannot_change_version_state(): void
    {
        $member = $this->memberOf(1);
        $stranger = $this->memberOf(2);
        $profile = $this->createProfile($member);
        $base = "/api/agent-profiles/{$profile['id']}";
        $this->publish($member, $profile['id'], 1)->assertCreated();

        $this->actingAs($stranger)->postJson("{$base}/versions/1/activate")->assertForbidden();
        $this->actingAs($stranger)->postJson("{$base}/versions/1/revoke")->assertForbidden();
        $this->actingAs($stranger)->getJson("{$base}/versions/1/manifest")->assertForbidden();
        $this->actingAs($member)->getJson("{$base}/versions/1")->assertJsonPath('data.status', 'inactive');
    }

    #[Test]
    public function resolution_fails_closed_for_deleted_profiles_tampered_manifests_and_moved_owners(): void
    {
        $member = $this->memberOf(1);
        $this->memberOf(2);
        $this->owners->grant($member, self::OWNER, 2);
        $resolver = app(ExecutionManifestResolver::class);

        $profile = $this->createProfile($member);
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();
        $version = AgentProfileVersion::query()->firstOrFail();
        $this->assertSame(1, $resolver->resolve($version, $member)->version->number);

        DB::table('agent_profile_versions')->update(['content' => json_encode(array_replace_recursive($version->content, ['definition' => ['purpose' => 'Tampered']]))]);
        $this->assertUnavailable(fn () => $resolver->resolve($version->fresh(), $member));

        DB::table('agent_profile_versions')->update(['content' => json_encode($version->content)]);
        DB::table('agent_profiles')->update(['owner_id' => '2']);
        $this->assertUnavailable(fn () => $resolver->resolve($version->fresh(), $member));

        DB::table('agent_profiles')->update(['owner_id' => '1']);
        $this->assertSame(1, $resolver->resolve($version->fresh(), $member)->version->number);

        AgentProfile::query()->firstOrFail()->delete();
        $this->assertUnavailable(fn () => $resolver->resolve($version->fresh(), $member));
    }

    #[Test]
    public function resolution_requires_an_authorized_actor(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();

        $this->expectException(AccessDenied::class);
        app(ExecutionManifestResolver::class)->resolveActive(AgentProfile::query()->firstOrFail());
    }

    private function assertUnavailable(callable $resolve): void
    {
        try {
            $resolve();
            $this->fail('The version resolved.');
        } catch (ProfileUnavailable) {
            $this->addToAssertionCount(1);
        }
    }
}
