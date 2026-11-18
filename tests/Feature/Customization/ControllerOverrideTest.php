<?php

namespace Tests\Feature\Customization;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountingController;
use Tests\TestCase;

class ControllerOverrideTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('agent-profiles.controller', CountingController::class);
    }

    #[Test]
    public function a_configured_controller_serves_the_package_routes(): void
    {
        CountingController::$shows = 0;
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);

        $this->actingAs($member)->getJson("/api/agent-profiles/{$profile['id']}")->assertOk();

        $this->assertSame(1, CountingController::$shows);
    }
}
