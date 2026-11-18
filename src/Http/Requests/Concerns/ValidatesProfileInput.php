<?php

namespace Whilesmart\AgentProfiles\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Validator;
use Whilesmart\AgentProfiles\Definition\DefinitionSchema;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Services\ProfileGate;

trait ValidatesProfileInput
{
    protected function allowsOnBoundProfile(string $ability): bool
    {
        $profile = $this->route('agent_profile');

        if (! $profile instanceof AgentProfile) {
            return true;
        }

        return app(ProfileGate::class)->allows(
            $this->user(),
            $ability,
            (string) $profile->owner_type,
            (string) $profile->owner_id,
            (string) $profile->scope_key,
        );
    }

    protected function definitionAndMetadataChecks(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->has('definition') && is_array($this->input('definition'))) {
                foreach (app(DefinitionSchema::class)->validate($this->input('definition')) as $issue) {
                    $validator->errors()->add($issue->path ?? 'definition', $issue->message);
                }
            }

            $metadata = $this->input('metadata');
            if (is_array($metadata)) {
                $limit = (int) config('agent-profiles.max_metadata_bytes', 16384);
                if (strlen((string) json_encode($metadata)) > $limit) {
                    $validator->errors()->add('metadata', "Metadata must encode to at most {$limit} bytes.");
                }
            }
        });
    }

    protected function revisionRules(): array
    {
        return ['required', 'integer', 'min:1'];
    }

    protected function identifierRule(int $max = 64): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max) {
            if (! (is_string($value) || is_int($value)) || $value === '' || strlen((string) $value) > $max) {
                $fail("The {$attribute} must be a text or whole-number identifier of at most {$max} characters.");
            }
        };
    }
}
