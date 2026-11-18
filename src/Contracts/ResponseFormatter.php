<?php

namespace Whilesmart\AgentProfiles\Contracts;

use Illuminate\Http\JsonResponse;

/**
 * Shapes the outer envelope of every package response. Resources shape the records.
 */
interface ResponseFormatter
{
    public function success(mixed $data = null, int $status = 200): JsonResponse;

    /**
     * @param  array<string, mixed>  $errors
     */
    public function failure(string $message, int $status = 400, array $errors = []): JsonResponse;
}
