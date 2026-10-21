# The foundation

A module never uses another module's classes, because that import would break as soon as the
modules are deployed separately. What a module shares with the others goes in the foundation:

- its RPC messages and its client;
- its events' names and payloads;
- the shape of the copies others keep of its rows;
- its permission enums.

Any module can use the foundation, and the foundation uses no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

The application owns the foundation: map `Foundation\` in `composer.json` and list its provider in
`bootstrap/providers.php`. The package loads neither. It only reads `distributable.foundation.path`
to check the rule (see [Checking the boundaries](./checking)).

```json
// composer.json
"autoload": { "psr-4": { "Foundation\\": "foundation/" } }
```

## What goes in it

- `foundation/{Module}/` is what that module publishes to the others, such as
  `Foundation\Iam\Rpc\FindUser`, `Foundation\Iam\Services\IamClient`,
  `Foundation\Iam\Events\UserRegisteredPayload`, `Foundation\Iam\Shadows\UserShadow` or
  `Foundation\Analytics\Enums\AnalyticsPermission`.
- `foundation/Common/` is not a module and isn't declared. It holds the base classes every module
  uses, grouped by technical concern (`Auth`, `Http`, `Database`, `Exceptions`, `Validation`…), such
  as `Foundation\Common\Http\Controllers\Controller`. The package doesn't depend on `Common`: these
  classes are yours, to change or delete. The starter says how firmly each piece is meant in
  [its three modules](https://github.com/mk-josias/laravel-distributable-starter/blob/main/docs/skeleton.md#foundationcommon).

Where the code of a concern sits follows who needs it. What every module uses goes in `Common`. What
only a module's neighbours need to know about it goes in that module's folder: how iam's tokens and
the gateway's identity are checked is `Foundation\Iam\Auth\JwtTokens` and `TrustedIdentity`.

A different layout is fine: the package asks only that what modules share be classes every
application can autoload.
