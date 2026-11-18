<?php

namespace Whilesmart\AgentProfiles\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Events\AgentProfileCreated;
use Whilesmart\AgentProfiles\Events\AgentProfileDeleted;
use Whilesmart\AgentProfiles\Events\AgentProfileDraftUpdated;
use Whilesmart\AgentProfiles\Exceptions\InvalidDefinition;
use Whilesmart\AgentProfiles\Exceptions\StaleRevision;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

/**
 * Draft writes. Every change to a draft, including its skill bindings, must name the
 * revision it was based on and moves the revision forward.
 */
class ProfileManager
{
    public function __construct(
        protected ProfileGate $gate,
        protected DefinitionSchema $schema,
        protected ManagedSkills $skills,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Authenticatable $actor): AgentProfile
    {
        $scopeKey = (string) ($attributes['scope_key'] ?? config('agent-profiles.default_scope_key', 'default'));
        $this->gate->ensureOwner($actor, 'create', (string) $attributes['owner_type'], (string) $attributes['owner_id'], $scopeKey);

        $model = $this->profileModel();
        $profile = new $model;
        $profile->fill([
            'owner_type' => (string) $attributes['owner_type'],
            'owner_id' => (string) $attributes['owner_id'],
            'scope_key' => $scopeKey,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'definition' => $this->definition($attributes['definition'] ?? []),
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        DB::transaction(function () use ($profile, $actor) {
            $profile->save();
            event(new AgentProfileCreated($profile, $actor));
        });

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AgentProfile $profile, int $expectedRevision, array $attributes, ?Authenticatable $actor): AgentProfile
    {
        $this->gate->ensure($actor, 'update', $profile);

        $changes = array_intersect_key($attributes, array_flip(['name', 'description', 'metadata']));
        if (array_key_exists('definition', $attributes)) {
            $changes['definition'] = $this->definition($attributes['definition'] ?? []);
        }

        return DB::transaction(function () use ($profile, $expectedRevision, $changes, $actor) {
            $locked = $this->lock($profile, $expectedRevision);
            $locked->fill($changes);
            $locked->revision = $locked->revision + 1;
            $locked->save();

            event(new AgentProfileDraftUpdated($locked, $actor));

            return $locked;
        });
    }

    public function delete(AgentProfile $profile, ?Authenticatable $actor): void
    {
        $this->gate->ensure($actor, 'delete', $profile);

        DB::transaction(function () use ($profile, $actor) {
            $profile->delete();
            event(new AgentProfileDeleted($profile, $actor));
        });
    }

    public function bindSkill(AgentProfile $profile, int $expectedRevision, string $versionUuid, ?Authenticatable $actor): AgentProfile
    {
        $this->gate->ensure($actor, 'bind-skills', $profile);

        return DB::transaction(function () use ($profile, $expectedRevision, $versionUuid, $actor) {
            $locked = $this->lock($profile, $expectedRevision);
            $this->skills->bind($locked, $versionUuid, $actor);

            return $locked->refresh();
        });
    }

    public function unbindSkill(AgentProfile $profile, int $expectedRevision, string $skillUuid, ?Authenticatable $actor): AgentProfile
    {
        $this->gate->ensure($actor, 'bind-skills', $profile);

        return DB::transaction(function () use ($profile, $expectedRevision, $skillUuid, $actor) {
            $locked = $this->lock($profile, $expectedRevision);

            $this->skills->unbind($locked, $skillUuid, $actor);

            return $locked->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(mixed $definition): array
    {
        $definition ??= [];
        $issues = $this->schema->validate($definition);

        if (Issue::anyErrors($issues)) {
            throw InvalidDefinition::because($issues);
        }

        return $this->schema->normalize($definition);
    }

    protected function lock(AgentProfile $profile, int $expectedRevision): AgentProfile
    {
        /** @var AgentProfile $locked */
        $locked = $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->revision !== $expectedRevision) {
            throw new StaleRevision($expectedRevision, $locked->revision);
        }

        return $locked;
    }

    /**
     * @return class-string<AgentProfile>
     */
    protected function profileModel(): string
    {
        return config('agent-profiles.models.profile', AgentProfile::class);
    }
}
