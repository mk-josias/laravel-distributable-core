<?php

declare(strict_types=1);

use Apps\Analytics\Jobs\BuildReport;
use Apps\Iam\Jobs\SyncDirectory;
use Distributable\Context\ModuleContext;
use Distributable\Context\Queue\BatchRepository;
use Distributable\Context\Queue\FailedJobProvider;
use Distributable\Exceptions\ModuleException;
use Distributable\Tests\Fixtures\OutsideJob;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Bus\BatchRepository as Batches;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    foreach (['iam' => 'iam', 'analytics' => 'analytics', 'warehouse' => 'analytics'] as $connection => $module) {
        $schema = $this->inModule($module, fn () => Schema::connection($connection));

        $schema->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $schema->create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }
});

it('stores a failed job on the connection the job ran on', function () {
    $failer = app('queue.failer');
    $payload = json_encode(['uuid' => 'f-1', 'displayName' => SyncDirectory::class]);

    $this->inModule('iam', fn () => $failer->log('sync', 'default', $payload, new RuntimeException('boom')));

    expect($failer)->toBeInstanceOf(FailedJobProvider::class)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(1)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('failed_jobs')->count())->toBe(0)
        ->and($failer->find('f-1'))->not->toBeNull()
        ->and($failer->all())->toHaveCount(1);
});

it('stores the failed job of a class no module owns on the connection it ran on, not on another module', function () {
    $payload = json_encode(['uuid' => 'f-2', 'displayName' => 'Apps\\Gone\\Job']);

    $this->inModule('iam', fn () => app('queue.failer')->log('sync', 'default', $payload, new RuntimeException('boom')));

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(1)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('failed_jobs')->count())->toBe(0);
});

it('stores the failed job of a module that no longer exists on the connection its job carried', function () {
    $payload = json_encode(['uuid' => 'f-3', 'displayName' => 'Apps\\Gone\\Job']);
    $this->inModule('iam', fn () => null);
    app(ModuleContext::class)->switchTo(null);
    Context::addHidden(ModuleContext::CONNECTION_KEY, 'iam');

    app('queue.failer')->log('sync', 'default', $payload, new RuntimeException('boom'));

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(1)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('failed_jobs')->count())->toBe(0);
});

it('refuses a failed job that has no connection to go to', function () {
    app(ModuleContext::class)->switchTo(null);
    Context::forgetHidden(ModuleContext::CONNECTION_KEY);

    app('queue.failer')->log('sync', 'default', json_encode(['uuid' => 'f-4', 'displayName' => 'Apps\\Gone\\Job']), new RuntimeException('boom'));
})->throws(ModuleException::class);

it('stores a batch in the database of the module owning its jobs', function () {
    $batch = Bus::batch([new SyncDirectory])->dispatch();
    app(ModuleContext::class)->switchTo(null);

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('job_batches')->where('id', $batch->id)->exists())->toBeTrue()
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('job_batches')->count())->toBe(0)
        ->and(Bus::findBatch($batch->id)?->finished())->toBeTrue();
});

it('looks a batch up first in the module that stored it', function () {
    $batch = Bus::batch([new SyncDirectory])->dispatch();
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries += (int) ($query->connectionName === 'analytics');
    });

    expect(Bus::findBatch($batch->id)?->id)->toBe($batch->id)
        ->and($queries)->toBe(0);
});

it('still finds a batch whose stored module no longer exists', function () {
    $batch = Bus::batch([new BuildReport])->dispatch();
    Context::addHidden(BatchRepository::CONTEXT_KEY, 'renamed');

    expect(Bus::findBatch($batch->id)?->id)->toBe($batch->id);
});

it('stores a batch whose jobs belong to no module in the module running', function () {
    $batch = $this->inModule('iam', fn () => Bus::batch([new OutsideJob])->dispatch());

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('job_batches')->where('id', $batch->id)->exists())->toBeTrue()
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('job_batches')->count())->toBe(0);
});

it('refuses a batch whose jobs belong to no module when no module runs', function () {
    app(ModuleContext::class)->switchTo(null);

    Bus::batch([new OutsideJob])->dispatch();
})->throws(ModuleException::class);

it('stores the failed job of a module on the connection its own config names', function () {
    $payload = json_encode(['uuid' => 'f-6', 'displayName' => BuildReport::class]);

    $this->inModule('analytics', fn () => app('queue.failer')->log('sync', 'default', $payload, new RuntimeException('boom')));

    expect($this->inModule('analytics', fn () => DB::connection('warehouse'))->table('failed_jobs')->count())->toBe(1)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('failed_jobs')->count())->toBe(0)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(0)
        ->and(app('queue.failer')->find('f-6'))->not->toBeNull();
});

it('ignores a connection the module does not declare, so nothing lands in another module', function () {
    config()->set('distributable.overlays.analytics.queue.failed.database', 'iam');
    $payload = json_encode(['uuid' => 'f-7', 'displayName' => BuildReport::class]);

    $this->inModule('analytics', fn () => app('queue.failer')->log('sync', 'default', $payload, new RuntimeException('boom')));

    expect($this->inModule('analytics', fn () => DB::connection('analytics'))->table('failed_jobs')->count())->toBe(1)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(0);
});

it('stores the batch of a module on the connection its own config names, and finds it there', function () {
    $batch = Bus::batch([new BuildReport])->dispatch();

    expect($this->inModule('analytics', fn () => DB::connection('warehouse'))->table('job_batches')->where('id', $batch->id)->exists())->toBeTrue()
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('job_batches')->count())->toBe(0)
        ->and(Bus::findBatch($batch->id)?->id)->toBe($batch->id);
});

it('limits the failed-job commands to the module --module names', function () {
    $log = fn (string $module, string $uuid, string $class) => $this->inModule($module, fn () => app('queue.failer')->log('sync', 'default', json_encode(['uuid' => $uuid, 'displayName' => $class]), new RuntimeException('boom')));
    $log('iam', 'f-iam', SyncDirectory::class);
    $log('analytics', 'f-ana', BuildReport::class);

    $this->artisan('queue:failed --module=iam')->expectsOutputToContain('f-iam')->doesntExpectOutputToContain('f-ana')->assertSuccessful();
    $this->artisan('queue:flush --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(0)
        ->and($this->inModule('analytics', fn () => DB::connection('warehouse'))->table('failed_jobs')->count())->toBe(1);
});

it('flushes the failed jobs of every module without --module', function () {
    $this->inModule('iam', fn () => app('queue.failer')->log('sync', 'default', json_encode(['uuid' => 'f-iam', 'displayName' => SyncDirectory::class]), new RuntimeException('boom')));
    $this->inModule('analytics', fn () => app('queue.failer')->log('sync', 'default', json_encode(['uuid' => 'f-ana', 'displayName' => BuildReport::class]), new RuntimeException('boom')));

    $this->artisan('queue:flush')->assertSuccessful();

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('failed_jobs')->count())->toBe(0)
        ->and($this->inModule('analytics', fn () => DB::connection('warehouse'))->table('failed_jobs')->count())->toBe(0);
});

it('lists the batches of the module that runs, and of every module otherwise', function () {
    Bus::batch([new SyncDirectory])->dispatch();
    Bus::batch([new BuildReport])->dispatch();
    app(ModuleContext::class)->switchTo(null);
    $batches = app(Batches::class);

    expect($batches->get())->toHaveCount(2)
        ->and($this->inModule('iam', fn () => $batches->get()))->toHaveCount(1);
});
