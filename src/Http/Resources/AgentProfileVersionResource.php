<?php

namespace Whilesmart\AgentProfiles\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Whilesmart\AgentProfiles\Exceptions\ProfileUnavailable;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;

/**
 * @mixin AgentProfileVersion
 */
class AgentProfileVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->hasIntactContent()) {
            throw ProfileUnavailable::because('The stored content no longer matches its hash.');
        }
        $content = $this->content;

        return [
            'id' => $this->uuid,
            'profile_id' => $this->profile?->uuid,
            'number' => (int) $this->number,
            'status' => $this->status()->value,
            'source_revision' => (int) $this->source_revision,
            'content_hash' => $this->content_hash,
            'definition' => $content['definition'],
            'rules' => $content['rules'] ?? null,
            'skills' => $content['skills'],
            'published_by' => $this->published_by_type ? ['type' => $this->published_by_type, 'id' => $this->published_by_id] : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
        ];
    }
}
