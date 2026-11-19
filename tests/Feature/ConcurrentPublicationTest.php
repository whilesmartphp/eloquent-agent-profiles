<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentProfiles\Models\AgentProfile;

#[RequiresPhpExtension('pcntl')]
class ConcurrentPublicationTest extends TestCase
{
    protected function connectionsToTransact()
    {
        return getenv('DB_CONNECTION') === 'mysql' ? [] : parent::connectionsToTransact();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql') {
            RefreshDatabaseState::$migrated = false;
        }
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL row locks and two independent processes.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql') {
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDown();
    }

    private function race(string $uuid, callable $parentAction, callable $childAction): array
    {
        $profileId = AgentProfile::query()->where('uuid', $uuid)->value('id');
        DB::disconnect();
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_timeout($parentSocket, 15);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            fgets($childSocket);
            fwrite($childSocket, "started\n");
            try {
                $status = $childAction()->status();
                fwrite($childSocket, json_encode(['status' => $status], JSON_THROW_ON_ERROR)."\n");
                fclose($childSocket);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($childSocket, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
                exit(1);
            }
        }
        fclose($childSocket);
        DB::purge();
        DB::beginTransaction();
        try {
            AgentProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail();
            fwrite($parentSocket, "go\n");
            $this->assertSame("started\n", fgets($parentSocket));
            $response = $parentAction();
            $read = [$parentSocket];
            $write = $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
            DB::commit();
            $childResult = json_decode((string) fgets($parentSocket), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($parentSocket);
            pcntl_waitpid($pid, $waitStatus);
        }
        $this->assertSame(0, pcntl_wexitstatus($waitStatus));
        $this->assertArrayNotHasKey('error', $childResult);

        return [$response, $childResult['status']];
    }

    #[Test]
    public function simultaneous_publishes_reuse_one_version(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        [$parent, $childStatus] = $this->race($profile['uuid'],
            fn () => $this->publish($member, $profile['uuid'], 1),
            fn () => $this->publish($member, $profile['uuid'], 1),
        );
        $parent->assertCreated();
        $this->assertSame(200, $childStatus);
        $this->assertDatabaseCount('agent_profile_versions', 1);
    }

    #[Test]
    public function competing_activations_leave_only_one_active_version(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $base = '/api/agent-profiles/'.$profile['uuid'];
        $this->publish($member, $profile['uuid'], 1)->assertCreated();
        $this->actingAs($member)->putJson($base, ['revision' => 1, 'definition' => $this->readyDefinition(['purpose' => 'Another version'])])->assertOk();
        $this->publish($member, $profile['uuid'], 2)->assertCreated();
        [$parent, $childStatus] = $this->race($profile['uuid'],
            fn () => $this->actingAs($member)->postJson($base.'/versions/1/activate'),
            fn () => $this->actingAs($member)->postJson($base.'/versions/2/activate'),
        );
        $parent->assertOk();
        $this->assertSame(200, $childStatus);
        $current = AgentProfile::query()->firstOrFail();
        $this->assertSame(1, $current->versions()->where('status', 'active')->count());
        $this->assertSame(2, $current->activeVersion()->number);
    }

    #[Test]
    public function editing_while_publication_waits_rejects_the_old_revision(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        [$parent, $childStatus] = $this->race($profile['uuid'],
            fn () => $this->actingAs($member)->putJson('/api/agent-profiles/'.$profile['uuid'], ['revision' => 1, 'definition' => $this->readyDefinition(['purpose' => 'Changed'])]),
            fn () => $this->publish($member, $profile['uuid'], 1),
        );
        $parent->assertOk();
        $this->assertSame(409, $childStatus);
        $this->assertDatabaseCount('agent_profile_versions', 0);
    }

    #[Test]
    public function revocation_while_activation_waits_cannot_activate_revoked_content(): void
    {
        $member = $this->memberOf(1);
        $profile = $this->createProfile($member);
        $this->publish($member, $profile['uuid'], 1, activate: true)->assertCreated();
        $base = '/api/agent-profiles/'.$profile['uuid'].'/versions/1';
        [$parent, $childStatus] = $this->race($profile['uuid'],
            fn () => $this->actingAs($member)->postJson($base.'/revoke'),
            fn () => $this->actingAs($member)->postJson($base.'/activate'),
        );
        $parent->assertOk();
        $this->assertSame(409, $childStatus);
        $this->assertNull(AgentProfile::query()->firstOrFail()->activeVersion());
    }
}
