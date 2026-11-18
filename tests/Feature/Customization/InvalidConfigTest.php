<?php

namespace Tests\Feature\Customization;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Whilesmart\AgentProfiles\AgentProfilesServiceProvider;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;
use Whilesmart\Skills\SkillsServiceProvider;

class InvalidConfigTest extends BaseTestCase
{
    private ?InvalidArgumentException $bootError = null;

    protected function getPackageProviders($app): array
    {
        return [OwnerAccessServiceProvider::class, SkillsServiceProvider::class, AgentProfilesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('agent-profiles.route_middleware', ['api']);
        $app['config']->set('agent-profiles.external_skills.providers', ['remote' => \stdClass::class]);
    }

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (InvalidArgumentException $e) {
            $this->bootError = $e;
        }
    }

    #[Test]
    public function a_configured_class_of_the_wrong_type_fails_with_a_clear_error(): void
    {
        $this->assertNotNull($this->bootError);
        $this->assertStringContainsString('agent-profiles.external_skills.providers.remote must be a class that is or extends', $this->bootError->getMessage());
    }
}
