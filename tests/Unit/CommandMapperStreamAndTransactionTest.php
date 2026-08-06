<?php

declare(strict_types=1);

namespace Synap\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Synap\SDK\TransportMode;

use function Synap\SDK\mapCommand;
use function Synap\SDK\mapResponse;

/**
 * The native transports speak raw commands, so a wrong name fails at runtime
 * with "unknown command" rather than at parse time. These tests pin the command
 * names against what the server actually dispatches
 * (crates/synap-server/src/protocol/synap_rpc/dispatch and .../resp3/command).
 */
final class CommandMapperStreamAndTransactionTest extends TestCase
{
    public function testStreamStatsReachesTheNativeTransports(): void
    {
        $result = mapCommand('stream.stats', ['room' => 'chat']);

        $this->assertNotNull($result, 'stream.stats must map to a native command');
        $this->assertSame('SSTATS', $result[0]);
        $this->assertSame(['chat'], $result[1]);
    }

    public function testStreamStatsResponseKeepsTheWipeDiscriminators(): void
    {
        $raw = [
            'name' => 'chat',
            'message_count' => 1,
            'created_at' => 1785964676070,
            'generation' => 1785964676070,
        ];

        $parsed = mapResponse('stream.stats', $raw);

        $this->assertSame(1785964676070, $parsed['generation']);
        $this->assertSame(1785964676070, $parsed['created_at']);
    }

    public function testPubSubCommandsCarryNoPrefix(): void
    {
        $publish = mapCommand('pubsub.publish', ['topic' => 't', 'payload' => ['a' => 1]]);
        $this->assertNotNull($publish);
        $this->assertSame('PUBLISH', $publish[0]);

        $subscribe = mapCommand('pubsub.subscribe', ['topics' => ['a', 'b']]);
        $this->assertNotNull($subscribe);
        $this->assertSame('SUBSCRIBE', $subscribe[0]);
        $this->assertSame(['a', 'b'], $subscribe[1], 'SUBSCRIBE takes topics only');

        $unsubscribe = mapCommand('pubsub.unsubscribe', [
            'subscriber_id' => 'sub-1',
            'topics' => ['a'],
        ]);
        $this->assertNotNull($unsubscribe);
        $this->assertSame('UNSUBSCRIBE', $unsubscribe[0]);
        $this->assertSame(['sub-1', 'a'], $unsubscribe[1]);

        $stats = mapCommand('pubsub.stats', []);
        $this->assertNotNull($stats);
        $this->assertSame('PSSTATS', $stats[0]);
    }

    /**
     * The two native wires name topic listing differently: SynapRPC dispatches
     * TOPICS and has no PUBSUB, RESP3 dispatches PUBSUB CHANNELS and has no
     * TOPICS — so one mapping cannot serve both.
     */
    public function testTopicListingFollowsTheTransport(): void
    {
        $rpc = mapCommand('pubsub.topics', [], TransportMode::SYNAP_RPC);
        $this->assertNotNull($rpc);
        $this->assertSame('TOPICS', $rpc[0]);
        $this->assertSame([], $rpc[1]);

        $resp3 = mapCommand('pubsub.topics', [], TransportMode::RESP3);
        $this->assertNotNull($resp3);
        $this->assertSame('PUBSUB', $resp3[0]);
        $this->assertSame(['CHANNELS'], $resp3[1]);
    }

    public function testSubscribeResponseKeepsTheSubscriberId(): void
    {
        $parsed = mapResponse('pubsub.subscribe', [
            'subscriber_id' => 'sub-9',
            'subscription_count' => 2,
        ]);

        $this->assertSame('sub-9', $parsed['subscriber_id']);
        $this->assertSame(2, $parsed['subscription_count']);
    }

    /**
     * A write carrying a client_id belongs to an open MULTI and must be queued
     * through TXQUEUE (ADR 005), never executed immediately.
     */
    public function testWriteWithClientIdIsQueuedThroughTxqueue(): void
    {
        $result = mapCommand('kv.set', [
            'key' => 'k',
            'value' => ['Str' => 'v'],
            'client_id' => 'cli-1',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('TXQUEUE', $result[0]);
        $this->assertSame(['cli-1', 'SET', 'k', ['Str' => 'v']], $result[1]);
    }

    public function testEveryQueueableWriteFamilyIsWrapped(): void
    {
        $cases = [
            ['kv.del', ['key' => 'k'], 'DEL'],
            ['kv.incr', ['key' => 'k'], 'INCR'],
            ['kv.decr', ['key' => 'k'], 'DECR'],
            ['hash.set', ['key' => 'h', 'field' => 'f', 'value' => 'v'], 'HSET'],
            ['list.lpush', ['key' => 'l', 'values' => ['v']], 'LPUSH'],
            ['set.add', ['key' => 's', 'members' => ['m']], 'SADD'],
        ];

        foreach ($cases as [$cmd, $payload, $expectedInner]) {
            $result = mapCommand($cmd, $payload + ['client_id' => 'cli-1']);

            $this->assertNotNull($result, "$cmd must be queueable");
            $this->assertSame('TXQUEUE', $result[0], "$cmd should travel as TXQUEUE");
            $args = $result[1];
            $this->assertSame('cli-1', $args[0]);
            $this->assertSame($expectedInner, $args[1], "$cmd inner command");
        }
    }

    /**
     * A read the server cannot queue must be refused rather than silently
     * executed outside the transaction.
     */
    public function testUnqueueableCommandWithClientIdIsRefused(): void
    {
        $this->assertNull(mapCommand('kv.get', ['key' => 'k', 'client_id' => 'cli-1']));
        $this->assertNull(mapCommand('stream.publish', [
            'room' => 'r',
            'event' => 'e',
            'client_id' => 'cli-1',
        ]));
    }

    /**
     * `kv.delete` is an SDK-only spelling: the native mapper accepted it, so
     * the bug only showed over HTTP, where the server — which dispatches
     * `kv.del` — answered "Unknown command: kv.delete".
     */
    public function testKvDeleteSendsTheCommandTheServerDispatches(): void
    {
        $client = $this->createMock(\Synap\SDK\SynapClient::class);
        $client->expects($this->once())
            ->method('execute')
            ->with('kv.del', 'k', [])
            ->willReturn([]);

        (new \Synap\SDK\Module\KVStore($client))->delete('k');
    }

    /**
     * A write inside a transaction carries the client id all the way down to
     * the payload, which is what turns it into a TXQUEUE on the wire.
     */
    public function testWriteMethodsForwardTheClientId(): void
    {
        $client = $this->createMock(\Synap\SDK\SynapClient::class);
        $client->expects($this->once())
            ->method('execute')
            ->with('kv.set', 'k', ['value' => 'v', 'client_id' => 'cli-1'])
            ->willReturn([]);

        (new \Synap\SDK\Module\KVStore($client))->set('k', 'v', null, 'cli-1');
    }

    /**
     * The transaction control commands take the client_id as their own
     * argument — they must not be wrapped in TXQUEUE.
     */
    public function testTransactionControlCommandsAreNotWrapped(): void
    {
        foreach (['transaction.multi' => 'MULTI', 'transaction.exec' => 'EXEC', 'transaction.discard' => 'DISCARD'] as $cmd => $expected) {
            $result = mapCommand($cmd, ['client_id' => 'cli-1']);

            $this->assertNotNull($result);
            $this->assertSame($expected, $result[0]);
            $this->assertSame(['cli-1'], $result[1]);
        }
    }
}
