# Services in other languages

A service written in another language (a Node gateway, a Go worker) takes part as described in
[laravel-microservices](https://mk-josias.github.io/laravel-microservices/docs/other-languages):
it writes and reads envelopes on the stream, and calls the modules over signed HTTP. To the
modules, it is one more service.

| In a distributable application | |
|---|---|
| declare it | in `config/distributable.php` under `declared`, with its `host` |
| call a module | `POST {host}/{module}/rpc/{version}/{message}` with `{"payload": {…}}`: each module answers on its own name |
| be called by a module | its messages and client sit in `foundation/{Name}/`, like a module's |
| be the source of a copy | the copy's shape sits in `foundation/{Name}/Shadows/`, with the service as `owner()` |

The gateway of [laravel-microservices' example](https://github.com/mk-josias/laravel-microservices/tree/main/examples)
is such a service, in Node: it asks iam over RPC who owns a token, then calls notifications.
