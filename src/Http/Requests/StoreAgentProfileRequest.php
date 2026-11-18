<?php

namespace Whilesmart\AgentProfiles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Whilesmart\AgentProfiles\Http\Requests\Concerns\ValidatesProfileInput;
use Whilesmart\AgentProfiles\Services\ProfileGate;

class StoreAgentProfileRequest extends FormRequest
{
    use ValidatesProfileInput;

    /**
     * Missing owner fields defer to validation so they surface as 422 rather than 403.
     */
    public function authorize(): bool
    {
        $ownerType = $this->input('owner_type');
        $ownerId = $this->input('owner_id');
        $scopeKey = $this->input('scope_key') ?? config('agent-profiles.default_scope_key', 'default');

        if (! is_string($ownerType) || $ownerType === '' || ! (is_string($ownerId) || is_int($ownerId)) || $ownerId === '' || ! is_string($scopeKey)) {
            return true;
        }

        return app(ProfileGate::class)->allows($this->user(), 'create', $ownerType, $ownerId, $scopeKey);
    }

    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', $this->identifierRule()],
            'scope_key' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'definition' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->definitionAndMetadataChecks($validator);
    }
}
