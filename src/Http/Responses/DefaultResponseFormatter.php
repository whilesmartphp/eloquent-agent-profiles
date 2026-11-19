<?php

namespace Whilesmart\AgentProfiles\Http\Responses;

use Illuminate\Http\JsonResponse;
use Whilesmart\AgentProfiles\Contracts\ResponseFormatter;

class DefaultResponseFormatter implements ResponseFormatter
{
    public function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public function failure(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }
}
