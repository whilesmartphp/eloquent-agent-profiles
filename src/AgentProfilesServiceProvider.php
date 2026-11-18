<?php

namespace Whilesmart\AgentProfiles;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Contracts\ExternalSkillProvider;
use Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer;
use Whilesmart\AgentProfiles\Contracts\ResponseFormatter;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Definition\RuleCompiler;
use Whilesmart\AgentProfiles\Http\Controllers\AgentProfileController;
use Whilesmart\AgentProfiles\Http\Requests\BindProfileSkillRequest;
use Whilesmart\AgentProfiles\Http\Requests\PublishAgentProfileRequest;
use Whilesmart\AgentProfiles\Http\Requests\RevokeProfileVersionRequest;
use Whilesmart\AgentProfiles\Http\Requests\StoreAgentProfileRequest;
use Whilesmart\AgentProfiles\Http\Requests\UnbindProfileSkillRequest;
use Whilesmart\AgentProfiles\Http\Requests\UpdateAgentProfileRequest;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Models\AgentProfileVersionState;

class AgentProfilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-profiles.php', 'agent-profiles');

        $this->app->singleton(DefinitionSchema::class, fn () => new DefinitionSchema((array) config('agent-profiles.definition', [])));
        $this->app->singleton(RuleCompiler::class);

        $this->app->bindIf(ProfileAuthorizer::class, fn ($app) => $app->make($this->configured('authorizer', ProfileAuthorizer::class)));
        $this->app->bindIf(ResponseFormatter::class, fn ($app) => $app->make($this->configured('response_formatter', ResponseFormatter::class)));
    }

    public function boot(): void
    {
        if (config('agent-profiles.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/agent-profiles.php' => config_path('agent-profiles.php'),
        ], 'agent-profiles-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'agent-profiles-migrations');

        $this->validateExtensions();

        if (config('agent-profiles.register_routes', true)) {
            $this->validateHttpClasses();

            $model = config('agent-profiles.models.profile');
            Route::bind('agent_profile', fn (string $value) => $model::query()->where('public_id', $value)->firstOrFail());

            Route::middleware(config('agent-profiles.route_middleware', ['api', 'auth:sanctum']))
                ->prefix(config('agent-profiles.route_prefix', 'api'))
                ->group(__DIR__.'/../routes/api.php');
        }
    }

    protected function validateExtensions(): void
    {
        $this->configured('models.profile', AgentProfile::class);
        $this->configured('models.version', AgentProfileVersion::class);
        $this->configured('models.version_state', AgentProfileVersionState::class);
        $this->configured('authorizer', ProfileAuthorizer::class);

        foreach ((array) config('agent-profiles.validators', []) as $index => $class) {
            $this->configured("validators.{$index}", DefinitionValidator::class);
        }

        foreach ((array) config('agent-profiles.external_skills.providers', []) as $source => $class) {
            if ($source === DefinitionSchema::MANAGED_SOURCE) {
                throw new InvalidArgumentException('agent-profiles.external_skills.providers cannot use the reserved source key managed.');
            }
            $this->configured("external_skills.providers.{$source}", ExternalSkillProvider::class);
        }
    }

    protected function validateHttpClasses(): void
    {
        $this->configured('requests.store', StoreAgentProfileRequest::class);
        $this->configured('requests.update', UpdateAgentProfileRequest::class);
        $this->configured('requests.publish', PublishAgentProfileRequest::class);
        $this->configured('requests.bind_skill', BindProfileSkillRequest::class);
        $this->configured('requests.unbind_skill', UnbindProfileSkillRequest::class);
        $this->configured('requests.revoke', RevokeProfileVersionRequest::class);
        $this->configured('resources.profile', JsonResource::class);
        $this->configured('resources.version', JsonResource::class);
        $this->configured('resources.skill', JsonResource::class);
        $this->configured('response_formatter', ResponseFormatter::class);
        $this->configured('controller', AgentProfileController::class);
    }

    /**
     * The configured class for $key, which must be or extend $base.
     */
    protected function configured(string $key, string $base): string
    {
        $class = config("agent-profiles.{$key}");

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, $base, true)) {
            throw new InvalidArgumentException("agent-profiles.{$key} must be a class that is or extends {$base}.");
        }

        return $class;
    }
}
