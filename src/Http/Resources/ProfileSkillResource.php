<?php

namespace Whilesmart\AgentProfiles\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps one binding row produced by ManagedSkills::bindings().
 */
class ProfileSkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;

        return [
            'skill_id' => $row['skill_uuid'] ?? null,
            'skill_version_id' => $row['skill_version_uuid'] ?? null,
            'name' => $row['name'] ?? null,
            'number' => $row['number'] ?? null,
            'content_hash' => $row['content_hash'] ?? null,
            'available' => (bool) ($row['available'] ?? false),
        ];
    }
}
