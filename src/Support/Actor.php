<?php

namespace Whilesmart\AgentProfiles\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class Actor
{
    public static function type(?Authenticatable $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        return $actor instanceof Model ? $actor->getMorphClass() : $actor::class;
    }

    public static function id(?Authenticatable $actor): ?string
    {
        $id = $actor?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    /**
     * @return array{changed_by_type: ?string, changed_by_id: ?string}
     */
    public static function columns(?Authenticatable $actor, string $prefix = 'changed_by'): array
    {
        return ["{$prefix}_type" => self::type($actor), "{$prefix}_id" => self::id($actor)];
    }
}
