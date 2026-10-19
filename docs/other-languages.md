# Programs in other languages

A program written in another language (a Node gateway, a Go worker) can take part: it emits and
consumes events on the stream, and calls the modules over RPC. The package doesn't ship a client
for it; this is what that client has to speak. To the modules, it is one more module.

Declare it in `config/distributable.php` under `declared`, with its `host`, like any other module.
A consumer acknowledges without reading any entry whose `emitter` isn't a declared module.

Everything that leaves a process is JSON: the envelope on the stream, the body and the answer of an
RPC call, and the cached answers. Nothing is serialized in a PHP format.

| The program wants to | It does |
|---|---|
| announce something | [writes an envelope to the stream](#events-on-redis) |
| react to a module's events | [reads the stream](#events-on-redis) with its own consumer group |
| ask a module something | [calls its RPC route](#rpc-over-http), signed |
| be asked by a module | [answers that same route](#answering-a-module) |
| own data the others keep a copy of | [announces each change](#being-the-source-of-a-copy) |
| read or forget a cached answer | [reads or deletes its key](#cached-answers) |

In a distributable application, the program also takes these places:

| | |
|---|---|
| declare it | in `config/distributable.php` under `declared`, with its `host` |
| be called by a module | its messages and client sit in `foundation/{Name}/`, like a module's |
| be the source of a copy | the copy's shape sits in `foundation/{Name}/Shadows/`, with the program as the `module:` of its `#[Shadow]` |

## Events on Redis

| | |
|---|---|
| Stream | the stream's `key`, `distributable:events` for the `default` stream, behind the prefix of the Redis connection: with the starter's `REDIS_PREFIX=database:`, the key Redis holds is `database:distributable:events`. Set `REDIS_PREFIX=` to have the bare key |
| Entry | `XADD {key} * envelope {json}`: one field, `envelope`, holding [the envelope](./events#on-the-wire) as JSON |
| `emitter` | the program's name in `declared` |
| `id` | a UUID, unique per event: the consumption guard keys on it |
| Consuming | one consumer group per consuming module, named after it, created at `0`; acknowledge with `XACK` once handled |

## RPC over HTTP

```
POST {host}/{module}/rpc/{version}/{message}
Content-Type: application/json
X-Rpc-Timestamp: 1790000000            unix seconds, within signature_ttl of the server's clock
X-Rpc-Nonce:     <a fresh UUID>        accepted once
X-Rpc-Context:   {"trace_id":"…"}      the propagated Context keys, as JSON; {} when none
X-Rpc-Signature: hex(hmac_sha256(secret,
                     timestamp + "\n" + nonce + "\n" + path + "\n" + body + "\n" + context))

{"payload": {"id": 42}}
```

`path` is `/{module}/rpc/{version}/{message}`, with its leading slash, or what `rpc-http.path` sets
(`DISTRIBUTABLE_RPC_PATH`) when the processes changed it. `message` is the message's class name in
kebab case (`find-user`) unless its `#[Rpc]` says otherwise, and `version` is its version. `body`
and `context` are signed as sent, byte for byte. `payload` holds the message's properties by name.
`secret` is `rpc-http.secret`. The answer is the handler's return value as JSON:

| Status | Means |
|---|---|
| 200 | the return value, as JSON |
| 404 | the handler returned `null` |
| 400 | the module answers no such message or version: `{"message": "…"}` |
| 403 | the signature is missing, stale, wrong or already used |
| 422 | the message's rules refuse the payload: `{"errors": {"field": ["…"]}}` |

## Cached answers

An `RpcClient` keeps an answer under its key, behind the prefix of the Redis connection
(`REDIS_PREFIX`) and the one of the cache (`CACHE_PREFIX`), as JSON, in the database of Laravel's
`cache` Redis connection (`REDIS_CACHE_DB`, 1 by default, where the stream is in 0):

```
GET database:cache:iam:user:42   →   {"id":42,"name":"Ada"}
DEL database:cache:iam:user:42       when the module owns the user and it changes
```

with `REDIS_PREFIX=database:` and `CACHE_PREFIX=cache:`, the starter's defaults. Laravel's own are
`{app name}-database-` and `{app name}-cache-`: every process then needs the same `APP_NAME`, or
the same two prefixes set.

The key after the prefixes is the one the owner's client passes to `readThrough()`.

## Answering a module

A Laravel module calls the program exactly as it calls another module: through a message and its
client, with the program's `host` in `declared`. The program receives the request above on
`POST /{name}/rpc/{version}/{message}`, checks the signature, and answers the return value as
JSON, or a 404 for `null`. Any other status is an error for the caller.

To call it over something else than this HTTP format (gRPC, an existing REST API), give its host a
transport of your own: see [Writing a driver](./extending).

## Being the source of a copy

A Laravel module can keep a [copy](./shadows) of rows a program owns, with the program as the
`module:` of the copy's `#[Shadow]`. The program then announces each change with a `distributable.shadow.changed`
event:

```json
{
  "id": "0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e88",
  "emitter": "identity",
  "name": "distributable.shadow.changed",
  "payload": { "source": "users", "key": 42, "attributes": { "name": "Ada" } },
  "headers": {},
  "emitted_at": "2026-09-30T13:22:41.512000Z",
  "recipients": [],
  "stream": "default",
  "version": 1
}
```

`source` is the `sourceTable()` of the copy, `key` the row's id (an integer or a string),
`attributes` the columns of the copy. A deleted row is announced with a `deleted_at` among the
attributes. Each module that keeps a copy writes it in its own database when it consumes the event.
