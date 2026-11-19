<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionPublished;
use Whilesmart\AgentProfiles\Exceptions\ImmutableVersion;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Support\Canonical;

class PublicationTest extends TestCase
{
    #[Test]
    public function readiness_reports_what_blocks_publication(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => ['personality' => 'Warm.']]);

        $issues = $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.revision', 1)
            ->json('data.issues');

        $this->assertEqualsCanonicalizing(['purpose_missing', 'instructions_missing'], array_column($issues, 'code'));

        $this->publish($member, $profile['id'], 1)
            ->assertUnprocessable()
            ->assertJsonPath('errors.issues.0.code', 'purpose_missing');

        $this->assertDatabaseCount('agent_profile_versions', 0);
    }

    #[Test]
    public function publishing_creates_an_immutable_inactive_version_with_a_verifiable_manifest(): void
    {
        Event::fake([AgentProfileVersionPublished::class]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/readiness")->assertJsonPath('data.ready', true);

        $this->publish($member, $profile['id'], 1)
            ->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.source_revision', 1)
            ->assertJsonPath('data.profile_id', $profile['id'])
            ->assertJsonPath('data.definition.purpose', 'Answer billing questions for customers.')
            ->assertJsonPath('data.published_by.id', (string) $member->getKey());

        $version = AgentProfileVersion::query()->firstOrFail();
        $this->assertSame($version->content_hash, Canonical::hash($version->content));
        $this->assertSame('whilesmart.agent-profile/1', $version->content['schema']);
        $this->assertSame(['managed' => [], 'external' => []], $version->content['skills']);
        Event::assertDispatched(AgentProfileVersionPublished::class);
    }

    #[Test]
    public function later_draft_edits_do_not_touch_published_versions(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $this->publish($member, $profile['id'], 1)->assertCreated();

        $this->actingAs($member)->putJson("/api/agent-profiles/{$profile['id']}", [
            'revision' => 1,
            'definition' => $this->readyDefinition(['purpose' => 'Handle refunds.']),
        ])->assertOk();

        $this->publish($member, $profile['id'], 2)->assertCreated()->assertJsonPath('data.number', 2);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/versions/1")
            ->assertOk()
            ->assertJsonPath('data.definition.purpose', 'Answer billing questions for customers.');

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/versions")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.data.0.number', 2)
            ->assertJsonPath('data.data.0.definition.purpose', 'Handle refunds.');
    }

    #[Test]
    public function a_stale_revision_cannot_publish(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $this->actingAs($member)->putJson("/api/agent-profiles/{$profile['id']}", ['revision' => 1, 'name' => 'Edited'])->assertOk();

        $this->publish($member, $profile['id'], 1)->assertStatus(409);
        $this->assertDatabaseCount('agent_profile_versions', 0);
    }

    #[Test]
    public function versions_reject_updates_and_deletes(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $this->publish($member, $profile['id'], 1)->assertCreated();
        $version = AgentProfileVersion::query()->firstOrFail();

        try {
            $version->update(['content' => ['definition' => ['purpose' => 'Changed']]]);
            $this->fail('A version was updated.');
        } catch (ImmutableVersion) {
        }

        try {
            $version->delete();
            $this->fail('A version was deleted.');
        } catch (ImmutableVersion) {
        }

        $this->assertSame('Answer billing questions for customers.', $version->fresh()->content['definition']['purpose']);
    }

    #[Test]
    public function an_unknown_version_number_is_not_found(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/versions/9")->assertNotFound();
        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/versions/abc")->assertNotFound();
    }
}
