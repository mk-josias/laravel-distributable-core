# One application or several

A module is written once. Whether it sits in an application with other modules or in an application
of its own is a choice of where its code lives: the code that calls it, announces to it or copies
its rows is the same. An application holds one module or several, and `config/distributable.php`
says which. The [starter](https://github.com/mk-josias/laravel-distributable/tree/main/starter)
is one application of three modules; the
[examples](https://github.com/mk-josias/laravel-distributable/tree/main/examples) are three
applications of one module each.

## Where a module's code lives

In an application of several modules, each module has a folder, `apps/Iam` under `Apps\Iam\`
(`modules.path` and `modules.namespace`). In an application of one module, the module is the
application: `app/` stays the root of its code, and it declares that with its own `path` and
`namespace`. A module that is laid out otherwise gives its two values the same way.

```php
// config/distributable.php, in an application of several modules
'declared' => [
    'iam' => [],
    'analytics' => [],
],

// config/distributable.php, in notifications: an application of one module
'declared' => [
    'notifications' => ['path' => '.', 'namespace' => 'App'],
    // a module it calls and doesn't run: only where it answers
    'iam' => ['host' => env('IAM_HOST', 'http://127.0.0.1:8001')],
],
'runs' => env('RUN_MODULES', 'notifications'),
```

## What `declared` can say about a module

A module is a name in `distributable.declared`. Its array holds only what its folder can't tell,
and can be empty (`'analytics' => []`).

```php
// config/distributable.php (php artisan vendor:publish --tag=distributable-config)
'declared' => [
    'iam'       => ['host' => env('IAM_HOST')],
    'analytics' => [],
],
'modules'    => ['path' => 'apps', 'namespace' => 'Apps'],
'foundation' => ['path' => 'foundation', 'namespace' => 'Foundation'],
```

| Key | What it says |
|---|---|
| `declared.iam.host` | the URL of `iam` when it runs in another process: calls to it go there, as `IAM_HOST=http://iam.internal:8000` does. It can also be `['url' => …, 'transport' => …]` to name another transport. `modules:doctor` reports a module that runs elsewhere and serves a contract without one |
| `declared.{name}.path`, `declared.{name}.namespace` | its own folder and the namespace of its `app/`, when it isn't `apps/{Name}` under `Apps\{Name}`: `'iam' => ['path' => 'apps/Identity', 'namespace' => 'Apps\Identity']` |
| `declared.{name}.foundation` | its folder under `foundation/` when it isn't the name in StudlyCase: `'iam' => ['foundation' => 'Identity']` |
| `modules`, `foundation` | the directory of the modules and of the foundation (`path`), and their root namespace (`namespace`) |

`RUN_MODULES` is not a key of `declared`: it says which of these modules a process runs. For
example, in a `docker-compose.yml` where `analytics` calls `iam` in another container:

```yaml
  analytics:
    environment:
      RUN_MODULES: analytics
      # read by declared.iam.host
      IAM_HOST: "http://iam.svc:8000"
  iam:
    environment:
      RUN_MODULES: iam
    networks:
      default:
        # the name analytics calls
        aliases: [iam.svc]
```

For the module `Iam` with the defaults, its name is `iam` (the folder name in snake_case), its
namespace `Apps\Iam` autoloaded from `apps/Iam/app`, its provider
`Apps\Iam\Providers\IamServiceProvider`, and it has a database because `apps/Iam/config/database.php`
exists.

## What modules share

Two modules never import each other's classes, in one application or in two. In two they couldn't:
each is deployed alone. Yet they have to agree on what they exchange: the message one sends and the
other answers, the payload of an event, the shape of a copy. That agreement lives in a place both
can reach, the foundation. It is the only code modules have in common; everything else in a module
stays in its own code, blind to the others. It is the charter's second rule, [a module knows of the
others only their contracts](./charter): each knows of the others only what the foundation shows
of them.

In one application the foundation is a folder. Across applications it is a Composer package, which
each requires; the classes are the same.

```json
// composer.json, in notifications
"require": { "example/foundation": "*" }
```

```json
// composer.json, in the foundation
"autoload": { "psr-4": { "Foundation\\": "src/" } },
"extra": { "laravel": { "providers": ["Foundation\\FoundationServiceProvider"] } }
```

Laravel discovers the provider in every application that requires the package. The package asks
nothing of the foundation's layout: what the modules share only has to be classes every
application can autoload. The examples use one package for their three applications, with one
folder per module (`Iam/`, `Notifications/`); how you lay yours out is yours.

## What an application needs to call a module that runs elsewhere

| It needs | Why |
|---|---|
| the same `DISTRIBUTABLE_RPC_SECRET` (or `APP_KEY`) as the others | the transport signs a call and the called application rejects any it can't verify |
| the event stream, shared | events travel on it between applications |
| `declared.{module}.host` for each module it calls | its calls go to that host |

The rest, placing processes and building images, is in [Deploying modules](./deploying).
