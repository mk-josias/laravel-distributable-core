<?php

declare(strict_types=1);

namespace Distributable\Data;

use Carbon\CarbonImmutable;

/**
 * The wire contract. Every transport carries this shape and nothing else — that is what makes
 * them interchangeable, and why the package owns the format rather than inheriting a broker
 * library's. Metadata lives here, never in the business payload.
 */
final readonly class Envelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers  context carried across modules (distributable.events.propagate)
     * @param  list<string>  $recipients  the only modules that handle it; empty for every module
     */
    public function __construct(
        public string $id,
        public string $emitter,
        public string $name,
        public array $payload,
        public array $headers,
        public CarbonImmutable $emittedAt,
        public string $stream,
        public array $recipients = [],
        public int $version = 1,
    ) {}

    public function isFor(string $module): bool
    {
        return $this->recipients === [] || in_array($module, $this->recipients, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'emitter' => $this->emitter,
            'name' => $this->name,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'emitted_at' => $this->emittedAt->toIso8601ZuluString('microsecond'),
            'recipients' => $this->recipients,
            'stream' => $this->stream,
            'version' => $this->version,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            emitter: (string) $data['emitter'],
            name: (string) $data['name'],
            payload: (array) ($data['payload'] ?? []),
            headers: (array) ($data['headers'] ?? []),
            emittedAt: CarbonImmutable::parse((string) $data['emitted_at']),
            recipients: array_values(array_map(strval(...), (array) ($data['recipients'] ?? []))),
            stream: (string) $data['stream'],
            version: (int) ($data['version'] ?? 1),
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }
}
