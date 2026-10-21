<?php

declare(strict_types=1);

use Distributable\Contracts\Rpc\Transport as RpcTransport;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\TransportManager as RpcTransportManager;
use Distributable\Tests\ModuleAppTestCase;
use Foundation\Iam\Services\IamClient;
use Foundation\Iam\Services\TokenClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

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
    $this->inModule('iam', fn () => Schema::connection('iam'))->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->insert(['id' => 1, 'name' => 'ada']);

    $count = $this->inModule('analytics', function (): int {
        app(IamClient::class)->renameUser(1, 'grace');

        return app(IamClient::class)->countUsers();
    });

    expect($count)->toBe(1)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->value('name'))->toBe('grace')
        ->and($this->inModule('analytics', fn () => Schema::connection('analytics'))->hasTable('iam_users'))->toBeFalse()
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->transactionLevel())->toBe(0);
});

it('only answers a message its module registers', function () {
    app(RpcTransport::class)->invoke('iam', 'shutdown', 1, []);
})->throws(ModuleException::class, 'answers no [shutdown] in version 1');

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
