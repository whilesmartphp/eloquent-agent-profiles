<?php

namespace Whilesmart\AgentProfiles\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer;
use Whilesmart\AgentProfiles\Exceptions\AccessDenied;
use Whilesmart\AgentProfiles\Models\AgentProfile;

class ProfileGate
{
    public function __construct(protected ProfileAuthorizer $authorizer) {}

    public function allows(?Authenticatable $actor, string $ability, string $ownerType, int|string $ownerId, string $scopeKey): bool
    {
        return $this->authorizer->authorize($actor, $ability, $ownerType, (string) $ownerId, $scopeKey);
    }

    public function ensureOwner(?Authenticatable $actor, string $ability, string $ownerType, int|string $ownerId, string $scopeKey): void
    {
        if (! $this->allows($actor, $ability, $ownerType, $ownerId, $scopeKey)) {
            throw AccessDenied::for($ability);
        }
    }

    public function ensure(?Authenticatable $actor, string $ability, AgentProfile $profile): void
    {
        $this->ensureOwner($actor, $ability, (string) $profile->owner_type, (string) $profile->owner_id, (string) $profile->scope_key);
    }

    public function scope(Builder $query, ?Authenticatable $actor): Builder
    {
        return $this->authorizer->scope($query, $actor);
    }
}
