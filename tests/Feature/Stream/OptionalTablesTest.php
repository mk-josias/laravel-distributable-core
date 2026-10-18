<?php

declare(strict_types=1);

use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

function migration(string $file): object
{
    return require __DIR__.'/../../../database/migrations/'.$file;
}

it('creates the publications table only when events are published reliably', function () {
    config()->set('microservices.events.reliable_publish', false);

    expect(migration('0001_01_01_000000_create_event_publications_table.php')->shouldRun())->toBeFalse()
        ->and(migration('0001_01_01_000002_add_version_to_event_publications_table.php')->shouldRun())->toBeFalse();

    config()->set('microservices.events.reliable_publish', true);

    expect(migration('0001_01_01_000000_create_event_publications_table.php')->shouldRun())->toBeTrue()
        ->and(migration('0001_01_01_000002_add_version_to_event_publications_table.php')->shouldRun())->toBeTrue();
});

it('creates the consumption table unless the guard is switched off', function () {
    $migration = migration('0001_01_01_000001_create_event_consumptions_table.php');

    config()->set('microservices.events.guard', false);
    expect($migration->shouldRun())->toBeFalse();

    config()->set('microservices.events.guard', null);
    expect($migration->shouldRun())->toBeTrue();

    config()->set('microservices.events.guard', true);
    expect($migration->shouldRun())->toBeTrue();
});

it('says so when stream:republish needs the publications table and events are not published reliably', function () {
    config()->set('microservices.events.reliable_publish', false);

    $this->artisan('stream:republish')
        ->expectsOutputToContain('MICROSERVICES_EVENTS_RELIABLE_PUBLISH=true')
        ->assertFailed();
});
