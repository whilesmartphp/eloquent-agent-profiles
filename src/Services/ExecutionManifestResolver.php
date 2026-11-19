<?php

namespace Whilesmart\AgentProfiles\Services;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Exceptions\ProfileUnavailable;
use Whilesmart\AgentProfiles\Exceptions\SkillUnavailable;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Support\Issue;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Skill;

/**
 * Turns a published version into what a host runtime may use, rechecking at call time that
 * the profile still exists, the version is active and intact, the profile still has the owner
 * and scope it was published under, and every pinned managed skill is still available to that
 * owner and scope with the same content.
 */
class ExecutionManifestResolver
{
    public function __construct(
        protected ProfileGate $gate,
        protected ManagedSkills $managed,
        protected ExternalSkills $external,
        protected PublicationPlanner $planner,
    ) {}

    public function resolve(AgentProfileVersion $version, ?Authenticatable $actor = null): ExecutionManifest
    {
        $profile = $this->liveProfile($version);
        $this->gate->ensure($actor, 'resolve', $profile);

        if ($version->status !== VersionStatus::Active || $version->revoked_at !== null) {
            throw ProfileUnavailable::because('This agent profile version is not active.');
        }

        [$skills, $issues] = $this->skills($profile, $version, $actor);
        if ($issues !== []) {
            throw ProfileUnavailable::because('This agent profile version depends on content that is no longer available.', $issues);
        }

        try {
            $pack = new Pack($skills);
        } catch (Exception) {
            throw ProfileUnavailable::because('The pinned skills of this version cannot be combined.');
        }

        return new ExecutionManifest($profile, $version, (array) $version->content['definition'], (array) $version->content['rules'], $pack);
    }

    public function resolveActive(AgentProfile $profile, ?Authenticatable $actor = null): ExecutionManifest
    {
        if ($profile->trashed()) {
            throw ProfileUnavailable::because('The agent profile was deleted.');
        }

        $this->gate->ensure($actor, 'resolve', $profile);

        $version = $profile->activeVersion();
        if ($version === null) {
            throw ProfileUnavailable::because('The agent profile has no active version.');
        }

        return $this->resolve($version, $actor);
    }

    /**
     * Problems that would stop the version from resolving, ignoring whether it is active.
     *
     * @return array<Issue>
     */
    public function check(AgentProfileVersion $version, ?Authenticatable $actor): array
    {
        try {
            $profile = $this->liveProfile($version);
        } catch (ProfileUnavailable $e) {
            return [Issue::error('profile_unavailable', $e->getMessage())];
        }

        return $this->skills($profile, $version, $actor)[1];
    }

    protected function liveProfile(AgentProfileVersion $version): AgentProfile
    {
        $profile = $version->profile()->first();

        if (! $profile instanceof AgentProfile || $profile->trashed()) {
            throw ProfileUnavailable::because('The agent profile was deleted.');
        }

        if (! $version->hasIntactContent()) {
            throw ProfileUnavailable::because('The stored manifest no longer matches its hash.');
        }

        $pinned = (array) ($version->content['owner'] ?? []);
        if (($pinned['owner_type'] ?? null) !== $profile->owner_type
            || ($pinned['owner_id'] ?? null) !== (string) $profile->owner_id
            || ($pinned['scope_key'] ?? null) !== $profile->scope_key) {
            throw ProfileUnavailable::because('The agent profile changed owner or scope since this version was published.');
        }

        return $profile;
    }

    /**
     * @return array{0: array<Skill>, 1: array<Issue>}
     */
    protected function skills(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): array
    {
        $skills = [];
        $issues = [];
        $pinned = (array) ($version->content['skills'] ?? []);

        foreach ((array) ($pinned['managed'] ?? []) as $index => $pin) {
            try {
                $skills[] = $this->managed->resolvePinned($profile, (array) $pin, $actor)->toPortableSkill();
            } catch (SkillUnavailable $e) {
                $issues[] = Issue::error('skill_unavailable', $e->getMessage(), "skills.managed.{$index}");
            } catch (Exception) {
                $issues[] = Issue::error('skill_unavailable', 'A pinned skill version could not be loaded.', "skills.managed.{$index}");
            }
        }

        $snapshots = (array) ($version->content['snapshots'] ?? []);
        foreach ((array) ($pinned['external'] ?? []) as $index => $pin) {
            try {
                $skills[] = $this->external->restore((array) $pin, $snapshots[$this->planner->snapshotKey((array) $pin)] ?? null);
            } catch (SkillUnavailable $e) {
                $issues[] = Issue::error('skill_unavailable', $e->getMessage(), "skills.external.{$index}");
            }
        }

        return [$skills, $issues];
    }
}
