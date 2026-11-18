## [1.0.0] - 2026-11-18
- Owner-scoped `AgentProfile` drafts with public UUIDs, `scope_key` isolation, revision-checked edits and soft delete
- Strict, bounded definition schema covering purpose, personality, instructions, structured rules, skill references, resources, model policy, permission ceiling and limits; unknown keys are rejected
- Configurable capability validators for tools, rule evaluators, resource types and model providers that deny anything the host has not declared
- Managed skills bound through the shared `HasSkills` trait and pinned at publication via `SkillVersionResolver` with the profile's owner and scope
- Optional external skills through host-configured providers, pinned by approved content hash and snapshotted
- Immutable `AgentProfileVersion` manifests with canonical SHA-256 hashes; activation, deactivation and revocation stored separately
- Fail-closed execution manifest resolution that rechecks profile, version, owner, scope and every pinned skill
- Configurable authorizer, models, requests, resources, response formatter, controller, route groups, disabled routes and write middleware
- Domain events dispatched after commit
