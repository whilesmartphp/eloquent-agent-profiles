<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\Support\GrantingOwnerAuthorizer;
use Tests\Support\IntegerUser;
use Tests\Support\SkillCatalogue;
use Tests\Support\UuidUser;
use Whilesmart\AgentProfiles\AgentProfilesServiceProvider;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;
use Whilesmart\Skills\SkillsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const OWNER = 'workspace';

    protected GrantingOwnerAuthorizer $owners;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owners = new GrantingOwnerAuthorizer;
        $this->app->instance(OwnerAuthorizer::class, $this->owners);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            OwnerAccessServiceProvider::class,
            SkillsServiceProvider::class,
            AgentProfilesServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('agent-profiles.route_middleware', ['api']);
        $app['config']->set('skills.route_middleware', ['api']);
        $app['config']->set('cache.default', 'array');
    }

    protected function memberOf(int|string $ownerId = 1, string $ownerType = self::OWNER): IntegerUser
    {
        $user = IntegerUser::query()->create(['name' => 'Member']);
        $this->owners->grant($user, $ownerType, $ownerId);

        return $user;
    }

    public function catalogueActor(string $ownerType, int|string $ownerId): IntegerUser
    {
        return $this->memberOf($ownerId, $ownerType);
    }

    protected function catalogue(): SkillCatalogue
    {
        return new SkillCatalogue($this);
    }

    protected function uuidMemberOf(int|string $ownerId, string $ownerType = self::OWNER): UuidUser
    {
        $user = UuidUser::query()->create(['name' => 'Member']);
        $this->owners->grant($user, $ownerType, $ownerId);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function profilePayload(array $overrides = []): array
    {
        return $overrides + [
            'owner_type' => self::OWNER,
            'owner_id' => 1,
            'name' => 'Billing assistant',
            'definition' => $this->readyDefinition(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function readyDefinition(array $extra = []): array
    {
        return $extra + [
            'purpose' => 'Answer billing questions for customers.',
            'personality' => 'Calm and exact.',
            'instructions' => 'Quote amounts from the invoice and never guess.',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function createProfile(Authenticatable $actor, array $overrides = []): array
    {
        return $this->actingAs($actor)
            ->postJson('/api/agent-profiles', $this->profilePayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    protected function publish(Authenticatable $actor, string $profileId, int $revision, bool $activate = false): TestResponse
    {
        return $this->actingAs($actor)->postJson("/api/agent-profiles/{$profileId}/publish", [
            'revision' => $revision,
            'activate' => $activate,
        ]);
    }
}
