# Deploying modules

The same code runs in every process: the modules a process runs are a setting. This page covers
placing modules in processes and containers. The package ships no Dockerfile; the
[starter](https://github.com/mk-josias/laravel-distributable-modules-starter) ships the ones used here.

## Placing a module

To move `iam` to its own process, start two copies of the same code with different settings:

```dotenv
# the iam process
RUN_MODULES=iam

# the analytics process
RUN_MODULES=analytics
IAM_HOST=http://iam.internal:8000
```

`config/distributable.php` declares every module in every process, so each process still knows the others
exist. What a process that runs a module needs:

| It needs | Why |
|---|---|
| the database of each module it runs | `migrate` makes one pass per module database it runs, so two processes never migrate the same one |
| the event stream, shared by every process | events travel on it between processes (Redis in the starter) |
| `distributable.declared.{module}.host` for each module it calls but doesn't run | its calls go to that host; `modules:doctor` reports a module that serves messages without one |
| the same `APP_KEY`, or the same `DISTRIBUTABLE_RPC_SECRET`, as the other processes | it signs the RPC calls, and the called process rejects any it can't verify |

## One image per module

An image for one module holds only its code. At build time, in this order (the commands are
described in [Modules](./modules#modules-this-process-doesn-t-run)):

```bash
UNUSED="$(RUN_MODULES=analytics php artisan modules:unused-packages)"
RUN_MODULES=analytics php artisan modules:purge --force
[ -z "$UNUSED" ] || composer update $UNUSED --no-dev --no-scripts
composer dump-autoload --optimize --no-dev
```

At start, `php artisan optimize` runs `modules:cache`, and an image started with a purged module in
`RUN_MODULES` fails at boot with that module's name.

## What a container does

The starter's image runs one or several roles, each switched on by a variable. It migrates only when
the `http` role is on:

| Role | Variable | Default | Run at most |
|---|---|---|---|
| `http` (Octane) | `WITH_HTTP` | `true` | as many as you need |
| `rpc` (`rpc:serve`, port `DISTRIBUTABLE_RPC_PORT`) | `WITH_RPC` | `false` | as many as you need: the calls between modules, kept off the API's workers |
| `worker` (`queue:work`) | `WITH_WORKER` | `false` | as many as you need |
| `scheduler` (`schedule:run` every minute) | `WITH_SCHEDULER` | `false` | one per set of modules: two would run each task twice |
| `consumers` (`stream:consume`) | `WITH_CONSUMERS` | `false` | one per set of modules: it serves every module of `RUN_MODULES`, each with its own cursor, and two would break the order a module reads in |

A scheduled task runs in the module that declared it, so the container with the scheduler is the
one that runs that module.

## Deploying a module on its own

Each file runs an image built for that module only: its code and the foundation, nothing of the
other modules (see [One image per module](#one-image-per-module)).

Nothing ties modules to a single compose file. Each module's service carries its own image,
`RUN_MODULES`, roles and port; what it shares with the others is only reachable infrastructure: the
database server, the stream, the secret, and the address of the modules it calls.

| Shared by every file | How |
|---|---|
| a Docker network | declared `external: true`, with the same `name` in every file |
| the database server and Redis | their service names (`postgres`, `redis`) resolve on that network |
| the address of a module another one calls | a network alias on the module (`iam.svc`), written in the caller's `IAM_HOST` |
| the signing secret | the same `APP_KEY` in every file |

Three files, started in this order, run `iam` and `analytics` apart:

```yaml
# infra.yml
name: dm-infra
services:
  postgres:
    image: distributable-postgres:local
    environment:
      POSTGRES_DB: distributable
      POSTGRES_USER: distributable
      POSTGRES_PASSWORD: distributable
      DB_APP_PASSWORD: distributable_app
      MODULE_DATABASES: "iam analytics notifications"
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "distributable"]
      interval: 5s
      timeout: 5s
      retries: 10
    networks: [dm]

  redis:
    image: valkey/valkey:8-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 10
    networks: [dm]

networks:
  dm:
    name: dm

volumes:
  pgdata:
```

`postgres` is the starter's `docker/postgres` image: it creates the databases listed in
`MODULE_DATABASES`.

```yaml
# analytics.yml
name: dm-analytics
x-env: &env
  APP_ENV: production
  APP_DEBUG: "false"
  APP_KEY: "base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE="
  LOG_CHANNEL: stderr
  DB_CONNECTION: pgsql
  DB_HOST: postgres
  DB_USERNAME: distributable
  DB_PASSWORD: distributable
  DB_APP_USERNAME: distributable_app
  DB_APP_PASSWORD: distributable_app
  REDIS_HOST: redis
  CACHE_STORE: redis
  SESSION_DRIVER: array
  QUEUE_CONNECTION: redis
  DISTRIBUTABLE_STREAM_DRIVER: redis
  AUTH_GATEWAY_SECRET: gateway-secret
  IAM_HOST: "http://iam.svc:8000"
  OCTANE_WORKERS: "2"
  BROADCAST_CONNECTION: reverb
  REVERB_APP_ID: distributable
  REVERB_APP_KEY: distributable-key
  REVERB_APP_SECRET: distributable-secret
  REVERB_HOST: localhost
  REVERB_PORT: "8080"
  REVERB_SCHEME: http

services:
  analytics:
    image: distributable-skeleton:analytics
    environment:
      <<: *env
      RUN_MODULES: analytics
      WITH_CONSUMERS: "true"
      WITH_SCHEDULER: "true"
    ports:
      - "8002:8000"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/up"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 40s
    networks: [dm]

networks:
  dm:
    external: true
    name: dm
```

`iam.yml` is the same file with `iam` for the name, the image, `RUN_MODULES` and the port, no `WITH_SCHEDULER`, `SYNC_PERMISSIONS: "true"`, and the alias `iam.svc` on its network
entry:

```yaml
    networks:
      dm:
        aliases: [iam.svc]
```

The images are the ones the starter's `docker-compose.yml` builds, one per module, with
`--build-arg RUN_MODULES=<module>`. The values above are for development: in production, set real
secrets.

## Checking a deployment

| To see | Use |
|---|---|
| each module, where it runs, its database and its remote host | `php artisan modules:list` |
| which modules a running process serves | `MODULES_STATUS_ROUTE=/`: the route answers with `"modules": ["analytics"]` and `"status": "ok"` |
| that every module can run with this configuration | `php artisan modules:doctor`, exit code non-zero on a problem |

Keep the RPC path off the public entry point: a gateway forwards client requests to a module's API
routes, never to its RPC routes (see [Calls](./rpc)).
