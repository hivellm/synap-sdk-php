<?php

declare(strict_types=1);

namespace Synap\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Synap\SDK\mapHttpResponse;
use function Synap\SDK\mapResponse;

/**
 * The HTTP endpoint answers with an envelope whose payload is shaped slightly
 * differently from the native wire reply. Modules read one shape, so both paths
 * have to produce it — returning the envelope made every read return the empty
 * value (`kv()->get()` was `null` over HTTP while SynapRPC answered correctly).
 *
 * Each case pins the HTTP normalisation against the native one for the same
 * command, which is the invariant that actually matters.
 */
final class HttpResponseShapeTest extends TestCase
{
    /**
     * `kv.get` answers with a bare scalar on both wires; the modules read a
     * named field.
     */
    public function testScalarPayloadsGetTheirCanonicalFieldName(): void
    {
        $this->assertSame(['value' => 'v1'], mapHttpResponse('kv.get', 'v1'));
        $this->assertSame(mapResponse('kv.get', 'v1'), mapHttpResponse('kv.get', 'v1'));

        // An absent key answers null on both paths.
        $this->assertSame(['value' => null], mapHttpResponse('kv.get', null));
    }

    /**
     * The server reports whether the hash field was newly created; the module
     * asks whether the write succeeded, which it did unless the call threw.
     */
    public function testHashSetReportsSuccessNotCreation(): void
    {
        $overwrite = mapHttpResponse('hash.set', ['created' => false, 'field' => 'f', 'key' => 'h']);

        $this->assertTrue($overwrite['success'], 'overwriting a field is still a successful write');
        $this->assertFalse($overwrite['created']);

        // The native path agrees: HSET answers 0 on an overwrite.
        $this->assertTrue(mapResponse('hash.set', 0)['success']);
        $this->assertFalse(mapResponse('hash.set', 0)['created']);
        $this->assertTrue(mapResponse('hash.set', 1)['created']);
    }

    /**
     * `set.card` is served by the size handler, which names the count `size`;
     * the canonical field — the one SetManager::card reads — is `cardinality`.
     */
    public function testSetCardinalityIsNamedTheSameOnBothPaths(): void
    {
        $http = mapHttpResponse('set.card', ['size' => 3]);

        $this->assertSame(3, $http['cardinality']);
        $this->assertSame(3, mapResponse('set.card', 3)['cardinality']);
    }

    /**
     * Most payloads already carry the field names the modules read, so they
     * pass through untouched rather than being rewritten case by case.
     */
    public function testRichPayloadsPassThrough(): void
    {
        $cases = [
            ['kv.exists', ['exists' => true]],
            ['kv.incr', ['value' => 7]],
            ['hash.getall', ['count' => 1, 'fields' => ['f' => 'v']]],
            ['hash.keys', ['count' => 1, 'keys' => ['f']]],
            ['hash.len', ['length' => 1]],
            ['list.rpush', ['key' => 'l', 'length' => 2]],
            ['list.range', ['key' => 'l', 'values' => ['a', 'b']]],
            ['set.members', ['members' => ['m1']]],
            ['set.ismember', ['is_member' => true]],
            ['stream.stats', ['name' => 'r', 'generation' => 123]],
        ];

        foreach ($cases as [$cmd, $payload]) {
            $this->assertSame($payload, mapHttpResponse($cmd, $payload), "$cmd should pass through");
        }
    }

    /**
     * A command that answers nothing must not produce a stray field the module
     * would then read as data.
     */
    public function testNullPayloadBecomesAnEmptyArray(): void
    {
        $this->assertSame([], mapHttpResponse('queue.ack', null));
    }
}
