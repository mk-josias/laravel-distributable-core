# Operating the event stream

An event stream keeps what it is given. Nothing removes old events, old outbox rows or old
consumption marks until you ask. This page says what grows, what cleans it, and when.

## What grows, and what cleans it

| What grows | Where | Cleaned by | Safe when |
|---|---|---|---|
| the stream | Redis, one stream per configured stream (`microservices:events` by default) | `stream:trim` | every module that reads it has acknowledged the entries |
| the outbox | `event_publications`, in the emitting module's database | `stream:export` | the rows are published (and acknowledged, with `--acknowledged`) |
| the consumption marks | `event_consumptions`, in the consuming module's database | `stream:prune-consumptions` | the marked event can no longer be redelivered |

Nothing is trimmed when an event is written, so a stopped consumer, or a module added later,
doesn't miss anything.

## The commands

```bash
# the stream: drop the entries every consumer has acknowledged
php artisan stream:trim [--stream=default]

# the consumption marks: delete those older than 7 days, in every local module
php artisan stream:prune-consumptions --days=7

# the outbox: move the published rows to an archive, then delete them from the table
php artisan stream:export storage/events.jsonl --acknowledged

# put the rows of an archive back as pending publications
php artisan stream:import storage/events.jsonl

# publish again the rows already published, to rebuild an emptied stream
php artisan stream:republish --since=2026-09-01
```

| Command | Options |
|---|---|
| `stream:trim` | `--stream` |
| `stream:prune-consumptions` | `--days` (7 by default): it must outlast how long an event can stay in the stream |
| `stream:export` | `--store`, `--service`, `--stream`, `--since`, `--until`, `--where`, `--acknowledged`, `--batch` |
| `stream:import` | `--store`, `--service`, `--stream`, `--since`, `--until`, `--where`, `--batch` |
| `stream:republish` | `--service`, `--since`, `--force` |

`export`, `import` and `republish` work on the rows of `event_publications`, so they have
something to work on only for a stream with the outbox on (see [Events](./events#the-outbox-and-the-publisher)).
The archive formats and the filters are in
[laravel-microservices' events](https://mk-josias.github.io/laravel-microservices/docs/events#the-outbox).

## What `stream:trim` removes

On the `redis` transport it removes every entry older than the oldest entry still unacknowledged
by any group, or older than what a group has already been delivered when it holds nothing pending.
It does nothing while no group reads the stream.

Every consumer is a group named after its module, and a group never disappears by itself. A module
that was removed, renamed or never started keeps its group, and the stream is trimmed no further
than that group has read. Look at the groups:

```bash
# the groups of the stream, with the last entry delivered to each
redis-cli XINFO GROUPS microservices:events
# the entries a group has received and not acknowledged
redis-cli XPENDING microservices:events analytics
```

A group that no module reads any more is removed with Redis, not with a command of the package:

```bash
redis-cli XGROUP DESTROY microservices:events analytics
```

## Scheduling

The starter schedules none of these commands. To run the two that are cleanups every day, add them
where your scheduler runs, for instance in `routes/console.php`:

```php
Schedule::command('stream:trim')->daily();
Schedule::command('stream:prune-consumptions --days=7')->daily();
```

Only a container with `WITH_SCHEDULER=true` runs the schedule, and one per module set: two would
run each task twice.
