<?php

namespace Whilesmart\AgentProfiles\Services;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Whilesmart\AgentProfiles\Contracts\ExternalSkillProvider;
use Whilesmart\AgentProfiles\Exceptions\SkillUnavailable;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Support\Issue;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\Skill;

/**
 * External skill references are denied unless the host maps their source key to a provider.
 * At publication the provider's skill must match the approved content hash and is kept as a
 * bounded snapshot; resolving a published version restores that snapshot and never fetches.
 */
class ExternalSkills
{
    public function __construct(protected Container $container) {}

    /**
     * @param  array<string, mixed>  $ref
     * @return array{pin: array<string, mixed>, snapshot: array<string, mixed>}|Issue
     */
    public function pin(array $ref, string $path, AgentProfile $profile, ?Authenticatable $actor): array|Issue
    {
        $provider = $this->provider((string) $ref['source']);

        if ($provider === null) {
            return Issue::error('external_source_not_configured', "Skill source {$ref['source']} is not enabled on this host.", "{$path}.source");
        }

        try {
            $skill = $provider->source((string) $ref['reference'], $profile, $actor)->load()->get((string) $ref['skill']);
        } catch (Exception) {
            return Issue::error('external_skill_unavailable', "Skill {$ref['skill']} could not be read from {$ref['source']}.", $path);
        }

        if (! $this->sameHash((string) $ref['content_hash'], $skill->contentHash())) {
            return Issue::error('external_skill_hash_mismatch', "Skill {$ref['skill']} no longer matches its approved content hash.", "{$path}.content_hash");
        }

        if (! $skill->valid()) {
            return Issue::error('external_skill_invalid', "Skill {$ref['skill']} does not pass validation.", $path);
        }

        $files = [];
        $bytes = 0;
        foreach ($skill->files as $name => $content) {
            $bytes += strlen((string) $content);
            $files[(string) $name] = base64_encode((string) $content);
        }

        $limit = (int) config('agent-profiles.external_skills.max_snapshot_bytes', 1048576);
        if ($bytes > $limit) {
            return Issue::error('external_snapshot_too_large', "Skill {$ref['skill']} is {$bytes} bytes; the snapshot limit is {$limit}.", $path);
        }

        $pin = [
            'source' => (string) $ref['source'],
            'reference' => (string) $ref['reference'],
            'name' => $skill->name(),
            'content_hash' => $skill->contentHash(),
        ];

        return ['pin' => $pin, 'snapshot' => ['files' => $files, 'provenance' => $skill->provenance]];
    }

    /**
     * @param  array<string, mixed>  $pin
     * @param  array<string, mixed>|null  $snapshot
     */
    public function restore(array $pin, ?array $snapshot): Skill
    {
        if (! is_array($snapshot) || ! is_array($snapshot['files'] ?? null)) {
            throw SkillUnavailable::make("The snapshot of skill {$pin['name']} is missing.");
        }

        $files = [];
        foreach ($snapshot['files'] as $name => $encoded) {
            $decoded = base64_decode((string) $encoded, true);
            if ($decoded === false) {
                throw SkillUnavailable::make("The snapshot of skill {$pin['name']} is damaged.");
            }
            $files[(string) $name] = $decoded;
        }

        if (array_key_exists('SKILL.md', $files)) {
            $files = array_combine(
                array_map(fn (string $name) => $pin['name'].'/'.$name, array_keys($files)),
                array_values($files),
            );
        }

        try {
            $skill = Loader::fromFiles($files, (array) ($snapshot['provenance'] ?? []))->get((string) $pin['name']);
        } catch (Exception) {
            throw SkillUnavailable::make("The snapshot of skill {$pin['name']} cannot be loaded.");
        }

        if (! $this->sameHash((string) $pin['content_hash'], $skill->contentHash())) {
            throw SkillUnavailable::make("The snapshot of skill {$pin['name']} no longer matches its content hash.");
        }

        return $skill;
    }

    public function provider(string $source): ?ExternalSkillProvider
    {
        $class = ((array) config('agent-profiles.external_skills.providers', []))[$source] ?? null;

        if (! is_string($class) || ! is_a($class, ExternalSkillProvider::class, true)) {
            return null;
        }

        return $this->container->make($class);
    }

    protected function sameHash(string $expected, string $actual): bool
    {
        $strip = fn (string $hash) => strtolower(str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash);

        return $expected !== '' && hash_equals($strip($expected), $strip($actual));
    }
}
