<?php

namespace Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class GrantingOwnerAuthorizer implements OwnerAuthorizer
{
    /** @var array<string, array<int, array{0: string, 1: string}>> */
    private array $grants = [];

    public function grant(Authenticatable $actor, string $ownerType, int|string $ownerId): void
    {
        $this->grants[$this->key($actor)][] = [$ownerType, (string) $ownerId];
    }

    public function authorize(?Authenticatable $user, string $ownerType, mixed $ownerId): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array([$ownerType, (string) $ownerId], $this->grants[$this->key($user)] ?? [], true);
    }

    public function scope(Builder $query, ?Authenticatable $user, string $ownerTypeColumn = 'owner_type', string $ownerIdColumn = 'owner_id'): Builder
    {
        $grants = $user === null ? [] : ($this->grants[$this->key($user)] ?? []);

        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($grants, $ownerTypeColumn, $ownerIdColumn) {
            foreach ($grants as [$type, $id]) {
                $outer->orWhere(fn (Builder $inner) => $inner->where($ownerTypeColumn, $type)->where($ownerIdColumn, $id));
            }
        });
    }

    private function key(Authenticatable $actor): string
    {
        return $actor::class.'#'.$actor->getAuthIdentifier();
    }
}
