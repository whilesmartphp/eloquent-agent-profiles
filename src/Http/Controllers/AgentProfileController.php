<?php

namespace Whilesmart\AgentProfiles\Http\Controllers;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Whilesmart\AgentProfiles\Contracts\ResponseFormatter;
use Whilesmart\AgentProfiles\Exceptions\AgentProfileException;
use Whilesmart\AgentProfiles\Models\AgentProfile;
use Whilesmart\AgentProfiles\Models\AgentProfileVersion;
use Whilesmart\AgentProfiles\Services\ExecutionManifestResolver;
use Whilesmart\AgentProfiles\Services\ManagedSkills;
use Whilesmart\AgentProfiles\Services\ProfileGate;
use Whilesmart\AgentProfiles\Services\ProfileManager;
use Whilesmart\AgentProfiles\Services\ProfilePublisher;
use Whilesmart\AgentProfiles\Services\PublicationPlanner;

class AgentProfileController extends Controller
{
    public function __construct(
        protected ResponseFormatter $responses,
        protected ProfileGate $gate,
        protected ProfileManager $profiles,
        protected ProfilePublisher $publisher,
        protected PublicationPlanner $planner,
        protected ManagedSkills $skills,
        protected ExecutionManifestResolver $manifests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $model = config('agent-profiles.models.profile');
        $query = $this->gate->scope($model::query(), $request->user());

        foreach (['owner_type', 'owner_id', 'scope_key'] as $filter) {
            if ($request->filled($filter) && is_scalar($request->input($filter))) {
                $query->where($filter, (string) $request->input($filter));
            }
        }

        if ($request->filled('q') && is_string($request->input('q'))) {
            $query->where('name', 'like', '%'.addcslashes($request->input('q'), '%_\\').'%');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $profiles = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($perPage);

        return $this->responses->success($this->resourceClass('profile')::collection($profiles)->response()->getData(true));
    }

    public function store(): JsonResponse
    {
        $request = $this->formRequest('store');

        return $this->attempt(fn () => $this->responses->success(
            $this->resource('profile', $this->profiles->create($request->validated(), $request->user())),
            201,
        ));
    }

    public function show(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile) {
            $this->gate->ensure($request->user(), 'view', $agent_profile);

            return $this->responses->success($this->resource('profile', $agent_profile));
        });
    }

    public function update(AgentProfile $agent_profile): JsonResponse
    {
        $request = $this->formRequest('update');
        $data = $request->validated();
        $revision = (int) $data['revision'];
        unset($data['revision']);

        return $this->attempt(fn () => $this->responses->success(
            $this->resource('profile', $this->profiles->update($agent_profile, $revision, $data, $request->user())),
        ));
    }

    public function destroy(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile) {
            $this->profiles->delete($agent_profile, $request->user());

            return $this->responses->success(['deleted' => true]);
        });
    }

    public function readiness(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile) {
            $this->gate->ensure($request->user(), 'publish', $agent_profile);

            return $this->responses->success($this->planner->plan($agent_profile, $request->user())->toArray());
        });
    }

    public function skills(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile) {
            $this->gate->ensure($request->user(), 'view', $agent_profile);

            return $this->responses->success(
                $this->resourceClass('skill')::collection($this->skills->bindings($agent_profile, $request->user()))
            );
        });
    }

    public function bindSkill(AgentProfile $agent_profile): JsonResponse
    {
        $request = $this->formRequest('bind_skill');

        return $this->attempt(function () use ($request, $agent_profile) {
            $profile = $this->profiles->bindSkill($agent_profile, (int) $request->input('revision'), (string) $request->input('skill_version_id'), $request->user());

            return $this->responses->success([
                'revision' => (int) $profile->draft_revision,
                'skills' => $this->resourceClass('skill')::collection($this->skills->bindings($profile, $request->user())),
            ], 201);
        });
    }

    public function unbindSkill(AgentProfile $agent_profile, string $skill): JsonResponse
    {
        $request = $this->formRequest('unbind_skill');

        return $this->attempt(function () use ($request, $agent_profile, $skill) {
            $profile = $this->profiles->unbindSkill($agent_profile, (int) $request->input('revision'), $skill, $request->user());

            return $this->responses->success(['revision' => (int) $profile->draft_revision]);
        });
    }

    public function publish(AgentProfile $agent_profile): JsonResponse
    {
        $request = $this->formRequest('publish');

        return $this->attempt(function () use ($request, $agent_profile) {
            $version = $this->publisher->publish($agent_profile, (int) $request->input('revision'), $request->user(), $request->boolean('activate'));

            return $this->responses->success($this->resource('version', $version->fresh(['state'])), 201);
        });
    }

    public function versions(Request $request, AgentProfile $agent_profile): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile) {
            $this->gate->ensure($request->user(), 'view', $agent_profile);

            $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
            $versions = $agent_profile->versions()->with('state')->orderByDesc('number')->paginate($perPage);

            return $this->responses->success($this->resourceClass('version')::collection($versions)->response()->getData(true));
        });
    }

    public function showVersion(Request $request, AgentProfile $agent_profile, int $version): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile, $version) {
            $this->gate->ensure($request->user(), 'view', $agent_profile);

            return $this->responses->success($this->resource('version', $this->findVersion($agent_profile, $version)));
        });
    }

    public function activate(Request $request, AgentProfile $agent_profile, int $version): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile, $version) {
            $this->gate->ensure($request->user(), 'activate', $agent_profile);
            $found = $this->publisher->activate($agent_profile, $this->findVersion($agent_profile, $version), $request->user());

            return $this->responses->success($this->resource('version', $found->fresh(['state'])));
        });
    }

    public function deactivate(Request $request, AgentProfile $agent_profile, int $version): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile, $version) {
            $this->gate->ensure($request->user(), 'activate', $agent_profile);
            $found = $this->publisher->deactivate($agent_profile, $this->findVersion($agent_profile, $version), $request->user());

            return $this->responses->success($this->resource('version', $found->fresh(['state'])));
        });
    }

    public function revoke(AgentProfile $agent_profile, int $version): JsonResponse
    {
        $request = $this->formRequest('revoke');

        return $this->attempt(function () use ($request, $agent_profile, $version) {
            $found = $this->publisher->revoke($agent_profile, $this->findVersion($agent_profile, $version), $request->input('reason'), $request->user());

            return $this->responses->success($this->resource('version', $found->fresh(['state'])));
        });
    }

    public function manifest(Request $request, AgentProfile $agent_profile, int $version): JsonResponse
    {
        return $this->attempt(function () use ($request, $agent_profile, $version) {
            $this->gate->ensure($request->user(), 'resolve', $agent_profile);

            return $this->responses->success($this->manifests->resolve($this->findVersion($agent_profile, $version), $request->user())->toArray());
        });
    }

    protected function findVersion(AgentProfile $profile, int $number): AgentProfileVersion
    {
        return $profile->versions()->with('state')->where('number', $number)->firstOrFail();
    }

    protected function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (AgentProfileException $e) {
            return $this->responses->failure($e->getMessage(), $e->status, $e->issues === [] ? [] : ['issues' => $e->issuesArray()]);
        }
    }

    /**
     * Resolving a form request through the container runs its authorization and validation.
     */
    protected function formRequest(string $key): FormRequest
    {
        return app(config("agent-profiles.requests.{$key}"));
    }

    protected function resourceClass(string $key): string
    {
        return config("agent-profiles.resources.{$key}");
    }

    protected function resource(string $key, mixed $record): mixed
    {
        $class = $this->resourceClass($key);

        return new $class($record);
    }
}
