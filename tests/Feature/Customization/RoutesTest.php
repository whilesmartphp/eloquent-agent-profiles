<?php

namespace Tests\Feature\Customization;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadOnlyMiddleware;
use Tests\TestCase;

class RoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('agent-profiles.route_groups.lifecycle', false);
        $app['config']->set('agent-profiles.disabled_routes', ['agent-profiles.destroy']);
        $app['config']->set('agent-profiles.write_middleware', [ReadOnlyMiddleware::class]);
        $app['config']->set('agent-profiles.route_prefix', 'v2');
    }

    #[Test]
    public function groups_and_single_routes_can_be_turned_off_and_writes_guarded(): void
    {
        $member = $this->memberOf(1);

        $this->assertFalse(Route::has('agent-profiles.versions.activate'));
        $this->assertFalse(Route::has('agent-profiles.versions.manifest'));
        $this->assertFalse(Route::has('agent-profiles.destroy'));
        $this->assertTrue(Route::has('agent-profiles.publish'));

        $this->actingAs($member)->postJson('/v2/agent-profiles', $this->profilePayload())->assertStatus(423);
        $this->actingAs($member)->getJson('/v2/agent-profiles')->assertOk();
        $this->actingAs($member)->getJson('/api/agent-profiles')->assertNotFound();
    }
}
