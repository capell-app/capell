# Hosting audit — July 2026

An audit of `packages/{core,admin,frontend,installer,marketplace}` for two questions a
self-hosting operator cares about: **what does Capell do that the documentation never
mentions**, and **what breaks on real hosting that CI never exercises**.

Findings are grouped by the host profile they affect. Every finding cites the source
line it was proven from. Where a suspicion turned out to be unfounded, it is recorded
as such rather than dropped — knowing what is *safe* is as useful as knowing what is not.

## Documentation coverage, measured

Mechanical diff of user-facing surfaces against `docs/`:

| Surface | Total | Undocumented before | After |
| --- | --- | --- | --- |
| Shipped Artisan commands | 55 | 6 | 0 |
| Capell-owned `env()` variables | 132 | 16 | 0 |
| Public contracts / events / config keys | ~300 | ~120 | ~120 (backlog, see below) |

The `test:*` and `capell:test-*` commands are test fixtures under
`packages/core/tests/Feature/Commands/Fixtures/` and do not ship. They are not findings.

## Severity 1 — multi-server correctness

Capell has no documented position on running behind a load balancer, and several
subsystems assume a single node. `grep` across `docs/` for "shared cache",
"load balanc", "multi-node", "sticky session", or "horizontal scal" returned **zero
hits** before this audit. Worse, [page cache architecture](../architecture/page-cache.md)
advertises the cache as working with "file, database, Redis, and Memcached" — which
reads as an endorsement of the `file` driver, the exact store that breaks first.

### 1.1 The upgrade lock is not globally shared — CRITICAL

[RunCapellUpgradeAction.php](../../packages/core/src/Actions/Upgrade/RunCapellUpgradeAction.php)
line 39 takes `Cache::lock(UpgradeLock, 1500)` on the **default** cache store. With
`file`, `array`, or per-node Redis, two nodes both acquire the lock and both enter
`runMigrationPhase()` against the same database. Concurrent `migrate` means racing
writes to the `migrations` table and half-applied schema changes.

[RollbackCommand.php](../../packages/core/src/Console/Commands/RollbackCommand.php)
line 61 uses the same key with a 600s TTL against the upgrade's 1500s — so even on a
shared store the TTLs disagree, and a long upgrade's lock outlives what rollback
assumes. [BuildUpgradeReadinessReportAction.php](../../packages/core/src/Actions/Upgrade/BuildUpgradeReadinessReportAction.php)
line 142 uses the same key as a *probe*, so it reports "safe to upgrade" on node B while
node A is mid-migration — and briefly acquires the key itself, which can falsely reject
a real upgrade on the same node.

The correct fix is not a better lock. It is to stop depending on cache topology for a
correctness guarantee: gate the upgrade on a database row with a unique active
constraint. [QueueCapellUpgradeAction.php](../../packages/admin/src/Actions/Upgrade/QueueCapellUpgradeAction.php)
line 54 already does this — it blocks with a timeout, catches `LockTimeoutException`,
and falls back to reading the active `UpgradeRun` row. It is the best-behaved lock site
in the codebase and should be the pattern for the others.

### 1.2 Static HTML invalidation is node-local — confirmed

The `page_cache` disk is `local`, rooted at `public_path('page-cache')` — see
[FilesystemsPageCacheDiskPatch.php](../../packages/installer/src/Support/InstallGuide/Patches/FilesystemsPageCacheDiskPatch.php)
line 142. Each node's web server serves that node's own `public/`. Nothing in the
shipped source pushes invalidation to other nodes — no broadcast, no shared-disk write,
no purge webhook. **A publish on node A leaves nodes B, C, and D serving stale HTML
indefinitely.** `capell:admin-clear-cache` runs in-process on one node only.

### 1.3 Lockdown only half-applies — HIGH, security-relevant

[LockdownStaticCacheSwitcher.php](../../packages/core/src/Support/Security/LockdownStaticCacheSwitcher.php)
lines 23-49 move `public/page-cache` aside and write a lockdown `index.html` **on
whichever node handled the admin request**. Every other node keeps serving the live
site. A feature whose whole purpose is to take a site down deserves to fail loudly
rather than half-succeed, so this ranks above its nominal severity.

### 1.4 Frontend build output is node-local

`RunFrontendBuildJob` writes build output to the worker node's local `public/`. Other
nodes serve stale assets and will 404 hashed filenames referenced by a manifest built
elsewhere.

### 1.5 Installer and uploads assume node affinity

The browser installer keeps progress in the cache
([CacheProgressReporter.php](../../packages/core/src/Support/Install/CacheProgressReporter.php)
line 84, [InstallerSessionRepository.php](../../packages/installer/src/Support/InstallerSessionRepository.php)),
so a load balancer routing a progress poll to another node loses the install.
[Troubleshooting](troubleshooting.md) documents this symptom but attributes it to cache
TTL, not routing. Filament uploads land on the `local` disk in one request and are
consumed in a later one, so without sticky sessions or a shared Livewire temp disk the
submit finds no file.

## Severity 2 — queues

### 2.1 Marketplace installs use a queue nobody is told about — HIGH

[capell-marketplace.php](../../packages/marketplace/config/capell-marketplace.php)
line 18 pins install jobs to connection `database` and queue `capell-marketplace`,
ignoring `QUEUE_CONNECTION` entirely. A worker started as plain `php artisan queue:work`
consumes `default` and **never picks these jobs up**. The admin UI sits at "queued" with
no error. If the `jobs` table does not exist — likely, since a `sync` install never runs
`queue:table` — the dispatch throws inside the admin request instead.

Now documented in [configuration](../development/configuration.md#marketplace-config),
including the required worker invocation.

### 2.2 The Composer install job retries forever — HIGH

[RunMarketplaceInstallAttemptJob.php](../../packages/marketplace/src/Jobs/RunMarketplaceInstallAttemptJob.php)
line 54 sets `tries = 0`, which Laravel treats as unlimited. With `timeout = 720`
against a default worker's 60s, the process is killed mid-`composer require`; the lock
taken at line 87 is held for `COMPOSER_TIMEOUT_SECONDS + 120` and never released,
`composer.json` and `vendor/` are left half-written, and the attempt row stays `running`
— forever, on repeat.

### 2.3 Long jobs run inline under `sync` — HIGH

`RunMarketplaceInstallAttemptJob` (720s), `RunCapellUpgradeJob` (1200s),
`RunFrontendBuildJob` (900s, runs an npm build), and `RunCapellInstallJob` (600s) are all
dispatched from web requests. Under `sync` the `$timeout` property is ignored and PHP's
`max_execution_time` — typically 30–60s — kills the request mid-work. Note that the
[quickstart](../getting-started/quickstart.md) recommends `QUEUE_CONNECTION=sync`, so
this is the default path a new operator takes.

`RunFrontendBuildJob` is the worst case: `tries = 1`, no backoff, and it holds a status
cache key, so a kill leaves a permanent "building" state in the admin with no retry.

### 2.4 What is actually fine

Two suspicions did not survive checking, and both are worth recording:

- **The scheduler self-registers.** `capell:purge-soft-deleted-media` is registered
  daily at 03:00 with `withoutOverlapping()->onOneServer()` in
  [AdminServiceProvider.php](../../packages/admin/src/Providers/AdminServiceProvider.php)
  line 866, and `capell:frontend-site-check` likewise. Operators only need
  `schedule:run`, which is already documented.
- **Scheduled publishing needs no cron.** Publish state is derived at query time from
  `visible_from` versus `now()` in
  [HasPublishDates.php](../../packages/core/src/Models/Concerns/HasPublishDates.php),
  so a missing scheduler cannot break it. **But** with static HTML or fragment caching
  on, a page whose `visible_from` passes stays invisible until something invalidates the
  cache, and no sweep is tied to upcoming publish times. The feared symptom is real; the
  mechanism is different. Worth documenting.

Backup retention (`capell:backup:prune`) is *not* scheduled and is not mentioned as a
cron entry anywhere — backup storage grows unbounded until the disk fills.

## Severity 3 — read-only and immutable deployments

Container images, Vapor-style artifacts, and atomic-symlink deploys all make the
application root read-only at runtime. Three code paths write to it **from a live web
request**:

| Path | Writes | Reached from |
| --- | --- | --- |
| [ProcessMarketplaceComposerRunner.php](../../packages/marketplace/src/Support/ProcessMarketplaceComposerRunner.php) line 57 | `composer.json`, `composer.lock`, `vendor/` | Filament UI → queued install job |
| [RunDatabaseMigrationsAction.php](../../packages/core/src/Actions/Upgrade/RunDatabaseMigrationsAction.php) line 93, `PublishPendingMigrationsAction.php` line 36 | `database/migrations`, `database/settings` | Filament UpgradePage → upgrade job |
| [LockdownStaticCacheSwitcher.php](../../packages/core/src/Support/Security/LockdownStaticCacheSwitcher.php) line 33 | `public/page-cache` | Admin request (Livewire) |

On an atomic-symlink deploy the Composer write is subtly worse than a hard failure: it
writes into the *old* release directory, so the change silently vanishes on next deploy.

The lockdown path has no error handling at all — raw filesystem exceptions bubble into a
Livewire 500 with no operator message.

Install-time writes (`WelcomeRouteInstaller`, `AdminPanelProviderEditor`, the installer
patches) are correctly confined to install commands and the browser installer. One nit:
[AdminPanelProviderEditor.php](../../packages/admin/src/Support/AdminPanelIntegration/AdminPanelProviderEditor.php)
line 266 ignores the return value of `file_put_contents`, so it silently no-ops on a
read-only filesystem.

## Severity 4 — external binaries

Capell shells out to `composer`, `npm`, `mysqldump`, `pg_dump`, `psql`, and CLI `php`.
Slim containers ship none of these; shared hosts frequently disable process execution.

**There are no direct `proc_open`/`exec`/`shell_exec` calls** in shipped code — every
invocation goes through Symfony Process or the Process facade, and the installer
correctly degrades when `proc_open` is disabled
([InstallerPreflight.php](../../packages/installer/src/Support/Preflight/InstallerPreflight.php)
line 558). The problem is not the mechanism, it is the error quality.

- **Backup drivers swallow the cause.** [MySqlDatabaseBackupDriver.php](../../packages/core/src/Support/Backup/Drivers/MySqlDatabaseBackupDriver.php)
  line 143 catches `Throwable` and rethrows `"MySQL backup create failed for connection
  [x]."`, discarding stderr. The operator cannot distinguish "mysqldump is not
  installed" from "the password is wrong". Postgres is identical. SQLite, by contrast,
  uses PDO `VACUUM INTO` and needs no binary — the right model.
- **`composer` is a bare string** in [RequirePackageAction.php](../../packages/core/src/Actions/RequirePackageAction.php)
  line 60 and `RemovePackageAction.php` line 41 — no `ExecutableFinder`, no config
  override, no preflight. `RemovePackageAction` deliberately withholds Composer output
  because it may contain credentials, which makes a missing binary indistinguishable
  from a dependency conflict.
- **`npm` has no probe.** [RunNpmBuildAction.php](../../packages/core/src/Actions/RunNpmBuildAction.php)
  runs `npm run build` from the admin UI; on a node-less container the failure message is
  "Review the queue worker log for details."
- **`COMPOSER_HOME` is left unset when `HOME` is unset** — the normal php-fpm case — in
  [ComposerProcessEnvironment.php](../../packages/core/src/Support/Composer/ComposerProcessEnvironment.php)
  line 33. Composer then dies with its own obscure message. The marketplace runner gets
  this right by forcing `storage/framework/composer`; the shared helper should do the
  same.

The good template already exists in
[SetupPackageAction.php](../../packages/core/src/Actions/SetupPackageAction.php) line 96:
*"Unable to locate a CLI PHP binary. Set `CAPELL_INSTALLER_PHP_BINARY` to the php
executable, not php-fpm."* — it names the problem and the config key that fixes it.

### Doctor has zero binary coverage

[BuildDoctorReportAction.php](../../packages/core/src/Actions/Diagnostics/BuildDoctorReportAction.php)
line 38 holds a `CORE_CHECKS` const listing 13 checks. None checks a binary,
`proc_open`, or the cache driver. Adding one is a new class extending
`AbstractDoctorCheck` plus one line in that array — cheap, and the natural home for
everything above.

## Severity 5 — Octane

Octane support is **implemented, not missing**: `Resettable` and `FlushResettableState`
are wired to `OperationTerminated` in
[CapellServiceProvider.php](../../packages/core/src/Providers/CapellServiceProvider.php)
line 330, guarded by `interface_exists()` so it no-ops without Octane. Request-shaped
services are correctly bound as `scoped`, not `singleton`. Of 281 static property
declarations, 278 are declarative configuration; three are genuine runtime mutations.

- **NOT A DEFECT (investigated, cleared) —** [LivewireFrontendResponseRenderer.php](../../packages/frontend/src/Support/Render/LivewireFrontendResponseRenderer.php)
  line 59 sets `SupportDisablingBackButtonCache::$disableBackButtonCache = false` on an
  anonymous cacheable request and never restores it, which looks like classic state
  bleed. It is not, and the reason is worth recording so nobody re-raises it. Livewire
  declares that static as `false` by default and only ever sets it to `true` in
  `SupportDisablingBackButtonCache::boot()`, a `ComponentHook` that runs whenever a
  Livewire component boots — that is, per component, per request. Capell writing `false`
  therefore only returns the flag to Livewire's own default; any later request that
  needs `true` sets it itself when its components boot. The flag cannot bleed in the
  unsafe direction.
  Note that Livewire's `flush-state` reset for this flag is triggered only from
  `SupportTesting`, not from any Octane hook — so the safety here comes from `boot()`
  re-asserting the value, not from a per-request flush.
- **MEDIUM —** [HasEnumOptions.php](../../packages/core/src/Enums/Concerns/HasEnumOptions.php)
  line 21 memoizes `::options()` in a method static while ~19 consumers translate labels
  via `__()`. The first request in a worker freezes select labels to its locale.
- **LOW —** `RenderHtmlContentAction.php` line 21 caches a config-built `HtmlSanitizer`
  statically. Harmless while the allowlist is app-global; wrong the moment it varies
  per site.

See [Running Capell on Laravel Octane](octane.md) for the contract extension authors
must follow.

## Backlog — undocumented extension surface

Roughly 120 public contracts, events, and config keys have no `docs/` mention. This is
too large for one pass; the highest-value items, in order:

1. The `RegistersExtension*` contract family (page types, widgets, routes, admin
   resources, settings, render hooks) — the primary reason to write an extension.
2. Publish lifecycle events: `PublicationTransitioning` / `PublicationTransitioned`,
   `PageUrlChanged`, and the `AuthorizesPublicationTransition` contract.
3. `ServingCapell` / `ServingAdmin` / `ServingFrontend` — the canonical answer to
   "where do I register things".
4. Frontend route middleware aliases (`frontend.resolve`, `frontend.rendering_strategy`,
   `frontend.maintenance`, `frontend.anonymous_cacheable_render`) — anyone adding a
   custom frontend route must apply `frontend.resolve`.
5. `capell-frontend.blade_components` / `livewire_components` — the only way a theme
   author registers or overrides a public component.
6. Duplicate-FQCN hazards worth an explicit note: `RedirectResolver`,
   `SettingsSchemaContract`, and `ThemePreviewRendererInterface` each exist in two
   packages, all undocumented.

Genuinely internal plumbing (frontend kernel interfaces, marketplace composer runners,
install/upgrade internals) is correctly left undocumented and should stay that way.

## Recommended order of work

1. Move the upgrade guarantee to the database (1.1). Correctness must not depend on
   cache topology.
2. Add a queue preflight shared by the marketplace, upgrade, frontend-build, and
   installer actions: reject `sync`, detect a missing driver table, and surface a
   blocking readable error instead of a stuck spinner (2.1, 2.3).
3. Fix `tries`/`failed()`/lock release on the Composer job (2.2).
4. Add the `capell:doctor` checks: cache driver, `proc_open`, Composer/PHP-CLI/npm
   binaries, backup binaries for the active connection, `COMPOSER_HOME` writability,
   and `page_cache` on a local disk under multi-node.
5. Preserve stderr in the backup drivers and name the config key in the error
   (Severity 4).
6. Key the enum options memo by locale (Severity 5).
7. Document the shared-store requirement, web-server rules, and Octane contract.
8. Work the extension-surface backlog.
