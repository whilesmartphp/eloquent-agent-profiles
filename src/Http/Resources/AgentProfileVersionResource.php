<?php

namespace Whilesmart\AgentProfiles\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;

/**
 * @mixin AgentProfileVersion
 */
class AgentProfileVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = $this->state;

        return [
            'id' => $this->public_id,
            'profile_id' => $this->manifest['profile']['public_id'] ?? null,
            'number' => (int) $this->number,
            'status' => ($state?->status ?? VersionStatus::Inactive)->value,
            'source_revision' => (int) $this->source_revision,
            'manifest_hash' => $this->manifest_hash,
            'definition' => $this->definition,
            'rules' => $this->manifest['rules'] ?? null,
            'skills' => $this->skills,
            'published_by' => $this->published_by_type ? ['type' => $this->published_by_type, 'id' => $this->published_by_id] : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'activated_at' => $state?->activated_at?->toIso8601String(),
            'deactivated_at' => $state?->deactivated_at?->toIso8601String(),
            'revoked_at' => $state?->revoked_at?->toIso8601String(),
            'revocation_reason' => $state?->revocation_reason,
        ];
    }
}
