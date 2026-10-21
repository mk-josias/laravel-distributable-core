<?php

declare(strict_types=1);

namespace Apps\Iam\Providers;

use Apps\Iam\Events\IamEvent;
use Apps\Iam\Events\SomethingCommitted;
use Apps\Iam\Events\SomethingHappened;
use Apps\Iam\Handlers\OnUserRegistered;
use Apps\Iam\Listeners\AfterCommitRecordEventConnection;
use Apps\Iam\Listeners\QueuedRecordEventConnection;
use Apps\Iam\Listeners\RecordEventConnection;
use Apps\Iam\Rpc\CountUsersHandler;
use Apps\Iam\Rpc\DefaultConnectionHandler;
use Apps\Iam\Rpc\FindUserHandler;
use Apps\Iam\Rpc\FindUserV2Handler;
use Apps\Iam\Rpc\RenameUserHandler;
use Apps\Iam\Rpc\TraceIdHandler;
use Distributable\Providers\ServiceProvider;
use Foundation\Iam\Events\UserRenamedPayload;
use Foundation\Iam\Rpc\CountUsers;
use Foundation\Iam\Rpc\DefaultConnection;
use Foundation\Iam\Rpc\FindUser;
use Foundation\Iam\Rpc\FindUserV2;
use Foundation\Iam\Rpc\RenameUser;
use Foundation\Iam\Rpc\TraceId;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class IamServiceProvider extends ServiceProvider
{
    protected array $rpcHandlers = [
        FindUser::class => FindUserHandler::class,
        FindUserV2::class => FindUserV2Handler::class,
        TraceId::class => TraceIdHandler::class,
        DefaultConnection::class => DefaultConnectionHandler::class,
        CountUsers::class => CountUsersHandler::class,
        RenameUser::class => RenameUserHandler::class,
    ];

    protected array $listen = [
        SomethingHappened::class => [
            RecordEventConnection::class,
            RecordEventConnection::class.'@remember',
            QueuedRecordEventConnection::class,
        ],
        SomethingCommitted::class => [AfterCommitRecordEventConnection::class],
    ];

    protected array $streamHandlers = [
        IamEvent::UserRegistered->value => [OnUserRegistered::class],
        UserRenamedPayload::NAME => [OnUserRegistered::class],
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/extra');
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => Cache::put('scheduled-connection', DB::getDefaultConnection()))->everyMinute();
    }
}
