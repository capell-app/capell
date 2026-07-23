# Running Capell on Laravel Octane

Capell supports Laravel Octane (Swoole, RoadRunner, or FrankenPHP). Octane boots the
application once and reuses the same PHP process for many requests, which is where the
speed comes from — and also the risk. Anything a request leaves behind in memory is
still there for the next visitor, who may be a different user on a different site.

Capell ships an explicit mechanism for this. You need to understand it if you write
extensions; you mostly do not need to think about it if you only host.

## The reset contract

`Capell\Core\Octane\Resettable` is a one-method interface:

```php
interface Resettable
{
    public const string TAG = 'octane.resettable';

    public function flushOctaneState(): void;
}
```

A service opts in by implementing the interface **and** being tagged with
`Resettable::TAG` in a service provider:

```php
$this->app->singleton(MyRegistry::class);
$this->app->tag([MyRegistry::class], Resettable::TAG);
```

`Capell\Core\Octane\FlushResettableState` listens for Octane's `OperationTerminated`
event and calls `flushOctaneState()` on every tagged service, resolving them from the
request's sandbox container rather than the root container so per-request overrides are
respected. Registration lives in
[CapellServiceProvider::registerOctaneStateReset()](../../packages/core/src/Providers/CapellServiceProvider.php) (line 330).

The listener is guarded by `interface_exists(OperationTerminated::class)`. Without
Octane installed the whole mechanism silently does nothing, so there is no cost to
running Capell under PHP-FPM.

Services tagged today: `CapellCoreManager`, `ComponentRegistry`, `LockdownStore`, and
the frontend's `ThemeViewRegistrar`.

## What you must do when writing an extension

**Prefer `scoped` over `singleton` for anything request-shaped.** Laravel resets
`scoped` bindings between Octane requests for you, which is why Capell binds
`FrontendState`, `FrontendContextReader`, `ThemePreviewContext`, the page caches, and
similar services that way. If your service holds the current site, page, theme, locale,
user, or anything derived from the request, `scoped` is the answer and you are done.

Reach for `Resettable` only when a service genuinely must be a singleton — a registry
built once at boot — but accumulates request state alongside its boot state. In that
case `flushOctaneState()` should clear the request state and **leave the boot-time
registrations intact**. Clearing registrations would mean losing them for every
subsequent request in that worker, because nothing re-runs boot.

Do not tag a service that is expensive to construct: the flush resolves each tagged
service on every request termination.

Two rules that catch most bugs:

- **Never mutate a static property during a request** unless you restore it afterwards.
  Static state is per-process, so under Octane it outlives the request that set it.
- **Never memoize anything derived from the locale, the site, or the config** in a plain
  static, because the first request in a worker's life will fix that value for all the
  others.

## Known issues in the current release

These are open findings from the [July 2026 hosting audit](hosting-audit-2026-07.md),
listed so operators can judge their own exposure:

| Issue | Effect under Octane |
| --- | --- |
| `HasEnumOptions` memoizes translated option labels in a method static | Filament select labels freeze to the locale of the first request the worker served |
| `RenderHtmlContentAction` caches a config-built `HtmlSanitizer` statically | Only matters if the HTML attribute allowlist varies per site |

If you run a multi-locale admin under Octane, the first issue is the one you will
notice.

## Operating notes

- Octane does not change any of Capell's other hosting requirements. Multi-node caveats
  in the [hosting audit](hosting-audit-2026-07.md) apply exactly as they do under
  PHP-FPM.
- Run `php artisan capell:runtime-refresh` as part of your deploy, then restart Octane.
  Refreshing caches without restarting leaves workers holding the previous boot state.
- When diagnosing a "wrong content for the wrong user" report, restart Octane first. If
  the problem disappears and then returns after some requests, it is state bleed, and
  the two rules above are where to look.

## Further reading

- [Hosting audit — July 2026](hosting-audit-2026-07.md)
- [Artisan commands reference](../development/artisan-commands.md)
- [Package boot lifecycle](../packages/package-boot-lifecycle.md)
