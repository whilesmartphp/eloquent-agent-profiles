<?php

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompactProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['ref' => $this->uuid, 'label' => $this->name];
    }
}
