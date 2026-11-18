<?php

namespace Tests\Support;

use Tests\TestCase;
use Whilesmart\Skills\Models\SkillVersion;

final class SkillCatalogue
{
    public function __construct(private TestCase $test) {}

    public function version(string $ownerType, int|string $ownerId, string $scopeKey, string $name, string $description = 'Helps with a task.'): SkillVersion
    {
        $actor = $this->test->catalogueActor($ownerType, $ownerId);
        $current = auth()->user();
        try {
            $skill = $this->test->actingAs($actor)->postJson('/api/skills', [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'scope_key' => $scopeKey,
                'files' => [['path' => 'SKILL.md', 'content' => "---\nname: {$name}\ndescription: {$description}\n---\n\n# {$name}\n\nFollow the steps.\n"]],
            ])->assertCreated()->json('data.id');
            $id = $this->test->postJson("/api/skills/{$skill}/publish", ['draft_revision' => 1])->assertCreated()->json('data.id');

            return SkillVersion::query()->where('public_id', $id)->firstOrFail();
        } finally {
            if ($current !== null) {
                $this->test->actingAs($current);
            }
        }
    }

    public function revoke(SkillVersion $version): void
    {
        $skill = $version->skill;
        $actor = $this->test->catalogueActor($skill->owner_type, $skill->owner_id);
        $current = auth()->user();
        try {
            $this->test->actingAs($actor)->postJson("/api/skills/{$skill->public_id}/versions/{$version->public_id}/revoke")->assertOk();
        } finally {
            if ($current !== null) {
                $this->test->actingAs($current);
            }
        }
    }

    public function deleteSkill(SkillVersion $version): void
    {
        $skill = $version->skill;
        $actor = $this->test->catalogueActor($skill->owner_type, $skill->owner_id);
        $current = auth()->user();
        try {
            $this->test->actingAs($actor)->deleteJson("/api/skills/{$skill->public_id}")->assertOk();
        } finally {
            if ($current !== null) {
                $this->test->actingAs($current);
            }
        }
    }
}
