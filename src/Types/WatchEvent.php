<?php

declare(strict_types=1);

namespace Synap\SDK\Types;

/**
 * One KV watch envelope (`docs/features/kv-watch.md` in the server repository).
 *
 * `value` is the **post-mutation** value and is null for terminal events
 * (`del`, `expired`, `evicted`), TTL-only events (`expire`, `persist`), and
 * envelopes degraded to notify-only (`truncated === true`).
 */
readonly class WatchEvent
{
    public function __construct(
        /** The key that changed */
        public string $key,
        /** What happened: set, del, expired, evicted, expire, persist, ... */
        public string $event,
        /**
         * Per-key counter for gap detection. Resets when the key is deleted,
         * expires or is evicted — version 1 marks a new incarnation.
         */
        public int $version,
        /** The post-mutation value, when inlined */
        public ?string $value = null,
        /** True when the value was withheld (over the inline cap, or not UTF-8) */
        public bool $truncated = false,
    ) {
    }

    /**
     * Build from a decoded envelope array; omitted fields take their defaults.
     *
     * @param array<string, mixed> $envelope
     */
    public static function fromArray(array $envelope): self
    {
        $value = $envelope['value'] ?? null;

        return new self(
            key: is_string($envelope['key'] ?? null) ? $envelope['key'] : '',
            event: is_string($envelope['event'] ?? null) ? $envelope['event'] : '',
            version: is_numeric($envelope['version'] ?? null) ? (int) $envelope['version'] : 0,
            value: is_string($value) ? $value : null,
            truncated: ($envelope['truncated'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'event' => $this->event,
            'version' => $this->version,
            'value' => $this->value,
            'truncated' => $this->truncated,
        ];
    }
}
