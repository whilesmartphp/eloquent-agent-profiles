# whilesmart/eloquent-agent-profiles

Agent definitions for Laravel. An `AgentProfile` belongs to a polymorphic owner and a `scope_key`, keeps an editable draft, and publishes immutable versions whose manifest pins the exact skills and settings an agent may use.

This package manages definitions and publication only. It does not run models, evaluate rules, call tools, queue work or keep chat history. A host runtime reads a resolved execution manifest and decides how to act on it.

## Install

The package is unreleased. Install the implementation branches from GitHub:

```bash
composer config repositories.skillpack vcs https://github.com/whilesmartphp/skillpack
composer config repositories.eloquent-skills vcs https://github.com/whilesmartphp/eloquent-skills
composer config repositories.eloquent-agent-profiles vcs https://github.com/whilesmartphp/eloquent-agent-profiles
composer require whilesmart/skillpack:dev-dev whilesmart/eloquent-skills:dev-feat/initial-package whilesmart/eloquent-agent-profiles:dev-feat/initial-package
php artisan migrate
```

It depends on `whilesmart/eloquent-skills` for the managed skill catalogue and `whilesmart/skillpack` for portable skills.

### Authorization fails closed

The default `OwnerAccessProfileAuthorizer` delegates to the `Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer` the host binds. While only the allow-all owner-access default is bound, or when there is no actor, every request is denied. Bind a strict `OwnerAuthorizer`, or set `authorizer` to your own `Whilesmart\AgentProfiles\Contracts\ProfileAuthorizer`, which also receives the ability (`view`, `create`, `update`, `delete`, `bind-skills`, `publish`, `activate`, `revoke`, `resolve`) and the `scope_key`.

Skill bindings are additionally authorized by the eloquent-skills `BindingAuthorizer`, which must be configured by the host too.

## Definitions

A draft definition is a JSON object. Every object rejects keys it does not declare, every list and string is bounded (`definition` in the config), and nothing in it names a PHP class, callable or endpoint.

```json
{
  "purpose": "Answer billing questions for customers.",
  "personality": "Calm and exact.",
  "instructions": "Quote amounts from the invoice and never guess.",
  "rules": [{
    "id": "no-refund-promises",
    "description": "Refunds need a person.",
    "evaluator": "keyword",
    "when": { "event": "before_tool", "tools": ["mail.send"] },
    "resources": ["ledger"],
    "config": { "words": ["refund"] },
    "action": "block"
  }],
  "skills": [
    { "source": "house", "reference": "house-style", "skill": "release-notes", "content_hash": "sha256:..." }
  ],
  "resources": [{ "id": "ledger", "type": "database", "reference": "ledger-main", "access": "read" }],
  "model": { "provider": "acme", "name": "acme-large", "fallbacks": [], "temperature": 0.2, "max_output_tokens": 2000 },
  "permissions": { "tools": ["invoices.read", "mail.send"], "approval_required": ["mail.send"] },
  "limits": { "max_steps": 20, "max_tool_calls": 40, "max_input_tokens": null, "max_output_tokens": 4000, "max_duration_seconds": 300 }
}
```

Rule events are `always`, `before_tool`, `after_tool` and `before_response`; actions are `block`, `warn` and `require_approval`. Rule tools must be granted in `permissions.tools` and rule resources must be declared in `resources`.

Structurally valid drafts are always saved. Publication additionally runs the configured `validators`. By default they accept no tools (`capabilities.tools`), no rule evaluators (`rule_evaluators`), no resource types (`resource_types`) and no model providers (`model_providers`), so a draft that grants anything reports it as missing until the host declares it. Add your own `Whilesmart\AgentProfiles\Contracts\DefinitionValidator` classes to the list.

Published rules are compiled into per-event and per-tool lookups (`Definition\RuleCompiler`). Nothing evaluates them here.

## Skills

`AgentProfile` uses `Whilesmart\Skills\Traits\HasSkills`. Bindings are the draft: binding a skill version goes through `SkillVersionResolver` with the profile's owner and `scope_key` and requires current availability, so skills of another owner or scope, deleted skills and revoked versions are refused. Publishing pins every current binding to its exact version and content hash. Managed entries in `definition.skills` are rejected; use the binding methods or skill routes.

External skills are off by default. Map a source key to a class implementing `Whilesmart\AgentProfiles\Contracts\ExternalSkillProvider` in `external_skills.providers`. At publication the provider's skill must match the approved `content_hash` and pass validation; it is kept as a bounded snapshot (`external_skills.max_snapshot_bytes`). Resolving a published version restores the snapshot and never fetches again. Nothing is imported into the catalogue.

## Versions and resolution

Publishing checks the current `revision`. New execution content creates version `n + 1`; unchanged content returns the existing version with HTTP 200. Renaming a profile does not create a new version. Content matching a revoked version cannot be republished.

Two tables hold the lifecycle:

- `agent_profiles` holds editable settings, skill bindings through `HasSkills`, and its edit revision.
- `agent_profile_versions` holds one immutable `content` payload, its `content_hash`, publication details, lifecycle status, and write-once revocation fields.

The payload contains the definition, compiled rules, exact skill pins, external snapshots, and ownership context. The API and execution resolver read the same payload. Activation marks the selected version active and marks the previous version inactive. Rollback activates an earlier version without copying it. Revoked versions cannot be activated. All transitions lock the profile first, so package-managed transitions serialize concurrent activation requests.

```php
use Whilesmart\AgentProfiles\Services\ExecutionManifestResolver;

$manifest = app(ExecutionManifestResolver::class)->resolveActive($profile, $actor);

$manifest->definition;    // normalized definition
$manifest->rules;         // compiled rules
$manifest->skills;        // Whilesmart\Skillpack\Pack of the pinned skills
$manifest->skillTools();  // list_skills, read_skill and read_reference from the portable core
```

Resolution throws `ProfileUnavailable` unless the profile exists, the version is active, its content still matches its hash, the profile still has the owner and scope it was published under, and every pinned managed skill still resolves for that owner and scope with the same content. Activation runs the same skill checks.

## API

All routes use `route_prefix` (default `api`) and `route_middleware` (default `api`, `auth:sanctum`). Responses use `{ "success": true, "data": ... }`; failures carry `errors.issues` where relevant. Profiles are addressed by UUID.

| Method | Path | Does |
| --- | --- | --- |
| GET | `/agent-profiles` | Paginated list; filter by `owner_type`, `owner_id`, `scope_key`, `q` |
| POST | `/agent-profiles` | Create a draft |
| GET | `/agent-profiles/{id}` | Show a draft |
| PUT/PATCH | `/agent-profiles/{id}` | Edit `name`, `description`, `definition`, `metadata` with `revision` (409 when stale) |
| DELETE | `/agent-profiles/{id}` | Soft delete |
| GET | `/agent-profiles/{id}/readiness` | What publishing would pin and what blocks it |
| GET | `/agent-profiles/{id}/skills` | Draft bindings with current availability |
| POST | `/agent-profiles/{id}/skills` | Bind `skill_version_id` with `revision` |
| DELETE | `/agent-profiles/{id}/skills/{skill}` | Unbind a skill with `revision` |
| POST | `/agent-profiles/{id}/publish` | Publish with `revision`, optionally `activate` |
| GET | `/agent-profiles/{id}/versions` | Versions, newest first |
| GET | `/agent-profiles/{id}/versions/{n}` | One version |
| POST | `/agent-profiles/{id}/versions/{n}/activate` | Make it the active version |
| POST | `/agent-profiles/{id}/versions/{n}/deactivate` | Deactivate |
| POST | `/agent-profiles/{id}/versions/{n}/revoke` | Revoke with an optional `reason` |
| GET | `/agent-profiles/{id}/versions/{n}/manifest` | Resolve the execution manifest summary |

## Customising

Everything is in `config/agent-profiles.php`:

- `models.*`, `requests.*`, `resources.*`, `response_formatter`, `controller`, `authorizer`, `validators`, `external_skills.providers`: replace a class with a subclass or an implementation of its contract. A wrong type fails at boot with a clear error.
- `route_groups` (`profiles`, `skills`, `versions`, `lifecycle`) and `disabled_routes` (route names): keep routes out.
- `write_middleware`: extra middleware on routes that change data.
- `tables` and `run_migrations`: point at tables the host owns and keep the package migration off.
- `default_scope_key`, `definition`, `max_metadata_bytes`: defaults and bounds.

Listen for `AgentProfileCreated`, `AgentProfileDraftUpdated`, `AgentProfileDeleted`, `AgentProfileSkillsChanged`, `AgentProfileVersionPublished`, `AgentProfileVersionActivated`, `AgentProfileVersionDeactivated` and `AgentProfileVersionRevoked`. All are dispatched after the surrounding transaction commits.

## Development

```bash
make bootstrap
docker compose run --rm app composer test
docker compose run --rm app composer pint
```

For local work against unreleased sibling packages, put path repositories in an ignored `.composer-local.json`.

The default owner-access bridge allows only the configured `default_scope_key`. Hosts supporting several scopes must supply a scope-aware authorizer that checks both individual operations and listing queries.

`AgentProfile` stores editable `definition` settings and a `revision` counter for concurrent edits. Publishing copies those settings into an immutable version. Profiles have a stable `uuid` and generated `slug`; either resolves the profile route. Slugs remain stable when names change.


All lifecycle mutations lock the profile first. Application code must use the profile skill methods or HTTP routes for binding edits; raw relation and database writes bypass revision checks. Transition events can be persisted by the host when activation history is required.
