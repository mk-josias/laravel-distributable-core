# The charter

Rules before code. Each one says what it guarantees, because a rule whose reason no one sees
anymore ends up being dropped. Each module is a microservice from its first line of code, and the
package holds it to that, even while it shares a process with the others.

| # | Rule | What it guarantees | How the package holds it |
|---|---|---|---|
| 1 | **Each module is designed as a microservice** | Its routes, jobs, commands, listeners, configuration and data belong to it, so it can leave on its own when the day comes. | The module's [service provider](./modules#the-module-service-provider) loads its config, routes, commands, listeners and schedule; it has its own connection ([data](./databases)). |
| 2 | **A module knows of the others only their contracts, in the shared layer** | What a module agrees to show (its contracts, the shape of the data it exchanges, the names of its events) lives in the [foundation](./modules#the-foundation). Nothing a module uses disappears when a neighbour moves to another process. | The foundation uses no module and every module may use it. A module this process doesn't run is never autoloaded. `Boundaries` reports a module naming another's namespace. |
| 3 | **Ask through a contract, announce through an event** | No module writes to another's data. | [RPC](./rpc) on a contract, [events](./events) on a stream, and [read-only copies](./shadows). `Boundaries` reports another module's connection or tables. |
| 4 | **A module chooses neither its connections nor its settings: its context provides them** | Its code always runs in its module's context, with its connections, its configuration and its services. Outside any module there is no database, so it has nothing to divert to reach a neighbour's. | `ModuleContext` sets the context at every [way into a module's code](./databases#models-and-transactions). Outside a module the connection is `no_module`, and `modules:doctor` reports a `database` driver for the cache, the queue or the session. |
| 5 | **The same code, whatever the topology** | Whether two modules run together or apart, neither the caller nor the callee changes. | A call to a module this process runs is a direct method call; any other goes out on the module's transport. Events travel on the stream in both cases. |
| 6 | **Deployment is configuration** | Which modules run in which process is a setting, not a decision written into the code. | `SERVICE_MODULES` and `modules.declared.{module}.host`; see [Deploying modules](./deploying). |
| 7 | **Boundaries are checked before deployment** | What separate applications prevented, a check must detect: a forbidden import or a neighbour's table named in code fails the tests. | `modules:doctor` and `Boundaries`, see [Checking the application](./modules#checking-the-application). |

## Nothing invented

None of these rules is new: they are proven microservices patterns, applied here to modules. What
changes is that they are in place by default, with no ceremony in the business code.

| Pattern | The problem it solves | How it applies to a module |
|---|---|---|
| Bounded context | where a domain ends | one module = one domain, with its code, its config, its routes |
| Autonomous service (database per service, and beyond) | a service reaching what belongs to another | the module's context: its connections, its configuration, its services; nothing that belongs to a neighbour is reachable from it |
| Shared kernel | what two domains share, and nothing more | the *foundation*: contracts, exchanged data, event names |
| Transactional outbox | an event lost when the data is saved but sending fails | the event is written in the same transaction as the data, then published |
| Idempotent consumer | an event received twice | each handled event is marked; a replay does nothing |
| Event-carried state transfer | reading another's data without calling it every time | shadows: a local reflection of its data, kept up to date by its events |
| Event versioning | evolving a message without breaking those who listen to it | each event carries a version; older ones are converted on read |
| Ports & adapters | changing transport without touching the business logic | the same contract as a local call or over RPC; stream and RPC are swappable |
