<?php

declare(strict_types=1);

/**
 * Transport parity harness for the PHP SDK.
 *
 * Every case runs the same module call over HTTP, SynapRPC and RESP3, and the
 * three results are compared. A difference means a transport-specific hole:
 * the module surface is supposed to be identical whatever the wire.
 *
 * Each run and each transport gets its own key space — DEL only clears the KV
 * store, so a list or set from an earlier run would survive and skew counts.
 *
 * Usage: start a server with all three listeners enabled, then
 *
 *     php scripts/transport-parity.php
 *
 * It exits non-zero on any disagreement. `list.index` is expected to differ:
 * LINDEX has no native dispatcher, so the native transports refuse it up front
 * (see docs/protocol/transports.md in the server repository).
 */

require __DIR__ . '/../vendor/autoload.php';

use Synap\SDK\SynapClient;
use Synap\SDK\SynapConfig;

const TRANSPORTS = [
    'http'     => 'http://127.0.0.1:15500',
    'synaprpc' => 'synap://127.0.0.1:15501',
    'resp3'    => 'resp3://127.0.0.1:6379',
];

$runId = bin2hex(random_bytes(4));

/** Key namespaced by run and transport. */
function pkey(string $wire, string $name): string
{
    global $runId;
    return sprintf('parity:%s:%s:%s', $runId, $wire, $name);
}

/** @var array<string, callable(SynapClient, string): mixed> $cases */
$cases = [
    // ── KV ────────────────────────────────────────────────────────────────
    'kv.get' => function (SynapClient $c, string $w): mixed {
        $c->kv()->set(pkey($w, 'kv'), 'hello');
        return $c->kv()->get(pkey($w, 'kv'));
    },
    'kv.get (absent)' => fn (SynapClient $c, string $w): mixed => $c->kv()->get(pkey($w, 'absent')),
    'kv.exists' => function (SynapClient $c, string $w): mixed {
        $c->kv()->set(pkey($w, 'kv'), 'hello');
        return $c->kv()->exists(pkey($w, 'kv'));
    },
    'kv.exists (absent)' => fn (SynapClient $c, string $w): mixed => $c->kv()->exists(pkey($w, 'absent')),
    'kv.incr' => fn (SynapClient $c, string $w): mixed => $c->kv()->incr(pkey($w, 'counter'), 1),
    'kv.decr' => function (SynapClient $c, string $w): mixed {
        $c->kv()->incr(pkey($w, 'counter2'), 1);
        return $c->kv()->decr(pkey($w, 'counter2'), 1);
    },

    // ── Hash ──────────────────────────────────────────────────────────────
    'hash.set' => fn (SynapClient $c, string $w): mixed => $c->hash()->set(pkey($w, 'hash'), 'f', 'v'),
    'hash.set (update)' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash'), 'f', 'v');
        return $c->hash()->set(pkey($w, 'hash'), 'f', 'v2');
    },
    'hash.get' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash'), 'f', 'v');
        return $c->hash()->get(pkey($w, 'hash'), 'f');
    },
    'hash.getAll' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash2'), 'f', 'v');
        return $c->hash()->getAll(pkey($w, 'hash2'));
    },
    'hash.exists' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash'), 'f', 'v');
        return $c->hash()->exists(pkey($w, 'hash'), 'f');
    },
    'hash.len' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash3'), 'f', 'v');
        return $c->hash()->len(pkey($w, 'hash3'));
    },
    'hash.keys' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash4'), 'f', 'v');
        return $c->hash()->keys(pkey($w, 'hash4'));
    },
    'hash.values' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash5'), 'f', 'v');
        return $c->hash()->values(pkey($w, 'hash5'));
    },
    'hash.delete' => function (SynapClient $c, string $w): mixed {
        $c->hash()->set(pkey($w, 'hash6'), 'gone', 'v');
        return $c->hash()->delete(pkey($w, 'hash6'), 'gone');
    },
    'hash.incrBy' => fn (SynapClient $c, string $w): mixed => $c->hash()->incrBy(pkey($w, 'hash7'), 'n', 3),

    // ── List ──────────────────────────────────────────────────────────────
    'list.rpush' => fn (SynapClient $c, string $w): mixed => $c->list()->rpush(pkey($w, 'list1'), ['a', 'b']),
    'list.lpush' => fn (SynapClient $c, string $w): mixed => $c->list()->lpush(pkey($w, 'list2'), ['a']),
    'list.range' => function (SynapClient $c, string $w): mixed {
        $c->list()->rpush(pkey($w, 'list3'), ['a', 'b']);
        return $c->list()->range(pkey($w, 'list3'), 0, -1);
    },
    'list.len' => function (SynapClient $c, string $w): mixed {
        $c->list()->rpush(pkey($w, 'list4'), ['a', 'b']);
        return $c->list()->len(pkey($w, 'list4'));
    },
    'list.lpop' => function (SynapClient $c, string $w): mixed {
        $c->list()->rpush(pkey($w, 'list5'), ['a', 'b']);
        return $c->list()->lpop(pkey($w, 'list5'), 1);
    },
    'list.index' => function (SynapClient $c, string $w): mixed {
        $c->list()->rpush(pkey($w, 'list6'), ['a', 'b']);
        return $c->list()->index(pkey($w, 'list6'), 1);
    },

    // ── Set ───────────────────────────────────────────────────────────────
    'set.add' => fn (SynapClient $c, string $w): mixed => $c->set()->add(pkey($w, 'set1'), ['m1', 'm2']),
    'set.members' => function (SynapClient $c, string $w): mixed {
        $c->set()->add(pkey($w, 'set2'), ['m1']);
        return $c->set()->members(pkey($w, 'set2'));
    },
    'set.card' => function (SynapClient $c, string $w): mixed {
        $c->set()->add(pkey($w, 'set3'), ['m1', 'm2']);
        return $c->set()->card(pkey($w, 'set3'));
    },
    'set.isMember' => function (SynapClient $c, string $w): mixed {
        $c->set()->add(pkey($w, 'set4'), ['m1']);
        return $c->set()->isMember(pkey($w, 'set4'), 'm1');
    },
    'set.rem' => function (SynapClient $c, string $w): mixed {
        $c->set()->add(pkey($w, 'set5'), ['m1', 'm2']);
        return $c->set()->rem(pkey($w, 'set5'), ['m2']);
    },

    // ── Stream ────────────────────────────────────────────────────────────
    'stream.publish' => function (SynapClient $c, string $w): mixed {
        $c->stream()->getOrCreateRoom(pkey($w, 'room1'));
        return $c->stream()->publish(pkey($w, 'room1'), 'tick', ['n' => 1]);
    },
    'stream.read' => function (SynapClient $c, string $w): mixed {
        $c->stream()->getOrCreateRoom(pkey($w, 'room2'));
        $c->stream()->publish(pkey($w, 'room2'), 'tick', ['n' => 1]);
        return count($c->stream()->read(pkey($w, 'room2'), 0));
    },
    'stream.stats generation' => function (SynapClient $c, string $w): mixed {
        $c->stream()->getOrCreateRoom(pkey($w, 'room3'));
        $stats = $c->stream()->stats(pkey($w, 'room3'));
        return ($stats['generation'] ?? 0) > 0;
    },
    'stream.listRooms' => function (SynapClient $c, string $w): mixed {
        $c->stream()->getOrCreateRoom(pkey($w, 'room4'));
        return in_array(pkey($w, 'room4'), $c->stream()->listRooms(), true);
    },

    // ── Queue ─────────────────────────────────────────────────────────────
    'queue.publish' => function (SynapClient $c, string $w): mixed {
        $c->queue()->createQueue(pkey($w, 'q1'));
        return is_string($c->queue()->publish(pkey($w, 'q1'), ['n' => 1]));
    },
    'queue.consume' => function (SynapClient $c, string $w): mixed {
        $c->queue()->createQueue(pkey($w, 'q2'));
        $c->queue()->publish(pkey($w, 'q2'), ['n' => 1]);
        return $c->queue()->consume(pkey($w, 'q2'), 'parity-consumer') !== null;
    },
    'queue.list' => function (SynapClient $c, string $w): mixed {
        $c->queue()->createQueue(pkey($w, 'q3'));
        return in_array(pkey($w, 'q3'), $c->queue()->list(), true);
    },
    'queue.stats' => function (SynapClient $c, string $w): mixed {
        $c->queue()->createQueue(pkey($w, 'q4'));
        $c->queue()->publish(pkey($w, 'q4'), ['n' => 1]);
        return is_array($c->queue()->stats(pkey($w, 'q4')));
    },

    // ── Pub/Sub ───────────────────────────────────────────────────────────
    'pubsub.publish' => fn (SynapClient $c, string $w): mixed => is_int($c->pubsub()->publish('parity.topic', ['n' => 1])),
    'pubsub.listTopics' => fn (SynapClient $c, string $w): mixed => is_array($c->pubsub()->listTopics()),
];

$clients = [];
foreach (TRANSPORTS as $wire => $url) {
    $clients[$wire] = new SynapClient(new SynapConfig($url));
}

$mismatches = 0;
foreach ($cases as $label => $run) {
    $results = [];
    foreach ($clients as $wire => $client) {
        try {
            $results[$wire] = $run($client, $wire);
        } catch (\Throwable $e) {
            $results[$wire] = 'THREW: ' . $e->getMessage();
        }
    }

    $encoded = array_map(fn ($v): string => (string) json_encode($v), $results);
    $same = count(array_unique($encoded)) === 1;
    $threw = array_filter($results, fn ($v): bool => is_string($v) && str_starts_with($v, 'THREW:'));

    if (!$same || $threw !== []) {
        $mismatches++;
        printf("[DIFF] %-24s %s\n", $label, json_encode($results));
    } else {
        printf("[ok]   %-24s %s\n", $label, $encoded['http']);
    }
}

echo "\nmismatches: $mismatches of " . count($cases) . "\n";
exit($mismatches === 0 ? 0 : 1);
