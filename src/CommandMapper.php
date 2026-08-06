<?php

declare(strict_types=1);

namespace Synap\SDK;

/**
 * Map dotted SDK commands to native wire commands and parse wire responses.
 *
 * These functions are in the Synap\SDK namespace so callers can import them
 * with `use function Synap\SDK\mapCommand` or call them as
 * \Synap\SDK\mapCommand(...).
 *
 * This file is loaded via composer autoload.files so the functions are
 * available globally within the namespace without explicit class references.
 */

/**
 * Raw commands the server can queue inside a MULTI via `TXQUEUE` (ADR 005) —
 * exactly the server's TransactionCommand enum.
 *
 * @var list<string>
 */
const TXQUEUEABLE = [
    'SET', 'DEL',
    'INCR', 'DECR', 'INCRBY', 'DECRBY',
    'HSET', 'HDEL', 'HINCRBY',
    'LPUSH', 'RPUSH', 'LPOP', 'RPOP',
    'SADD', 'SREM',
];

/**
 * Map a dotted SDK command + payload to a native wire command + ordered args.
 *
 * Returns null for commands that have no native mapping (HTTP only).
 *
 * @param string               $cmd       Dotted command key, e.g. "kv.set"
 * @param array<string, mixed> $payload   Full operation payload
 * @param string|null          $transport Target transport, for the few commands
 *                                        whose wire name differs between
 *                                        SynapRPC and RESP3
 * @return array{0: string, 1: list<mixed>}|null  [WIRE_CMD, args] or null
 */
function mapCommand(string $cmd, array $payload, ?string $transport = null): ?array
{
    // Transactional writes (payload carries a client_id) travel as
    // `TXQUEUE <client_id> <CMD> <args...>` so the server queues them into the
    // open MULTI instead of executing immediately (ADR 005). Commands the
    // server cannot queue return null (-> UnsupportedCommandException), never a
    // silent execution outside the transaction.
    $clientId = $payload['client_id'] ?? null;
    if (is_scalar($clientId) && (string) $clientId !== '' && !str_starts_with($cmd, 'transaction.')) {
        $rest = $payload;
        unset($rest['client_id']);
        $inner = mapCommand($cmd, $rest, $transport);
        if ($inner === null || !in_array($inner[0], TXQUEUEABLE, true)) {
            return null;
        }
        return ['TXQUEUE', array_merge([(string) $clientId, $inner[0]], $inner[1])];
    }

    $key    = (string) ($payload['key'] ?? '');
    $value  = $payload['value'] ?? null;
    $field  = (string) ($payload['field'] ?? '');
    $fields = (array) ($payload['fields'] ?? []);
    $ttl    = $payload['ttl'] ?? null;

    switch ($cmd) {
        // ── KV ──────────────────────────────────────────────────────────────
        case 'kv.get':
            return ['GET', [$key]];
        case 'kv.set':
            if ($ttl !== null) {
                return ['SET', [$key, $value, 'EX', $ttl]];
            }
            return ['SET', [$key, $value]];
        case 'kv.del':
        case 'kv.delete':
            return ['DEL', [$key]];
        case 'kv.exists':
            return ['EXISTS', [$key]];
        case 'kv.expire':
            return ['EXPIRE', [$key, $payload['seconds'] ?? 0]];
        case 'kv.ttl':
            return ['TTL', [$key]];
        case 'kv.persist':
            return ['PERSIST', [$key]];
        case 'kv.incr':
            return ['INCR', [$key]];
        case 'kv.incrby':
            return ['INCRBY', [$key, $payload['amount'] ?? 1]];
        case 'kv.decr':
            return ['DECR', [$key]];
        case 'kv.decrby':
            return ['DECRBY', [$key, $payload['amount'] ?? 1]];
        case 'kv.append':
            return ['APPEND', [$key, $value]];
        case 'kv.strlen':
            return ['STRLEN', [$key]];
        case 'kv.getset':
            return ['GETSET', [$key, $value]];
        case 'kv.setnx':
            return ['SETNX', [$key, $value]];
        case 'kv.scan':
            return ['SCAN', [
                $payload['cursor'] ?? 0,
                'MATCH',
                $payload['pattern'] ?? '*',
                'COUNT',
                $payload['count'] ?? 100,
            ]];
        case 'kv.keys':
            return ['KEYS', [$payload['pattern'] ?? '*']];
        case 'kv.type':
            return ['TYPE', [$key]];
        case 'kv.rename':
            return ['RENAME', [$key, $payload['new_key'] ?? '']];
        case 'kv.copy':
            return ['COPY', [$key, $payload['destination'] ?? '']];
        case 'kv.stats':
            return ['INFO', ['keyspace']];

        // ── Hash ─────────────────────────────────────────────────────────────
        case 'hash.get':
            return ['HGET', [$key, $field]];
        case 'hash.set':
            return ['HSET', [$key, $field, $value]];
        case 'hash.del':
            return ['HDEL', [$key, $field]];
        case 'hash.exists':
            return ['HEXISTS', [$key, $field]];
        case 'hash.getall':
            return ['HGETALL', [$key]];
        case 'hash.keys':
            return ['HKEYS', [$key]];
        case 'hash.vals':
        case 'hash.values':
            return ['HVALS', [$key]];
        case 'hash.len':
            return ['HLEN', [$key]];
        case 'hash.mget':
            return ['HMGET', array_merge([$key], (array) ($payload['fields'] ?? []))];
        case 'hash.mset': {
            $args = [$key];
            foreach ($fields as $k => $v) {
                $args[] = $k;
                $args[] = $v;
            }
            return ['HMSET', $args];
        }
        // The modules send `increment`; reading only `amount` meant every
        // native increment silently used the default of 1.
        case 'hash.incrby':
            return ['HINCRBY', [$key, $field, $payload['increment'] ?? $payload['amount'] ?? 1]];
        case 'hash.incrbyfloat':
            return ['HINCRBYFLOAT', [$key, $field, $payload['increment'] ?? $payload['amount'] ?? 1.0]];
        case 'hash.setnx':
            return ['HSETNX', [$key, $field, $value]];

        // ── List ─────────────────────────────────────────────────────────────
        // The push/pop family takes a list of values, not the single `value`
        // field: pushing sent the whole array as one element, and popping
        // ignored the requested count.
        case 'list.lpush':
            return ['LPUSH', array_merge([$key], array_values((array) ($payload['values'] ?? [$value])))];
        case 'list.rpush':
            return ['RPUSH', array_merge([$key], array_values((array) ($payload['values'] ?? [$value])))];
        case 'list.lpop':
            return ['LPOP', [$key, $payload['count'] ?? 1]];
        case 'list.rpop':
            return ['RPOP', [$key, $payload['count'] ?? 1]];
        // The modules send the short spellings, which the server accepts as
        // aliases; without them here the command had no native mapping at all.
        case 'list.lrange':
        case 'list.range':
            return ['LRANGE', [$key, $payload['start'] ?? 0, $payload['stop'] ?? -1]];
        case 'list.llen':
        case 'list.len':
            return ['LLEN', [$key]];
            // LINDEX, LSET, LTRIM, LREM and LPOS have no native dispatcher —
            // neither SynapRPC nor RESP3 answers them — so they are left
            // unmapped on purpose. Returning null raises
            // UnsupportedCommandException, which is honest; mapping them
            // produced "unknown command" from the server instead.

        // ── Set ──────────────────────────────────────────────────────────────
        // Like the list family, these take `members`, and the modules send
        // `set.rem` rather than the `set.remove` this mapper knew about.
        case 'set.add':
            return ['SADD', array_merge([$key], array_values((array) ($payload['members'] ?? [$value])))];
        case 'set.rem':
        case 'set.remove':
            return ['SREM', array_merge([$key], array_values((array) ($payload['members'] ?? [$value])))];
        case 'set.members':
            return ['SMEMBERS', [$key]];
        case 'set.ismember':
            return ['SISMEMBER', [$key, $payload['member'] ?? $value]];
        case 'set.card':
            return ['SCARD', [$key]];
        case 'set.pop':
            return ['SPOP', [$key]];
        case 'set.randmember':
            return ['SRANDMEMBER', [$key, $payload['count'] ?? 1]];
        case 'set.union':
            return ['SUNION', array_merge([$key], (array) ($payload['keys'] ?? []))];
        case 'set.inter':
            return ['SINTER', array_merge([$key], (array) ($payload['keys'] ?? []))];
        case 'set.diff':
            return ['SDIFF', array_merge([$key], (array) ($payload['keys'] ?? []))];
        case 'set.move':
            return ['SMOVE', [$key, $payload['destination'] ?? '', $value]];

        // ── Sorted Set ───────────────────────────────────────────────────────
        case 'sorted_set.add':
            return ['ZADD', [$key, $payload['score'] ?? 0.0, $value]];
        case 'sorted_set.score':
            return ['ZSCORE', [$key, $value]];
        case 'sorted_set.rank':
            return ['ZRANK', [$key, $value]];
        case 'sorted_set.revrank':
            return ['ZREVRANK', [$key, $value]];
        case 'sorted_set.range':
            return ['ZRANGE', [$key, $payload['start'] ?? 0, $payload['stop'] ?? -1, 'WITHSCORES']];
        case 'sorted_set.revrange':
            return ['ZREVRANGE', [$key, $payload['start'] ?? 0, $payload['stop'] ?? -1, 'WITHSCORES']];
        case 'sorted_set.card':
            return ['ZCARD', [$key]];
        case 'sorted_set.count':
            return ['ZCOUNT', [$key, $payload['min'] ?? '-inf', $payload['max'] ?? '+inf']];
        case 'sorted_set.rem':
            return ['ZREM', [$key, $value]];
        case 'sorted_set.incrby':
            return ['ZINCRBY', [$key, $payload['increment'] ?? 1.0, $value]];

        // ── Queue ─────────────────────────────────────────────────────────────
        case 'queue.create':
            return ['QCREATE', [
                (string) ($payload['name'] ?? ''),
                (int)    ($payload['max_depth'] ?? 0),
                (int)    ($payload['ack_deadline_secs'] ?? 0),
            ]];
        case 'queue.delete':
            return ['QDELETE', [(string) ($payload['queue'] ?? '')]];
        case 'queue.publish': {
            // The module hands over the message as a byte array (that is what
            // the HTTP endpoint takes); the native wire wants the bytes as a
            // string, so fold it back rather than JSON-encoding the array.
            $message = $payload['payload'] ?? null;
            $body = is_array($message) && $message === array_filter($message, 'is_int')
                ? implode('', array_map('chr', $message))
                : json_encode($message);

            $args = [
                (string) ($payload['queue'] ?? ''),
                $body,
                (int)    ($payload['priority'] ?? 0),
                (int)    ($payload['max_retries'] ?? 3),
            ];
            return ['QPUBLISH', $args];
        }
        case 'queue.consume':
            return ['QCONSUME', [
                (string) ($payload['queue'] ?? ''),
                (string) ($payload['consumer_id'] ?? ''),
            ]];
        case 'queue.ack':
            return ['QACK', [
                (string) ($payload['queue'] ?? ''),
                (string) ($payload['message_id'] ?? ''),
            ]];
        case 'queue.nack':
            return ['QNACK', [
                (string) ($payload['queue'] ?? ''),
                (string) ($payload['message_id'] ?? ''),
                (int)    ($payload['delay_secs'] ?? 0),
            ]];
        case 'queue.stats':
            return ['QSTATS', [(string) ($payload['queue'] ?? '')]];
        case 'queue.purge':
            return ['QPURGE', [(string) ($payload['queue'] ?? '')]];
        case 'queue.list':
            return ['QLIST', []];

        // ── Stream ────────────────────────────────────────────────────────────
        case 'stream.create':
            return ['SCREATE', [(string) ($payload['room'] ?? '')]];
        case 'stream.get_or_create':
            return ['SGETORCREATE', [(string) ($payload['room'] ?? '')]];
        case 'stream.delete':
            return ['SDELETE', [(string) ($payload['room'] ?? '')]];
        case 'stream.publish':
            return ['SPUBLISH', [
                (string) ($payload['room'] ?? ''),
                (string) ($payload['event'] ?? ''),
                json_encode($payload['data'] ?? null),
            ]];
        case 'stream.consume':
            return ['SREAD', [
                (string) ($payload['room'] ?? ''),
                (string) ($payload['subscriber_id'] ?? 'sdk-reader'),
                (string) ($payload['from_offset'] ?? 0),
            ]];
        case 'stream.list':
            return ['SLIST', []];
        case 'stream.stats':
            return ['SSTATS', [(string) ($payload['room'] ?? '')]];

        // ── Pub/Sub ───────────────────────────────────────────────────────────
        // The wire names carry no `P` prefix: the server dispatches PUBLISH /
        // SUBSCRIBE / UNSUBSCRIBE / PUBSUB CHANNELS / PSSTATS.
        case 'pubsub.publish':
            return ['PUBLISH', [
                (string) ($payload['topic'] ?? ''),
                json_encode($payload['payload'] ?? null),
            ]];
        case 'pubsub.subscribe':
            return ['SUBSCRIBE', array_values((array) ($payload['topics'] ?? []))];
        case 'pubsub.unsubscribe':
            return ['UNSUBSCRIBE', array_merge(
                [(string) ($payload['subscriber_id'] ?? '')],
                array_values((array) ($payload['topics'] ?? []))
            )];
        case 'pubsub.topics':
            // The two native wires name this differently: SynapRPC dispatches
            // TOPICS and has no PUBSUB, RESP3 dispatches PUBSUB CHANNELS (the
            // Redis spelling) and has no TOPICS.
            return $transport === TransportMode::RESP3
                ? ['PUBSUB', ['CHANNELS']]
                : ['TOPICS', []];
        case 'pubsub.stats':
            return ['PSSTATS', []];

        // ── Transaction ───────────────────────────────────────────────────────
        case 'transaction.multi':
            return ['MULTI', [(string) ($payload['client_id'] ?? '')]];
        case 'transaction.exec':
            return ['EXEC', [(string) ($payload['client_id'] ?? '')]];
        case 'transaction.discard':
            return ['DISCARD', [(string) ($payload['client_id'] ?? '')]];
        case 'transaction.watch':
            return ['WATCH', array_merge(
                [(string) ($payload['client_id'] ?? '')],
                (array) ($payload['keys'] ?? [])
            )];
        case 'transaction.unwatch':
            return ['UNWATCH', [(string) ($payload['client_id'] ?? '')]];

        // ── Scripting ─────────────────────────────────────────────────────────
        case 'script.eval':
            return ['EVAL', [
                (string) ($payload['script'] ?? ''),
                count((array) ($payload['keys'] ?? [])),
                ...array_merge(
                    (array) ($payload['keys'] ?? []),
                    (array) ($payload['args'] ?? [])
                ),
            ]];
        case 'script.evalsha':
            return ['EVALSHA', [
                (string) ($payload['sha'] ?? ''),
                count((array) ($payload['keys'] ?? [])),
                ...array_merge(
                    (array) ($payload['keys'] ?? []),
                    (array) ($payload['args'] ?? [])
                ),
            ]];

        // ── HyperLogLog ───────────────────────────────────────────────────────
        case 'hyperloglog.pfadd':
            return ['PFADD', array_merge([$key], (array) ($payload['elements'] ?? []))];
        case 'hyperloglog.pfcount':
            return ['PFCOUNT', array_merge([$key], (array) ($payload['keys'] ?? []))];
        case 'hyperloglog.pfmerge':
            return ['PFMERGE', array_merge(
                [(string) ($payload['destination'] ?? '')],
                [$key],
                (array) ($payload['sources'] ?? [])
            )];

        // ── Geospatial ────────────────────────────────────────────────────────
        case 'geo.add':
            return ['GEOADD', [
                $key,
                (float) ($payload['longitude'] ?? 0.0),
                (float) ($payload['latitude'] ?? 0.0),
                (string) ($payload['member'] ?? ''),
            ]];
        case 'geo.dist':
            return ['GEODIST', [
                $key,
                (string) ($payload['member1'] ?? ''),
                (string) ($payload['member2'] ?? ''),
                (string) ($payload['unit'] ?? 'm'),
            ]];
        case 'geo.pos':
            return ['GEOPOS', [$key, (string) ($payload['member'] ?? '')]];
        case 'geo.radius':
            return ['GEORADIUS', [
                $key,
                (float) ($payload['longitude'] ?? 0.0),
                (float) ($payload['latitude'] ?? 0.0),
                (float) ($payload['radius'] ?? 0.0),
                (string) ($payload['unit'] ?? 'm'),
                'WITHCOORD', 'WITHDIST', 'ASC',
                'COUNT', (int) ($payload['count'] ?? 100),
            ]];
        case 'geo.search':
            return ['GEOSEARCH', [
                $key,
                'FROMMEMBER', (string) ($payload['member'] ?? ''),
                'BYRADIUS', (float) ($payload['radius'] ?? 0.0), (string) ($payload['unit'] ?? 'm'),
                'ASC',
                'COUNT', (int) ($payload['count'] ?? 100),
            ]];
        case 'geo.hash':
            return ['GEOHASH', [$key, (string) ($payload['member'] ?? '')]];

        default:
            return null;
    }
}

/**
 * Map an HTTP reply payload to the same array shape the native path produces.
 *
 * The StreamableHTTP endpoint answers with an envelope
 * (`{success, request_id, payload, error}`) whose payload is already a rich
 * JSON object for most commands, while the native path hands modules the
 * output of {@see mapResponse()}. Modules read one shape, so the payload is
 * normalised here: the envelope is peeled off by the caller, and the handful of
 * commands whose HTTP payload names a field differently — or is a bare scalar —
 * are realigned.
 *
 * @param string $cmd     Dotted SDK command key, e.g. "kv.get"
 * @param mixed  $payload The envelope's `payload` field
 * @return array<string, mixed>
 */
function mapHttpResponse(string $cmd, mixed $payload): array
{
    /** @var array<string, mixed> $fields */
    $fields = is_array($payload) ? $payload : [];

    switch ($cmd) {
        // A bare scalar on the wire; the modules read a named field.
        case 'kv.get':
        case 'set.pop':
            return ['value' => $payload];

        // The server reports whether the field was newly created; the module
        // asks whether the write succeeded, which it did unless it threw.
        case 'hash.set':
        case 'hash.mset':
            return $fields + ['success' => true];

        // `set.card` is served by the size handler, which names the count
        // `size`; the canonical field is `cardinality`.
        case 'set.card':
            if (array_key_exists('size', $fields)) {
                return $fields + ['cardinality' => $fields['size']];
            }
            return is_array($payload) ? $fields : ['cardinality' => $payload];

        default:
            if (is_array($payload)) {
                return $fields;
            }
            // Anything else scalar (or null) keeps the envelope-free shape the
            // modules already tolerate.
            return $payload === null ? [] : ['value' => $payload];
    }
}

/**
 * Map a raw wire response to the array shape expected by SDK modules.
 *
 * @param string $cmd Dotted SDK command key, e.g. "kv.get"
 * @param mixed  $raw Decoded wire value from the transport
 * @return array<string, mixed>
 */
function mapResponse(string $cmd, mixed $raw): array
{
    switch ($cmd) {
        // ── KV ──────────────────────────────────────────────────────────────
        case 'kv.get':
            return ['value' => $raw];
        case 'kv.set':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'kv.del':
        case 'kv.delete':
            return ['deleted' => (bool) $raw];
        case 'kv.exists':
            return ['exists' => (bool) $raw];
        case 'kv.expire':
        case 'kv.persist':
            return ['success' => (bool) $raw];
        case 'kv.ttl':
            return ['ttl' => $raw];
        case 'kv.incr':
        case 'kv.incrby':
        case 'kv.decr':
        case 'kv.decrby':
            return ['value' => $raw];
        case 'kv.append':
        case 'kv.strlen':
            return ['length' => $raw];
        case 'kv.setnx':
            return ['success' => (bool) $raw];
        case 'kv.getset':
            return ['old_value' => $raw];
        case 'kv.scan':
            if (is_array($raw) && count($raw) >= 2) {
                return ['cursor' => $raw[0], 'keys' => (array) $raw[1]];
            }
            return ['cursor' => 0, 'keys' => []];
        case 'kv.keys':
            return ['keys' => is_array($raw) ? $raw : []];
        case 'kv.type':
            return ['type' => $raw];
        case 'kv.rename':
        case 'kv.copy':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'kv.stats':
            return is_array($raw) ? $raw : ['result' => $raw];

        // ── Hash ─────────────────────────────────────────────────────────────
        case 'hash.get':
            return ['value' => $raw];
        case 'hash.set':
            // HSET answers 1 for a new field and 0 for an overwrite, but both
            // are successful writes — reporting failure on every update is
            // wrong. `created` keeps the distinction for callers that want it.
            return ['success' => true, 'created' => (bool) $raw];
        case 'hash.setnx':
            return ['success' => (bool) $raw];
        case 'hash.del':
            return ['deleted' => (bool) $raw];
        case 'hash.exists':
            return ['exists' => (bool) $raw];
        case 'hash.getall':
            if (is_array($raw) && array_is_list($raw)) {
                $fieldMap = [];
                for ($i = 0; $i < count($raw) - 1; $i += 2) {
                    $fieldMap[(string) $raw[$i]] = $raw[$i + 1];
                }
                return ['fields' => $fieldMap];
            }
            return ['fields' => is_array($raw) ? $raw : []];
        case 'hash.keys':
            return ['keys' => is_array($raw) ? $raw : []];
        case 'hash.vals':
        case 'hash.values':
            return ['values' => is_array($raw) ? $raw : []];
        case 'hash.len':
            return ['length' => $raw];
        case 'hash.mget':
            return ['values' => is_array($raw) ? $raw : []];
        case 'hash.mset':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'hash.incrby':
        case 'hash.incrbyfloat':
            return ['value' => $raw];

        // ── List ─────────────────────────────────────────────────────────────
        case 'list.lpush':
        case 'list.rpush':
            return ['length' => $raw];
        case 'list.lpop':
        case 'list.rpop':
            // The modules read `values`: popping with a count answers an array,
            // popping one answers the element itself.
            if (is_array($raw)) {
                return ['values' => $raw, 'value' => $raw[0] ?? null];
            }
            return ['values' => $raw === null ? [] : [$raw], 'value' => $raw];
        case 'list.lrange':
        case 'list.range':
            return ['values' => is_array($raw) ? $raw : []];
        case 'list.llen':
        case 'list.len':
            return ['length' => $raw];
        case 'list.lindex':
        case 'list.index':
            return ['value' => $raw];
        case 'list.lset':
        case 'list.set':
        case 'list.ltrim':
        case 'list.trim':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'list.lrem':
            return ['removed' => $raw];
        case 'list.lpos':
            return ['index' => $raw];

        // ── Set ──────────────────────────────────────────────────────────────
        case 'set.add':
            return ['added' => $raw];
        case 'set.rem':
        case 'set.remove':
            return ['removed' => $raw];
        case 'set.members':
            return ['members' => is_array($raw) ? $raw : []];
        case 'set.ismember':
            return ['is_member' => (bool) $raw];
        case 'set.card':
            return ['cardinality' => $raw];
        case 'set.pop':
            return ['value' => $raw];
        case 'set.randmember':
            return ['members' => is_array($raw) ? $raw : [$raw]];
        case 'set.union':
        case 'set.inter':
        case 'set.diff':
            return ['members' => is_array($raw) ? $raw : []];
        case 'set.move':
            return ['success' => (bool) $raw];

        // ── Sorted Set ───────────────────────────────────────────────────────
        case 'sorted_set.add':
            return ['added' => $raw];
        case 'sorted_set.rem':
            return ['removed' => $raw];
        case 'sorted_set.score':
            return ['score' => $raw !== null ? (float) $raw : null];
        case 'sorted_set.rank':
        case 'sorted_set.revrank':
            return ['rank' => $raw];
        case 'sorted_set.range':
        case 'sorted_set.revrange':
        case 'sorted_set.rangebyscore':
            if (is_array($raw) && array_is_list($raw)) {
                $members = [];
                for ($i = 0; $i < count($raw) - 1; $i += 2) {
                    $members[] = ['member' => (string) $raw[$i], 'score' => (float) $raw[$i + 1]];
                }
                return ['members' => $members];
            }
            return ['members' => []];
        case 'sorted_set.card':
            return ['cardinality' => $raw];
        case 'sorted_set.count':
            return ['count' => $raw];
        case 'sorted_set.incrby':
            return ['score' => (float) $raw];

        // ── Queue ─────────────────────────────────────────────────────────────
        case 'queue.create':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'queue.delete':
        case 'queue.purge':
            return ['success' => (bool) $raw];
        case 'queue.publish':
            return ['message_id' => is_string($raw) ? $raw : (string) ($raw ?? '')];
        case 'queue.consume':
            if ($raw === null || $raw === 'Null') {
                return [];
            }
            if (is_array($raw)) {
                return ['message' => $raw];
            }
            return [];
        case 'queue.ack':
        case 'queue.nack':
            return ['success' => (bool) $raw];
        case 'queue.stats':
            return is_array($raw) ? $raw : ['result' => $raw];
        case 'queue.list':
            return ['queues' => is_array($raw) ? $raw : []];

        // ── Stream ────────────────────────────────────────────────────────────
        case 'stream.create':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'stream.get_or_create':
            // SGETORCREATE returns "CREATED" / "EXISTS" on the SynapRPC
            // wire. Normalise to the StreamableHTTP shape.
            if (is_array($raw)) {
                return $raw;
            }
            return ['created' => $raw === 'CREATED'];
        case 'stream.delete':
            return ['success' => (bool) $raw];
        case 'stream.publish':
            return ['offset' => is_int($raw) ? $raw : (int) ($raw ?? 0)];
        case 'stream.consume':
            return ['events' => is_array($raw) ? $raw : []];
        case 'stream.list':
            return ['rooms' => is_array($raw) ? $raw : []];
        case 'stream.stats':
            // SSTATS replies with the stats map itself, including the
            // created_at/generation wipe discriminators added in 1.3.1.
            return is_array($raw) ? $raw : [];

        // ── Pub/Sub ───────────────────────────────────────────────────────────
        case 'pubsub.publish':
            return ['subscribers_matched' => is_int($raw) ? $raw : (int) ($raw ?? 0)];
        case 'pubsub.subscribe':
            // SUBSCRIBE replies with { subscriber_id, subscription_count }; the
            // caller needs the id to unsubscribe later.
            return is_array($raw) ? $raw : ['success' => (bool) $raw];
        case 'pubsub.unsubscribe':
            return ['success' => (bool) $raw];
        case 'pubsub.topics':
            return ['topics' => is_array($raw) ? $raw : []];
        case 'pubsub.stats':
            return is_array($raw) ? $raw : ['result' => $raw];

        // ── Transaction ───────────────────────────────────────────────────────
        case 'transaction.multi':
        case 'transaction.discard':
        case 'transaction.watch':
        case 'transaction.unwatch':
            return ['success' => $raw === 'OK' || $raw === true];
        case 'transaction.exec':
            return ['success' => true, 'results' => is_array($raw) ? $raw : []];

        // ── Scripting ─────────────────────────────────────────────────────────
        case 'script.eval':
        case 'script.evalsha':
            return ['result' => $raw];

        // ── HyperLogLog ───────────────────────────────────────────────────────
        case 'hyperloglog.pfadd':
            return ['changed' => (bool) $raw];
        case 'hyperloglog.pfcount':
            return ['count' => is_int($raw) ? $raw : (int) ($raw ?? 0)];
        case 'hyperloglog.pfmerge':
            return ['success' => $raw === 'OK' || $raw === true];

        // ── Geospatial ────────────────────────────────────────────────────────
        case 'geo.add':
            return ['added' => is_int($raw) ? $raw : (int) ($raw ?? 0)];
        case 'geo.dist':
            return ['distance' => $raw !== null ? (float) $raw : null];
        case 'geo.pos':
            return ['position' => is_array($raw) ? $raw : null];
        case 'geo.radius':
        case 'geo.search':
            return ['members' => is_array($raw) ? $raw : []];
        case 'geo.hash':
            return ['hash' => is_string($raw) ? $raw : null];

        default:
            if (is_array($raw)) {
                return $raw;
            }
            return ['result' => $raw];
    }
}
