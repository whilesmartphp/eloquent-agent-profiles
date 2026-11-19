<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Whilesmart\AgentProfiles\Support\Canonical;

class CanonicalTest extends TestCase
{
    #[Test]
    public function object_key_order_does_not_change_the_hash_but_list_order_does(): void
    {
        $a = ['b' => 1, 'a' => ['y' => true, 'x' => [1, 2]]];
        $b = ['a' => ['x' => [1, 2], 'y' => true], 'b' => 1];
        $c = ['a' => ['x' => [2, 1], 'y' => true], 'b' => 1];

        $this->assertSame(Canonical::hash($a), Canonical::hash($b));
        $this->assertNotSame(Canonical::hash($a), Canonical::hash($c));
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', Canonical::hash($a));
    }

    #[Test]
    public function a_json_round_trip_keeps_the_hash(): void
    {
        $value = ['temperature' => 1.0, 'name' => 'é/ü', 'empty' => []];

        $this->assertSame(Canonical::hash($value), Canonical::hash(json_decode(json_encode($value), true)));
    }
}
