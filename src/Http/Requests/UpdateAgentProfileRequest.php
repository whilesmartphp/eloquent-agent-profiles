<?php

namespace Whilesmart\AgentProfiles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Whilesmart\AgentProfiles\Http\Requests\Concerns\ValidatesProfileInput;

class UpdateAgentProfileRequest extends FormRequest
{
    use ValidatesProfileInput;

    public function authorize(): bool
    {
        return $this->allowsOnBoundProfile('update');
    }

    public function rules(): array
    {
        return [
            'revision' => $this->revisionRules(),
            'owner_type' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'scope_key' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'definition' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->definitionAndMetadataChecks($validator);
    }
}
