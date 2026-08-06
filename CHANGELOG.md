# Changelog

All notable changes to the Synap PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.1] - 2026-08-06

Catches the PHP SDK up with the server: it was still on 1.2.3 while the server
reached 1.3.1, so it carried neither the 1.3.0 transaction work nor the 1.3.1
stream fixes. The pub/sub gap was not a missing feature but a broken wire —
every command was mapped under a name the server has never dispatched — which
had shipped that way because nothing tested the command mapping. 1.3.0 is
skipped so the number matches the server release this SDK is verified against.

### Fixed
- **Deleting a key over HTTP works.** `KVStore::delete()` sent `kv.delete`,
  which the server does not dispatch — it answers `kv.del`. The native mapper
  accepted both spellings, so the break was HTTP-only and came back as
  `Unknown command: kv.delete`.
- **Topic listing follows the transport.** `pubsub.topics` mapped to `TOPICS`
  for both native wires, but only SynapRPC dispatches it: RESP3 answers
  `PUBSUB CHANNELS` and has no `TOPICS`, so listing topics over RESP3 failed.
  `mapCommand()` now takes the transport for the few commands whose wire name
  differs between the two.
- **Pub/Sub reaches the native transports at all.** Every pub/sub command was
  mapped with a `P` prefix the server has never dispatched — `PPUBLISH`,
  `PSUBSCRIBE`, `PUNSUBSCRIBE`, `PTOPICS`, `PSTATS` — so publishing or
  subscribing over SynapRPC/RESP3 failed as an unknown command. They now map to
  `PUBLISH`, `SUBSCRIBE`, `UNSUBSCRIBE`, `TOPICS` and `PSSTATS`. `SUBSCRIBE`
  takes topics only (the subscriber id is assigned by the server and returned),
  and the reply is passed through so callers keep the `subscriber_id` they need
  in order to unsubscribe.
- **`stream.stats` reaches the native transports.** It had no `SSTATS` mapping,
  so room stats were HTTP-only.

### Added
- **Transactional writes on the native transports** (ADR 005, server 1.3.0): a
  write carrying a `client_id` now travels as `TXQUEUE <client_id> <CMD>
  <args...>` so the server queues it into the open `MULTI` instead of executing
  it immediately. Every queueable write method takes an optional `$clientId`
  (`KVStore::set`/`delete`/`incr`/`decr`, `HashManager::set`/`delete`/`incrBy`,
  `ListManager::lpush`/`rpush`/`lpop`/`rpop`, `SetManager::add`/`rem`), so a
  transaction can be assembled through the modules rather than by hand-building
  payloads. Commands the server cannot queue are refused with
  `UnsupportedCommandException` rather than silently executed outside the
  transaction. The queueable set matches the server's `TransactionCommand`
  enum: `SET`/`DEL`/`INCR[BY]`/`DECR[BY]`/`HSET`/`HDEL`/`HINCRBY`/`LPUSH`/
  `RPUSH`/`LPOP`/`RPOP`/`SADD`/`SREM`.
- **Room wipe discriminator** (hivellm/synap#257, server 1.3.1): the
  `stream.stats` payload carries `created_at` and `generation`. Generation
  identifies this incarnation of the room and is strictly increasing across
  every room creation, including server restarts — remember it alongside your
  offset cursor and rewind when it changes, because every counter in the
  payload resets on a wipe and can coincide with a pre-wipe value.

## [1.2.3] - 2026-07-20

Realigns the PHP SDK onto the shared Synap SDK version — all six SDKs now sit at
1.2.3. The previous 1.2.1 tag was cut before the KV-watch commits landed, so
this release is where KV watch actually ships for PHP. 1.2.2 is skipped to reach
a single common number.

### Added
- **KV watch.** `$client->kv()->watch($pattern, $onEvent, $mode)` (callback)
  and `watchEvents($pattern, $mode)` (Generator) stream value-carrying change
  envelopes over a dedicated `KV.WATCH` push connection, decoded into the new
  `Synap\SDK\Types\WatchEvent` value object. `mode: 'notify'` asks the server
  to strip values per subscription. The pump exits on the transport timeout or
  a `$shouldStop` predicate and issues `KV.UNWATCH` on the way out. SynapRPC
  only; HTTP clients get a clear error pointing at the `/kv/ws` endpoint.

## [1.2.1] - 2026-07-19

### Changed
- **The `synap://` transport now runs on [Thunder](https://github.com/hivellm/thunder)**
  (`hivellm/thunder` 0.2.1), the family's shared binary RPC client — the same
  protocol implementation the Synap server runs, so the two ends of the wire
  cannot drift. The hand-written framing, socket handling and externally-tagged
  value codec are gone.

  This lands as 1.2.1 rather than in 1.2.0 because Thunder's PHP package reached
  Packagist after 1.2.0 had already been tagged and indexed.

### Notes
- Thunder's PHP client is synchronous and exposes no public poll, so a push
  subscription keeps the reader running by issuing a cheap `PING` and collecting
  it. Push frames that arrived meanwhile are dispatched during that read. Asked
  upstream for a public poll
  ([thunder#11](https://github.com/hivellm/thunder/issues/11)).

## [1.2.0] - 2026-07-19

The SDK now lives in its own repository, `hivellm/synap-sdk-php`, and is
consumed by the Synap repo as a submodule.

### Fixed
- **The SDK can reach an authenticated server over `synap://`.** The RPC
  transport never sent `AUTH`, so against a `require_auth` deployment on 15501
  every command came back `NOAUTH` — the transport was unusable, not degraded.
  Credentials now open both the command socket and the dedicated push socket.
- **Pub/sub over SynapRPC works.** `SUBSCRIBE` was sent with `id = 0xFFFFFFFF`,
  the reserved server-push sentinel, which the server refuses outright: `ERR
  request id u32::MAX is reserved for server push frames`. It now uses an
  ordinary request id — the sentinel identifies frames coming *from* the server
  and was never an address a client could send to.
- **Binary values survive the round trip.** A string that is not valid UTF-8 was
  packed as MessagePack `str`, and the server closed the connection. Such
  strings now travel as `bin`.

### Notes
- Verified against the same server build as every other SDK by the cross-SDK
  interop matrix: all four cells green.

## [1.0.0] - 2026-07-11

### Changed
- Version aligned with the Synap server 1.0.0 release. SynapRPC (`synap://host:15501`) is the default transport; RESP3 and HTTP remain available via URL scheme. Test suite verified against the official `hivehub/synap:1.0.0` image.

## [0.11.0] - 2026-04-09

### Added

- **URL-scheme transport selection**: `SynapConfig` constructor now parses the
  URL scheme:
  - `synap://host:port` → SynapRPC (recommended default)
  - `resp3://host:port` → RESP3
  - `http://` / `https://` → HTTP/REST
- **Full command parity on SynapRPC**: queue, stream, pub/sub, transaction,
  script, geospatial, and HyperLogLog commands mapped in `Transport.php`.
- **`UnsupportedCommandException`**: thrown for commands not mapped on the
  active native transport.
- **Synchronous server-push**: `SynapRpcTransport::subscribePush()` opens a
  dedicated blocking socket loop calling an `$onMessage` callable on each
  received push frame (`id == 0xFFFFFFFF`).
- **`PubSubManager::observe()`**: convenience wrapper using
  `subscribePush()` on `synap://` or HTTP polling otherwise.
- **`SynapClient::sendCommand()`**: sends a command with an explicit payload
  dict, bypassing the HTTP-style `target`/`key` injection.
- **E2E suite** (`sdks/php/tests/Unit/Module/RpcParityS2STest.php`): queue,
  stream, pub/sub, transaction, script across all three transports;
  `UnsupportedCommandException` regression. Gated behind `SYNAP_S2S=true`.

### Changed

- **Builder methods deprecated** (`trigger_error(E_USER_DEPRECATED)`):
  `withSynapRpcTransport`, `withResp3Transport`, `withHttpTransport`,
  `withRpcAddr`, `withResp3Addr`. Migrate to URL-scheme construction.
  Will be removed in v0.12.0.

## [0.10.0] - 2026-04-08

### Added

- **Multi-transport support**: `SynapConfig` now supports SynapRPC
  (default), RESP3 and HTTP transports via `withSynapRpcTransport()`,
  `withResp3Transport()`, `withHttpTransport()`. Override endpoints with
  `withRpcAddr(host, port)` / `withResp3Addr(host, port)`. Unmapped
  commands fall back to HTTP automatically.
- SynapRPC uses MessagePack framing over persistent TCP; RESP3 speaks
  the Redis wire protocol.

### Changed

- SDK version aligned with server and sibling SDKs (`0.2.0 → 0.10.0`).

## [0.2.0] - 2025-10-25

### Added - Redis Data Structures 🎉

**Complete Redis-compatible Hash, List, and Set data structures**

#### Hash Manager (13 commands)
- `hash()->set()`, `hash()->get()`, `hash()->getAll()`, `hash()->delete()`, `hash()->exists()`
- `hash()->keys()`, `hash()->values()`, `hash()->len()`, `hash()->mset()`, `hash()->mget()`
- `hash()->incrBy()`, `hash()->incrByFloat()`, `hash()->setNX()`

#### List Manager (9 commands)
- `list()->lpush()`, `list()->rpush()`, `list()->lpop()`, `list()->rpop()`, `list()->range()`
- `list()->len()`, `list()->index()`, `list()->set()`, `list()->trim()`

#### Set Manager (11 commands)
- `set()->add()`, `set()->rem()`, `set()->isMember()`, `set()->members()`, `set()->card()`
- `set()->pop()`, `set()->randMember()`, `set()->move()`
- `set()->inter()`, `set()->union()`, `set()->diff()`

**Usage Example**:
```php
<?php
use Synap\SDK\SynapClient;
use Synap\SDK\SynapConfig;

$client = new SynapClient(new SynapConfig('http://localhost:15500'));

// Hash operations
$client->hash()->set('user:1', 'name', 'Alice');
$name = $client->hash()->get('user:1', 'name');

// List operations
$client->list()->rpush('tasks', ['task1', 'task2']);
$tasks = $client->list()->range('tasks', 0, -1);

// Set operations
$client->set()->add('tags', ['php', 'redis']);
$isMember = $client->set()->isMember('tags', 'php');
```

## [0.1.0] - 2025-10-23

### Added

**Core Features:**
- ✅ Complete Key-Value Store API with TTL support
- ✅ Message Queue API with ACK/NACK and priority
- ✅ Event Stream API with offset tracking
- ✅ Pub/Sub API with wildcard topic support
- ✅ StreamableHTTP protocol implementation

**Type Safety:**
- ✅ PHP 8.2+ strict types
- ✅ Full type hints for all parameters and returns
- ✅ Readonly classes for immutable data types
- ✅ Named arguments support

**Developer Experience:**
- ✅ PSR-4 autoloading
- ✅ PSR-12 code style compliance
- ✅ Comprehensive error handling
- ✅ Complete API documentation
- ✅ Working examples

**Quality Assurance:**
- ✅ PHPStan max level static analysis
- ✅ PHP-CS-Fixer for code style
- ✅ PHPUnit for testing
- ✅ Composer scripts for quality checks

### Dependencies

**Core:**
- php: ^8.2
- guzzlehttp/guzzle: ^7.8 (HTTP client)

**Development:**
- phpunit/phpunit: ^11.0 (testing)
- phpstan/phpstan: ^2.0 (static analysis)
- squizlabs/php_codesniffer: ^3.10 (code style)
- friendsofphp/php-cs-fixer: ^3.64 (formatting)

### Documentation

- Complete README with API reference
- Changelog (this file)
- Examples demonstrating all features
- Inline PHPDoc for all public methods

### Compatibility

- PHP: 8.2+
- Synap Server: v1.0.0+
- Platforms: Linux, macOS, Windows

[0.1.0]: https://github.com/hivellm/synap/releases/tag/php-sdk-v0.1.0

