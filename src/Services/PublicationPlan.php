<?php

namespace Whilesmart\AgentProfiles\Services;

use Whilesmart\AgentProfiles\Support\Issue;

final class PublicationPlan
{
    /**
     * @param  array<string, mixed>|null  $definition
     * @param  array<Issue>  $issues
     * @param  array<int, array<string, mixed>>  $managed
     * @param  array<int, array<string, mixed>>  $external
     * @param  array<string, array<string, mixed>>  $snapshots
     * @param  array<string, mixed>|null  $rules
     */
    public function __construct(
        public readonly int $revision,
        public readonly ?array $definition,
        public readonly array $issues,
        public readonly array $managed = [],
        public readonly array $external = [],
        public readonly array $snapshots = [],
        public readonly ?array $rules = null,
    ) {}

    public function ready(): bool
    {
        return $this->definition !== null && ! Issue::anyErrors($this->issues);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready(),
            'revision' => $this->revision,
            'issues' => array_map(fn (Issue $issue) => $issue->toArray(), $this->issues),
            'skills' => [
                'managed' => $this->managed,
                'external' => $this->external,
            ],
        ];
    }
}
