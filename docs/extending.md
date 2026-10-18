# Extending

What you can replace, and with what. These contracts come from laravel-microservices
(`Distributable\` namespace):

| Contract | Default | Replace it with |
|---|---|---|
| `Contracts\Stream\Transport`, the event stream | `array`, `null`; `redis` and `queue` from their packages | `Services\Stream\TransportManager::extend()` |
| `Contracts\Rpc\Transport`, calls between modules | a method call within the process; `http` from `mk-josias/laravel-microservices-rpc-http` between processes | `Services\Rpc\TransportManager::extend()` |
| `Contracts\Stream\Handler`, a module's reaction to an event | none | a class listed under `events.listen` |
| `Contracts\Stream\Versioned`, the versions of an event's payload | none: every event is version 1 | a payload class in the owner's foundation, mapped in `FoundationServiceProvider::$payloads` |
| `Services\Rpc\RpcService`, a module's client in the other processes | none | a subclass in `foundation/{Module}/Services`, mapped in `FoundationServiceProvider::$rpc` |
| `Models\ShadowModel`, a read-only copy | none | an abstract subclass in the owner's foundation, extended in each keeper |

The package defines the envelope format, which is why transports can be swapped.

How to write a transport, and what it has to guarantee, is in
[Writing a transport](https://mk-josias.github.io/laravel-microservices/docs/transports).
