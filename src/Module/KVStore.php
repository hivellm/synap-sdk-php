<?php

declare(strict_types=1);

namespace Synap\SDK\Module;

use Synap\SDK\Exception\SynapException;
use Synap\SDK\SynapClient;
use Synap\SDK\Types\WatchEvent;

/**
 * Key-Value Store operations
 */
class KVStore
{
    public function __construct(
        private SynapClient $client
    ) {
    }

    /**
     * Set a key-value pair
     *
     * @param string|null $clientId Queue the write into the open MULTI keyed by
     *                              this client id instead of applying it now
     */
    public function set(string $key, mixed $value, ?int $ttl = null, ?string $clientId = null): void
    {
        $data = ['value' => $value];

        if ($ttl !== null) {
            $data['ttl'] = $ttl;
        }
        if ($clientId !== null) {
            $data['client_id'] = $clientId;
        }

        $this->client->execute('kv.set', $key, $data);
    }

    /**
     * Get a value by key
     */
    public function get(string $key): mixed
    {
        $response = $this->client->execute('kv.get', $key);

        return $response['value'] ?? null;
    }

    /**
     * Delete a key
     *
     * @param string|null $clientId Queue the write into the open MULTI keyed by
     *                              this client id instead of applying it now
     */
    public function delete(string $key, ?string $clientId = null): void
    {
        $data = $clientId !== null ? ['client_id' => $clientId] : [];

        // `kv.del` is the command the server dispatches; `kv.delete` only ever
        // worked because the native mapper accepted both spellings, so a delete
        // over HTTP came back "Unknown command: kv.delete".
        $this->client->execute('kv.del', $key, $data);
    }

    /**
     * Check if key exists
     */
    public function exists(string $key): bool
    {
        $response = $this->client->execute('kv.exists', $key);

        return (bool) ($response['exists'] ?? false);
    }

    /**
     * Increment a numeric value
     *
     * @param string|null $clientId Queue the write into the open MULTI keyed by
     *                              this client id instead of applying it now.
     *                              A queued write returns 0 — the real value is
     *                              in the EXEC result list.
     */
    public function incr(string $key, int $delta = 1, ?string $clientId = null): int
    {
        $data = ['delta' => $delta];
        if ($clientId !== null) {
            $data['client_id'] = $clientId;
        }

        $response = $this->client->execute('kv.incr', $key, $data);
        $value = $response['value'] ?? 0;

        assert(is_int($value) || is_numeric($value));

        return (int) $value;
    }

    /**
     * Decrement a numeric value
     *
     * @param string|null $clientId Queue the write into the open MULTI keyed by
     *                              this client id instead of applying it now.
     *                              A queued write returns 0 — the real value is
     *                              in the EXEC result list.
     */
    public function decr(string $key, int $delta = 1, ?string $clientId = null): int
    {
        $data = ['delta' => $delta];
        if ($clientId !== null) {
            $data['client_id'] = $clientId;
        }

        $response = $this->client->execute('kv.decr', $key, $data);
        $value = $response['value'] ?? 0;

        assert(is_int($value) || is_numeric($value));

        return (int) $value;
    }

    /**
     * Get KV store statistics
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->client->execute('kv.stats', '*');
    }

    /**
     * Scan keys by prefix
     *
     * @return array<string>
     */
    public function scan(string $prefix, int $limit = 100): array
    {
        $response = $this->client->execute('kv.scan', $prefix, ['limit' => $limit]);
        $keys = $response['keys'] ?? [];

        assert(is_array($keys));

        /** @var array<string> */
        return $keys;
    }

    /**
     * Watch a key (or wildcard pattern) and invoke `$onEvent` for each change.
     *
     * SynapRPC only (`synap://` URL): opens a dedicated push connection driven
     * by `KV.WATCH` and blocks the current PHP process, calling `$onEvent`
     * with a {@see WatchEvent} per key change. Delivery is best-effort,
     * latest-value — use `WatchEvent::$version` to detect gaps. The loop exits
     * when the transport timeout elapses or `$shouldStop` returns true, and
     * issues `KV.UNWATCH` on the way out.
     *
     * @param string        $pattern    Key or wildcard pattern (e.g. `user:*`)
     * @param callable      $onEvent    Called with each {@see WatchEvent}
     * @param string        $mode       `value` (default) or `notify` (no value bandwidth)
     * @param callable|null $shouldStop Optional predicate — the loop exits when it returns true
     * @throws SynapException when the client is not on the SynapRPC transport
     */
    public function watch(
        string $pattern,
        callable $onEvent,
        string $mode = 'value',
        ?callable $shouldStop = null,
    ): void {
        $this->requireRpc()->watchPush($pattern, $onEvent, $mode, $shouldStop);
    }

    /**
     * Iterator flavor of {@see watch}: yields a {@see WatchEvent} per change.
     *
     * @param string        $pattern    Key or wildcard pattern (e.g. `user:*`)
     * @param string        $mode       `value` (default) or `notify`
     * @param callable|null $shouldStop Optional predicate — the loop exits when it returns true
     * @return \Generator<int, WatchEvent, mixed, void>
     * @throws SynapException when the client is not on the SynapRPC transport
     */
    public function watchEvents(
        string $pattern,
        string $mode = 'value',
        ?callable $shouldStop = null,
    ): \Generator {
        yield from $this->requireRpc()->watchPushIterator($pattern, $mode, $shouldStop);
    }

    /**
     * @throws SynapException when the client is not on the SynapRPC transport
     */
    private function requireRpc(): \Synap\SDK\SynapRpcTransport
    {
        $rpc = $this->client->getSynapRpcTransport();
        if ($rpc === null) {
            throw SynapException::serverError(
                'kv watch requires the synap:// transport; over HTTP use the /kv/ws WebSocket endpoint'
            );
        }

        return $rpc;
    }
}
