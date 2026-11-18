<?php

namespace Whilesmart\AgentProfiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Exceptions\ImmutableVersion;
use Whilesmart\AgentProfiles\Support\Canonical;

/**
 * An immutable published snapshot. Activation and revocation live in AgentProfileVersionState.
 *
 * @property int $id
 * @property string $public_id
 * @property int $agent_profile_id
 * @property int $number
 * @property int $source_revision
 * @property array<string, mixed> $definition
 * @property array<string, mixed> $skills
 * @property array<string, mixed> $manifest
 * @property string $manifest_hash
 * @property ?array<string, mixed> $snapshots
 * @property ?string $published_by_type
 * @property ?string $published_by_id
 */
class AgentProfileVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_profile_id',
        'number',
        'source_revision',
        'definition',
        'skills',
        'manifest',
        'manifest_hash',
        'snapshots',
        'published_by_type',
        'published_by_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'source_revision' => 'integer',
            'definition' => 'array',
            'skills' => 'array',
            'manifest' => 'array',
            'snapshots' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.versions', 'agent_profile_versions');
    }

    protected static function booted(): void
    {
        static::creating(function (AgentProfileVersion $version) {
            $version->public_id = $version->public_id ?: (string) Str::uuid();
        });

        static::updating(fn () => throw ImmutableVersion::make());
        static::deleting(fn () => throw ImmutableVersion::make());
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(config('agent-profiles.models.profile', AgentProfile::class), 'agent_profile_id')->withTrashed();
    }

    public function state(): HasOne
    {
        return $this->hasOne(config('agent-profiles.models.version_state', AgentProfileVersionState::class), 'agent_profile_version_id');
    }

    public function status(): VersionStatus
    {
        $status = $this->state()->value('status');

        return $status instanceof VersionStatus ? $status : VersionStatus::Inactive;
    }

    /**
     * Current availability: the profile is not deleted, this version is active and its
     * stored manifest still matches its hash.
     */
    public function isAvailable(): bool
    {
        $profile = $this->profile()->first();

        return $profile !== null
            && ! $profile->trashed()
            && $this->status() === VersionStatus::Active
            && $this->hasIntactManifest();
    }

    public function hasIntactManifest(): bool
    {
        return hash_equals($this->manifest_hash, Canonical::hash($this->manifest));
    }
}
