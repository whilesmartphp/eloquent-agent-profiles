<?php

namespace Whilesmart\AgentProfiles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Whilesmart\AgentProfiles\Http\Requests\Concerns\ValidatesProfileInput;

class PublishAgentProfileRequest extends FormRequest
{
    use ValidatesProfileInput;

    public function authorize(): bool
    {
        return $this->allowsOnBoundProfile('publish');
    }

    public function rules(): array
    {
        return [
            'revision' => $this->revisionRules(),
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
