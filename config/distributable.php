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
    | Each module is a service of laravel-microservices: its calls and its
    | events are configured in config/microservices.php.
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

];
