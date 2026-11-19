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
use Whilesmart\AgentProfiles\Events\AgentProfileSkillsChanged;
use Whilesmart\AgentProfiles\Exceptions\ImmutableVersion;
use Whilesmart\AgentProfiles\Exceptions\StaleRevision;
use Whilesmart\AgentProfiles\Services\ProfileGate;
use Whilesmart\Skills\Models\Skill;
use Whilesmart\Skills\Models\SkillBinding;
use Whilesmart\Skills\Models\SkillVersion;
use Whilesmart\Skills\Traits\HasSkills;

/**
 * @property int $id
 * @property string $uuid
 * @property string $owner_type
 * @property string $owner_id
 * @property string $scope_key
 * @property string $name
 * @property ?string $description
 * @property int $revision
 * @property array<string, mixed> $definition
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
        'definition',
        'metadata',
    ];

    protected $attributes = [
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'string',
            'revision' => 'integer',
            'definition' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.profiles', 'agent_profiles');
    }

    protected static function booted(): void
    {
        static::updating(function (AgentProfile $profile) {
            if ($profile->isDirty(['owner_type', 'owner_id', 'scope_key', 'uuid'])) {
                throw ImmutableVersion::make();
            }
        });

        static::creating(function (AgentProfile $profile) {
            $profile->uuid = $profile->uuid ?: (string) Str::uuid();
            $profile->slug = $profile->slug ?: (Str::slug($profile->name) ?: 'agent').'-'.$profile->uuid;
            $profile->scope_key = $profile->scope_key ?: (string) config('agent-profiles.default_scope_key', 'default');
            $profile->definition = $profile->definition ?? [];
        });
    }

    public function assignSkillVersion(SkillVersion $version, ?Authenticatable $actor = null): SkillBinding
    {
        return DB::transaction(function () use ($version, $actor) {
            $locked = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            app(ProfileGate::class)->ensure($actor, 'bind-skills', $locked);
            if ($locked->revision !== $this->revision) {
                throw new StaleRevision($this->revision, $locked->revision);
            }
            $previous = $locked->skillBindings()->where('skill_id', $version->skill_id)->value('skill_version_id');
            $binding = $locked->assignPinnedSkillVersion($version, $actor);
            if ((string) $previous === (string) $binding->skill_version_id) {
                return $binding;
            }
            $locked->revision++;
            $locked->save();
            $this->revision = $locked->revision;
            event(new AgentProfileSkillsChanged($locked, $actor));

            return $binding;
        });
    }

    public function detachSkill(Skill $skill, ?Authenticatable $actor = null): bool
    {
        return DB::transaction(function () use ($skill, $actor) {
            $locked = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            app(ProfileGate::class)->ensure($actor, 'bind-skills', $locked);
            if ($locked->revision !== $this->revision) {
                throw new StaleRevision($this->revision, $locked->revision);
            }
            $removed = $locked->detachPinnedSkill($skill, $actor);
            if ($removed) {
                $locked->revision++;
                $locked->save();
                $this->revision = $locked->revision;
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

    public function activeVersion(): ?AgentProfileVersion
    {
        return $this->active_version_id === null ? null : $this->versions()->whereKey($this->active_version_id)->whereNull('revoked_at')->first();
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
