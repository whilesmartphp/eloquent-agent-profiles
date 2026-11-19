<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Exceptions\ImmutableVersion;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;

class PublicationIdentityTest extends TestCase
{
    #[Test]
    public function storage_has_two_tables_and_one_published_payload(): void
    {
        $this->assertFalse(Schema::hasTable('agent_profile_version_states'));
        $this->assertTrue(Schema::hasColumn('agent_profiles', 'active_version_id'));
        $this->assertTrue(Schema::hasColumn('agent_profile_versions', 'content'));
        foreach (['manifest', 'definition', 'skills', 'snapshots'] as $column) {
            $this->assertFalse(Schema::hasColumn('agent_profile_versions', $column));
        }
    }

    #[Test]
    public function publishing_unchanged_content_or_renaming_reuses_the_version(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $first = $this->publish($member, $profile['id'], 1)->assertCreated()->json('data');
        $this->publish($member, $profile['id'], 1)->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->actingAs($member)->putJson('/api/agent-profiles/'.$profile['id'], ['revision' => 1, 'name' => 'New name'])->assertOk();
        $this->publish($member, $profile['id'], 2, activate: true)->assertOk()->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.status', 'active');
        $this->assertDatabaseCount('agent_profile_versions', 1);
    }

    #[Test]
    public function rollback_reactivates_old_content_without_creating_versions(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = '/api/agent-profiles/'.$profile['id'];
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();
        $this->actingAs($member)->putJson($base, ['revision' => 1, 'definition' => $this->readyDefinition(['purpose' => 'Handle refunds.'])])->assertOk();
        $this->publish($member, $profile['id'], 2, activate: true)->assertCreated();
        $this->postJson($base.'/versions/1/activate')->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson($base.'/versions/2')->assertJsonPath('data.status', 'inactive');
        $this->actingAs($member)->putJson($base, ['revision' => 2, 'definition' => $this->readyDefinition()])->assertOk();
        $this->publish($member, $profile['id'], 3)->assertOk()->assertJsonPath('data.number', 1);
        $this->assertDatabaseCount('agent_profile_versions', 2);
    }

    #[Test]
    public function tampered_content_is_rejected_by_both_read_paths(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = '/api/agent-profiles/'.$profile['id'];
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();
        $version = AgentProfileVersion::query()->firstOrFail();
        $content = $version->content;
        $content['definition']['purpose'] = 'Changed outside publication';
        DB::table('agent_profile_versions')->where('id', $version->getKey())->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        $this->actingAs($member)->getJson($base.'/versions/1')->assertStatus(409);
        $this->getJson($base.'/versions/1/manifest')->assertStatus(409);
    }

    #[Test]
    public function revoked_content_cannot_be_republished_and_revocation_is_write_once(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = '/api/agent-profiles/'.$profile['id'];
        $this->publish($member, $profile['id'], 1, activate: true)->assertCreated();
        $this->actingAs($member)->postJson($base.'/versions/1/revoke')->assertOk();
        $this->assertNull(AgentProfile::query()->firstOrFail()->active_version_id);
        $this->publish($member, $profile['id'], 1)->assertStatus(409);
        $version = AgentProfileVersion::query()->firstOrFail();
        $this->expectException(ImmutableVersion::class);
        $version->forceFill(['revoked_at' => null])->save();
    }
}
