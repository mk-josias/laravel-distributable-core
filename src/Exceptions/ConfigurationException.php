<?php

declare(strict_types=1);

namespace Distributable\Exceptions;

use RuntimeException;

/** A setting or a declared class the package cannot work with. */
final class ConfigurationException extends RuntimeException
{
    public static function missingAttribute(string $class, string $attribute): self
    {
        return new self("[{$class}] must carry #[{$attribute}].");
    }

    public static function invalidHandler(string $class, string $contract): self
    {
        return new self("Event handler [{$class}] must implement {$contract}.");
    }

    public static function unversionedPayload(string $class, string $contract): self
    {
        return new self("[{$class}] is in \$payloads: it must implement {$contract}.");
    }

    public static function unknownStream(string $name): self
    {
        return new self("Stream [{$name}] is not declared: add it to distributable.events.streams.");
    }

    public static function unknownDriver(string $driver, string $stream): self
    {
        $package = ['redis' => 'mk-josias/laravel-distributable-stream-redis'][$driver] ?? null;

        return new self($package === null
            ? "Stream driver [{$driver}] of stream [{$stream}] is not supported: register it with Services\\Stream\\TransportManager::extend()."
            : "Stream driver [{$driver}] of stream [{$stream}] comes with its own package: composer require {$package}.");
    }

    public static function unknownRpcTransport(string $name): self
    {
        return new self("RPC transport [{$name}] is not declared: add it to distributable.rpc.transports.");
    }

    public static function unknownRpcDriver(string $driver, string $transport): self
    {
        return new self("RPC driver [{$driver}] of transport [{$transport}] is not supported: register it with Services\\Rpc\\TransportManager::extend(), or install the package that does.");
    }

    public static function noRpcTransport(): self
    {
        return new self('No RPC transport is configured: install the package of one (composer require mk-josias/laravel-distributable-rpc-http for HTTP) or set distributable.rpc.transport.');
    }

    public static function unarchivableStream(string $stream): self
    {
        return new self("The transport of stream [{$stream}] cannot give its entries back: exporting needs one implementing Contracts\\Stream\\ArchivesStreams.");
    }

    public static function invalidExportFilter(string $column): self
    {
        return new self("Cannot filter an export on [{$column}]: use name, emitter or payload.{field}.");
    }

    public static function unknownArchiveStore(string $name): self
    {
        return new self("Archive store [{$name}] is not supported: register it with Services\\Stream\\ArchiveStores::extend().");
    }

    public static function messageWithoutRpc(string $class): self
    {
        return new self("[{$class}] is a message without #[Rpc]: it names no module.");
    }

    public static function messageOfAnotherModule(string $message, string $owner, string $module): self
    {
        return new self("[{$message}] belongs to module [{$owner}]: module [{$module}] cannot answer it in \$rpcHandlers.");
    }

    public static function handlerWithoutContract(string $handler): self
    {
        return new self("[{$handler}] answers a message in \$rpcHandlers but does not implement Contracts\\Rpc\\Handler.");
    }

    public static function handlerOfAnotherMessage(string $handler, string $message): self
    {
        return new self("[{$handler}] must declare handle({$message} \$message): it has no handle(), or its first parameter is another type.");
    }

    public static function missingRpcHost(string $module): self
    {
        return new self("No RPC host configured for module [{$module}]: set distributable.declared.{$module}.host.");
    }
}
