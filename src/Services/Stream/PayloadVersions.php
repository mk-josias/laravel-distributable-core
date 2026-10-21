<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Attributes\Payload;
use Distributable\Contracts\Stream\Versioned;
use Distributable\Data\Envelope;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Discovery\ClassAttribute;

/** Lifts the payload of an envelope to the version this process reads, so a handler only ever sees the current shape. */
final class PayloadVersions
{
    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    private array $payloads = [];

    /** @param list<class-string> $payloads */
    public function add(array $payloads): void
    {
        foreach ($payloads as $class) {
            if (! is_subclass_of($class, Versioned::class)) {
                throw ConfigurationException::unversionedPayload($class, Versioned::class);
            }

            $payload = ClassAttribute::of($class, Payload::class)
                ?? throw ConfigurationException::missingAttribute($class, Payload::class);

            $this->payloads[$payload->event] = $class;
        }
    }

    /** The version of $name this process reads: 1 until its payload class declares another. */
    public function current(string $name): int
    {
        return isset($this->payloads[$name]) ? $this->declared($this->payloads[$name])->version : 1;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ModuleException when the envelope is newer than this process: its consumer has to be deployed first
     */
    public function lift(Envelope $envelope): array
    {
        $current = $this->current($envelope->name);

        if ($envelope->version > $current) {
            throw ModuleException::eventVersionAhead($envelope->name, $envelope->version, $current);
        }

        $payload = $envelope->payload;

        for ($version = $envelope->version; $version < $current; $version++) {
            $payload = $this->payloads[$envelope->name]::upcast($version, $payload);
        }

        return $payload;
    }

    /** @param class-string $class */
    private function declared(string $class): Payload
    {
        return ClassAttribute::of($class, Payload::class)
            ?? throw ConfigurationException::missingAttribute($class, Payload::class);
    }
}
