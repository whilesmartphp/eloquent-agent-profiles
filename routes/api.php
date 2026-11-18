<?php

use Illuminate\Support\Facades\Route;

$controller = config('agent-profiles.controller');
$write = (array) config('agent-profiles.write_middleware', []);
$groups = (array) config('agent-profiles.route_groups', []);
$disabled = (array) config('agent-profiles.disabled_routes', []);

$route = function (array $methods, string $uri, string $action, string $name, bool $writes) use ($controller, $write, $disabled) {
    if (in_array($name, $disabled, true)) {
        return;
    }

    $registered = Route::match($methods, $uri, [$controller, $action])->name($name)->whereNumber('version');

    if ($writes) {
        $registered->middleware($write);
    }
};

$profile = 'agent-profiles/{agent_profile}';
$version = "{$profile}/versions/{version}";

if ($groups['profiles'] ?? true) {
    $route(['GET'], 'agent-profiles', 'index', 'agent-profiles.index', false);
    $route(['POST'], 'agent-profiles', 'store', 'agent-profiles.store', true);
    $route(['GET'], $profile, 'show', 'agent-profiles.show', false);
    $route(['PUT', 'PATCH'], $profile, 'update', 'agent-profiles.update', true);
    $route(['DELETE'], $profile, 'destroy', 'agent-profiles.destroy', true);
    $route(['GET'], "{$profile}/readiness", 'readiness', 'agent-profiles.readiness', false);
}

if ($groups['skills'] ?? true) {
    $route(['GET'], "{$profile}/skills", 'skills', 'agent-profiles.skills.index', false);
    $route(['POST'], "{$profile}/skills", 'bindSkill', 'agent-profiles.skills.bind', true);
    $route(['DELETE'], "{$profile}/skills/{skill}", 'unbindSkill', 'agent-profiles.skills.unbind', true);
}

if ($groups['versions'] ?? true) {
    $route(['POST'], "{$profile}/publish", 'publish', 'agent-profiles.publish', true);
    $route(['GET'], "{$profile}/versions", 'versions', 'agent-profiles.versions.index', false);
    $route(['GET'], $version, 'showVersion', 'agent-profiles.versions.show', false);
}

if ($groups['lifecycle'] ?? true) {
    $route(['POST'], "{$version}/activate", 'activate', 'agent-profiles.versions.activate', true);
    $route(['POST'], "{$version}/deactivate", 'deactivate', 'agent-profiles.versions.deactivate', true);
    $route(['POST'], "{$version}/revoke", 'revoke', 'agent-profiles.versions.revoke', true);
    $route(['GET'], "{$version}/manifest", 'manifest', 'agent-profiles.versions.manifest', false);
}
