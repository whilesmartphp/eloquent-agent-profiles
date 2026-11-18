<?php

namespace Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Whilesmart\AgentProfiles\Contracts\ExternalSkillProvider;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\Pack;

class InlineSkillProvider implements ExternalSkillProvider
{
    public static int $loads = 0;

    /**
     * @return array<string, string>
     */
    public static function files(string $reference): array
    {
        return match ($reference) {
            'house-style' => [
                'release-notes/SKILL.md' => "---\nname: release-notes\ndescription: Writes release notes in the house style.\n---\n\nGroup changes by audience. See [tone](references/tone.md).\n",
                'release-notes/references/tone.md' => "Plain words, short sentences.\n",
            ],
            default => throw new RuntimeException("Unknown reference {$reference}."),
        };
    }

    public static function hash(string $reference, string $skill): string
    {
        return app(Loader::class)->fromFiles(self::files($reference))->get($skill)->contentHash();
    }

    public function source(string $reference, AgentProfile $profile, ?Authenticatable $actor): SkillSource
    {
        $files = self::files($reference);

        return new class($files) implements SkillSource
        {
            /**
             * @param  array<string, string>  $files
             */
            public function __construct(private array $files) {}

            public function load(): Pack
            {
                InlineSkillProvider::$loads++;

                return app(Loader::class)->fromFiles($this->files, ['source' => 'inline']);
            }
        };
    }
}
