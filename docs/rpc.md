# Calls between modules (RPC)

Calls between modules are [laravel-microservices' RPC](https://mk-josias.github.io/laravel-microservices/docs/rpc):
each module is a service, named after it. This page covers what a module adds; the signature, the
status codes, the answer cache and custom transports are described there.

A call is a message. The module that answers puts the message in its foundation, with the rules
that validate it, so every caller has it whether the module runs in the same process or not. It
writes the handler in its own directory, and the foundation gives callers a client:

```
foundation/Iam/Rpc/FindUser.php               the message: its module, its version, its rules
foundation/Iam/Services/IamClient.php         extends RpcClient: every call to iam goes through it
apps/Iam/app/Rpc/FindUserHandler.php          answers the message with iam's own data
apps/Iam/app/Providers/IamServiceProvider     declares the handler in $rpc
```

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

// apps/Iam/app/Rpc/FindUserHandler.php
final class FindUserHandler
{
    public function handle(FindUser $message): ?array
    {
        $user = User::query()->find($message->id);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}

// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Distributable\Providers\ServiceProvider
{
    protected array $rpc = [
        FindUser::class => FindUserHandler::class,
    ];
}

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

The message names its module in `#[Rpc]`, and its name on the wire is its class name in kebab case
(`find-user`) unless `name:` says otherwise. Other modules type-hint `IamClient` and don't need to
know where iam runs.

## Versions

A message has a version, 1 unless `#[Rpc(module: 'iam', version: 2, name: 'find-user')]` says
otherwise. A new version is a new class; iam registers both in `$rpc` and serves both while callers
move over. A version iam doesn't register is refused with a 400.

## One path, wherever the module runs

```
analytics ─► IamClient::findUser(1) ─► Rpc::send(new FindUser(1))
                                              │  validated by FindUser::rules()
                          does iam run in this process (RUN_MODULES)?
        ┌─────────────────────────────────────┴──────────────────────────────────┐
        │ yes: the handler is called                                             │ no: POST {iam's host}/iam/rpc/1/find-user, signed
        ▼                                                                        ▼
iam's $rpc: Apps\Iam\Rpc\FindUserHandler                          the same call, in iam's process
called in iam's context, so its queries land in iam's database
```

When the two modules share a process, the message is handed to its handler: no HTTP, no signature,
nothing on the network. It becomes a signed HTTP call only between two processes. On both paths
the handler runs in iam's [context](./module-context), so it never has to
switch to its own database.

The module writes no route: the package serves `POST {module}/rpc/{version}/{message}` for every module the
process runs. A module's host comes from `distributable.declared`; the secret, the cache store and the
transports are under `rpc` in `config/distributable.php`.

## Where to find a module that runs elsewhere

A module that runs in another process is reached at its host. Give it, for each process that calls
it:

```dotenv
# read by distributable.declared.iam.host
IAM_HOST=http://iam.svc:8000
```

The container side of this, with the network alias that makes `iam.svc` resolve, is in
[Deploying a module on its own](./deploying#deploying-a-module-on-its-own).

## Keeping an answer

`readThrough()`, used by `IamClient::findUser()` above, keeps the answer in the cache: the next
call doesn't leave the process. The owner forgets the key when its data changes, and the key
expires after its time to live. The cache store is the one `distributable.rpc.cache` names, shared
by every process. See
[laravel-microservices' RPC](https://mk-josias.github.io/laravel-microservices/docs/rpc#caching-answers).

## Rules of RPC between modules

| Rule | What it means |
|---|---|
| The message is the only way in | A module uses another module's messages and client, which live in the foundation, never its own classes (its models, its handlers). `Boundaries` and `modules:doctor` report the crossing. |
| Only registered messages can be sent | Over HTTP, a message or a version a module doesn't list in `$rpc` is refused with a 400. |
| A payload is validated | By the message's rules, before it is sent and again where it is handled; over HTTP, a refusal is a 422, and the caller gets a `ValidationException`. |
| The callee runs in its own context | The handler runs in the context of the module that answers the message, in this process or in another, so its queries land in its own database. |
| A module can't enter another's context | `ModuleContext::within()`, called from another module's code or from the foundation, throws `ModuleException`. |
| An RPC action isn't in the caller's transaction | If the caller fails afterwards, the action stays done. |
| Ask when you need the answer | When the caller doesn't need the result, announce an [event](./events) instead. |
| `null` is an answer | A handler that returns `null` answers 404 over HTTP, and the caller reads `null`. |
| Every call is signed | The signature covers the timestamp, a nonce, the path, the body and the propagated context. A call that is unsigned, too old, modified or replayed gets a 403. |
| One secret for every process | `distributable.rpc.secret`, which defaults to `APP_KEY`; `modules:doctor` reports it empty. |
| The RPC path stays private | A gateway forwards client requests to a module's API routes, never to `{module}/rpc/…`. |

The first four rules are the module's; the others come from
[laravel-microservices' RPC](https://mk-josias.github.io/laravel-microservices/docs/rpc).
