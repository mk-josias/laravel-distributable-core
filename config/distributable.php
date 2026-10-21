<?php

declare(strict_types=1);
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    | Every module of the application, by name: apps/Iam is `iam`. What the
    | package can read from a module's folder (its namespace, its provider,
    | whether it has a database) is never declared here. `host` is the URL of
    | a module when it runs in another process, or ['url' => …, 'transport' => …].
    | Its calls and its events are configured below.
    */

    'declared' => [
        // 'iam' => ['host' => env('IAM_HOST')],
        // 'iam' => ['path' => 'apps/Identity', 'namespace' => 'Apps\Identity'],
        // 'iam' => ['path' => '.', 'namespace' => 'App'],                 // the project itself: an application of one module
        // 'iam' => ['prefix' => 'identity/{name}'],                       // /identity/api/…, in place of routes.prefix
    ],

    // The URL of a module's routes file: {module} its name, {name} the file's (routes/api.php → api).
    // A gateway routing by path (the starter's gateway/traefik/dynamic/routers.yml) must match it.
    'routes' => [
        'prefix' => '{module}/{name}',
    ],

    // A module's config file that joins the application's whole: config/distributable.php of a module (its streams).
    'shared' => [
        'distributable',
    ],

    // Modules booted by THIS process: '*' = all, or a comma-separated list.
    'runs' => env('RUN_MODULES', '*'),

    // A module is {path}/{Module}, its classes {path}/{Module}/app under {namespace}\{Module}\, unless
    // it declares its own path and namespace. No entry to add to composer.json.
    'modules' => [
        'path' => 'apps',
        'namespace' => 'Apps',
    ],

    // Where what the modules share lives. The package checks it (no module's code in it, none of it
    // entering a module) and never loads it: map it in composer.json like your own code.
    'foundation' => [
        'path' => 'foundation',
    ],

    // Path answering which modules this process runs (e.g. '/'), or null to register nothing.
    'status_route' => env('MODULES_STATUS_ROUTE'),

    // Container services built once per module, with that module's config, and swapped in as the module runs: what
    // one opens by name (a connection, a store, a disk) stays its own. Add a package's manager, or a singleton of
    // yours that receives one of these in its constructor.
    'module_services' => [
        'db', 'cache', 'cache.store', 'queue', 'queue.connection', 'mail.manager', 'mailer',
        'filesystem', 'filesystem.disk', 'filesystem.cloud', 'log', 'redis',
        BroadcastManager::class, Broadcaster::class,
        'auth', 'auth.driver', 'validator', 'validation.presence',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calls between modules
    |--------------------------------------------------------------------------
    | Named transports, like queue connections; every driver comes from
    | Services\Rpc\TransportManager::extend(), from the package of the transport (`http` from
    | mk-josias/laravel-distributable-rpc-http, with its own config/rpc-http.php), which declares
    | its transport and takes it as the default. A module's host may name another.
    */

    'rpc' => [
        // The transport calls travel on unless a module's host names another; null: the one a package sets.
        'transport' => env('DISTRIBUTABLE_RPC_TRANSPORT'),

        'transports' => [],

        // The cache store of the RpcClient answers, shared by every process: the caller keeps an
        // answer, the owner forgets it when it writes. Null is the default store.
        'cache' => env('DISTRIBUTABLE_RPC_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    | Named streams, like queue connections: each has a driver and that
    | driver's options. An event travels on the stream its stream() method
    | names, or on the default one. Drivers: `local` (to the modules this
    | process runs, with no broker), `array` (in memory, for tests),
    | `null` (drops everything), and from its own package `redis`
    | (mk-josias/laravel-distributable-stream-redis).
    | Any other driver resolves through a creator registered with extend().
    */

    'events' => [
        'stream' => env('DISTRIBUTABLE_STREAM', 'default'),

        'streams' => [

            // Nothing is trimmed on write: stream:trim drops only what every consumer group acknowledged.
            // A stream sets its driver; a driver's own options (the redis ones: key, block, count, claim_after, on_failure) are in its package's config file, and a stream may override them here.
            'default' => [
                'driver' => env('DISTRIBUTABLE_STREAM_DRIVER', 'array'), // redis once its package is installed
            ],

        ],

        // 'event.name' => [Handler::class, …]
        'listen' => [],

        // true: each event is stored in event_publications, in the transaction, until it is sent: a committed event is not lost if the process dies before sending. Off: it is only sent after the commit.
        'reliable_publish' => env('DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH', false),

        // The publications and the consumption marks, in each module's own database.
        'tables' => [
            'publications' => 'event_publications',
            'consumptions' => 'event_consumptions',
        ],

        // Where stream:export and stream:import keep the published rows: `file` (JSON lines), or a store registered with ArchiveStores::extend().
        'archive' => env('DISTRIBUTABLE_STREAM_ARCHIVE', 'file'),

        // Marks (event, handler) in the consumptions table inside the handler's transaction, so a
        // redelivery is a no-op. Null turns it on exactly when delivery is at-least-once.
        'guard' => env('DISTRIBUTABLE_STREAM_GUARD'),

        // prune: a mark stays until stream:prune-consumptions. acknowledge: it is deleted once its entry is acknowledged (redis transport only).
        'guard_cleanup' => 'prune',

        // Keys of Laravel's Context carried in the envelope headers and restored around each handler.
        'propagate' => [],
    ],

];
