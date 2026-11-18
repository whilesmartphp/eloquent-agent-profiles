<?php

namespace Whilesmart\AgentProfiles\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Whilesmart\AgentProfiles\Contracts\DefinitionValidator;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Definition\RuleCompiler;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;

/**
 * Works out what publishing the current draft would produce and everything that stops it:
 * structural problems, capabilities the host does not provide, and skills that do not
 * resolve for the profile's owner and scope.
 */
class PublicationPlanner
{
    public function __construct(
        protected DefinitionSchema $schema,
        protected RuleCompiler $compiler,
        protected ManagedSkills $managed,
        protected ExternalSkills $external,
        protected Container $container,
    ) {}

    public function plan(AgentProfile $profile, ?Authenticatable $actor): PublicationPlan
    {
        $revision = (int) $profile->revision;
        $raw = $profile->definition ?? [];
        $issues = $this->schema->validate($raw);

        if (Issue::anyErrors($issues)) {
            return new PublicationPlan($revision, null, $issues);
        }

        $definition = $this->schema->normalize($raw);

        if ($definition['purpose'] === null) {
            $issues[] = Issue::error('purpose_missing', 'A published profile needs a purpose.', 'definition.purpose');
        }
        if ($definition['instructions'] === null) {
            $issues[] = Issue::error('instructions_missing', 'A published profile needs instructions.', 'definition.instructions');
        }

        foreach ($this->validators() as $validator) {
            array_push($issues, ...$validator->validate($definition, $profile));
        }

        [$managed, $managedIssues] = $this->managed->pin($profile, $actor);
        array_push($issues, ...$managedIssues);

        $bound = array_column($managed, 'skill_uuid');
        $external = [];
        $snapshots = [];

        foreach ($definition['skills'] as $index => $ref) {
            $path = "definition.skills.{$index}";

            if ($ref['source'] === DefinitionSchema::MANAGED_SOURCE) {
                if (! in_array($ref['skill'], array_map('strtolower', $bound), true)) {
                    $issues[] = Issue::error('skill_not_bound', 'The definition names a managed skill that has no available binding on this profile.', "{$path}.skill");
                }

                continue;
            }

            $result = $this->external->pin($ref, $path, $profile, $actor);
            if ($result instanceof Issue) {
                $issues[] = $result;

                continue;
            }

            $external[] = $result['pin'];
            $snapshots[$this->snapshotKey($result['pin'])] = $result['snapshot'];
        }

        $names = array_merge(array_column($managed, 'name'), array_column($external, 'name'));
        foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $name) {
            $issues[] = Issue::error('duplicate_skill_name', "Two skills are named {$name}.", 'skills');
        }

        return new PublicationPlan(
            $revision,
            $definition,
            $issues,
            $managed,
            $external,
            $snapshots,
            $this->compiler->compile($definition),
        );
    }

    /**
     * @param  array<string, mixed>  $pin
     */
    public function snapshotKey(array $pin): string
    {
        return $pin['source'].':'.$pin['name'];
    }

    /**
     * @return array<DefinitionValidator>
     */
    protected function validators(): array
    {
        return array_map(function (mixed $class) {
            if (! is_string($class) || ! is_a($class, DefinitionValidator::class, true)) {
                throw new InvalidArgumentException('agent-profiles.validators entries must implement '.DefinitionValidator::class.'.');
            }

            return $this->container->make($class);
        }, array_values((array) config('agent-profiles.validators', [])));
    }
}
