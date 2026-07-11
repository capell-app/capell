# Do Not Do This

![Capell Do Not Do This screenshot](../images/admin-dashboard.png)

These patterns create upgrade, security, performance, or support problems in Capell. Use the safer extension point instead.

## Public Output

| Do not                                                                                                     | Why                                                                    | Use instead                                                                    |
| ---------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Render authoring controls in public Blade                                                                  | Cached/static HTML would expose editor internals to anonymous visitors | Frontend authoring beacon after authenticated admin response.                  |
| Include model IDs, field paths, permissions, selectors, package names, or signed admin URLs in public HTML | Leaks admin structure and breaks public cache safety                   | Pass only public render data to views.                                         |
| Query models or lazy-load relationships in public Blade                                                    | Hidden N+1s and unsafe fallback state                                  | Hydrate data in Actions, controllers, Livewire, composers, or view components. |
| Let render hooks output diagnostics or admin state                                                         | Hooks run inside public pages                                          | Keep hooks public-only and test anonymous output.                              |

## Package Architecture

| Do not                                                    | Why                                                                | Use instead                                                    |
| --------------------------------------------------------- | ------------------------------------------------------------------ | -------------------------------------------------------------- |
| Patch host package classes from an extension              | Breaks upgrades and package isolation                              | Documented contracts, registries, tags, Actions, and Data.     |
| Register Filament resources from frontend providers       | Frontend requests should not boot admin UI                         | Admin provider or AdminBridge.                                 |
| Write database state from service providers               | Providers run in too many contexts and during cache/build commands | Install/setup Actions and idempotent migrations.               |
| Keep app-specific model dependencies in reusable packages | Package cannot be installed independently                          | Contracts, config, or app glue in the app.                     |
| Add dependencies for small helpers                        | Larger install surface and harder upgrades                         | Laravel/core helpers unless the dependency owns a real domain. |

## Admin

| Do not                                                        | Why                                                    | Use instead                                          |
| ------------------------------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------- |
| Publish core schemas to customize fields                      | Copies internal files and breaks future Capell changes | Schema extenders and AdminBridge.                    |
| Put business logic in Filament pages/resources                | Hard to test and reuse                                 | Actions and Data objects.                            |
| Use static Filament label properties for user-facing strings  | Bypasses translation conventions                       | Method overrides returning translation keys.         |
| Register package settings in the core Settings screen by hand | Hard to audit package ownership                        | Settings registry and package-owned extension pages. |

## Frontend Cache

| Do not                                         | Why                                     | Use instead                                                      |
| ---------------------------------------------- | --------------------------------------- | ---------------------------------------------------------------- |
| Build cache keys directly in Blade             | Invalidation cannot find them reliably  | `@cache` with surrogate keys or package cache helpers.           |
| Use wildcard invalidation first                | Can flush too much output on busy sites | Exact keys/dependencies, then broader patterns only when needed. |
| Serve static cache during Lockdown/maintenance | Public users may see stale unsafe pages | Ensure lockdown and maintenance bypass stale static files.       |

## Marketplace And Remote Data

| Do not                                                                  | Why                                                                 | Use instead                                                                 |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Treat Marketplace metadata as trusted executable code                   | Remote product data is not code review                              | Install packages through Composer/deploy workflow and normal package tests. |
| Expose instance IDs, signing secrets, licence keys, or challenge tokens | They are secrets or trust material                                  | Keep them in encrypted columns/log-safe diagnostics.                        |
| Assume catalogue browsing means install authorization will pass         | Install has entitlement, domain, instance, and compatibility checks | Run account connection, diagnostics, heartbeat, and authorization flow.     |

## Database And Eloquent

| Do not                                                                                | Why                                                                                                                                                                                                                                | Use instead                                                                                                                          |
| ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Use `whereJsonDoesntContain('meta->key', false)` to mean "absent or not false"        | On MySQL this compiles to `NOT JSON_CONTAINS(...)`, which returns NULL (so the row is **excluded**) when the key is absent. SQLite — used by the test suite and local dev — includes it, so the bug is invisible until a MySQL/cloud install (this 404'd the homepage). | Pair it with a key-presence guard: `->orWhereJsonDoesntContainKey('meta->key')->orWhereJsonDoesntContain('meta->key', false)`. The `JsonScopeSafetyTest` arch test enforces this. |

## Tests

| Do not                                                      | Why                                                                                                                     | Use instead                                                                                  |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Only test through HTTP when the behavior lives in an Action | Slow tests and unclear failures                                                                                         | Test Actions directly; test UI only for orchestration.                                       |
| Mock every package boundary in cross-system features        | Mocks miss provider/config/table mistakes                                                                               | Use real package providers and real data where integration matters.                          |
| Test public output only for expected content                | Leaks are absence failures                                                                                              | Assert forbidden admin markers are absent.                                                   |
| Define named classes at the bottom of a test file           | PSR-4 autoloaders and PHPStan silently skip classes whose file path does not match the class name — they become invisible to static analysis | Put fixtures in a `Fixtures/` subdirectory alongside the test (e.g. `tests/Feature/Foo/Fixtures/BarFixture.php` with namespace `…Tests\Feature\Foo\Fixtures`). The `Psr4ComplianceTest` arch test enforces this. |

## Next

- [Public HTML safety](../frontend/public-html-safety.md)
- [Extension point API reference](../packages/extension-point-api-reference.md)
- [Testing packages](../packages/testing-packages.md)
