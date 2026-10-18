# Configuration

`php artisan vendor:publish --tag=distributable-config` publishes `config/distributable.php`. The package
reads it only through `Distributable\Config\Modules`. What a module's folder must look like is not
configuration: see [Conventions](modules.md#conventions).

| Key | Default | |
|---|---|---|
| `declared` | `[]` | `'iam' => ['host' => …, 'path' => …, 'namespace' => …, 'foundation' => …]`: every module, the URL of the ones running elsewhere, its own folder and namespace, and its folder under the foundation when not its name (`Iam`) |
| `runs` | `env('RUN_MODULES', '*')` | modules this process runs |
| `modules.path` | `apps` | directory of the modules |
| `modules.namespace` | `Apps` | root namespace of the modules |
| `foundation.path` | `foundation` | directory of what modules share with each other |
| `foundation.namespace` | `Foundation` | root namespace of the foundation |
| `status_route` | `env('MODULES_STATUS_ROUTE')` | path of the status route, `null` for none |

Calls and events are configured in the same file, under `rpc` and `events`: the RPC secret,
transports and cache store, the streams, the guard and the propagated context. A module's host is
its `host` in `declared`. A module declares its handlers in `$handlers` of its service provider,
its copies in `$shadows`, and its own streams in its `config/distributable.php`.

## Source layout

```
src/
├── Providers/          DistributableServiceProvider · ServiceProvider · FoundationServiceProvider
├── Http/               Controllers/StatusController · Middleware/SetModuleContext
├── Console/Commands/   Install · MakeModule · DeleteModule · ListModules · Doctor · PurgeModules · UnusedPackages · CacheModules · ClearModules
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Console/            ModuleOption · ModuleGenerators · ModuleSeedCommand
├── Config/             Modules
├── Data/               Module
├── Context/            ModuleContext · ModuleDeferredCallbacks · ModuleConcurrencyDriver · ModuleTaskDispatcher · ModuleFactories · ScheduledTasks
├── Context/Queue/      FailedJobProvider · BatchRepository · Databases
├── Traits/             ResolvesModule
├── Exceptions/         ModuleException
├── Testing/            Boundaries · ModuleAware
├── Services/           ModuleRegistry
├── Services/Discovery/ ClassDiscovery · DiscoveryCache
├── Services/Migrations/ ModuleMigrations
├── Services/Rpc/       ModuleRpcTransport · …
└── Services/Shadows/   CachedShadowRegistry · ShadowRegistry
```

`ModuleRegistry` tells which modules this process runs, which module a class belongs to
(`ownerOf()`), and which connection each one uses (`connectionOf()`).
`ModuleRpcTransport` turns a call to a module this process runs into a direct method call.
