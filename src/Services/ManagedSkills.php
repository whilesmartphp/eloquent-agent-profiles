<?php

namespace Whilesmart\AgentProfiles\Services;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Whilesmart\AgentProfiles\Exceptions\AccessDenied;
use Whilesmart\AgentProfiles\Exceptions\SkillUnavailable;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;
use Whilesmart\Skills\Exceptions\SkillAccessDenied;
use Whilesmart\Skills\Exceptions\SkillsException;
use Whilesmart\Skills\Models\Skill;
use Whilesmart\Skills\Models\SkillBinding;
use Whilesmart\Skills\Models\SkillVersion;
use Whilesmart\Skills\Services\SkillVersionResolver;
use Whilesmart\Skills\Support\SkillsConfig;

/**
 * The only path from a profile to managed catalogue skills. Every lookup goes through the
 * shared SkillVersionResolver with the profile's current owner and scope, so a skill of
 * another owner or scope, a deleted skill or a revoked version never resolves.
 */
class ManagedSkills
{
    public function __construct(protected SkillVersionResolver $resolver) {}

    public function resolve(AgentProfile $profile, string $versionUuid, ?Authenticatable $actor): SkillVersion
    {
        try {
            $version = $this->resolver->resolve(
                $versionUuid,
                (string) $profile->owner_type,
                (string) $profile->owner_id,
                (string) $profile->scope_key,
                $actor,
            );
        } catch (Exception) {
            throw SkillUnavailable::make();
        }

        if (! $version->isAvailable()) {
            throw SkillUnavailable::make();
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $pin
     */
    public function resolvePinned(AgentProfile $profile, array $pin, ?Authenticatable $actor): SkillVersion
    {
        $version = $this->resolve($profile, (string) ($pin['skill_version_uuid'] ?? ''), $actor);

        if ((string) $version->getKey() !== (string) ($pin['skill_version_id'] ?? '')
            || ! hash_equals((string) ($pin['content_hash'] ?? ''), (string) $version->content_hash)) {
            throw SkillUnavailable::make('The pinned skill version no longer matches its published content.');
        }

        return $version;
    }

    public function bind(AgentProfile $profile, string $versionUuid, ?Authenticatable $actor): SkillBinding
    {
        $version = $this->resolve($profile, $versionUuid, $actor);

        try {
            return $profile->assignSkillVersion($version, $actor);
        } catch (SkillAccessDenied|AuthorizationException|AccessDeniedHttpException) {
            throw AccessDenied::for('bind-skills');
        } catch (SkillsException $e) {
            throw SkillUnavailable::make($e->getMessage());
        }
    }

    public function unbind(AgentProfile $profile, string $skillUuid, ?Authenticatable $actor): bool
    {
        $skill = SkillsConfig::query('skill')->where('uuid', $skillUuid)->first();

        if ($skill === null) {
            return false;
        }

        return $profile->detachSkill($skill, $actor);
    }

    /**
     * Pins every current binding of the profile to the exact version it names.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<Issue>}
     */
    public function pin(AgentProfile $profile, ?Authenticatable $actor): array
    {
        $pins = [];
        $issues = [];

        foreach ($profile->skillBindings()->get() as $index => $binding) {
            $path = "skills.bindings.{$index}";
            $uuid = SkillsConfig::query('version')->whereKey($binding->skill_version_id)->value('uuid');

            try {
                if ($uuid === null) {
                    throw SkillUnavailable::make();
                }
                $version = $this->resolve($profile, (string) $uuid, $actor);
            } catch (SkillUnavailable $e) {
                $issues[] = Issue::error('skill_unavailable', 'A bound skill version is not available to this profile: '.$e->getMessage(), $path);

                continue;
            }

            if ((string) $version->getKey() !== (string) $binding->skill_version_id) {
                $issues[] = Issue::error('skill_unavailable', 'A bound skill version resolved to a different version.', $path);

                continue;
            }

            $pins[] = $this->describe($version);
        }

        usort($pins, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return [$pins, $issues];
    }

    /**
     * The profile's draft bindings with their current availability.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bindings(AgentProfile $profile, ?Authenticatable $actor): array
    {
        $rows = [];

        foreach ($profile->skillBindings()->get() as $binding) {
            $version = SkillsConfig::query('version')->whereKey($binding->skill_version_id)->first();
            $available = false;

            if ($version !== null) {
                try {
                    $available = (string) $this->resolve($profile, (string) $version->uuid, $actor)->getKey() === (string) $version->getKey();
                } catch (SkillUnavailable) {
                    $available = false;
                }
            }

            $rows[] = ($version ? $this->describe($version) : [
                'skill_uuid' => null,
                'skill_version_uuid' => null,
                'skill_version_id' => (string) $binding->skill_version_id,
                'name' => null,
                'number' => null,
                'content_hash' => null,
            ]) + ['available' => $available];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(SkillVersion $version): array
    {
        return [
            'skill_uuid' => (string) SkillsConfig::query('skill')->whereKey($version->skill_id)->value('uuid'),
            'skill_version_uuid' => (string) $version->uuid,
            'skill_version_id' => (string) $version->getKey(),
            'name' => (string) $version->name,
            'number' => (int) $version->number,
            'content_hash' => (string) $version->content_hash,
        ];
    }
}
