# The charter

Seven rules. Each one says what it guarantees, because a rule whose reason no one sees anymore ends
up being dropped. Each module is a microservice from its first line of code, and the package holds
it to that, even while it shares a process with the others.

## 1. Each module is designed as a microservice

Its routes, jobs, commands, listeners, configuration and data belong to it, so it can leave on its
own when the day comes. Its [provider](./module-layout) loads them from its
folder, and it has its own database connection ([Its database](./databases)).

## 2. A module knows of the others only what they show

What a module agrees to show (the messages it answers, the shape of the data it exchanges, the names
of its events) lives in the [foundation](./foundation). Nothing a module uses disappears
when a neighbour moves to another process. The foundation uses no module, and every module may use
it. `modules:doctor` and your tests report a module that names another's namespace.

## 3. Ask with a message, announce with an event

No module writes to another's data. A module asks another with a [message](./rpc), announces with an
[event](./events), and reads rows it needs from a [copy](./shadows). `modules:doctor` and your tests
report a module that names another module's connection or tables.

## 4. A module chooses neither its connections nor its settings

Its code always runs with its own connections, settings and services
([Its settings and services](./module-context)). Outside any module there is no default connection, so it has
nothing to divert to reach a neighbour's.

## 5. The same code, whatever the topology

Whether two modules run together or apart, neither the caller nor the callee changes. A call to a
module the process runs is a direct method call; any other goes out on the module's transport.
Events travel on the stream in both cases.

## 6. Deployment is configuration

Which modules run in which process is a setting, not a decision written into the code:
`RUN_MODULES`, and `host` for a module that runs elsewhere. See [Deploying](./deploying).

## 7. Boundaries are checked before deployment

What separate applications prevented, a check must detect: a forbidden import, or a neighbour's
table named in code, fails your tests. See [Checking the boundaries](./checking).

## Nothing invented

None of these rules is new: they are proven microservices patterns, applied to modules and in place
by default, with no ceremony in the business code.

- **Bounded context:** one module is one domain, with its code, its settings and its routes.
- **Database per service, and beyond:** a module reaches only its own connections, settings and
  services.
- **Shared kernel:** the foundation holds what modules share, and nothing more.
- **Transactional outbox** (opt-in): an event is kept in the same transaction as the data until it
  is sent, so it isn't lost when sending fails.
- **Idempotent consumer:** a handled event is marked, and a second delivery does nothing.
- **Event-carried state transfer:** a copy of another module's rows, kept up to date by its events.
- **Event versioning:** each event carries a version, and older ones are converted when read.
- **Ports and adapters:** a call is the same message locally or over the network, and the stream and
  the transport can be swapped.
