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

    private const LIFECYCLE = ['status', 'revoked_at', 'revocation_reason', 'revoked_by_type', 'revoked_by_id'];

    protected $attributes = ['status' => 'inactive'];

    protected $fillable = ['agent_profile_id', 'number', 'source_revision', 'content', 'content_hash', 'published_by_type', 'published_by_id', 'published_at'];

    protected function casts(): array
    {
        return ['status' => VersionStatus::class, 'number' => 'integer', 'source_revision' => 'integer', 'content' => 'array', 'published_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.versions', 'agent_profile_versions');
    }

    protected static function booted(): void
    {
        static::creating(function (AgentProfileVersion $version) {
            if ($version->status !== VersionStatus::Inactive || $version->revoked_at !== null) {
                throw ImmutableVersion::make();
            }
            $version->uuid = $version->uuid ?: (string) Str::uuid();
        });
        static::updating(function (AgentProfileVersion $version) {
            if ($version->getRawOriginal('status') === VersionStatus::Revoked->value
                || $version->getRawOriginal('revoked_at') !== null
                || array_diff(array_keys($version->getDirty()), self::LIFECYCLE) !== []) {
                throw ImmutableVersion::make();
            }
            if ($version->status === VersionStatus::Revoked) {
                if ($version->revoked_at === null) {
                    throw ImmutableVersion::make();
                }
            } elseif ($version->isDirty(['revoked_at', 'revocation_reason', 'revoked_by_type', 'revoked_by_id'])) {
                throw ImmutableVersion::make();
            }
        });
        static::deleting(fn () => throw ImmutableVersion::make());
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(config('agent-profiles.models.profile', AgentProfile::class), 'agent_profile_id')->withTrashed();
    }

    public function hasIntactContent(): bool
    {
        return hash_equals($this->content_hash, Canonical::hash($this->content));
    }
}
