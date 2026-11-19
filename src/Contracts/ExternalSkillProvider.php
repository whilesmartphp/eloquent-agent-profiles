<?php

namespace Whilesmart\AgentProfiles\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\Skillpack\Contracts\SkillSource;

/**
 * Supplies the portable source behind an external skill reference. Providers are only used
 * when the host maps a source key to them; they decide which references a profile may use
 * and must throw when access is not allowed. The package pins the approved content hash and
 * keeps a snapshot, so a published version never fetches again.
 */
interface ExternalSkillProvider
{
    public function source(string $reference, AgentProfile $profile, ?Authenticatable $actor): SkillSource;
}
