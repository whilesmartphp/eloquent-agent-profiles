<?php

namespace Tests\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Whilesmart\AgentProfiles\Http\Controllers\AgentProfileController;
use Whilesmart\AgentProfiles\Models\AgentProfile;

class CountingController extends AgentProfileController
{
    public static int $shows = 0;

    public function show(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        self::$shows++;

        return parent::show($request, $agent_profile);
    }
}
