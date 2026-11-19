<?php

namespace Whilesmart\AgentProfiles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Whilesmart\AgentProfiles\Http\Requests\Concerns\ValidatesProfileInput;

class RevokeProfileVersionRequest extends FormRequest
{
    use ValidatesProfileInput;

    public function authorize(): bool
    {
        return $this->allowsOnBoundProfile('revoke');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
