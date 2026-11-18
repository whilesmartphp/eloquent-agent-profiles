<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InlineSkillProvider;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Services\ExecutionManifestResolver;

class ExternalSkillTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function definitionWith(string $hash): array
    {
        return $this->readyDefinition(['skills' => [[
            'source' => 'house',
            'reference' => 'house-style',
            'skill' => 'release-notes',
            'content_hash' => $hash,
        ]]]);
    }

    #[Test]
    public function external_sources_are_denied_until_the_host_configures_a_provider(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => $this->definitionWith('sha256:'.str_repeat('0', 64))]);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/readiness")
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'external_source_not_configured');
    }

    #[Test]
    public function an_approved_hash_is_pinned_and_snapshotted_so_resolution_never_fetches(): void
    {
        config(['agent-profiles.external_skills.providers' => ['house' => InlineSkillProvider::class]]);
        InlineSkillProvider::$loads = 0;
        $member = $this->memberOf(1);
        $hash = InlineSkillProvider::hash('house-style', 'release-notes');
        $profile = $this->createProfile($member, ['definition' => $this->definitionWith($hash)]);

        $this->publish($member, $profile['id'], 1, activate: true)
            ->assertCreated()
            ->assertJsonPath('data.skills.external.0.name', 'release-notes')
            ->assertJsonPath('data.skills.external.0.content_hash', $hash);

        $loads = InlineSkillProvider::$loads;
        $manifest = app(ExecutionManifestResolver::class)->resolve(AgentProfileVersion::query()->firstOrFail(), $member);

        $this->assertSame($loads, InlineSkillProvider::$loads);
        $this->assertSame('release-notes', $manifest->skills->get('release-notes')->name());
        $this->assertSame("Plain words, short sentences.\n", $manifest->skills->get('release-notes')->reference('tone'));
    }

    #[Test]
    public function a_changed_external_skill_does_not_publish(): void
    {
        config(['agent-profiles.external_skills.providers' => ['house' => InlineSkillProvider::class]]);
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member, ['definition' => $this->definitionWith('sha256:'.str_repeat('f', 64))]);

        $this->publish($member, $profile['id'], 1)
            ->assertUnprocessable()
            ->assertJsonPath('errors.issues.0.code', 'external_skill_hash_mismatch');
    }

    #[Test]
    public function a_reference_the_provider_refuses_is_unavailable(): void
    {
        config(['agent-profiles.external_skills.providers' => ['house' => InlineSkillProvider::class]]);
        $member = $this->memberOf(1);
        $definition = $this->definitionWith('sha256:'.str_repeat('a', 64));
        $definition['skills'][0]['reference'] = 'somewhere-else';
        $profile = $this->createProfile($member, ['definition' => $definition]);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}/readiness")
            ->assertJsonPath('data.issues.0.code', 'external_skill_unavailable');
    }
}
