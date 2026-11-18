<?php

namespace Whilesmart\AgentProfiles\Services;

use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\Skillpack\Pack;

/**
 * A published, active profile version with every pinned skill rechecked and loaded. This is
 * data for a host runtime; the package does not run models, rules or tools.
 */
final class ExecutionManifest
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $rules
     */
    public function __construct(
        public readonly AgentProfile $profile,
        public readonly AgentProfileVersion $version,
        public readonly array $definition,
        public readonly array $rules,
        public readonly Pack $skills,
    ) {}

    /**
     * The skill read-tools of the portable core: list_skills, read_skill and read_reference.
     *
     * @return array<int, mixed>
     */
    public function skillTools(): array
    {
        return $this->skills->tools();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profile->uuid,
            'version' => $this->version->number,
            'version_id' => $this->version->uuid,
            'manifest_hash' => $this->version->manifest_hash,
            'definition' => $this->definition,
            'rules' => $this->rules,
            'skills' => $this->skills->index(),
        ];
    }
}
