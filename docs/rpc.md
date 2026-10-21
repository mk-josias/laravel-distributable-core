# Calling another module

By the end of this page, `analytics` asks `iam` for a user and gets the answer, whether `iam` runs in
the same process or in another.

Use a call when you need the answer right away: reading data the other module owns, or having it do
something whose result you need. When you don't need the result, [announce an event](./events)
instead. A call is not part of your transaction: if your code fails afterwards, what the other
module did stays done.

## The call

```php
// apps/Analytics/app/..., anywhere in analytics
$user = app(\Foundation\Iam\Services\IamClient::class)->findUser($id);
```

`IamClient` belongs to `iam`. Analytics never imports a class of `iam`: it knows the client and the
messages, which live in the [foundation](./foundation).

## Write the message

A call is a message. `iam` puts it in the foundation, with the rules that validate it, so every
caller has it:

```php
// foundation/Iam/Rpc/FindUser.php
#[Rpc(module: 'iam')]
final readonly class FindUser implements Message
{
    public function __construct(public int $id) {}

    public static function rules(): array
    {
        return ['id' => ['required', 'integer']];
    }
}
```

On the wire the message is called by its class name in kebab case (`find-user`). Change that with
`name:`: `#[Rpc(module: 'iam', name: 'get-user')]`.

## Answer it

The handler lives in `iam`, next to its models, and answers with its own data:

```php
// apps/Iam/app/Rpc/FindUserHandler.php
/** @implements Handler<FindUser> */
final class FindUserHandler implements Handler
{
    public function handle(FindUser $message): ?array
    {
        $user = User::query()->find($message->id);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
```

and `iam`'s provider lists it:

```php
// apps/Iam/app/Providers/IamServiceProvider.php
protected array $rpcHandlers = [
    FindUser::class => FindUserHandler::class,
];
```

`iam` writes no route: the package serves one for each message it answers. The handler runs with
iam's database and settings, wherever the call comes from. Returning `null` is an answer: the caller
reads `null`.

## The client

`iam` also ships the client that callers use, in the foundation:

```php
// foundation/Iam/Services/IamClient.php
final class IamClient extends \Distributable\Services\Rpc\RpcClient
{
    public function findUser(int $id): ?array
    {
        return $this->readThrough(
            "iam:user:{$id}",
            // a week: iam forgets the key when the user changes
            self::DEFAULT_TTL,
            // the raw answer, the one kept in the cache
            fn () => $this->send(new FindUser($id)),
            fn (array $raw) => ['id' => $raw['id'], 'name' => $raw['name']],
        );
    }
}
```

`send()` validates the message with its rules, then delivers it. A payload the rules refuse throws a
`ValidationException`.

## Where iam runs

Analytics doesn't change when `iam` moves. What decides is the configuration of the process:

- `iam` runs in this process (`RUN_MODULES`): the handler is called directly. There is no HTTP, no
  signature, nothing on the network.
- `iam` runs elsewhere: the call goes to its host, `declared.iam.host`:

```dotenv
# read by declared.iam.host
IAM_HOST=http://iam.svc:8000
```

The container side, with the network alias that makes `iam.svc` resolve, is in
[Deploying](./deploying#deploying-a-module-on-its-own).

## Keep an answer

Cache what is read often and changes rarely (a user, a product): a long lifetime, and the module
that owns the data forgets the answer when it writes. Don't cache what must be exact on every call,
such as a token that can be revoked. Data to join, filter or sort on locally belongs in a
[copy](./shadows), not in this cache.

`readThrough()`, used by `findUser()` above, keeps the answer: the next call doesn't leave the
process. The owner writes the methods that forget:

```php
// foundation/Iam/Services/IamClient.php, called by iam when the user changes
public function forgetUser(int $id): void
{
    $this->forget("iam:user:{$id}");
}

// not cached: a revoked token must stop working at once
public function findUserByToken(string $token): ?array
{
    return $this->send(new FindUserByToken($token));
}
```

- `readThrough($key, $ttl, $fetch, $map, $tags)` reads the cache. On a miss it calls `$fetch` and
  keeps the raw answer. `$map` turns the raw answer into what you return, on every read, so a
  mapping changed by a deploy applies to answers cached before it. An answer `$map` can no longer
  read is logged, dropped and returned as `null`.
- `readThroughUntil($key, $fetch, $map, $ttlOf)` is the same, with a lifetime read from the mapped
  answer, for example a token kept until it expires. A lifetime of zero or less returns `null`.
- `forget($key, $tags)` drops an answer.

The answers live in the cache store named by `distributable.rpc.cache`. The caller keeps an answer
and the owner forgets it, so every process uses the same store, with the same `CACHE_PREFIX` and
`REDIS_PREFIX`. An answer is kept as JSON: on Redis, the key `{REDIS_PREFIX}{CACHE_PREFIX}iam:user:42`
holds `{"id":42,"name":"Ada"}`, so a program in another language can read or delete it.

## Change a message

A message has a version, 1 unless `#[Rpc(module: 'iam', version: 2)]` says otherwise. A new version
is a new class. `iam` registers both in `$rpcHandlers` and serves both while callers move over:

```php
protected array $rpcHandlers = [
    FindUser::class => FindUserHandler::class,
    FindUserV2::class => FindUserV2Handler::class,
];
```

A version `iam` doesn't register is refused with a 400.

## What can go wrong

- **A module can't use another's classes.** Analytics uses `IamClient` and the messages, never
  iam's models or handlers. `modules:doctor` and your tests report the crossing.
- **A module can't run its code as another.** `ModuleContext::within()`, called from another
  module's code or from the foundation, throws `ModuleException`.
- **An unregistered message is refused.** Over HTTP, a message or a version the module doesn't list
  in `$rpcHandlers` answers 400.
- **The path stays private.** A gateway forwards client requests to a module's API routes, never to
  `{module}/rpc/…`.

## Over HTTP

The `http` transport comes with
[`mk-josias/laravel-distributable-rpc-http`](./rpc-http). It serves
`POST {module}/rpc/{version}/{message}` for each module the process runs, with the body
`{"payload": {…}}`. Every call is signed with a secret shared by all processes: `rpc-http.secret`,
which reads `DISTRIBUTABLE_RPC_SECRET` and defaults to `APP_KEY`. When processes have their own
keys, set the same `DISTRIBUTABLE_RPC_SECRET` on both sides, otherwise every call gets a 403.

For another protocol, see [Writing a driver](./extending).
