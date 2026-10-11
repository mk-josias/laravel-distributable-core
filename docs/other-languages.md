# Services in other languages

A service written in another language (a Node gateway, a Go worker) takes part as described in
[laravel-microservices](https://github.com/mk-josias/laravel-microservices/blob/main/docs/other-languages.md):
it writes and reads envelopes on the stream, and calls the modules over signed HTTP. To the
modules, it is one more service.

| In a distributable application | |
|---|---|
| declare it | in `config/microservices.php` under `services`, with its `host` |
| call a module | `POST {host}/{module}/rpc/{method}`: each module answers on its own name |
| be called by a module | its contract and `RpcService` sit in `foundation/{Name}/`, like a module's |
| be the source of a copy | the copy's shape sits in `foundation/{Name}/Shadows/`, with the service as `owner()` |

The gateway of [laravel-microservices' example](https://github.com/mk-josias/laravel-microservices/tree/main/examples)
is such a service, in Node: it asks iam over RPC who owns a token, then calls notifications.
