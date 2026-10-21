# Operating the event stream

An event stream keeps what it is given. Nothing removes old events or old consumption marks until
you ask. This page says what grows, what cleans it, and when.

## What grows, and what cleans it

- **the stream**
  - Where: Redis, one stream per configured stream (`distributable:events` by default)
  - Cleaned by: `stream:trim`
  - Safe when: every module that reads it has acknowledged the entries
- **the consumption marks**
  - Where: `event_consumptions`, in the consuming module's database
  - Cleaned by: `stream:prune-consumptions`, or `guard_cleanup => acknowledge`
  - Safe when: the marked event can no longer be redelivered

`event_publications` does not grow: with `DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH` on, a row is
deleted once its event is sent, so the table only holds the events that could not go out.

Nothing is trimmed when an event is written, so a stopped consumer, or a module added later,
doesn't miss anything.

## The commands

```bash
# the stream: drop the entries every consumer has acknowledged
php artisan stream:trim [--stream=default]

# the consumption marks: delete those older than 7 days, in every local module
php artisan stream:prune-consumptions --days=7

# the stream: copy what it keeps to an archive (nothing is deleted)
php artisan stream:export storage/events.jsonl --acknowledged

# the stream: publish the entries of an archive back on it, to rebuild an emptied stream
php artisan stream:import storage/events.jsonl

# the events still stored because they could not go out after their commit
php artisan stream:republish

# an entry that can never succeed holds its module while on_failure is block: drop the oldest one
php artisan stream:skip --module=analytics
```

- **`stream:trim`:** `--stream`
- **`stream:prune-consumptions`:** `--days` (7 by default): it must outlast how long an event can
  stay in the stream
- **`stream:export`:** `--store`, `--emitter`, `--stream`, `--since`, `--until`, `--where`,
  `--acknowledged`, `--batch`
- **`stream:import`:** `--store`, `--emitter`, `--stream`, `--since`, `--until`, `--where`
- **`stream:republish`:** `--module`
- **`stream:skip`:** `--module`, `--stream`, `--until` (a date: every entry up to it, entries not
  read yet included)

`export` and `import` read and write the stream itself, so they need no setting; the transport has
to be able to give its entries back (`redis` does). `republish` works on the rows of
`event_publications`, so it has something to send only with `DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH`
on (see [Events](./events#don-t-lose-an-event)).

## Archiving the stream

`stream:export` and `stream:import` work on the stream itself, not on the table: the transport
keeps what was published, so they need no setting.

```bash
# copy what the stream keeps to an archive
php artisan stream:export storage/events.jsonl [--store=file] [--emitter=*] [--stream=default] \
    [--since=2026-08-01] [--until=2026-09-01] \
    [--where=name=iam.user.registered] [--where=payload.status=paid] \
    [--acknowledged] [--batch=1000]
# publish the entries of an archive back on a stream, for example one that was emptied
php artisan stream:import storage/events.jsonl [--store=file] [--emitter=*] [--stream=default] \
    [--since=2026-08-01] [--until=2026-09-01] [--where=payload.status=paid]
```

Every module writes to the same stream, so an export takes all of them; `--emitter` keeps the
entries one module emitted. Nothing is deleted: `stream:trim` drops what every consumer has read.
`--acknowledged` stops below what every consumer group has acknowledged. The transport has to
implement `ArchivesStreams` (`redis` does). `import` publishes the entries again: the consumption
guard turns what a consumer already applied into a no-op, unless `guard_cleanup` is `acknowledge`,
which deletes the marks, so an imported event is applied again.

By default the archive store is `file` (`DISTRIBUTABLE_STREAM_ARCHIVE`): it writes JSON lines, one
envelope per line. To archive elsewhere (S3, for instance), see
[Writing a driver](./extending#an-archive-store).

## The consumption marks

By default a mark stays until `stream:prune-consumptions` deletes it. With
`'guard_cleanup' => 'acknowledge'` in `config/distributable.php` (the `redis` transport), a mark is
deleted as soon as its entry is acknowledged, since it can no longer be redelivered, and the table
stays almost empty. The price: an entry imported or sent again is no longer recognised, and its
handlers run again.

## What `stream:trim` removes

On the `redis` transport it removes every entry older than the oldest entry still unacknowledged
by any group, or older than what a group has already been delivered when it holds nothing pending.
It does nothing while no group reads the stream.

Every consumer is a group named after its module, and a group never disappears by itself. A module
that was removed, renamed or never started keeps its group, and the stream is trimmed no further
than that group has read. Look at the groups:

```bash
# the groups of the stream, with the last entry delivered to each
redis-cli XINFO GROUPS distributable:events
# the entries a group has received and not acknowledged
redis-cli XPENDING distributable:events analytics
```

A group that no module reads any more is removed with Redis, not with a command of the package:

```bash
redis-cli XGROUP DESTROY distributable:events analytics
```

## Scheduling

The starter schedules `stream:republish` every minute when events are published reliably
(`DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH=true`, in its `bootstrap/app.php`). To run the two that are
cleanups every day, add them to a module's provider, whose `schedule()` runs only where that module
runs:

```php
// apps/Notifications/app/Providers/NotificationsServiceProvider.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('stream:trim')->daily();
    $schedule->command('stream:prune-consumptions --days=7')->daily();
}
```

Only a container with `WITH_SCHEDULER=true` runs the schedule, and one per module set: two would
run each task twice.
