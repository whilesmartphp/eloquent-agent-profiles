<?php

namespace Whilesmart\AgentProfiles\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Events\AgentProfileSkillsChanged;
use Whilesmart\AgentProfiles\Exceptions\StaleRevision;
use Whilesmart\AgentProfiles\Services\ProfileGate;
use Whilesmart\Skills\Models\Skill;
use Whilesmart\Skills\Models\SkillBinding;
use Whilesmart\Skills\Models\SkillVersion;
use Whilesmart\Skills\Traits\HasSkills;

/**
 * @property int $id
 * @property string $public_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $scope_key
 * @property string $name
 * @property ?string $description
 * @property int $draft_revision
 * @property array<string, mixed> $draft_definition
 * @property ?array<string, mixed> $metadata
 */
class AgentProfile extends Model
{
    use HasSkills {
        assignSkillVersion as protected assignPinnedSkillVersion;
        detachSkill as protected detachPinnedSkill;
    }
    use SoftDeletes;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'scope_key',
        'name',
        'description',
        'draft_definition',
        'metadata',
    ];

    protected $attributes = [
        'draft_revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'string',
            'draft_revision' => 'integer',
            'draft_definition' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.profiles', 'agent_profiles');
    }

    protected static function booted(): void
    {
        static::creating(function (AgentProfile $profile) {
            $profile->public_id = $profile->public_id ?: (string) Str::uuid();
            $profile->scope_key = $profile->scope_key ?: (string) config('agent-profiles.default_scope_key', 'default');
            $profile->draft_definition = $profile->draft_definition ?? [];
        });
    }

    public function assignSkillVersion(SkillVersion $version, ?Authenticatable $actor = null): SkillBinding
    {
        return DB::transaction(function () use ($version, $actor) {
            $locked = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            app(ProfileGate::class)->ensure($actor, 'bind-skills', $locked);
            if ($locked->draft_revision !== $this->draft_revision) {
                throw new StaleRevision($this->draft_revision, $locked->draft_revision);
            }
            $binding = $locked->assignPinnedSkillVersion($version, $actor);
            $locked->draft_revision++;
            $locked->save();
            $this->draft_revision = $locked->draft_revision;
            event(new AgentProfileSkillsChanged($locked, $actor));

            return $binding;
        });
    }

    public function detachSkill(Skill $skill, ?Authenticatable $actor = null): bool
    {
        return DB::transaction(function () use ($skill, $actor) {
            $locked = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            app(ProfileGate::class)->ensure($actor, 'bind-skills', $locked);
            if ($locked->draft_revision !== $this->draft_revision) {
                throw new StaleRevision($this->draft_revision, $locked->draft_revision);
            }
            $removed = $locked->detachPinnedSkill($skill, $actor);
            if ($removed) {
                $locked->draft_revision++;
                $locked->save();
                $this->draft_revision = $locked->draft_revision;
                event(new AgentProfileSkillsChanged($locked, $actor));
            }

            return $removed;
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(config('agent-profiles.models.version', AgentProfileVersion::class), 'agent_profile_id');
    }

    public function versionStates(): HasMany
    {
        return $this->hasMany(config('agent-profiles.models.version_state', AgentProfileVersionState::class), 'agent_profile_id');
    }

    public function activeVersion(): ?AgentProfileVersion
    {
        $state = $this->versionStates()->where('status', VersionStatus::Active->value)->first();

        return $state ? $this->versions()->whereKey($state->agent_profile_version_id)->first() : null;
    }

    public function scopeForOwner(Builder $query, string $ownerType, int|string $ownerId): Builder
    {
        return $query->where('owner_type', $ownerType)->where('owner_id', (string) $ownerId);
    }

    public function scopeInScope(Builder $query, string $scopeKey): Builder
    {
        return $query->where('scope_key', $scopeKey);
    }
}
