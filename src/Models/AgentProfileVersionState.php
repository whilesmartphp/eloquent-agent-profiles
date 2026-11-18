<?php

namespace Whilesmart\AgentProfiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Whilesmart\AgentProfiles\Enums\VersionStatus;

/**
 * @property int $agent_profile_version_id
 * @property int $agent_profile_id
 * @property VersionStatus $status
 * @property ?string $revocation_reason
 */
class AgentProfileVersionState extends Model
{
    protected $fillable = [
        'agent_profile_version_id',
        'agent_profile_id',
        'status',
        'activated_at',
        'deactivated_at',
        'revoked_at',
        'revocation_reason',
        'changed_by_type',
        'changed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return (string) config('agent-profiles.tables.version_states', 'agent_profile_version_states');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(config('agent-profiles.models.version', AgentProfileVersion::class), 'agent_profile_version_id');
    }
}
