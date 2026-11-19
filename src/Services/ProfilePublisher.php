<?php

namespace Whilesmart\AgentProfiles\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
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
use Whilesmart\AgentProfiles\Support\Actor;
use Whilesmart\AgentProfiles\Support\Canonical;

class ProfilePublisher
{
    public const MANIFEST_SCHEMA = 'whilesmart.agent-profile/1';

    public function __construct(protected ProfileGate $gate, protected PublicationPlanner $planner, protected ExecutionManifestResolver $manifests) {}

    public function publish(AgentProfile $profile, int $expectedRevision, ?Authenticatable $actor, bool $activate = false): AgentProfileVersion
    {
        return DB::transaction(function () use ($profile, $expectedRevision, $actor, $activate) {
            $locked = $this->lock($profile);
            $this->gate->ensure($actor, 'publish', $locked);
            if ($activate) {
                $this->gate->ensure($actor, 'activate', $locked);
            }
            if ($locked->revision !== $expectedRevision) {
                throw new StaleRevision($expectedRevision, $locked->revision);
            }
            $plan = $this->planner->plan($locked, $actor);
            if (! $plan->ready()) {
                throw PublicationBlocked::because($plan->issues);
            }
            $definition = $plan->definition;
            unset($definition['skills']);
            $content = [
                'schema' => self::MANIFEST_SCHEMA,
                'owner' => ['owner_type' => $locked->owner_type, 'owner_id' => (string) $locked->owner_id, 'scope_key' => $locked->scope_key],
                'definition' => $definition,
                'rules' => $plan->rules,
                'skills' => ['managed' => $plan->managed, 'external' => $plan->external],
                'snapshots' => $plan->snapshots,
            ];
            $hash = Canonical::hash($content);
            $version = $locked->versions()->where('content_hash', $hash)->first();
            if ($version !== null) {
                if ($version->revoked_at !== null) {
                    throw InvalidTransition::from('revoked', 'republished');
                }
                if (! $version->hasIntactContent()) {
                    throw ProfileUnavailable::because('The stored content no longer matches its hash.');
                }
            } else {
                $model = config('agent-profiles.models.version', AgentProfileVersion::class);
                $version = $model::query()->create([
                    'agent_profile_id' => $locked->getKey(),
                    'number' => (int) $locked->versions()->max('number') + 1,
                    'source_revision' => $locked->revision,
                    'content' => $content,
                    'content_hash' => $hash,
                    'published_at' => now(),
                ] + Actor::columns($actor, 'published_by'));
                event(new AgentProfileVersionPublished($locked, $version, $actor));
            }
            if ($activate) {
                $this->switchActive($locked, $version, $actor);
            }

            return $version;
        });
    }

    public function activate(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): AgentProfileVersion
    {
        return DB::transaction(function () use ($profile, $version, $actor) {
            $locked = $this->lock($profile);
            $this->gate->ensure($actor, 'activate', $locked);
            $current = $this->version($locked, $version);
            $this->switchActive($locked, $current, $actor);

            return $current;
        });
    }

    public function deactivate(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): AgentProfileVersion
    {
        return DB::transaction(function () use ($profile, $version, $actor) {
            $locked = $this->lock($profile);
            $this->gate->ensure($actor, 'activate', $locked);
            $current = $this->version($locked, $version);
            if ((string) $locked->active_version_id !== (string) $current->getKey()) {
                throw InvalidTransition::from($current->status()->value, 'deactivated');
            }
            $locked->active_version_id = null;
            $locked->save();
            event(new AgentProfileVersionDeactivated($locked, $current, $actor));

            return $current;
        });
    }

    public function revoke(AgentProfile $profile, AgentProfileVersion $version, ?string $reason, ?Authenticatable $actor): AgentProfileVersion
    {
        return DB::transaction(function () use ($profile, $version, $reason, $actor) {
            $locked = $this->lock($profile);
            $this->gate->ensure($actor, 'revoke', $locked);
            $current = $this->version($locked, $version);
            if ($current->revoked_at !== null) {
                throw InvalidTransition::from('revoked', 'revoked');
            }
            $current->forceFill(['revoked_at' => now(), 'revocation_reason' => $reason] + Actor::columns($actor, 'revoked_by'))->save();
            if ((string) $locked->active_version_id === (string) $current->getKey()) {
                $locked->active_version_id = null;
                $locked->save();
            }
            event(new AgentProfileVersionRevoked($locked, $current, $actor));

            return $current;
        });
    }

    protected function switchActive(AgentProfile $profile, AgentProfileVersion $version, ?Authenticatable $actor): void
    {
        if ($version->revoked_at !== null) {
            throw InvalidTransition::from('revoked', 'activated');
        }
        $issues = $this->manifests->check($version, $actor);
        if ($issues !== []) {
            throw ProfileUnavailable::because('This version cannot be activated while its pinned content is unavailable.', $issues);
        }
        if ((string) $profile->active_version_id === (string) $version->getKey()) {
            return;
        }
        $profile->active_version_id = $version->getKey();
        $profile->save();
        event(new AgentProfileVersionActivated($profile, $version, $actor));
    }

    protected function lock(AgentProfile $profile): AgentProfile
    {
        return $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function version(AgentProfile $profile, AgentProfileVersion $version): AgentProfileVersion
    {
        $current = $profile->versions()->whereKey($version->getKey())->first();
        if ($current === null) {
            throw ProfileUnavailable::because('The version does not belong to this profile.');
        }

        return $current;
    }
}
