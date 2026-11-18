<?php

namespace Tests\Feature\Customization;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BareFormatter;
use Tests\Support\CompactProfileResource;
use Tests\TestCase;

class ResourceAndFormatterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('agent-profiles.resources.profile', CompactProfileResource::class);
        $app['config']->set('agent-profiles.response_formatter', BareFormatter::class);
    }

    #[Test]
    public function configured_resources_and_formatter_shape_responses(): void
    {
        $member = $this->memberOf(1);

        $created = $this->actingAs($member)->postJson('/api/agent-profiles', $this->profilePayload())
            ->assertCreated()
            ->assertJsonPath('label', 'Billing assistant')
            ->assertJsonMissingPath('success');

        $this->actingAs($member)->putJson("/api/agent-profiles/{$created->json('ref')}", ['revision' => 7, 'name' => 'Stale'])
            ->assertStatus(409)
            ->assertJsonStructure(['error']);
    }
}
