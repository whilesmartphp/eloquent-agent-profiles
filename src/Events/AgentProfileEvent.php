<?php

namespace Whilesmart\AgentProfiles\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Whilesmart\AgentProfiles\Models\AgentProfile;

abstract class AgentProfileEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly AgentProfile $profile,
        public readonly ?Authenticatable $actor = null,
    ) {}
}
