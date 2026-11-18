<?php

namespace Whilesmart\AgentProfiles\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer;
use Whilesmart\OwnerAccess\Authorizers\AllowAllAuthorizer;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

/**
 * Delegates owner decisions to the host's OwnerAuthorizer. It denies every request without
 * an actor and every request while no strict OwnerAuthorizer is bound, so installing the
 * package never opens profiles through the allow-all owner-access default.
 */
class OwnerAccessProfileAuthorizer implements ProfileAuthorizer
{
    public function __construct(protected Container $container) {}

    public function authorize(?Authenticatable $actor, string $ability, string $ownerType, string $ownerId, string $scopeKey): bool
    {
        $owners = $this->owners();

        if ($actor === null || $owners === null) {
            return false;
        }

        return $owners->authorize($actor, $ownerType, $ownerId);
    }

    public function scope(Builder $query, ?Authenticatable $actor): Builder
    {
        $owners = $this->owners();

        if ($actor === null || $owners === null) {
            return $query->whereRaw('1 = 0');
        }

        return $owners->scope($query, $actor, 'owner_type', 'owner_id');
    }

    protected function owners(): ?OwnerAuthorizer
    {
        if (! $this->container->bound(OwnerAuthorizer::class)) {
            return null;
        }

        $owners = $this->container->make(OwnerAuthorizer::class);

        return $owners instanceof AllowAllAuthorizer ? null : $owners;
    }
}
