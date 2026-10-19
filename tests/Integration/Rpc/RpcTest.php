<?php

declare(strict_types=1);

use Distributable\Contracts\Rpc\Transport as RpcTransport;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ServiceException;
use Distributable\RpcHttp\HttpTransport;
use Distributable\RpcHttp\RpcSignature;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\TransportManager as RpcTransportManager;
use Distributable\Tests\ModuleAppTestCase;
use Foundation\Iam\Services\IamClient;
use Foundation\Iam\Services\TokenClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(ModuleAppTestCase::class);

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $context
 * @return array<string, string>
 */
function signedRpcHeaders(string $path, array $body, array $context = []): array
{
    return app(RpcSignature::class)->headers($path, json_encode($body), json_encode((object) $context));
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{payload: object}
 */
function rpcBody(array $payload = []): array
{
    return ['payload' => (object) $payload];
}

/** iam runs in another process from here on: its calls leave through its host. */
function iamRunsElsewhere(): void
{
    config()->set('distributable.runs', 'analytics');
    app()->forgetInstance(ModuleRegistry::class);
    app()->forgetInstance(RpcTransportManager::class);
}

it('reaches iam in this process when iam runs here', function () {
    Http::fake();

    expect(app(IamClient::class)->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertNothingSent();
});

it('runs the local call in iam context, whichever module calls it', function () {
    $connection = $this->inModule('analytics', fn (): string => app(IamClient::class)->defaultConnection());

    expect($connection)->toBe('iam')
        ->and($this->inModule('analytics', fn (): string => DB::getDefaultConnection()))->toBe('analytics');
});

it('runs DB:: queries and a DB::transaction() of the local call on iam database, called from analytics', function () {
    Schema::connection('iam')->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::connection('iam')->table('iam_users')->insert(['id' => 1, 'name' => 'ada']);

    $count = $this->inModule('analytics', function (): int {
        app(IamClient::class)->renameUser(1, 'grace');

        return app(IamClient::class)->countUsers();
    });

    expect($count)->toBe(1)
        ->and(DB::connection('iam')->table('iam_users')->value('name'))->toBe('grace')
        ->and(Schema::connection('analytics')->hasTable('iam_users'))->toBeFalse()
        ->and(DB::connection('iam')->transactionLevel())->toBe(0);
});

it('only answers a message its module registers', function () {
    app(RpcTransport::class)->invoke('iam', 'shutdown', 1, []);
})->throws(ServiceException::class, 'answers no [shutdown] in version 1');

it('rejects an unsigned call', function () {
    $this->postJson('/iam/rpc/1/find-user', rpcBody(['id' => 1]))->assertForbidden();
});

it('answers a signed call through the module implementation', function () {
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('answers a missing record with a 404, read back as null', function () {
    $body = rpcBody(['id' => 9]);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))->assertNotFound();
});

it('answers a message or a version the module does not serve with a 400, not a failure', function () {
    foreach (['/iam/rpc/1/does-not-exist', '/iam/rpc/3/find-user'] as $path) {
        $body = rpcBody(['id' => 1]);

        $this->postJson($path, $body, signedRpcHeaders($path, $body))
            ->assertStatus(400)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Module [iam] answers no'));
    }
});

it('answers a payload its rules refuse with a 422', function () {
    $body = rpcBody(['id' => 'x']);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertStatus(422)
        ->assertJsonValidationErrors('id');
});

it('serves two versions of a message while a transition lasts', function () {
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/2/find-user', $body, signedRpcHeaders('/iam/rpc/2/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada', 'email' => 'ada@example.test']);

    $this->postJson('/iam/rpc/1/find-user', $body, signedRpcHeaders('/iam/rpc/1/find-user', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('rejects a stale signature', function () {
    $timestamp = (string) (time() - 3600);
    $body = rpcBody(['id' => 1]);

    $this->postJson('/iam/rpc/1/find-user', $body, [
        RpcSignature::TIMESTAMP_HEADER => $timestamp,
        RpcSignature::NONCE_HEADER => 'n-1',
        RpcSignature::CONTEXT_HEADER => '{}',
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, 'n-1', '/iam/rpc/1/find-user', json_encode($body), '{}'),
    ])->assertForbidden();
});

it('rejects a signed call replayed', function () {
    $body = rpcBody(['id' => 1]);
    $headers = signedRpcHeaders('/iam/rpc/1/find-user', $body);

    $this->postJson('/iam/rpc/1/find-user', $body, $headers)->assertOk();
    $this->postJson('/iam/rpc/1/find-user', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    $body = rpcBody();

    $this->postJson('/iam/rpc/1/trace-id', $body, signedRpcHeaders('/iam/rpc/1/trace-id', $body, ['trace_id' => 'abc']))
        ->assertOk()->assertContent('"abc"');
});

it('rejects a context changed after signing', function () {
    $body = rpcBody();

    $this->postJson('/iam/rpc/1/trace-id', $body, [
        ...signedRpcHeaders('/iam/rpc/1/trace-id', $body, ['trace_id' => 'abc']),
        RpcSignature::CONTEXT_HEADER => json_encode(['trace_id' => 'forged']),
    ])->assertForbidden();
});

it('sends a signed POST naming the version and the message, and decodes the answer', function () {
    config()->set('distributable.events.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $answer = app(HttpTransport::class)->invoke('iam', 'find-user', 1, ['id' => 1]);

    expect($answer)->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/iam/rpc/1/find-user'
        && $request['payload'] === ['id' => 1]
        && $request->header(RpcSignature::CONTEXT_HEADER)[0] === '{"trace_id":"abc"}'
        && app(RpcSignature::class)->verify(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/iam/rpc/1/find-user',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
            $request->header(RpcSignature::SIGNATURE_HEADER)[0],
        ));
});

it('reaches iam over HTTP once iam runs elsewhere', function () {
    iamRunsElsewhere();
    Http::fake(['iam.test/iam/rpc/1/find-user' => Http::response(['id' => 1, 'name' => 'ada'])]);

    expect(app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 1]))
        ->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});

it('reads a remote refusal of the payload as a validation error', function () {
    iamRunsElsewhere();
    Http::fake(['iam.test/*' => Http::response(['errors' => ['id' => ['bad']]], 422)]);

    app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 'x']);
})->throws(ValidationException::class);

it('reads a missing remote record as null', function () {
    iamRunsElsewhere();
    Http::fake(['iam.test/*' => Http::response(null, 404)]);

    expect(app(RpcTransport::class)->invoke('iam', 'find-user', 1, ['id' => 9]))->toBeNull();
});

it('caches a remote answer for as long as the answer says, and forgets it on demand', function () {
    $service = app(TokenClient::class);

    expect($service->token(0))->toBeNull()
        ->and($service->token(60)['token'])->toBe('t2')
        ->and($service->token(60)['token'])->toBe('t2');

    $service->drop();

    expect($service->token(60)['token'])->toBe('t3');
});

it('keeps the raw answer and maps it on every read, dropping one that no longer maps', function () {
    $service = app(TokenClient::class);
    $service->shaped(fn (): array => ['user_id' => 1]);

    expect(Cache::get('iam:shaped'))->toBeNull()
        ->and($service->shaped(fn (): array => ['id' => 7]))->toBe(['id' => 7])
        ->and(Cache::get('iam:shaped'))->toBe('{"id":7}');
});

it('keeps the answers in the store distributable.rpc.cache names, shared by the caller and the owner', function () {
    config()->set('cache.stores.rpc', ['driver' => 'array']);
    config()->set('distributable.rpc.cache', 'rpc');

    app(IamClient::class)->findUser(1);

    expect(Cache::store('rpc')->get('iam:user:1'))->toBe('{"id":1,"name":"ada"}')
        ->and(Cache::get('iam:user:1'))->toBeNull();
});

it('routes a call to the transport its module host names, registered with extend()', function () {
    iamRunsElsewhere();
    config()->set('distributable.rpc.transports.grpc', ['driver' => 'grpc', 'port' => 50051]);
    config()->set('distributable.declared.iam.host', ['url' => 'grpc://iam', 'transport' => 'grpc']);

    app(RpcTransportManager::class)->extend('grpc', fn ($app, array $config): RpcTransport => new class($config) implements RpcTransport
    {
        /** @param array<string, mixed> $config */
        public function __construct(private array $config) {}

        public function invoke(string $module, string $name, int $version, array $payload): mixed
        {
            return ['via' => 'grpc', 'port' => $this->config['port'], 'call' => "{$module}/{$version}/{$name}"];
        }
    });

    expect(app(RpcTransport::class)->invoke('iam', 'find-user', 1, []))
        ->toBe(['via' => 'grpc', 'port' => 50051, 'call' => 'iam/1/find-user']);
});

it('fails loudly on an RPC transport nobody declared', function () {
    iamRunsElsewhere();
    config()->set('distributable.declared.iam.host', ['url' => 'x', 'transport' => 'amqp']);

    app(RpcTransport::class)->invoke('iam', 'find-user', 1, []);
})->throws(ConfigurationException::class, 'RPC transport [amqp] is not declared');
