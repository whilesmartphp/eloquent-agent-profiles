<?php

namespace Whilesmart\AgentProfiles\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Whilesmart\AgentProfiles\Models\AgentProfile;

/**
 * @mixin AgentProfile
 */
class AgentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'owner_type' => $this->owner_type,
            'owner_id' => (string) $this->owner_id,
            'scope_key' => $this->scope_key,
            'name' => $this->name,
            'description' => $this->description,
            'revision' => (int) $this->draft_revision,
            'definition' => $this->draft_definition,
            'metadata' => $this->metadata,
            'active_version' => $this->activeVersion()?->number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
