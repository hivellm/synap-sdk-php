<?php

declare(strict_types=1);

namespace Synap\SDK;

use HiveLLM\Thunder\Client\Client as ThunderClient;
use HiveLLM\Thunder\Client\ClientConfig;
use HiveLLM\Thunder\Client\Config;
use HiveLLM\Thunder\Client\Credentials;
use HiveLLM\Thunder\Client\ErrorConvention;
use HiveLLM\Thunder\Client\Handshake;
use HiveLLM\Thunder\Client\HelloStyle;
use HiveLLM\Thunder\Client\PushPolicy;
use HiveLLM\Thunder\Client\ThunderException;
use HiveLLM\Thunder\Wire\Kind;
use HiveLLM\Thunder\Wire\MapEntry;
use HiveLLM\Thunder\Wire\Value;
use Synap\SDK\Exception\SynapException;
use Synap\SDK\Types\WatchEvent;

/**
 * SynapRPC transport, backed by Thunder.
 *
 * The binary transport is not hand-written here. It is
 * {@link https://github.com/hivellm/thunder Thunder} (`hivellm/thunder`) — the
 * HiveLLM family's shared binary RPC client, the same protocol implementation
 * the Synap server runs, so the two ends of the wire cannot drift.
 *
 * What that replaced: hand-rolled framing, socket handling, an
 * externally-tagged value codec, and a push loop that read frames itself.
 */
class SynapRpcTransport
{
    /** Frame-body cap, matching the server's `MAX_FRAME_BYTES`. */
    public const MAX_FRAME_BYTES = 512 * 1024 * 1024;

    /** Default SynapRPC port. */
    public const DEFAULT_RPC_PORT = 15501;

    private ?ThunderClient $client = null;

    private readonly string $endpoint;

    private readonly ClientConfig $clientConfig;

    /**
     * @param string      $host        SynapRPC host.
     * @param int         $port        SynapRPC port.
     * @param int         $timeoutSecs Connect and per-call timeout.
     * @param string|null $authToken   API key / token, sent in the handshake.
     * @param string|null $username    Username, paired with $password.
     * @param string|null $password    Password paired with $username.
     */
    public function __construct(
        string $host,
        int $port,
        private readonly int $timeoutSecs,
        ?string $authToken = null,
        ?string $username = null,
        ?string $password = null,
    ) {
        $this->endpoint = "synap://{$host}:{$port}";
        $this->clientConfig = new ClientConfig(
            connectTimeout: (float) $timeoutSecs,
            callTimeout: (float) $timeoutSecs,
            credentials: self::credentials($authToken, $username, $password),
            clientName: 'synap-php-sdk',
        );
    }

    /**
     * How Synap uses the Thunder wire, mirroring the server's `synap_config()`.
     *
     * Thunder ships one standard and zero product knowledge, so this
     * description lives in Synap's own repository. Every divergence from the
     * standard is deliberate: Synap authenticates with `AUTH` rather than a
     * mandatory `HELLO`, it ships a push-producing command (`SUBSCRIBE`), its
     * errors use the Redis-compatible prefixes it shares with its RESP3 port,
     * and its frame cap is 512 MiB rather than 64.
     */
    public static function synapConfig(): Config
    {
        return Config::standard()
            ->withScheme('synap')
            ->withPort(self::DEFAULT_RPC_PORT)
            ->withHandshake(Handshake::AuthCommand)
            ->withHelloStyle(HelloStyle::NotUsed)
            ->withPush(PushPolicy::Enabled)
            ->withErrorCodes(ErrorConvention::Resp3Prefixes)
            ->withMaxFrameBytes(self::MAX_FRAME_BYTES);
    }

    /**
     * Build handshake credentials.
     *
     * This transport never sent `AUTH`, so it could not reach a `require_auth`
     * deployment on 15501 at all — every command came back `NOAUTH`. The
     * credentials the HTTP transport already carried in an `Authorization`
     * header now open the connection here too.
     */
    private static function credentials(
        ?string $authToken,
        ?string $username,
        ?string $password,
    ): ?Credentials {
        if ($username !== null && $username !== '' && $password !== null) {
            return Credentials::userPass($username, $password);
        }
        if ($authToken !== null && $authToken !== '') {
            return Credentials::apiKey($authToken);
        }

        return null;
    }

    /**
     * Convert a plain PHP value into a Thunder wire value.
     *
     * A PHP string is an arbitrary byte sequence, not necessarily UTF-8, and
     * the SDK's surface is string-typed — so a string is the only way a caller
     * can hand over binary. Strings that are not valid UTF-8 travel as `bin`,
     * which round-trips exactly; as `str` the server rejected the frame and
     * closed the connection.
     */
    public static function toWire(mixed $v): Value
    {
        if ($v === null) {
            return Value::null();
        }
        if (is_bool($v)) {
            return Value::bool($v);
        }
        if (is_int($v)) {
            return Value::int($v);
        }
        if (is_float($v)) {
            return Value::float($v);
        }
        if (is_string($v)) {
            return mb_check_encoding($v, 'UTF-8') ? Value::str($v) : Value::bytes($v);
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return Value::array(array_map(self::toWire(...), $v));
            }
            $pairs = [];
            foreach ($v as $key => $item) {
                $pairs[] = new MapEntry(Value::str((string) $key), self::toWire($item));
            }

            return Value::map($pairs);
        }

        return Value::str((string) $v);
    }

    /**
     * Convert a Thunder wire value back to a plain PHP value.
     *
     * `Bytes` decode to a string: the SDK's surface is string-typed and a PHP
     * string holds arbitrary bytes, so binary survives. Thunder accepts both
     * the canonical MessagePack `bin` the server emits from 1.2.0 and the
     * legacy array-of-integers form, so a pre-1.2.0 server still interoperates.
     */
    public static function fromWire(Value $v): mixed
    {
        return match ($v->kind) {
            Kind::Null => null,
            Kind::Bool => $v->asBool(),
            Kind::Int => $v->asInt(),
            Kind::Float => $v->asFloat(),
            Kind::Str => $v->asStr(),
            Kind::Bytes => $v->asBytes(),
            Kind::Array => array_map(self::fromWire(...), $v->asArray() ?? []),
            Kind::Map => self::mapFromWire($v),
        };
    }

    /** @return array<string, mixed> */
    private static function mapFromWire(Value $v): array
    {
        $out = [];
        foreach ($v->asMap() ?? [] as $entry) {
            $out[(string) self::fromWire($entry->key)] = self::fromWire($entry->value);
        }

        return $out;
    }

    /** The shared client, dialed on first use. */
    private function ensureConnected(): ThunderClient
    {
        if ($this->client !== null && $this->client->isAlive()) {
            return $this->client;
        }
        $this->client = $this->dial();

        return $this->client;
    }

    /** Open a fresh Thunder client against the configured endpoint. */
    private function dial(): ThunderClient
    {
        try {
            return ThunderClient::connect(
                $this->endpoint,
                self::synapConfig(),
                $this->clientConfig,
            );
        } catch (ThunderException $e) {
            throw SynapException::networkError(
                "SynapRPC connect to {$this->endpoint} failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Execute a command over SynapRPC and return the decoded response value.
     *
     * @param string      $cmd  Wire command name (e.g. "GET", "SET")
     * @param list<mixed> $args Command arguments
     * @return mixed Decoded PHP value from the server response
     * @throws SynapException on network or server error
     */
    public function execute(string $cmd, array $args): mixed
    {
        $client = $this->ensureConnected();

        try {
            $result = $client->call(
                strtoupper($cmd),
                array_map(self::toWire(...), $args),
            );
        } catch (ThunderException $e) {
            throw SynapException::serverError($e->getMessage());
        }

        return self::fromWire($result);
    }

    /**
     * Open a dedicated push connection, subscribe, and deliver each push frame.
     *
     * The push hook is registered before `SUBSCRIBE` is sent, so a message
     * published between the server's acknowledgement and the reader starting
     * cannot be lost.
     *
     * The old implementation sent `SUBSCRIBE` with `id = 0xFFFFFFFF`, the
     * reserved push sentinel, which the server refuses outright — pub/sub over
     * SynapRPC could not work at all. Thunder allocates an ordinary request id
     * and routes push frames by the sentinel, which is what the sentinel is for.
     *
     * @param list<string>  $topics     Topic patterns to subscribe to
     * @param callable      $onMessage  Called with each decoded push message array
     * @param callable|null $shouldStop Optional predicate — the loop exits when it returns true
     * @throws SynapException on connection or subscription failure
     */
    public function subscribePush(array $topics, callable $onMessage, ?callable $shouldStop = null): void
    {
        $client = $this->dial();

        $client->onPush(static function (Value $value) use ($onMessage): void {
            $frame = self::fromWire($value);
            if (is_array($frame)) {
                $onMessage($frame);
            }
        });

        try {
            $client->call('SUBSCRIBE', array_map(self::toWire(...), $topics));
        } catch (ThunderException $e) {
            $client->close();
            throw SynapException::serverError($e->getMessage());
        }

        // Pump the connection so push frames reach the hook.
        //
        // Thunder's PHP client is synchronous and exposes no public poll: push
        // frames are only dispatched while it is reading, which it does inside
        // `call` and `collect`. So the loop issues a cheap PING and collects
        // it; any push frames that arrived in the meantime are routed to the
        // handler during that read. Asked upstream for a public poll
        // (hivellm/thunder#11); this is the workaround until it lands.
        $deadline = microtime(true) + $this->timeoutSecs;
        while (microtime(true) < $deadline && $client->isAlive()) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            try {
                $id = $client->send('PING');
                $client->collect($id, 0.25);
            } catch (ThunderException) {
                // A timeout here means no traffic in that window, which is the
                // normal idle case, not a failure.
            }
        }

        $client->close();
    }

    /**
     * Open a dedicated push connection driven by `KV.WATCH` and yield each
     * watch envelope as a {@see WatchEvent} — the watch twin of
     * {@see subscribePush}, with the same PING-based pump (thunder#11).
     *
     * The generator runs until the transport timeout elapses, `$shouldStop`
     * returns true, or the connection dies. On exit it issues `KV.UNWATCH`
     * (best-effort) before closing the connection.
     *
     * @param string        $pattern    Key or wildcard pattern (e.g. `user:*`)
     * @param string        $mode       `value` (envelopes carry the value) or `notify`
     * @param callable|null $shouldStop Optional predicate — the loop exits when it returns true
     * @return \Generator<int, WatchEvent, mixed, void>
     * @throws SynapException on connection or watch failure
     */
    public function watchPushIterator(
        string $pattern,
        string $mode = 'value',
        ?callable $shouldStop = null,
    ): \Generator {
        $client = $this->dial();

        /** @var list<WatchEvent> $buffer */
        $buffer = [];
        $client->onPush(static function (Value $value) use (&$buffer): void {
            $frame = self::fromWire($value);
            if (!is_array($frame)) {
                return;
            }
            // The bridge encodes the envelope as a JSON string.
            $payload = $frame['payload'] ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (is_array($payload)) {
                /** @var array<string, mixed> $payload */
                $buffer[] = WatchEvent::fromArray($payload);
            }
        });

        $args = [self::toWire($pattern)];
        if ($mode !== 'value') {
            $args[] = self::toWire($mode);
        }

        try {
            $result = $client->call('KV.WATCH', $args);
        } catch (ThunderException $e) {
            $client->close();
            throw SynapException::serverError($e->getMessage());
        }

        $decoded = self::fromWire($result);
        $subscriberId = '';
        if (is_array($decoded) && is_string($decoded['subscriber_id'] ?? null)) {
            $subscriberId = $decoded['subscriber_id'];
        }

        try {
            // Pump the connection so push frames reach the hook — same
            // workaround as subscribePush until thunder#11 lands.
            $deadline = microtime(true) + $this->timeoutSecs;
            while (microtime(true) < $deadline && $client->isAlive()) {
                if ($shouldStop !== null && $shouldStop()) {
                    break;
                }
                try {
                    $id = $client->send('PING');
                    $client->collect($id, 0.25);
                } catch (ThunderException) {
                    // Idle window, not a failure.
                }
                while ($buffer !== []) {
                    yield array_shift($buffer);
                }
            }
        } finally {
            // Teardown issues KV.UNWATCH so the server drops the routing entry
            // before the socket closes. Best-effort: closing unwinds it anyway.
            if ($subscriberId !== '') {
                try {
                    $client->call('KV.UNWATCH', [self::toWire($subscriberId)]);
                } catch (ThunderException) {
                    // The connection may already be gone.
                }
            }
            $client->close();
        }
    }

    /**
     * Callback flavor of {@see watchPushIterator}: invoke `$onEvent` for each
     * watch envelope until the timeout elapses or `$shouldStop` returns true.
     *
     * @param callable      $onEvent    Called with each {@see WatchEvent}
     * @param callable|null $shouldStop Optional predicate — the loop exits when it returns true
     * @throws SynapException on connection or watch failure
     */
    public function watchPush(
        string $pattern,
        callable $onEvent,
        string $mode = 'value',
        ?callable $shouldStop = null,
    ): void {
        foreach ($this->watchPushIterator($pattern, $mode, $shouldStop) as $event) {
            $onEvent($event);
        }
    }

    /** Close the connection and fail anything still in flight. */
    public function close(): void
    {
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
    }
}
