<?php

use Whilesmart\AgentProfiles\Authorization\OwnerAccessProfileAuthorizer;
use Whilesmart\AgentProfiles\Http\Controllers\AgentProfileController;
use Whilesmart\AgentProfiles\Http\Requests\BindProfileSkillRequest;
use Whilesmart\AgentProfiles\Http\Requests\PublishAgentProfileRequest;
use Whilesmart\AgentProfiles\Http\Requests\RevokeProfileVersionRequest;
use Whilesmart\AgentProfiles\Http\Requests\StoreAgentProfileRequest;
use Whilesmart\AgentProfiles\Http\Requests\UnbindProfileSkillRequest;
use Whilesmart\AgentProfiles\Http\Requests\UpdateAgentProfileRequest;
use Whilesmart\AgentProfiles\Http\Resources\AgentProfileResource;
use Whilesmart\AgentProfiles\Http\Resources\AgentProfileVersionResource;
use Whilesmart\AgentProfiles\Http\Resources\ProfileSkillResource;
use Whilesmart\AgentProfiles\Http\Responses\DefaultResponseFormatter;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Models\AgentProfileVersionState;
use Whilesmart\AgentProfiles\Validators\ModelProviderValidator;
use Whilesmart\AgentProfiles\Validators\ResourceTypeValidator;
use Whilesmart\AgentProfiles\Validators\RuleEvaluatorValidator;
use Whilesmart\AgentProfiles\Validators\ToolCapabilityValidator;

return [
    'register_routes' => env('AGENT_PROFILES_REGISTER_ROUTES', true),
    'route_prefix' => env('AGENT_PROFILES_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],

    // Extra middleware on routes that change data.
    'write_middleware' => [],

    // Route groups the package registers. Turn one off to keep its routes out.
    'route_groups' => [
        'profiles' => true,
        'skills' => true,
        'versions' => true,
        'lifecycle' => true,
    ],

    // Route names to leave unregistered, for example 'agent-profiles.destroy'.
    'disabled_routes' => [],

    'run_migrations' => env('AGENT_PROFILES_RUN_MIGRATIONS', true),

    'tables' => [
        'profiles' => env('AGENT_PROFILES_TABLE', 'agent_profiles'),
        'versions' => env('AGENT_PROFILE_VERSIONS_TABLE', 'agent_profile_versions'),
        'version_states' => env('AGENT_PROFILE_VERSION_STATES_TABLE', 'agent_profile_version_states'),
    ],

    'default_scope_key' => 'default',

    'models' => [
        'profile' => AgentProfile::class,
        'version' => AgentProfileVersion::class,
        'version_state' => AgentProfileVersionState::class,
    ],

    'requests' => [
        'store' => StoreAgentProfileRequest::class,
        'update' => UpdateAgentProfileRequest::class,
        'publish' => PublishAgentProfileRequest::class,
        'bind_skill' => BindProfileSkillRequest::class,
        'unbind_skill' => UnbindProfileSkillRequest::class,
        'revoke' => RevokeProfileVersionRequest::class,
    ],

    'resources' => [
        'profile' => AgentProfileResource::class,
        'version' => AgentProfileVersionResource::class,
        'skill' => ProfileSkillResource::class,
    ],

    'response_formatter' => DefaultResponseFormatter::class,

    'controller' => AgentProfileController::class,

    // Implements Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer. The default delegates to a strict
    // OwnerAuthorizer the host binds and denies everything while only the allow-all default is bound.
    'authorizer' => OwnerAccessProfileAuthorizer::class,

    // Classes implementing Whilesmart\AgentProfiles\Contracts\DefinitionValidator, run on readiness and publication.
    'validators' => [
        ToolCapabilityValidator::class,
        RuleEvaluatorValidator::class,
        ResourceTypeValidator::class,
        ModelProviderValidator::class,
    ],

    // Tool names a definition may grant in its permission ceiling. Empty grants none.
    'capabilities' => [
        'tools' => [],
    ],

    // Evaluator names the host can run for structured rules. Empty accepts no rules.
    'rule_evaluators' => [],

    // Resource types the host can resolve. Empty accepts no resources.
    'resource_types' => [],

    // Model providers a definition may name. Empty accepts only the host default (no provider).
    'model_providers' => [],

    'definition' => [
        'max_bytes' => 65536,
        'max_text' => 20000,
        'max_short_text' => 2000,
        'max_rules' => 100,
        'max_skills' => 50,
        'max_resources' => 100,
        'max_tools' => 200,
        'max_rule_config_entries' => 50,
        'max_fallback_models' => 5,
        'limits' => [
            'max_steps' => 1000,
            'max_tool_calls' => 10000,
            'max_input_tokens' => 2000000,
            'max_output_tokens' => 1000000,
            'max_duration_seconds' => 86400,
        ],
    ],

    'max_metadata_bytes' => 16384,

    // External skills are pinned by approved content hash and snapshotted at publication.
    // Map a source key to a class implementing Whilesmart\AgentProfiles\Contracts\ExternalSkillProvider.
    'external_skills' => [
        'providers' => [],
        'max_snapshot_bytes' => 1048576,
    ],
];
