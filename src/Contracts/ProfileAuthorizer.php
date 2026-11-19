<?php

namespace Whilesmart\AgentProfiles\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides who may act on agent profiles of an owner and scope. Abilities are
 * view, create, update, delete, bind-skills, publish, activate, revoke and resolve.
 * Implementations must deny when they cannot decide.
 */
interface ProfileAuthorizer
{
    public function authorize(?Authenticatable $actor, string $ability, string $ownerType, string $ownerId, string $scopeKey): bool;

    public function scope(Builder $query, ?Authenticatable $actor): Builder;
}
