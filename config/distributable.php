<?php

declare(strict_types=1);

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
        // 'iam' => ['path' => 'apps/Identity', 'namespace' => 'Apps\Identity', 'foundation' => 'Identity'],
        // 'iam' => ['path' => '.', 'namespace' => 'App'],                 // the project itself: an application of one module
        // 'iam' => ['prefix' => 'identity/{name}'],                       // /identity/api/…, in place of routes.prefix
    ],

    // The URL of a module's routes file: {module} its name, {name} the file's (routes/api.php → api).
    // A gateway routing by path (the starter's gateway/traefik/dynamic/routers.yml) must match it.
    'routes' => [
        'prefix' => '{module}/{name}',
    ],

    // Modules booted by THIS process: '*' = all, or a comma-separated list.
    'runs' => env('RUN_MODULES', '*'),

    // A module is {path}/{Module}, its classes {path}/{Module}/app under {namespace}\{Module}\, unless
    // it declares its own path and namespace. No entry to add to composer.json.
    'modules' => [
        'path' => 'apps',
        'namespace' => 'Apps',
    ],

    // What module iam shares is {path}/Iam, under {namespace}\Iam\.
    'foundation' => [
        'path' => 'foundation',
        'namespace' => 'Foundation',
    ],

    // Path answering which modules this process runs (e.g. '/'), or null to register nothing.
    'status_route' => env('MODULES_STATUS_ROUTE'),

    // Container services built once per module, with that module's config, and swapped in as the
    // module runs: e.g. ['cache', 'cache.store', 'mail.manager', 'mailer', 'filesystem'].
    'per_module' => [],

    /*
    |--------------------------------------------------------------------------
    | Calls between modules
    |--------------------------------------------------------------------------
    | Named transports, like queue connections; every driver comes from
    | Services\Rpc\TransportManager::extend(), `http` from mk-josias/laravel-distributable-rpc-http,
    | which has its own config/rpc-http.php and reads the secret below. A module's
    | host may name the one its calls travel on.
    */

    'rpc' => [
        'transport' => env('DISTRIBUTABLE_RPC_TRANSPORT', 'http'),

        'transports' => [
            'http' => ['driver' => 'http'],
        ],

        // Signs every call; the caller and the called process must share it, so it falls back to APP_KEY.
        'secret' => env('DISTRIBUTABLE_RPC_SECRET', env('APP_KEY', '')),

        // The cache store of the RpcService answers, shared by every process: the caller keeps an
        // answer, the owner forgets it when it writes. Null is the default store.
        'cache' => env('DISTRIBUTABLE_RPC_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    | Named streams, like queue connections: each has a driver and that
    | driver's options. An event travels on the stream its stream() method
    | names, or on the default one. Drivers: `array` (in memory, for tests),
    | `null` (drops everything), and from their own packages `redis`
    | (mk-josias/laravel-distributable-stream-redis) and `queue` (mk-josias/laravel-distributable-stream-queue).
    | Any other driver resolves through a creator registered with extend().
    */

    'events' => [
        'stream' => env('DISTRIBUTABLE_STREAM', 'default'),

        'streams' => [

            // Nothing is trimmed on write: stream:trim drops only what every consumer group acknowledged.
            'default' => [
                'driver' => env('DISTRIBUTABLE_STREAM_DRIVER', 'array'), // redis or queue once its package is installed
                'connection' => env('DISTRIBUTABLE_STREAM_CONNECTION'), // null: the driver's own default connection
                'key' => 'distributable:events', // the Redis stream every module writes to, or the queue prefix
                'block' => 5_000,           // read block window, ms
                'count' => 50,              // entries per read
                'claim_after' => 60_000,    // reclaim entries a dead consumer left pending, ms
                'on_failure' => 'block',    // block: a failed entry is retried before later ones; skip: later ones go on, the failed one is dropped
            ],

            // 'jobs' => [
            //     'driver' => 'queue', // mk-josias/laravel-distributable-stream-queue
            //     'connection' => env('DISTRIBUTABLE_QUEUE_CONNECTION'),
            //     'key' => 'distributable-jobs', // each module reads {key}-{module}
            //     'sleep' => 1,                  // seconds to wait when the queue is empty
            // ],

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
