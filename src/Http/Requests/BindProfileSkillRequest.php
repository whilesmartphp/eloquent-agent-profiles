<?php

namespace Whilesmart\AgentProfiles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Whilesmart\AgentProfiles\Http\Requests\Concerns\ValidatesProfileInput;

class BindProfileSkillRequest extends FormRequest
{
    use ValidatesProfileInput;

    public function authorize(): bool
    {
        return $this->allowsOnBoundProfile('bind-skills');
    }

    public function rules(): array
    {
        return [
            'revision' => $this->revisionRules(),
            'skill_version_id' => ['required', 'string', 'max:64'],
        ];
    }
}
