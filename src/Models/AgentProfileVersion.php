<?php

namespace Whilesmart\AgentProfiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Exceptions\ImmutableVersion;
use Whilesmart\AgentProfiles\Support\Canonical;

class AgentProfileVersion extends Model
{
    public const UPDATED_AT = null;

    private const REVOCATION = ['revoked_at', 'revocation_reason', 'revoked_by_type', 'revoked_by_id'];

    protected $fillable = ['agent_profile_id', 'number', 'source_revision', 'content', 'content_hash', 'published_by_type', 'published_by_id', 'published_at'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'source_revision' => 'integer', 'content' => 'array', 'published_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.versions', 'agent_profile_versions');
    }

    protected static function booted(): void
    {
        static::creating(function (AgentProfileVersion $version) {
            $version->uuid = $version->uuid ?: (string) Str::uuid();
        });
        static::updating(function (AgentProfileVersion $version) {
            if ($version->getRawOriginal('revoked_at') !== null
                || ! $version->isDirty('revoked_at') || $version->revoked_at === null
                || array_diff(array_keys($version->getDirty()), self::REVOCATION) !== []) {
                throw ImmutableVersion::make();
            }
        });
        static::deleting(fn () => throw ImmutableVersion::make());
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(config('agent-profiles.models.profile', AgentProfile::class), 'agent_profile_id')->withTrashed();
    }

    public function status(): VersionStatus
    {
        if ($this->revoked_at !== null) {
            return VersionStatus::Revoked;
        }
        $profile = $this->profile()->first();

        return $profile && (string) $profile->active_version_id === (string) $this->getKey() ? VersionStatus::Active : VersionStatus::Inactive;
    }

    public function hasIntactContent(): bool
    {
        return hash_equals($this->content_hash, Canonical::hash($this->content));
    }
}
