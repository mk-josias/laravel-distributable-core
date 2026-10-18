<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Closure;
use Distributable\Config\Streamer;
use Distributable\Contracts\Stream\Transport;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Transports\Stream\ArrayTransport;
use Distributable\Transports\Stream\NullTransport;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a named stream of microservices.events.streams to its transport, like QueueManager::connection(),
 * and stays open: extend('kafka', fn ($app, array $options, string $stream) => …) registers any
 * driver. The package ships only `array` and `null`; `redis` and `queue` come with their own packages.
 */
final class TransportManager
{
    /** @var array<string, Closure(Container, array<string, mixed>, string): Transport> */
    private array $creators = [];

    /** @var array<string, array{options: array<string, mixed>, transport: Transport}> */
    private array $streams = [];

    public function __construct(private readonly Container $container) {}

    /** A stream is rebuilt when its options change, so config stays live. */
    public function stream(?string $name = null): Transport
    {
        $name ??= $this->container->make(Streamer::class)->getDefaultStream();
        $options = $this->container->make(Streamer::class)->getStream($name);

        if (($this->streams[$name]['options'] ?? null) !== $options) {
            $this->streams[$name] = ['options' => $options, 'transport' => $this->create($name, $options)];
        }

        return $this->streams[$name]['transport'];
    }

    /** @param Closure(Container, array<string, mixed>, string): Transport $creator */
    public function extend(string $driver, Closure $creator): static
    {
        $this->creators[$driver] = $creator;
        $this->streams = [];

        return $this;
    }

    /** @param array<string, mixed> $options */
    private function create(string $name, array $options): Transport
    {
        $driver = (string) ($options['driver'] ?? '');

        if (isset($this->creators[$driver])) {
            return ($this->creators[$driver])($this->container, $options, $name);
        }

        return match ($driver) {
            'array' => new ArrayTransport,
            'null' => new NullTransport,
            default => throw ConfigurationException::unknownDriver($driver, $name),
        };
    }
}
