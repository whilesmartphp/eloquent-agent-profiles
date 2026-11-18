<?php

namespace Whilesmart\AgentProfiles\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Whilesmart\AgentProfiles\Enums\VersionStatus;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionActivated;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionDeactivated;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionPublished;
use Whilesmart\AgentProfiles\Events\AgentProfileVersionRevoked;
use Whilesmart\AgentProfiles\Exceptions\InvalidTransition;
use Whilesmart\AgentProfiles\Exceptions\ProfileUnavailable;
use Whilesmart\AgentProfiles\Exceptions\PublicationBlocked;
use Whilesmart\AgentProfiles\Exceptions\StaleRevision;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Models\AgentProfileVersionState;
use Whilesmart\AgentProfiles\Support\Actor;
use Whilesmart\AgentProfiles\Support\Canonical;

class ProfilePublisher
{
    public const MANIFEST_SCHEMA = 'whilesmart.agent-profile/1';

    public function __construct(
        protected ProfileGate $gate,
        protected PublicationPlanner $planner,
        protected ExecutionManifestResolver $manifests,
    ) {}

    public function publish(AgentProfile $profile, int $expectedRevision, ?Authenticatable $actor, bool $activate = false): AgentProfileVersion
    {
        $this->gate->ensure($actor, 'publish', $profile);
        if ($activate) {
            $this->gate->ensure($actor, 'activate', $profile);
        }

        if ((int) $profile->draft_revision !== $expectedRevision) {
            throw new StaleRevision($expectedRevision, (int) $profile->draft_revision);
        }

        $plan = $this->planner->plan($profile, $actor);
        if (! $plan->ready()) {
            throw PublicationBlocked::because($plan->issues);
        }

        return DB::transaction(function () use ($profile, $expectedRevision, $plan, $actor, $activate) {
            /** @var AgentProfile $locked */
            $locked = $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->draft_revision !== $expectedRevision || $locked->draft_revision !== $plan->revision) {
                throw new StaleRevision($expectedRevision, $locked->draft_revision);
            }

            $number = (int) $locked->versions()->max('number') + 1;
            $skills = ['managed' => $plan->managed, 'external' => $plan->external];
            $manifest = [
                'schema' => self::MANIFEST_SCHEMA,
                'profile' => [
                    'public_id' => $locked->public_id,
                    'owner_type' => $locked->owner_type,
                    'owner_id' => (string) $locked->owner_id,
                    'scope_key' => $locked->scope_key,
                    'name' => $locked->name,
                ],
                'version' => $number,
                'source_revision' => $plan->revision,
                'definition' => $plan->definition,
                'rules' => $plan->rules,
                'skills' => $skills,
            ];
            $manifest = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            $versionModel = config('agent-profiles.models.version', AgentProfileVersion::class);
            /** @var AgentProfileVersion $version */
            $version = $versionModel::query()->create([
                'agent_profile_id' => $locked->getKey(),
                'number' => $number,
                'source_revision' => $plan->revision,
                'definition' => $manifest['definition'],
                'skills' => $manifest['skills'],
                'manifest' => $manifest,
                'manifest_hash' => Canonical::hash($manifest),
                'snapshots' => $plan->snapshots ?: null,
                'published_at' => now(),
            ] + Actor::columns($actor, 'published_by'));

            $this->stateModel()::query()->create([
                'agent_profile_version_id' => $version->getKey(),
                'agent_profile_id' => $locked->getKey(),
                'status' => VersionStatus::Inactive,
            ] + Actor::columns($actor));

            event(new AgentProfileVersionPublished($locked, $version, $actor));

            if ($activate) {
                $this->switchActive($locked, $version, $actor);
            }

            return $version;
        });
    }

    public function activate(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): AgentProfileVersion
    {
        $this->gate->ensure($actor, 'activate', $profile);
        $this->ensureBelongs($profile, $version);

        if ($profile->trashed()) {
            throw ProfileUnavailable::because('The agent profile was deleted.');
        }

        if ($version->status() === VersionStatus::Revoked) {
            throw InvalidTransition::from('revoked', 'activated');
        }

        $issues = $this->manifests->check($version, $actor);
        if ($issues !== []) {
            throw ProfileUnavailable::because('This version cannot be activated while its pinned content is unavailable.', $issues);
        }

        return DB::transaction(function () use ($profile, $version, $actor) {
            $locked = $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $this->switchActive($locked, $version, $actor);

            return $version;
        });
    }

    public function deactivate(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): AgentProfileVersion
    {
        $this->gate->ensure($actor, 'activate', $profile);
        $this->ensureBelongs($profile, $version);

        return DB::transaction(function () use ($profile, $version, $actor) {
            $state = $this->lockState($version);
            if ($state->status !== VersionStatus::Active) {
                throw InvalidTransition::from($state->status->value, 'deactivated');
            }

            $state->update(['status' => VersionStatus::Inactive, 'deactivated_at' => now()] + Actor::columns($actor));
            event(new AgentProfileVersionDeactivated($profile, $version, $actor));

            return $version;
        });
    }

    public function revoke(AgentProfile $profile, AgentProfileVersion $version, ?string $reason, ?Authenticatable $actor): AgentProfileVersion
    {
        $this->gate->ensure($actor, 'revoke', $profile);
        $this->ensureBelongs($profile, $version);

        return DB::transaction(function () use ($profile, $version, $reason, $actor) {
            $state = $this->lockState($version);
            if ($state->status === VersionStatus::Revoked) {
                throw InvalidTransition::from('revoked', 'revoked');
            }

            $state->update([
                'status' => VersionStatus::Revoked,
                'revoked_at' => now(),
                'deactivated_at' => $state->status === VersionStatus::Active ? now() : $state->deactivated_at,
                'revocation_reason' => $reason,
            ] + Actor::columns($actor));

            event(new AgentProfileVersionRevoked($profile, $version, $actor));

            return $version;
        });
    }

    protected function switchActive(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): void
    {
        $state = $this->lockState($version);
        if ($state->status === VersionStatus::Revoked) {
            throw InvalidTransition::from('revoked', 'activated');
        }

        $this->stateModel()::query()
            ->where('agent_profile_id', $profile->getKey())
            ->where('status', VersionStatus::Active->value)
            ->where('agent_profile_version_id', '!=', $version->getKey())
            ->update(['status' => VersionStatus::Inactive->value, 'deactivated_at' => now()] + Actor::columns($actor));

        $state->update(['status' => VersionStatus::Active, 'activated_at' => now()] + Actor::columns($actor));

        event(new AgentProfileVersionActivated($profile, $version, $actor));
    }

    protected function lockState(AgentProfileVersion $version): AgentProfileVersionState
    {
        return $this->stateModel()::query()
            ->where('agent_profile_version_id', $version->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function ensureBelongs(AgentProfile $profile, AgentProfileVersion $version): void
    {
        if ((string) $version->agent_profile_id !== (string) $profile->getKey()) {
            throw ProfileUnavailable::because('The version does not belong to this profile.');
        }
    }

    /**
     * @return class-string<AgentProfileVersionState>
     */
    protected function stateModel(): string
    {
        return config('agent-profiles.models.version_state', AgentProfileVersionState::class);
    }
}
