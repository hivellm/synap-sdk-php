<?php

declare(strict_types=1);

namespace Synap\SDK\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use Synap\SDK\Types\WatchEvent;

final class WatchEventTest extends TestCase
{
    public function testFullEnvelopeDecodes(): void
    {
        $event = WatchEvent::fromArray([
            'key' => 'user:1',
            'event' => 'set',
            'version' => 3,
            'value' => 'alice',
        ]);

        $this->assertSame('user:1', $event->key);
        $this->assertSame('set', $event->event);
        $this->assertSame(3, $event->version);
        $this->assertSame('alice', $event->value);
        $this->assertFalse($event->truncated);
    }

    public function testOmittedOptionalFieldsTakeDefaults(): void
    {
        // A del envelope omits value and truncated entirely.
        $event = WatchEvent::fromArray([
            'key' => 'k',
            'event' => 'del',
            'version' => 7,
        ]);

        $this->assertNull($event->value);
        $this->assertFalse($event->truncated);
        $this->assertSame(7, $event->version);
    }

    public function testTruncatedEnvelopeKeepsTheFlag(): void
    {
        $event = WatchEvent::fromArray([
            'key' => 'big',
            'event' => 'set',
            'version' => 1,
            'truncated' => true,
        ]);

        $this->assertTrue($event->truncated);
        $this->assertNull($event->value);
    }

    public function testToArrayRoundTrip(): void
    {
        $event = new WatchEvent(
            key: 'user:1',
            event: 'set',
            version: 2,
            value: 'v',
        );

        $this->assertSame([
            'key' => 'user:1',
            'event' => 'set',
            'version' => 2,
            'value' => 'v',
            'truncated' => false,
        ], $event->toArray());
    }
}
